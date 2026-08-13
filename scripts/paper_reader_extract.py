#!/usr/bin/env python3
"""
paper_reader_extract.py — Tahap 3: pipeline produksi Mode E ("grid per-kotak").

Dipanggil dari Laravel (PaperReaderService::extract()) lewat shell-out
sekali-panggil. SATU-SATUNYA output ke STDOUT adalah SATU JSON envelope:

  Sukses: {"success": true, "data": {...MFDowntimeExtraction...}, "meta": {...}}
  Gagal : {"success": false, "error": "...", "error_type": "..."}

Semua log/diagnostik proses (kalibrasi kolom, ink_ratio per kotak, dst)
ditulis ke STDERR, BUKAN stdout -- supaya PHP bisa json_decode(stdout) apa
adanya tanpa perlu membersihkan baris log dulu.

Exit code: 0 = sukses, 1 = gagal (lihat field "error" di JSON stdout).

Konsolidasi dari notebook `tahap2_prompt_ekstraksi_v10_final.ipynb`
(preprocessing 1b, skema 2, prompt header 4, PATCH v4/v5 ROW_BOUNDS,
PATCH v3 block_x_bounds whole-table, Mode E cell 47/49). HANYA logika
Mode E yang disertakan di sini -- Mode A/B/C/D dibuang karena tidak
dipakai produksi (lihat keputusan Tahap 2).

Penggunaan:
    python3 paper_reader_extract.py --image /path/to/foto.jpg \
        [--model qwen2.5vl:7b] [--ollama-url http://127.0.0.1:11434] \
        [--timeout 600] [--num-ctx 16384] [--keep-temp]
"""

import argparse
import base64
import builtins
import json
import re
import sys
import time
import traceback
from pathlib import Path
from typing import List, Optional

import cv2
import numpy as np
import requests
from PIL import Image, ImageOps
from pydantic import BaseModel, Field, field_validator

# ============================================================
# Semua print() di file ini default ke STDERR (log proses).
# _stdout_print() dipakai SEKALI SAJA di paling akhir untuk JSON hasil.
# ============================================================
_stdout_print = builtins.print


def print(*args, **kwargs):  # noqa: A001 - sengaja menimpa print bawaan modul ini
    kwargs.setdefault("file", sys.stderr)
    kwargs.setdefault("flush", True)
    _stdout_print(*args, **kwargs)

class SectionDetectionError(Exception):
    """Dilempar saat deteksi 1 section (grid/header/speed_size) gagal-keras.
    Beda dari Exception umum -- ditangkap terpisah di run_pipeline() supaya
    hasil section LAIN yang sudah berhasil tidak ikut hilang."""
    def __init__(self, section: str, message: str):
        self.section = section
        super().__init__(message)

# ============================================================
# 1. PREPROCESSING — auto-crop, upscale, enhance (dari Cell 4)
# ============================================================
MIN_AREA_RATIO = 0.20
MIN_ASPECT_RATIO = 0.3
MAX_ASPECT_RATIO = 3.5
MIN_OUTPUT_DIMENSION = 1600


def order_points(pts: np.ndarray) -> np.ndarray:
    rect = np.zeros((4, 2), dtype="float32")
    s = pts.sum(axis=1)
    rect[0] = pts[np.argmin(s)]
    rect[2] = pts[np.argmax(s)]
    diff = np.diff(pts, axis=1)
    rect[1] = pts[np.argmin(diff)]
    rect[3] = pts[np.argmax(diff)]
    return rect


def _validate_contour(contour, image_shape) -> bool:
    img_h, img_w = image_shape[:2]
    img_area = img_h * img_w
    area = cv2.contourArea(contour)
    if area < MIN_AREA_RATIO * img_area:
        return False
    x, y, w, h = cv2.boundingRect(contour)
    aspect = w / h if h > 0 else 0
    return MIN_ASPECT_RATIO <= aspect <= MAX_ASPECT_RATIO


def _find_quad_from_edges(gray):
    blurred = cv2.GaussianBlur(gray, (5, 5), 0)
    median_val = float(np.median(blurred))
    lower = int(max(0, 0.66 * median_val))
    upper = int(min(255, 1.33 * median_val))
    edged = cv2.Canny(blurred, lower, upper)
    edged = cv2.dilate(edged, None, iterations=2)

    contours, _ = cv2.findContours(edged, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    if not contours:
        return None
    for contour in sorted(contours, key=cv2.contourArea, reverse=True)[:5]:
        peri = cv2.arcLength(contour, True)
        approx = cv2.approxPolyDP(contour, 0.02 * peri, True)
        if len(approx) == 4 and _validate_contour(approx, gray.shape):
            return approx.reshape(4, 2).astype("float32")
    return None


def _find_quad_from_contrast(gray):
    blurred = cv2.GaussianBlur(gray, (7, 7), 0)
    _, thresh = cv2.threshold(blurred, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    contours, _ = cv2.findContours(thresh, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    if not contours:
        return None
    largest = max(contours, key=cv2.contourArea)
    if not _validate_contour(largest, gray.shape):
        return None
    peri = cv2.arcLength(largest, True)
    approx = cv2.approxPolyDP(largest, 0.02 * peri, True)
    if len(approx) == 4:
        return approx.reshape(4, 2).astype("float32")
    rect = cv2.minAreaRect(largest)
    return cv2.boxPoints(rect).astype("float32")


def _warp(image, pts):
    rect = order_points(pts)
    (tl, tr, br, bl) = rect
    widthA, widthB = np.linalg.norm(br - bl), np.linalg.norm(tr - tl)
    maxWidth = int(max(widthA, widthB))
    heightA, heightB = np.linalg.norm(tr - br), np.linalg.norm(tl - bl)
    maxHeight = int(max(heightA, heightB))
    dst = np.array([[0, 0], [maxWidth - 1, 0], [maxWidth - 1, maxHeight - 1], [0, maxHeight - 1]], dtype="float32")
    M = cv2.getPerspectiveTransform(rect, dst)
    return cv2.warpPerspective(image, M, (maxWidth, maxHeight))

def imread_exif_safe(path: str):
    """Baca gambar dengan koreksi EXIF orientation dipaksa eksplisit --
    TIDAK bergantung pada perilaku implisit cv2.imread() yg bisa beda
    antar versi OpenCV/platform. WAJIB dipakai utk foto dari kamera HP,
    karena JS (browser) SELALU auto-rotate sesuai EXIF saat menggambar ke
    canvas -- kalau Python tidak konsisten melakukan hal sama, koordinat
    titik dari JS (mode close-up grid, 4-titik manual) jadi salah acu."""
    pil_img = Image.open(path)
    pil_img = ImageOps.exif_transpose(pil_img)  # putar piksel sesuai EXIF, lalu buang tag EXIF-nya
    pil_img = pil_img.convert("RGB")
    arr = np.array(pil_img)
    return cv2.cvtColor(arr, cv2.COLOR_RGB2BGR)

def warp_from_3_points(image, points_normalized):
    """points_normalized: 3 titik {x,y} (0-1) urutan [kiri-atas, kanan-atas,
    kiri-bawah]. HANYA affine (rotasi+skala), BUKAN perspektif penuh --
    cukup utk kemiringan kecil hasil foto tangan. Dipakai KHUSUS mode
    close-up grid: operator menandai PERSIS baris 'Lost time' yang gagal
    saja, hasil warp = 1 baris grid siap dibagi rata (lihat
    compute_uniform_block_x_bounds), TANPA deteksi garis apa pun."""
    h, w = image.shape[:2]
    src = np.array([
        [points_normalized[0]["x"] * w, points_normalized[0]["y"] * h],
        [points_normalized[1]["x"] * w, points_normalized[1]["y"] * h],
        [points_normalized[2]["x"] * w, points_normalized[2]["y"] * h],
    ], dtype="float32")

    width = max(int(np.linalg.norm(src[1] - src[0])), 10)
    height = max(int(np.linalg.norm(src[2] - src[0])), 10)

    dst = np.array([[0, 0], [width - 1, 0], [0, height - 1]], dtype="float32")
    M = cv2.getAffineTransform(src, dst)
    return cv2.warpAffine(image, M, (width, height))


def compute_uniform_block_x_bounds(n_blocks=8):
    """Bagi lebar frame (0-1) jadi n_blocks blok SAMA RATA -- TIDAK ada
    deteksi garis sama sekali. Valid krn tabel kertas printed presisi rata,
    ASALKAN batas luar (hasil warp_from_3_points) sudah benar."""
    return [i / n_blocks for i in range(n_blocks + 1)]

def auto_crop_document(image):
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    quad = _find_quad_from_edges(gray)
    if quad is not None:
        return _warp(image, quad), True, "edge_detection"
    quad = _find_quad_from_contrast(gray)
    if quad is not None:
        return _warp(image, quad), True, "contrast_threshold"
    return image, False, "none"


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


def preprocess_image(input_path: str, output_path: str):
    image = imread_exif_safe(input_path)
    if image is None:
        raise FileNotFoundError(f"Tidak bisa baca gambar: {input_path}")

    cropped, crop_success, method = auto_crop_document(image)
    if not crop_success:
        print("Auto-crop GAGAL mendeteksi 4 sudut kertas.")
    else:
        print(f"Auto-crop berhasil (metode: {method})")

    upscaled = upscale_if_needed(cropped)
    final = enhance(upscaled)
    cv2.imwrite(output_path, final)
    print(f"Preprocessing selesai -> {output_path} ({final.shape[1]}x{final.shape[0]})")
    return output_path, crop_success, method   # <-- sekarang return 3 nilai, bukan 1


# ============================================================
# 2. SKEMA DATA (Pydantic) — dari Cell 7
# ============================================================
def _parse_measurement(v):
    if v is None:
        return None
    if isinstance(v, (int, float)):
        return float(v)
    if isinstance(v, str):
        s = v.strip()
        if not s:
            return None
        s_dot = s.replace(",", ".")
        try:
            return float(s_dot)
        except ValueError:
            pass
        m = re.fullmatch(r"(\d+)\s*-\s*(\d+)", s)
        if m:
            return float(f"{m.group(1)}.{m.group(2)}")
        cleaned = re.sub(r"[^0-9.]", "", s_dot)
        if cleaned in ("", "."):
            return None
        try:
            return float(cleaned)
        except ValueError:
            return None
    return None


def _coerce_optional_str_list(v):
    if v is None:
        return v
    if isinstance(v, str):
        v = [v]
    result = []
    for item in v:
        if item is None:
            result.append(None)
        elif isinstance(item, dict):
            result.append(json.dumps(item, ensure_ascii=False))
        else:
            result.append(str(item))
    return result


def _coerce_str_list(v):
    if v is None:
        return v
    if isinstance(v, str):
        v = [v]
    result = []
    for item in v:
        if item is None:
            continue
        elif isinstance(item, dict):
            result.append(json.dumps(item, ensure_ascii=False))
        else:
            result.append(str(item))
    return result


class GridWaktuSlot(BaseModel):
    jam_mulai: str
    blok: List[Optional[str]] = Field(default_factory=list)

    @field_validator("blok", mode="before")
    @classmethod
    def _v_blok(cls, v):
        return _coerce_optional_str_list(v) if v is not None else []


class MFDowntimeExtraction(BaseModel):
    tanggal: Optional[str] = None
    mesin_code: Optional[str] = None
    shift: Optional[str] = None
    speed: Optional[float] = None
    operator_nama: Optional[str] = None
    grid_waktu: List[GridWaktuSlot] = Field(default_factory=list)
    low_confidence_fields: Optional[List[str]] = None

    @field_validator("speed", mode="before")
    @classmethod
    def _v_speed(cls, v):
        return _parse_measurement(v)

    @field_validator("low_confidence_fields", mode="before")
    @classmethod
    def _v_low_conf(cls, v):
        return _coerce_str_list(v)

    @field_validator("tanggal", mode="before")
    @classmethod
    def _v_tanggal_passthrough(cls, v):
        return None if v is None else str(v)


# ============================================================
# 3. PROMPT — hanya yang dipakai Mode E (header + per-kotak)
# ============================================================
HEADER_ONLY_PROMPT = '''
Kamu membaca bagian HEADER dari form kertas "LAPORAN PROSES DRAWING
HARIAN". Kembalikan HANYA JSON (tanpa teks lain) dengan struktur PERSIS:

{
  "tanggal": string atau null, "mesin_code": string atau null,
  "shift": string atau null,
  "operator_nama": string atau null,
  "low_confidence_fields": [string, ...] atau null
}

- "tanggal": label "Hari & Tanggal", transkrip PERSIS seperti tertulis.
- "mesin_code": label "Mesin" -- TRANSKRIP APA ADANYA, jangan menilai
  valid/tidak atau mengoreksi ke kode lain (akan dikoreksi lewat
  pencocokan Tahap 4b, bukan tugasmu di sini).
- "shift": label "Shift".
- "operator_nama": nama di kolom "Operator" (kanan atas form).

HINT DISAMBIGUASI karakter tulisan tangan yang mirip (i/l/1, G/6, O/0)
-- pakai konteks format tiap field untuk memilih yang masuk akal:
- "shift" HARUS 1 digit angka wajar (1, 2, atau 3) -- huruf "l"/"I" di
  posisi ini hampir pasti angka "1".
- "tanggal" HARUS membentuk tanggal valid (hari 1-31, bulan 1-12) --
  pilih pembacaan yang menghasilkan tanggal valid kalau ambigu.
- Untuk "mesin_code"/"operator_nama": JANGAN disambiguasi sendiri,
  transkrip apa adanya walau ambigu.

- Nilai tidak terbaca -> null. JANGAN mengarang. Ragu -> null.
- Output JSON valid saja, tanpa teks lain.
'''

DATA_TABLE_CROP_Y = (0.08, 0.48)  # generus -- dari atas tabel Data Material sampai sebelum grid Jenis Lubricant
DATA_TABLE_CROP_X = (0.0, 0.775)

SPEED_ONLY_PROMPT = '''
Kamu melihat POTONGAN TABEL dari form kertas industri -- berisi beberapa
baris berlabel di sisi kiri (seperti "Supplier/Pemasok", "Grade", "Size",
"Speed (m/mnt)", "Dies Pass", dst), dengan area kosong/isian di sisi kanan.

TUGASMU: cari 2 baris berikut, baca isian tulisan tangan di sisi kanan
masing-masing:
1. Baris berlabel PERSIS "Size" (di section "Data Pemesanan").
2. Baris berlabel PERSIS "Speed (m/mnt)" (di section "Proses Kontrol",
   TEPAT DI BAWAH baris Size).

Kembalikan HANYA JSON (tanpa teks lain):
{"size": string atau null, "speed": number atau null}

- "size": transkrip APA ADANYA seperti tertulis (contoh: "00.80 MM",
  "1.20 MM"). JANGAN diubah jadi angka desimal, JANGAN buang satuan "MM".
- "speed": dari baris "Speed (m/mnt)" SAJA, JANGAN tertukar dengan baris
  Size. Jadikan angka desimal (contoh "9-6" -> 9.6, "3.6" -> 3.6).
- Huruf "G" di posisi angka hampir pasti "6"; huruf "O" hampir pasti "0".
- Baris tidak ditemukan / kosong / tidak terbaca -> null utk field itu.
  JANGAN mengarang.
- Output JSON valid saja, PERSIS 2 field "size" dan "speed".
'''




CELL_PROMPT = (
    "PERHATIAN: kadang operator menulis 2 kode berdekatan di 2 kotak bersebelahan\n"
    "(mis. \"6a\" di kotak ini, \"5b\" di kotak sebelahnya) -- JANGAN menyalin kode\n"
    "kotak tetangga hanya karena bentuknya mirip. Baca HANYA yang benar-benar ada\n"
    "di kotak TENGAH gambar ini.\n"
    "Kamu melihat SATU KOTAK KECIL saja dari form kertas industri -- 1 kotak\n"
    "ini mewakili 10 menit di baris \"Lost time\". Gambar ini SUDAH DIPOTONG\n"
    "supaya HANYA berisi SATU kotak itu, tidak ada kotak lain.\n\n"
    "Kembalikan HANYA JSON (tanpa teks lain, tanpa markdown code fence):\n"
    "{\"kode\": \"6a\"}   <- kalau ADA tulisan tangan di kotak ini\n"
    "{\"kode\": \"x\"}    <- kalau isinya HURUF 'x' SAJA (penanda akhir rentang\n"
    "                        masalah panjang, BUKAN kode kategori biasa)\n"
    "{\"kode\": null}    <- kalau kotak ini KOSONG (tidak ada coretan sama sekali)\n\n"
    "ATURAN PENTING:\n"
    "1. Kode berupa 1 digit angka (0-8), ATAU 1 digit angka + 1 huruf kecil\n"
    "   (mis. \"6a\", \"2f\", \"3c\"). Huruf kecil \"l\" BEDA dari angka \"1\" --\n"
    "   perhatikan baik-baik bentuknya.\n"
    "2. KHUSUS huruf \"x\" SENDIRIAN (tanpa angka di depannya) -- ini PENANDA\n"
    "   AKHIR RENTANG MASALAH PANJANG, beda dari kode kategori. Kembalikan\n"
    "   persis \"x\" kalau kamu lihat huruf ini sendirian di kotak.\n"
    "3. JANGAN PERNAH mengarang kode. Kalau ragu atau kotak kelihatan kosong\n"
    "   -> null.\n"
    "4. Abaikan garis tepi kotak/bayangan/noise -- itu bukan kode.\n"
    "5. \"kode\" WAJIB JSON string bertanda kutip ganda (termasuk \"0\"), atau\n"
    "   null (tanpa kutip) kalau kosong.\n"
    "6. Output HARUS JSON valid saja, PERSIS 1 field \"kode\".\n"
)

ROW_BLOCK_LABELS = {
    "jam_07_15": ["07.00 - 08.00", "08.00 - 09.00", "09.00 - 10.00", "10.00 - 11.00",
                  "11.00 - 12.00", "12.00 - 13.00", "13.00 - 14.00", "14.00 - 15.00"],
    "jam_15_23": ["15.00 - 16.00", "16.00 - 17.00", "17.00 - 18.00", "18.00 - 19.00",
                  "19.00 - 20.00", "20.00 - 21.00", "21.00 - 22.00", "22.00 - 23.00"],
    "jam_23_07": ["23.00 - 24.00", "24.00 - 01.00", "01.00 - 02.00", "02.00 - 03.00",
                  "03.00 - 04.00", "04.00 - 05.00", "05.00 - 06.00", "06.00 - 07.00"],
}


# ============================================================
# 4. PEMANGGIL OLLAMA — dari Cell 13
# ============================================================
class OllamaConfig:
    def __init__(self, base_url: str, model: str, timeout: int, num_ctx: int):
        self.base_url = base_url
        self.model = model
        self.timeout = timeout
        self.num_ctx = num_ctx


def _encode_image_array(image) -> str:
    success, buffer = cv2.imencode(".jpg", image)
    if not success:
        raise RuntimeError("Gagal encode gambar ke JPEG")
    return base64.standard_b64encode(buffer).decode("utf-8")


def _strip_json_fence(text: str) -> str:
    text = text.strip()
    if text.startswith("```"):
        text = text.split("```")[1]
        if text.startswith("json"):
            text = text[len("json"):]
    return text.strip()


def _try_repair_json(text: str):
    def _quote_token(m):
        return f'{m.group(1)}"{m.group(2)}"'
    fixed = re.sub(r'([\[,]\s*)([0-9]+[a-zA-Z]?)(?=\s*[,\]])', _quote_token, text)
    return json.loads(fixed)


def _normalize_blok_values(obj):
    if isinstance(obj, dict):
        for k, v in obj.items():
            if k == "blok" and isinstance(v, list):
                obj[k] = [None if x is None else str(x) for x in v]
            else:
                _normalize_blok_values(v)
    elif isinstance(obj, list):
        for item in obj:
            _normalize_blok_values(item)
    return obj


def _call_ollama(cfg: OllamaConfig, image, prompt: str) -> dict:
    image_b64 = _encode_image_array(image)
    payload = {
        "model": cfg.model,
        "messages": [
            {"role": "system", "content": prompt},
            {"role": "user", "content": "Ekstrak data sesuai skema yang sudah dijelaskan.",
             "images": [image_b64]},
        ],
        "stream": False,
        "options": {"temperature": 0.1, "num_ctx": cfg.num_ctx},
    }
    
    try:
        resp = requests.post(f"{cfg.base_url}/api/chat", json=payload, timeout=cfg.timeout)
    except requests.exceptions.ConnectionError as e:
        raise RuntimeError(f"Tidak bisa konek ke Ollama di {cfg.base_url}: {e}") from e
    except requests.exceptions.ReadTimeout:
        raise RuntimeError(f"Timeout setelah {cfg.timeout}s menunggu Ollama.")
    if resp.status_code != 200:
        raise RuntimeError(f"Ollama error {resp.status_code}: {resp.text[:500]}")
    raw_text = resp.json()["message"]["content"]
    clean_text = _strip_json_fence(raw_text)

    try:
        parsed = json.loads(clean_text)
    except json.JSONDecodeError as e_original:
        try:
            parsed = _try_repair_json(clean_text)
            print("  (JSON diperbaiki otomatis -- token tanpa tanda kutip)")
        except json.JSONDecodeError:
            raise RuntimeError(f"Gagal parse JSON dari model. Error: {e_original}. Raw: {raw_text[:500]}")

    return _normalize_blok_values(parsed)

def extract_speed_and_size(cfg: OllamaConfig, image, crop_y=None, crop_x=None):
    """Crop GENERUS yang sama dipakai utk baca 2 field sekaligus (Size +
    Speed) dalam SATU pemanggilan Ollama -- tidak nambah waktu proses
    dibanding sebelumnya (dulu cuma baca Speed, sekarang sekalian Size,
    keduanya sama-sama ada di crop yang sama)."""
    h, w = image.shape[:2]
    y0f, y1f = crop_y if crop_y is not None else DATA_TABLE_CROP_Y
    x0f, x1f = crop_x if crop_x is not None else DATA_TABLE_CROP_X
    crop = image[int(y0f * h):int(y1f * h), int(x0f * w):int(x1f * w)]

    print(f"  [diag speed] crop area: y=({y0f}-{y1f}), x=({x0f}-{x1f}), shape={crop.shape}")

    result = _call_ollama(cfg, crop, SPEED_ONLY_PROMPT)
    print(f"  [diag speed] raw response: {result!r}")

    speed_value = _parse_measurement(result.get("speed"))
    size_value = result.get("size")
    size_value = size_value.strip() if isinstance(size_value, str) and size_value.strip() else None

    return speed_value, size_value

# ============================================================
# 5. PATCH v4/v5 — ROW_BOUNDS dari struktur asli (dari Cell 21)
# ============================================================
ROW_BOUNDS_FALLBACK = {
    "jam_07_15": (0.765, 0.810),
    "jam_15_23": (0.810, 0.855),
    "jam_23_07": (0.855, 0.900),
}
MODE_B_GRID_BOUNDS = (0.765, 1.000)
ROW_SEARCH_TOP_MARGIN = 0.03
ROW_Y_SEARCH_RANGE = (MODE_B_GRID_BOUNDS[0] - ROW_SEARCH_TOP_MARGIN, MODE_B_GRID_BOUNDS[1])
LOST_TIME_FALLBACK_SPLIT_RATIO = 0.5
EXPECTED_SUBROW_HEIGHT_FRAC = 0.0225
SUBROW_HEIGHT_TOLERANCE = 0.40


def detect_row_bounds_from_structure(image, y_search_range=None, x_search_range=None, kernel_frac=0.6, require_size_match=True):
    h, w = image.shape[:2]
    y0f, y1f = y_search_range if y_search_range is not None else ROW_Y_SEARCH_RANGE
    y0, y1 = int(y0f * h), int(y1f * h)
    x0f, x1f = x_search_range if x_search_range is not None else (0.14, 0.775)
    x0, x1 = int(x0f * w), int(x1f * w)
    region = image[y0:y1, x0:x1]
    gray = cv2.cvtColor(region, cv2.COLOR_BGR2GRAY) if region.ndim == 3 else region
    _, thresh = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU)
    rw = thresh.shape[1]
    horiz_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (max(int(rw * kernel_frac), 3), 1))
    horiz_lines = cv2.morphologyEx(thresh, cv2.MORPH_OPEN, horiz_kernel, iterations=1)
    row_sum = horiz_lines.sum(axis=1) / 255
    if row_sum.max() == 0:
        return None
    thr = max(row_sum.max() * 0.4, 5)
    candidates = np.where(row_sum > thr)[0]
    if len(candidates) == 0:
        return None

    lines = []
    start = candidates[0]; prev = candidates[0]
    for y in candidates[1:]:
        if y - prev > 6:
            lines.append((start + prev) // 2)
            start = y
        prev = y
    lines.append((start + prev) // 2)

    if len(lines) < 7:
        print(f"  [diag row] hanya {len(lines)} garis horizontal terdeteksi (butuh >= 7) -> fallback")
        return None

    lines = sorted(lines)
    expected_px = EXPECTED_SUBROW_HEIGHT_FRAC * h

    valid_candidates = []
    for start_i in range(len(lines) - 6):
        window = lines[start_i:start_i + 7]
        diffs = [window[i + 1] - window[i] for i in range(6)]
        med = sorted(diffs)[3]
        spread = max(abs(d - med) for d in diffs)
        uniform_ok = spread <= med * 0.35
        size_ok = (not require_size_match) or abs(med - expected_px) <= expected_px * SUBROW_HEIGHT_TOLERANCE
        if uniform_ok and size_ok:
            valid_candidates.append((start_i, window, med, spread))

    if not valid_candidates:
        print(f"  [diag row] tidak ada jendela 7-garis yang seragam DAN sesuai ukuran "
              f"(expected≈{expected_px:.0f}px) -> fallback")
        return None

    start_i, window, med, spread = max(valid_candidates, key=lambda c: c[0])
    print(f"  [diag row] {len(valid_candidates)} jendela valid, pakai yang paling bawah "
          f"(start_i={start_i}, med_spacing={med:.1f}px)")

    y_abs = [y0 + ly for ly in window]
    row_bounds = {
        "jam_07_15": (y_abs[0] / h, y_abs[2] / h),
        "jam_15_23": (y_abs[2] / h, y_abs[4] / h),
        "jam_23_07": (y_abs[4] / h, y_abs[6] / h),
    }
    lost_time_bounds = {
        "jam_07_15": (y_abs[1] / h, y_abs[2] / h),
        "jam_15_23": (y_abs[3] / h, y_abs[4] / h),
        "jam_23_07": (y_abs[5] / h, y_abs[6] / h),
    }
    return row_bounds, lost_time_bounds

def get_calibrated_row_bounds(image, y_search_range=None, x_search_range=None, kernel_frac=0.6, require_size_match=True):
    result = detect_row_bounds_hough(image, y_search_range=y_search_range, x_search_range=x_search_range)
    if result is None:
        print("  [diag row] auto-deteksi ROW_BOUNDS GAGAL -> fallback statis (BERISIKO)")
        lost_time_fallback = {
            rk: (ROW_BOUNDS_FALLBACK[rk][0] + (ROW_BOUNDS_FALLBACK[rk][1] - ROW_BOUNDS_FALLBACK[rk][0]) * 0.5,
                 ROW_BOUNDS_FALLBACK[rk][1])
            for rk in ROW_BOUNDS_FALLBACK
        }
        return ROW_BOUNDS_FALLBACK, lost_time_fallback, "fallback"
    row_bounds, lost_time_bounds = result
    return row_bounds, lost_time_bounds, "auto_structure"


# ============================================================
# 6. PATCH v3 — block_x_bounds whole-table (dari Cell 33)
# ============================================================
BLOCK_X_BOUNDS_FALLBACK = [0.1320, 0.2126, 0.2937, 0.3748, 0.4554, 0.5349, 0.6146, 0.6944, 0.7735]
BLOCK_WIDTH_TOLERANCE = 0.35


def detect_block_x_bounds_whole_table(image, row_bounds, expected_blocks=8, x_search_range=None, kernel_frac=0.5):
    h, w = image.shape[:2]
    y0f = min(v[0] for v in row_bounds.values())
    y1f = max(v[1] for v in row_bounds.values())
    y0, y1 = int(y0f * h), int(y1f * h)

    x0f, x1f = x_search_range if x_search_range is not None else (0.08, 0.82)
    x_search_0, x_search_1 = int(x0f * w), int(x1f * w)
    table = image[y0:y1, x_search_0:x_search_1]

    gray = cv2.cvtColor(table, cv2.COLOR_BGR2GRAY) if table.ndim == 3 else table
    _, thresh = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU)
    th = thresh.shape[0]
    vert_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (1, max(int(th * 0.5), 3)))
    vertical_lines = cv2.morphologyEx(thresh, cv2.MORPH_OPEN, vert_kernel, iterations=1)
    col_sum = vertical_lines.sum(axis=0) / 255
    print(f"  [diag] col_sum.max()={col_sum.max():.1f}")
    if col_sum.max() == 0:
        return BLOCK_X_BOUNDS_FALLBACK, "fallback"
    threshold_val = max(col_sum.max() * 0.3, 5)
    candidates = np.where(col_sum > threshold_val)[0]
    if len(candidates) == 0:
        return BLOCK_X_BOUNDS_FALLBACK, "fallback"

    lines = []
    start = candidates[0]; prev = candidates[0]
    for x in candidates[1:]:
        if x - prev > 8:
            lines.append((start + prev) // 2)
            start = x
        prev = x
    lines.append((start + prev) // 2)

    cols_px = [x + x_search_0 for x in lines]

    if len(cols_px) < expected_blocks + 1:
        print(f"  [diag] hanya {len(cols_px)} garis terdeteksi (butuh >= {expected_blocks + 1}) -> fallback")
        return BLOCK_X_BOUNDS_FALLBACK, "fallback"

    cols = [x / w for x in cols_px]

    sumber = "auto_whole_table"
    if cols[0] - BLOCK_X_BOUNDS_FALLBACK[0] > 0.03:
        cols = [BLOCK_X_BOUNDS_FALLBACK[0]] + cols
        sumber = "auto_whole_table+left_border_fallback"

    diffs = [cols[i + 1] - cols[i] for i in range(len(cols) - 1)]
    while len(cols) > expected_blocks + 1 and diffs:
        med = sorted(diffs)[len(diffs) // 2]
        left_gap, right_gap = diffs[0], diffs[-1]
        if abs(left_gap - med) <= med * BLOCK_WIDTH_TOLERANCE and abs(right_gap - med) <= med * BLOCK_WIDTH_TOLERANCE:
            break
        if abs(left_gap - med) >= abs(right_gap - med):
            cols = cols[1:]
        else:
            cols = cols[:-1]
        diffs = [cols[i + 1] - cols[i] for i in range(len(cols) - 1)]

    if len(cols) != expected_blocks + 1:
        print(f"  [diag] setelah trim jadi {len(cols)} garis (butuh persis {expected_blocks + 1}) -> fallback")
        return BLOCK_X_BOUNDS_FALLBACK, "fallback"

    FALLBACK_SPAN = BLOCK_X_BOUNDS_FALLBACK[-1] - BLOCK_X_BOUNDS_FALLBACK[0]
    expected_block_width = FALLBACK_SPAN / expected_blocks
    median_width = sorted(diffs)[len(diffs) // 2]
    if abs(median_width - expected_block_width) > expected_block_width * BLOCK_WIDTH_TOLERANCE:
        print(f"  [diag] median_width={median_width:.4f} vs expected={expected_block_width:.4f} -> fallback")
        return BLOCK_X_BOUNDS_FALLBACK, "fallback"

    return cols, sumber


def get_block_x_bounds_validated(block_x_bounds, sumber, row_key):
    if sumber == "fallback":
        raise SectionDetectionError(
            "grid",
            f"[{row_key}] Auto-deteksi kolom GAGAL, jatuh ke fallback statis -- "
            f"BERISIKO SALAH karena fallback dikalibrasi dari foto LAIN."
        )
    return block_x_bounds

def validate_row_bounds_source(row_sumber):
    """Sesuai prinsip Bab 5 Rencana AI: gagal KERAS, bukan diam-diam pakai
    fallback yang berisiko salah. Simetris dengan get_block_x_bounds_validated()
    utk kolom -- sebelumnya row bounds TIDAK punya pengaman ini, cuma print
    peringatan lalu tetap lanjut pakai fallback statis (sumber bug besar
    "grid naik ke atas" yang pernah kita alami)."""
    if row_sumber == "fallback":
        raise SectionDetectionError(
            "grid",
            "Auto-deteksi ROW_BOUNDS GAGAL, jatuh ke fallback statis -- "
            "BERISIKO SALAH karena fallback dikalibrasi dari foto LAIN."
        )

def _cluster_positions(positions, merge_gap):
    """Gabung posisi (y atau x) yg berdekatan (<merge_gap) jadi 1 titik
    (rata-rata). Dipakai stlh Hough Transform, dimana 1 garis fisik sering
    terdeteksi sbg beberapa segmen kecil berdekatan."""
    positions = sorted(positions)
    clusters = []
    cur = [positions[0]]
    for p in positions[1:]:
        if p - cur[-1] > merge_gap:
            clusters.append(sum(cur) / len(cur))
            cur = [p]
        else:
            cur.append(p)
    clusters.append(sum(cur) / len(cur))
    return clusters


def _find_uniform_window_sequential(clusters, n_points, spread_tolerance=0.35,
                                     expected_top_frac=None, region_y0=None, region_h=None):
    """Cari window KONTIGU (n_points elemen berurutan di list clusters) dgn
    spasi paling seragam. Kalau ADA lebih dari 1 window yg sama-sama seragam
    (kasus umum: garis grid lebih banyak dari yg dibutuhkan), window dipilih
    berdasarkan JARAK TERDEKAT ke posisi yg diharapkan (expected_top_frac),
    BUKAN asal ambil yg paling bawah -- itu bug lama yg terbukti salah pilih
    window pd pengujian nyata (garis noise kebetulan seragam scr matematis
    tapi salah scr fisik). Kalau expected_top_frac/region_y0/region_h tidak
    diisi, fallback ke window PALING ATAS (bukan paling bawah -- lebih aman
    drpd default lama)."""
    if len(clusters) < n_points:
        return None

    candidates = []
    for i in range(len(clusters) - n_points + 1):
        window = clusters[i:i + n_points]
        diffs = [window[j + 1] - window[j] for j in range(n_points - 1)]
        med = sorted(diffs)[len(diffs) // 2]
        spread = max(abs(d - med) for d in diffs)
        if spread <= med * spread_tolerance:
            candidates.append(window)

    if not candidates:
        return None

    if expected_top_frac is None or region_y0 is None or region_h is None:
        return candidates[0]

    def _dist_to_anchor(window):
        top_frac = (region_y0 + window[0]) / region_h
        return abs(top_frac - expected_top_frac)

    return min(candidates, key=_dist_to_anchor)


def _find_uniform_window_stepped(clusters, n_points, tolerance_frac=0.15):
    """Cari window TIDAK HARUS kontigu (skip beberapa cluster antar titik) --
    cocok utk kolom blok jam, dimana candidate lines JAUH lebih padat
    (tercampur dgn garis sub-kotak 10 menit). Preferensi: spasi PALING
    BESAR di antara semua window valid yg ditemukan (blok jam pasti lebih
    lebar drpd sub-kotak)."""
    best = None
    for i in range(len(clusters)):
        for j in range(i + 1, len(clusters)):
            spacing = clusters[j] - clusters[i]
            if spacing <= 0:
                continue
            window = [clusters[i], clusters[j]]
            ok = True
            for k in range(2, n_points):
                target = clusters[i] + k * spacing
                nearest = min(clusters, key=lambda c: abs(c - target))
                if abs(nearest - target) > spacing * tolerance_frac:
                    ok = False
                    break
                window.append(nearest)
            if ok and len(window) == n_points:
                if best is None or spacing > best[1]:
                    best = (window, spacing)
    return best[0] if best else None

def detect_row_bounds_hough(image, y_search_range=None, x_search_range=None):
    """Pengganti detect_row_bounds_from_structure() yg TOLERAN kemiringan --
    pakai Hough Transform (deteksi garis pd sudut berapa pun), bukan
    morphological kernel horizontal murni (yg cuma tangkap garis 0° persis).
    Terbukti via pengujian: berhasil menemukan 7-garis seragam bahkan dari
    foto dgn distorsi perspektif berat, TANPA perlu warp/deskew dulu."""
    h, w = image.shape[:2]
    y0f, y1f = y_search_range if y_search_range is not None else ROW_Y_SEARCH_RANGE
    x0f, x1f = x_search_range if x_search_range is not None else (0.14, 0.775)
    y0, y1 = int(y0f * h), int(y1f * h)
    x0, x1 = int(x0f * w), int(x1f * w)
    region = image[y0:y1, x0:x1]
    if region.size == 0:
        return None

    gray = cv2.cvtColor(region, cv2.COLOR_BGR2GRAY) if region.ndim == 3 else region
    edges = cv2.Canny(gray, 50, 150, apertureSize=3)
    rh, rw = gray.shape[:2]

    lines = cv2.HoughLinesP(edges, 1, np.pi / 360, threshold=40,
                             minLineLength=int(rw * 0.15), maxLineGap=10)
    if lines is None:
        print("  [diag row-hough] tidak ada garis terdeteksi Hough -> fallback")
        return None

    ys = []
    for l in lines:
        lx1, ly1, lx2, ly2 = np.ravel(l)
        angle = np.degrees(np.arctan2(ly2 - ly1, lx2 - lx1))
        length = np.hypot(lx2 - lx1, ly2 - ly1)
        if abs(angle) < 15 and length > rw * 0.1:  # toleransi miring +-15 derajat
            ys.append((ly1 + ly2) / 2)

    if len(ys) < 7:
        print(f"  [diag row-hough] hanya {len(ys)} kandidat garis horizontal -> fallback")
        return None

    clusters = _cluster_positions(ys, merge_gap=max(6, int(rh * 0.01)))
    window = _find_uniform_window_sequential(
        clusters, 7, spread_tolerance=0.35,
        expected_top_frac=ROW_BOUNDS_FALLBACK["jam_07_15"][0],
        region_y0=y0, region_h=h,
    )
    if window is None:
        print(f"  [diag row-hough] {len(clusters)} garis, tidak ada window 7-seragam -> fallback")
        return None

    print(f"  [diag row-hough] window 7-garis ditemukan via Hough: {[round(x) for x in window]}")
    y_abs = [y0 + ly for ly in window]
    row_bounds = {
        "jam_07_15": (y_abs[0] / h, y_abs[2] / h),
        "jam_15_23": (y_abs[2] / h, y_abs[4] / h),
        "jam_23_07": (y_abs[4] / h, y_abs[6] / h),
    }
    lost_time_bounds = {
        "jam_07_15": (y_abs[1] / h, y_abs[2] / h),
        "jam_15_23": (y_abs[3] / h, y_abs[4] / h),
        "jam_23_07": (y_abs[5] / h, y_abs[6] / h),
    }
    return row_bounds, lost_time_bounds

def detect_block_x_bounds_hough(image, row_bounds, expected_blocks=8, x_search_range=None):
    """Pengganti detect_block_x_bounds_whole_table() -- sama alasannya spt
    versi baris, pakai Hough + pencarian window TIDAK-KONTIGU (krn garis
    sub-kotak 10 menit jauh lebih padat drpd garis blok jam)."""
    h, w = image.shape[:2]
    y0f = min(v[0] for v in row_bounds.values())
    y1f = max(v[1] for v in row_bounds.values())
    y0, y1 = int(y0f * h), int(y1f * h)
    x0f, x1f = x_search_range if x_search_range is not None else (0.08, 0.82)
    x0, x1 = int(x0f * w), int(x1f * w)

    table = image[y0:y1, x0:x1]
    if table.size == 0:
        return BLOCK_X_BOUNDS_FALLBACK, "fallback"

    gray = cv2.cvtColor(table, cv2.COLOR_BGR2GRAY) if table.ndim == 3 else table
    edges = cv2.Canny(gray, 50, 150, apertureSize=3)
    th, tw = gray.shape[:2]

    lines = cv2.HoughLinesP(edges, 1, np.pi / 360, threshold=40,
                             minLineLength=20, maxLineGap=6)
    if lines is None:
        print("  [diag col-hough] tidak ada garis terdeteksi -> fallback")
        return BLOCK_X_BOUNDS_FALLBACK, "fallback"

    xs = []
    for l in lines:
        lx1, ly1, lx2, ly2 = np.ravel(l)
        angle = np.degrees(np.arctan2(ly2 - ly1, lx2 - lx1))
        ymin, ymax = min(ly1, ly2), max(ly1, ly2)
        overlap = min(ymax, th) - max(ymin, 0)
        ang_from_vert = abs(abs(angle) - 90)
        if ang_from_vert < 25 and overlap > 15:  # toleransi miring +-25 derajat dari vertikal
            xs.append((lx1 + lx2) / 2)

    if len(xs) < expected_blocks + 1:
        print(f"  [diag col-hough] hanya {len(xs)} kandidat garis vertikal -> fallback")
        return BLOCK_X_BOUNDS_FALLBACK, "fallback"

    clusters = _cluster_positions(xs, merge_gap=max(8, int(tw * 0.005)))
    window = _find_uniform_window_stepped(clusters, expected_blocks + 1, tolerance_frac=0.15)
    if window is None:
        print(f"  [diag col-hough] {len(clusters)} garis, tidak ada window {expected_blocks+1}-seragam -> fallback")
        return BLOCK_X_BOUNDS_FALLBACK, "fallback"

    print(f"  [diag col-hough] window {expected_blocks+1}-garis ditemukan via Hough: {[round(x) for x in window]}")
    cols = [(x0 + lx) / w for lx in window]
    return cols, "auto_hough"
# ============================================================
# 7. MODE E — crop per-kotak-kecil (dari Cell 47/49)
# ============================================================
CELL_CROP_MARGIN_X = 0.06
CELL_CROP_MARGIN_Y = 0.10
CELL_UPSCALE_TARGET = (260, 260)
CELL_INK_MARGIN_X = 0.24
CELL_INK_MARGIN_Y = 0.15
CELL_DARK_PIXEL_VALUE = 150
CELL_INK_RATIO_THRESHOLD = 0.020
CELL_ROW_UPSCALE_MIN_HEIGHT = 600


def cell_x_bounds(block_x_bounds, block_idx, cell_idx, n_cells=6):
    bx0, bx1 = block_x_bounds[block_idx], block_x_bounds[block_idx + 1]
    cell_w = (bx1 - bx0) / n_cells
    return bx0 + cell_idx * cell_w, bx0 + (cell_idx + 1) * cell_w


def crop_cell(image, block_idx, cell_idx, block_x_bounds, lost_time_bounds,
              margin_x=CELL_CROP_MARGIN_X, margin_y=CELL_CROP_MARGIN_Y):
    h, w = image.shape[:2]
    ry0, ry1 = lost_time_bounds
    cx0f, cx1f = cell_x_bounds(block_x_bounds, block_idx, cell_idx)
    cw = cx1f - cx0f
    rh = ry1 - ry0
    cx0f2, cx1f2 = cx0f + margin_x * cw, cx1f - margin_x * cw
    ry0_2, ry1_2 = ry0 + margin_y * rh, ry1 - margin_y * rh

    y0px, y1px = int(ry0_2 * h), int(ry1_2 * h)
    x0px, x1px = int(cx0f2 * w), int(cx1f2 * w)
    crop = image[y0px:y1px, x0px:x1px]
    if crop.size == 0:
        return np.zeros((*CELL_UPSCALE_TARGET[::-1], 3), dtype=np.uint8)

    ch = crop.shape[0]
    if ch < CELL_ROW_UPSCALE_MIN_HEIGHT:
        scale = CELL_ROW_UPSCALE_MIN_HEIGHT / ch
        crop = cv2.resize(crop, (int(crop.shape[1] * scale), CELL_ROW_UPSCALE_MIN_HEIGHT),
                           interpolation=cv2.INTER_CUBIC)

    target_w, target_h = CELL_UPSCALE_TARGET
    return cv2.resize(crop, (target_w, target_h), interpolation=cv2.INTER_CUBIC)

def build_debug_overlay(image, header_bounds, speed_bounds, block_x_bounds, lost_time_bounds, target_row_key):
    h, w = image.shape[:2]
    vis = image.copy()

    hy0, hy1 = header_bounds
    cv2.rectangle(vis, (0, int(hy0 * h)), (w, int(hy1 * h)), (255, 128, 0), 3)
    cv2.putText(vis, "HEADER", (10, int(hy1 * h) - 10),
                cv2.FONT_HERSHEY_SIMPLEX, 1.0, (255, 128, 0), 2)

    sy0, sy1 = speed_bounds
    cv2.rectangle(vis, (0, int(sy0 * h)), (w, int(sy1 * h)), (0, 220, 255), 3)
    cv2.putText(vis, "SPEED (area pencarian)", (10, int(sy0 * h) - 10),
                cv2.FONT_HERSHEY_SIMPLEX, 1.0, (0, 220, 255), 2)

    ry0, ry1 = lost_time_bounds
    y0px, y1px = int(ry0 * h), int(ry1 * h)
    for block_idx in range(8):
        bx0, bx1 = block_x_bounds[block_idx], block_x_bounds[block_idx + 1]
        x0px, x1px = int(bx0 * w), int(bx1 * w)
        cv2.rectangle(vis, (x0px, y0px), (x1px, y1px), (0, 200, 0), 2)
        for cell_idx in range(1, 6):
            cx = int((bx0 + cell_idx * (bx1 - bx0) / 6) * w)
            cv2.line(vis, (cx, y0px), (cx, y1px), (0, 200, 255), 1)

    cv2.putText(vis, f"GRID LOST TIME ({target_row_key})", (int(0.14 * w), y0px - 10),
                cv2.FONT_HERSHEY_SIMPLEX, 1.0, (0, 200, 0), 2)

    return vis

def _cell_ink_ratio(image, block_idx, cell_idx, block_x_bounds, lost_time_bounds):
    cell = crop_cell(image, block_idx, cell_idx, block_x_bounds, lost_time_bounds,
                      margin_x=CELL_INK_MARGIN_X, margin_y=CELL_INK_MARGIN_Y)
    gray = cv2.cvtColor(cell, cv2.COLOR_BGR2GRAY) if cell.ndim == 3 else cell
    dark = (gray < CELL_DARK_PIXEL_VALUE).sum()
    return float(dark) / gray.size if gray.size else 0.0


def extract_cell(cfg: OllamaConfig, image, block_idx, cell_idx, block_x_bounds, lost_time_bounds):
    ink_ratio = _cell_ink_ratio(image, block_idx, cell_idx, block_x_bounds, lost_time_bounds)
    if ink_ratio < CELL_INK_RATIO_THRESHOLD:
        return None, ink_ratio, "kosong (skip)"

    cell_image = crop_cell(image, block_idx, cell_idx, block_x_bounds, lost_time_bounds)
    result = _call_ollama(cfg, cell_image, CELL_PROMPT)
    kode = result.get("kode")
    return kode, ink_ratio, "dipanggil ke model"

# PATCH v6: pemetaan shift -> baris grid yang perlu diproses.
# Shift 1 = jam 07.00-15.00, Shift 2 = jam 15.00-23.00, Shift 3 = jam 23.00-07.00
# (asumsi 1 shift = 8 jam, sesuai pola baris di kertas). Sesuaikan mapping ini
# kalau ternyata penomoran shift di pabrik Anda berbeda.
SHIFT_TO_ROW_KEY = {
    "1": "jam_07_15",
    "2": "jam_15_23",
    "3": "jam_23_07",
}

def normalize_shift(raw_shift):
    """Ambil digit 1/2/3 pertama dari teks shift mentah. Return None kalau
    tidak ada digit valid yang cocok -- JANGAN menebak, biar caller fallback
    ke proses semua baris."""
    if raw_shift is None:
        return None
    match = re.search(r"[123]", str(raw_shift))
    return match.group(0) if match else None

def detect_header(cfg: OllamaConfig, image, header_bounds=(0.000, 0.125)):
    h, w = image.shape[:2]
    margin = 0.015
    hy0, hy1 = header_bounds
    header_crop = image[0:min(int((hy1 + margin) * h), h), :]
    print("Memanggil model untuk section header...")
    return _call_ollama(cfg, header_crop, HEADER_ONLY_PROMPT)

def extract_split_by_cell(cfg: OllamaConfig, image, header_result, target_row_key):
    """target_row_key SUDAH pasti (hasil normalize_shift atau shift_override
    dari run_pipeline) -- fungsi ini tidak lagi menebak/fallback sendiri."""
    row_bounds, lost_time_precomputed, row_sumber = get_calibrated_row_bounds(image)
    print(f"ROW_BOUNDS terkalibrasi via: {row_sumber}")
    validate_row_bounds_source(row_sumber)

    block_x_bounds_raw, col_sumber = detect_block_x_bounds_hough(image, row_bounds)
    print(f"block_x_bounds terkalibrasi via: {col_sumber}")
    block_x_bounds = get_block_x_bounds_validated(block_x_bounds_raw, col_sumber, "whole_table")

    all_row_keys = ["jam_07_15", "jam_15_23", "jam_23_07"]
    print(f"HANYA proses baris '{target_row_key}' (48 kotak, bukan 144).")

    n_calls = 0
    all_grid = []
    for row_key in all_row_keys:
        labels = ROW_BLOCK_LABELS[row_key]

        if row_key != target_row_key:
            for label in labels:
                all_grid.append({"jam_mulai": label, "blok": [None] * 6})
            continue

        lost_time_bounds = lost_time_precomputed[row_key]
        print(f"\n{row_key} | Lost-time bounds: {lost_time_bounds}")
        for block_idx in range(8):
            blok = []
            for cell_idx in range(6):
                kode, ink_ratio, status = extract_cell(
                    cfg, image, block_idx, cell_idx, block_x_bounds, lost_time_bounds)
                if status == "dipanggil ke model":
                    n_calls += 1
                print(f"    {labels[block_idx]} kotak-{cell_idx+1}: ink={ink_ratio:.4f} "
                      f"-> {status} -> kode={kode!r}")
                blok.append(kode)
            all_grid.append({"jam_mulai": labels[block_idx], "blok": blok})

    print(f"\nTotal panggilan model untuk grid: {n_calls}")
    lost_time_bounds = lost_time_precomputed[target_row_key]
    header_bounds_for_overlay = (0.000, 0.125)
    speed_bounds_for_overlay = DATA_TABLE_CROP_Y
    overlay_img = build_debug_overlay(
        image, header_bounds_for_overlay, speed_bounds_for_overlay,
        block_x_bounds, lost_time_bounds, target_row_key
    )
    merged = {**header_result, "grid_waktu": all_grid}
    meta = {
        "row_bounds_source": row_sumber,
        "column_bounds_source": col_sumber,
        "total_cell_model_calls": n_calls,
    }
    return MFDowntimeExtraction(**merged), meta, overlay_img

def run_pipeline(cfg: OllamaConfig, image, shift_override=None):
    header_result = detect_header(cfg, image)
    speed_value, size_value = extract_speed_and_size(cfg, image)
    header_result["speed"] = speed_value

    if shift_override:
        resolved_shift = shift_override
        shift_source = "user_confirmed"
        print(f"Shift dari user (override): '{resolved_shift}'")
    else:
        resolved_shift = normalize_shift(header_result.get("shift"))
        shift_source = "auto_detected" if resolved_shift else None
        if resolved_shift:
            print(f"Shift terbaca otomatis: '{resolved_shift}'")

    if resolved_shift is None:
        print(f"Shift TIDAK terbaca jelas (raw={header_result.get('shift')!r}) -> "
              f"berhenti di sini, minta konfirmasi user (grid BELUM diproses).")
        return {
            "status": "needs_confirmation",
            "reason": "shift_ambiguous",
            "data": {**header_result, "grid_waktu": [], "size_raw": size_value},
            "meta": {"shift_raw": header_result.get("shift")},
        }

    target_row_key = SHIFT_TO_ROW_KEY[resolved_shift]
    try:
        result, meta, overlay_img = extract_split_by_cell(cfg, image, header_result, target_row_key)
    except SectionDetectionError as e:
        print(f"Grid gagal-keras ({e}) -> minta foto close-up section 'grid'.")
        return {
            "status": "needs_section_photo",
            "section": "grid",
            "data": {**header_result, "grid_waktu": [], "size_raw": size_value},
            "meta": {"shift_resolved": resolved_shift},
        }
    result_data = result.model_dump()
    result_data["shift"] = resolved_shift
    result_data["size_raw"] = size_value  # PATCH: field baru, MFDowntimeExtraction tidak punya field ini (extra diabaikan pydantic saat merge di extract_split_by_cell), jadi disisipkan manual di sini setelah model_dump()
    meta["shift_source"] = shift_source
    meta["rows_processed"] = [target_row_key]

    if all(result_data.get(f) is None for f in ("tanggal", "mesin_code", "shift")):
        print("Header semua null -> minta foto close-up section 'header'.")
        return {
            "status": "needs_section_photo", "section": "header",
            "data": result_data, "meta": meta,
        }
    if result_data.get("speed") is None and result_data.get("size_raw") is None:
        print("Speed & Size null -> minta foto close-up section 'speed_size'.")
        return {
            "status": "needs_section_photo", "section": "speed_size",
            "data": result_data, "meta": meta,
        }

    envelope = {"status": "success", "data": result_data, "meta": meta}
    envelope["_overlay_img"] = overlay_img
    return envelope

def run_section_closeup_pipeline(cfg: OllamaConfig, image, section: str, shift_override=None, three_points=None):
    """Proses foto close-up SATU section saja. image di sini SUDAH lolos
    upscale+enhance TAPI TIDAK lewat auto_crop_document() -- lihat main()."""
    if section == "header":
        data = _call_ollama(cfg, image, HEADER_ONLY_PROMPT)
        return {"status": "success", "section": section, "data": data, "meta": {}}

    if section == "speed_size":
        speed_value, size_value = extract_speed_and_size(
            cfg, image, crop_y=(0.0, 1.0), crop_x=(0.0, 1.0)
        )
        return {
            "status": "success", "section": section,
            "data": {"speed": speed_value, "size_raw": size_value}, "meta": {},
        }

    if section == "grid":
        if not shift_override:
            raise ValueError("section='grid' WAJIB disertai shift_override.")
        if not three_points or len(three_points) != 3:
            raise ValueError("section='grid' WAJIB disertai 3 titik (kiri-atas, kanan-atas, kiri-bawah).")

        target_row_key = SHIFT_TO_ROW_KEY[shift_override]
        warped = warp_from_3_points(image, three_points)
        block_x_bounds = compute_uniform_block_x_bounds(n_blocks=8)
        lost_time_bounds = (0.0, 1.0)  # seluruh tinggi hasil warp = baris value itu sendiri

        labels = ROW_BLOCK_LABELS[target_row_key]
        blok_list = []
        n_calls = 0
        for block_idx in range(8):
            blok = []
            for cell_idx in range(6):
                kode, ink_ratio, status = extract_cell(
                    cfg, warped, block_idx, cell_idx, block_x_bounds, lost_time_bounds)
                if status == "dipanggil ke model":
                    n_calls += 1
                blok.append(kode)
            blok_list.append({"jam_mulai": labels[block_idx], "blok": blok})

        return {
            "status": "success", "section": section,
            "data": {"grid_waktu_partial": blok_list, "row_key": target_row_key},
            "meta": {"method": "manual_3point_proportional", "total_cell_model_calls": n_calls},
        }

    raise ValueError(f"section tidak dikenal: {section!r}")

# ============================================================
# 8. MAIN — orkestrasi + JSON envelope ke stdout
# ============================================================
def main():
    parser = argparse.ArgumentParser(description="Ekstrak MFDOWNTIME dari foto kertas (Mode E).")
    parser.add_argument("--image", required=True, help="Path foto input (mentah, belum di-crop).")
    parser.add_argument("--model", default="qwen2.5vl:7b")
    parser.add_argument("--ollama-url", default="http://127.0.0.1:11434")
    parser.add_argument("--timeout", type=int, default=600, help="Timeout per panggilan Ollama (detik).")
    parser.add_argument("--num-ctx", type=int, default=16384)
    parser.add_argument("--keep-temp", action="store_true",
                         help="Jangan hapus file foto_bersih_*.jpg hasil preprocessing setelah selesai.")
    parser.add_argument("--shift-override", choices=["1", "2", "3"], default=None,
                         help="Shift yang SUDAH dikonfirmasi user (skip deteksi otomatis, langsung pakai ini).")
    parser.add_argument("--section", choices=["header", "speed_size", "grid"], default=None,
                         help="Kalau diisi: foto INI adalah close-up 1 section saja -- skip auto_crop_document().")
    parser.add_argument("--points", default=None,
                         help="JSON string 3 titik {x,y} (0-1) [kiri-atas,kanan-atas,kiri-bawah] -- KHUSUS section=grid, wajib ada.")
    args = parser.parse_args()
    # (TIDAK ADA kode overlay di sini -- sudah dihapus, pindah ke bawah)

    t0 = time.time()
    clean_path = None
    try:
        image_path = Path(args.image)
        if not image_path.exists():
            raise FileNotFoundError(f"File tidak ditemukan: {image_path}")

        cfg = OllamaConfig(base_url=args.ollama_url, model=args.model,
                            timeout=args.timeout, num_ctx=args.num_ctx)

        if args.section:
            # Mode close-up: TIDAK lewat auto_crop_document() sama sekali
            # (lihat kesepakatan Fase O-lanjutan) -- cukup upscale+enhance,
            # KECUALI kalau user menandai 4 sudut manual (khusus grid) --
            # itu di-warp DULU sebelum upscale, supaya deteksi baris/kolom
            # bekerja di atas gambar yg sudah lurus (bukan miring/perspektif).
            raw_image = imread_exif_safe(str(image_path))
            if raw_image is None:
                raise FileNotFoundError(f"Tidak bisa baca gambar: {image_path}")

            crop_method = "skipped_closeup"
            image = raw_image  # TIDAK di-upscale/enhance di sini utk grid --
                                # warp_from_3_points butuh koordinat relatif ke
                                # gambar ASLI (sama seperti dihitung JS dari file asli)
            three_points = None
            if args.section == "grid":
                if not args.points:
                    raise ValueError("section='grid' wajib disertai --points (3 titik).")
                three_points = json.loads(args.points)
                crop_method = "manual_3point_affine"
            else:
                image = enhance(upscale_if_needed(raw_image))

            clean_path = None  # tidak ada file sementara utk dihapus di finally

            try:
                envelope = run_section_closeup_pipeline(
                    cfg, image, args.section, shift_override=args.shift_override, three_points=three_points
                )
            except SectionDetectionError as e:
                envelope = {
                    "status": "needs_section_photo",
                    "section": e.section,
                    "reason": str(e),
                    "meta": {},
                }
        else:
            clean_path = str(image_path.parent / f"foto_bersih_{image_path.stem}.jpg")
            clean_path, crop_success, crop_method = preprocess_image(str(image_path), clean_path)

            if not crop_success:
                print("Berhenti sebelum ekstraksi AI -- minta foto ulang.")
                envelope = {
                    "status": "needs_retake",
                    "reason": "corner_detection_failed",
                    "meta": {"elapsed_seconds": round(time.time() - t0, 1)},
                }
                _stdout_print(json.dumps(envelope, ensure_ascii=False))
                return 0

            image = cv2.imread(clean_path)
            if image is None:
                raise RuntimeError(f"Gagal baca hasil preprocessing: {clean_path}")

            envelope = run_pipeline(cfg, image, shift_override=args.shift_override)

        # PATCH: kalau ada overlay image (status sukses), simpan ke file
        # DULU, ganti isi meta jadi PATH STRING, cabut key mentahnya --
        # numpy array tidak bisa di-json.dumps().
        overlay_img = envelope.pop("_overlay_img", None)
        if overlay_img is not None:
            overlay_path = str(image_path.parent / f"overlay_{image_path.stem}.jpg")
            cv2.imwrite(overlay_path, overlay_img)
            envelope.setdefault("meta", {})["overlay_image_path"] = overlay_path
            print(f"Overlay debug disimpan -> {overlay_path}")

        elapsed = time.time() - t0
        envelope.setdefault("meta", {})["elapsed_seconds"] = round(elapsed, 1)
        envelope["meta"]["crop_method"] = crop_method
        print(f"\nSelesai dalam {elapsed:.1f}s (status: {envelope['status']})")

        _stdout_print(json.dumps(envelope, ensure_ascii=False))
        return 0 if envelope["status"] != "error" else 1

    except Exception as e:
        print("ERROR:", traceback.format_exc())
        envelope = {
            "status": "error",
            "error": str(e),
            "error_type": type(e).__name__,
        }
        _stdout_print(json.dumps(envelope, ensure_ascii=False))
        return 1

    finally:
        if clean_path and not args.keep_temp:
            try:
                Path(clean_path).unlink(missing_ok=True)
            except Exception:
                pass

if __name__ == "__main__":
    sys.exit(main())
