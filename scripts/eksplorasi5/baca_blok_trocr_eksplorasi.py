#!/usr/bin/env python3
"""
EKSPLORASI 5 -- baca_blok_trocr_eksplorasi.py
Tiruan EKSPLORASI 2 (eksplorasi2/ollama_block_pipeline_refine_eksplorasi.py,
baca PER BLOK = 6 kotak sekaligus); beda UTAMA: pembaca tulisan Ollama
(qwen2.5vl:3b) diganti TrOCR handwritten (pretrained, tanpa pelatihan, CPU).

  1. geometri : detect_grid_fast() + refine -- SAMA dgn E2;
  2. crop     : crop_block_rectified() (perspective warp per blok) -- SAMA,
                KECUALI 2 penyesuaian krn pembacanya OCR baris, bukan VLM:
                  a. ruang ekstra di atas (label jam) DIBUANG -- TrOCR akan
                     ikut membaca "07.00 - 08.00" sbg teks;
                  b. TANPA garis bantu merah/cyan; garis cetak justru
                     DIHAPUS (pita 8% diisi warna latar, TrOCR membaca garis
                     kotak sbg "1"/"l") + kontras diregangkan -- praproses
                     yg sama dgn eksplorasi4;
  3. tinta    : ink-gate SAMA -- blok kosong semua TIDAK dibaca model,
                kotak kosong selalu null; + "ink hint" versi OCR: kotak
                kosong dibuang dari gambar, kotak bertinta dirangkai jadi
                1 baris rapat (--no-padatkan utk mematikan);
  4. baca     : TrOCR membaca 1 blok sbg 1 baris teks, mode:
                  kandidat (default) -- beam search dibatasi tata bahasa
                    "<kode> <kode> ..." TEPAT n kode (kode = 0-8 + opsional
                    huruf a-k, atau "x"; n = jumlah kotak
                    bertinta); kode ke-k dipasang ke kotak bertinta ke-k;
                  bebas -- teks bebas dipecah per spasi, dipasang berurutan
                    ke kotak bertinta (jumlah tdk cocok -> ditandai).

Envelope stdout SAMA gaya skrip lain (SATU baris JSON terakhir), log ke stderr:
    python baca_blok_trocr_eksplorasi.py --image foto.jpg --shift 1
"""

import argparse
import builtins
import json
import sys
import time
from pathlib import Path

import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).parent))

_stdout_print = builtins.print


def print(*args, **kwargs):  # noqa: A001
    kwargs.setdefault("file", sys.stderr)
    kwargs.setdefault("flush", True)
    _stdout_print(*args, **kwargs)


from crop_blocks_refine_eksplorasi import crop_block_rectified  # noqa: E402
from refine_grid_eksplorasi import (  # noqa: E402
    detect_grid_fast, INK_EMPTY_THRESHOLD, N_BLOCKS, N_SUBCELLS,
)
from trocr_reader import DEFAULT_MODEL, LETTERS, LETTERS_AZ, TrOCRReader, flatten_background, normalize_contrast  # noqa: E402

BLOCK_PAD_Y_TOP = 0.15  # E2: 0.9 (supaya label jam terlihat VLM) -- di sini dibuang
BLOCK_PAD_Y_BOT = 0.15  # E2: 0.3
LINE_BAND_FRAC = 0.08   # pita di sekitar garis cetak yg diisi warna latar (thd lebar kotak / tinggi baris)

ROW_BLOCK_LABELS = {
    "1": ["07.00 - 08.00", "08.00 - 09.00", "09.00 - 10.00", "10.00 - 11.00",
          "11.00 - 12.00", "12.00 - 13.00", "13.00 - 14.00", "14.00 - 15.00"],
    "2": ["15.00 - 16.00", "16.00 - 17.00", "17.00 - 18.00", "18.00 - 19.00",
          "19.00 - 20.00", "20.00 - 21.00", "21.00 - 22.00", "22.00 - 23.00"],
    "3": ["23.00 - 24.00", "24.00 - 01.00", "01.00 - 02.00", "02.00 - 03.00",
          "03.00 - 04.00", "04.00 - 05.00", "05.00 - 06.00", "06.00 - 07.00"],
}


def clean_block(crop, meta, band_frac=LINE_BAND_FRAC):
    """Posisi garis cetak di crop diketahui (meta dari crop_block_rectified):
    pita di sekitar 7 garis vertikal + area di luar baris (atas/bawah) diisi
    warna latar kertas, lalu kontras diregangkan. Sama prinsipnya dgn
    clean_cell() di eksplorasi4."""
    h, w = crop.shape[:2]
    lt = np.asarray(meta["lines_top_crop"])
    lb = np.asarray(meta["lines_bot_crop"])
    y0, y1 = meta["row_y_crop"]
    cell_w = float(np.median(np.diff(lt[:, 0])))
    bx = band_frac * cell_w
    by = band_frac * (y1 - y0)
    mask = np.zeros((h, w), np.uint8)
    mask[:int(round(y0 + by)), :] = 255
    mask[int(round(y1 - by)):, :] = 255
    # area padding kiri/kanan di luar garis batas blok (berisi garis blok tetangga)
    mask[:, :int(round(min(lt[0, 0], lb[0, 0]) + bx))] = 255
    mask[:, int(round(max(lt[-1, 0], lb[-1, 0]) - bx)):] = 255
    for (xt, yt), (xb, yb) in zip(lt, lb):
        slope = (xb - xt) / (yb - yt) if yb != yt else 0.0
        xa, xz = xt + slope * (0 - yt), xt + slope * (h - yt)
        poly = np.int32([[xa - bx, 0], [xa + bx, 0], [xz + bx, h], [xz - bx, h]])
        cv2.fillPoly(mask, [poly], 255)
    inner = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)[mask == 0]
    bg = int(np.percentile(inner, 90)) if inner.size else 255
    out = crop.copy()
    out[mask > 0] = (bg, bg, bg)
    out = normalize_contrast(flatten_background(out))
    out[mask > 0] = 255  # pita garis putih murni (tanpa sisa tepi kotak)
    return out


def compact_filled(crop, meta, filled, gap_frac=0.4):
    """Rangkai HANYA kotak bertinta jadi 1 baris teks rapat (kotak kosong
    dibuang). Padanan OCR dari "ink hint" E2 (di E2 info kotak berisi
    diberikan ke VLM lewat prompt; TrOCR tdk punya prompt). Tanpa ini TrOCR
    berhalusinasi "1" di ruang kosong lebar (uji: "6a 5b" -> "1 1M")."""
    lt = np.asarray(meta["lines_top_crop"])
    lb = np.asarray(meta["lines_bot_crop"])
    xs = (lt[:, 0] + lb[:, 0]) / 2
    cell_w = float(np.median(np.diff(xs)))
    h = crop.shape[0]
    gap = np.full((h, int(gap_frac * cell_w), 3), 255, np.uint8)
    parts = [gap]
    for i, f in enumerate(filled):
        if f:
            parts += [crop[:, int(round(xs[i])):int(round(xs[i + 1]))], gap]
    return np.hstack(parts)


def block_crop_for_ocr(img, top_px, bot_px, b, filled, hapus_garis=True, padatkan=True):
    crop, meta = crop_block_rectified(img, top_px, bot_px, b,
                                      pad_y_top=BLOCK_PAD_Y_TOP, pad_y_bot=BLOCK_PAD_Y_BOT)
    if hapus_garis:
        crop = clean_block(crop, meta)
        if padatkan:
            crop = compact_filled(crop, meta, filled)
    return crop, meta


def assign_codes(codes, filled):
    """Pasang kode berurutan ke kotak bertinta. Return (kotak[6], cocok?)."""
    kotak = [None] * N_SUBCELLS
    idx = [i for i, f in enumerate(filled) if f]
    for k, i in enumerate(idx):
        kotak[i] = codes[k] if k < len(codes) else "?"
    return kotak, len(codes) == len(idx)


def read_blocks(det, reader, mode="kandidat", hapus_garis=True, padatkan=True, save_crops_dir=None,
                stem="foto"):
    img = det["corrected_image"]
    top_px = np.asarray(det["top_pts_px"], np.float32)
    bot_px = np.asarray(det["bot_pts_px"], np.float32)
    ink = det["cell_ink"]
    blocks = []
    n_calls, t_model = 0, 0.0
    for b in range(N_BLOCKS):
        cell_ink = ink[b * N_SUBCELLS:(b + 1) * N_SUBCELLS]
        filled = [r >= INK_EMPTY_THRESHOLD for r in cell_ink]
        res = {"block_idx": b, "cell_ink": cell_ink, "cell_filled": filled}
        if not any(filled):
            res.update(kotak=[None] * N_SUBCELLS, skipped_model=True, elapsed_sec=0.0)
            blocks.append(res)
            continue
        crop, _ = block_crop_for_ocr(img, top_px, bot_px, b, filled, hapus_garis, padatkan)
        if save_crops_dir is not None:
            cv2.imwrite(str(Path(save_crops_dir) / f"{stem}_b{b}.jpg"), crop)
        n_filled = sum(filled)
        t0 = time.time()
        if mode == "kandidat":
            codes, raw = reader.read_line_constrained(crop, n_filled)
        else:
            raw = reader.read_free([crop], max_new_tokens=32)[0]
            codes = raw.split()
        dt = time.time() - t0
        n_calls += 1
        t_model += dt
        kotak, count_ok = assign_codes(codes, filled)
        res.update(kotak=kotak, raw_text=raw, n_kode_terbaca=len(codes),
                   jumlah_cocok=count_ok, skipped_model=False, elapsed_sec=round(dt, 3))
        blocks.append(res)
    return blocks, {"n_model_calls": n_calls, "model_seconds": round(t_model, 2)}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--image", required=True)
    ap.add_argument("--shift", required=True, choices=["1", "2", "3"])
    ap.add_argument("--ref-dir", default=str(Path(__file__).parent / "reference"))
    ap.add_argument("--mode", default="kandidat", choices=["kandidat", "bebas"])
    ap.add_argument("--trocr-model", default=DEFAULT_MODEL)
    ap.add_argument("--huruf", default="a-k", choices=["a-k", "a-z"],
                    help="Huruf sub-poin yg boleh dipakai mode kandidat (default a-k = legenda formulir).")
    ap.add_argument("--no-hapus-garis", action="store_true")
    ap.add_argument("--no-padatkan", action="store_true",
                    help="Jangan buang kotak kosong dari gambar blok (baca blok utuh 6 kotak).")
    ap.add_argument("--threads", type=int, default=None)
    ap.add_argument("--save-crops", default=None, help="Folder utk menyimpan crop yg dibaca model.")
    args = ap.parse_args()

    t0 = time.time()
    try:
        ref_dir = Path(args.ref_dir)
        data = np.load(ref_dir / "ref_template.npz")
        anchors = json.loads((ref_dir / "ref_anchors.json").read_text())
        det = detect_grid_fast(args.image, args.shift, data["kp_pts"], data["des"],
                               int(data["img_w"]), int(data["img_h"]), anchors)
        t_geo = time.time() - t0
        if not det.get("refined"):
            envelope = {"status": det["status"], "reason": det.get("reason"),
                        "meta": {"elapsed_seconds": round(time.time() - t0, 1)}}
            _stdout_print(json.dumps(envelope, ensure_ascii=False))
            return 0

        reader = None
        if any(r >= INK_EMPTY_THRESHOLD for r in det["cell_ink"]):
            reader = TrOCRReader(args.trocr_model, num_threads=args.threads,
                                 letters=LETTERS if args.huruf == "a-k" else LETTERS_AZ)
        if args.save_crops:
            Path(args.save_crops).mkdir(parents=True, exist_ok=True)
        blocks, stats = read_blocks(det, reader, args.mode, not args.no_hapus_garis,
                                    not args.no_padatkan, args.save_crops, Path(args.image).name.split(".")[0])
        labels = ROW_BLOCK_LABELS[args.shift]
        for blk in blocks:
            blk["expected_jam_label"] = labels[blk["block_idx"]]
            print(f"  blok {blk['block_idx']} ({blk['expected_jam_label']}) -> {blk['kotak']}"
                  f"  raw={blk.get('raw_text')!r}")
        envelope = {
            "status": det["status"],
            "data": {"shift": args.shift, "blocks": blocks},
            "meta": {
                "reader": "trocr", "trocr_model": args.trocr_model, "mode": args.mode, "huruf": args.huruf,
                "hapus_garis": not args.no_hapus_garis,
                "padatkan": not args.no_padatkan,
                "geometry_path": det.get("path"),
                "refine_quality": det["refine"]["quality"],
                "geometry_seconds": round(t_geo, 2),
                "model_load_seconds": round(reader.load_seconds, 2) if reader else 0.0,
                **stats,
                "elapsed_seconds": round(time.time() - t0, 1),
            },
        }
    except Exception as e:
        import traceback
        print("ERROR:", traceback.format_exc())
        envelope = {"status": "error", "error": str(e), "error_type": type(e).__name__}

    _stdout_print(json.dumps(envelope, ensure_ascii=False, default=lambda o: float(o)
                             if isinstance(o, np.floating) else str(o)))
    return 0 if envelope["status"] != "error" else 1


if __name__ == "__main__":
    sys.exit(main())
