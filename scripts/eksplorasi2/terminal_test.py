#!/usr/bin/env python3
"""
EKSPLORASI 2 -- terminal_test.py
Testing MURNI dari terminal. 8 panggilan Ollama (crop kecil per blok),
hasil dicetak ke terminal + crop disimpan ke disk utk verifikasi visual
manual + evaluasi interaktif per blok.

Cara pakai:
    python terminal_test.py --image path/foto.jpg --shift 1 \
        --ref-dir reference --model qwen2.5vl:3b --ollama-url http://127.0.0.1:11434
"""

import argparse
import base64
import csv
import json
import sys
import time
from datetime import datetime
from pathlib import Path

import cv2
import numpy as np
import requests

sys.path.insert(0, str(Path(__file__).parent))
from crop_blocks import crop_jam_blocks  # noqa: E402
from prompt import BLOCK_PROMPT  # noqa: E402
from grid_overlay import draw_grid_overlay  # noqa: E402  <-- BARU

def dict_to_kotak_list(parsed):
    """Konversi skema object {'kolom_1':..,'kolom_6':..} balik ke list 6
    elemen berurutan -- dilakukan di Python (deterministik), BUKAN
    dipercayakan ke urutan yang ditulis model."""
    return [parsed.get(f"kolom_{i}") for i in range(1, 7)]

def encode_b64_array(image):
    ok, buf = cv2.imencode(".jpg", image, [cv2.IMWRITE_JPEG_QUALITY, 95])
    if not ok:
        raise RuntimeError("Gagal encode JPEG")
    return base64.standard_b64encode(buf).decode("utf-8")


def call_ollama(base_url, model, timeout, num_ctx, image, prompt):
    image_b64 = encode_b64_array(image)
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": prompt},
            {"role": "user", "content": "Baca 6 kotak sesuai instruksi.", "images": [image_b64]},
        ],
        "stream": False,
        "options": {"temperature": 0.1, "num_ctx": num_ctx},
    }
    t0 = time.time()
    try:
        resp = requests.post(f"{base_url}/api/chat", json=payload, timeout=timeout)
    except requests.exceptions.RequestException as e:
        return {"error": str(e), "elapsed_sec": time.time() - t0}
    elapsed = time.time() - t0
    if resp.status_code != 200:
        return {"error": f"HTTP {resp.status_code}: {resp.text[:300]}", "elapsed_sec": elapsed}

    raw_text = resp.json().get("message", {}).get("content", "")
    text = raw_text.strip()
    parsed = None
    if "```" in text:
        parts = text.split("```")
        for part in parts[1:]:
            cleaned = part.strip()
            if cleaned.startswith("json"):
                cleaned = cleaned[4:].strip()
            try:
                parsed = json.loads(cleaned)
                break
            except json.JSONDecodeError:
                pass
    if parsed is None:
        import re
        m = re.search(r"\{[\s\S]*\}", text)
        if m:
            try:
                parsed = json.loads(m.group(0))
            except json.JSONDecodeError:
                pass
    if parsed is None:
        try:
            parsed = json.loads(text)
        except json.JSONDecodeError:
            return {"error": "JSON tidak valid", "raw_text": raw_text, "elapsed_sec": elapsed}

    kolom_keys = [f"kolom_{i}" for i in range(1, 7)]
    n_kolom_present = sum(1 for k in kolom_keys if k in parsed)
    kotak = dict_to_kotak_list(parsed)
    n_x = sum(1 for v in kotak if v == "x")
    return {
        "parsed": parsed, "raw_text": raw_text, "elapsed_sec": elapsed,
        "kotak": kotak,  # list hasil konversi, dipakai tampilan & log
        "validation": {
            "n_kolom_present": n_kolom_present,
            "n_kolom_ok": n_kolom_present == 6,
            "n_x_in_block": n_x,
            "x_count_suspicious": n_x > 1,  # jaring pengaman: >1 "x" per blok = sangat tidak wajar
        },
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--image", required=True)
    ap.add_argument("--shift", required=True, choices=["1", "2", "3"])
    ap.add_argument("--ref-dir", default="reference")
    ap.add_argument("--output-dir", default="output_eksplorasi2")
    ap.add_argument("--model", default="qwen2.5vl:3b")
    ap.add_argument("--ollama-url", default="http://127.0.0.1:11434")
    ap.add_argument("--timeout", type=int, default=120)
    ap.add_argument("--num-ctx", type=int, default=8192)
    ap.add_argument("--no-interactive", action="store_true")
    args = ap.parse_args()

    out_dir = Path(args.output_dir)
    out_dir.mkdir(exist_ok=True)

    ref_dir = Path(args.ref_dir)
    if not (ref_dir / "ref_template.npz").exists():
        print(f"ERROR: {ref_dir / 'ref_template.npz'} tidak ditemukan. Jalankan calibrate_reference_cli.py dulu.")
        sys.exit(1)

    data = np.load(ref_dir / "ref_template.npz")
    kp_pts, des = data["kp_pts"], data["des"]
    ref_h, ref_w = int(data["img_h"]), int(data["img_w"])
    anchors = json.loads((ref_dir / "ref_anchors.json").read_text())

    print(f"\n{'='*60}")
    print(f"EKSPLORASI 2 -- {Path(args.image).name} (shift {args.shift}, model={args.model})")
    print(f"{'='*60}")

    t0 = time.time()
    det, crops = crop_jam_blocks(args.image, args.shift, kp_pts, des, ref_w, ref_h, anchors)

    if crops is None:
        print(f"Status geometri gagal: {det['status']} ({det.get('reason')})")
        return

    print(f"Geometri: status={det['status']}, confidence={det.get('confidence')}")
    print(f"Memanggil Ollama utk 8 blok...\n")

    img_h, img_w = det["image_shape"]
    crops = [
        draw_grid_overlay(crop, meta, img_h, img_w)
        for crop, meta in zip(crops, det["block_crops_meta"])
    ]

    stem = Path(args.image).stem
    results = []
    n_ok = 0
    n_manual_correct = 0
    n_manual_graded = 0

    for i, crop in enumerate(crops):
        crop_path = out_dir / f"{stem}_shift{args.shift}_block{i}.jpg"
        cv2.imwrite(str(crop_path), crop)

        res = call_ollama(args.ollama_url, args.model, args.timeout, args.num_ctx, crop, BLOCK_PROMPT)
        parsed = res.get("parsed", {})
        kotak = res.get("kotak", res.get("error", "?"))
        ok = res.get("validation", {}).get("n_kolom_ok", False)
        if res.get("validation", {}).get("x_count_suspicious"):
            print(f"  [!] PERINGATAN: {res['validation']['n_x_in_block']} 'x' dalam 1 blok -- sangat tidak wajar, kemungkinan halusinasi.")
        if ok:
            n_ok += 1

        print(f"--- Blok {i} ---")
        print(f"  Crop disimpan : {crop_path.resolve()}")
        print(f"  Jam terbaca   : {parsed.get('jam_label', '-')}")
        print(f"  Kotak         : {kotak}")
        print(f"  Struktur valid (persis 6): {'YA' if ok else 'TIDAK'}")
        print(f"  Waktu         : {res.get('elapsed_sec', 0):.1f}s")

        manual = None
        if not args.no_interactive:
            while True:
                ans = input(f"  Sesuai foto asli (buka crop di atas)? [y/n]: ").strip().lower()
                if ans in ("y", "n"):
                    manual = (ans == "y")
                    n_manual_graded += 1
                    if manual:
                        n_manual_correct += 1
                    break
                print("  Jawab y atau n.")
        print()

        results.append({"block_idx": i, "response": res, "manual_grade": manual})

    elapsed_total = time.time() - t0
    print(f"{'='*60}")
    print(f"RINGKASAN: {n_ok}/8 blok struktur valid | "
          f"{n_manual_correct}/{n_manual_graded if n_manual_graded else '?'} manual benar | "
          f"waktu total {elapsed_total:.1f}s")
    print(f"{'='*60}\n")

    log_result(out_dir, args, det, results, elapsed_total, n_ok, n_manual_correct, n_manual_graded)


def log_result(out_dir, args, det, results, elapsed_total, n_ok, n_manual_correct, n_manual_graded):
    log_path = out_dir / "evaluation_log.csv"
    is_new = not log_path.exists()
    row = {
        "timestamp": datetime.now().isoformat(timespec="seconds"),
        "image": Path(args.image).name,
        "shift": args.shift,
        "model": args.model,
        "geometry_confidence": det.get("confidence"),
        "n_blocks_struktur_ok": n_ok,
        "n_blocks_manual_correct": n_manual_correct,
        "n_blocks_manual_graded": n_manual_graded,
        "elapsed_total_sec": round(elapsed_total, 1),
    }
    with open(log_path, "a", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=row.keys())
        if is_new:
            writer.writeheader()
        writer.writerow(row)

    detail_path = out_dir / f"{Path(args.image).stem}_shift{args.shift}_detail.json"
    with open(detail_path, "w") as f:
        json.dump(results, f, indent=2, ensure_ascii=False, default=str)

    print(f"Log ringkasan -> {log_path.resolve()}")
    print(f"Detail lengkap -> {detail_path.resolve()}")


if __name__ == "__main__":
    main()