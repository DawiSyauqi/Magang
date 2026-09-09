#!/usr/bin/env python3
"""
EKSPLORASI 1 -- geometry_pipeline.py
Murni geometri (OCR-anchor + homography), TIDAK memanggil Ollama sama
sekali. Output: metrik deteksi + overlay visual (base64) utk verifikasi
manual di browser.
"""

import argparse
import base64
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


from detect_grid import detect_grid  # noqa: E402
from crop_and_overlay import build_overlay  # noqa: E402


def encode_b64_array(image):
    ok, buf = cv2.imencode(".jpg", image, [cv2.IMWRITE_JPEG_QUALITY, 90])
    if not ok:
        raise RuntimeError("Gagal encode JPEG")
    return base64.standard_b64encode(buf).decode("utf-8")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--image", required=True)
    ap.add_argument("--shift", required=True, choices=["1", "2", "3"])
    ap.add_argument("--ref-dir", required=True)
    args = ap.parse_args()

    t0 = time.time()
    try:
        ref_dir = Path(args.ref_dir)
        data = np.load(ref_dir / "ref_template.npz")
        kp_pts, des = data["kp_pts"], data["des"]
        ref_h, ref_w = int(data["img_h"]), int(data["img_w"])
        anchors = json.loads((ref_dir / "ref_anchors.json").read_text())

        det = detect_grid(args.image, args.shift, kp_pts, des, ref_w, ref_h, anchors)
        img = det.pop("corrected_image", None)

        if det["status"] not in ("success", "needs_manual_review"):
            envelope = {
                "status": det["status"],
                "reason": det.get("reason"),
                "meta": {"elapsed_seconds": round(time.time() - t0, 1)},
            }
            _stdout_print(json.dumps(envelope, ensure_ascii=False))
            return 0

        overlay = build_overlay(img, det["col_x_frac"], det["row_y_top_per_col"], det["row_y_bot_per_col"])
        overlay_b64 = encode_b64_array(overlay)

        hg = det.get("homography_debug", {})
        envelope = {
            "status": det["status"],
            "data": {
                "confidence": det.get("confidence"),
                "orientation_rot": det.get("orientation_rot"),
                "overlay_image_b64": overlay_b64,
            },
            "meta": {
                "n_good_matches": hg.get("n_good_matches"),
                "n_inliers": hg.get("n_inliers"),
                "inlier_ratio": hg.get("inlier_ratio"),
                "ocr_cross_check_diff": det.get("ocr_cross_check", {}).get("diff"),
                "elapsed_seconds": round(time.time() - t0, 1),
            },
        }
    except Exception as e:
        import traceback
        print("ERROR:", traceback.format_exc())
        envelope = {"status": "error", "error": str(e), "error_type": type(e).__name__}

    _stdout_print(json.dumps(envelope, ensure_ascii=False))
    return 0 if envelope["status"] != "error" else 1


if __name__ == "__main__":
    sys.exit(main())