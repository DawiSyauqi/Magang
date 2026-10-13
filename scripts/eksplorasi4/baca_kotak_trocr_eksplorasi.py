#!/usr/bin/env python3
"""
EKSPLORASI 4 -- baca_kotak_trocr_eksplorasi.py
Tiruan EKSPLORASI 1 (eksplorasi1/baca_kotak_eksplorasi.py, baca PER KOTAK);
SATU-SATUNYA beda: pembaca tulisan Ollama (qwen2.5vl:3b) diganti TrOCR
handwritten (pretrained, tanpa pelatihan, jalan di CPU, tanpa Ollama).

  1. geometri  : detect_grid_fast() -- SAMA dgn E1 (salinan refine_grid_eksplorasi.py);
  2. tinta     : 48 kotak diukur tintanya -> kotak kosong = null tanpa model (SAMA);
  3. crop      : crop_cell_rectified() -- SAMA dgn E1 (224 px, margin 12%);
                 lalu praproses utk OCR (--no-hapus-garis utk mematikan):
                   a. pita tepi (garis kotak + 8% ke dalam) diisi warna latar,
                      krn TrOCR membaca sisa garis kotak sbg "1"/"7"/"x";
                   b. kontras diregangkan (kertas putih, tinta hitam).
                 Nilai 8% & normalisasi dipilih dari uji tuning di 2 foto
                 (134505 & 004756); lihat laporan eksplorasi 4-5.
  4. baca      : TrOCR, 1 kotak per inferensi, mode:
                   kandidat (default) -- semua kode sah (0-8 + opsional huruf a-k,
                                         atau "x"; --huruf a-z = uji lama) dinilai, ambil yg
                                         tertinggi (lihat trocr_reader.py);
                   bebas              -- model menulis apa saja;
  5. susun     : 8 blok x 6 kotak (SAMA).

Envelope stdout SAMA gaya skrip lain (SATU baris JSON terakhir), log ke stderr:
    python baca_kotak_trocr_eksplorasi.py --image foto.jpg --shift 1 --ref-dir reference
    python baca_kotak_trocr_eksplorasi.py --image foto.jpg --shift 1 --mode bebas
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


from refine_grid_eksplorasi import (  # noqa: E402
    detect_grid_fast, INK_EMPTY_THRESHOLD, N_BLOCKS, N_SUBCELLS,
)
from trocr_reader import DEFAULT_MODEL, LETTERS, LETTERS_AZ, TrOCRReader, normalize_contrast  # noqa: E402

CELL_OUT_PX = 224        # SAMA dgn E1
CELL_MARGIN_FRAC = 0.12  # SAMA dgn E1

ROW_BLOCK_LABELS = {
    "1": ["07.00 - 08.00", "08.00 - 09.00", "09.00 - 10.00", "10.00 - 11.00",
          "11.00 - 12.00", "12.00 - 13.00", "13.00 - 14.00", "14.00 - 15.00"],
    "2": ["15.00 - 16.00", "16.00 - 17.00", "17.00 - 18.00", "18.00 - 19.00",
          "19.00 - 20.00", "20.00 - 21.00", "21.00 - 22.00", "22.00 - 23.00"],
    "3": ["23.00 - 24.00", "24.00 - 01.00", "01.00 - 02.00", "02.00 - 03.00",
          "03.00 - 04.00", "04.00 - 05.00", "05.00 - 06.00", "06.00 - 07.00"],
}


def crop_cell_rectified(image, top_px, bot_px, i, out_px=CELL_OUT_PX, margin=CELL_MARGIN_FRAC):
    """SAMA dgn eksplorasi1/baca_kotak_eksplorasi.py."""
    tl, tr = np.float32(top_px[i]), np.float32(top_px[i + 1])
    bl, br = np.float32(bot_px[i]), np.float32(bot_px[i + 1])
    m = out_px * margin
    inner = out_px - 2 * m
    src = np.float32([tl, tr, br, bl])
    dst = np.float32([[m, m], [m + inner, m], [m + inner, m + inner], [m, m + inner]])
    H = cv2.getPerspectiveTransform(src, dst)
    return cv2.warpPerspective(image, H, (out_px, out_px), flags=cv2.INTER_CUBIC,
                               borderMode=cv2.BORDER_REPLICATE)


CELL_INSET_FRAC = 0.08   # lebar pita tepi yg dihapus (di dlm garis kotak)


def clean_cell(crop, out_px=CELL_OUT_PX, margin=CELL_MARGIN_FRAC, inset_frac=CELL_INSET_FRAC):
    """Garis kotak posisinya PASTI (tepat di margin crop) -> seluruh pita tepi
    (di luar kotak + tebal garis) diisi warna latar kertas. Ujung coretan yg
    menyentuh garis ikut terpotong sedikit; isi kotak tdk disentuh."""
    a = int(round(out_px * (margin + inset_frac)))
    out = crop.copy()
    inner = cv2.cvtColor(crop[a:out_px - a, a:out_px - a], cv2.COLOR_BGR2GRAY)
    bg = int(np.percentile(inner, 90))
    mask = np.ones(crop.shape[:2], bool)
    mask[a:out_px - a, a:out_px - a] = False
    out[mask] = (bg, bg, bg)
    return out


def read_cells(det, reader, mode="kandidat", hapus_garis=True, save_crops_dir=None, stem="foto"):
    img = det["corrected_image"]
    top_px, bot_px = det["top_pts_px"], det["bot_pts_px"]
    ink = det["cell_ink"]
    blocks = []
    n_calls, t_model = 0, 0.0
    for b in range(N_BLOCKS):
        kotak, calls = [], []
        for c in range(N_SUBCELLS):
            i = b * N_SUBCELLS + c
            if ink[i] < INK_EMPTY_THRESHOLD:
                kotak.append(None)
                continue
            crop = crop_cell_rectified(img, top_px, bot_px, i)
            if hapus_garis:
                crop = normalize_contrast(clean_cell(crop))
            if save_crops_dir is not None:
                cv2.imwrite(str(Path(save_crops_dir) / f"{stem}_b{b}_k{c + 1}.jpg"), crop)
            t0 = time.time()
            if mode == "kandidat":
                kode, skor, top5 = reader.read_cell_candidates(crop)
                extra = {"skor": round(skor, 3), "top5": top5}
            else:
                kode = reader.read_free([crop])[0] or "?"
                extra = {}
            dt = time.time() - t0
            n_calls += 1
            t_model += dt
            kotak.append(kode)
            calls.append({"kolom": c + 1, "elapsed_sec": round(dt, 3), **extra})
        blocks.append({"block_idx": b, "kotak": kotak,
                       "cell_ink": ink[b * N_SUBCELLS:(b + 1) * N_SUBCELLS], "calls": calls})
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
        blocks, stats = read_cells(det, reader, args.mode, not args.no_hapus_garis,
                                   args.save_crops, Path(args.image).name.split(".")[0])
        labels = ROW_BLOCK_LABELS[args.shift]
        for blk in blocks:
            blk["expected_jam_label"] = labels[blk["block_idx"]]
            print(f"  blok {blk['block_idx']} ({blk['expected_jam_label']}) -> {blk['kotak']}")
        envelope = {
            "status": det["status"],
            "data": {"shift": args.shift, "blocks": blocks},
            "meta": {
                "reader": "trocr", "trocr_model": args.trocr_model, "mode": args.mode, "huruf": args.huruf,
                "hapus_garis": not args.no_hapus_garis,
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
