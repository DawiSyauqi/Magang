"""
field_crop.py -- potong 1 field isian (header / Size / Speed / Operator) dari
kanvas E6 & siapkan utk TrOCR:
  1. tinta = adaptive threshold di kotak field (longgar, lihat LAYOUT);
  2. garis CETAK (garis bawah isian, garis tabel) dibuang: komponen
     horizontal/vertikal panjang hasil morfologi open;
  3. sisa tinta = tulisan tangan -> kotak pembatas gabungan komponen yg cukup
     besar; tinta terlalu sedikit = field KOSONG (null, model tdk dipanggil);
  4. sisa garis dibuang: potongan tipis yg menempel tepi kiri/kanan (garis
     tabel) & potongan pipih di bawah (sisa garis bawah isian);
  5. coretan (kata yg dicoret/diblok) dibuang: komponen lebar, padat, dan
     ada bagian yg jauh lebih TEBAL dari goresan biasa (jarak-ke-tepi maks
     >= CORET_TEBAL x lebar goresan khas field itu) -- lihat CORET_*;
  6. crop dari gambar asli (garis cetak & coretan diisi warna kertas),
     kontras diregangkan.
"""

import cv2
import numpy as np

MIN_KOMPONEN_PX = 40        # komponen lebih kecil = noda
MIN_TINTA_PX = 60           # total tinta tulisan < ini -> field kosong ("1" kecil ~100 px)
PAD_PX = 14
CORET_TEBAL = 2.0           # tebal maks / lebar goresan khas >= ini ...
CORET_PADAT = 0.37          # ... rasio tinta dlm bbox >= ini ...
CORET_MIN_LEBAR = 40        # ... dan lebar >= ini px kanvas -> coretan


def _normalize(gray):
    lo = np.percentile(gray, 1)
    hi = np.percentile(gray, 90)
    out = np.clip((gray.astype(np.float32) - lo) * 255.0 / max(1.0, hi - lo), 0, 255)
    return out.astype(np.uint8)


def crop_field(kanvas, box, buang_coretan=True):
    x0, y0, x1, y1 = box
    roi = kanvas[y0:y1, x0:x1]
    gray = cv2.cvtColor(roi, cv2.COLOR_BGR2GRAY)
    bw = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_MEAN_C, cv2.THRESH_BINARY_INV, 31, 15)
    h, w = bw.shape
    hor = cv2.morphologyEx(bw, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_RECT, (max(60, w // 6), 1)))
    ver = cv2.morphologyEx(bw, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_RECT, (1, max(30, int(h * 0.7)))))
    garis = cv2.dilate(cv2.bitwise_or(hor, ver), np.ones((5, 5), np.uint8))
    ink = cv2.bitwise_and(bw, cv2.bitwise_not(garis))

    n, lab, st, _ = cv2.connectedComponentsWithStats(cv2.dilate(ink, np.ones((3, 3), np.uint8)), 8)
    keep = np.zeros_like(ink)
    coret = np.zeros_like(ink)
    info = {"n_komponen": 0, "n_coretan": 0}
    dist = cv2.distanceTransform(ink, cv2.DIST_L2, 3)
    sw = float(np.percentile(dist[ink > 0], 75)) if ink.any() else 1.0
    for i in range(1, n):
        x, y, cw, ch, area = st[i]
        if area < MIN_KOMPONEN_PX:
            continue
        if cw <= 8 and (x <= 2 or x + cw >= w - 2):
            continue  # sisa garis tabel vertikal di tepi
        if ch <= 6 and y + ch / 2 > 0.75 * h:
            continue  # sisa garis bawah isian
        comp = (lab == i) & (ink > 0)
        padat = comp.sum() / float(cw * ch)
        tebal = dist[comp].max() / max(sw, 1.0)
        if buang_coretan and tebal >= CORET_TEBAL and padat >= CORET_PADAT and cw >= CORET_MIN_LEBAR:
            coret[lab == i] = 255
            info["n_coretan"] += 1
            continue
        keep[comp] = 255
        info["n_komponen"] += 1
    info["tinta_px"] = int((keep > 0).sum())
    if info["tinta_px"] < MIN_TINTA_PX:
        return None, info

    ys, xs = np.nonzero(keep)
    bx0, bx1 = max(0, xs.min() - PAD_PX), min(w, xs.max() + PAD_PX + 1)
    by0, by1 = max(0, ys.min() - PAD_PX), min(h, ys.max() + PAD_PX + 1)
    bg = int(np.percentile(gray, 85))
    clean = gray.copy()
    hapus = cv2.bitwise_or(garis, cv2.dilate(coret, np.ones((5, 5), np.uint8)))
    clean[(hapus > 0) & (keep == 0)] = bg
    out = _normalize(clean[by0:by1, bx0:bx1])
    info["bbox"] = [int(x0 + bx0), int(y0 + by0), int(x0 + bx1), int(y0 + by1)]
    return cv2.cvtColor(out, cv2.COLOR_GRAY2BGR), info
