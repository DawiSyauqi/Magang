#!/usr/bin/env python3
"""
EKSPLORASI 2 -- aset_visual_eksplorasi.py
Membuat gambar utk evaluasi kualitatif (dipakai buat_laporan_visual_eksplorasi.py).
Tanpa Ollama -- hanya geometri, jadi crop yg dihasilkan SAMA PERSIS dgn
yg dikirim ke model oleh E1 (per kotak) & E2 (per blok).

Per foto & shift, ditulis ke --out-dir/<foto>_s<shift>/:
  overlay.jpg        -- baris Lost time + garis hasil refine + titik tinta
  blok<b>.jpg        -- crop blok spt dikirim E2 (dgn garis bantu)
  kotak<i>.jpg       -- crop 1 kotak spt dikirim E1 (hanya kotak bertinta)
  info.json          -- status geometri
"""

import argparse
import glob
import json
import os
import sys
from pathlib import Path

import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).parent))
from crop_blocks_refine_eksplorasi import crop_block_rectified, draw_block_overlay  # noqa: E402
from refine_grid_eksplorasi import (  # noqa: E402
    detect_grid_fast, draw_refined_overlay, INK_EMPTY_THRESHOLD, N_BLOCKS,
)


def crop_cell_like_e1(image, top_px, bot_px, i, out_px=224, margin=0.12):
    """Sama dgn crop_cell_rectified di eksplorasi1/baca_kotak_eksplorasi.py."""
    m = out_px * margin
    inner = out_px - 2 * m
    src = np.float32([top_px[i], top_px[i + 1], bot_px[i + 1], bot_px[i]])
    dst = np.float32([[m, m], [m + inner, m], [m + inner, m + inner], [m, m + inner]])
    H = cv2.getPerspectiveTransform(src, dst)
    return cv2.warpPerspective(image, H, (out_px, out_px), flags=cv2.INTER_CUBIC,
                               borderMode=cv2.BORDER_REPLICATE)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--images-dir", required=True)
    ap.add_argument("--shifts", nargs="+", default=["1"])
    ap.add_argument("--out-dir", required=True)
    ap.add_argument("--ref-dir", default=str(Path(__file__).parent / "reference"))
    ap.add_argument("--overlay-width", type=int, default=2400)
    args = ap.parse_args()

    d = np.load(Path(args.ref_dir) / "ref_template.npz")
    anchors = json.loads((Path(args.ref_dir) / "ref_anchors.json").read_text())
    for photo in sorted(glob.glob(os.path.join(args.images_dir, "*.jp*g"))):
        stem = Path(photo).name.split(".")[0]
        for shift in args.shifts:
            out = Path(args.out_dir) / f"{stem}_s{shift}"
            out.mkdir(parents=True, exist_ok=True)
            det = detect_grid_fast(photo, shift, d["kp_pts"], d["des"], int(d["img_w"]), int(d["img_h"]), anchors)
            info = {"status": det["status"], "reason": det.get("reason"), "path": det.get("path"),
                    "quality": det.get("refine", {}).get("quality"), "rotasi": det.get("orientation_rot")}
            (out / "info.json").write_text(json.dumps(info), encoding="utf-8")
            if not det.get("refined"):
                print(stem, shift, "geometri gagal", flush=True)
                continue
            img = det["corrected_image"]
            T = np.float32(det["top_pts_px"])
            B = np.float32(det["bot_pts_px"])
            ink = det["cell_ink"]

            ov = draw_refined_overlay(img, T, B, cell_ink=ink)
            scale = args.overlay_width / ov.shape[1]
            ov = cv2.resize(ov, None, fx=scale, fy=scale, interpolation=cv2.INTER_AREA)
            cv2.imwrite(str(out / "overlay.jpg"), ov, [cv2.IMWRITE_JPEG_QUALITY, 85])

            for b in range(N_BLOCKS):
                crop, meta = crop_block_rectified(img, T, B, b)
                cv2.imwrite(str(out / f"blok{b}.jpg"), draw_block_overlay(crop, meta),
                            [cv2.IMWRITE_JPEG_QUALITY, 85])
            for i, r in enumerate(ink):
                if r >= INK_EMPTY_THRESHOLD:
                    cv2.imwrite(str(out / f"kotak{i}.jpg"), crop_cell_like_e1(img, T, B, i),
                                [cv2.IMWRITE_JPEG_QUALITY, 85])
            print(stem, shift, "ok", flush=True)


if __name__ == "__main__":
    main()
