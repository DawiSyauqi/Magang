import cv2
import numpy as np

from imgutil import imread_exif_safe, detect_and_fix_orientation
from ocr_anchor import find_jam_rows_ocr
from reference_calib import ROW_TOP_RATIO

Y_TOLERANCE_FRAC = 0.015


def match_homography(ref_kp_pts, ref_des, target_gray, ratio=0.75, min_matches=10):
    orb = cv2.ORB_create(nfeatures=5000)
    kp2, des2 = orb.detectAndCompute(target_gray, None)
    if des2 is None or len(kp2) < 10:
        return {"status": "failed", "reason": "target_no_keypoints"}

    bf = cv2.BFMatcher(cv2.NORM_HAMMING)
    matches = bf.knnMatch(ref_des, des2, k=2)
    good = []
    for m_n in matches:
        if len(m_n) != 2:
            continue
        m, n = m_n
        if m.distance < ratio * n.distance:
            good.append(m)

    if len(good) < min_matches:
        return {"status": "failed", "reason": "not_enough_matches", "n_good": len(good)}

    src_pts = np.float32([ref_kp_pts[m.queryIdx] for m in good]).reshape(-1, 1, 2)
    dst_pts = np.float32([kp2[m.trainIdx].pt for m in good]).reshape(-1, 1, 2)

    H, mask = cv2.findHomography(src_pts, dst_pts, cv2.RANSAC, 5.0)
    if H is None:
        return {"status": "failed", "reason": "homography_not_found", "n_good": len(good)}

    n_inliers = int(mask.sum()) if mask is not None else 0
    return {
        "status": "success", "H": H,
        "n_good_matches": len(good), "n_inliers": n_inliers,
        "inlier_ratio": n_inliers / len(good) if good else 0,
    }


def transform_points_frac(H, points_frac, ref_w, ref_h, target_w, target_h):
    pts = np.array([[xf * ref_w, yf * ref_h] for xf, yf in points_frac], dtype=np.float32)
    pts = pts.reshape(-1, 1, 2)
    transformed = cv2.perspectiveTransform(pts, H)
    result = []
    for p in transformed.reshape(-1, 2):
        result.append((p[0] / target_w, p[1] / target_h))
    return result


def detect_grid(photo_path, shift, ref_kp_pts, ref_des, ref_w, ref_h, anchors,
                 y_tolerance_frac=Y_TOLERANCE_FRAC):
    raw = imread_exif_safe(photo_path)
    corrected, osd_ok, rot = detect_and_fix_orientation(raw)
    if not osd_ok:
        return {"status": "needs_retake", "reason": "osd_failed"}

    h, w = corrected.shape[:2]
    gray = cv2.cvtColor(corrected, cv2.COLOR_BGR2GRAY)

    hg = match_homography(ref_kp_pts, ref_des, gray)
    if hg["status"] != "success":
        return {"status": "needs_retake", "reason": f"homography_failed:{hg.get('reason')}",
                "homography_debug": hg}

    H = hg["H"]
    row_bounds_ref = anchors["shift_row_bounds_y_frac"][str(shift)]
    cols_ref = anchors["grid_columns_x_frac"]

    col_pts_top_frac = [(xf, row_bounds_ref[0]) for xf in cols_ref]
    col_pts_bot_frac = [(xf, row_bounds_ref[1]) for xf in cols_ref]
    col_pts_top_target = transform_points_frac(H, col_pts_top_frac, ref_w, ref_h, w, h)
    col_pts_bot_target = transform_points_frac(H, col_pts_bot_frac, ref_w, ref_h, w, h)

    cols_target_xfrac = [p[0] for p in col_pts_top_target]
    row_y_top_per_col = [p[1] for p in col_pts_top_target]
    row_y_bot_per_col = [p[1] for p in col_pts_bot_target]

    mid_idx = len(cols_ref) // 2
    y_top_homography = row_y_top_per_col[mid_idx]
    y_bot_homography = row_y_bot_per_col[mid_idx]

    result = {
        "status": "success",
        "confidence": "medium",
        "row_y_frac": [y_top_homography, y_bot_homography],
        "row_y_top_per_col": row_y_top_per_col,
        "row_y_bot_per_col": row_y_bot_per_col,
        "col_x_frac": cols_target_xfrac,
        "orientation_rot": rot,
        "homography_debug": {k: v for k, v in hg.items() if k != "H"},
        "image_shape": [h, w],
    }

    ocr_result = find_jam_rows_ocr(corrected)
    shift_idx = int(shift) - 1
    if ocr_result["status"] == "success" and len(ocr_result["jam_y_frac"]) == 3:
        ocr_jam_y = ocr_result["jam_y_frac"][shift_idx]
        ocr_spacing = ocr_result["spacing"]
        ocr_y_top = ocr_jam_y + ROW_TOP_RATIO * ocr_spacing
        diff = abs(ocr_y_top - y_top_homography)
        result["ocr_cross_check"] = {"ocr_y_top": ocr_y_top, "diff": diff}
        if diff < y_tolerance_frac:
            result["confidence"] = "high"
            offset = ocr_y_top - y_top_homography
            result["row_y_top_per_col"] = [y + offset for y in row_y_top_per_col]
            result["row_y_bot_per_col"] = [y + offset for y in row_y_bot_per_col]
            result["row_y_frac"] = [ocr_y_top, y_bot_homography + offset]
        else:
            result["status"] = "needs_manual_review"
            result["reason"] = "ocr_homography_disagree"
    else:
        result["ocr_cross_check"] = {"status": "ocr_failed"}

    result["corrected_image"] = corrected
    return result