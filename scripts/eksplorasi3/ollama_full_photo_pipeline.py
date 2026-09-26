#!/usr/bin/env python3
"""
EKSPLORASI 3 -- ollama_full_photo_pipeline.py
Dipanggil dari Laravel (PaperScanEksplorasi3Controller). Envelope sama dgn
skrip lain: SATU baris JSON terakhir ke stdout, log ke stderr.

BEDA MENDASAR dari eksplorasi 1 & 2: TIDAK ADA crop geometri sama sekali.
Tiap panggilan Ollama menerima FOTO UTUH yang SAMA (sudah di-enhance), model
sendiri yang harus menemukan baris shift & blok jam yang dimaksud.
"""

import argparse
import base64
import builtins
import json
import sys
import time
from pathlib import Path

import cv2
import requests

sys.path.insert(0, str(Path(__file__).parent))

_stdout_print = builtins.print


def print(*args, **kwargs):  # noqa: A001
    kwargs.setdefault("file", sys.stderr)
    kwargs.setdefault("flush", True)
    _stdout_print(*args, **kwargs)


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
    ap.add_argument("--model", default="qwen2.5vl:3b")
    ap.add_argument("--ollama-url", default="http://127.0.0.1:11434")
    ap.add_argument("--timeout", type=int, default=120)
    ap.add_argument("--num-ctx", type=int, default=8192)
    args = ap.parse_args()

    t0 = time.time()
    try:
        image, osd_ok, rot = preprocess_closeup(args.image)
        if not osd_ok:
            envelope = {"status": "needs_retake", "reason": "osd_failed",
                        "meta": {"elapsed_seconds": round(time.time() - t0, 1)}}
            _stdout_print(json.dumps(envelope, ensure_ascii=False))
            return 0

        print(f"Preprocessing OK (rotate_applied={rot}). Encode gambar sekali, reuse tiap panggilan...")
        image_b64 = encode_b64_array(image)  # encode SEKALI, reuse 8x (hemat, gambar sama persis)

        labels = ROW_BLOCK_LABELS[args.shift]
        blocks_result = []
        for i, jam_label in enumerate(labels):
            print(f"  blok {i} ({jam_label}) -- memanggil Ollama (foto utuh, cari sendiri)...")
            prompt = build_block_prompt(args.shift, jam_label, BLOCK_POSITION_DESC[i])
            res = call_ollama(args.ollama_url, args.model, args.timeout, args.num_ctx, image_b64, prompt)
            res["expected_jam_label"] = jam_label
            res["block_idx"] = i
            blocks_result.append(res)
            print(f"    -> {res.get('parsed', res.get('error'))}")

        n_ok = sum(1 for r in blocks_result if r.get("validation", {}).get("n_kotak_ok"))
        n_label_match = sum(
            1 for r in blocks_result
            if r.get("parsed", {}).get("jam_label_ditemukan", "").strip() == r["expected_jam_label"]
        )
        envelope = {
            "status": "success",
            "data": {"shift": args.shift, "blocks": blocks_result},
            "meta": {
                "n_blocks_ok": n_ok,
                "n_blocks_total": len(blocks_result),
                "n_label_match": n_label_match,
                "elapsed_seconds": round(time.time() - t0, 1),
            },
        }
    except Exception as e:
        import traceback
        print("ERROR:", traceback.format_exc())
        envelope = {"status": "error", "error": str(e), "error_type": type(e).__name__}

    def _json_default(o):
        if isinstance(o, (np.floating, float)):
            return float(o)
        if isinstance(o, (np.integer, int)):
            return int(o)
        return str(o)

    _stdout_print(json.dumps(envelope, ensure_ascii=False, default=_json_default))
    return 0 if envelope["status"] != "error" else 1


if __name__ == "__main__":
    sys.exit(main())