#!/usr/bin/env python3
"""
analisis_hibrida_e4.py -- uji "hibrida E4 + konfirmasi operator" TANPA
menjalankan ulang model: memakai skor 5 kandidat teratas per kotak yg sudah
disimpan E4 (meta calls[].top5 di mentah/*_e4_kandidat.json).

Ide: tiap kotak diberi nilai KEYAKINAN; kotak di bawah ambang ditandai
"ragu" -> dikonfirmasi operator (memilih dari 3 kandidat teratas / mengetik).
Yg diukur utk tiap ambang:
  - % kotak ditandai (beban operator),
  - akurasi kotak yg lolos otomatis,
  - kesalahan E4 yg tertangkap (masuk daftar ragu),
  - kebenaran akhir bila operator membetulkan semua kotak ragu,
  - seberapa sering jawaban benar ada di 3 kandidat teratas (operator cukup klik).

Kode dibandingkan TANPA membedakan huruf besar/kecil (spt aplikasi:
ProblemCodeConverter menormalkan ke huruf kecil); varian besar/kecil kode
yg sama digabung (peluangnya dijumlah).

Tiga ukuran keyakinan dibandingkan:
  posterior : peluang kode teratas relatif thd 5 kandidat teratas (0..1);
  selisih   : log-prob kode teratas - kode berbeda berikutnya;
  skor      : log-prob kode teratas.

Cara pakai (folder scripts/eksplorasi4, python venv):
    .venv/Scripts/python analisis_hibrida_e4.py
Output: <hasil>/hibrida/hibrida_e4.json + kurva_ambang.csv
"""

import argparse
import csv
import glob
import json
import math
from pathlib import Path

HERE = Path(__file__).parent
TUNING_FOTO = {"IMG_20260901_134505", "IMG_20260921_004756"}


def merge_case(top5):
    """[(kode, logp)] -> [(kode_kecil, logp_gabungan, bentuk_teratas)] terurut."""
    groups = {}
    for code, lp in top5:
        k = code.lower()
        if k not in groups:
            groups[k] = [lp, code]
        else:
            m = max(groups[k][0], lp)
            groups[k][0] = m + math.log(math.exp(groups[k][0] - m) + math.exp(lp - m))
    return sorted(((k, v[0], v[1]) for k, v in groups.items()), key=lambda t: -t[1])


def confidence(top5):
    g = merge_case(top5)
    z = sum(math.exp(lp - g[0][1]) for _, lp, _ in g)
    post = 1.0 / z
    worst = min(lp for _, lp in top5)
    margin = g[0][1] - g[1][1] if len(g) > 1 else g[0][1] - worst  # batas bawah
    return {"posterior": post, "selisih": margin, "skor": g[0][1], "kandidat": [k for k, _, _ in g]}


def load_cells(hasil, key):
    cells = []
    for f in sorted(glob.glob(str(Path(hasil) / "mentah" / "*_e4_kandidat.json"))):
        name = Path(f).name
        stem, shift = name.split("_s")[0], name.split("_s")[1][0]
        truth = key["lembar"][key["foto"][stem]].get(shift)
        env = json.loads(Path(f).read_text(encoding="utf-8"))
        for b in env.get("data", {}).get("blocks", []):
            for c in b["calls"]:
                g = truth[b["block_idx"]][c["kolom"] - 1] if truth else None
                if g is None or g == "SKIP":
                    continue
                v = b["kotak"][c["kolom"] - 1]
                conf = confidence(c["top5"])
                cells.append({
                    "foto": stem, "shift": shift, "blok": b["block_idx"], "kolom": c["kolom"],
                    "kunci": g, "e4": v, "benar": v.lower() == g.lower(),
                    "benar_di_top3": g.lower() in conf["kandidat"][:3],
                    "lembar": key["foto"][stem], "tuning": stem in TUNING_FOTO, **conf,
                })
    return cells


def sweep(cells, measure, flag_rates=(0, .05, .10, .15, .20, .25, .30, .40, .50)):
    """Ambang dipilih dari kuantil ukuran keyakinan supaya % kotak ditandai pas."""
    vals = sorted(c[measure] for c in cells)
    n = len(cells)
    n_err = sum(not c["benar"] for c in cells)
    rows = []
    for fr in flag_rates:
        k = int(round(fr * n))
        thr = vals[k] if k < n else math.inf  # ditandai bila nilai < thr
        flagged = [c for c in cells if c[measure] < thr] if k else []
        auto = [c for c in cells if c not in flagged]
        caught = sum(not c["benar"] for c in flagged)
        rows.append({
            "ukuran": measure, "target_ditandai": fr, "ambang": None if math.isinf(thr) else round(thr, 4),
            "ditandai": len(flagged), "ditandai_pct": round(100 * len(flagged) / n, 1),
            "akurasi_otomatis_pct": round(100 * sum(c["benar"] for c in auto) / max(1, len(auto)), 1),
            "salah_tertangkap": caught, "salah_total": n_err,
            "salah_lolos": n_err - caught,
            "benar_akhir_pct": round(100 * (n - (n_err - caught)) / n, 1),
            "ragu_benar_di_top3_pct": round(100 * sum(c["benar_di_top3"] for c in flagged) / max(1, len(flagged)), 1),
        })
    return rows


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--hasil", default=str(HERE / "output_bandingkan_e4_e5_ak"))
    ap.add_argument("--kunci", default=str(HERE.parent / "kunci_jawaban_eksplorasi.json"))
    args = ap.parse_args()

    key = json.loads(Path(args.kunci).read_text(encoding="utf-8"))
    cells = load_cells(args.hasil, key)
    out = Path(args.hasil) / "hibrida"
    out.mkdir(exist_ok=True)

    all_rows = []
    for m in ("posterior", "selisih", "skor"):
        rows = sweep(cells, m)
        all_rows += rows
        print(f"\n== ukuran keyakinan: {m}")
        print(f"{'ditandai':>9} {'ambang':>8} {'akurasi otomatis':>17} {'salah tertangkap':>17} {'benar akhir':>12} {'top3':>6}")
        for r in rows:
            print(f"{r['ditandai_pct']:8.1f}% {str(r['ambang']):>8} {r['akurasi_otomatis_pct']:16.1f}% "
                  f"{r['salah_tertangkap']:>8}/{r['salah_total']:<8} {r['benar_akhir_pct']:11.1f}% {r['ragu_benar_di_top3_pct']:5.1f}%")

    # validasi ambang: pilih ambang di 2 foto tuning, terapkan ke 10 foto lain
    best = "posterior"
    tune = [c for c in cells if c["tuning"]]
    held = [c for c in cells if not c["tuning"]]
    val = []
    for fr in (.10, .15, .20, .25):
        vals = sorted(c[best] for c in tune)
        thr = vals[int(round(fr * len(tune)))]
        fl = [c for c in held if c[best] < thr]
        err = sum(not c["benar"] for c in held)
        caught = sum(not c["benar"] for c in fl)
        val.append({"target_ditandai_di_tuning": fr, "ambang": round(thr, 4),
                    "ditandai_pct": round(100 * len(fl) / len(held), 1),
                    "salah_tertangkap": caught, "salah_total": err,
                    "benar_akhir_pct": round(100 * (len(held) - (err - caught)) / len(held), 1),
                    "benar_tanpa_konfirmasi_pct": round(100 * (len(held) - err) / len(held), 1)})
    print("\n== validasi: ambang dari 2 foto tuning -> 10 foto lain")
    for v in val:
        print(v)

    yakin_salah = sorted((c for c in cells if not c["benar"]), key=lambda c: -c[best])[:10]
    print("\n== salah tapi paling YAKIN (tdk tertangkap duluan):")
    for c in yakin_salah:
        print(f"  {c['foto']} s{c['shift']} b{c['blok']}k{c['kolom']}: kunci {c['kunci']} -> {c['e4']} "
              f"posterior {c['posterior']:.2f} selisih {c['selisih']:.2f}")

    (out / "hibrida_e4.json").write_text(json.dumps(
        {"n_kotak": len(cells), "kurva": all_rows, "validasi": val, "sel": cells},
        ensure_ascii=False, indent=1), encoding="utf-8")
    with open(out / "kurva_ambang.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.DictWriter(f, fieldnames=list(all_rows[0]))
        w.writeheader()
        w.writerows(all_rows)
    print(f"\nHasil -> {out.resolve()}")


if __name__ == "__main__":
    main()
