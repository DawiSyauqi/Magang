#!/usr/bin/env python3
"""
EKSPLORASI 1 -- baca_kotak_eksplorasi.py
Melengkapi eksplorasi 1 (yg awalnya murni geometri) sampai menghasilkan
NILAI tiap blok, dgn cara baca PER KOTAK (gaya Mode E produksi):

  1. geometri  : detect_grid_fast() -- homography ORB (rotasi foto ditebak
                 dari homography, tanpa OSD/OCR) + refine ke garis cetak;
  2. tinta     : 48 kotak diukur proporsi tintanya -> kotak kosong = null
                 tanpa memanggil model;
  3. baca      : tiap kotak BERISI di-crop sendiri (perspective warp dari 4
                 sudut kotak, jadi lurus), dikirim ke Ollama 1 kotak/panggilan;
  4. susun     : 48 hasil disusun jadi 8 blok x 6 kotak.

Bandingkan dgn eksplorasi 2 yg membaca 6 kotak sekaligus per blok.

Envelope stdout SAMA gaya skrip lain (SATU baris JSON terakhir), log ke
stderr:
    python baca_kotak_eksplorasi.py --image foto.jpg --shift 1 --ref-dir reference
"""

import argparse
import base64
import builtins
import json
import re
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


from refine_grid_eksplorasi import (  # noqa: E402
    detect_grid_fast, INK_EMPTY_THRESHOLD, N_BLOCKS, N_SUBCELLS,
)

CELL_OUT_PX = 224        # ukuran crop 1 kotak yg dikirim ke model
CELL_MARGIN_FRAC = 0.12  # crop sedikit melebar keluar kotak (tulisan kadang menyentuh garis)
PLACEHOLDER = "<isi>"

ROW_BLOCK_LABELS = {
    "1": ["07.00 - 08.00", "08.00 - 09.00", "09.00 - 10.00", "10.00 - 11.00",
          "11.00 - 12.00", "12.00 - 13.00", "13.00 - 14.00", "14.00 - 15.00"],
    "2": ["15.00 - 16.00", "16.00 - 17.00", "17.00 - 18.00", "18.00 - 19.00",
          "19.00 - 20.00", "20.00 - 21.00", "21.00 - 22.00", "22.00 - 23.00"],
    "3": ["23.00 - 24.00", "24.00 - 01.00", "01.00 - 02.00", "02.00 - 03.00",
          "03.00 - 04.00", "04.00 - 05.00", "05.00 - 06.00", "06.00 - 07.00"],
}

# Tanpa contoh nilai kode (model kecil terbukti menyalin contoh di prompt).
CELL_PROMPT_EKSPLORASI = (
    "Gambar ini adalah SATU kotak kecil (10 menit) dari baris \"Lost time\" "
    "formulir produksi, sudah dipotong dan diluruskan. Garis gelap di tepi "
    "gambar adalah garis kotak, BUKAN tulisan.\n\n"
    "Kotak ini berisi tulisan tangan operator, salah satu bentuk:\n"
    "  - kode: 1 angka (0-8) diikuti 1 huruf;\n"
    "  - angka saja (mis. \"0\");\n"
    "  - huruf \"x\" saja = penanda akhir rentang masalah panjang.\n\n"
    "ATURAN:\n"
    "1. Tulis isinya PERSIS seperti tertulis (huruf besar/kecil apa adanya).\n"
    "2. Huruf kecil \"l\" berbeda dari angka \"1\"; perhatikan bentuknya.\n"
    "3. Kalau benar-benar tidak terbaca, tulis \"?\" -- jangan menebak kode lain.\n"
    f"4. Kembalikan HANYA JSON valid tanpa teks lain: {{\"kode\": \"{PLACEHOLDER}\"}}\n"
)


def crop_cell_rectified(image, top_px, bot_px, i, out_px=CELL_OUT_PX, margin=CELL_MARGIN_FRAC):
    """Luruskan kotak ke-i (0..47) + margin tipis di sekelilingnya."""
    tl, tr = np.float32(top_px[i]), np.float32(top_px[i + 1])
    bl, br = np.float32(bot_px[i]), np.float32(bot_px[i + 1])
    m = out_px * margin
    inner = out_px - 2 * m
    src = np.float32([tl, tr, br, bl])
    dst = np.float32([[m, m], [m + inner, m], [m + inner, m + inner], [m, m + inner]])
    H = cv2.getPerspectiveTransform(src, dst)
    return cv2.warpPerspective(image, H, (out_px, out_px), flags=cv2.INTER_CUBIC,
                               borderMode=cv2.BORDER_REPLICATE)


def encode_b64_array(image):
    ok, buf = cv2.imencode(".jpg", image, [cv2.IMWRITE_JPEG_QUALITY, 95])
    if not ok:
        raise RuntimeError("Gagal encode JPEG")
    return base64.standard_b64encode(buf).decode("utf-8")


def parse_model_json(raw_text):
    text = raw_text.strip()
    if "```" in text:
        for part in text.split("```")[1:]:
            cleaned = part.strip()
            if cleaned.startswith("json"):
                cleaned = cleaned[4:].strip()
            try:
                return json.loads(cleaned)
            except json.JSONDecodeError:
                pass
    m = re.search(r"\{[\s\S]*\}", text)
    if m:
        try:
            return json.loads(m.group(0))
        except json.JSONDecodeError:
            pass
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        return None


def call_ollama_cell(base_url, model, timeout, num_ctx, crop):
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": CELL_PROMPT_EKSPLORASI},
            {"role": "user", "content": "Baca isi kotak ini.", "images": [encode_b64_array(crop)]},
        ],
        "stream": False,
        "options": {"temperature": 0.1, "num_ctx": num_ctx},
    }
    t0 = time.time()
    try:
        resp = requests.post(f"{base_url}/api/chat", json=payload, timeout=timeout)
    except requests.exceptions.RequestException as e:
        return {"kode": "?", "error": str(e), "elapsed_sec": time.time() - t0}
    elapsed = time.time() - t0
    if resp.status_code != 200:
        return {"kode": "?", "error": f"HTTP {resp.status_code}", "elapsed_sec": elapsed}
    raw = resp.json().get("message", {}).get("content", "")
    parsed = parse_model_json(raw)
    kode = parsed.get("kode") if isinstance(parsed, dict) else None
    if isinstance(kode, (int, float)):
        kode = str(kode)
    if not isinstance(kode, str) or not kode.strip() or kode.strip() == PLACEHOLDER:
        kode = "?"  # kotak ADA tintanya, jadi null/kosong dari model = tdk terbaca
    return {"kode": kode.strip(), "raw_text": raw, "elapsed_sec": elapsed}


def read_cells(det, base_url, model, timeout=120, num_ctx=4096, save_crops_dir=None, stem="foto"):
    """Baca 48 kotak per-kotak. Return (blocks, stats). blocks = 8 dict dgn
    'kotak' (6 nilai), 'cell_ink', 'calls'."""
    img = det["corrected_image"]
    top_px, bot_px = det["top_pts_px"], det["bot_pts_px"]
    ink = det["cell_ink"]
    blocks = []
    n_calls, t_model = 0, 0.0
    for b in range(N_BLOCKS):
        kotak, calls = [], []
        for c in range(N_SUBCELLS):
            i = b * N_SUBCELLS + c
            if ink[i] < INK_EMPTY_THRESHOLD:
                kotak.append(None)
                continue
            crop = crop_cell_rectified(img, top_px, bot_px, i)
            if save_crops_dir is not None:
                cv2.imwrite(str(Path(save_crops_dir) / f"{stem}_b{b}_k{c + 1}.jpg"), crop)
            r = call_ollama_cell(base_url, model, timeout, num_ctx, crop)
            n_calls += 1
            t_model += r["elapsed_sec"]
            kotak.append(r["kode"])
            calls.append({"kolom": c + 1, **{k: v for k, v in r.items() if k != "kode"}})
        blocks.append({"block_idx": b, "kotak": kotak,
                       "cell_ink": ink[b * N_SUBCELLS:(b + 1) * N_SUBCELLS], "calls": calls})
    return blocks, {"n_ollama_calls": n_calls, "ollama_seconds": t_model}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--image", required=True)
    ap.add_argument("--shift", required=True, choices=["1", "2", "3"])
    ap.add_argument("--ref-dir", required=True)
    ap.add_argument("--model", default="qwen2.5vl:3b")
    ap.add_argument("--ollama-url", default="http://127.0.0.1:11434")
    ap.add_argument("--timeout", type=int, default=120)
    ap.add_argument("--num-ctx", type=int, default=4096)
    args = ap.parse_args()

    t0 = time.time()
    try:
        ref_dir = Path(args.ref_dir)
        data = np.load(ref_dir / "ref_template.npz")
        anchors = json.loads((ref_dir / "ref_anchors.json").read_text())
        det = detect_grid_fast(args.image, args.shift, data["kp_pts"], data["des"],
                               int(data["img_w"]), int(data["img_h"]), anchors)
        t_geo = time.time() - t0
        if not det.get("refined"):
            envelope = {"status": det["status"], "reason": det.get("reason"),
                        "meta": {"elapsed_seconds": round(time.time() - t0, 1)}}
            _stdout_print(json.dumps(envelope, ensure_ascii=False))
            return 0
        blocks, stats = read_cells(det, args.ollama_url, args.model, args.timeout, args.num_ctx)
        labels = ROW_BLOCK_LABELS[args.shift]
        for blk in blocks:
            blk["expected_jam_label"] = labels[blk["block_idx"]]
            print(f"  blok {blk['block_idx']} ({blk['expected_jam_label']}) -> {blk['kotak']}")
        envelope = {
            "status": det["status"],
            "data": {"shift": args.shift, "blocks": blocks},
            "meta": {
                "geometry_path": det.get("path"),
                "refine_quality": det["refine"]["quality"],
                "geometry_seconds": round(t_geo, 2),
                **stats,
                "elapsed_seconds": round(time.time() - t0, 1),
            },
        }
    except Exception as e:
        import traceback
        print("ERROR:", traceback.format_exc())
        envelope = {"status": "error", "error": str(e), "error_type": type(e).__name__}

    _stdout_print(json.dumps(envelope, ensure_ascii=False, default=lambda o: float(o)
                             if isinstance(o, np.floating) else str(o)))
    return 0 if envelope["status"] != "error" else 1


if __name__ == "__main__":
    sys.exit(main())
