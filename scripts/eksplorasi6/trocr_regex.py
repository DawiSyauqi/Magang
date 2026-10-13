"""
trocr_regex.py -- TrOCR dgn jawaban DIPAKSA mengikuti pola (regex) per field.

Tiap langkah beam search hanya token yg membuat teks tetap AWALAN SAH dari
pola yg boleh dipilih (pustaka `regex`, partial=True); </s> hanya boleh bila
teks sudah cocok penuh. Jadi model tdk bisa menulis "Sabtu 13-0b-2O26" utk
tanggal atau huruf di kolom Speed; ia memilih bacaan sah yg paling mungkin.

Dipakai juga utk field teks bebas (operator) dgn pola longgar (huruf/spasi).
"""

import regex
import torch

CHARSET = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 ,.-/'"


class RegexReader:
    def __init__(self, reader):
        self.r = reader           # TrOCRReader (trocr_reader.py)
        self._vocab = None

    def vocab(self):
        """{token_id: teks permukaan} utk token yg hanya berisi CHARSET."""
        if self._vocab is None:
            ok = set(CHARSET)
            v = {}
            for tid in range(len(self.r.tok)):
                s = self.r._surface(tid)
                if s and len(s) <= 12 and set(s) <= ok:
                    v[tid] = s
            self._vocab = v
            first = {}
            for tid, s in v.items():
                first.setdefault(s[0], []).append(tid)
            self._by_first = first
        return self._vocab

    @torch.no_grad()
    def read(self, image_bgr, pattern, num_beams=5, max_new_tokens=24, ignorecase=True):
        """Return (teks, skor log-prob per token rata2). teks None bila gagal."""
        vocab = self.vocab()
        pat = regex.compile(pattern, regex.IGNORECASE if ignorecase else 0)
        r = self.r
        cache = {}

        def is_prefix(text):
            return pat.fullmatch(text, partial=True) is not None

        def allowed(batch_id, input_ids):
            ids = input_ids.tolist()
            body = [t for t in ids[1:] if t not in (r.bos_id, r.start_id)]
            text = "".join(r._surface(t) for t in body)
            if text in cache:
                return cache[text]
            norm = text.lstrip()
            res = []
            next_chars = [c for c in CHARSET if is_prefix(norm + c)] if norm else None
            for ch, tids in self._by_first.items():
                if norm and ch != " " and ch not in next_chars:
                    continue
                for t in tids:
                    if is_prefix((norm + vocab[t]).lstrip() if not norm else norm + vocab[t]):
                        res.append(t)
            if norm and pat.fullmatch(norm):
                res.append(r.eos_id)
            if len(ids) == 1 and r.bos_id is not None and r.bos_id != r.start_id:
                res.append(r.bos_id)
            res = res or [r.eos_id]
            cache[text] = res
            return res

        pv = r._pixels([image_bgr])
        out = r.model.generate(pv, num_beams=num_beams, num_return_sequences=1,
                               max_new_tokens=max_new_tokens, prefix_allowed_tokens_fn=allowed,
                               early_stopping=True, output_scores=True, return_dict_in_generate=True)
        text = r.tok.decode(out.sequences[0], skip_special_tokens=True).strip()
        score = float(out.sequences_scores[0]) if out.sequences_scores is not None else None
        if not pat.fullmatch(text):
            return None, score
        return text, score
