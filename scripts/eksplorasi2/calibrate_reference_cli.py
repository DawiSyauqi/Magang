#!/usr/bin/env python3
"""
Jalankan SEKALI SAJA di mesin Anda, dengan foto referensi close-up yang
grid-nya jelas (mis. IMG_20260901_134505_jpg.jpeg), untuk menghasilkan
ref_template.npz + ref_anchors.json yang dipakai ollama_block_pipeline.py.

Contoh:
    python3 calibrate_reference_cli.py --image path/ke/foto_referensi.jpg \
        --left-frac 0.164 --right-frac 0.8558 --out-dir reference
"""

import argparse
import os
from reference_calib import calibrate_reference


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--image", required=True)
    ap.add_argument("--left-frac", type=float, default=0.164,
                     help="Fraksi x batas kiri grid (tepi kiri blok jam pertama). "
                          "0.164 valid utk foto referensi 134505 -- kalibrasi ulang kalau pakai foto lain.")
    ap.add_argument("--right-frac", type=float, default=0.8558,
                     help="Fraksi x batas kanan grid (tepi kanan blok jam terakhir).")
    ap.add_argument("--out-dir", default="reference")
    args = ap.parse_args()

    os.makedirs(args.out_dir, exist_ok=True)
    out_prefix = os.path.join(args.out_dir, "ref")

    anchors = calibrate_reference(args.image, args.left_frac, args.right_frac, out_prefix)
    print("Kalibrasi berhasil.")
    print(f"  -> {out_prefix}_template.npz")
    print(f"  -> {out_prefix}_anchors.json")
    print("shift_row_bounds_y_frac:", anchors["shift_row_bounds_y_frac"])


if __name__ == "__main__":
    main()