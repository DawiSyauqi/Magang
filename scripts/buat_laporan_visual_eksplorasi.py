#!/usr/bin/env python3
"""
buat_laporan_visual_eksplorasi.py -- laporan EVALUASI KUALITATIF (HTML mandiri).

Untuk tiap foto: overlay hasil deteksi, lalu per blok jam: crop yg dilihat
model E2 + tabel Kunci / E1 / E2 per kotak, lalu galeri kotak yg salah
(crop yg dilihat model E1) supaya jelas MASALAHNYA apa.

Butuh (dihasilkan sebelumnya):
  - aset gambar   : eksplorasi2/aset_visual_eksplorasi.py --out-dir <aset>
  - hasil E1      : <dir-e1>/mentah/<foto>_s<shift>_e1.json  (bandingkan_eksplorasi.py)
  - hasil E2      : <dir-e2>/mentah/<foto>_s<shift>_e2.json
  - kunci jawaban : kunci_jawaban_eksplorasi.json

Contoh (dari folder scripts):
    python buat_laporan_visual_eksplorasi.py --aset <aset> \
        --dir-e1 output_bandingkan_eksplorasi \
        --dir-e2 output_bandingkan_eksplorasi/3b_e2_overlay_xretry \
        --out "../Laporan/Evaluasi Visual Eksplorasi.html"
"""

import argparse
import base64
import html
import json
from pathlib import Path

ROOT = Path(__file__).parent
JAM = {
    "1": ["07-08", "08-09", "09-10", "10-11", "11-12", "12-13", "13-14", "14-15"],
    "2": ["15-16", "16-17", "17-18", "18-19", "19-20", "20-21", "21-22", "22-23"],
    "3": ["23-24", "24-01", "01-02", "02-03", "03-04", "04-05", "05-06", "06-07"],
}


def b64img(path, cls="", alt=""):
    data = base64.b64encode(Path(path).read_bytes()).decode()
    return f'<img class="{cls}" alt="{html.escape(alt)}" src="data:image/jpeg;base64,{data}">'


def load(path):
    p = Path(path)
    return json.loads(p.read_text(encoding="utf-8")) if p.exists() else None


def val(v):
    if v is None:
        return "&middot;"
    return html.escape(str(v))


def score(blocks, truth):
    ok = n = 0
    for b in range(8):
        for c in range(6):
            g = truth[b][c]
            if g == "SKIP":
                continue
            n += 1
            ok += int(blocks[b][c] == g)
    return ok, n


CSS = """
:root{--bg:#f6f7f9;--card:#fff;--ink:#1d2433;--muted:#5b6474;--line:#d9dde4;
--ok:#e3f3e8;--okink:#1e6b3a;--bad:#fbe1de;--badink:#a3271b;--na:#f0f1f4;--accent:#2f5bd3}
*{box-sizing:border-box}
body{margin:0;font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:var(--bg)}
main{max-width:1280px;margin:0 auto;padding:24px 16px 64px}
h1{font-size:22px;margin:0 0 4px} h2{font-size:18px;margin:0}
h3{font-size:15px;margin:22px 0 8px}
.sub{color:var(--muted);margin:0 0 18px}
.legend span{display:inline-block;padding:1px 8px;border-radius:4px;margin-right:6px}
.box{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px;margin:18px 0}
table{border-collapse:collapse}
.sum td,.sum th{border:1px solid var(--line);padding:4px 9px;text-align:center;font-variant-numeric:tabular-nums}
.sum th{background:var(--na)} .sum td.l{text-align:left}
.sum a{color:var(--accent);text-decoration:none}
.head{display:flex;flex-wrap:wrap;gap:6px 18px;align-items:baseline;margin-bottom:10px}
.pill{font-size:12px;padding:2px 8px;border-radius:999px;background:var(--na);color:var(--muted)}
.pill.warn{background:#fff1d6;color:#8a5a00}
.ovl{overflow-x:auto;border:1px solid var(--line);border-radius:6px;cursor:zoom-in}
.ovl img{display:block;width:100%}
.ovl.zoom{cursor:zoom-out} .ovl.zoom img{width:auto;max-width:none}
.blocks{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px}
.blk{border:1px solid var(--line);border-radius:8px;padding:8px;background:#fbfbfc;min-width:0}
.blk.has-err{border-color:#e7a59d}
.blk .t{font-weight:600;margin-bottom:4px;display:flex;justify-content:space-between}
.blk img{width:100%;border-radius:4px;display:block}
.vt{width:100%;max-width:100%;margin-top:6px;table-layout:fixed}
.vt td,.vt th{border:1px solid var(--line);padding:2px 0;text-align:center;font:12px ui-monospace,Consolas,monospace}
.vt th{background:var(--na);font-weight:600}
.vt col.lbl{width:3.6em}
.vt td.ok{background:var(--ok);color:var(--okink)}
.vt td.bad{background:var(--bad);color:var(--badink);font-weight:700}
.vt td.k{background:#fff}
.note{font-size:12px;color:#8a5a00;margin-top:4px}
.gal{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
.cell{border:1px solid var(--line);border-radius:8px;padding:6px;background:#fff;font-size:12px}
.cell img{width:100%;border-radius:4px;display:block}
.cell .w{margin-top:4px;font-family:ui-monospace,Consolas,monospace}
.cell .w b{color:var(--badink)} .cell .w i{color:var(--okink);font-style:normal}
.muted{color:var(--muted)}
"""


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--aset", required=True)
    ap.add_argument("--dir-e1", default=str(ROOT / "output_bandingkan_eksplorasi"))
    ap.add_argument("--dir-e2", default=str(ROOT / "output_bandingkan_eksplorasi" / "3b_e2_overlay_xretry"))
    ap.add_argument("--label-e1", default="E1 per-kotak (qwen2.5vl:3b)")
    ap.add_argument("--label-e2", default="E2 per-blok (qwen2.5vl:3b, garis bantu + tanya ulang x)")
    ap.add_argument("--kunci", default=str(ROOT / "kunci_jawaban_eksplorasi.json"))
    ap.add_argument("--out", required=True)
    args = ap.parse_args()

    key = load(args.kunci)
    aset = Path(args.aset)
    sections, summary = [], []

    for photo, sheet in key["foto"].items():
        for shift, truth in key["lembar"][sheet].items():
            # shift yg seluruhnya kosong tdk ditampilkan (semua benar, tdk informatif)
            if all(v is None for blk in truth for v in blk):
                continue
            d = aset / f"{photo}_s{shift}"
            info = load(d / "info.json") or {}
            e1 = load(Path(args.dir_e1) / "mentah" / f"{photo}_s{shift}_e1.json") or {}
            e2 = load(Path(args.dir_e2) / "mentah" / f"{photo}_s{shift}_e2.json") or {}
            b1 = e1.get("data", {}).get("blocks", [])
            b2 = e2.get("data", {}).get("blocks", [])
            k1 = [b.get("kotak") or [None] * 6 for b in b1] or [[None] * 6] * 8
            k2 = [b.get("kotak") or [None] * 6 for b in b2] or [[None] * 6] * 8
            s1, s2 = score(k1, truth), score(k2, truth)
            anchor = f"{photo}_s{shift}"
            summary.append((anchor, photo, shift, sheet, info, s1, s2,
                            e1.get("meta", {}).get("elapsed_seconds"), e2.get("meta", {}).get("elapsed_seconds")))

            out = [f'<section class="box" id="{anchor}"><div class="head">'
                   f'<h2>{photo} &mdash; shift {shift}</h2>'
                   f'<span class="pill">{sheet.replace("_", " ")}</span>'
                   f'<span class="pill{" warn" if info.get("status") != "success" else ""}">geometri: '
                   f'{html.escape(str(info.get("status")))} ({html.escape(str(info.get("quality")))})</span>'
                   f'<span class="pill">E1 {s1[0]}/{s1[1]}</span><span class="pill">E2 {s2[0]}/{s2[1]}</span></div>']
            if (d / "overlay.jpg").exists():
                out.append('<div class="ovl" onclick="this.classList.toggle(\'zoom\')">'
                           + b64img(d / "overlay.jpg", alt="overlay") + "</div>"
                           '<p class="muted" style="margin:4px 0 0">Klik gambar untuk memperbesar. '
                           'Biru = batas baris, hijau = batas blok, kuning = batas kotak, titik magenta = kotak bertinta.</p>')

            out.append('<h3>Per blok jam (gambar = crop yang dikirim ke model E2)</h3><div class="blocks">')
            wrong_cells = []
            for b in range(8):
                rows = {"Kunci": truth[b], "E1": k1[b] if b < len(k1) else [None] * 6,
                        "E2": k2[b] if b < len(k2) else [None] * 6}
                has_err = any(rows[t][c] != truth[b][c] and truth[b][c] != "SKIP"
                              for t in ("E1", "E2") for c in range(6))
                img = b64img(d / f"blok{b}.jpg", alt=f"blok {b}") if (d / f"blok{b}.jpg").exists() else ""
                tbl = ['<table class="vt"><colgroup><col class="lbl">' + '<col>' * 6 + '</colgroup><tr><th></th>' + "".join(f"<th>{c + 1}</th>" for c in range(6)) + "</tr>"]
                for name, vals in rows.items():
                    tds = []
                    for c in range(6):
                        g = truth[b][c]
                        if name == "Kunci":
                            tds.append(f'<td class="k">{"skip" if g == "SKIP" else val(g)}</td>')
                        else:
                            cls = "" if g == "SKIP" else ("ok" if vals[c] == g else "bad")
                            tds.append(f'<td class="{cls}">{val(vals[c])}</td>')
                    tbl.append(f"<tr><th>{name}</th>{''.join(tds)}</tr>")
                tbl.append("</table>")
                note = ""
                xr = b2[b].get("x_retry") if b < len(b2) else None
                if xr:
                    note = (f'<div class="note">E2 tanya ulang "x" di kotak {xr["kolom"]}: '
                            f'{html.escape(str(xr["sebelum"]))} &rarr; {html.escape(str(xr["sesudah"]))}</div>')
                rt = b2[b].get("retry") if b < len(b2) else None
                if rt:
                    note += f'<div class="note">E2 tanya ulang kotak tak terbaca: {rt["kolom"]}</div>'
                out.append(f'<div class="blk{" has-err" if has_err else ""}"><div class="t">'
                           f'<span>Blok {b} &middot; {JAM[shift][b]}</span>'
                           f'<span class="muted">{"ada salah" if has_err else "semua benar"}</span></div>'
                           f'{img}{"".join(tbl)}{note}</div>')
                for c in range(6):
                    g = truth[b][c]
                    if g == "SKIP":
                        continue
                    if rows["E1"][c] != g or rows["E2"][c] != g:
                        wrong_cells.append((b, c, g, rows["E1"][c], rows["E2"][c]))
            out.append("</div>")

            out.append(f'<h3>Kotak yang salah ({len(wrong_cells)}) &mdash; gambar = crop yang dikirim ke model E1</h3>')
            if wrong_cells:
                out.append('<div class="gal">')
                for b, c, g, v1, v2 in wrong_cells:
                    i = b * 6 + c
                    img = b64img(d / f"kotak{i}.jpg", alt="kotak") if (d / f"kotak{i}.jpg").exists() \
                        else '<div class="muted" style="padding:30px 0;text-align:center">(tdk bertinta)</div>'
                    def mark(v):
                        return f"<i>{val(v)}</i>" if v == g else f"<b>{val(v)}</b>"
                    out.append(f'<div class="cell">{img}<div class="w">Blok {b} kotak {c + 1}<br>'
                               f'kunci: {val(g)}<br>E1: {mark(v1)} &nbsp; E2: {mark(v2)}</div></div>')
                out.append("</div>")
            else:
                out.append('<p class="muted">Tidak ada &mdash; E1 dan E2 sama-sama benar semua.</p>')
            out.append("</section>")
            sections.append("".join(out))

    sm = ['<table class="sum"><tr><th>Foto</th><th>Shift</th><th>Lembar</th><th>Geometri</th>'
          '<th>E1 benar</th><th>E1 waktu</th><th>E2 benar</th><th>E2 waktu</th></tr>']
    for anchor, photo, shift, sheet, info, s1, s2, t1, t2 in summary:
        sm.append(f'<tr><td class="l"><a href="#{anchor}">{photo}</a></td><td>{shift}</td>'
                  f'<td>{sheet.replace("lembar_", "")}</td><td>{html.escape(str(info.get("status")))}</td>'
                  f'<td>{s1[0]}/{s1[1]}</td><td>{t1 if t1 is not None else "-"} dtk</td>'
                  f'<td>{s2[0]}/{s2[1]}</td><td>{t2 if t2 is not None else "-"} dtk</td></tr>')
    sm.append("</table>")

    page = f"""<!doctype html><html lang="id"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Evaluasi Visual Eksplorasi</title><style>{CSS}</style></head><body><main>
<h1>Evaluasi Visual Eksplorasi 1 vs Eksplorasi 2</h1>
<p class="sub">E1 = {html.escape(args.label_e1)} &middot; E2 = {html.escape(args.label_e2)}.<br>
Kunci jawaban dari pengamatan visual (<code>scripts/kunci_jawaban_eksplorasi.json</code>).
Shift yang seluruh kotaknya kosong tidak ditampilkan (semua benar di kedua eksplorasi).</p>
<p class="legend"><span style="background:var(--ok);color:var(--okink)">benar</span>
<span style="background:var(--bad);color:var(--badink)">salah</span>
<span style="background:var(--na)">&middot; = kosong</span></p>
<div class="box"><h2 style="margin-bottom:8px">Ringkasan</h2>{"".join(sm)}</div>
{"".join(sections)}
</main></body></html>"""
    Path(args.out).write_text(page, encoding="utf-8")
    print(f"{len(summary)} bagian -> {Path(args.out).resolve()} ({Path(args.out).stat().st_size / 1e6:.1f} MB)")


if __name__ == "__main__":
    main()
