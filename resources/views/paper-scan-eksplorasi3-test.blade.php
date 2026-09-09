<!DOCTYPE html>
<html>
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Tes Eksplorasi 3 - Full Ollama (Tanpa Geometri)</title>
</head>
<body style="font-family: sans-serif; max-width: 800px; margin: 40px auto;">
    <h2>Tes Eksplorasi 3 (sementara, hapus setelah keputusan diambil)</h2>
    <p>Ide: TANPA crop geometri. Foto utuh (enhance saja) dikirim 8x ke Ollama,
       Ollama sendiri mencari baris shift & blok jam yang dimaksud.</p>

    <form id="uploadForm">
        <input type="file" name="photo" accept="image/*" required>
        <select name="shift" required>
            <option value="1">Shift 1 (Jam/Lost-time PERTAMA)</option>
            <option value="2">Shift 2 (Jam/Lost-time KEDUA)</option>
            <option value="3">Shift 3 (Jam/Lost-time KETIGA)</option>
        </select>
        <button type="submit">Analisa</button>
    </form>

    <div id="summary" style="margin-top:15px; font-weight:bold;"></div>
    <pre id="result" style="background:#f4f4f4; padding:15px; margin-top:20px; white-space:pre-wrap; word-break:break-all; max-height:600px; overflow:auto;"></pre>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        document.getElementById('uploadForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            document.getElementById('summary').textContent = '';
            document.getElementById('result').textContent = 'Memproses... (8 panggilan Ollama dgn foto utuh, bisa lebih lama dari eksplorasi 2)';

            const res = await fetch("{{ route('paper-scan.eksplorasi3.analyze') }}", {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                body: formData,
            });
            const data = await res.json();
            document.getElementById('result').textContent = JSON.stringify(data, null, 2);

            if (data.status === 'success' && data.meta) {
                document.getElementById('summary').textContent =
                    `Blok dgn PERSIS 6 kotak: ${data.meta.n_blocks_ok}/${data.meta.n_blocks_total} | ` +
                    `Blok dgn label jam COCOK (bukti Ollama tidak nyasar): ${data.meta.n_label_match}/${data.meta.n_blocks_total} | ` +
                    `Waktu: ${data.meta.elapsed_seconds}s`;
            }
        });
    </script>
</body>
</html>