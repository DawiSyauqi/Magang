#!/usr/bin/env python3
"""
EKSPLORASI 6 -- baca_fullpage_eksplorasi.py
SATU foto seluruh halaman -> header + Speed/Size + Operator + Lost time,
menggantikan alur 2 section (Section 1 foto utuh utk header via Ollama,
Section 2 foto close-up grid + 3 titik manual).

  1. geometri : foto dipetakan ke kanvas formulir (SIFT thd referensi yg hanya
                memuat CETAKAN, lihat geometri_fullpage.py) -> semua field
                punya posisi tetap; tanpa OSD, tanpa klik 3 titik;
  2. field    : tiap field dipotong & dibersihkan (field_crop.py), field
                kosong = null tanpa model; dibaca TrOCR sesuai bentuk isinya
                (baca_field.py);
  3. Lost time: baris shift di-snap ke garis cetak (refine_grid E1), kotak
                kosong dari ukuran tinta, kotak bertinta dibaca SAMA PERSIS
                dgn E4 (crop 224 px, pita tepi dihapus, kandidat kode a-k);
                default membaca SEMUA baris yg bertinta (--baris shift = hanya
                baris shift header);
  4. ragu     : kotak dgn keyakinan < AMBANG_RAGU (posterior kode teratas thd
                5 kandidat, varian besar/kecil digabung -- spt hibrida E4)
                ditandai utk dikonfirmasi operator, beserta 3 kandidat.

Envelope stdout SATU baris JSON terakhir (spt skrip lain), log ke stderr:
    python baca_fullpage_eksplorasi.py --image foto_utuh.jpg [--shift 1] [--save-crops dir]
"""

import argparse
import builtins
import json
import math
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


from baca_field import FieldReader  # noqa: E402
from field_crop import crop_field  # noqa: E402
from geometri_fullpage import daftarkan, grid_lost_time, imread_exif_safe, ke_kanvas, load_referensi  # noqa: E402
from refine_grid_eksplorasi import INK_EMPTY_THRESHOLD, N_BLOCKS, N_SUBCELLS  # noqa: E402
from trocr_reader import DEFAULT_MODEL, TrOCRReader, normalize_contrast  # noqa: E402

AMBANG_RAGU = 0.85        # dari hibrida E4 (eksplorasi4/analisis_hibrida_e4.py)
CELL_OUT_PX = 224         # SAMA dgn E1/E4
CELL_MARGIN_FRAC = 0.12   # SAMA dgn E1/E4
CELL_INSET_FRAC = 0.08    # SAMA dgn E4

ROW_BLOCK_LABELS = {
    "1": ["07.00 - 08.00", "08.00 - 09.00", "09.00 - 10.00", "10.00 - 11.00",
          "11.00 - 12.00", "12.00 - 13.00", "13.00 - 14.00", "14.00 - 15.00"],
    "2": ["15.00 - 16.00", "16.00 - 17.00", "17.00 - 18.00", "18.00 - 19.00",
          "19.00 - 20.00", "20.00 - 21.00", "21.00 - 22.00", "22.00 - 23.00"],
    "3": ["23.00 - 24.00", "24.00 - 01.00", "01.00 - 02.00", "02.00 - 03.00",
          "03.00 - 04.00", "04.00 - 05.00", "05.00 - 06.00", "06.00 - 07.00"],
}


# ---------------------------------------------------------------- kotak (E4)
def crop_cell_rectified(image, top_px, bot_px, i, out_px=CELL_OUT_PX, margin=CELL_MARGIN_FRAC):
    """SAMA dgn eksplorasi4/baca_kotak_trocr_eksplorasi.py (= E1)."""
    tl, tr = np.float32(top_px[i]), np.float32(top_px[i + 1])
    bl, br = np.float32(bot_px[i]), np.float32(bot_px[i + 1])
    m = out_px * margin
    inner = out_px - 2 * m
    src = np.float32([tl, tr, br, bl])
    dst = np.float32([[m, m], [m + inner, m], [m + inner, m + inner], [m, m + inner]])
    H = cv2.getPerspectiveTransform(src, dst)
    return cv2.warpPerspective(image, H, (out_px, out_px), flags=cv2.INTER_CUBIC,
                               borderMode=cv2.BORDER_REPLICATE)


def clean_cell(crop, out_px=CELL_OUT_PX, margin=CELL_MARGIN_FRAC, inset_frac=CELL_INSET_FRAC):
    """SAMA dgn eksplorasi4/baca_kotak_trocr_eksplorasi.py."""
    a = int(round(out_px * (margin + inset_frac)))
    out = crop.copy()
    inner = cv2.cvtColor(crop[a:out_px - a, a:out_px - a], cv2.COLOR_BGR2GRAY)
    bg = int(np.percentile(inner, 90))
    mask = np.ones(crop.shape[:2], bool)
    mask[a:out_px - a, a:out_px - a] = False
    out[mask] = (bg, bg, bg)
    return out


def keyakinan_kode(top5):
    """Posterior kode teratas thd 5 kandidat, varian besar/kecil digabung
    (SAMA dgn eksplorasi4/analisis_hibrida_e4.py). Return (p, 3 kandidat)."""
    groups = {}
    for code, lp in top5:
        k = code.lower()
        if k in groups:
            m = max(groups[k], lp)
            groups[k] = m + math.log(math.exp(groups[k] - m) + math.exp(lp - m))
        else:
            groups[k] = lp
    g = sorted(groups.items(), key=lambda t: -t[1])
    z = sum(math.exp(lp - g[0][1]) for _, lp in g)
    return 1.0 / z, [k for k, _ in g[:3]]


def read_row(kanvas, det, reader, shift, save_dir=None, stem="foto"):
    top_px, bot_px, ink = det["top_pts_px"], det["bot_pts_px"], det["cell_ink"]
    blocks, n_calls, t_model = [], 0, 0.0
    for b in range(N_BLOCKS):
        kotak, calls = [], []
        for c in range(N_SUBCELLS):
            i = b * N_SUBCELLS + c
            if ink[i] < INK_EMPTY_THRESHOLD:
                kotak.append(None)
                continue
            crop = normalize_contrast(clean_cell(crop_cell_rectified(kanvas, top_px, bot_px, i)))
            if save_dir is not None:
                cv2.imwrite(str(Path(save_dir) / f"{stem}_s{shift}_b{b}_k{c + 1}.jpg"), crop)
            t0 = time.time()
            kode, skor, top5 = reader.read_cell_candidates(crop)
            dtm = time.time() - t0
            n_calls += 1
            t_model += dtm
            p, kand = keyakinan_kode(top5)
            kotak.append(kode)
            calls.append({"kolom": c + 1, "skor": round(skor, 3), "top5": top5,
                          "keyakinan": round(p, 3), "ragu": p < AMBANG_RAGU, "kandidat_3": kand,
                          "elapsed_sec": round(dtm, 3)})
        blocks.append({"block_idx": b, "jam": ROW_BLOCK_LABELS[shift][b], "kotak": kotak,
                       "cell_ink": [round(v, 4) for v in ink[b * N_SUBCELLS:(b + 1) * N_SUBCELLS]],
                       "calls": calls})
    return blocks, n_calls, t_model


# -------------------------------------------------------------------- field
def read_fields(kanvas, layout, fr, save_dir=None, stem="foto"):
    out = {}
    for name, box in layout["field"].items():
        crop, info = crop_field(kanvas, box)
        if crop is None:
            out[name] = {"nilai": None, "kosong": True, "tinta_px": info["tinta_px"]}
            continue
        if save_dir is not None:
            cv2.imwrite(str(Path(save_dir) / f"{stem}_field_{name}.png"), crop)
        t0 = time.time()
        if name == "shift":
            res = fr.shift(crop)
        elif name == "tanggal":
            res = fr.tanggal(crop)
        elif name == "mesin":
            res = fr.mesin(crop)
        elif name == "operator":
            res = fr.operator(crop)
        elif name == "size":
            res = fr.angka(crop, satuan="MM")
        else:
            res = fr.angka(crop)
        res.update({"kosong": False, "bbox_kanvas": info["bbox"], "n_coretan_dibuang": info["n_coretan"],
                    "elapsed_sec": round(time.time() - t0, 2)})
        out[name] = res
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--image", required=True)
    ap.add_argument("--shift", choices=["1", "2", "3"], help="Paksa shift (default: dari header).")
    ap.add_argument("--baris", choices=["bertinta", "shift"], default="bertinta",
                    help="bertinta = baca semua baris Lost time yg ada tintanya; shift = hanya baris shift.")
    ap.add_argument("--ref-dir", default=str(Path(__file__).parent / "reference"))
    ap.add_argument("--trocr-model", default=DEFAULT_MODEL)
    ap.add_argument("--threads", type=int, default=None)
    ap.add_argument("--save-crops", default=None)
    args = ap.parse_args()

    t_all = time.time()
    stem = Path(args.image).name.split(".")[0]
    save_dir = None
    if args.save_crops:
        save_dir = Path(args.save_crops)
        save_dir.mkdir(parents=True, exist_ok=True)
    try:
        ref = load_referensi(args.ref_dir)
        t0 = time.time()
        foto = imread_exif_safe(args.image)
        reg = daftarkan(foto, ref)
        geo = {k: v for k, v in reg.items() if k != "H"}
        if reg["status"] != "success":
            _stdout_print(json.dumps({"status": "needs_retake", "reason": reg["reason"], "meta": {"geometri": geo}}))
            return
        kanvas = ke_kanvas(foto, reg["H"])
        rows = {s: grid_lost_time(kanvas, s, ref["layout"]) for s in ("1", "2", "3")}
        t_geo = time.time() - t0
        if save_dir is not None:
            cv2.imwrite(str(save_dir / f"{stem}_kanvas.jpg"), cv2.resize(kanvas, None, fx=0.5, fy=0.5))

        reader = TrOCRReader(args.trocr_model, num_threads=args.threads)
        fr = FieldReader(reader)
        t0 = time.time()
        fields = read_fields(kanvas, ref["layout"], fr, save_dir, stem)
        t_field = time.time() - t0

        shift_header = fields["shift"].get("nilai")
        shift = args.shift or shift_header
        if args.baris == "shift":
            target = [shift] if shift else []
        else:
            target = [s for s in ("1", "2", "3")
                      if rows[s].get("cell_ink") and max(rows[s]["cell_ink"]) >= INK_EMPTY_THRESHOLD]
            if shift and shift not in target:
                target.insert(0, shift)

        lost, n_calls, t_model = {}, 0, 0.0
        for s in target:
            det = rows[s]
            entry = {"status": det["status"], "reason": det.get("reason"),
                     "refine_quality": det.get("refine", {}).get("quality")}
            if det.get("cell_ink"):
                blocks, n, tm = read_row(kanvas, det, reader, s, save_dir, stem)
                entry["blocks"] = blocks
                n_calls += n
                t_model += tm
            lost[s] = entry

        n_ragu = sum(c["ragu"] for e in lost.values() for b in e.get("blocks", []) for c in b["calls"])
        status = "success"
        if any(e["status"] != "success" for e in lost.values()):
            status = "needs_manual_review"
        env = {
            "status": status,
            "data": {
                "header": fields, "shift_header": shift_header, "shift_dipakai": shift,
                "lost_time": lost, "n_kotak_ragu": n_ragu,
            },
            "meta": {
                "metode": "eksplorasi6_fullpage_trocr", "trocr_model": args.trocr_model,
                "ambang_ragu": AMBANG_RAGU, "geometri": geo,
                "geometry_seconds": round(t_geo, 2), "model_load_seconds": round(reader.load_seconds, 2),
                "field_seconds": round(t_field, 2), "n_model_calls": n_calls,
                "model_seconds": round(t_model, 2), "total_seconds": round(time.time() - t_all, 2),
            },
        }
        _stdout_print(json.dumps(env, ensure_ascii=False))
    except Exception as e:  # envelope tetap 1 baris JSON
        import traceback
        traceback.print_exc()
        _stdout_print(json.dumps({"status": "error", "error": f"{type(e).__name__}: {e}"}))


if __name__ == "__main__":
    main()
