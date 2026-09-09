#!/usr/bin/env python3
"""
EKSPLORASI 2 -- ollama_block_pipeline.py
Dipanggil dari Laravel (PaperScanEksplorasi2Controller) lewat shell-out.
Envelope SAMA dgn paper_reader_extract.py: SATU baris JSON terakhir ke
stdout, semua log ke stderr.

TERPISAH TOTAL dari paper_reader_extract.py & grid_resolution_test.py --
ide "Ollama baca 6 kotak sekaligus per blok jam", bukan Mode E (1
kotak/panggilan) atau Mode A-D (48 kotak/panggilan).
"""

import argparse
import base64
import builtins
import json
import sys
import time
from pathlib import Path

import cv2
import numpy as np
import requests

sys.path.insert(0, str(Path(__file__).parent))

_stdout_print = builtins.print


def print(*args, **kwargs):  # noqa: A001
    kwargs.setdefault("file", sys.stderr)
    kwargs.setdefault("flush", True)
    _stdout_print(*args, **kwargs)


from crop_blocks import crop_jam_blocks  # noqa: E402
from prompt import BLOCK_PROMPT  # noqa: E402


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

    n_kotak = len(parsed.get("kotak", [])) if isinstance(parsed.get("kotak"), list) else None
    return {
        "parsed": parsed, "raw_text": raw_text, "elapsed_sec": elapsed,
        "validation": {"n_kotak_returned": n_kotak, "n_kotak_ok": n_kotak == 6},
    }


ROW_BLOCK_LABELS = {
    "1": ["07.00 - 08.00", "08.00 - 09.00", "09.00 - 10.00", "10.00 - 11.00",
          "11.00 - 12.00", "12.00 - 13.00", "13.00 - 14.00", "14.00 - 15.00"],
    "2": ["15.00 - 16.00", "16.00 - 17.00", "17.00 - 18.00", "18.00 - 19.00",
          "19.00 - 20.00", "20.00 - 21.00", "21.00 - 22.00", "22.00 - 23.00"],
    "3": ["23.00 - 24.00", "24.00 - 01.00", "01.00 - 02.00", "02.00 - 03.00",
          "03.00 - 04.00", "04.00 - 05.00", "05.00 - 06.00", "06.00 - 07.00"],
}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--image", required=True)
    ap.add_argument("--shift", required=True, choices=["1", "2", "3"])
    ap.add_argument("--ref-dir", required=True,
                     help="Folder hasil calibrate_reference_cli.py (isi ref_template.npz + ref_anchors.json)")
    ap.add_argument("--model", default="qwen2.5vl:7b")
    ap.add_argument("--ollama-url", default="http://127.0.0.1:11434")
    ap.add_argument("--timeout", type=int, default=120)
    ap.add_argument("--num-ctx", type=int, default=8192)
    args = ap.parse_args()

    t0 = time.time()
    try:
        ref_dir = Path(args.ref_dir)
        data = np.load(ref_dir / "ref_template.npz")
        kp_pts, des = data["kp_pts"], data["des"]
        ref_h, ref_w = int(data["img_h"]), int(data["img_w"])
        anchors = json.loads((ref_dir / "ref_anchors.json").read_text())

        det, crops = crop_jam_blocks(args.image, args.shift, kp_pts, des, ref_w, ref_h, anchors)

        if crops is None:
            envelope = {
                "status": det["status"],
                "reason": det.get("reason"),
                "meta": {"elapsed_seconds": round(time.time() - t0, 1)},
            }
            _stdout_print(json.dumps(envelope, ensure_ascii=False))
            return 0

        print(f"Geometri OK (confidence={det.get('confidence')}), memanggil Ollama utk 8 blok...")
        blocks_result = []
        labels = ROW_BLOCK_LABELS[args.shift]
        for i, crop in enumerate(crops):
            print(f"  blok {i} ({labels[i]}) -- memanggil Ollama...")
            res = call_ollama(args.ollama_url, args.model, args.timeout, args.num_ctx, crop, BLOCK_PROMPT)
            res["expected_jam_label"] = labels[i]
            res["block_idx"] = i
            blocks_result.append(res)
            print(f"    -> {res.get('parsed', res.get('error'))}")

        n_ok = sum(1 for r in blocks_result if r.get("validation", {}).get("n_kotak_ok"))
        envelope = {
            "status": "success",
            "data": {"shift": args.shift, "blocks": blocks_result},
            "meta": {
                "detection_confidence": det.get("confidence"),
                "n_blocks_ok": n_ok,
                "n_blocks_total": len(blocks_result),
                "elapsed_seconds": round(time.time() - t0, 1),
            },
        }
    except Exception as e:
        import traceback
        print("ERROR:", traceback.format_exc())
        envelope = {"status": "error", "error": str(e), "error_type": type(e).__name__}

    _stdout_print(json.dumps(envelope, ensure_ascii=False))
    return 0 if envelope["status"] != "error" else 1


if __name__ == "__main__":
    sys.exit(main())