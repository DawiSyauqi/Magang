"""
EKSPLORASI 2 (refine) -- crop_blocks_refine_eksplorasi.py
Pengganti crop_blocks.py + grid_overlay.py yg memakai grid hasil REFINE
(refine_grid_eksplorasi.py). Beda utama dgn versi lama:
  - tiap blok di-crop dgn PERSPECTIVE WARP dari 4 sudut blok yg presisi,
    jadi crop sudah lurus (tdk miring) & ukurannya seragam antar blok/foto;
  - garis bantu merah (5 pemisah kotak) & cyan (batas baris) digambar di
    posisi garis cetak ASLI hasil snap, bukan dibagi rata;
  - tiap blok membawa info tinta per kotak (cell_ink) utk ink-gate.
Gaya overlay (warna, nomor 1-6) SAMA dgn grid_overlay.py supaya BLOCK_PROMPT
di prompt.py tetap berlaku.
"""

import cv2
import numpy as np

from refine_grid_eksplorasi import detect_grid_fast, detect_grid_refined, N_BLOCKS, N_SUBCELLS

CELL_PX = 80              # lebar 1 sub-kotak di crop hasil warp
BLOCK_PAD_X_FRAC = 0.02   # thd lebar blok
BLOCK_PAD_Y_TOP_FRAC = 0.9  # thd tinggi baris (supaya label jam ikut terlihat)
BLOCK_PAD_Y_BOT_FRAC = 0.3

OVERLAY_LINE_COLOR = (0, 0, 255)        # merah, BGR
OVERLAY_ROW_LINE_COLOR = (255, 255, 0)  # cyan, BGR
OVERLAY_TEXT_COLOR = (0, 200, 0)        # hijau, BGR
OVERLAY_LINE_THICKNESS = 2
OVERLAY_FONT_SCALE = 0.6


def _warp_pts(H, pts):
    pts = np.asarray(pts, np.float32).reshape(-1, 1, 2)
    return cv2.perspectiveTransform(pts, H).reshape(-1, 2)


def crop_block_rectified(image, top_px, bot_px, b, cell_px=CELL_PX,
                         pad_x=BLOCK_PAD_X_FRAC, pad_y_top=BLOCK_PAD_Y_TOP_FRAC,
                         pad_y_bot=BLOCK_PAD_Y_BOT_FRAC):
    """Warp blok ke-b jadi crop lurus. Return (crop, meta) -- meta berisi
    posisi 7 garis vertikal (atas & bawah) & batas baris di koordinat crop."""
    i0, i1 = b * N_SUBCELLS, (b + 1) * N_SUBCELLS
    tl, tr, br, bl = top_px[i0], top_px[i1], bot_px[i1], bot_px[i0]
    width_img = (np.linalg.norm(np.subtract(tr, tl)) + np.linalg.norm(np.subtract(br, bl))) / 2
    height_img = (np.linalg.norm(np.subtract(bl, tl)) + np.linalg.norm(np.subtract(br, tr))) / 2

    bw = N_SUBCELLS * cell_px
    bh = int(round(bw * height_img / width_img))
    px = int(round(pad_x * bw))
    pt = int(round(pad_y_top * bh))
    pb = int(round(pad_y_bot * bh))
    out_w, out_h = bw + 2 * px, pt + bh + pb

    src = np.float32([tl, tr, br, bl])
    dst = np.float32([[px, pt], [px + bw, pt], [px + bw, pt + bh], [px, pt + bh]])
    H = cv2.getPerspectiveTransform(src, dst)
    crop = cv2.warpPerspective(image, H, (out_w, out_h), flags=cv2.INTER_CUBIC,
                               borderMode=cv2.BORDER_REPLICATE)

    lines_top = _warp_pts(H, top_px[i0:i1 + 1])
    lines_bot = _warp_pts(H, bot_px[i0:i1 + 1])
    meta = {
        "block_idx": b,
        "crop_size": [out_w, out_h],
        "row_y_crop": [float(pt), float(pt + bh)],
        "lines_top_crop": lines_top.tolist(),
        "lines_bot_crop": lines_bot.tolist(),
        "block_corners_img_px": np.asarray(src).tolist(),
    }
    return crop, meta


def draw_block_overlay(crop, meta, n_cells=N_SUBCELLS):
    """Garis bantu gaya grid_overlay.py, tp di posisi garis cetak asli."""
    img = crop.copy()
    h, _ = img.shape[:2]
    lt = np.asarray(meta["lines_top_crop"])
    lb = np.asarray(meta["lines_bot_crop"])
    ry0, ry1 = meta["row_y_crop"]

    # 5 garis merah pemisah kotak, diperpanjang menembus seluruh tinggi crop
    for i in range(1, n_cells):
        (x0, y0), (x1, y1) = lt[i], lb[i]
        slope = (x1 - x0) / (y1 - y0) if y1 != y0 else 0.0
        xa = x0 + slope * (0 - y0)
        xb = x0 + slope * (h - y0)
        cv2.line(img, (int(round(xa)), 0), (int(round(xb)), h), OVERLAY_LINE_COLOR, OVERLAY_LINE_THICKNESS)

    # 2 garis cyan batas atas & bawah baris Lost-time
    cv2.line(img, tuple(int(round(v)) for v in lt[0]), tuple(int(round(v)) for v in lt[-1]),
             OVERLAY_ROW_LINE_COLOR, OVERLAY_LINE_THICKNESS)
    cv2.line(img, tuple(int(round(v)) for v in lb[0]), tuple(int(round(v)) for v in lb[-1]),
             OVERLAY_ROW_LINE_COLOR, OVERLAY_LINE_THICKNESS)

    # nomor 1-6 tepat di atas garis cyan atas
    ty = int(ry0 - 8) if ry0 - 8 >= 12 else int(ry0 + 18)
    for i in range(n_cells):
        cx = int((lt[i][0] + lt[i + 1][0]) / 2)
        cv2.putText(img, str(i + 1), (cx - 6, ty), cv2.FONT_HERSHEY_SIMPLEX,
                    OVERLAY_FONT_SCALE, OVERLAY_TEXT_COLOR, OVERLAY_LINE_THICKNESS)
    return img


def crop_jam_blocks_refined(photo_path, shift, ref_kp_pts, ref_des, ref_w, ref_h, anchors,
                            with_overlay=True, fast=True, **crop_kwargs):
    """Return (det, crops). crops None kalau geometri gagal. Tiap elemen
    det["block_crops_meta"] punya "cell_ink" (6 nilai) kalau refine sukses.
    fast=True: geometri jalur cepat (tanpa OSD/OCR, lihat detect_grid_fast);
    fast=False: jalur lama detect_grid (OSD + OCR) + refine."""
    if fast:
        det = detect_grid_fast(photo_path, shift, ref_kp_pts, ref_des, ref_w, ref_h, anchors)
    else:
        det = detect_grid_refined(photo_path, shift, ref_kp_pts, ref_des, ref_w, ref_h, anchors)
    if det["status"] not in ("success", "needs_manual_review"):
        return det, None

    img = det["corrected_image"]
    top_px = np.asarray(det["top_pts_px"], np.float32)
    bot_px = np.asarray(det["bot_pts_px"], np.float32)
    ink = det.get("cell_ink")

    crops, metas = [], []
    for b in range(N_BLOCKS):
        crop, meta = crop_block_rectified(img, top_px, bot_px, b, **crop_kwargs)
        if ink is not None:
            meta["cell_ink"] = ink[b * N_SUBCELLS:(b + 1) * N_SUBCELLS]
        # crop polos tetap disimpan (tdk ikut ke JSON): dipakai tanya ulang "x",
        # krn garis bantu terbukti bisa mengganggu pembacaan angka "0"
        meta["clean_crop"] = crop
        if with_overlay:
            crop = draw_block_overlay(crop, meta)
        crops.append(crop)
        metas.append(meta)

    det["block_crops_meta"] = metas
    return det, crops
