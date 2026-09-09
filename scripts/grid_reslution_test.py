#!/usr/bin/env python3
"""
scripts/grid_resolution_test.py — HANYA untuk uji empiris hipotesis
downsampling internal model vision (Fase O-lanjutan). TIDAK dipanggil
dari alur produksi. Aman dihapus kapan saja setelah keputusan diambil.

Skenario yang diuji dalam SATU run (1 foto full-page):
  A. "full_page_direct"   -- kirim foto FULL PAGE utuh (setelah
                              auto_crop_document saja) ke Ollama, minta
                              baca SATU baris grid lost-time tertentu.
                              Ini mereplikasi kegagalan lama.
  B. "auto_crop_grid"     -- crop area grid pakai MODE_B_GRID_BOUNDS +
                              deteksi baris (proven reliable), upscale,
                              BARU dikirim ke Ollama. Tanpa foto kedua,
                              tanpa 3-titik manual -- murni software crop
                              dari foto yang SAMA.

Output: SATU JSON ke stdout (raw_response A & B + metadata crop),
log diagnostik ke stderr. Silakan bandingkan manual dulu -- script ini
TIDAK menilai/skor mana yang benar (butuh ground truth manual dari user).
"""

import argparse
import base64
import json
import sys
import time
from pathlib import Path

import cv2
import numpy as np
import requests

# --- reuse fungsi yang SUDAH terbukti andal dari script produksi,
#     JANGAN duplikasi logika auto-crop / deteksi baris di sini ---
sys.path.insert(0, str(Path(__file__).parent))
from paper_reader_extract import (
    imread_exif_safe,
    auto_crop_document,
    detect_row_bounds_hough,
    MODE_B_GRID_BOUNDS,
)


def log(*args):
    print(*args, file=sys.stderr, flush=True)


def encode_b64(image) -> str:
    ok, buf = cv2.imencode(".jpg", image, [cv2.IMWRITE_JPEG_QUALITY, 95])
    if not ok:
        raise RuntimeError("Gagal encode JPEG")
    return base64.standard_b64encode(buf).decode("utf-8")


def upscale_to_min_height(image, min_height_px=400):
    h, w = image.shape[:2]
    if h >= min_height_px:
        return image
    scale = min_height_px / h
    return cv2.resize(image, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_CUBIC)


ONE_ROW_PROMPT = (
    "Kamu melihat gambar form kertas industri berisi tabel jadwal produksi "
    "per jam, dengan baris berlabel 'Lost time' berisi kotak-kotak kecil "
    "(1 kotak = 10 menit). Cari BARIS PERTAMA berlabel 'Lost time' "
    "(yang berada tepat di bawah baris 'Jam' yang dimulai dari '07.00 - 08.00'), "
    "lalu baca SEMUA kode tulisan tangan di kotak-kotak baris itu, dari kiri ke kanan.\n\n"
    "Kembalikan HANYA JSON (tanpa teks lain):\n"
    '{"kotak": [string_atau_null, ...]}\n\n'
    "- Array HARUS berisi PERSIS 48 elemen (8 jam x 6 kotak per jam).\n"
    "- Tiap elemen: kode (1 digit angka, atau 1 digit angka + 1 huruf kecil, "
    "atau huruf 'x' sendirian) kalau kotak terisi, atau null kalau kotak kosong.\n"
    "- JANGAN mengarang. Ragu -> null.\n"
    "- Urutan HARUS sesuai posisi kiri-ke-kanan di kertas, JANGAN diacak.\n"
)


def call_ollama(base_url, model, timeout, num_ctx, image, prompt):
    image_b64 = encode_b64(image)
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": prompt},
            {"role": "user", "content": "Ekstrak sesuai skema.", "images": [image_b64]},
        ],
        "stream": False,
        "options": {"temperature": 0.1, "num_ctx": num_ctx},
    }
    t0 = time.time()
    resp = requests.post(f"{base_url}/api/chat", json=payload, timeout=timeout)
    elapsed = time.time() - t0
    if resp.status_code != 200:
        return {"error": f"HTTP {resp.status_code}: {resp.text[:300]}", "elapsed_sec": elapsed}
    raw_text = resp.json()["message"]["content"]
    text = raw_text.strip()
    if text.startswith("```"):
        text = text.split("```")[1]
        if text.startswith("json"):
            text = text[4:]
        text = text.strip()
    try:
        parsed = json.loads(text)
    except json.JSONDecodeError:
        return {"error": "JSON tidak valid", "raw_text": raw_text, "elapsed_sec": elapsed}
    return {"parsed": parsed, "raw_text": raw_text, "elapsed_sec": elapsed}


sys.path.insert(0, str(Path(__file__).parent))
from paper_reader_extract import (
    imread_exif_safe,
    auto_crop_document,
    detect_row_bounds_hough,
    BLOCK_X_BOUNDS_FALLBACK,
)


def crop_grid_row_auto(full_page_image):
    """Crop HANYA baris 'Lost time' pertama (07.00-15.00). Beroperasi
    LANGSUNG pada full-page (hasil auto_crop_document()) -- TIDAK slice
    manual dulu, karena detect_row_bounds_hough() sudah punya default
    search-range yang mengarah ke area grid. lost_time_bounds sudah
    dipisah dari row_bounds oleh fungsi ini sendiri -- tidak perlu tebak
    index baris mana yang 'Jam' vs 'Lost time'."""
    h, w = full_page_image.shape[:2]

    result = detect_row_bounds_hough(full_page_image)
    if result is None:
        raise RuntimeError(
            "detect_row_bounds_hough gagal total pada foto ini -- "
            "tidak bisa uji skenario B (auto_crop_grid) utk foto ini."
        )
    row_bounds, lost_time_bounds = result
    y0f, y1f = lost_time_bounds["jam_07_15"]
    y0, y1 = int(y0f * h), int(y1f * h)

    x0f, x1f = BLOCK_X_BOUNDS_FALLBACK[0], BLOCK_X_BOUNDS_FALLBACK[-1]
    x0, x1 = int(x0f * w), int(x1f * w)

    row_crop = full_page_image[y0:y1, x0:x1]
    row_crop = upscale_to_min_height(row_crop, min_height_px=400)
    return row_crop, "detect_row_bounds_hough"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--image", required=True)
    ap.add_argument("--model", default="qwen2.5vl:7b")
    ap.add_argument("--ollama-url", default="http://127.0.0.1:11434")
    ap.add_argument("--timeout", type=int, default=600)
    ap.add_argument("--num-ctx", type=int, default=16384)
    args = ap.parse_args()

    try:
        raw_image = imread_exif_safe(args.image)
        cropped, crop_ok, crop_method = auto_crop_document(raw_image)
        log(f"[main] auto_crop_document -> ok={crop_ok}, method={crop_method}")

        log("[main] Panggil Ollama -- skenario A (full page utuh)...")
        res_a = call_ollama(args.ollama_url, args.model, args.timeout, args.num_ctx,
                             cropped, ONE_ROW_PROMPT)

        grid_row_crop, row_method = crop_grid_row_auto(cropped)
        log("[main] Panggil Ollama -- skenario B (crop-grid-otomatis)...")
        res_b = call_ollama(args.ollama_url, args.model, args.timeout, args.num_ctx,
                             grid_row_crop, ONE_ROW_PROMPT)

        envelope = {
            "status": "success",
            "data": {
                "scenarios": {
                    "A_full_page_direct": res_a,
                    "B_auto_crop_grid": res_b,
                    "B_meta": {
                        "row_detect_method": row_method,
                        "crop_shape": list(grid_row_crop.shape),
                    },
                    "crop_meta": {"auto_crop_ok": crop_ok, "auto_crop_method": crop_method},
                },
            },
        }
    except Exception as e:
        envelope = {"status": "error", "error": str(e), "error_type": type(e).__name__}

    print(json.dumps(envelope, ensure_ascii=False))  # stdout: SATU baris JSON terakhir


if __name__ == "__main__":
    main()