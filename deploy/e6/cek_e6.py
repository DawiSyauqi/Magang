#!/usr/bin/env python3
"""
Cek lingkungan AI Baca Kertas (Eksplorasi 6) di komputer ini.

1. Versi paket penting (transformers HARUS 4.x).
2. GPU: apakah torch melihat CUDA (RTX 3060).
3. Model TrOCR bisa dimuat dari folder lokal (tanpa internet).
4. Uji baca 1 foto contoh dari fullimg/ dan bandingkan kotak Lost time
   dengan hasil tersimpan scripts/eksplorasi6/output_e6/mentah/ (harus identik).

Pemakaian (dari folder proyek Laravel):
    scripts\\eksplorasi6\\.venv\\Scripts\\python.exe deploy\\e6\\cek_e6.py [--device cuda] [--model <folder>]
Kode keluar 0 = semua lolos.
"""

import argparse
import json
import os
import sys
import time
from pathlib import Path

PROYEK = Path(__file__).resolve().parents[2]
E6 = PROYEK / "scripts" / "eksplorasi6"
MODEL_LOKAL = E6 / "model" / "trocr-small-handwritten"
FOTO_UJI = "IMG_20261011_143018.jpg.jpeg"

os.environ.setdefault("PYTHONUTF8", "1")
sys.path.insert(0, str(E6))

hasil = []


def cek(nama, lolos, info=""):
    hasil.append(lolos)
    print(f"  [{'OK ' if lolos else 'GAGAL'}] {nama}{(' — ' + info) if info else ''}")
    return lolos


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--device", default="auto", help="auto | cpu | cuda")
    ap.add_argument("--model", default=str(MODEL_LOKAL) if MODEL_LOKAL.is_dir() else "microsoft/trocr-small-handwritten")
    ap.add_argument("--tanpa-uji-foto", action="store_true")
    args = ap.parse_args()

    print("1. Paket Python")
    print(f"  Python {sys.version.split()[0]} ({sys.executable})")
    try:
        import cv2
        import numpy
        import torch
        import transformers
        import sentencepiece  # noqa: F401
        import pytesseract  # noqa: F401
        import regex  # noqa: F401
    except ImportError as e:
        cek("semua paket terpasang", False, str(e))
        return 1
    cek("transformers 4.x", transformers.__version__.startswith("4."), transformers.__version__)
    print(f"  torch {torch.__version__}, opencv {cv2.__version__}, numpy {numpy.__version__}")
    cek("opencv punya SIFT", hasattr(cv2, "SIFT_create"))

    print("2. GPU")
    ada_cuda = torch.cuda.is_available()
    if ada_cuda:
        print(f"  CUDA {torch.version.cuda}, GPU: {torch.cuda.get_device_name(0)}")
    else:
        print(f"  CUDA tidak terdeteksi (torch build: {torch.version.cuda or 'CPU'})")
    device = args.device if args.device != "auto" else ("cuda" if ada_cuda else "cpu")
    if args.device == "cuda":
        cek("GPU dipakai", ada_cuda, "pasang driver NVIDIA terbaru / torch versi CUDA" if not ada_cuda else "")
        if not ada_cuda:
            return 1

    print("3. Model TrOCR")
    print(f"  sumber: {args.model}")
    if Path(args.model).is_dir():
        os.environ["HF_HUB_OFFLINE"] = "1"  # buktikan model benar-benar lokal
        os.environ["TRANSFORMERS_OFFLINE"] = "1"
    from baca_fullpage_eksplorasi import TrOCRReader, baca_foto, load_referensi  # noqa: E402
    try:
        reader = TrOCRReader(args.model, device=device)
    except Exception as e:  # noqa: BLE001
        cek("model dimuat", False, f"{type(e).__name__}: {e}")
        return 1
    cek("model dimuat", True, f"{device}, {reader.load_seconds:.1f} dtk")

    if args.tanpa_uji_foto:
        return 0 if all(hasil) else 1

    print("4. Uji baca foto contoh")
    foto = PROYEK / "fullimg" / FOTO_UJI
    acuan = E6 / "output_e6" / "mentah" / (FOTO_UJI.split(".")[0] + ".json")
    if not foto.is_file() or not acuan.is_file():
        print(f"  dilewati: {foto.name} atau hasil acuannya tidak ada")
        return 0 if all(hasil) else 1
    ref = load_referensi(str(E6 / "reference"))
    t0 = time.time()
    env = baca_foto(str(foto), ref, lambda: reader, trocr_model=args.model)
    detik = time.time() - t0
    if not cek("foto terbaca", env["status"] != "needs_retake", env.get("reason") or env["status"]):
        return 1
    print(f"  waktu baca: {detik:.1f} dtk (tanpa memuat model)")

    def kotak(e):
        return {s: [b["kotak"] for b in v.get("blocks", [])] for s, v in e["data"]["lost_time"].items()}

    lama = json.loads(acuan.read_text(encoding="utf-8"))
    a, b = kotak(env), kotak(lama)
    sel = [(s, i, j) for s in b for i, blk in enumerate(b[s]) for j, _ in enumerate(blk)]
    sama = sum(1 for s, i, j in sel if a.get(s) and a[s][i][j] == b[s][i][j])
    cek("kotak Lost time sama dengan hasil uji", sama == len(sel), f"{sama}/{len(sel)} kotak")
    hdr = lambda e: {k: v.get("nilai") for k, v in e["data"]["header"].items()}  # noqa: E731
    cek("header sama dengan hasil uji", hdr(env) == hdr(lama))
    if sama != len(sel) and device == "cuda":
        print("  catatan: di GPU selisih 1-2 kotak ragu masih wajar (pembulatan float berbeda dari CPU).")

    print()
    print("SEMUA LOLOS" if all(hasil) else "ADA YANG GAGAL — lihat di atas")
    return 0 if all(hasil) else 1


if __name__ == "__main__":
    sys.exit(main())
