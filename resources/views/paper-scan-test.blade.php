<!DOCTYPE html>
<html>
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Tes Upload - Paper Scan</title>
</head>
<body style="font-family: sans-serif; max-width: 700px; margin: 40px auto;">
    <h2>Tes Upload Foto Kertas (halaman sementara, hapus setelah Tahap 6 jadi)</h2>

    <form id="uploadForm">
        <input type="file" name="photo" accept="image/*" required>
        <button type="submit">Analisa</button>
    </form>

    <div id="shiftSection" style="display:none; margin-top:20px; padding:10px; background:#fff3cd;">
        <p>Shift tidak terbaca jelas. Pilih shift:</p>
        <select id="shiftSelect">
            <option value="1">1</option>
            <option value="2">2</option>
            <option value="3">3</option>
        </select>
        <button id="confirmShiftBtn">Konfirmasi Shift</button>
    </div>

    <pre id="result" style="background:#f4f4f4; padding:15px; margin-top:20px; white-space:pre-wrap; word-break:break-all;"></pre>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        let currentToken = null;

        document.getElementById('uploadForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            document.getElementById('result').textContent = 'Memproses... (bisa beberapa puluh detik)';

            const res = await fetch('/paper-scan/analyze', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                body: formData,
            });
            const data = await res.json();
            document.getElementById('result').textContent = JSON.stringify(data, null, 2);

            document.getElementById('shiftSection').style.display =
                data.status === 'needs_shift_confirmation' ? 'block' : 'none';
            if (data.status === 'needs_shift_confirmation') {
                currentToken = data.token;
            }
        });

        document.getElementById('confirmShiftBtn').addEventListener('click', async () => {
            document.getElementById('result').textContent = 'Memproses...';

            const res = await fetch('/paper-scan/analyze/confirm-shift', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ token: currentToken, shift: document.getElementById('shiftSelect').value }),
            });
            const data = await res.json();
            document.getElementById('result').textContent = JSON.stringify(data, null, 2);
        });
    </script>
</body>
</html>