"""
trocr_reader.py -- pembaca tulisan tangan TrOCR (pretrained, TANPA pelatihan).
Dipakai eksplorasi4 (per kotak) & eksplorasi5 (per blok; salinan file ini).

Dua mode:
  - bebas    : model.generate() biasa, model menulis apa saja.
  - kandidat : jawaban DIPAKSA mengikuti format kode yg sah di aplikasi
               (1 digit 0-8 + opsional 1 huruf sub-poin, ditambah "x" =
               penanda akhir kode yg durasinya panjang). Huruf sub-poin
               default a-k (legenda formulir, dikonfirmasi pemilik proyek);
               letters="abcdefghijklmnopqrstuvwxyz" = aturan regex
               ProblemCodeConverter (dipakai di uji pertama).
      * read_cell_candidates(): 1 kotak -> SEMUA kode sah dinilai
        (log-prob total urutan token, termasuk token akhir), ambil yg
        tertinggi. Eksak, bukan tebakan beam.
      * read_line_constrained(): 1 blok -> beam search dgn tata bahasa
        "<kode> <kode> ..." sebanyak n kode (n = jumlah kotak bertinta).

Model default: microsoft/trocr-small-handwritten (62 jt parameter, CPU ok).
Diunduh otomatis dari Hugging Face saat pertama dipakai (cache lokal).
"""

import re
import time

import cv2
import numpy as np
import torch
from PIL import Image

DEFAULT_MODEL = "microsoft/trocr-small-handwritten"

LETTERS = "abcdefghijk"            # huruf sub-poin di legenda formulir
LETTERS_AZ = "abcdefghijklmnopqrstuvwxyz"


def code_regex(letters=LETTERS):
    """Kode sah: angka 0-8 + opsional 1 huruf sub-poin (besar/kecil), atau "x"."""
    cls = re.escape(letters + letters.upper())
    return re.compile(rf"^(?:[0-8][{cls}]?|[xX])$")


def valid_codes(letters=LETTERS, case_variants=True):
    """Semua kode sah. case_variants: huruf kecil & besar (kunci memuat "2F")."""
    out = [str(d) for d in range(9)] + ["x"]
    for d in range(9):
        for ch in letters:
            out.append(f"{d}{ch}")
            if case_variants:
                out.append(f"{d}{ch.upper()}")
    return out


class TrOCRReader:
    def __init__(self, model_name=DEFAULT_MODEL, device="cpu", num_threads=None, letters=LETTERS):
        from transformers import TrOCRProcessor, VisionEncoderDecoderModel
        if num_threads:
            torch.set_num_threads(num_threads)
        t0 = time.time()
        self.model_name = model_name
        self.letters = letters
        self.code_re = code_regex(letters)
        self.processor = TrOCRProcessor.from_pretrained(model_name)
        self.model = VisionEncoderDecoderModel.from_pretrained(model_name).to(device).eval()
        self.device = device
        self.tok = self.processor.tokenizer
        cfg, gen = self.model.config, self.model.generation_config

        def _first(*vals):
            return next(v for v in vals if v is not None)
        self.start_id = _first(cfg.decoder_start_token_id, gen.decoder_start_token_id,
                               cfg.decoder.decoder_start_token_id)
        self.eos_id = _first(cfg.eos_token_id, gen.eos_token_id, self.tok.eos_token_id)
        self.pad_id = _first(cfg.pad_token_id, gen.pad_token_id, self.tok.pad_token_id)
        self.bos_id = self.tok.bos_token_id
        self._cand_cache = {}
        self._grammar_vocab = None
        self.load_seconds = time.time() - t0

    # ------------------------------------------------------------------ util
    def _pixels(self, images_bgr):
        pil = [Image.fromarray(cv2.cvtColor(im, cv2.COLOR_BGR2RGB)) for im in images_bgr]
        return self.processor(images=pil, return_tensors="pt").pixel_values.to(self.device)

    def _labels(self, text):
        """Urutan token yg DIHASILKAN model utk text: token isi + </s>.
        Catatan: model TrOCR ini TIDAK menghasilkan <s> setelah token start
        (cek generate(): [</s>, ▁2, a, </s>]), jadi <s> dari tokenizer dibuang."""
        ids = self.tok(text).input_ids
        return ids[1:] if ids and ids[0] == self.bos_id else ids

    def _surface(self, token_id):
        """Teks permukaan 1 token (penanda spasi BPE/sentencepiece -> spasi)."""
        t = self.tok.convert_ids_to_tokens(int(token_id))
        return t.replace("Ġ", " ").replace("▁", " ")

    # ----------------------------------------------------------------- bebas
    @torch.no_grad()
    def read_free(self, images_bgr, num_beams=4, max_new_tokens=16):
        pv = self._pixels(images_bgr)
        out = self.model.generate(pv, num_beams=num_beams, max_new_tokens=max_new_tokens)
        return [s.strip() for s in self.tok.batch_decode(out, skip_special_tokens=True)]

    # ------------------------------------------------------ kandidat (kotak)
    def _candidate_trie(self, candidates):
        """Kelompokkan prefiks unik per panjang: {L: [prefiks (tuple id)]}.
        Urutan decoder = [start] + label[:-1]; tiap prefiks cukup dihitung 1x."""
        key = tuple(candidates)
        if key not in self._cand_cache:
            seqs = [self._labels(c) for c in candidates]
            by_len = {}
            for s in seqs:
                for t in range(len(s)):
                    p = (self.start_id, *s[:t])
                    by_len.setdefault(len(p), set()).add(p)
            by_len = {L: sorted(v) for L, v in by_len.items()}
            self._cand_cache[key] = (seqs, by_len)
        return self._cand_cache[key]

    @torch.no_grad()
    def _encode(self, image_bgr):
        enc = self.model.encoder(pixel_values=self._pixels([image_bgr])).last_hidden_state
        proj = getattr(self.model, "enc_to_dec_proj", None)
        return proj(enc) if proj is not None else enc

    @torch.no_grad()
    def _next_logp(self, enc, prefixes):
        """log-softmax token berikutnya utk sekumpulan prefiks sama panjang."""
        ids = torch.tensor(prefixes, dtype=torch.long, device=self.device)
        dec = self.model.decoder  # TrOCRForCausalLM
        h = dec.model.decoder(input_ids=ids, encoder_hidden_states=enc.expand(len(prefixes), -1, -1)
                              ).last_hidden_state[:, -1]
        return torch.log_softmax(dec.output_projection(h).float(), -1)

    @torch.no_grad()
    def score_candidates(self, image_bgr, candidates):
        """Log-prob total tiap kandidat (termasuk token akhir </s>) utk 1 gambar."""
        seqs, by_len = self._candidate_trie(candidates)
        enc = self._encode(image_bgr)
        table = {}
        for L, prefixes in by_len.items():
            lp = self._next_logp(enc, prefixes)
            for p, row in zip(prefixes, lp):
                table[p] = row
        scores = np.empty(len(seqs))
        for i, s in enumerate(seqs):
            scores[i] = sum(float(table[(self.start_id, *s[:t])][s[t]]) for t in range(len(s)))
        return scores

    @torch.no_grad()
    def read_cell_candidates(self, image_bgr, candidates=None, case_variants=True, top_k=5, chunk=8):
        """Kandidat dgn log-prob tertinggi, dicari EKSAK secara best-first:
        skor parsial sebuah prefiks = batas atas skor semua kandidat di
        bawahnya (log-prob <= 0), jadi begitu kandidat LENGKAP muncul di puncak
        antrean, tdk ada kandidat lain yg bisa lebih tinggi. Hanya sebagian
        kecil prefiks yg perlu dihitung (score_candidates menghitung semua).
        Return (kode, skor, top_k [(kode, skor)])."""
        import heapq
        candidates = candidates or valid_codes(self.letters, case_variants)
        key = ("bf", tuple(candidates))
        if key not in self._cand_cache:
            children, complete = {}, {}
            for c in candidates:
                s = self._labels(c)
                for t in range(len(s)):
                    children.setdefault((self.start_id, *s[:t]), set()).add(s[t])
                complete[(self.start_id, *s)] = c
            self._cand_cache[key] = (children, complete)
        children, complete = self._cand_cache[key]

        enc = self._encode(image_bgr)
        heap = [(-0.0, (self.start_id,))]
        found = []
        while heap and len(found) < top_k:
            if heap[0][1] in complete:
                neg, p = heapq.heappop(heap)
                found.append((complete[p], round(-neg, 3)))
                continue
            batch = []  # perluas beberapa prefiks teratas sekaligus (sama panjang)
            while heap and len(batch) < chunk and heap[0][1] not in complete:
                if batch and len(heap[0][1]) != len(batch[0][1]):
                    break
                batch.append(heapq.heappop(heap))
            lp = self._next_logp(enc, [p for _, p in batch])
            for (neg, p), row in zip(batch, lp):
                for tok in children[p]:
                    heapq.heappush(heap, (neg - float(row[tok]), (*p, tok)))
        return found[0][0], found[0][1], found

    # ------------------------------------------------ kandidat (baris/blok)
    def _grammar_tokens(self):
        """Token kosakata yg isinya hanya karakter kode/spasi (prefilter)."""
        if self._grammar_vocab is None:
            ok = re.compile(r"^ ?[0-8a-zA-Z]{1,2}$|^ $")
            vocab = {}
            for tid in range(len(self.tok)):
                s = self._surface(tid)
                if ok.match(s):
                    vocab[tid] = s
            self._grammar_vocab = vocab
        return self._grammar_vocab

    def _grammar_ok(self, text, n_codes, final):
        """Apakah text prefix sah dari tepat n_codes kode dipisah spasi."""
        if text.startswith(" "):
            text = text[1:]
        if "  " in text:
            return False
        parts = text.split(" ")
        trailing = text.endswith(" ")
        if trailing:
            complete = parts[:-1]
            last = None
        else:
            complete, last = parts[:-1], parts[-1]
        if any(not self.code_re.match(p) for p in complete):
            return False
        if last is not None and last != "" and not self.code_re.match(last):
            return False  # semua prefix kode sah juga kode sah
        n_done = len(complete) + (1 if last else 0)
        if final:
            return not trailing and last is not None and bool(self.code_re.match(last)) and n_done == n_codes
        if trailing and len(complete) >= n_codes:
            return False
        return n_done <= n_codes

    @torch.no_grad()
    def read_line_constrained(self, image_bgr, n_codes, num_beams=6, max_new_tokens=None):
        """Baca 1 baris berisi tepat n_codes kode. Return list n_codes kode."""
        vocab = self._grammar_tokens()
        special_head = {self.start_id}
        max_new_tokens = max_new_tokens or 4 * n_codes + 4

        def allowed(batch_id, input_ids):
            ids = input_ids.tolist()
            body = [t for t in ids[1:] if t != self.bos_id]
            if len(ids) == 1 and self.bos_id is not None and self.bos_id != self.start_id:
                # TrOCR memprediksi <s> dulu setelah token start
                return [self.bos_id] + [t for t, s in vocab.items() if self._grammar_ok(s, n_codes, False)]
            text = "".join(self._surface(t) for t in body if t not in special_head)
            res = [t for t, s in vocab.items() if self._grammar_ok(text + s, n_codes, False)]
            if self._grammar_ok(text, n_codes, True):
                res.append(self.eos_id)
            return res or [self.eos_id]

        pv = self._pixels([image_bgr])
        out = self.model.generate(pv, num_beams=num_beams, max_new_tokens=max_new_tokens,
                                  prefix_allowed_tokens_fn=allowed, early_stopping=True)
        text = self.tok.decode(out[0], skip_special_tokens=True).strip()
        codes = text.split()
        if len(codes) != n_codes:  # mestinya tdk terjadi; jaga2 kalau beam habis
            codes = (codes + ["?"] * n_codes)[:n_codes]
        return codes, text


# ---------------------------------------------------------------------------
# Praproses
# ---------------------------------------------------------------------------

def flatten_background(img_bgr, ksize_frac=0.12):
    """Ratakan pencahayaan kertas: bagi gambar dgn estimasi latar (closing
    morfologi dgn kernel > tebal coretan -> tinta hilang, sisa latar). Latar
    jadi ~seragam sehingga normalize_contrast() memutihkan SELURUH kertas
    (tanpa ini sisa kotak abu-abu tampak spt tepi garis bagi OCR)."""
    g = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)
    k = max(7, int(min(g.shape) * ksize_frac)) | 1
    bg = cv2.morphologyEx(g, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (k, k)))
    bg = cv2.GaussianBlur(bg, (k, k), 0).astype(np.float32)
    out = np.clip(g.astype(np.float32) / np.maximum(bg, 1) * 255, 0, 255).astype(np.uint8)
    return cv2.cvtColor(out, cv2.COLOR_GRAY2BGR)


def normalize_contrast(img_bgr, lo_pct=1, bg_pct=90):
    """Regangkan kontras: latar kertas (persentil 90) -> putih, tinta
    tergelap (persentil 1) -> hitam, output abu-abu 3 kanal. TrOCR dilatih
    pada tulisan gelap di kertas putih; foto kertas yg abu-abu membuat "0"
    sering terbaca "1a"/"x" (uji tuning: 47/55 vs 42/55 tanpa ini)."""
    g = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY).astype(np.float32)
    bg, lo = np.percentile(g, bg_pct), np.percentile(g, lo_pct)
    out = np.clip((g - lo) / max(1.0, bg - lo), 0, 1) * 255
    return cv2.cvtColor(out.astype(np.uint8), cv2.COLOR_GRAY2BGR)


def remove_grid_lines(img_bgr, v_lines_x=None, h_lines_y=None, band_frac=0.06, line_len_frac=0.55):
    """Putihkan piksel GARIS LURUS panjang (morfologi) yg letaknya dekat posisi
    garis cetak yg diketahui. v_lines_x/h_lines_y: posisi garis (piksel crop);
    None = semua garis lurus panjang di mana pun. Tinta tulisan di luar pita
    garis tdk disentuh, jadi angka "1" di tengah kotak aman."""
    gray = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)
    h, w = gray.shape
    bw = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_MEAN_C, cv2.THRESH_BINARY_INV,
                               (max(15, int(min(h, w) * 0.25)) // 2) * 2 + 1, 12)
    lines = np.zeros_like(bw)
    if h_lines_y is not None or v_lines_x is None:
        k = max(5, int(w * line_len_frac)) if h_lines_y is None else max(5, int(w * 0.3))
        hm = cv2.morphologyEx(bw, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_RECT, (k, 1)))
        if h_lines_y is not None:
            band = np.zeros_like(hm)
            bh = max(2, int(h * band_frac))
            for y in h_lines_y:
                band[max(0, int(y - bh)):int(y + bh + 1), :] = 255
            hm = cv2.bitwise_and(hm, band)
        lines |= hm
    if v_lines_x is not None or h_lines_y is None:
        span = h if h_lines_y is None else (max(h_lines_y) - min(h_lines_y))
        k = max(5, int(span * line_len_frac))
        vm = cv2.morphologyEx(bw, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_RECT, (1, k)))
        if v_lines_x is not None:
            band = np.zeros_like(vm)
            bwid = max(2, int(w * band_frac / max(1, len(v_lines_x) - 1)))
            for x in v_lines_x:
                band[:, max(0, int(x - bwid)):int(x + bwid + 1)] = 255
            vm = cv2.bitwise_and(vm, band)
        lines |= vm
    lines = cv2.dilate(lines, np.ones((3, 3), np.uint8))
    out = img_bgr.copy()
    bg = np.percentile(gray, 90)
    out[lines > 0] = (bg, bg, bg)
    return out
