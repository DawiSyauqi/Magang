import cv2
import numpy as np
import json

from imgutil import imread_exif_safe, detect_and_fix_orientation
from ocr_anchor import find_jam_rows_ocr, find_label_hits, filter_jam_lost_tokens

N_BLOCKS = 8
N_SUBCELLS = 6

# Rasio empiris (dari kalibrasi visual thd foto referensi 134505): posisi
# baris "Lost time" relatif thd jam_y (anchor "Jam") & spacing antar-Jam.
ROW_TOP_RATIO = 0.26
ROW_BOT_RATIO = 0.76


def find_lost_y_near(image_bgr, jam_y, spacing, scale=2.0, psm=6):
    hits = find_label_hits(image_bgr, scale=scale, psm=psm)
    _, lost_hits = filter_jam_lost_tokens(hits)
    if not lost_hits:
        return None
    target = jam_y + spacing * 0.5
    candidates = [h for h in lost_hits if abs(h['y_frac'] - target) < spacing * 0.7]
    if not candidates:
        return None
    return sorted(candidates, key=lambda h: abs(h['y_frac'] - target))[0]['y_frac']


def compute_column_bounds(left_frac, right_frac, n_blocks=N_BLOCKS):
    block_w = (right_frac - left_frac) / n_blocks
    cols = []
    for b in range(n_blocks):
        b_left = left_frac + b * block_w
        for c in range(N_SUBCELLS):
            cols.append(b_left + c * (block_w / N_SUBCELLS))
    cols.append(right_frac)
    return cols  # 49 nilai


def calibrate_reference(image_path, left_frac, right_frac, out_prefix):
    raw = imread_exif_safe(image_path)
    corrected, osd_ok, rot = detect_and_fix_orientation(raw)
    if not osd_ok:
        raise RuntimeError("OSD gagal di foto referensi -- pilih foto referensi lain.")

    row_result = find_jam_rows_ocr(corrected)
    if row_result['status'] != 'success':
        raise RuntimeError("OCR-anchor baris gagal di foto referensi -- pilih foto referensi lain.")

    jam_y_frac = row_result['jam_y_frac']
    spacing = row_result['spacing']

    shift_row_bounds = {}
    for i, key in enumerate(["1", "2", "3"]):
        shift_row_bounds[key] = [
            jam_y_frac[i] + ROW_TOP_RATIO * spacing,
            jam_y_frac[i] + ROW_BOT_RATIO * spacing,
        ]

    lost_y_check = find_lost_y_near(corrected, jam_y_frac[0], spacing)
    cols = compute_column_bounds(left_frac, right_frac)

    gray = cv2.cvtColor(corrected, cv2.COLOR_BGR2GRAY)
    orb = cv2.ORB_create(nfeatures=5000)
    kp, des = orb.detectAndCompute(gray, None)
    kp_pts = np.array([k.pt for k in kp], dtype=np.float32)

    h, w = corrected.shape[:2]
    np.savez(f"{out_prefix}_template.npz", kp_pts=kp_pts, des=des, img_h=h, img_w=w)

    anchors = {
        "source_image": image_path,
        "orientation_rot_applied": rot,
        "left_frac": left_frac,
        "right_frac": right_frac,
        "shift_row_bounds_y_frac": shift_row_bounds,
        "jam_y_frac_raw": jam_y_frac,
        "row_spacing_frac": spacing,
        "lost_y_check_shift1": lost_y_check,
        "row_top_ratio": ROW_TOP_RATIO,
        "row_bot_ratio": ROW_BOT_RATIO,
        "grid_columns_x_frac": cols,
    }
    with open(f"{out_prefix}_anchors.json", "w") as f:
        json.dump(anchors, f, indent=2)

    return anchors