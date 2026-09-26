"""
EKSPLORASI 2 -- grid_overlay.py
Gambar garis bantu di atas crop blok jam, SEBELUM dikirim ke Ollama.
5 garis vertikal MENEMBUS PENUH dari atas sampai bawah crop (bukan
cuma di rentang baris Lost-time) -- posisi X tetap dihitung dari
geometri asli (x_frac dari detect_grid), BUKAN dibagi rata dari tepi
crop. Garis horizontal tetap menandai batas atas/bawah baris Lost-time
saja (supaya model tetap tahu baris mana yg dimaksud di antara padding).
"""

import cv2

OVERLAY_LINE_COLOR = (0, 0, 255)   # merah, BGR
OVERLAY_ROW_LINE_COLOR = (255, 255, 0)  # cyan, BGR -- beda warna dari garis kolom
OVERLAY_TEXT_COLOR = (0, 200, 0)   # hijau, BGR
OVERLAY_LINE_THICKNESS = 2
OVERLAY_FONT_SCALE = 0.6


def draw_grid_overlay(crop, meta, img_h, img_w, n_cells=6):
    """
    crop  : hasil crop_jam_blocks() utk 1 blok (BGR, numpy array).
    meta  : 1 elemen dari det["block_crops_meta"] -- "x_frac" [x0f,x1f]
            (batas BLOK relatif ke gambar ASLI), "y_frac" [y0f,y1f]
            (batas baris Lost-time), "crop_bbox_px" [x0px,y0px,x1px,y1px].
    img_h, img_w : dimensi gambar hasil detect_grid (det["image_shape"]).
    """
    x0f, x1f = meta["x_frac"]
    y0f, y1f = meta["y_frac"]
    x0px, y0px, x1px, y1px = meta["crop_bbox_px"]

    img = crop.copy()
    crop_h, crop_w = img.shape[:2]

    # Konversi batas BLOK ke koordinat LOKAL crop (horizontal)
    local_bx0 = x0f * img_w - x0px
    local_bx1 = x1f * img_w - x0px
    # Konversi batas baris Lost-time ke koordinat LOKAL crop (vertikal) --
    # tetap dihitung, dipakai utk garis horizontal & posisi nomor
    local_ry0 = y0f * img_h - y0px
    local_ry1 = y1f * img_h - y0px

    cell_w = (local_bx1 - local_bx0) / n_cells

    # 5 garis vertikal pemisah 6 kotak -- MENEMBUS PENUH dari y=0 sampai
    # y=crop_h (seluruh tinggi crop, termasuk area padding atas/bawah)
    for i in range(1, n_cells):
        x = int(local_bx0 + i * cell_w)
        cv2.line(img, (x, 0), (x, crop_h), OVERLAY_LINE_COLOR, OVERLAY_LINE_THICKNESS)

    # Garis horizontal batas atas & bawah baris Lost-time -- tetap hanya
    # sepanjang lebar blok, warna beda (cyan) supaya jelas beda peran dari
    # garis vertikal (garis ini "baris mana", garis vertikal "kotak mana")
    cv2.line(img, (int(local_bx0), int(local_ry0)), (int(local_bx1), int(local_ry0)),
              OVERLAY_ROW_LINE_COLOR, OVERLAY_LINE_THICKNESS)
    cv2.line(img, (int(local_bx0), int(local_ry1)), (int(local_bx1), int(local_ry1)),
              OVERLAY_ROW_LINE_COLOR, OVERLAY_LINE_THICKNESS)

    # Nomor 1-6, ditaruh di dalam rentang baris Lost-time (bukan di tepi
    # crop) supaya tetap jelas nomor ini utk baris yg mana
    ty = int(local_ry0 - 8) if local_ry0 - 8 >= 12 else int(local_ry0 + 18)
    for i in range(n_cells):
        cx = int(local_bx0 + (i + 0.5) * cell_w)
        cv2.putText(img, str(i + 1), (cx - 6, ty),
                    cv2.FONT_HERSHEY_SIMPLEX, OVERLAY_FONT_SCALE,
                    OVERLAY_TEXT_COLOR, OVERLAY_LINE_THICKNESS)

    return img