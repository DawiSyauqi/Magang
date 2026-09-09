import cv2
import numpy as np
from PIL import Image, ImageOps
import pytesseract


def imread_exif_safe(path):
    pil_img = Image.open(path)
    pil_img = ImageOps.exif_transpose(pil_img)
    pil_img = pil_img.convert("RGB")
    arr = np.array(pil_img)
    return cv2.cvtColor(arr, cv2.COLOR_RGB2BGR)


def detect_and_fix_orientation(image_bgr):
    """Return (corrected_image, osd_ok, rotate_deg_applied)."""
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