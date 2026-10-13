#!/usr/bin/env python3
"""
EKSPLORASI 6 -- geometri_fullpage.py
Geometri SATU FOTO SELURUH HALAMAN formulir "LAPORAN PROSES DRAWING HARIAN".

Beda dgn E1-E5 (referensi = foto close-up grid, titik ORB kebanyakan jatuh
di tulisan tangan lembar referensi):
  - referensi = FORMULIR UTUH yg sudah diluruskan ke bingkai cetaknya
    ("kanvas", KANVAS_W x KANVAS_H px), dibuat SEKALI dari 1 foto
    (lihat buat_referensi());
  - titik fitur SIFT diambil HANYA dari bagian CETAKAN (logo, judul, label
    kiri, legenda kanan, label jam, catatan); semua area isian tulisan tangan
    dimasker -> tidak bergantung pada isi lembar referensi;
  - SIFT tahan skala & rotasi -> foto landscape/portrait/terbalik langsung
    dipetakan, tanpa OSD;
  - foto dipetakan (homography) ke kanvas -> semua field (header, Size,
    Speed, Operator, 3 baris Lost time) punya posisi tetap di kanvas;
  - posisi baris Lost time lalu di-snap ke garis cetak dgn refine_grid()
    (SALINAN dari E1/E4, tidak diubah) -> kertas melengkung tetap tertangani.

    python geometri_fullpage.py --buat-referensi foto_referensi.jpg   (sekali)
"""

import argparse
import json
import sys
from pathlib import Path

import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).parent))
from imgutil import imread_exif_safe  # noqa: E402
from refine_grid_eksplorasi import (  # noqa: E402
    N_COLS, cell_ink_ratios, refine_grid, refine_quality,
)

HERE = Path(__file__).parent
REF_DIR = HERE / "reference"
KANVAS_W, KANVAS_H = 3400, 2330
MATCH_SCALE = 0.5          # kanvas dicocokkan pada 1700 x 1165 px
FOTO_MATCH_PX = 2000       # sisi terpanjang foto saat pencocokan
MIN_INLIERS = 40

# Tata letak di kanvas (px). Kotak field sengaja longgar; isi dicari dari tinta.
LAYOUT = {
    "field": {
        "tanggal": [865, 40, 1440, 118],
        "mesin": [865, 112, 1320, 168],
        "shift": [865, 160, 1140, 220],
        "operator": [2325, 178, 2660, 245],
        "size": [415, 708, 1500, 762],
        "speed": [415, 756, 1500, 810],
    },
    # baris Lost time per shift: [y_atas, y_bawah]; kolom: 49 garis x0..x1
    "lost_time_x": [412, 2640],
    "lost_time_y": {"1": [1855, 1900], "2": [1950, 1995], "3": [2045, 2090]},
    # area isian tulisan tangan -> TIDAK dipakai utk titik fitur
    "masker_isian": [
        [690, 35, 1460, 228],      # nilai tanggal/mesin/shift
        [1380, 85, 2670, 250],     # koordinator/barcode/operator (paraf, nama)
        [340, 200, 2670, 860],     # nilai Data Material / Pemesanan / Size / Speed
        [340, 850, 2670, 1765],    # grid Proses & Produk Kontrol
        [340, 1845, 2670, 1908],   # Lost time shift 1
        [340, 1942, 2670, 2003],   # Lost time shift 2
        [340, 2037, 2670, 2100],   # Lost time shift 3
    ],
}


def _gray(img):
    return img if img.ndim == 2 else cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)


def _sift():
    return cv2.SIFT_create(nfeatures=8000)


def buat_referensi(foto, sudut_bingkai, out_dir=REF_DIR):
    """sudut_bingkai: 4 titik sudut bingkai cetak formulir di foto (TL,TR,BR,BL)."""
    out_dir = Path(out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    img = imread_exif_safe(foto)
    dst = np.float32([[0, 0], [KANVAS_W, 0], [KANVAS_W, KANVAS_H], [0, KANVAS_H]])
    M = cv2.getPerspectiveTransform(np.float32(sudut_bingkai), dst)
    kanvas = cv2.warpPerspective(img, M, (KANVAS_W, KANVAS_H), flags=cv2.INTER_CUBIC)
    cv2.imwrite(str(out_dir / "kanvas_referensi.jpg"), kanvas, [cv2.IMWRITE_JPEG_QUALITY, 92])

    small = cv2.resize(_gray(kanvas), None, fx=MATCH_SCALE, fy=MATCH_SCALE, interpolation=cv2.INTER_AREA)
    mask = np.full(small.shape, 255, np.uint8)
    for x0, y0, x1, y1 in LAYOUT["masker_isian"]:
        s = MATCH_SCALE
        mask[int(y0 * s):int(y1 * s), int(x0 * s):int(x1 * s)] = 0
    kp, des = _sift().detectAndCompute(small, mask)
    pts = np.float32([k.pt for k in kp]) / MATCH_SCALE
    np.savez_compressed(out_dir / "ref_fullpage.npz", kp_pts=pts, des=des)
    meta = {"sumber": Path(foto).name, "sudut_bingkai": np.asarray(sudut_bingkai).tolist(),
            "kanvas": [KANVAS_W, KANVAS_H], "n_titik": len(kp), "layout": LAYOUT}
    (out_dir / "ref_fullpage.json").write_text(json.dumps(meta, indent=1), encoding="utf-8")
    vis = cv2.cvtColor(small, cv2.COLOR_GRAY2BGR)
    vis[mask == 0] = (vis[mask == 0] * 0.6 + np.array([0, 0, 102])).astype(np.uint8)
    for p in pts * MATCH_SCALE:
        cv2.circle(vis, tuple(int(v) for v in p), 2, (0, 160, 0), -1)
    cv2.imwrite(str(out_dir / "titik_fitur_referensi.jpg"), vis)
    return meta


def load_referensi(ref_dir=REF_DIR):
    d = np.load(Path(ref_dir) / "ref_fullpage.npz")
    meta = json.loads((Path(ref_dir) / "ref_fullpage.json").read_text(encoding="utf-8"))
    return {"kp_pts": d["kp_pts"], "des": d["des"], "layout": meta["layout"]}


def daftarkan(foto_bgr, ref, ratio=0.75):
    """Homography foto -> kanvas. Return dict status + H (3x3, px penuh)."""
    h, w = foto_bgr.shape[:2]
    s = FOTO_MATCH_PX / max(h, w)
    small = cv2.resize(_gray(foto_bgr), None, fx=s, fy=s, interpolation=cv2.INTER_AREA)
    kp, des = _sift().detectAndCompute(small, None)
    if des is None or len(kp) < MIN_INLIERS:
        return {"status": "needs_retake", "reason": "fitur_foto_terlalu_sedikit"}
    matcher = cv2.FlannBasedMatcher({"algorithm": 1, "trees": 5}, {"checks": 64})
    pairs = matcher.knnMatch(des, ref["des"], k=2)
    good = [m for m, n in (p for p in pairs if len(p) == 2) if m.distance < ratio * n.distance]
    if len(good) < MIN_INLIERS:
        return {"status": "needs_retake", "reason": "cocok_terlalu_sedikit", "n_cocok": len(good)}
    src = np.float32([kp[m.queryIdx].pt for m in good]) / s
    dst = ref["kp_pts"][[m.trainIdx for m in good]]
    H, inl = cv2.findHomography(src, dst, cv2.RANSAC, 6.0, maxIters=5000, confidence=0.999)
    n_in = int(inl.sum()) if inl is not None else 0
    info = {"n_cocok": len(good), "n_inlier": n_in}
    if H is None or n_in < MIN_INLIERS:
        return {"status": "needs_retake", "reason": "homography_gagal", **info}

    # sanity: bingkai kanvas dipetakan balik ke foto harus segi-4 cembung & wajar
    Hi = np.linalg.inv(H)
    sudut = cv2.perspectiveTransform(
        np.float32([[0, 0], [KANVAS_W, 0], [KANVAS_W, KANVAS_H], [0, KANVAS_H]])[None], Hi)[0]
    if not cv2.isContourConvex(sudut.astype(np.float32)):
        return {"status": "needs_retake", "reason": "homography_tidak_wajar", **info}
    luas = cv2.contourArea(sudut) / (w * h)
    keluar = float(max(0, -sudut[:, 0].min(), -sudut[:, 1].min(),
                        sudut[:, 0].max() - w, sudut[:, 1].max() - h)) / max(w, h)
    info.update({"sudut_formulir_di_foto": sudut.round(1).tolist(),
                 "luas_formulir_frac": round(luas, 3), "keluar_frame_frac": round(keluar, 3),
                 "skala_px_per_kanvas": round(float(np.sqrt(abs(np.linalg.det(Hi[:2, :2])))), 3)})
    if luas < 0.08:
        return {"status": "needs_retake", "reason": "formulir_terlalu_kecil", **info}
    return {"status": "success", "H": H, **info}


def ke_kanvas(foto_bgr, H):
    return cv2.warpPerspective(foto_bgr, H, (KANVAS_W, KANVAS_H), flags=cv2.INTER_CUBIC,
                               borderMode=cv2.BORDER_CONSTANT, borderValue=(255, 255, 255))


def grid_lost_time(kanvas, shift, layout):
    """Kotak Lost time 1 shift di kanvas, di-snap ke garis cetak (refine_grid E1)."""
    x0, x1 = layout["lost_time_x"]
    y0, y1 = layout["lost_time_y"][str(shift)]
    cols = [(x0 + (x1 - x0) * i / (N_COLS - 1)) / KANVAS_W for i in range(N_COLS)]
    top = [y0 / KANVAS_H] * N_COLS
    bot = [y1 / KANVAS_H] * N_COLS
    ref = refine_grid(kanvas, cols, top, bot)
    if ref["status"] != "success":
        return {"status": "needs_manual_review", "reason": f"refine:{ref.get('reason')}", "refined": False,
                "top_pts_px": [[x * KANVAS_W, y0] for x in cols],
                "bot_pts_px": [[x * KANVAS_W, y1] for x in cols]}
    q = refine_quality(ref["metrics"])
    det = {"status": "success" if q == "good" else "needs_manual_review",
           "reason": None if q == "good" else "refine_partial", "refined": True,
           "refine": {"quality": q, "metrics": ref["metrics"]},
           "top_pts_px": ref["top_pts_px"], "bot_pts_px": ref["bot_pts_px"]}
    det["cell_ink"] = cell_ink_ratios(kanvas, det["top_pts_px"], det["bot_pts_px"])
    return det


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--buat-referensi", metavar="FOTO")
    ap.add_argument("--sudut", default="390,382,3702,374,3728,2664,377,2657",
                    help="4 sudut bingkai cetak di foto referensi: TLx,TLy,TRx,TRy,BRx,BRy,BLx,BLy")
    args = ap.parse_args()
    if args.buat_referensi:
        v = [float(t) for t in args.sudut.split(",")]
        meta = buat_referensi(args.buat_referensi, np.float32(v).reshape(4, 2))
        print(json.dumps({k: meta[k] for k in ("sumber", "n_titik")}))


if __name__ == "__main__":
    main()
