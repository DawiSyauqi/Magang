#!/usr/bin/env python3
"""
EKSPLORASI 1 (refine) -- terminal_test_refine_eksplorasi.py
Sama spt terminal_test.py, tp posisi grid di-refine ke garis cetak asli
(lihat refine_grid_eksplorasi.py). Overlay menampilkan hasil refine, dan
(opsional --show-coarse) garis merah tipis = posisi versi lama, utk
perbandingan. Titik magenta = kotak yg terdeteksi berisi tinta.

Cara pakai:
    python terminal_test_refine_eksplorasi.py --image path/foto.jpg --shift 1 --ref-dir reference
    python terminal_test_refine_eksplorasi.py --image path/foto.jpg --shift 1 --show-coarse --no-interactive
"""

import argparse
import csv
import json
import sys
from datetime import datetime
from pathlib import Path

import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).parent))
from refine_grid_eksplorasi import (  # noqa: E402
    detect_grid_fast, detect_grid_refined, draw_refined_overlay, INK_EMPTY_THRESHOLD, N_SUBCELLS,
)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--image", required=True)
    ap.add_argument("--shift", required=True, choices=["1", "2", "3"])
    ap.add_argument("--ref-dir", default="reference")
    ap.add_argument("--output-dir", default="output_eksplorasi1")
    ap.add_argument("--show-coarse", action="store_true",
                     help="Gambar juga posisi versi lama (merah tipis) utk perbandingan.")
    ap.add_argument("--no-interactive", action="store_true")
    ap.add_argument("--jalur-lama", action="store_true",
                     help="Geometri lewat OSD + OCR (detect_grid lama) lalu refine. Default: jalur cepat.")
    args = ap.parse_args()

    out_dir = Path(args.output_dir)
    out_dir.mkdir(exist_ok=True)

    ref_dir = Path(args.ref_dir)
    if not (ref_dir / "ref_template.npz").exists():
        print(f"ERROR: {ref_dir / 'ref_template.npz'} tidak ditemukan. Jalankan calibrate_reference_cli.py dulu.")
        sys.exit(1)

    data = np.load(ref_dir / "ref_template.npz")
    kp_pts, des = data["kp_pts"], data["des"]
    ref_h, ref_w = int(data["img_h"]), int(data["img_w"])
    anchors = json.loads((ref_dir / "ref_anchors.json").read_text())

    print(f"\n{'='*60}")
    print(f"EKSPLORASI 1 (REFINE) -- {Path(args.image).name} (shift {args.shift})")
    print(f"{'='*60}")

    detect = detect_grid_refined if args.jalur_lama else detect_grid_fast
    det = detect(args.image, args.shift, kp_pts, des, ref_w, ref_h, anchors)
    img = det.pop("corrected_image", None)

    print(f"Status           : {det['status']}")
    if det["status"] not in ("success", "needs_manual_review"):
        print(f"Alasan gagal     : {det.get('reason')}")
        log_result(out_dir, args, det, manual_grade=None)
        return

    hg = det.get("homography_debug", {})
    print(f"Confidence       : {det.get('confidence')}")
    print(f"Rotasi diterapkan: {det.get('orientation_rot')} derajat")
    print(f"Inlier homography: {hg.get('n_inliers')}/{hg.get('n_good_matches')} "
          f"({hg.get('inlier_ratio', 0)*100:.1f}%)")

    refine = det.get("refine", {})
    if det.get("refined"):
        m = refine["metrics"]
        print(f"Refine           : OK (kualitas={refine['quality']})")
        print(f"  batas blok terdeteksi : {m['n_block_borders_found']}/9")
        print(f"  garis vertikal snap   : {m['n_vertical_snapped']}/49")
        print(f"  blok dgn garis baris  : {m['n_blocks_row_found']}/8")
        print(f"  koreksi thd versi lama: rata2 {m['mean_correction_px']:.1f}px, maks {m['max_correction_px']:.1f}px")
        ink = det["cell_ink"]
        print("  kotak berisi tinta (per blok):")
        for b in range(len(ink) // N_SUBCELLS):
            cells = ink[b * N_SUBCELLS:(b + 1) * N_SUBCELLS]
            marks = " ".join("#" if r >= INK_EMPTY_THRESHOLD else "." for r in cells)
            print(f"    blok {b}: {marks}   ({' '.join(f'{r*100:4.1f}' for r in cells)} %)")
    else:
        print(f"Refine           : GAGAL ({refine.get('reason')}) -- overlay memakai posisi versi lama")

    coarse = None
    if args.show_coarse and "coarse" in det:
        coarse = (det["coarse"]["top_pts_px"], det["coarse"]["bot_pts_px"])
    overlay = draw_refined_overlay(img, det["top_pts_px"], det["bot_pts_px"], coarse=coarse,
                                   cell_ink=det.get("cell_ink"))
    overlay_path = out_dir / f"{Path(args.image).stem}_shift{args.shift}_refined_overlay.jpg"
    cv2.imwrite(str(overlay_path), overlay)
    print(f"\nOverlay disimpan -> {overlay_path.resolve()}")
    print("BUKA FILE INI SECARA MANUAL untuk verifikasi visual.")

    manual_grade = None
    if not args.no_interactive:
        while True:
            ans = input("\nApakah overlay PRESISI (garis biru/hijau/kuning tepat di garis grid)? [y/n]: ").strip().lower()
            if ans in ("y", "n"):
                manual_grade = (ans == "y")
                break
            print("Jawab y atau n.")

    log_result(out_dir, args, det, manual_grade)


def log_result(out_dir, args, det, manual_grade):
    log_path = out_dir / "evaluation_log_refine.csv"
    is_new = not log_path.exists()
    hg = det.get("homography_debug", {})
    m = det.get("refine", {}).get("metrics", {})
    row = {
        "timestamp": datetime.now().isoformat(timespec="seconds"),
        "image": Path(args.image).name,
        "shift": args.shift,
        "status": det["status"],
        "confidence": det.get("confidence"),
        "n_inliers": hg.get("n_inliers"),
        "inlier_ratio": hg.get("inlier_ratio"),
        "refined": det.get("refined"),
        "refine_quality": det.get("refine", {}).get("quality") or det.get("refine", {}).get("reason"),
        "n_block_borders": m.get("n_block_borders_found"),
        "n_vertical_snapped": m.get("n_vertical_snapped"),
        "max_correction_px": round(m["max_correction_px"], 1) if "max_correction_px" in m else None,
        "manual_grade_presisi": manual_grade,
    }
    with open(log_path, "a", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=row.keys())
        if is_new:
            writer.writeheader()
        writer.writerow(row)
    print(f"Log ditambahkan -> {log_path.resolve()}")


if __name__ == "__main__":
    main()
