#!/usr/bin/env python3
"""
EKSPLORASI 1 (refine) -- geometry_pipeline_refine_eksplorasi.py
Pengganti drop-in utk geometry_pipeline.py: argumen & envelope JSON SAMA
(SATU baris JSON terakhir ke stdout, log ke stderr), tp posisi grid sudah
di-refine ke garis cetak asli. Field tambahan di data/meta:
  data.refined, data.refine_quality, data.cell_filled (48 bool),
  meta.refine_metrics.
Utk dipakai dari PaperScanEksplorasi1Controller cukup ganti $scriptPath ke
file ini.
"""

import argparse
import builtins
import json
import sys
import time
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).parent))

_stdout_print = builtins.print


def print(*args, **kwargs):  # noqa: A001
    kwargs.setdefault("file", sys.stderr)
    kwargs.setdefault("flush", True)
    _stdout_print(*args, **kwargs)


from geometry_pipeline import encode_b64_array  # noqa: E402
from refine_grid_eksplorasi import (  # noqa: E402
    detect_grid_refined, draw_refined_overlay, INK_EMPTY_THRESHOLD,
)


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

        det = detect_grid_refined(args.image, args.shift, kp_pts, des, ref_w, ref_h, anchors)
        img = det.pop("corrected_image", None)

        if det["status"] not in ("success", "needs_manual_review"):
            envelope = {
                "status": det["status"],
                "reason": det.get("reason"),
                "meta": {"elapsed_seconds": round(time.time() - t0, 1)},
            }
            _stdout_print(json.dumps(envelope, ensure_ascii=False))
            return 0

        overlay = draw_refined_overlay(img, det["top_pts_px"], det["bot_pts_px"],
                                       cell_ink=det.get("cell_ink"))
        refine = det.get("refine", {})
        hg = det.get("homography_debug", {})
        ink = det.get("cell_ink")
        envelope = {
            "status": det["status"],
            "data": {
                "confidence": det.get("confidence"),
                "orientation_rot": det.get("orientation_rot"),
                "overlay_image_b64": encode_b64_array(overlay),
                "refined": det.get("refined"),
                "refine_quality": refine.get("quality") or refine.get("reason"),
                "cell_filled": [r >= INK_EMPTY_THRESHOLD for r in ink] if ink else None,
            },
            "meta": {
                "n_good_matches": hg.get("n_good_matches"),
                "n_inliers": hg.get("n_inliers"),
                "inlier_ratio": hg.get("inlier_ratio"),
                "ocr_cross_check_diff": det.get("ocr_cross_check", {}).get("diff"),
                "refine_metrics": refine.get("metrics"),
                "elapsed_seconds": round(time.time() - t0, 1),
            },
        }
    except Exception as e:
        import traceback
        print("ERROR:", traceback.format_exc())
        envelope = {"status": "error", "error": str(e), "error_type": type(e).__name__}

    def _json_default(o):
        if isinstance(o, (np.floating, float)):
            return float(o)
        if isinstance(o, (np.integer, int)):
            return int(o)
        if isinstance(o, np.bool_):
            return bool(o)
        return str(o)

    _stdout_print(json.dumps(envelope, ensure_ascii=False, default=_json_default))
    return 0 if envelope["status"] != "error" else 1


if __name__ == "__main__":
    sys.exit(main())
