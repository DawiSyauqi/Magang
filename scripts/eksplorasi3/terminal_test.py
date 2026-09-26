#!/usr/bin/env python3
"""
EKSPLORASI 3 -- terminal_test.py
Testing MURNI dari terminal. TANPA geometri sama sekali -- foto utuh
(sudah di-enhance) dikirim berulang 8x ke Ollama. Cetak evaluasi ke
terminal, termasuk indikator "nyasar" (label ditemukan vs diharapkan).

Cara pakai:
    python terminal_test.py --image path/foto.jpg --shift 1 \
        --model qwen2.5vl:3b --ollama-url http://127.0.0.1:11434
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
import requests

sys.path.insert(0, str(Path(__file__).parent))
from preprocess import preprocess_closeup  # noqa: E402
from prompt_full_photo import build_block_prompt, ROW_BLOCK_LABELS, BLOCK_POSITION_DESC  # noqa: E402


def encode_b64_array(image):
    ok, buf = cv2.imencode(".jpg", image, [cv2.IMWRITE_JPEG_QUALITY, 95])
    if not ok:
        raise RuntimeError("Gagal encode JPEG")
    return base64.standard_b64encode(buf).decode("utf-8")


def call_ollama(base_url, model, timeout, num_ctx, image_b64, prompt):
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": prompt},
            {"role": "user", "content": "Cari & baca sesuai instruksi.", "images": [image_b64]},
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

    n_kotak = len(parsed.get("kotak", [])) if isinstance(parsed.get("kotak"), list) else None
    return {
        "parsed": parsed, "raw_text": raw_text, "elapsed_sec": elapsed,
        "validation": {"n_kotak_returned": n_kotak, "n_kotak_ok": n_kotak == 6},
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--image", required=True)
    ap.add_argument("--shift", required=True, choices=["1", "2", "3"])
    ap.add_argument("--output-dir", default="output_eksplorasi3")
    ap.add_argument("--model", default="qwen2.5vl:3b")
    ap.add_argument("--ollama-url", default="http://127.0.0.1:11434")
    ap.add_argument("--timeout", type=int, default=120)
    ap.add_argument("--num-ctx", type=int, default=8192)
    ap.add_argument("--no-interactive", action="store_true")
    args = ap.parse_args()

    out_dir = Path(args.output_dir)
    out_dir.mkdir(exist_ok=True)

    print(f"\n{'='*60}")
    print(f"EKSPLORASI 3 -- {Path(args.image).name} (shift {args.shift}, model={args.model})")
    print(f"{'='*60}")

    t0 = time.time()
    image, osd_ok, rot = preprocess_closeup(args.image)
    if not osd_ok:
        print("Status: needs_retake (OSD gagal)")
        return

    stem = Path(args.image).stem
    preview_path = out_dir / f"{stem}_shift{args.shift}_preprocessed.jpg"
    cv2.imwrite(str(preview_path), image)
    print(f"Preprocessing OK (rotate={rot}). Foto hasil enhance -> {preview_path.resolve()}")
    print("BUKA FILE INI dan siapkan utk bandingkan tiap blok di bawah.\n")

    image_b64 = encode_b64_array(image)
    labels = ROW_BLOCK_LABELS[args.shift]

    results = []
    n_ok = 0
    n_label_match = 0
    n_manual_correct = 0
    n_manual_graded = 0

    for i, jam_label in enumerate(labels):
        prompt = build_block_prompt(args.shift, jam_label, BLOCK_POSITION_DESC[i])
        res = call_ollama(args.ollama_url, args.model, args.timeout, args.num_ctx, image_b64, prompt)
        parsed = res.get("parsed", {})
        found_label = parsed.get("jam_label_ditemukan", "-")
        kotak = parsed.get("kotak", res.get("error", "?"))
        ok = res.get("validation", {}).get("n_kotak_ok", False)
        label_match = found_label.strip() == jam_label if isinstance(found_label, str) else False
        if ok:
            n_ok += 1
        if label_match:
            n_label_match += 1

        print(f"--- Blok {i} (diharapkan: {jam_label}) ---")
        print(f"  Label ditemukan Ollama: {found_label} {'✓' if label_match else '⚠️  TIDAK COCOK'}")
        print(f"  Kotak         : {kotak}")
        print(f"  Struktur valid (persis 6): {'YA' if ok else 'TIDAK'}")
        print(f"  Waktu         : {res.get('elapsed_sec', 0):.1f}s")

        manual = None
        if not args.no_interactive:
            while True:
                ans = input(f"  Sesuai foto asli (bandingkan ke file preprocessed di atas)? [y/n]: ").strip().lower()
                if ans in ("y", "n"):
                    manual = (ans == "y")
                    n_manual_graded += 1
                    if manual:
                        n_manual_correct += 1
                    break
                print("  Jawab y atau n.")
        print()

        results.append({"block_idx": i, "expected_label": jam_label, "response": res, "manual_grade": manual})

    elapsed_total = time.time() - t0
    print(f"{'='*60}")
    print(f"RINGKASAN: {n_ok}/8 struktur valid | {n_label_match}/8 label COCOK (indikator nyasar) | "
          f"{n_manual_correct}/{n_manual_graded if n_manual_graded else '?'} manual benar | "
          f"waktu total {elapsed_total:.1f}s")
    print(f"{'='*60}\n")

    log_result(out_dir, args, results, elapsed_total, n_ok, n_label_match, n_manual_correct, n_manual_graded)


def log_result(out_dir, args, results, elapsed_total, n_ok, n_label_match, n_manual_correct, n_manual_graded):
    log_path = out_dir / "evaluation_log.csv"
    is_new = not log_path.exists()
    row = {
        "timestamp": datetime.now().isoformat(timespec="seconds"),
        "image": Path(args.image).name,
        "shift": args.shift,
        "model": args.model,
        "n_blocks_struktur_ok": n_ok,
        "n_blocks_label_match": n_label_match,
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