#!/usr/bin/env python3
"""
EKSPLORASI 2 (refine) -- ollama_block_pipeline_refine_eksplorasi.py
Pengganti drop-in utk ollama_block_pipeline.py: argumen & envelope JSON
SAMA (SATU baris JSON terakhir ke stdout, log ke stderr), dgn perubahan:

1. Crop per blok dari grid hasil REFINE + perspective warp (lurus, garis
   bantu tepat di garis cetak) -- lihat crop_blocks_refine_eksplorasi.py.
2. INK-GATE (default aktif): tinta tiap kotak diukur deterministik.
   - blok yg ke-6 kotaknya kosong -> Ollama TIDAK dipanggil, hasil 6x null
     (hemat waktu & menutup peluang halusinasi "x" di blok kosong);
   - jawaban model utk kotak yg terukur KOSONG dipaksa null (dicatat di
     "ink_gate.overridden");
   - kotak yg ADA tintanya tp model jawab null dicatat di
     "ink_gate.ink_but_null" (TIDAK dikarang, hanya ditandai utk review).
3. INK-HINT (default aktif): pesan user ke model menyebut kolom mana yg
   berisi coretan, jadi model cukup fokus MEMBACA isinya.
4. PROMPT BARU (default, --prompt refine): prompt_refine_eksplorasi.py --
   tanpa contoh nilai kode (prompt.py lama berisi contoh "6a"/"5b" yg
   terbukti disalin model kecil), + template JSON per blok dari ink-gate.
   --prompt lama memakai BLOCK_PROMPT dari prompt.py (utk perbandingan).

Utk dipakai dari PaperScanEksplorasi2Controller cukup ganti $scriptPath ke
file ini (argumen tambahan opsional: --no-ink-gate, --no-ink-hint,
--no-overlay).
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


from crop_blocks_refine_eksplorasi import crop_jam_blocks_refined  # noqa: E402
from prompt import BLOCK_PROMPT  # noqa: E402
from prompt_refine_eksplorasi import (  # noqa: E402
    BLOCK_PROMPT_REFINE, PLACEHOLDER, build_user_message_refine,
)
from refine_grid_eksplorasi import INK_EMPTY_THRESHOLD, N_SUBCELLS  # noqa: E402

ROW_BLOCK_LABELS = {
    "1": ["07.00 - 08.00", "08.00 - 09.00", "09.00 - 10.00", "10.00 - 11.00",
          "11.00 - 12.00", "12.00 - 13.00", "13.00 - 14.00", "14.00 - 15.00"],
    "2": ["15.00 - 16.00", "16.00 - 17.00", "17.00 - 18.00", "18.00 - 19.00",
          "19.00 - 20.00", "20.00 - 21.00", "21.00 - 22.00", "22.00 - 23.00"],
    "3": ["23.00 - 24.00", "24.00 - 01.00", "01.00 - 02.00", "02.00 - 03.00",
          "03.00 - 04.00", "04.00 - 05.00", "05.00 - 06.00", "06.00 - 07.00"],
}


def encode_b64_array(image):
    ok, buf = cv2.imencode(".jpg", image, [cv2.IMWRITE_JPEG_QUALITY, 95])
    if not ok:
        raise RuntimeError("Gagal encode JPEG")
    return base64.standard_b64encode(buf).decode("utf-8")


def build_user_message(filled):
    msg = "Baca 6 kotak sesuai instruksi."
    if filled is None:
        return msg
    cols = [str(i + 1) for i, f in enumerate(filled) if f]
    empty = [str(i + 1) for i, f in enumerate(filled) if not f]
    msg += (f"\n\nINFO TAMBAHAN (hasil deteksi tinta otomatis, sangat akurat): "
            f"kolom yang BERISI coretan = {', '.join(cols)}.")
    if empty:
        msg += f" Kolom {', '.join(empty)} KOSONG -> tulis null."
    msg += " Tugasmu tinggal MEMBACA isi kolom yang berisi coretan."
    return msg


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


def call_ollama(base_url, model, timeout, num_ctx, image, prompt, user_message):
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": prompt},
            {"role": "user", "content": user_message, "images": [encode_b64_array(image)]},
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
    parsed = parse_model_json(raw_text)
    if parsed is None:
        return {"error": "JSON tidak valid", "raw_text": raw_text, "elapsed_sec": elapsed}
    return {"parsed": parsed, "raw_text": raw_text, "elapsed_sec": elapsed}


def finalize_block(res, filled, ink_gate):
    """Isi res["kotak"] + res["validation"] (+ res["ink_gate"])."""
    parsed = res.get("parsed")
    if parsed is None:
        return res
    kolom_keys = [f"kolom_{i}" for i in range(1, N_SUBCELLS + 1)]
    n_kolom_present = sum(1 for k in kolom_keys if k in parsed)
    kotak = [parsed.get(k) for k in kolom_keys]

    # model kadang mengembalikan penanda template apa adanya -> anggap tdk terbaca
    kotak = ["?" if v == PLACEHOLDER else v for v in kotak]

    if ink_gate and filled is not None:
        overridden, ink_but_null = [], []
        for i, (v, f) in enumerate(zip(kotak, filled)):
            if not f and v is not None:
                overridden.append({"kolom": i + 1, "model_value": v})
                kotak[i] = None
            elif f and v is None:
                ink_but_null.append(i + 1)
        res["ink_gate"] = {"overridden": overridden, "ink_but_null": ink_but_null}

    n_x = sum(1 for v in kotak if v == "x")
    res["kotak"] = kotak
    res["validation"] = {
        "n_kolom_present": n_kolom_present,
        "n_kolom_ok": n_kolom_present == N_SUBCELLS,
        "n_x_in_block": n_x,
        "x_count_suspicious": n_x > 1,
        "unreadable_kolom": [i + 1 for i, v in enumerate(kotak) if v == "?"],
    }
    return res


def read_block(args, crop, meta, label, ink_gate=True, ink_hint=True):
    """Proses 1 blok: ink-gate -> (opsional) Ollama -> finalisasi."""
    ink = meta.get("cell_ink")
    filled = [r >= INK_EMPTY_THRESHOLD for r in ink] if ink is not None else None

    if ink_gate and filled is not None and not any(filled):
        res = {
            "parsed": {"jam_label": label, **{f"kolom_{i}": None for i in range(1, N_SUBCELLS + 1)}},
            "raw_text": None, "elapsed_sec": 0.0, "skipped_ollama": True,
        }
    else:
        hint = filled if ink_hint else None
        if args.prompt == "lama":
            system_prompt, user_msg = BLOCK_PROMPT, build_user_message(hint)
        else:
            system_prompt, user_msg = BLOCK_PROMPT_REFINE, build_user_message_refine(hint, label)
        res = call_ollama(args.ollama_url, args.model, args.timeout, args.num_ctx,
                          crop, system_prompt, user_msg)
        res["skipped_ollama"] = False
    res["cell_ink"] = ink
    res["cell_filled"] = filled
    res = finalize_block(res, filled, ink_gate)

    # retry terarah (1x): kotak berisi tinta tp tdk terbaca ("?" / penanda
    # template dikembalikan apa adanya / null) ditanyakan ulang secara spesifik
    if args.prompt != "lama" and filled is not None and "kotak" in res:
        todo = [i for i, v in enumerate(res["kotak"]) if filled[i] and v in ("?", None)]
        if todo:
            res = retry_cells(args, crop, res, todo)

    # tanya ulang "x": menurut aturan form, "x" (penanda akhir rentang) sangat
    # jarang -- >1 "x" dlm 1 blok hampir pasti salah baca (mis. blok berisi "0")
    if (args.prompt != "lama" and not getattr(args, "no_x_retry", False)
            and res.get("validation", {}).get("x_count_suspicious")):
        res = retry_x(args, meta.get("clean_crop", crop), res, label, filled)
    return res


def retry_x(args, clean_crop, res, label, filled):
    """Blok dgn >1 "x" dibaca ulang memakai crop POLOS (tanpa garis bantu).
    Eksperimen: blok berisi enam "0" yg terbaca "x"/"?" dgn overlay, terbaca
    benar dgn crop polos. Hanya kotak yg tadinya "x" yg boleh berubah."""
    todo = [i for i, v in enumerate(res["kotak"]) if v == "x"]
    user_msg = build_user_message_refine(filled, label)
    r = call_ollama(args.ollama_url, args.model, args.timeout, args.num_ctx,
                    clean_crop, BLOCK_PROMPT_REFINE, user_msg)
    before = list(res["kotak"])
    parsed = r.get("parsed") or {}
    for i in todo:
        v = parsed.get(f"kolom_{i + 1}")
        if isinstance(v, (int, float)):
            v = str(v)
        if isinstance(v, str) and v.strip() and v.strip() not in (PLACEHOLDER, "?"):
            res["kotak"][i] = v.strip()
    res["x_retry"] = {"kolom": [i + 1 for i in todo], "sebelum": [before[i] for i in todo],
                      "sesudah": [res["kotak"][i] for i in todo], "raw_text": r.get("raw_text"),
                      "error": r.get("error"), "elapsed_sec": r.get("elapsed_sec")}
    res["elapsed_sec"] = (res.get("elapsed_sec") or 0) + (r.get("elapsed_sec") or 0)
    n_x = sum(1 for v in res["kotak"] if v == "x")
    res["validation"]["n_x_in_block"] = n_x
    res["validation"]["x_count_suspicious"] = n_x > 1
    return res


def retry_cells(args, crop, res, todo):
    nums = ", ".join(str(i + 1) for i in todo)
    keys = ", ".join(f'"kolom_{i + 1}"' for i in todo)
    user_msg = (f"Lihat kotak nomor {nums} (di antara garis merah, di dalam 2 garis cyan). "
                f"Kotak itu PASTI berisi tulisan tangan (bisa kode angka+huruf, angka saja "
                f"seperti \"0\", atau \"x\"). JANGAN jawab \"?\" -- tulis tebakan terbaikmu "
                f"persis seperti tertulis. Jawab HANYA JSON dengan key {keys}.")
    r = call_ollama(args.ollama_url, args.model, args.timeout, args.num_ctx,
                    crop, BLOCK_PROMPT_REFINE, user_msg)
    # hasil retry = TEBAKAN paksa -> ditandai supaya tetap direview manusia
    res["retry"] = {"kolom": [i + 1 for i in todo], "forced_guess": True,
                    "raw_text": r.get("raw_text"),
                    "error": r.get("error"), "elapsed_sec": r.get("elapsed_sec")}
    parsed = r.get("parsed") or {}
    for i in todo:
        v = parsed.get(f"kolom_{i + 1}")
        if isinstance(v, (int, float)):
            v = str(v)
        if isinstance(v, str) and v.strip() and v.strip() != PLACEHOLDER:
            res["kotak"][i] = v.strip()
    res["elapsed_sec"] = (res.get("elapsed_sec") or 0) + (r.get("elapsed_sec") or 0)
    res["validation"]["unreadable_kolom"] = [i + 1 for i, v in enumerate(res["kotak"]) if v == "?"]
    res["validation"]["n_x_in_block"] = sum(1 for v in res["kotak"] if v == "x")
    res["validation"]["x_count_suspicious"] = res["validation"]["n_x_in_block"] > 1
    if "ink_gate" in res:
        res["ink_gate"]["ink_but_null"] = [i + 1 for i in todo if res["kotak"][i] is None]
    return res


def load_reference(ref_dir):
    ref_dir = Path(ref_dir)
    data = np.load(ref_dir / "ref_template.npz")
    anchors = json.loads((ref_dir / "ref_anchors.json").read_text())
    return data["kp_pts"], data["des"], int(data["img_w"]), int(data["img_h"]), anchors


def add_common_args(ap):
    ap.add_argument("--image", required=True)
    ap.add_argument("--shift", required=True, choices=["1", "2", "3"])
    ap.add_argument("--model", default="qwen2.5vl:3b")
    ap.add_argument("--ollama-url", default="http://127.0.0.1:11434")
    ap.add_argument("--timeout", type=int, default=120)
    ap.add_argument("--num-ctx", type=int, default=8192)
    ap.add_argument("--no-ink-gate", action="store_true",
                     help="Matikan ink-gate (semua blok dikirim ke Ollama, jawaban tdk dikoreksi).")
    ap.add_argument("--no-ink-hint", action="store_true",
                     help="Jangan beri tahu model kolom mana yg berisi coretan.")
    ap.add_argument("--no-overlay", action="store_true",
                     help="Kirim crop polos tanpa garis bantu merah/cyan.")
    ap.add_argument("--no-x-retry", action="store_true",
                     help="Jangan tanya ulang blok yg berisi >1 'x'.")
    ap.add_argument("--prompt", choices=["refine", "lama"], default="refine",
                     help="refine = prompt_refine_eksplorasi.py (default), lama = prompt.py.")


def main():
    ap = argparse.ArgumentParser()
    add_common_args(ap)
    ap.add_argument("--ref-dir", required=True,
                     help="Folder hasil calibrate_reference_cli.py (isi ref_template.npz + ref_anchors.json)")
    args = ap.parse_args()

    t0 = time.time()
    try:
        kp_pts, des, ref_w, ref_h, anchors = load_reference(args.ref_dir)
        det, crops = crop_jam_blocks_refined(args.image, args.shift, kp_pts, des, ref_w, ref_h, anchors,
                                             with_overlay=not args.no_overlay)
        t_geo = time.time() - t0
        if crops is None:
            envelope = {
                "status": det["status"],
                "reason": det.get("reason"),
                "meta": {"elapsed_seconds": round(time.time() - t0, 1)},
            }
            _stdout_print(json.dumps(envelope, ensure_ascii=False))
            return 0

        print(f"Geometri OK (confidence={det.get('confidence')}, refined={det.get('refined')}), memproses 8 blok...")
        labels = ROW_BLOCK_LABELS[args.shift]
        blocks_result = []
        for i, (crop, meta) in enumerate(zip(crops, det["block_crops_meta"])):
            res = read_block(args, crop, meta, labels[i],
                             ink_gate=not args.no_ink_gate, ink_hint=not args.no_ink_hint)
            res["crop_image_b64"] = encode_b64_array(crop)
            res["expected_jam_label"] = labels[i]
            res["block_idx"] = i
            blocks_result.append(res)
            tag = "skip (kosong)" if res.get("skipped_ollama") else "Ollama"
            print(f"  blok {i} ({labels[i]}) [{tag}] -> {res.get('kotak', res.get('error'))}")

        refine = det.get("refine", {})
        n_ok = sum(1 for r in blocks_result if r.get("validation", {}).get("n_kolom_ok"))
        envelope = {
            "status": det["status"],
            "data": {"shift": args.shift, "blocks": blocks_result},
            "meta": {
                "geometry_path": det.get("path"),
                "geometry_seconds": round(t_geo, 2),
                "n_ollama_calls": sum((0 if r.get("skipped_ollama") else 1) + (1 if "retry" in r else 0)
                                      + (1 if "x_retry" in r else 0) for r in blocks_result),
                "n_x_retry": sum(1 for r in blocks_result if "x_retry" in r),
                "ollama_seconds": sum(r.get("elapsed_sec") or 0 for r in blocks_result),
                "detection_confidence": det.get("confidence"),
                "refined": det.get("refined"),
                "refine_quality": refine.get("quality") or refine.get("reason"),
                "n_blocks_ok": n_ok,
                "n_blocks_total": len(blocks_result),
                "n_blocks_skipped_empty": sum(1 for r in blocks_result if r.get("skipped_ollama")),
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
        if isinstance(o, np.bool_):
            return bool(o)
        return str(o)

    _stdout_print(json.dumps(envelope, ensure_ascii=False, default=_json_default))
    return 0 if envelope["status"] != "error" else 1


if __name__ == "__main__":
    sys.exit(main())
