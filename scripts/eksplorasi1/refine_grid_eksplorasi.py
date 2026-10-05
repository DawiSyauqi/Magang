"""
refine_grid_eksplorasi.py -- REFINEMENT posisi grid baris "Lost time".

Masalah versi lama (detect_grid.py): posisi grid HANYA dari homography ORB
thd 1 foto referensi + koreksi offset OCR. Hasilnya kasar:
  - garis atas/bawah baris bisa geser setengah baris & miring,
  - 48 sub-kotak dibagi rata dari referensi, tdk menempel garis cetak asli,
  - garis kolom selalu tegak lurus sumbu gambar (x atas == x bawah),
    padahal di foto garis cetak miring & kemiringannya berubah sepanjang
    baris (efek perspektif).

Pendekatan di sini: HYBRID. Hasil detect_grid tetap dipakai sbg posisi
AWAL (supaya tahu blok mana = jam berapa, jadi tdk mengulang kegagalan
deteksi garis global / Hough spt di laporan: jumlah blok lebih / tertukar).
Lalu tiap garis di-SNAP ke garis cetak asli terdekat dlm jendela kecil:
  0. baris diluruskan jadi "strip" (affine), + koreksi medan shear
     (kemiringan garis vertikal per posisi x, fit linier antar blok);
  1. garis atas/bawah baris Lost-time per blok (morfologi horizontal),
     dibedakan dari baris "Jam" lewat kepadatan garis sub-kotak, lalu fit
     polinomial robust sepanjang baris;
  2a. BATAS BLOK: garis vertikal yg menembus baris "Jam" (tinggi >= 1.4
     baris) -- garis sub-kotak cuma setinggi 1 baris, jadi tdk bisa tertukar;
  2b. 5 garis sub-kotak per blok: bagi rata antar batas blok lalu snap lokal,
     dgn cek konsistensi jarak (fit linier per blok, outlier diganti fit);
  3. semua titik dipetakan balik ke koordinat gambar.

Bonus: cell_ink_ratios() -- ukur proporsi tinta di dlm tiap kotak (setelah
diluruskan & margin garis dibuang). Kotak kosong ~0%, kotak berisi >=~8%,
sehingga "kosong vs berisi" bisa diputuskan deterministik tanpa model.

Output kompatibel dgn detect_grid (col_x_frac, row_y_top_per_col,
row_y_bot_per_col) + titik presisi per garis (top_pts_px, bot_pts_px) yg
MIRING sesuai foto.
"""

import cv2
import numpy as np

N_BLOCKS = 8
N_SUBCELLS = 6
N_COLS = N_BLOCKS * N_SUBCELLS + 1  # 49


def _odd(n, lo=3):
    n = max(lo, int(n))
    return n if n % 2 == 1 else n + 1


def _find_peaks_1d(profile, min_height, min_dist):
    peaks = []
    n = len(profile)
    for i in range(1, n - 1):
        v = profile[i]
        if v < min_height or v < profile[i - 1] or v < profile[i + 1]:
            continue
        lo, hi = max(0, i - min_dist), min(n, i + min_dist + 1)
        if v >= profile[lo:hi].max():
            if peaks and i - peaks[-1] < min_dist:
                continue
            peaks.append(i)
    return peaks


def _subpixel(profile, i):
    if 0 < i < len(profile) - 1:
        a, b, c = profile[i - 1], profile[i], profile[i + 1]
        den = a - 2 * b + c
        if den != 0:
            return float(i + 0.5 * (a - c) / den)
    return float(i)


def _robust_polyfit(xs, ys, deg, thresh, iters=3):
    xs, ys = np.asarray(xs, float), np.asarray(ys, float)
    keep = np.ones(len(xs), bool)
    coef = None
    for _ in range(iters):
        d = min(deg, int(keep.sum()) - 1)
        if d < 0:
            break
        coef = np.polyfit(xs[keep], ys[keep], d)
        res = np.abs(np.polyval(coef, xs) - ys)
        new_keep = res < thresh
        if new_keep.sum() < max(2, deg + 1) or (new_keep == keep).all():
            break
        keep = new_keep
    return coef, keep


def _smooth1d(p, k=5):
    return cv2.GaussianBlur(np.asarray(p, np.float32).reshape(1, -1), (k, 1), 0).ravel()


def refine_grid(image, col_x_frac, row_y_top_per_col, row_y_bot_per_col, debug=False):
    h, w = image.shape[:2]
    T = np.array([[x * w, y * h] for x, y in zip(col_x_frac, row_y_top_per_col)], np.float32)
    B = np.array([[x * w, y * h] for x, y in zip(col_x_frac, row_y_bot_per_col)], np.float32)

    row_h = float(np.median(np.linalg.norm(B - T, axis=1)))
    length = float(np.linalg.norm(T[-1] - T[0]))
    cell = length / (N_COLS - 1)

    # sanity check hasil coarse
    if (np.diff(T[:, 0]) <= 0).any():
        return {"status": "refine_failed", "reason": "coarse_columns_not_monotonic"}
    if T[:, 0].min() < 0 or T[:, 0].max() > w or T[:, 1].min() < 0 or B[:, 1].max() > h:
        return {"status": "refine_failed", "reason": "coarse_outside_image"}
    if not (0.5 < row_h / cell < 2.0):
        return {"status": "refine_failed", "reason": "coarse_aspect_implausible",
                "row_h_px": row_h, "cell_w_px": cell}

    mu = 1.5 * cell
    mv = 2.5 * row_h
    Wr = int(round(length + 2 * mu))
    Hr = int(round(row_h + 2 * mv))
    vc = mv + row_h / 2
    uu = np.arange(Wr, dtype=np.float32)
    V = np.arange(Hr, dtype=np.float32)[:, None]

    # ---------- 0) strip lurus: affine gambar -> strip ----------
    src = np.vstack([T, B])
    dst = np.vstack([
        np.stack([mu + np.arange(N_COLS) * cell, np.full(N_COLS, mv)], 1),
        np.stack([mu + np.arange(N_COLS) * cell, np.full(N_COLS, mv + row_h)], 1),
    ]).astype(np.float32)
    A, _ = cv2.estimateAffine2D(src, dst, method=cv2.LMEDS)
    A_inv = cv2.invertAffineTransform(A)
    strip = cv2.warpAffine(image, A, (Wr, Hr), flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_REPLICATE)
    gray = cv2.cvtColor(strip, cv2.COLOR_BGR2GRAY)
    bw0 = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_MEAN_C, cv2.THRESH_BINARY_INV,
                                _odd(cell * 0.8, 15), 12)
    coarse_u = (A[:, :2] @ T.T + A[:, 2:3])[0]

    # ---------- 0b) medan shear: kemiringan garis vertikal per posisi u ----------
    # Kolom coarse selalu tegak lurus sumbu gambar, padahal garis cetak di foto
    # miring & kemiringannya BERUBAH sepanjang baris (efek perspektif). Estimasi
    # per blok, lalu fit linier thd u.
    vshort = cv2.morphologyEx(bw0, cv2.MORPH_OPEN,
                              cv2.getStructuringElement(cv2.MORPH_RECT, (1, _odd(row_h * 0.35))))
    vshort = vshort.astype(np.float32)
    shears = np.linspace(-0.15, 0.15, 61)
    block_scores = np.zeros((len(shears), N_BLOCKS))
    for si, sh in enumerate(shears):
        S = np.float32([[1, sh, -sh * vc], [0, 1, 0]])
        p = cv2.warpAffine(vshort, S, (Wr, Hr)).sum(0)
        for b in range(N_BLOCKS):
            u0, u1 = int(coarse_u[b * 6]), int(coarse_u[(b + 1) * 6])
            block_scores[si, b] = float((p[u0:u1] ** 2).sum())
    # CATATAN tanda: warpAffine S memetakan (u,v)->(u+sh(v-vc), v); garis
    # di strip dgn kemiringan x = u0 - sh*(v-vc) jadi tegak.
    block_sh = shears[block_scores.argmax(0)]
    block_uc = np.array([(coarse_u[b * 6] + coarse_u[(b + 1) * 6]) / 2 for b in range(N_BLOCKS)])
    sh_coef, _ = _robust_polyfit(block_uc, block_sh, 1, 0.03)
    sh_u = np.polyval(sh_coef, uu).astype(np.float32)  # shear di tiap u (koord. strip lurus)

    # strip "lurus" final: titik (u,v) diambil dari strip affine di (u - sh(u)(v-vc), v)
    def to_affine_strip(u, v):
        u = np.asarray(u, np.float64); v = np.asarray(v, np.float64)
        return u - np.polyval(sh_coef, u) * (v - vc), v

    map_x, map_y = np.meshgrid(uu, np.arange(Hr, dtype=np.float32))
    map_x = (map_x - sh_u[None, :] * (map_y - vc)).astype(np.float32)
    bw = cv2.remap(bw0, map_x, map_y, cv2.INTER_NEAREST, borderMode=cv2.BORDER_CONSTANT)

    hmask = cv2.morphologyEx(bw, cv2.MORPH_OPEN,
                             cv2.getStructuringElement(cv2.MORPH_RECT, (_odd(cell * 1.3), 1)))
    vmask = cv2.morphologyEx(bw, cv2.MORPH_OPEN,
                             cv2.getStructuringElement(cv2.MORPH_RECT, (1, _odd(row_h * 0.45))))
    tall = cv2.morphologyEx(bw, cv2.MORPH_OPEN,
                            cv2.getStructuringElement(cv2.MORPH_RECT, (1, _odd(row_h * 1.4))))
    hmask = (hmask > 0).astype(np.float32)
    vmask = (vmask > 0).astype(np.float32)
    tall = (tall > 0).astype(np.float32)

    # ---------- 1) batas atas/bawah baris per blok ----------
    block_pts = []
    coarse_mid = mv + row_h / 2
    for b in range(N_BLOCKS):
        u0 = int(coarse_u[b * 6] + 0.15 * cell)
        u1 = int(coarse_u[(b + 1) * 6] - 0.15 * cell)
        prof = cv2.GaussianBlur(hmask[:, u0:u1].mean(1).reshape(-1, 1), (1, 5), 0).ravel()
        peaks = _find_peaks_1d(prof, 0.25, max(3, int(row_h * 0.3)))
        best = None
        for k in range(len(peaks) - 1):
            p0, p1 = peaks[k], peaks[k + 1]
            gap = p1 - p0
            if not (0.6 * row_h <= gap <= 1.45 * row_h):
                continue
            a0, a1 = int(p0 + 0.2 * gap), int(p1 - 0.2 * gap)
            # baris "Lost time" punya garis sub-kotak -> densitas garis vertikal tinggi;
            # baris "Jam" tdk punya (hanya teks label jam)
            vcol = vmask[a0:a1, u0:u1].mean(0)
            density = float((vcol > 0.5).sum()) / max(1, (u1 - u0)) * cell
            dist = abs((p0 + p1) / 2 - coarse_mid) / row_h
            if density < 1.5 or dist > 1.0:
                continue
            score = density - 2.0 * dist
            if best is None or score > best[0]:
                best = (score, _subpixel(prof, p0), _subpixel(prof, p1))
        if best is not None:
            block_pts.append(((u0 + u1) / 2, best[1], best[2]))
        if debug:
            print(f"  blok {b}: peaks={peaks} best={best}")

    if len(block_pts) < 3:
        return {"status": "refine_failed", "reason": "horizontal_lines_not_found",
                "n_blocks_found": len(block_pts)}

    bu = [p[0] for p in block_pts]
    deg = 2 if len(block_pts) >= 6 else 1
    top_coef, _ = _robust_polyfit(bu, [p[1] for p in block_pts], deg, 0.15 * row_h)
    bot_coef, _ = _robust_polyfit(bu, [p[2] for p in block_pts], deg, 0.15 * row_h)
    top_v = np.polyval(top_coef, uu)
    bot_v = np.polyval(bot_coef, uu)
    gap_v = bot_v - top_v

    # ---------- 2) garis vertikal ----------
    band = (V > top_v + 0.15 * gap_v) & (V < bot_v - 0.15 * gap_v)
    vprof = _smooth1d((vmask * band).sum(0) / np.maximum(1, band.sum(0)))

    center_u = coarse_u.mean()
    best_g = (-1, 0.0, 1.0)
    for s in np.arange(0.985, 1.0151, 0.0025):
        for dx in np.arange(-0.6 * cell, 0.6 * cell + 0.01, 0.5):
            q = center_u + s * (coarse_u - center_u) + dx
            sc = float(np.interp(q, uu, vprof).sum())
            if sc > best_g[0]:
                best_g = (sc, dx, s)
    _, gdx, gs = best_g
    pred = center_u + gs * (coarse_u - center_u) + gdx

    # 2a) BATAS BLOK dulu: satu2nya garis vertikal yg menembus baris "Jam"
    #     (tinggi >= ~1.4 baris). Garis sub-kotak cuma setinggi 1 baris, jadi
    #     tdk mungkin tertukar dgn batas blok.
    tband = (V > top_v - 0.9 * gap_v) & (V < bot_v - 0.1 * gap_v)
    tprof = _smooth1d((tall * tband).sum(0) / np.maximum(1, tband.sum(0)))

    border_pred = pred[::6]
    border = border_pred.copy()
    border_ok = np.zeros(N_BLOCKS + 1, bool)
    bwin = int(2.0 * cell)
    for k, q in enumerate(border_pred):
        lo, hi = max(0, int(q - bwin)), min(Wr - 1, int(q + bwin))
        seg = tprof[lo:hi + 1]
        if len(seg) == 0:
            continue
        dist_pen = 0.15 * np.abs(np.arange(lo, hi + 1) - q) / cell
        j = int((seg - dist_pen).argmax())
        if seg[j] >= 0.5:
            border[k] = _subpixel(tprof, lo + j)
            border_ok[k] = True
    ks = np.arange(N_BLOCKS + 1)
    if border_ok.sum() >= 4:
        coef, _ = _robust_polyfit(ks[border_ok], border[border_ok], 2, 0.3 * cell)
        fit = np.polyval(coef, ks)
        bad = ~border_ok | (np.abs(border - fit) > 0.3 * cell)
        border[bad] = fit[bad]
        border_ok &= ~bad
    else:
        border = border_pred
        border_ok[:] = False

    # 2b) garis sub-kotak: bagi rata antar batas blok, lalu snap lokal
    subpred = np.empty(N_COLS)
    for b in range(N_BLOCKS):
        subpred[b * 6:(b + 1) * 6 + 1] = np.linspace(border[b], border[b + 1], 7)

    snapped = subpred.copy()
    snapped_ok = np.zeros(N_COLS, bool)
    snapped_ok[::6] = border_ok
    for i, q in enumerate(subpred):
        if i % 6 == 0:
            continue
        local_cell = (border[i // 6 + 1] - border[i // 6]) / 6
        win = int(max(2, 0.25 * local_cell))
        lo, hi = max(0, int(q - win)), min(Wr - 1, int(q + win))
        seg = vprof[lo:hi + 1]
        if len(seg) == 0:
            continue
        j = int(seg.argmax())
        if seg[j] >= 0.35:
            snapped[i] = _subpixel(vprof, lo + j)
            snapped_ok[i] = True

    # konsistensi per blok: 7 garis dlm 1 blok hrs ~berjarak sama
    final_u = snapped.copy()
    for b in range(N_BLOCKS):
        idx = np.arange(b * 6, b * 6 + 7)
        local_cell = (border[b + 1] - border[b]) / 6
        ok = snapped_ok[idx]
        if ok.sum() >= 3:
            coef, _ = _robust_polyfit(idx[ok], snapped[idx][ok], 1, 0.12 * local_cell)
            fit = np.polyval(coef, idx)
            for k, i in enumerate(idx):
                if i % 6 == 0 and border_ok[i // 6]:
                    continue
                if not snapped_ok[i] or abs(snapped[i] - fit[k]) > 0.12 * local_cell:
                    final_u[i] = fit[k]
                    snapped_ok[i] = False
        else:
            final_u[idx[1:-1]] = subpred[idx[1:-1]]

    # ---------- 3) kembalikan ke koordinat gambar ----------
    def to_img(u, v):
        au, av = to_affine_strip(u, v)
        p = np.stack([au, av], 1)
        return (A_inv[:, :2] @ p.T + A_inv[:, 2:3]).T

    top_img = to_img(final_u, np.polyval(top_coef, final_u))
    bot_img = to_img(final_u, np.polyval(bot_coef, final_u))

    res_top = np.abs(np.polyval(top_coef, bu) - [p[1] for p in block_pts])
    res_bot = np.abs(np.polyval(bot_coef, bu) - [p[2] for p in block_pts])
    shift_px = np.linalg.norm(top_img - T, axis=1)
    metrics = {
        "row_h_px": row_h, "cell_w_px": cell,
        "n_blocks_row_found": len(block_pts),
        "n_block_borders_found": int(border_ok.sum()),
        "n_vertical_snapped": int(snapped_ok.sum()),
        "global_dx_px": float(gdx), "global_scale": float(gs),
        "shear_per_block": [round(float(s), 3) for s in block_sh],
        "row_fit_residual_max_px": float(max(res_top.max(), res_bot.max())),
        "mean_correction_px": float(shift_px.mean()),
        "max_correction_px": float(shift_px.max()),
    }
    return {
        "status": "success",
        "top_pts_px": top_img.tolist(),
        "bot_pts_px": bot_img.tolist(),
        "col_x_frac": ((top_img[:, 0] + bot_img[:, 0]) / 2 / w).tolist(),
        "row_y_top_per_col": (top_img[:, 1] / h).tolist(),
        "row_y_bot_per_col": (bot_img[:, 1] / h).tolist(),
        "metrics": metrics,
    }



# ---------------------------------------------------------------------------
# Tinta per kotak
# ---------------------------------------------------------------------------

INK_EMPTY_THRESHOLD = 0.03  # < 3% piksel tinta di dlm kotak -> dianggap kosong


def warp_cell(gray, top_px, bot_px, i, size=64):
    """Luruskan kotak ke-i (0..47) jadi persegi size x size."""
    quad = np.float32([top_px[i], top_px[i + 1], bot_px[i + 1], bot_px[i]])
    dst = np.float32([[0, 0], [size, 0], [size, size], [0, size]])
    M = cv2.getPerspectiveTransform(quad, dst)
    return cv2.warpPerspective(gray, M, (size, size), flags=cv2.INTER_AREA)


def cell_ink_ratios(image, top_px, bot_px, size=64, margin=0.14, dark_delta=45):
    """Proporsi piksel 'tinta' di bagian DALAM tiap kotak (48 nilai, 0..1).
    margin: potong tepi kotak (buang garis cetak). dark_delta: piksel dianggap
    tinta kalau lebih gelap dari latar kotak (persentil 80) sebesar ini."""
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if image.ndim == 3 else image
    top_px = np.asarray(top_px, np.float32)
    bot_px = np.asarray(bot_px, np.float32)
    m = int(size * margin)
    out = []
    for i in range(len(top_px) - 1):
        inner = warp_cell(gray, top_px, bot_px, i, size)[m:size - m, m:size - m].astype(np.float32)
        bg = float(np.percentile(inner, 80))
        out.append(float((inner < bg - dark_delta).mean()))
    return out


# ---------------------------------------------------------------------------
# Wrapper: detect_grid (coarse) + refine
# ---------------------------------------------------------------------------

def refine_quality(metrics):
    if (metrics["n_block_borders_found"] >= 8 and metrics["n_vertical_snapped"] >= 44
            and metrics["n_blocks_row_found"] >= 6):
        return "good"
    return "partial"


def detect_grid_refined(photo_path, shift, ref_kp_pts, ref_des, ref_w, ref_h, anchors):
    """Sama spt detect_grid(), tp posisi grid sudah di-refine ke garis cetak.
    Kalau refine gagal, hasil coarse tetap dikembalikan (det["refined"]=False)."""
    from detect_grid import detect_grid

    det = detect_grid(photo_path, shift, ref_kp_pts, ref_des, ref_w, ref_h, anchors)
    if det["status"] not in ("success", "needs_manual_review"):
        det["refined"] = False
        return det

    img = det["corrected_image"]
    h, w = img.shape[:2]
    det["coarse"] = {
        "col_x_frac": det["col_x_frac"],
        "row_y_top_per_col": det["row_y_top_per_col"],
        "row_y_bot_per_col": det["row_y_bot_per_col"],
        "top_pts_px": [[x * w, y * h] for x, y in zip(det["col_x_frac"], det["row_y_top_per_col"])],
        "bot_pts_px": [[x * w, y * h] for x, y in zip(det["col_x_frac"], det["row_y_bot_per_col"])],
    }

    ref = refine_grid(img, det["col_x_frac"], det["row_y_top_per_col"], det["row_y_bot_per_col"])
    if ref["status"] != "success":
        det["refined"] = False
        det["refine"] = ref
        if ref.get("reason") in ("coarse_columns_not_monotonic", "coarse_outside_image"):
            # hasil homography terbukti rusak (mis. OSD salah putar) -> jangan diteruskan
            det["status"] = "needs_retake"
            det["reason"] = f"refine_rejected:{ref['reason']}"
        det["top_pts_px"] = det["coarse"]["top_pts_px"]
        det["bot_pts_px"] = det["coarse"]["bot_pts_px"]
        return det

    for k in ("col_x_frac", "row_y_top_per_col", "row_y_bot_per_col", "top_pts_px", "bot_pts_px"):
        det[k] = ref[k]
    mid = N_COLS // 2
    det["row_y_frac"] = [ref["row_y_top_per_col"][mid], ref["row_y_bot_per_col"][mid]]
    det["refined"] = True
    det["refine"] = {"status": "success", "quality": refine_quality(ref["metrics"]),
                     "metrics": ref["metrics"]}
    det["cell_ink"] = cell_ink_ratios(img, ref["top_pts_px"], ref["bot_pts_px"])
    return det


# ---------------------------------------------------------------------------
# Jalur CEPAT: tanpa OSD & tanpa OCR
# ---------------------------------------------------------------------------
# OSD (~3 dtk) & OCR label "Jam" (~2-8 dtk) memakan ~90% waktu detect_grid.
# Keduanya bisa dilewati:
#   - ORB tahan rotasi, jadi homography thd foto MENTAH sudah memuat rotasi
#     foto; arah sumbu-x grid hasil homography menentukan rotasi koreksi
#     (0/90/180/270). Ini juga menyelamatkan foto yg OSD-nya salah/gagal.
#   - cek silang OCR digantikan refine (baris dicari dari garis cetak).
# Jatuh balik ke jalur lama (OSD + OCR) HANYA kalau homography/refine gagal
# total; kualitas "partial" dikembalikan dgn status needs_manual_review.

_ROT_CODES = {90: cv2.ROTATE_90_COUNTERCLOCKWISE, 180: cv2.ROTATE_180, 270: cv2.ROTATE_90_CLOCKWISE}


def _coarse_from_homography(img, shift, ref_kp_pts, ref_des, ref_w, ref_h, anchors):
    from detect_grid import match_homography, transform_points_frac
    h, w = img.shape[:2]
    hg = match_homography(ref_kp_pts, ref_des, cv2.cvtColor(img, cv2.COLOR_BGR2GRAY))
    if hg["status"] != "success":
        return None, hg
    rb = anchors["shift_row_bounds_y_frac"][str(shift)]
    cols = anchors["grid_columns_x_frac"]
    top = transform_points_frac(hg["H"], [(x, rb[0]) for x in cols], ref_w, ref_h, w, h)
    bot = transform_points_frac(hg["H"], [(x, rb[1]) for x in cols], ref_w, ref_h, w, h)
    return (top, bot), hg


def detect_grid_fast(photo_path, shift, ref_kp_pts, ref_des, ref_w, ref_h, anchors, fallback=True):
    """Hasil format sama dgn detect_grid_refined(). det["path"] = "fast" atau
    "fallback_osd_ocr" (kalau jalur cepat tdk meyakinkan)."""
    from imgutil import imread_exif_safe

    raw = imread_exif_safe(photo_path)
    coarse, hg = _coarse_from_homography(raw, shift, ref_kp_pts, ref_des, ref_w, ref_h, anchors)
    det = None
    if coarse is not None:
        H_, W_ = raw.shape[:2]
        t0 = np.array(coarse[0][0]) * [W_, H_]
        t1 = np.array(coarse[0][-1]) * [W_, H_]
        ang = float(np.degrees(np.arctan2(t1[1] - t0[1], t1[0] - t0[0])))
        rot = {0: 0, 90: 90, 180: 180, -180: 180, -90: 270}[int(round(ang / 90.0)) * 90]
        img = raw if rot == 0 else cv2.rotate(raw, _ROT_CODES[rot])
        if rot:
            coarse, hg = _coarse_from_homography(img, shift, ref_kp_pts, ref_des, ref_w, ref_h, anchors)
        if coarse is not None:
            top, bot = coarse
            col_x = [p[0] for p in top]
            ref = refine_grid(img, col_x, [p[1] for p in top], [p[1] for p in bot])
            if ref["status"] == "success":
                # kualitas "partial" (mis. foto buram) TETAP dipakai tp ditandai
                # utk review -- jalur lama (OSD) tdk lebih baik di foto spt ini
                quality = refine_quality(ref["metrics"])
                h, w = img.shape[:2]
                mid = N_COLS // 2
                det = {
                    "status": "success" if quality == "good" else "needs_manual_review",
                    "reason": None if quality == "good" else "refine_partial",
                    "confidence": "high" if quality == "good" else "low", "path": "fast",
                    "orientation_rot": rot,
                    "homography_debug": {k: v for k, v in hg.items() if k != "H"},
                    "image_shape": [h, w],
                    "coarse": {
                        "top_pts_px": [[x * w, y * h] for x, y in top],
                        "bot_pts_px": [[x * w, y * h] for x, y in bot],
                    },
                    "refined": True,
                    "refine": {"status": "success", "quality": quality, "metrics": ref["metrics"]},
                    "row_y_frac": [ref["row_y_top_per_col"][mid], ref["row_y_bot_per_col"][mid]],
                    "corrected_image": img,
                }
                for k in ("col_x_frac", "row_y_top_per_col", "row_y_bot_per_col", "top_pts_px", "bot_pts_px"):
                    det[k] = ref[k]
                det["cell_ink"] = cell_ink_ratios(img, ref["top_pts_px"], ref["bot_pts_px"])
                return det

    if not fallback:
        return {"status": "needs_retake", "reason": "fast_path_failed", "path": "fast", "refined": False}
    det = detect_grid_refined(photo_path, shift, ref_kp_pts, ref_des, ref_w, ref_h, anchors)
    det["path"] = "fallback_osd_ocr"
    return det


# ---------------------------------------------------------------------------
# Overlay
# ---------------------------------------------------------------------------

def draw_refined_overlay(image, top_px, bot_px, coarse=None, cell_ink=None,
                         ink_threshold=INK_EMPTY_THRESHOLD):
    """Garis biru = batas atas/bawah baris, hijau tebal = batas blok, kuning =
    sub-kotak (semua mengikuti kemiringan asli). coarse=(top_px, bot_px)
    opsional: digambar merah tipis utk perbandingan dgn versi lama.
    cell_ink opsional: kotak yg terdeteksi berisi tinta diberi titik magenta."""
    vis = image.copy()
    top_px = np.asarray(top_px, np.float64)
    bot_px = np.asarray(bot_px, np.float64)

    def pt(p):
        return tuple(int(v) for v in np.round(p))

    if coarse is not None:
        ct, cb = np.asarray(coarse[0]), np.asarray(coarse[1])
        cv2.polylines(vis, [np.int32(ct)], False, (0, 0, 255), 1)
        cv2.polylines(vis, [np.int32(cb)], False, (0, 0, 255), 1)
    cv2.polylines(vis, [np.int32(np.round(top_px))], False, (255, 0, 0), 2)
    cv2.polylines(vis, [np.int32(np.round(bot_px))], False, (255, 0, 0), 2)
    for i in range(len(top_px)):
        is_border = i % N_SUBCELLS == 0
        cv2.line(vis, pt(top_px[i]), pt(bot_px[i]), (0, 200, 0) if is_border else (0, 200, 255),
                 3 if is_border else 2)
    if cell_ink is not None:
        for i, r in enumerate(cell_ink):
            if r >= ink_threshold:
                c = (top_px[i] + top_px[i + 1] + bot_px[i] + bot_px[i + 1]) / 4
                cv2.circle(vis, pt(c + [0, -0.3 * (bot_px[i][1] - top_px[i][1])]), 4, (255, 0, 255), -1)

    ys = np.concatenate([top_px[:, 1], bot_px[:, 1]])
    rh = float(np.median(bot_px[:, 1] - top_px[:, 1]))
    y0, y1 = max(0, int(ys.min() - 3 * rh)), min(image.shape[0], int(ys.max() + 3 * rh))
    x0 = max(0, int(min(top_px[:, 0].min(), bot_px[:, 0].min()) - 2 * rh))
    x1 = min(image.shape[1], int(max(top_px[:, 0].max(), bot_px[:, 0].max()) + 2 * rh))
    if y1 <= y0 or x1 <= x0:
        return vis  # titik di luar gambar -> tampilkan gambar penuh
    return vis[y0:y1, x0:x1]
