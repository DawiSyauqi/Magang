import os
import shutil
import cv2
import numpy as np
import pytesseract

if not shutil.which("tesseract"):
    for _p in [
        r"C:\Program Files\Tesseract-OCR\tesseract.exe",
        r"C:\Program Files (x86)\Tesseract-OCR\tesseract.exe",
        os.path.expanduser(r"~\AppData\Local\Programs\Tesseract-OCR\tesseract.exe"),
    ]:
        if os.path.exists(_p):
            pytesseract.pytesseract.tesseract_cmd = _p
            break


def _clean_alpha(s):
    return ''.join(c for c in s if c.isalpha()).lower()


def find_label_hits(image_bgr, x_frac=(0.0, 0.13), scale=1.0, psm=11):
    h, w = image_bgr.shape[:2]
    x0, x1 = int(x_frac[0] * w), int(x_frac[1] * w)
    crop = image_bgr[:, x0:x1]
    if scale != 1.0:
        crop = cv2.resize(crop, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
    gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
    config = f'--psm {psm}'
    data = pytesseract.image_to_data(gray, config=config, output_type=pytesseract.Output.DICT)

    n = len(data['text'])
    hits = []
    for i in range(n):
        raw = data['text'][i]
        if not raw or not raw.strip():
            continue
        clean = _clean_alpha(raw)
        if clean == '':
            continue
        conf = float(data['conf'][i]) if data['conf'][i] not in ('-1', -1) else -1
        y_px = data['top'][i] + data['height'][i] / 2.0
        y_frac = y_px / scale / h
        hits.append({
            'text_raw': raw, 'text_clean': clean,
            'line_num': data['line_num'][i],
            'y_frac': y_frac, 'conf': conf,
        })
    return hits


def filter_jam_lost_tokens(hits):
    by_line = {}
    for h in hits:
        by_line.setdefault(h['line_num'], []).append(h['text_clean'])

    bad_lines = set()
    for line_num, tokens in by_line.items():
        joined = ' '.join(tokens)
        if 'total' in joined or 'nett' in joined:
            bad_lines.add(line_num)

    jam_hits = []
    lost_hits = []
    for h in hits:
        if h['line_num'] in bad_lines:
            continue
        t = h['text_clean']
        if t == 'jam':
            jam_hits.append(h)
        elif 'lost' in t or t == 'time':
            lost_hits.append(h)
    return jam_hits, lost_hits


def find_uniform_triplet(y_values, tolerance_frac=0.30):
    ys = sorted(set(round(y, 5) for y in y_values))
    if len(ys) < 3:
        return None
    best = None
    for i in range(len(ys)):
        for j in range(i + 1, len(ys)):
            for k in range(j + 1, len(ys)):
                a, b, c = ys[i], ys[j], ys[k]
                d1, d2 = b - a, c - b
                med = (d1 + d2) / 2
                if med <= 0:
                    continue
                spread = abs(d1 - d2) / med
                if spread <= tolerance_frac:
                    if best is None or spread < best[2]:
                        best = ([a, b, c], med, spread)
    return best


def find_jam_rows_ocr(image_bgr, x_frac=(0.0, 0.13), scale_psm_chain=None):
    if scale_psm_chain is None:
        scale_psm_chain = [(1.0, 11), (2.0, 11), (2.0, 6), (3.0, 6), (4.0, 6)]

    for scale, psm in scale_psm_chain:
        hits = find_label_hits(image_bgr, x_frac=x_frac, scale=scale, psm=psm)
        jam_hits, lost_hits = filter_jam_lost_tokens(hits)
        if len(jam_hits) < 3:
            continue
        ys = [h['y_frac'] for h in jam_hits]
        result = find_uniform_triplet(ys, tolerance_frac=0.30)
        if result is not None:
            triplet, spacing, spread = result
            return {
                'status': 'success',
                'jam_y_frac': triplet,
                'spacing': spacing,
                'spread': spread,
                'scale': scale, 'psm': psm,
                'n_jam_hits': len(jam_hits), 'n_lost_hits': len(lost_hits),
            }
    return {'status': 'failed'}