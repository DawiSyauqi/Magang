"""
EKSPLORASI 3 -- prompt_full_photo.py
Prompt utk 1 panggilan = 1 blok jam, TAPI gambar yang dikirim adalah FOTO
UTUH (bukan crop) -- Ollama sendiri yang harus MENEMUKAN baris & blok yang
dimaksud, baru baca isinya.

SHIFT_ORDINAL_WORDS: krn section "Waktu Produksi" berisi 3 pasang baris
"Jam"+"Lost time" berurutan dari atas ke bawah (shift1, shift2, shift3) --
model diberi tahu urutan ke-berapa yg harus dicari, BUKAN diminta menebak
dari nilai "Shift" di header (supaya tidak tergantung field header yg
mungkin sendiri tidak terbaca).
"""

SHIFT_ORDINAL_WORDS = {
    "1": "PERTAMA (paling atas)",
    "2": "KEDUA (di tengah)",
    "3": "KETIGA (paling bawah)",
}


def build_block_prompt(shift, jam_label, block_position_desc):
    ordinal = SHIFT_ORDINAL_WORDS[str(shift)]
    return (
        "Kamu melihat FOTO UTUH form kertas industri \"LAPORAN PROSES DRAWING "
        "HARIAN\". Form ini punya banyak section; yang kamu perlukan HANYA "
        "section berjudul \"Waktu Produksi\" (biasanya di bagian bawah form, "
        "berupa tabel besar dgn banyak kotak kecil).\n\n"
        "Section \"Waktu Produksi\" berisi TEPAT 3 pasang baris \"Jam\" + "
        "\"Lost time\" tersusun dari atas ke bawah. Tugasmu HANYA berkaitan "
        f"dgn pasangan baris \"Jam\"+\"Lost time\" yang {ordinal} dari 3 "
        "pasangan itu -- ABAIKAN 2 pasangan baris lainnya sepenuhnya.\n\n"
        f"Di baris \"Jam\" pasangan itu, cari label jam \"{jam_label}\" "
        f"({block_position_desc}). Tepat DI BAWAH label jam itu, di baris "
        "\"Lost time\" yang sama, ada 6 KOTAK KECIL (1 kotak = 10 menit). "
        "Baca isi ke-6 kotak itu, urut kiri ke kanan.\n\n"
        "Kembalikan HANYA JSON (tanpa teks lain, tanpa markdown code fence), "
        "PERSIS format ini:\n"
        '{"jam_label_ditemukan": "' + jam_label + '", "kotak": [k1, k2, k3, k4, k5, k6]}\n\n'
        "ATURAN:\n"
        "1. \"jam_label_ditemukan\": transkrip label jam yg BENAR-BENAR kamu "
        "temukan di posisi itu (utk verifikasi kamu mencari section/baris yg "
        "benar -- kalau ternyata berbeda dari yg diminta, tetap laporkan apa "
        "adanya, JANGAN dipaksakan sama).\n"
        "2. \"kotak\" WAJIB PERSIS 6 elemen, urutan kiri ke kanan.\n"
        "3. Tiap elemen: 1 digit angka (0-8) opsional + 1 huruf kecil (mis. "
        "\"6a\", \"2f\", \"0\"), ATAU huruf \"x\" sendirian, ATAU null kalau "
        "kotak KOSONG.\n"
        "4. Huruf kecil \"l\" BEDA dari angka \"1\".\n"
        "5. JANGAN mengarang isi kotak. Ragu -> null.\n"
        "6. JANGAN salin kode dari kotak tetangga hanya krn bentuknya mirip.\n"
        "7. JANGAN tertukar dgn 2 pasangan baris \"Jam\"+\"Lost time\" LAIN "
        "di section yang sama -- pastikan kamu berada di pasangan baris yang "
        f"{ordinal}.\n"
        "8. \"kode\" dlm array WAJIB JSON string bertanda kutip ganda "
        "(termasuk \"0\"), atau null (tanpa kutip) kalau kosong.\n"
        "9. Output HARUS JSON valid saja, PERSIS 2 field \"jam_label_ditemukan\" "
        "dan \"kotak\".\n"
    )


ROW_BLOCK_LABELS = {
    "1": ["07.00 - 08.00", "08.00 - 09.00", "09.00 - 10.00", "10.00 - 11.00",
          "11.00 - 12.00", "12.00 - 13.00", "13.00 - 14.00", "14.00 - 15.00"],
    "2": ["15.00 - 16.00", "16.00 - 17.00", "17.00 - 18.00", "18.00 - 19.00",
          "19.00 - 20.00", "20.00 - 21.00", "21.00 - 22.00", "22.00 - 23.00"],
    "3": ["23.00 - 24.00", "24.00 - 01.00", "01.00 - 02.00", "02.00 - 03.00",
          "03.00 - 04.00", "04.00 - 05.00", "05.00 - 06.00", "06.00 - 07.00"],
}

BLOCK_POSITION_DESC = [
    "blok jam PERTAMA (paling kiri) di baris itu",
    "blok jam KEDUA dari kiri",
    "blok jam KETIGA dari kiri",
    "blok jam KEEMPAT dari kiri",
    "blok jam KELIMA dari kiri",
    "blok jam KEENAM dari kiri",
    "blok jam KETUJUH dari kiri",
    "blok jam KEDELAPAN (paling kanan) di baris itu",
]