#!/usr/bin/env python3
"""
buat_laporan_docx_e6.py -- laporan evaluasi EKSPLORASI 6 (foto seluruh
halaman) dlm format .docx, gaya sama dgn "Evaluasi Visual Eksplorasi 4 vs 5"
(landscape, ringkasan, cara kerja, temuan, rekomendasi, lalu per foto:
overlay kanvas, tabel header, tabel Lost time per blok, galeri kotak salah).

Butuh hasil bandingkan_e6.py (output_e6/ringkasan.json, mentah/, crops/).

    ../eksplorasi4/.venv/Scripts/python buat_laporan_docx_e6.py
"""

import argparse
import io
import json
import sys
from pathlib import Path

import cv2
from docx import Document
from docx.enum.section import WD_ORIENT
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt, RGBColor

HERE = Path(__file__).parent
sys.path.insert(0, str(HERE))
sys.dont_write_bytecode = True
from geometri_fullpage import daftarkan, grid_lost_time, imread_exif_safe, ke_kanvas, load_referensi  # noqa: E402

INK = RGBColor(0x1D, 0x24, 0x33)
MUTED = RGBColor(0x5B, 0x64, 0x74)
RED = RGBColor(0xA3, 0x27, 0x1B)
WARN = RGBColor(0x8A, 0x5A, 0x00)
LINE, HEAD, OK, BAD = "D9DDE4", "EEF1F5", "E5F4EA", "FBE3E1"
JAM = {
    "1": ["07.00-08.00", "08.00-09.00", "09.00-10.00", "10.00-11.00",
          "11.00-12.00", "12.00-13.00", "13.00-14.00", "14.00-15.00"],
    "2": ["15.00-16.00", "16.00-17.00", "17.00-18.00", "18.00-19.00",
          "19.00-20.00", "20.00-21.00", "21.00-22.00", "22.00-23.00"],
}
FIELD_LABEL = {"tanggal": "Hari & Tanggal", "mesin": "Mesin", "shift": "Shift", "operator": "Operator",
               "size": "Size", "speed": "Speed (m/mnt)"}


# ------------------------------------------------------------------ helper docx
def _set(el, tag, **attrs):
    child = el.find(qn(tag))
    if child is None:
        child = OxmlElement(tag)
        el.append(child)
    for k, v in attrs.items():
        child.set(qn(k), str(v))
    return child


def style_cell(cell, fill=None, border=LINE, margin=(40, 60), valign="center"):
    tcPr = cell._tc.get_or_add_tcPr()
    borders = _set(tcPr, "w:tcBorders")
    for side in ("top", "left", "bottom", "right"):
        _set(borders, f"w:{side}", **{"w:val": "single", "w:sz": 4, "w:color": border})
    if fill:
        _set(tcPr, "w:shd", **{"w:val": "clear", "w:color": "auto", "w:fill": fill})
    mar = _set(tcPr, "w:tcMar")
    for side, v in (("top", margin[0]), ("bottom", margin[0]), ("left", margin[1]), ("right", margin[1])):
        _set(mar, f"w:{side}", **{"w:w": v, "w:type": "dxa"})
    _set(tcPr, "w:vAlign", **{"w:val": valign})


def cell_text(cell, text, bold=False, size=9, color=INK, align="left", mono=False, first=True):
    p = cell.paragraphs[0] if first else cell.add_paragraph()
    p.alignment = {"left": WD_ALIGN_PARAGRAPH.LEFT, "center": WD_ALIGN_PARAGRAPH.CENTER}[align]
    p.paragraph_format.space_after = Pt(0)
    r = p.add_run(text)
    r.bold = bold
    r.font.size = Pt(size)
    r.font.color.rgb = color
    if mono:
        r.font.name = "Consolas"
        r._element.rPr.rFonts.set(qn("w:eastAsia"), "Consolas")
    return p


def img_stream(img_bgr, quality=85):
    ok, buf = cv2.imencode(".jpg", img_bgr, [cv2.IMWRITE_JPEG_QUALITY, quality])
    return io.BytesIO(buf.tobytes())


def bullet(doc, bold, text):
    p = doc.add_paragraph(style="List Paragraph")
    p.paragraph_format.left_indent = Cm(0.6)
    p.paragraph_format.space_after = Pt(3)
    r0 = p.add_run("•  ")
    r0.font.color.rgb = MUTED
    if bold:
        r = p.add_run(bold + " ")
        r.bold = True
    p.add_run(text)
    return p


def para(doc, text, color=INK, size=None, italic=False):
    p = doc.add_paragraph()
    r = p.add_run(text)
    r.font.color.rgb = color
    r.italic = italic
    if size:
        r.font.size = Pt(size)
    return p


def simple_table(doc, header, rows, widths_cm):
    t = doc.add_table(rows=1 + len(rows), cols=len(header))
    t.alignment = WD_TABLE_ALIGNMENT.LEFT
    for j, h in enumerate(header):
        c = t.rows[0].cells[j]
        style_cell(c, fill=HEAD)
        cell_text(c, h, bold=True, size=9, align="center" if j else "left")
    for i, row in enumerate(rows, 1):
        for j, v in enumerate(row):
            c = t.rows[i].cells[j]
            fill = None
            if isinstance(v, (tuple, list)):
                v, fill = v
            style_cell(c, fill=fill)
            cell_text(c, str(v), size=9, align="center" if j else "left")
    for j, w in enumerate(widths_cm):
        for r in t.rows:
            r.cells[j].width = Cm(w)
    return t


# ------------------------------------------------------------------ data
def pct(s):
    a, b = s.split(" ")[0].split("/")
    return f"{s.split(' ')[0]} ({100 * int(a) / int(b):.1f}%)".replace(".", ",")


def e4_subset(e4_dir, kunci_lost):
    """Akurasi E1/E2/E4 (berisi, abaikan besar/kecil) per lembar dari hasil E4/E5."""
    p = Path(e4_dir) / "ringkasan.json"
    if not p.exists():
        return {}
    d = json.loads(p.read_text(encoding="utf-8"))
    out = {}
    for tag in ("e2", "e4_kandidat"):
        for lembar in ("lembar_padat", "lembar_jarang", None):
            ok = n = 0
            for r in d["per_foto"]:
                if lembar and kunci_lost["foto"][r["foto"]] != lembar:
                    continue
                v = r.get(tag + "_berisi_benar_ci")
                if v:
                    a, b = v.split("/")
                    ok += int(a)
                    n += int(b)
            out[(tag, lembar)] = (ok, n)
    return out


def fr(ok_n):
    ok, n = ok_n
    return f"{ok}/{n} ({100 * ok / n:.1f}%)".replace(".", ",")


def overlay(photo, ref, env):
    foto = imread_exif_safe(photo)
    reg = daftarkan(foto, ref)
    if reg["status"] != "success":
        return None
    k = ke_kanvas(foto, reg["H"])
    vis = k.copy()
    for name, (x0, y0, x1, y1) in ref["layout"]["field"].items():
        cv2.rectangle(vis, (x0, y0), (x1, y1), (200, 120, 0), 3)
        bb = env["data"]["header"].get(name, {}).get("bbox_kanvas")
        if bb:
            cv2.rectangle(vis, (bb[0], bb[1]), (bb[2], bb[3]), (0, 160, 0), 3)
    for s in ("1", "2", "3"):
        d = grid_lost_time(k, s, ref["layout"])
        ink = d.get("cell_ink") or [0] * 48
        for i in range(48):
            tl, tr = d["top_pts_px"][i], d["top_pts_px"][i + 1]
            bl, br = d["bot_pts_px"][i], d["bot_pts_px"][i + 1]
            col = (0, 0, 220) if ink[i] >= 0.03 else (0, 160, 0)
            pts = [tuple(int(v) for v in p) for p in (tl, tr, br, bl)]
            for a, b in zip(pts, pts[1:] + pts[:1]):
                cv2.line(vis, a, b, col, 2)
    s = 2400 / vis.shape[1]
    return cv2.resize(vis, None, fx=s, fy=s, interpolation=cv2.INTER_AREA)


# ------------------------------------------------------------------ bagian per foto
def header_table(doc, env, kunci, crops_dir, stem):
    t = doc.add_table(rows=1 + len(FIELD_LABEL), cols=5)
    for j, h in enumerate(["Field", "Kunci", "E6", "Crop yang dibaca", "Keterangan"]):
        style_cell(t.rows[0].cells[j], fill=HEAD)
        cell_text(t.rows[0].cells[j], h, bold=True, size=9)
    from bandingkan_e6 import norm
    for i, (f, label) in enumerate(FIELD_LABEL.items(), 1):
        res = env["data"]["header"].get(f, {})
        got, want = res.get("nilai"), kunci[f]
        benar = norm(f, got) == norm(f, want)
        cells = t.rows[i].cells
        for c in cells:
            style_cell(c)
        cell_text(cells[0], label, bold=True, size=9)
        cell_text(cells[1], "· (kosong)" if want is None else str(want), size=9, mono=True)
        style_cell(cells[2], fill=OK if benar else BAD)
        cell_text(cells[2], "· (kosong)" if got is None else str(got), size=9, mono=True, bold=not benar)
        img = crops_dir / f"{stem}_field_{f}.png"
        if img.exists():
            ih, iw = cv2.imread(str(img)).shape[:2]
            cells[3].paragraphs[0].add_run().add_picture(str(img), height=Cm(min(0.9, 7.5 * ih / iw)))
        else:
            cell_text(cells[3], "tidak ada tulisan — model tidak dipanggil", size=8, color=MUTED)
        ket = []
        if "keyakinan" in res:
            ket.append(f"yakin {res['keyakinan']:.2f}")
        if res.get("keyakinan_min") is not None:
            ket.append(f"digit paling ragu {res['keyakinan_min']:.2f}")
        if f == "tanggal" and res.get("valid") is not None:
            ket.append("tanggal valid" if res.get("valid") else "tanggal TIDAK valid")
            if res.get("hari_cocok_kalender") is False:
                ket.append("nama hari TIDAK cocok dgn kalender")
        if res.get("n_coretan_dibuang"):
            ket.append(f"{res['n_coretan_dibuang']} coretan dibuang")
        cell_text(cells[4], ", ".join(ket).replace(".", ",") or "–", size=8, color=MUTED)
    for r in t.rows:
        for j, w in enumerate([3.0, 4.2, 4.2, 8.0, 6.5]):
            r.cells[j].width = Cm(w)


def lost_table(doc, truth, blocks, shift):
    t = doc.add_table(rows=2, cols=4)
    for b in range(8):
        cell = t.rows[b // 4].cells[b % 4]
        style_cell(cell, margin=(80, 80), valign="top")
        cell.width = Cm(6.7)
        vals = blocks[b]["kotak"] if blocks else [None] * 6
        calls = {c["kolom"]: c for c in (blocks[b]["calls"] if blocks else [])}
        salah = any(truth[b][k] not in ("SKIP",) and (vals[k] or "").lower() != (truth[b][k] or "").lower()
                    for k in range(6))
        p = cell_text(cell, f"Blok {b} · {JAM[shift][b]}", bold=True, size=9)
        if salah:
            r = p.add_run("   ada salah")
            r.font.size = Pt(8)
            r.font.color.rgb = RED
        nt = cell.add_table(rows=4, cols=7)
        for j, h in enumerate(["", "1", "2", "3", "4", "5", "6"]):
            style_cell(nt.rows[0].cells[j], fill=HEAD, margin=(10, 20))
            cell_text(nt.rows[0].cells[j], h, size=7.5, align="center", mono=True)
        for i, (lbl, vv) in enumerate([("Kunci", truth[b]), ("E6", vals)], 1):
            style_cell(nt.rows[i].cells[0], fill=HEAD, margin=(10, 20))
            cell_text(nt.rows[i].cells[0], lbl, size=7.5, bold=True, mono=True)
            for k in range(6):
                v, g = vv[k], truth[b][k]
                c = nt.rows[i].cells[k + 1]
                fill = None
                if lbl == "E6" and g != "SKIP":
                    fill = OK if (v or "").lower() == (g or "").lower() and (v is None) == (g is None) else BAD
                style_cell(c, fill=fill, margin=(10, 20))
                txt = "·" if v is None else ("(skip)" if v == "SKIP" else str(v))
                cell_text(c, txt, size=7.5, align="center", mono=True, bold=(fill == BAD))
        style_cell(nt.rows[3].cells[0], fill=HEAD, margin=(10, 20))
        cell_text(nt.rows[3].cells[0], "ragu", size=7, mono=True, color=WARN)
        for k in range(6):
            c = nt.rows[3].cells[k + 1]
            style_cell(c, margin=(10, 20))
            cc = calls.get(k + 1)
            cell_text(c, "?" if cc and cc["ragu"] else "", size=7.5, align="center", mono=True,
                      color=WARN, bold=True)
        for r in nt.rows:
            r.cells[0].width = Cm(1.1)
            for k in range(1, 7):
                r.cells[k].width = Cm(0.85)


def wrong_gallery(doc, stem, shift, truth, blocks, crops_dir):
    wrong = []
    for b in range(8):
        for k in range(6):
            g = truth[b][k]
            v = blocks[b]["kotak"][k] if blocks else None
            if g == "SKIP" or ((v or "") == (g or "")):
                continue
            wrong.append((b, k, g, v))
    if not wrong:
        return
    doc.add_paragraph(f"Kotak shift {shift} yang tidak sama persis dengan kunci ({len(wrong)})", style="Heading 3")
    n_rows = (len(wrong) + 7) // 8
    t = doc.add_table(rows=n_rows, cols=8)
    for idx in range(n_rows * 8):
        cell = t.rows[idx // 8].cells[idx % 8]
        cell.width = Cm(3.3)
        if idx >= len(wrong):
            continue
        b, k, g, v = wrong[idx]
        style_cell(cell, valign="top")
        p = cell.paragraphs[0]
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        img = crops_dir / f"{stem}_s{shift}_b{b}_k{k + 1}.jpg"
        if img.exists():
            p.add_run().add_picture(str(img), width=Cm(2.2))
        cell_text(cell, f"Blok {b} kotak {k + 1}", size=8, bold=True, align="center", first=False)
        cell_text(cell, f"kunci: {'·' if g is None else g}", size=8, align="center", color=MUTED, first=False)
        pp = cell_text(cell, f"E6: {'·' if v is None else v}", size=8, align="center", color=RED,
                       mono=True, first=False)
        if v and g and v.lower() == g.lower():
            r = pp.add_run("  (huruf besar/kecil)")
            r.font.size = Pt(7)
            r.font.color.rgb = MUTED
            continue
        call = next((c for c in blocks[b]["calls"] if c["kolom"] == k + 1), None) if blocks else None
        if call:
            txt = (f"ditandai ragu · yakin {call['keyakinan']:.2f}" if call["ragu"]
                   else f"LOLOS · yakin {call['keyakinan']:.2f}").replace(".", ",")
            cell_text(cell, txt, size=7, align="center", color=WARN if call["ragu"] else RED,
                      bold=not call["ragu"], first=False)
            cell_text(cell, "3 kandidat: " + " / ".join(call["kandidat_3"]), size=7, align="center",
                      color=MUTED, first=False)


# ------------------------------------------------------------------ main
def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--hasil", default=str(HERE / "output_e6"))
    ap.add_argument("--images-dir", default=str(HERE.parent.parent / "fullimg"))
    ap.add_argument("--kunci-header", default=str(HERE / "kunci_fullpage_eksplorasi6.json"))
    ap.add_argument("--kunci-lost", default=str(HERE.parent / "kunci_jawaban_eksplorasi.json"))
    ap.add_argument("--hasil-e4", default=str(HERE.parent / "eksplorasi4" / "output_bandingkan_e4_e5_ak"))
    ap.add_argument("--narasi", default=str(HERE / "narasi_laporan_e6.json"))
    ap.add_argument("--out", default=str(HERE.parent.parent / "Laporan" / "Evaluasi Visual Eksplorasi 6.docx"))
    args = ap.parse_args()

    hasil = Path(args.hasil)
    data = json.loads((hasil / "ringkasan.json").read_text(encoding="utf-8"))
    ring = data["ringkasan"]
    kh = json.loads(Path(args.kunci_header).read_text(encoding="utf-8"))
    kl = json.loads(Path(args.kunci_lost).read_text(encoding="utf-8"))
    nar = json.loads(Path(args.narasi).read_text(encoding="utf-8"))
    e4 = e4_subset(args.hasil_e4, kl)
    ref = load_referensi()

    doc = Document()
    sec = doc.sections[0]
    sec.orientation = WD_ORIENT.LANDSCAPE
    sec.page_width, sec.page_height = Cm(29.7), Cm(21.0)
    for m in ("left_margin", "right_margin"):
        setattr(sec, m, Cm(1.6))
    sec.top_margin = sec.bottom_margin = Cm(1.4)
    st = doc.styles["Normal"]
    st.font.name = "Calibri"
    st.font.size = Pt(10)
    st.font.color.rgb = INK

    doc.add_paragraph("Evaluasi Visual Eksplorasi 6", style="Title")
    para(doc, nar["subjudul"], color=MUTED, size=11)

    # ---------------- ringkasan
    doc.add_paragraph("Ringkasan", style="Heading 1")
    for b in nar["ringkasan"]:
        bullet(doc, b[0], b[1])

    doc.add_paragraph("Hasil per field header (7 foto)", style="Heading 2")
    rows = []
    for f, lbl in FIELD_LABEL.items():
        ok, n = map(int, ring["header"][f].split("/"))
        rows.append([lbl, (f"{ok}/{n}", OK if ok == n else BAD), nar["cara_field"][f]])
    simple_table(doc, ["Field", "Benar", "Cara baca"], rows, [3.5, 2.0, 20.5])
    para(doc, nar["catatan_header"], color=MUTED, size=9, italic=True)

    doc.add_paragraph("Hasil Lost time (kotak berisi, abaikan huruf besar/kecil)", style="Heading 2")
    lt = ring
    rows = [
        ["E6 · foto utuh (fullimg, 7 foto)", pct(lt["lost_time_lembar_padat"]["berisi_ci"]),
         pct(lt["lost_time_lembar_jarang"]["berisi_ci"]), (pct(lt["lost_time_semua"]["berisi_ci"]), OK),
         pct(lt["lost_time_semua"]["berisi_eksak"])],
    ]
    if e4:
        rows.append(["E4 · foto close-up (images, 12 foto)", fr(e4[("e4_kandidat", "lembar_padat")]),
                     fr(e4[("e4_kandidat", "lembar_jarang")]), fr(e4[("e4_kandidat", None)]), "83,7%"])
        rows.append(["E2 · foto close-up (images, 12 foto)", fr(e4[("e2", "lembar_padat")]),
                     fr(e4[("e2", "lembar_jarang")]), fr(e4[("e2", None)]), "83,4%"])
    simple_table(doc, ["Metode · foto", "Lembar padat", "Lembar jarang", "Total", "Total (eksak)"], rows,
                 [7.5, 4.5, 4.5, 4.5, 3.5])
    para(doc, nar["catatan_lost"], color=MUTED, size=9, italic=True)

    doc.add_paragraph("Konfirmasi operator (ambang keyakinan 0,85, sama dengan hibrida E4)", style="Heading 2")
    h = ring["hibrida"]
    simple_table(doc, ["Kotak berisi", "Ditandai ragu", "Salah", "Salah tertangkap", "Benar akhir",
                       "Ragu: jawaban benar di 3 kandidat"],
                 [[str(h["kotak_berisi"]), h["ditandai_ragu"].replace(".", ","), str(h["salah"]),
                   f"{h['salah_tertangkap']}/{h['salah']}", (h["benar_akhir"].replace(".", ","), OK),
                   h["ragu_benar_di_top3"]]],
                 [3.5, 3.5, 2.5, 4.0, 3.0, 6.0])

    doc.add_paragraph("Waktu proses per foto (CPU, sekali jalan = 1 foto)", style="Heading 2")
    rows = []
    for r in data["header"]:
        rows.append([r["foto"], r["lembar"].replace("lembar_", ""), f"{r['geometri_dtk']:.1f}".replace(".", ","),
                     f"{r['field_dtk']:.1f}".replace(".", ","), f"{r['lost_time_dtk']:.1f}".replace(".", ","),
                     f"{r['total_dtk']:.1f}".replace(".", ",")])
    simple_table(doc, ["Foto", "Lembar", "Geometri (dtk)", "Header (dtk)", "Lost time (dtk)", "Total (dtk)"],
                 rows, [6.0, 2.5, 3.5, 3.5, 3.5, 3.5])
    para(doc, nar["catatan_waktu"], color=MUTED, size=9, italic=True)

    # ---------------- cara kerja, temuan, rekomendasi
    for key, title in (("cara_kerja", "Cara kerja Eksplorasi 6"), ("temuan", "Temuan"),
                       ("keterbatasan", "Keterbatasan"), ("rekomendasi", "Rekomendasi")):
        doc.add_paragraph(title, style="Heading 1")
        for item in nar[key]:
            if isinstance(item, dict) and "gambar" in item:
                img = HERE / item["gambar"]
                if img.exists():
                    doc.add_picture(str(img), width=Cm(item.get("lebar_cm", 16)))
                para(doc, item.get("keterangan", ""), color=MUTED, size=9, italic=True)
            else:
                bullet(doc, item[0], item[1])

    # ---------------- per foto
    mentah = hasil / "mentah"
    for r in data["header"]:
        stem = r["foto"]
        env = json.loads((mentah / f"{stem}.json").read_text(encoding="utf-8"))
        lembar = kh["foto"][stem]
        doc.add_page_break()
        doc.add_paragraph(f"Foto {stem}", style="Heading 1")
        g = env["meta"]["geometri"]
        para(doc, (f"{lembar.replace('_', ' ')} · status {env['status']} · {g['n_inlier']} titik cocok · "
                   f"formulir {100 * g['luas_formulir_frac']:.0f}% luas foto · {g['skala_px_per_kanvas']:.2f} px foto "
                   f"per px kanvas · total {r['total_dtk']:.1f} dtk").replace(".", ","), color=MUTED, size=9)
        photo = next(Path(args.images_dir).glob(f"{stem}.*"))
        ov = overlay(photo, ref, env)
        if ov is not None:
            doc.add_picture(img_stream(ov), width=Cm(24.0))
            para(doc, "Foto setelah dipetakan ke kanvas formulir. Kotak oranye = area field, kotak hijau = tulisan "
                      "yang ditemukan; baris Lost time: merah = kotak bertinta (dibaca), hijau = kosong.",
                 color=MUTED, size=8, italic=True)
        doc.add_paragraph("Header, Size, Speed, Operator", style="Heading 2")
        header_table(doc, env, kh["header"][lembar], hasil / "crops" / stem, stem)
        for s in ("1", "2"):
            truth = kl["lembar"][lembar][s]
            if all(v is None for b in truth for v in b):
                continue
            e = env["data"]["lost_time"].get(s, {})
            blocks = e.get("blocks")
            lr = next(x for x in data["lost_time"] if x["foto"] == stem and x["shift"] == s)
            doc.add_paragraph(f"Lost time shift {s} — kotak berisi benar {lr['berisi_benar_ci']} "
                              f"(abaikan besar/kecil) · {lr['ragu']} ditandai ragu", style="Heading 2")
            lost_table(doc, truth, blocks, s)
            wrong_gallery(doc, stem, s, truth, blocks, hasil / "crops" / stem)

    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    doc.save(out)
    print(f"Laporan -> {out.resolve()}")


if __name__ == "__main__":
    main()
