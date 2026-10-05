#!/usr/bin/env python3
"""
bandingkan_eksplorasi.py -- jalankan EKSPLORASI 1 & 2 pada semua foto di
folder images, lalu bandingkan WAKTU & NILAI tiap blok.

  E1 = eksplorasi1/baca_kotak_eksplorasi.py
       geometri cepat + ink-gate + Ollama PER KOTAK (1 kotak/panggilan)
  E2 = eksplorasi2/ollama_block_pipeline_refine_eksplorasi.py
       geometri cepat + ink-gate + Ollama PER BLOK (6 kotak/panggilan)

Tiap eksplorasi dijalankan sbg PROSES TERPISAH (spt dipanggil Laravel), jadi
waktu total sudah termasuk start Python + load library. Sebelum mulai, model
"dipanaskan" 1x supaya foto pertama tdk menanggung waktu load model.

Output (folder --out, default output_bandingkan_eksplorasi/):
  waktu_per_foto.csv   -- waktu & skor per foto per eksplorasi
  nilai_per_blok.csv   -- isi tiap blok: kunci vs E1 vs E2
  laporan_perbandingan_eksplorasi.html -- tabel visual (buka di browser)
  mentah/<foto>_e1.json, _e2.json      -- envelope lengkap tiap run

Cara pakai (dari folder scripts):
    python bandingkan_eksplorasi.py
    python bandingkan_eksplorasi.py --shifts 1 2 --model qwen2.5vl:3b
    python bandingkan_eksplorasi.py --foto IMG_20260921_004756.jpg.jpeg
"""

import argparse
import base64
import csv
import glob
import html
import json
import os
import subprocess
import sys
import time
from pathlib import Path

import requests

ROOT = Path(__file__).parent
E1 = ("E1 per-kotak", ROOT / "eksplorasi1", "baca_kotak_eksplorasi.py")
E2 = ("E2 per-blok", ROOT / "eksplorasi2", "ollama_block_pipeline_refine_eksplorasi.py")


def run_pipeline(spec, image, shift, model, url, extra=()):
    _, folder, script = spec
    cmd = [sys.executable, script, "--image", str(Path(image).resolve()), "--shift", shift,
           "--ref-dir", "reference", "--model", model, "--ollama-url", url, *extra]
    t0 = time.time()
    p = subprocess.run(cmd, cwd=folder, capture_output=True, text=True, encoding="utf-8")
    wall = time.time() - t0
    lines = [ln for ln in p.stdout.strip().splitlines() if ln.strip()]
    try:
        env = json.loads(lines[-1])
    except (IndexError, json.JSONDecodeError):
        env = {"status": "error", "error": (p.stderr or "")[-500:]}
    for b in env.get("data", {}).get("blocks", []):
        b.pop("crop_image_b64", None)
    return env, wall


def warm_up(model, url):
    import numpy as np
    import cv2
    ok, buf = cv2.imencode(".jpg", np.full((64, 64, 3), 255, np.uint8))
    try:
        requests.post(f"{url}/api/chat", timeout=300, json={
            "model": model, "stream": False,
            "messages": [{"role": "user", "content": "ok", "images": [base64.b64encode(buf).decode()]}],
        })
    except requests.exceptions.RequestException as e:
        sys.exit(f"Ollama tdk bisa dihubungi di {url}: {e}\nJalankan 'ollama serve' dulu.")


def load_key(path):
    if not path.exists():
        return None
    return json.loads(path.read_text(encoding="utf-8"))


def key_for(key, stem, shift):
    if key is None:
        return None
    sheet = key["foto"].get(stem)
    if sheet is None:
        return None
    return key["lembar"].get(sheet, {}).get(shift)


def score(kotak_blocks, truth):
    """Return (benar, dinilai, berisi_benar, berisi_dinilai)."""
    ok = n = fok = fn = 0
    for b in range(8):
        got = kotak_blocks[b] if b < len(kotak_blocks) else [None] * 6
        for c in range(6):
            g = truth[b][c]
            if g == "SKIP":
                continue
            v = got[c] if c < len(got) else None
            n += 1
            ok += int(v == g)
            if g is not None:
                fn += 1
                fok += int(v == g)
    return ok, n, fok, fn


def fmt_block(vals):
    return " | ".join("." if v is None else str(v) for v in vals)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--images-dir", default=str(ROOT.parent / "images"))
    ap.add_argument("--foto", nargs="*", help="Nama file tertentu saja (default: semua di images-dir).")
    ap.add_argument("--shifts", nargs="+", default=["1"], choices=["1", "2", "3"])
    ap.add_argument("--model", default="qwen2.5vl:3b")
    ap.add_argument("--ollama-url", default="http://127.0.0.1:11434")
    ap.add_argument("--kunci", default=str(ROOT / "kunci_jawaban_eksplorasi.json"))
    ap.add_argument("--out", default=str(ROOT / "output_bandingkan_eksplorasi"))
    ap.add_argument("--eksplorasi", nargs="+", default=["e1", "e2"], choices=["e1", "e2"],
                    help="Eksplorasi yg dijalankan (default keduanya).")
    ap.add_argument("--e2-args", default="",
                    help="Argumen tambahan utk E2, mis. \"--no-overlay\" atau \"--no-x-retry\".")
    args = ap.parse_args()

    out = Path(args.out)
    (out / "mentah").mkdir(parents=True, exist_ok=True)
    key = load_key(Path(args.kunci))

    photos = sorted(glob.glob(os.path.join(args.images_dir, "*.jp*g")))
    if args.foto:
        photos = [p for p in photos if os.path.basename(p) in args.foto]

    print(f"Memanaskan model {args.model} ...", flush=True)
    warm_up(args.model, args.ollama_url)

    rows_time, rows_block = [], []
    for photo in photos:
        stem = Path(photo).name.split(".")[0]
        for shift in args.shifts:
            truth = key_for(key, stem, shift)
            row = {"foto": stem, "shift": shift}
            blocks_by = {"e1": [], "e2": []}
            for tag, spec in (("e1", E1), ("e2", E2)):
                if tag not in args.eksplorasi:
                    continue
                print(f"[{stem} shift{shift}] {spec[0]} ...", end=" ", flush=True)
                extra = args.e2_args.split() if tag == "e2" else []
                env, wall = run_pipeline(spec, photo, shift, args.model, args.ollama_url, extra)
                (out / "mentah" / f"{stem}_s{shift}_{tag}.json").write_text(
                    json.dumps(env, ensure_ascii=False, indent=1, default=str), encoding="utf-8")
                meta = env.get("meta", {})
                blocks = [b.get("kotak") or [None] * 6 for b in env.get("data", {}).get("blocks", [])]
                blocks_by[tag] = blocks
                row[f"{tag}_status"] = env.get("status")
                row[f"{tag}_total_dtk"] = round(wall, 1)
                row[f"{tag}_geometri_dtk"] = meta.get("geometry_seconds")
                row[f"{tag}_ollama_dtk"] = round(meta["ollama_seconds"], 1) if "ollama_seconds" in meta else None
                row[f"{tag}_panggilan_ollama"] = meta.get("n_ollama_calls")
                if truth is not None and blocks:
                    ok, n, fok, fn = score(blocks, truth)
                    row[f"{tag}_benar"] = f"{ok}/{n}"
                    row[f"{tag}_berisi_benar"] = f"{fok}/{fn}"
                else:
                    row[f"{tag}_benar"] = row[f"{tag}_berisi_benar"] = None
                print(f"{env.get('status')} {wall:.1f}s benar={row[f'{tag}_benar']}", flush=True)
            rows_time.append(row)

            for b in range(8):
                rows_block.append({
                    "foto": stem, "shift": shift, "blok": b,
                    "kunci": fmt_block(truth[b]) if truth else "",
                    "e1": fmt_block(blocks_by["e1"][b]) if len(blocks_by["e1"]) > b else "-",
                    "e2": fmt_block(blocks_by["e2"][b]) if len(blocks_by["e2"]) > b else "-",
                    "_truth": truth[b] if truth else None,
                    "_e1": blocks_by["e1"][b] if len(blocks_by["e1"]) > b else None,
                    "_e2": blocks_by["e2"][b] if len(blocks_by["e2"]) > b else None,
                })

    write_csv(out / "waktu_per_foto.csv", rows_time)
    write_csv(out / "nilai_per_blok.csv", [{k: v for k, v in r.items() if not k.startswith("_")}
                                           for r in rows_block])
    write_html(out / "laporan_perbandingan_eksplorasi.html", rows_time, rows_block, args.model)
    print_summary(rows_time)
    print(f"\nHasil -> {out.resolve()}")


def write_csv(path, rows):
    if not rows:
        return
    keys = list(dict.fromkeys(k for r in rows for k in r))
    with open(path, "w", newline="", encoding="utf-8-sig") as f:
        w = csv.DictWriter(f, fieldnames=keys)
        w.writeheader()
        w.writerows(rows)


def _sum_frac(rows, col):
    ok = n = 0
    for r in rows:
        if r.get(col):
            a, b = r[col].split("/")
            ok += int(a)
            n += int(b)
    return ok, n


def print_summary(rows):
    print(f"\n{'='*92}")
    print(f"{'foto':22}{'sh':>3} | {'E1 total':>8} {'geo':>5} {'ollama':>7} {'call':>4} {'benar':>7}"
          f" | {'E2 total':>8} {'geo':>5} {'ollama':>7} {'call':>4} {'benar':>7}")
    for r in rows:
        def part(t):
            if t + "_total_dtk" not in r:
                return f"{'-':>8} {'':5} {'':7} {'':4} {'':>7}"
            return (f"{r[t + '_total_dtk']:8.1f} {r[t + '_geometri_dtk'] or 0:5.1f} {r[t + '_ollama_dtk'] or 0:7.1f}"
                    f" {r[t + '_panggilan_ollama'] or 0:4d} {r[t + '_benar'] or '-':>7}")
        print(f"{r['foto']:22}{r['shift']:>3} | {part('e1')} | {part('e2')}")
    for t, name in (("e1", "E1 per-kotak"), ("e2", "E2 per-blok")):
        if not any(f"{t}_total_dtk" in r for r in rows):
            continue
        tot = sum(r[f"{t}_total_dtk"] for r in rows)
        ok, n = _sum_frac(rows, f"{t}_benar")
        fok, fn = _sum_frac(rows, f"{t}_berisi_benar")
        acc = f"{ok}/{n} ({100 * ok / n:.1f}%)" if n else "-"
        facc = f"{fok}/{fn} ({100 * fok / fn:.1f}%)" if fn else "-"
        print(f"{name}: total {tot:.1f}s, rata2 {tot / max(1, len(rows)):.1f}s/foto | "
              f"semua kotak {acc} | kotak berisi {facc}")
    print("=" * 92)


def write_html(path, rows_time, rows_block, model):
    def cell(v, truth_v, has_truth):
        txt = "&middot;" if v is None else html.escape(str(v))
        if not has_truth or truth_v == "SKIP":
            cls = "na"
        else:
            cls = "ok" if v == truth_v else "bad"
        return f'<td class="{cls}">{txt}</td>'

    parts = [f"""<!doctype html><html lang="id"><head><meta charset="utf-8">
<title>Perbandingan Eksplorasi 1 vs 2</title><style>
body{{font:14px system-ui,sans-serif;margin:24px;color:#1d2433;background:#fafafa}}
h1{{font-size:20px}} h2{{font-size:16px;margin-top:28px}}
table{{border-collapse:collapse;margin:8px 0 16px;background:#fff}}
th,td{{border:1px solid #d5d9e0;padding:3px 7px;text-align:center;font-variant-numeric:tabular-nums}}
th{{background:#eef1f5}} td.ok{{background:#e5f4ea}} td.bad{{background:#fbe3e1;font-weight:600}}
td.na{{color:#555}} td.lbl{{text-align:left;font-weight:600;background:#f6f7f9}}
.wrap{{overflow-x:auto}} .note{{color:#555;max-width:900px}}
</style></head><body>
<h1>Perbandingan Eksplorasi 1 (per kotak) vs Eksplorasi 2 (per blok)</h1>
<p class="note">Model: {html.escape(model)}. Hijau = sama dgn kunci, merah = beda, &middot; = kosong.
Kunci jawaban dari pengamatan visual (kunci_jawaban_eksplorasi.json).</p>
<h2>Waktu per foto (detik)</h2><div class="wrap"><table>
<tr><th rowspan=2>Foto</th><th rowspan=2>Shift</th><th colspan=5>E1 per-kotak</th><th colspan=5>E2 per-blok</th></tr>
<tr><th>Total</th><th>Geometri</th><th>Ollama</th><th>Panggilan</th><th>Benar</th>
<th>Total</th><th>Geometri</th><th>Ollama</th><th>Panggilan</th><th>Benar</th></tr>"""]
    for r in rows_time:
        def g(k):
            v = r.get(k)
            return "-" if v is None else v
        tds = "".join(
            f"<td>{g(t + '_total_dtk')}</td><td>{g(t + '_geometri_dtk')}</td><td>{g(t + '_ollama_dtk')}</td>"
            f"<td>{g(t + '_panggilan_ollama')}</td><td>{g(t + '_benar')}</td>" for t in ("e1", "e2"))
        parts.append(f"<tr><td class='lbl'>{r['foto']}</td><td>{r['shift']}</td>{tds}</tr>")
    parts.append("</table></div><h2>Nilai tiap blok</h2>")

    by_photo = {}
    for r in rows_block:
        by_photo.setdefault((r["foto"], r["shift"]), []).append(r)
    for (foto, shift), blocks in by_photo.items():
        parts.append(f"<h3>{foto} &mdash; shift {shift}</h3><div class='wrap'><table><tr><th></th>")
        parts.append("".join(f"<th colspan=6>Blok {b['blok']}</th>" for b in blocks) + "</tr>")
        for label, k in (("Kunci", "_truth"), ("E1", "_e1"), ("E2", "_e2")):
            row = [f"<tr><td class='lbl'>{label}</td>"]
            for b in blocks:
                vals = b[k] or [None] * 6
                truth = b["_truth"]
                for c in range(6):
                    tv = truth[c] if truth else None
                    if label == "Kunci":
                        row.append(f"<td class='na'>{'&middot;' if vals[c] is None else html.escape(str(vals[c]))}</td>")
                    else:
                        row.append(cell(vals[c] if c < len(vals) else None, tv, truth is not None))
            parts.append("".join(row) + "</tr>")
        parts.append("</table></div>")
    parts.append("</body></html>")
    path.write_text("\n".join(parts), encoding="utf-8")


if __name__ == "__main__":
    main()
