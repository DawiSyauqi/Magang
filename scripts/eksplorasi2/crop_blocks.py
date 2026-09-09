"""
EKSPLORASI 2 -- crop_blocks.py
Geometri hanya menyiapkan crop KASAR per blok-jam (8 blok, 6 sub-kotak
masing2), padding LONGGAR. Ollama yang menentukan batas 6 sub-kotak &
membaca isinya sekaligus.
"""

import cv2
import numpy as np

from detect_grid import detect_grid

N_BLOCKS = 8

BLOCK_PAD_X_FRAC = 0.15
BLOCK_PAD_Y_TOP_FRAC = 0.9
BLOCK_PAD_Y_BOT_FRAC = 0.3


def get_block_boundaries_x_frac(anchors):
    cols49 = anchors["grid_columns_x_frac"]
    return [cols49[i] for i in range(0, 49, 6)]


def crop_jam_blocks(photo_path, shift, ref_kp_pts, ref_des, ref_w, ref_h, anchors,
                     pad_x=BLOCK_PAD_X_FRAC, pad_y_top=BLOCK_PAD_Y_TOP_FRAC,
                     pad_y_bot=BLOCK_PAD_Y_BOT_FRAC):
    det = detect_grid(photo_path, shift, ref_kp_pts, ref_des, ref_w, ref_h, anchors)
    if det["status"] not in ("success", "needs_manual_review"):
        return det, None

    img = det["corrected_image"]
    h, w = img.shape[:2]

    cols49_target = det["col_x_frac"]
    row_top_per_col = det["row_y_top_per_col"]
    row_bot_per_col = det["row_y_bot_per_col"]

    block_x = [cols49_target[i] for i in range(0, 49, 6)]
    block_row_top = [row_top_per_col[i] for i in range(0, 49, 6)]
    block_row_bot = [row_bot_per_col[i] for i in range(0, 49, 6)]

    crops = []
    crop_meta = []
    for b in range(N_BLOCKS):
        x0f, x1f = block_x[b], block_x[b + 1]
        y0f = (block_row_top[b] + block_row_top[b + 1]) / 2
        y1f = (block_row_bot[b] + block_row_bot[b + 1]) / 2
        bw = x1f - x0f
        bh = y1f - y0f

        cx0 = x0f - pad_x * bw
        cx1 = x1f + pad_x * bw
        cy0 = y0f - pad_y_top * bh
        cy1 = y1f + pad_y_bot * bh

        x0px = max(0, int(cx0 * w))
        x1px = min(w, int(cx1 * w))
        y0px = max(0, int(cy0 * h))
        y1px = min(h, int(cy1 * h))

        crop = img[y0px:y1px, x0px:x1px]
        crops.append(crop)
        crop_meta.append({
            "block_idx": b,
            "x_frac": [x0f, x1f], "y_frac": [y0f, y1f],
            "crop_bbox_px": [x0px, y0px, x1px, y1px],
        })

    det["block_crops_meta"] = crop_meta
    return det, crops