<?php

return [

    /*
    |--------------------------------------------------------------------
    | Python binary & script
    |--------------------------------------------------------------------
    | python_binary  : biasanya venv khusus di server GPU, JANGAN pakai
    |                  python system kalau opencv/pydantic di-install di
    |                  venv terpisah. Contoh:
    |                  /opt/paper-reader-venv/bin/python3
    | script_path    : path ke paper_reader_extract.py (letakkan di luar
    |                  public/, mis. base_path('scripts/paper_reader_extract.py')).
    */
    'python_binary' => env('PAPER_READER_PYTHON_BIN', '/usr/bin/python3'),
    'script_path' => env('PAPER_READER_SCRIPT_PATH', base_path('scripts/paper_reader_extract.py')),

    /*
    |--------------------------------------------------------------------
    | Batas waktu proses (detik)
    |--------------------------------------------------------------------
    | process_timeout : batas WALL-CLOCK utk keseluruhan proses (preprocessing
    |                    + ~24-144 panggilan Ollama tergantung mode). Mode E
    |                    (produksi, per-kotak) diukur ~160-300s per foto dari
    |                    hasil uji Tahap 2 -- beri jarak aman.
    | ollama_call_timeout : diteruskan ke skrip Python sbg --timeout, batas
    |                        SATU panggilan HTTP ke Ollama (jaga-jaga model
    |                        macet/hang di satu kotak).
    */
    'process_timeout' => (int) env('PAPER_READER_PROCESS_TIMEOUT', 900),
    'ollama_call_timeout' => (int) env('PAPER_READER_OLLAMA_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------
    | Ollama
    |--------------------------------------------------------------------
    | Ganti 'model' sesuai keputusan Tahap 1 kalau ternyata model lain yang
    | dipilih (bukan qwen2.5vl:7b).
    */
    'ollama' => [
        'base_url' => env('PAPER_READER_OLLAMA_URL', 'http://127.0.0.1:11434'),
        'model' => env('PAPER_READER_OLLAMA_MODEL', 'qwen2.5vl:7b'),
        'num_ctx' => (int) env('PAPER_READER_OLLAMA_NUM_CTX', 16384),
    ],

];
