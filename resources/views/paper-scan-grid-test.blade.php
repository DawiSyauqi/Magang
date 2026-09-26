<!DOCTYPE html>
<html>
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Grid Resolution Test - Paper Scan</title>
    <style>
        body { font-family: sans-serif; max-width: 800px; margin: 40px auto; padding: 0 20px; }
        .result-box { background: #f4f4f4; border: 1px solid #ddd; padding: 15px; border-radius: 6px; margin-top: 20px; white-space: pre-wrap; word-break: break-all; font-family: monospace; }
        .loading { color: #0d6efd; font-weight: bold; }
    </style>
</head>
<body>
    <h2>Grid Resolution Test (Tahap O-lanjutan)</h2>
    <p>Uji perbandingan <strong>A (Full Page Direct)</strong> vs <strong>B (Auto Crop Grid)</strong>.</p>

    <form id="testForm">
        <input type="file" name="photo" accept="image/*" required>
        <button type="submit">Jalankan Uji</button>
    </form>

    <div id="status"></div>
    <pre id="resultBox" class="result-box" style="display:none;"></pre>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        document.getElementById('testForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const statusEl = document.getElementById('status');
            const resultBox = document.getElementById('resultBox');

            statusEl.className = 'loading';
            statusEl.textContent = 'Memproses... (menjalankan 2 skenario Ollama berturut-turut, mohon tunggu)';
            resultBox.style.display = 'none';

            try {
                const res = await fetch('{{ route("paper-scan.grid-test.run") }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken },
                    body: formData,
                });
                const data = await res.json();
                statusEl.textContent = '';
                resultBox.style.display = 'block';
                resultBox.textContent = JSON.stringify(data, null, 2);
            } catch (err) {
                statusEl.className = '';
                statusEl.innerHTML = '<span style="color:red">Terjadi kesalahan: ' + err.message + '</span>';
            }
        });
    </script>
</body>
</html>
