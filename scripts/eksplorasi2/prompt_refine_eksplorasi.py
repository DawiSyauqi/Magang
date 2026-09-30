"""
EKSPLORASI 2 (refine) -- prompt_refine_eksplorasi.py
Prompt utk pipeline refine. Beda dgn prompt.py:
  - TIDAK ada contoh nilai kode di JSON (di prompt.py contohnya "6a"/"5b",
    dan model kecil terbukti MENYALIN contoh itu ke kotak yg isinya "x");
  - urusan "kotak mana kosong" sudah diputuskan deterministik oleh ink-gate,
    jadi prompt fokus ke MEMBACA isi kotak yg ditandai berisi;
  - template JSON dibangun per blok: kolom kosong sudah diisi null, kolom
    berisi diberi penanda PLACEHOLDER yg wajib diganti model.
"""

PLACEHOLDER = "BACA"

BLOCK_PROMPT_REFINE = (
    "Gambar ini adalah potongan 1 blok jam dari formulir produksi. Baris yang "
    "dibaca adalah baris \"Lost time\" di antara 2 GARIS CYAN horizontal. "
    "5 GARIS MERAH vertikal membagi baris itu menjadi 6 KOTAK, bernomor 1-6 "
    "(angka hijau) dari kiri ke kanan. Teks jam di bagian atas gambar "
    "hanya label, BUKAN isi kotak.\n\n"
    "Isi kotak adalah tulisan tangan operator, salah satu bentuk:\n"
    "  - kode: 1 angka (0-8) diikuti 1 huruf, mis. angka lalu huruf kecil; "
    "salin huruf besar/kecil persis seperti tertulis;\n"
    "  - angka saja (mis. \"0\");\n"
    "  - huruf \"x\" saja = penanda akhir rentang masalah panjang.\n\n"
    "ATURAN:\n"
    "1. Baca HANYA isi di dalam kotak itu sendiri (antara 2 garis merah dan "
    "2 garis cyan). Jangan menyalin isi kotak tetangga.\n"
    "2. Huruf kecil \"l\" berbeda dari angka \"1\"; perhatikan bentuknya.\n"
    "3. Kalau tulisan benar-benar tidak terbaca, tulis \"?\" (JANGAN menebak "
    "kode lain).\n"
    "4. Kembalikan HANYA JSON valid (tanpa teks lain, tanpa markdown), dengan "
    "PERSIS key: \"jam_label\", \"kolom_1\" s.d. \"kolom_6\". Nilai kolom "
    "berupa string bertanda kutip ganda atau null.\n"
)


def build_user_message_refine(filled, jam_label):
    """filled: list 6 bool dari ink-gate (None -> tanpa info tinta)."""
    if filled is None:
        return (
            "Baca 6 kotak sesuai instruksi. Kotak kosong -> null. Kembalikan JSON "
            "dgn key jam_label, kolom_1 s.d. kolom_6."
        )
    cols = [str(i + 1) for i, f in enumerate(filled) if f]
    lines = [f'  "jam_label": "{jam_label}"']
    for i, f in enumerate(filled):
        lines.append(f'  "kolom_{i + 1}": ' + (f'"{PLACEHOLDER}"' if f else "null"))
    template = "{\n" + ",\n".join(lines) + "\n}"
    return (
        f"Hasil deteksi otomatis: kotak yang BERISI tulisan = kotak {', '.join(cols)}; "
        f"kotak lain kosong.\n"
        f"Lengkapi template JSON berikut: ganti setiap \"{PLACEHOLDER}\" dengan isi "
        f"kotak tersebut PERSIS seperti tertulis. Kolom yang null biarkan null.\n\n"
        f"{template}"
    )
