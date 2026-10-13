#!/usr/bin/env python3
"""
bandingkan_e4_e5.py -- jalankan EKSPLORASI 4 & 5 (TrOCR) pada semua foto di
folder images, nilai dgn kunci jawaban yg SAMA (kunci_jawaban_eksplorasi.json,
fungsi skor diimpor dari ../bandingkan_eksplorasi.py), lalu sandingkan dgn
hasil E1 & E2 (Ollama) yg sudah ada di ../output_bandingkan_eksplorasi/mentah.

  E4 = eksplorasi4/baca_kotak_trocr_eksplorasi.py  (tiruan E1, per kotak)
  E5 = eksplorasi5/baca_blok_trocr_eksplorasi.py   (tiruan E2, per blok)
  tiap eksplorasi 2 mode: kandidat & bebas.

Tiap run = PROSES TERPISAH (spt dipanggil Laravel) -> waktu total sudah
termasuk start Python + load model TrOCR.

Output (--out, default eksplorasi4/output_bandingkan_e4_e5/):
  mentah/<foto>_s<shift>_<varian>.json  envelope lengkap
  crops/<varian>/...                    crop yg dibaca model
  waktu_per_foto.csv, nilai_per_blok.csv, ringkasan.json

Cara pakai (dari folder scripts/eksplorasi4, pakai python venv):
    .venv/Scripts/python bandingkan_e4_e5.py
    .venv/Scripts/python bandingkan_e4_e5.py --varian e4_kandidat e5_kandidat --foto IMG_20260921_004756.jpg.jpeg
"""

import argparse
import csv
import glob
import json
import os
import subprocess
import sys
import time
from pathlib import Path

HERE = Path(__file__).parent
SCRIPTS = HERE.parent
sys.path.insert(0, str(SCRIPTS))
sys.dont_write_bytecode = True  # jangan tinggalkan __pycache__ di folder scripts/
from bandingkan_eksplorasi import key_for, load_key, score  # noqa: E402

VARIAN = {
    "e4_kandidat": (SCRIPTS / "eksplorasi4", "baca_kotak_trocr_eksplorasi.py", ["--mode", "kandidat"]),
    "e4_bebas": (SCRIPTS / "eksplorasi4", "baca_kotak_trocr_eksplorasi.py", ["--mode", "bebas"]),
    "e5_kandidat": (SCRIPTS / "eksplorasi5", "baca_blok_trocr_eksplorasi.py", ["--mode", "kandidat"]),
    "e5_bebas": (SCRIPTS / "eksplorasi5", "baca_blok_trocr_eksplorasi.py", ["--mode", "bebas"]),
}
LAMA = {"e1": "_e1.json", "e2": "_e2.json"}  # hasil Ollama yg sudah ada


def run(varian, image, shift, out, extra):
    folder, script, args = VARIAN[varian]
    stem = Path(image).name.split(".")[0]
    crops = out / "crops" / varian / f"{stem}_s{shift}"
    cmd = [sys.executable, script, "--image", str(Path(image).resolve()), "--shift", shift,
           "--save-crops", str(crops.resolve()), *args, *extra]
    t0 = time.time()
    p = subprocess.run(cmd, cwd=folder, capture_output=True, text=True, encoding="utf-8")
    wall = time.time() - t0
    lines = [ln for ln in p.stdout.strip().splitlines() if ln.strip()]
    try:
        env = json.loads(lines[-1])
    except (IndexError, json.JSONDecodeError):
        env = {"status": "error", "error": (p.stderr or "")[-800:]}
    return env, wall


def score_ci(blocks, truth):
    """Skor tanpa membedakan huruf besar/kecil (aplikasi menormalkan ke lowercase)."""
    low = [[v.lower() if isinstance(v, str) else v for v in b] for b in blocks]
    tl = [[v.lower() if isinstance(v, str) and v != "SKIP" else v for v in b] for b in truth]
    return score(low, tl)


def blocks_of(env):
    return [b.get("kotak") or [None] * 6 for b in env.get("data", {}).get("blocks", [])]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--images-dir", default=str(SCRIPTS.parent / "images"))
    ap.add_argument("--foto", nargs="*")
    ap.add_argument("--shifts", nargs="+", default=["1", "2"], choices=["1", "2", "3"])
    ap.add_argument("--varian", nargs="+", default=list(VARIAN), choices=list(VARIAN))
    ap.add_argument("--kunci", default=str(SCRIPTS / "kunci_jawaban_eksplorasi.json"))
    ap.add_argument("--dir-lama", default=str(SCRIPTS / "output_bandingkan_eksplorasi"),
                    help="Folder hasil bandingkan_eksplorasi.py (E1/E2 Ollama) utk disandingkan.")
    ap.add_argument("--out", default=str(HERE / "output_bandingkan_e4_e5"))
    ap.add_argument("--extra", default="", help="Argumen tambahan utk semua varian, mis. \"--trocr-model microsoft/trocr-base-handwritten\".")
    args = ap.parse_args()

    out = Path(args.out)
    (out / "mentah").mkdir(parents=True, exist_ok=True)
    key = load_key(Path(args.kunci))
    photos = sorted(glob.glob(os.path.join(args.images_dir, "*.jp*g")))
    if args.foto:
        photos = [p for p in photos if os.path.basename(p) in args.foto]

    rows, block_rows = [], []
    for photo in photos:
        stem = Path(photo).name.split(".")[0]
        for shift in args.shifts:
            truth = key_for(key, stem, shift)
            row = {"foto": stem, "shift": shift}
            got = {}
            for tag, suffix in LAMA.items():
                p = Path(args.dir_lama) / "mentah" / f"{stem}_s{shift}{suffix}"
                if p.exists():
                    env = json.loads(p.read_text(encoding="utf-8"))
                    got[tag] = blocks_of(env)
                    m = env.get("meta", {})
                    row[f"{tag}_ollama_dtk"] = round(m["ollama_seconds"], 1) if "ollama_seconds" in m else None
            for v in args.varian:
                print(f"[{stem} s{shift}] {v} ...", end=" ", flush=True)
                env, wall = run(v, photo, shift, out, args.extra.split())
                (out / "mentah" / f"{stem}_s{shift}_{v}.json").write_text(
                    json.dumps(env, ensure_ascii=False, indent=1), encoding="utf-8")
                m = env.get("meta", {})
                got[v] = blocks_of(env)
                row[f"{v}_status"] = env.get("status")
                row[f"{v}_total_dtk"] = round(wall, 1)
                row[f"{v}_geometri_dtk"] = m.get("geometry_seconds")
                row[f"{v}_load_model_dtk"] = m.get("model_load_seconds")
                row[f"{v}_baca_dtk"] = m.get("model_seconds")
                row[f"{v}_panggilan"] = m.get("n_model_calls")
                print(f"{env.get('status')} {wall:.1f}s", flush=True)
            for tag, blocks in got.items():
                if truth is None or not blocks:
                    continue
                ok, n, fok, fn = score(blocks, truth)
                cok, _, cfok, _ = score_ci(blocks, truth)
                row[f"{tag}_benar"] = f"{ok}/{n}"
                row[f"{tag}_berisi_benar"] = f"{fok}/{fn}"
                row[f"{tag}_berisi_benar_ci"] = f"{cfok}/{fn}"
            rows.append(row)
            for b in range(8):
                br = {"foto": stem, "shift": shift, "blok": b,
                      "kunci": truth[b] if truth else None}
                for tag, blocks in got.items():
                    br[tag] = blocks[b] if len(blocks) > b else None
                block_rows.append(br)

    write_csv(out / "waktu_per_foto.csv", rows)
    write_csv(out / "nilai_per_blok.csv",
              [{k: (" | ".join("." if x is None else str(x) for x in v) if isinstance(v, list) else v)
                for k, v in r.items()} for r in block_rows])
    summary = summarize(rows, ["e1", "e2", *args.varian])
    (out / "ringkasan.json").write_text(json.dumps({"ringkasan": summary, "per_foto": rows,
                                                    "per_blok": block_rows}, ensure_ascii=False, indent=1),
                                        encoding="utf-8")
    print("\n" + "=" * 100)
    for tag, s in summary.items():
        print(f"{tag:12} semua {s['semua']:>16} | berisi {s['berisi']:>16} | berisi (abaikan besar/kecil) "
              f"{s['berisi_ci']:>16} | rata2 waktu/run {s['rata_waktu']}")
    print(f"\nHasil -> {out.resolve()}")


def _frac(rows, col):
    ok = n = 0
    for r in rows:
        if r.get(col):
            a, b = r[col].split("/")
            ok += int(a)
            n += int(b)
    return ok, n


def summarize(rows, tags):
    res = {}
    for t in tags:
        if not any(f"{t}_benar" in r for r in rows):
            continue
        s = {}
        for name, col in (("semua", "benar"), ("berisi", "berisi_benar"), ("berisi_ci", "berisi_benar_ci")):
            ok, n = _frac(rows, f"{t}_{col}")
            s[name] = f"{ok}/{n} ({100 * ok / n:.1f}%)" if n else "-"
            s[name + "_raw"] = [ok, n]
        times = [r[f"{t}_total_dtk"] for r in rows if r.get(f"{t}_total_dtk") is not None]
        s["rata_waktu"] = f"{sum(times) / len(times):.1f}s" if times else "-"
        res[t] = s
    return res


def write_csv(path, rows):
    if not rows:
        return
    keys = list(dict.fromkeys(k for r in rows for k in r))
    with open(path, "w", newline="", encoding="utf-8-sig") as f:
        w = csv.DictWriter(f, fieldnames=keys)
        w.writeheader()
        w.writerows(rows)


if __name__ == "__main__":
    main()
