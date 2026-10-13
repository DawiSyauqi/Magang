#!/usr/bin/env python3
"""
bandingkan_e6.py -- jalankan EKSPLORASI 6 (foto seluruh halaman) pada semua
foto di folder fullimg & nilai dgn kunci:
  - header/Size/Speed/Operator : kunci_fullpage_eksplorasi6.json
  - Lost time                  : ../kunci_jawaban_eksplorasi.json (lembar yg sama
                                 dgn E1-E5; fungsi skor diimpor dari
                                 ../bandingkan_eksplorasi.py)
Tiap foto = PROSES TERPISAH (spt dipanggil Laravel) -> waktu termasuk load model.

Output (--out, default eksplorasi6/output_e6/):
  mentah/<foto>.json, crops/<foto>/..., nilai_header.csv, nilai_lost_time.csv,
  ringkasan.json

    ../eksplorasi4/.venv/Scripts/python bandingkan_e6.py
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
from bandingkan_eksplorasi import score  # noqa: E402

FIELDS = ["tanggal", "mesin", "shift", "operator", "size", "speed"]


def norm(field, v):
    if v is None:
        return None
    if field == "speed":
        try:
            return round(float(v), 3)
        except (TypeError, ValueError):
            return str(v)
    return "".join(str(v).lower().split()).replace(",", "")


def run(image, out):
    stem = Path(image).name.split(".")[0]
    cmd = [sys.executable, "-B", "baca_fullpage_eksplorasi.py", "--image", str(Path(image).resolve()),
           "--save-crops", str((out / "crops" / stem).resolve())]
    t0 = time.time()
    p = subprocess.run(cmd, cwd=HERE, capture_output=True, text=True, encoding="utf-8")
    wall = time.time() - t0
    lines = [ln for ln in p.stdout.strip().splitlines() if ln.strip()]
    try:
        env = json.loads(lines[-1])
    except (IndexError, json.JSONDecodeError):
        env = {"status": "error", "error": (p.stderr or "")[-800:]}
    return env, wall


def lower_blocks(blocks):
    return [[v.lower() if isinstance(v, str) and v != "SKIP" else v for v in b] for b in blocks]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--images-dir", default=str(SCRIPTS.parent / "fullimg"))
    ap.add_argument("--foto", nargs="*")
    ap.add_argument("--kunci-header", default=str(HERE / "kunci_fullpage_eksplorasi6.json"))
    ap.add_argument("--kunci-lost", default=str(SCRIPTS / "kunci_jawaban_eksplorasi.json"))
    ap.add_argument("--out", default=str(HERE / "output_e6"))
    ap.add_argument("--pakai-mentah", action="store_true", help="Jangan jalankan ulang; nilai dari mentah/.")
    args = ap.parse_args()

    out = Path(args.out)
    (out / "mentah").mkdir(parents=True, exist_ok=True)
    kh = json.loads(Path(args.kunci_header).read_text(encoding="utf-8"))
    kl = json.loads(Path(args.kunci_lost).read_text(encoding="utf-8"))
    photos = sorted(glob.glob(os.path.join(args.images_dir, "*.jp*g")))
    if args.foto:
        photos = [p for p in photos if os.path.basename(p) in args.foto]

    hrows, lrows, cells = [], [], []
    for photo in photos:
        stem = Path(photo).name.split(".")[0]
        mp = out / "mentah" / f"{stem}.json"
        if args.pakai_mentah and mp.exists():
            env = json.loads(mp.read_text(encoding="utf-8"))
            wall = env.get("_wall")
        else:
            print(f"[{stem}] ...", end=" ", flush=True)
            env, wall = run(photo, out)
            env["_wall"] = round(wall, 1)
            mp.write_text(json.dumps(env, ensure_ascii=False, indent=1), encoding="utf-8")
            print(f"{env.get('status')} {wall:.1f}s", flush=True)
        lembar = kh["foto"][stem]
        data, meta = env.get("data", {}), env.get("meta", {})

        hr = {"foto": stem, "lembar": lembar, "status": env.get("status"), "total_dtk": wall,
              "geometri_dtk": meta.get("geometry_seconds"), "field_dtk": meta.get("field_seconds"),
              "lost_time_dtk": meta.get("model_seconds"),
              "skala_px": meta.get("geometri", {}).get("skala_px_per_kanvas")}
        for f in FIELDS:
            got = data.get("header", {}).get(f, {}).get("nilai")
            want = kh["header"][lembar][f]
            hr[f] = got
            hr[f + "_benar"] = norm(f, got) == norm(f, want)
        tg = data.get("header", {}).get("tanggal", {})
        hr["tanggal_hari_cocok_kalender"] = tg.get("hari_cocok_kalender")
        hrows.append(hr)

        for s in ("1", "2"):
            truth = kl["lembar"][lembar].get(s)
            e = data.get("lost_time", {}).get(s)
            blocks = [b["kotak"] for b in e["blocks"]] if e and e.get("blocks") else [[None] * 6] * 8
            ok, n, fok, fn = score(blocks, truth)
            cok, _, cfok, _ = score(lower_blocks(blocks), lower_blocks(truth))
            n_ragu = n_salah = n_tangkap = 0
            for b in (e or {}).get("blocks", []):
                for c in b["calls"]:
                    g = truth[b["block_idx"]][c["kolom"] - 1]
                    if g is None or g == "SKIP":
                        continue
                    v = b["kotak"][c["kolom"] - 1]
                    benar = v.lower() == g.lower()
                    n_ragu += c["ragu"]
                    n_salah += not benar
                    n_tangkap += (not benar) and c["ragu"]
                    cells.append({"foto": stem, "shift": s, "blok": b["block_idx"], "kolom": c["kolom"],
                                  "kunci": g, "e6": v, "benar": benar, "keyakinan": c["keyakinan"],
                                  "ragu": c["ragu"], "kandidat_3": c["kandidat_3"],
                                  "benar_di_top3": g.lower() in c["kandidat_3"]})
            lrows.append({"foto": stem, "lembar": lembar, "shift": s,
                          "status_baris": (e or {}).get("status"),
                          "benar": f"{ok}/{n}", "berisi_benar": f"{fok}/{fn}", "berisi_benar_ci": f"{cfok}/{fn}",
                          "ragu": n_ragu, "salah_ci": n_salah, "salah_tertangkap": n_tangkap,
                          "kotak": " | ".join(" ".join("." if v is None else v for v in b) for b in blocks)})

    ring = summarize(hrows, lrows, cells)
    write_csv(out / "nilai_header.csv", hrows)
    write_csv(out / "nilai_lost_time.csv", lrows)
    (out / "ringkasan.json").write_text(json.dumps({"ringkasan": ring, "header": hrows, "lost_time": lrows,
                                                    "sel": cells}, ensure_ascii=False, indent=1), encoding="utf-8")
    print(json.dumps(ring, ensure_ascii=False, indent=1))
    print(f"\nHasil -> {out.resolve()}")


def _frac(rows, col):
    ok = n = 0
    for r in rows:
        a, b = r[col].split("/")
        ok += int(a)
        n += int(b)
    return ok, n


def summarize(hrows, lrows, cells):
    res = {"header": {}}
    for f in FIELDS:
        k = sum(r[f + "_benar"] for r in hrows)
        res["header"][f] = f"{k}/{len(hrows)}"
    res["header_semua_field_benar"] = f"{sum(all(r[f + '_benar'] for f in FIELDS) for r in hrows)}/{len(hrows)}"
    for name, sub in (("semua", lrows), ("lembar_padat", [r for r in lrows if r["lembar"] == "lembar_padat"]),
                      ("lembar_jarang", [r for r in lrows if r["lembar"] == "lembar_jarang"])):
        ok, n = _frac(sub, "berisi_benar")
        cok, _ = _frac(sub, "berisi_benar_ci")
        aok, an = _frac(sub, "benar")
        res[f"lost_time_{name}"] = {"berisi_eksak": f"{ok}/{n} ({100 * ok / max(1, n):.1f}%)",
                                    "berisi_ci": f"{cok}/{n} ({100 * cok / max(1, n):.1f}%)",
                                    "semua_kotak": f"{aok}/{an} ({100 * aok / max(1, an):.1f}%)"}
    n = len(cells)
    salah = [c for c in cells if not c["benar"]]
    ragu = [c for c in cells if c["ragu"]]
    lolos = [c for c in salah if not c["ragu"]]
    res["hibrida"] = {"kotak_berisi": n, "ditandai_ragu": f"{len(ragu)} ({100 * len(ragu) / max(1, n):.1f}%)",
                      "salah": len(salah), "salah_tertangkap": len(salah) - len(lolos),
                      "benar_akhir": f"{100 * (n - len(lolos)) / max(1, n):.1f}%",
                      "ragu_benar_di_top3": f"{sum(c['benar_di_top3'] for c in ragu)}/{len(ragu)}"}
    times = [r["total_dtk"] for r in hrows if r["total_dtk"]]
    res["rata_waktu_per_foto_dtk"] = round(sum(times) / max(1, len(times)), 1)
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
