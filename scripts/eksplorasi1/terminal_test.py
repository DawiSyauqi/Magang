#!/usr/bin/env python3
"""
EKSPLORASI 1 -- terminal_test.py
Testing MURNI dari terminal, TANPA Laravel/DB sama sekali. Panggil
langsung fungsi geometri (detect_grid), simpan overlay ke file (bukan
base64 spt versi web), cetak evaluasi ke terminal + tanya konfirmasi
manual interaktif.

Cara pakai:
    python terminal_test.py --image path/foto.jpg --shift 1 --ref-dir reference
"""

import argparse
import csv
import json
import sys
from datetime import datetime
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).parent))
from detect_grid import detect_grid  # noqa: E402
from crop_and_overlay import build_overlay  # noqa: E402

import cv2


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--image", required=True)
    ap.add_argument("--shift", required=True, choices=["1", "2", "3"])
    ap.add_argument("--ref-dir", default="reference")
    ap.add_argument("--output-dir", default="output_eksplorasi1")
    ap.add_argument("--no-interactive", action="store_true",
                     help="Skip pertanyaan manual (utk automated run tanpa evaluasi).")
    args = ap.parse_args()

    out_dir = Path(args.output_dir)
    out_dir.mkdir(exist_ok=True)

    ref_dir = Path(args.ref_dir)
    if not (ref_dir / "ref_template.npz").exists():
        print(f"ERROR: {ref_dir / 'ref_template.npz'} tidak ditemukan.")
        print("Jalankan calibrate_reference_cli.py dulu.")
        sys.exit(1)

    data = np.load(ref_dir / "ref_template.npz")
    kp_pts, des = data["kp_pts"], data["des"]
    ref_h, ref_w = int(data["img_h"]), int(data["img_w"])
    anchors = json.loads((ref_dir / "ref_anchors.json").read_text())

    print(f"\n{'='*60}")
    print(f"EKSPLORASI 1 -- {Path(args.image).name} (shift {args.shift})")
    print(f"{'='*60}")

    det = detect_grid(args.image, args.shift, kp_pts, des, ref_w, ref_h, anchors)
    img = det.pop("corrected_image", None)

    print(f"Status          : {det['status']}")
    if det["status"] not in ("success", "needs_manual_review"):
        print(f"Alasan gagal    : {det.get('reason')}")
        log_result(out_dir, args, det, manual_grade=None)
        return

    hg = det.get("homography_debug", {})
    print(f"Confidence      : {det.get('confidence')}")
    print(f"Rotasi diterapkan: {det.get('orientation_rot')} derajat")
    print(f"Inlier homography: {hg.get('n_inliers')}/{hg.get('n_good_matches')} "
          f"({hg.get('inlier_ratio', 0)*100:.1f}%)")
    if "ocr_cross_check" in det:
        diff = det["ocr_cross_check"].get("diff")
        print(f"Selisih OCR vs homography: {diff if diff is not None else 'N/A (OCR gagal)'}")

    overlay = build_overlay(img, det["col_x_frac"], det["row_y_top_per_col"], det["row_y_bot_per_col"])
    overlay_path = out_dir / f"{Path(args.image).stem}_shift{args.shift}_overlay.jpg"
    cv2.imwrite(str(overlay_path), overlay)
    print(f"\nOverlay disimpan -> {overlay_path.resolve()}")
    print("BUKA FILE INI SECARA MANUAL untuk verifikasi visual.")

    manual_grade = None
    if not args.no_interactive:
        while True:
            ans = input("\nApakah overlay PRESISI (kotak biru/garis hijau tepat di grid)? [y/n]: ").strip().lower()
            if ans in ("y", "n"):
                manual_grade = (ans == "y")
                break
            print("Jawab y atau n.")

    log_result(out_dir, args, det, manual_grade)


def log_result(out_dir, args, det, manual_grade):
    log_path = out_dir / "evaluation_log.csv"
    is_new = not log_path.exists()
    hg = det.get("homography_debug", {})
    row = {
        "timestamp": datetime.now().isoformat(timespec="seconds"),
        "image": Path(args.image).name,
        "shift": args.shift,
        "status": det["status"],
        "confidence": det.get("confidence"),
        "n_inliers": hg.get("n_inliers"),
        "n_good_matches": hg.get("n_good_matches"),
        "inlier_ratio": hg.get("inlier_ratio"),
        "ocr_diff": det.get("ocr_cross_check", {}).get("diff"),
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