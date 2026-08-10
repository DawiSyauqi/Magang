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
    image = cv2.imread(input_path)
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
  "shift": string atau null, "speed": number atau null,
  "operator_nama": string atau null,
  "low_confidence_fields": [string, ...] atau null
}

- "tanggal": label "Hari & Tanggal", transkrip PERSIS seperti tertulis.
- "mesin_code": label "Mesin" -- TRANSKRIP APA ADANYA, jangan menilai
  valid/tidak atau mengoreksi ke kode lain (akan dikoreksi lewat
  pencocokan Tahap 4b, bukan tugasmu di sini).
- "shift": label "Shift". "speed": label "Speed (m/mnt)", jadi angka (contoh "9-6" -> 9.6).
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

SPEED_ONLY_PROMPT = '''
Kamu melihat SATU POTONGAN KECIL dari form kertas industri -- HANYA berisi
baris "Speed (m/mnt)" (kadang ikut sedikit baris "Size" di atasnya, dan
baris "Dies Pass" di bawahnya yang biasanya KOSONG -- abaikan keduanya).

Kembalikan HANYA JSON (tanpa teks lain):
{"speed": number atau null}

- Baca angka di baris berlabel "Speed (m/mnt)" SAJA. JANGAN baca baris
  "Size" (biasanya format "NN.NN MM", BUKAN speed).
- Jadikan angka desimal (contoh "9-6" -> 9.6, "3.6" -> 3.6).
- Huruf "G" di posisi angka hampir pasti "6"; huruf "O" hampir pasti "0".
- Baris kosong / tidak terbaca -> null. JANGAN mengarang.
- Output JSON valid saja, PERSIS 1 field "speed".
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
    "{\"kode\": null}    <- kalau kotak ini KOSONG (tidak ada coretan sama sekali)\n\n"
    "ATURAN PENTING:\n"
    "1. Kode berupa 1 digit angka (0-8), ATAU 1 digit angka + 1 huruf kecil\n"
    "   (mis. \"6a\", \"2f\", \"3c\"). Huruf kecil \"l\" BEDA dari angka \"1\" --\n"
    "   perhatikan baik-baik bentuknya.\n"
    "2. JANGAN PERNAH mengarang kode. Kalau ragu atau kotak kelihatan kosong\n"
    "   -> null.\n"
    "3. Abaikan garis tepi kotak/bayangan/noise -- itu bukan kode.\n"
    "4. \"kode\" WAJIB JSON string bertanda kutip ganda (termasuk \"0\"), atau\n"
    "   null (tanpa kutip) kalau kosong.\n"
    "5. Output HARUS JSON valid saja, PERSIS 1 field \"kode\".\n"
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




SPEED_ANCHOR_VALIDATION_STRIP = 0.015  # tinggi strip di bawah kandidat, utk cek grid vertikal
SPEED_ANCHOR_MIN_VERTICAL_LINES = 5    # grid Jenis Lubricant py BANYAK kolom -- min 5 garis vertikal


def _count_vertical_lines_below(image, y_frac, x_search=(0.14, 0.775), strip_height=SPEED_ANCHOR_VALIDATION_STRIP):
    """Hitung berapa garis vertikal rapat ada TEPAT DI BAWAH y_frac -- ciri
    khas grid kotak-kecil (Jenis Lubricant/Kondisi/dst), BUKAN baris teks
    biasa yang cuma py 1-2 garis vertikal (tepi tabel kiri-kanan saja)."""
    h, w = image.shape[:2]
    y0 = int(y_frac * h)
    y1 = int(min(1.0, y_frac + strip_height) * h)
    x0, x1 = int(x_search[0] * w), int(x_search[1] * w)
    strip = image[y0:y1, x0:x1]
    if strip.size == 0:
        return 0
    gray = cv2.cvtColor(strip, cv2.COLOR_BGR2GRAY) if strip.ndim == 3 else strip
    _, thresh = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU)
    th = thresh.shape[0]
    vert_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (1, max(int(th * 0.6), 3)))
    vertical_lines = cv2.morphologyEx(thresh, cv2.MORPH_OPEN, vert_kernel, iterations=1)
    col_sum = vertical_lines.sum(axis=0) / 255
    if col_sum.max() == 0:
        return 0
    candidates = np.where(col_sum > col_sum.max() * 0.5)[0]
    if len(candidates) == 0:
        return 0
    # cluster jadi garis individual (mirip pola clustering yg sudah ada)
    lines = 1
    prev = candidates[0]
    for x in candidates[1:]:
        if x - prev > 8:
            lines += 1
        prev = x
    return lines


def detect_lubricant_grid_top(image, y_search=(0.28, 0.50), x_search=(0.14, 0.775)):
    """Deteksi garis batas ATAS grid kotak-kecil 'Jenis Lubricant'.
    PATCH: sekarang divalidasi -- cek beberapa kandidat garis terkuat,
    pilih yang BENAR py banyak garis vertikal rapat di bawahnya (ciri
    grid), bukan cuma percaya puncak sinyal horizontal tertinggi saja
    (itu sebelumnya bisa salah tangkap garis lain, mis. noise kompresi)."""
    h, w = image.shape[:2]
    y0f, y1f = y_search
    x0f, x1f = x_search
    y0, y1 = int(y0f * h), int(y1f * h)
    x0, x1 = int(x0f * w), int(x1f * w)
    region = image[y0:y1, x0:x1]
    gray = cv2.cvtColor(region, cv2.COLOR_BGR2GRAY) if region.ndim == 3 else region

    th = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
                                cv2.THRESH_BINARY_INV, 25, 10)
    close_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (15, 1))
    closed = cv2.morphologyEx(th, cv2.MORPH_CLOSE, close_kernel, iterations=2)
    row_sum = closed.sum(axis=1) / 255
    rw = closed.shape[1]

    # Ambil TOP 5 kandidat garis terkuat (bukan cuma 1), urut dari nilai
    # tertinggi -- lalu cek satu-satu mana yang beneran py grid di bawahnya.
    top_candidates_idx = np.argsort(row_sum)[::-1][:15]  # ambil lebih banyak dulu, nanti di-dedupe
    seen_y = []
    checked = 0
    for idx in top_candidates_idx:
        y_frac = (y0 + idx) / h
        if any(abs(y_frac - s) < 0.01 for s in seen_y):  # skip duplikat berdekatan
            continue
        seen_y.append(y_frac)
        checked += 1

        confidence = row_sum[idx] / rw
        vert_lines = _count_vertical_lines_below(image, y_frac)
        print(f"  [diag speed anchor] kandidat y={y_frac:.4f} confidence={confidence:.3f} "
              f"garis_vertikal_di_bawah={vert_lines}")

        if vert_lines >= SPEED_ANCHOR_MIN_VERTICAL_LINES:
            print(f"  [diag speed anchor] TERPILIH y={y_frac:.4f} (tervalidasi py grid di bawahnya)")
            return y_frac, confidence

        if checked >= 8:  # batasi jumlah kandidat dicek, jangan terlalu lama
            break

    print("  [diag speed anchor] TIDAK ADA kandidat yang tervalidasi py grid di bawahnya")
    return None, 0.0

HEADER_ANCHOR_MIN_CONFIDENCE = 0.5
SPEED_CROP_MARGIN_HIGH = 0.075  # naik dari anchor, mencakup Speed+Dies Pass
SPEED_CROP_MARGIN_LOW = 0.005

def extract_speed(cfg: OllamaConfig, image) -> Optional[float]:
    anchor_frac, confidence = detect_lubricant_grid_top(image)

    if anchor_frac is None or confidence < HEADER_ANCHOR_MIN_CONFIDENCE:
        print(f"  [diag speed] anchor gagal tervalidasi -> field speed di-skip (null).")
        return None

    y0 = max(0.0, anchor_frac - SPEED_CROP_MARGIN_HIGH)
    y1 = min(1.0, anchor_frac - SPEED_CROP_MARGIN_LOW)
    h, w = image.shape[:2]
    speed_crop = image[int(y0 * h):int(y1 * h), int(0.14 * w):int(0.775 * w)]

    result = _call_ollama(cfg, speed_crop, SPEED_ONLY_PROMPT)
    return _parse_measurement(result.get("speed"))




def get_header_crop_bounds(image):
    """HANYA dipakai utk overlay visual (build_debug_overlay) -- BUKAN lagi
    dipakai detect_header() (itu sudah kembali pakai crop tetap 0.125).
    Kalau anchor gagal, kembalikan fallback 0.125 biar overlay tetap bisa
    dibuat (bukan proses kritikal, cuma bantuan visual)."""
    anchor_frac, confidence = detect_lubricant_grid_top(image)

    if anchor_frac is None or confidence < HEADER_ANCHOR_MIN_CONFIDENCE:
        print(f"  [diag header] anchor gagal tervalidasi -> overlay pakai fallback 0.125")
        return 0.000, 0.125

    print(f"  [diag header] anchor grid-lubricant terdeteksi di y={anchor_frac:.4f} "
          f"(confidence={confidence:.3f})")
    return 0.000, anchor_frac

def detect_row_bounds_from_structure(image):
    h, w = image.shape[:2]
    y0f, y1f = ROW_Y_SEARCH_RANGE
    y0, y1 = int(y0f * h), int(y1f * h)
    x0, x1 = int(0.14 * w), int(0.775 * w)
    region = image[y0:y1, x0:x1]
    gray = cv2.cvtColor(region, cv2.COLOR_BGR2GRAY) if region.ndim == 3 else region
    _, thresh = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU)
    rw = thresh.shape[1]
    horiz_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (max(int(rw * 0.6), 3), 1))
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
        size_ok = abs(med - expected_px) <= expected_px * SUBROW_HEIGHT_TOLERANCE
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


def get_calibrated_row_bounds(image):
    result = detect_row_bounds_from_structure(image)
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


def detect_block_x_bounds_whole_table(image, row_bounds, expected_blocks=8):
    h, w = image.shape[:2]
    y0f = min(v[0] for v in row_bounds.values())
    y1f = max(v[1] for v in row_bounds.values())
    y0, y1 = int(y0f * h), int(y1f * h)

    x_search_0, x_search_1 = int(0.08 * w), int(0.82 * w)
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
        raise RuntimeError(
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
        raise RuntimeError(
            "Auto-deteksi ROW_BOUNDS GAGAL, jatuh ke fallback statis -- "
            "BERISIKO SALAH karena fallback dikalibrasi dari foto LAIN."
        )


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
    """Gambar overlay SEMUA area yang dipakai pipeline -- SEKARANG header
    dan speed digambar sebagai 2 KOTAK TERPISAH sesuai crop ASLI yang
    benar-benar dikirim ke model (sebelumnya 1 kotak gabungan yang
    menyesatkan, tidak merepresentasikan crop speed yang sebenarnya sempit)."""
    h, w = image.shape[:2]
    vis = image.copy()

    # 1. Area HEADER asli (fixed 0.000 - 0.125) -- kotak biru
    hy0, hy1 = header_bounds
    cv2.rectangle(vis, (0, int(hy0 * h)), (w, int(hy1 * h)), (255, 128, 0), 3)
    cv2.putText(vis, "HEADER", (10, int(hy1 * h) - 10),
                cv2.FONT_HERSHEY_SIMPLEX, 1.0, (255, 128, 0), 2)

    # 2. Area SPEED asli (pita sempit sekitar anchor) -- kotak kuning,
    #    HANYA digambar kalau anchor berhasil terdeteksi.
    if speed_bounds is not None:
        sy0, sy1 = speed_bounds
        cv2.rectangle(vis, (int(0.14 * w), int(sy0 * h)), (int(0.775 * w), int(sy1 * h)), (0, 220, 255), 3)
        cv2.putText(vis, "SPEED", (int(0.15 * w), int(sy0 * h) - 10),
                    cv2.FONT_HERSHEY_SIMPLEX, 1.0, (0, 220, 255), 2)
    else:
        cv2.putText(vis, "SPEED: GAGAL DETEKSI", (int(0.15 * w), int(0.30 * h)),
                    cv2.FONT_HERSHEY_SIMPLEX, 1.0, (0, 0, 255), 2)

    # 3. Grid Lost Time -- garis blok jam (vertikal) + kotak 10 menit
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
    header_crop = image[0:int((hy1 + margin) * h), :]
    print("Memanggil model untuk section header...")
    return _call_ollama(cfg, header_crop, HEADER_ONLY_PROMPT)

def extract_split_by_cell(cfg: OllamaConfig, image, header_result, target_row_key):
    """target_row_key SUDAH pasti (hasil normalize_shift atau shift_override
    dari run_pipeline) -- fungsi ini tidak lagi menebak/fallback sendiri."""
    row_bounds, lost_time_precomputed, row_sumber = get_calibrated_row_bounds(image)
    print(f"ROW_BOUNDS terkalibrasi via: {row_sumber}")
    validate_row_bounds_source(row_sumber)

    block_x_bounds_raw, col_sumber = detect_block_x_bounds_whole_table(image, row_bounds)
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

    # Hitung ulang bounds ASLI (murah, bukan panggilan model) utk overlay
    # jujur -- header FIXED 0.125, speed PITA SEMPIT sekitar anchor (kalau
    # terdeteksi), BUKAN 1 kotak gabungan seperti sebelumnya.
    header_bounds_for_overlay = (0.000, 0.125)
    anchor_frac, anchor_conf = detect_lubricant_grid_top(image)
    if anchor_frac is not None and anchor_conf >= HEADER_ANCHOR_MIN_CONFIDENCE:
        speed_bounds_for_overlay = (
            max(0.0, anchor_frac - SPEED_CROP_MARGIN_HIGH),
            min(1.0, anchor_frac - SPEED_CROP_MARGIN_LOW),
        )
    else:
        speed_bounds_for_overlay = None

    lost_time_bounds = lost_time_precomputed[target_row_key]
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
    speed_value = extract_speed(cfg, image)
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
            "data": {**header_result, "grid_waktu": []},
            "meta": {"shift_raw": header_result.get("shift")},
        }

    target_row_key = SHIFT_TO_ROW_KEY[resolved_shift]
    result, meta, overlay_img = extract_split_by_cell(cfg, image, header_result, target_row_key)
    result_data = result.model_dump()
    result_data["shift"] = resolved_shift
    meta["shift_source"] = shift_source
    meta["rows_processed"] = [target_row_key]

    envelope = {"status": "success", "data": result_data, "meta": meta}
    envelope["_overlay_img"] = overlay_img  # numpy array -- HARUS dicabut sebelum json.dumps, lihat main()
    return envelope



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
    args = parser.parse_args()
    # (TIDAK ADA kode overlay di sini -- sudah dihapus, pindah ke bawah)

    t0 = time.time()
    clean_path = None
    try:
        image_path = Path(args.image)
        if not image_path.exists():
            raise FileNotFoundError(f"File tidak ditemukan: {image_path}")

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

        cfg = OllamaConfig(base_url=args.ollama_url, model=args.model,
                            timeout=args.timeout, num_ctx=args.num_ctx)

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
