"""
baca_field.py -- pembaca field header / Data Material EKSPLORASI 6 (TrOCR).

Tiap field dibaca dgn cara yg paling cocok utk bentuk isinya (semua dgn
TrOCR small yg sama dgn E4, tanpa pelatihan):

  shift    : 3 kandidat "1"/"2"/"3" dinilai (spt kode kotak E4) -> peluang.
  tanggal  : beam search dipaksa pola "<hari>, D-MM-YYYY" (trocr_regex),
             lalu diurai & dicek: tanggal valid? nama hari cocok dgn kalender?
  mesin    : beam search dipaksa pola kode mesin (1-2 huruf + 1-2 angka, mis.
             AN1, D16, DD16, AN01) -- hasil MENTAH, dicocokkan ke master mesin
             oleh aplikasi (MesinResolver), sama spt alur sekarang.
  speed,
  size     : PER KARAKTER (angka di field ini kecil & titik desimal sering
             hilang kalau dibaca 1 baris): komponen tinta dipisah, titik
             dikenali dari ukurannya, tiap digit dinilai 10 kandidat 0-9;
             satuan "MM" di Size dikenali dari jarak lebar setelah angka.
  operator : beam search dipaksa huruf/spasi (nama bebas) -- dicocokkan ke
             master operator oleh aplikasi (OperatorMatcher).
"""

import datetime as dt
import math
import re

import cv2
import numpy as np

from trocr_regex import RegexReader

POLA_TANGGAL = (r"(senin|selasa|rabu|kamis|jum'?at|sabtu|minggu) ?,? ?[0-3]?[0-9] ?[-./] ?"
                r"[01]?[0-9] ?[-./] ?20[0-9]{2}")
POLA_MESIN = r"[a-z]{1,2}[0-9]{1,2}"
POLA_OPERATOR = r"[a-z][a-z .']{1,30}"
HARI = ["senin", "selasa", "rabu", "kamis", "jumat", "sabtu", "minggu"]  # weekday() 0..6
DIGIT = [str(d) for d in range(10)]


def _posterior(scores):
    s = np.asarray(scores, float)
    p = np.exp(s - s.max())
    return p / p.sum()


class FieldReader:
    def __init__(self, reader):
        self.r = reader
        self.rx = RegexReader(reader)

    # ------------------------------------------------------------ shift
    def shift(self, img):
        sc = self.r.score_candidates(img, ["1", "2", "3"])
        p = _posterior(sc)
        i = int(np.argmax(p))
        return {"nilai": str(i + 1), "keyakinan": round(float(p[i]), 3)}

    # ---------------------------------------------------------- tanggal
    def tanggal(self, img):
        raw, skor = self.rx.read(img, POLA_TANGGAL)
        out = {"raw": raw, "skor": None if skor is None else round(skor, 3)}
        if raw is None:
            return {**out, "nilai": None, "valid": False}
        m = re.match(r"(?i)\s*([a-z']+)\s*,?\s*(\d{1,2})\s*[-./]\s*(\d{1,2})\s*[-./]\s*(\d{4})", raw)
        hari = m.group(1).lower().replace("'", "")
        d, mo, y = int(m.group(2)), int(m.group(3)), int(m.group(4))
        out["nilai"] = f"{hari.capitalize()}, {d}-{mo:02d}-{y}"
        try:
            tgl = dt.date(y, mo, d)
            out.update({"iso": tgl.isoformat(), "valid": True,
                        "hari_cocok_kalender": HARI[tgl.weekday()] == hari})
        except ValueError:
            out.update({"iso": None, "valid": False, "hari_cocok_kalender": None})
        return out

    # ------------------------------------------------------- teks pola
    def mesin(self, img):
        raw, skor = self.rx.read(img, POLA_MESIN)
        return {"nilai": raw.upper() if raw else None, "skor": None if skor is None else round(skor, 3)}

    def operator(self, img):
        raw, skor = self.rx.read(img, POLA_OPERATOR)
        return {"nilai": raw.strip().title() if raw else None,
                "skor": None if skor is None else round(skor, 3)}

    # ------------------------------------------------- angka per karakter
    @staticmethod
    def _karakter(img):
        g = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
        bw = (g < 140).astype(np.uint8) * 255
        n, _, st, _ = cv2.connectedComponentsWithStats(cv2.dilate(bw, np.ones((3, 3), np.uint8)), 8)
        boxes = sorted((list(map(int, st[i][:4])) for i in range(1, n) if st[i][4] > 25), key=lambda b: b[0])
        merged = []
        for b in boxes:  # bagian 1 karakter yg terpisah (bertumpuk di sumbu x) digabung
            if merged and b[0] < merged[-1][0] + merged[-1][2] - 0.5 * min(b[2], merged[-1][2]):
                m = merged[-1]
                x0, y0 = min(m[0], b[0]), min(m[1], b[1])
                merged[-1] = [x0, y0, max(m[0] + m[2], b[0] + b[2]) - x0, max(m[1] + m[3], b[1] + b[3]) - y0]
            else:
                merged.append(b)
        return merged

    @staticmethod
    def _glyph(img, b, pad=0.35):
        x, y, w, h = b
        s = max(w, h)
        p = int(s * pad)
        c = np.full((s + 2 * p, s + 2 * p, 3), 255, np.uint8)
        oy, ox = p + (s - h) // 2, p + (s - w) // 2
        c[oy:oy + h, ox:ox + w] = img[y:y + h, x:x + w]
        return c

    def angka(self, img, satuan=None):
        """Baca bilangan desimal per karakter. satuan: teks satuan (mis. "MM")
        yg ditempel bila ada tinta setelah jarak lebar."""
        bs = self._karakter(img)
        if not bs:
            return {"nilai": None}
        H = float(np.median([b[3] for b in bs]))
        unit_at = None
        gaps = [bs[i][0] - (bs[i - 1][0] + bs[i - 1][2]) for i in range(1, len(bs))]
        if satuan and gaps:
            mg = float(np.median(gaps))
            for i, gp in enumerate(gaps):
                if i >= 1 and gp > max(2.5 * mg, 0.6 * H):
                    unit_at = i + 1
                    break
        num = bs[:unit_at] if unit_at else bs
        teks, yakin = "", []
        for b in num:
            if b[3] < 0.4 * H:
                teks += "."
                continue
            parts = max(1, int(round(b[2] / (0.75 * H)))) if b[2] > 1.25 * H else 1
            for k in range(parts):  # angka yg menyambung (mis. "00") dibelah rata
                w = b[2] / parts
                sub = [int(b[0] + k * w), b[1], int(math.ceil(w)), b[3]]
                p = _posterior(self.r.score_candidates(self._glyph(img, sub), DIGIT))
                teks += DIGIT[int(np.argmax(p))]
                yakin.append(float(p.max()))
        out = {"raw": teks, "keyakinan_min": round(min(yakin), 3) if yakin else None,
               "n_karakter": len(bs)}
        try:
            out["angka"] = float(teks.strip(".")) if teks.strip(".") else None
        except ValueError:
            out["angka"] = None
        out["nilai"] = teks + (f" {satuan}" if unit_at else "")
        return out
