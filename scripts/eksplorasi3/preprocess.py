"""
EKSPLORASI 3 -- preprocess.py
Preprocessing MINIMAL: exif-safe read + koreksi orientasi (OSD) + enhance +
upscale. TIDAK ADA auto_crop_document, TIDAK ADA OCR-anchor, TIDAK ADA
homography -- semua pencarian posisi diserahkan ke Ollama.

enhance() & upscale_if_needed() disalin APA ADANYA dari paper_reader_extract.py
(fungsi generik yg sudah lama dipakai produksi, bukan bagian eksperimental).
"""

import cv2
import numpy as np
from PIL import Image, ImageOps
import pytesseract

MIN_OUTPUT_DIMENSION = 1600


def imread_exif_safe(path):
    pil_img = Image.open(path)
    pil_img = ImageOps.exif_transpose(pil_img)
    pil_img = pil_img.convert("RGB")
    arr = np.array(pil_img)
    return cv2.cvtColor(arr, cv2.COLOR_RGB2BGR)


def detect_and_fix_orientation(image_bgr):
    rgb = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2RGB)
    pil_img = Image.fromarray(rgb)
    try:
        osd = pytesseract.image_to_osd(pil_img)
        rotate = 0
        for line in osd.splitlines():
            if line.startswith("Rotate:"):
                rotate = int(line.split(":")[1].strip())
                break
        if rotate == 0:
            return image_bgr, True, 0
        elif rotate == 180:
            return cv2.rotate(image_bgr, cv2.ROTATE_180), True, 180
        elif rotate == 90:
            return cv2.rotate(image_bgr, cv2.ROTATE_90_COUNTERCLOCKWISE), True, 90
        elif rotate == 270:
            return cv2.rotate(image_bgr, cv2.ROTATE_90_CLOCKWISE), True, 270
        else:
            return image_bgr, True, rotate
    except Exception:
        return image_bgr, False, None


def upscale_if_needed(image, min_dim=MIN_OUTPUT_DIMENSION):
    h, w = image.shape[:2]
    shortest_side = min(h, w)
    if shortest_side >= min_dim:
        return image
    scale = min_dim / shortest_side
    return cv2.resize(image, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_CUBIC)


def enhance(image):
    lab = cv2.cvtColor(image, cv2.COLOR_BGR2LAB)
    l, a, b = cv2.split(lab)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    l2 = clahe.apply(l)
    merged = cv2.merge((l2, a, b))
    return cv2.cvtColor(merged, cv2.COLOR_LAB2BGR)


def preprocess_closeup(image_path):
    """Return (final_image_bgr, osd_ok, rotate_applied)."""
    raw = imread_exif_safe(image_path)
    corrected, osd_ok, rot = detect_and_fix_orientation(raw)
    if not osd_ok:
        return corrected, False, rot
    upscaled = upscale_if_needed(corrected)
    final = enhance(upscaled)
    return final, True, rot