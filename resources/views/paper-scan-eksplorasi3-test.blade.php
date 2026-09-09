<!DOCTYPE html>
<html>
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Tes Eksplorasi 3 - Full Ollama</title>
    <style>
        body { font-family: sans-serif; max-width: 900px; margin: 40px auto; }
        #previewImg { max-width:100%; border:1px solid #ccc; margin:10px 0; }
        table { width:100%; border-collapse: collapse; margin-top:10px; }
        td, th { border:1px solid #ccc; padding:6px; font-size:14px; }
        tr.graded-correct { background:#e8f5e9; }
        tr.graded-wrong { background:#ffebee; }
        tr.label-mismatch { background:#fff3cd; }
        button.correct { background:#2e7d32; color:#fff; border:none; padding:4px 10px; border-radius:4px; cursor:pointer; }
        button.wrong { background:#c62828; color:#fff; border:none; padding:4px 10px; border-radius:4px; cursor:pointer; margin-left:4px; }
        .metric-box { background:#f4f4f4; padding:12px; border-radius:6px; margin-top:15px; }
        .metric-box span { display:inline-block; margin-right:20px; }
        #summaryText { white-space:pre-wrap; background:#f4f4f4; border:1px dashed #999; padding:10px; margin-top:10px; font-family:monospace; }
    </style>
</head>
<body>
    <h2>Tes Eksplorasi 3 (full Ollama, tanpa geometri)</h2>
    <p>Bandingkan hasil tiap baris dgn foto di atas (foto yang SAMA dikirim 8x ke Ollama).</p>

    <form id="uploadForm">
        <input type="file" name="photo" id="photoInput" accept="image/*" required>
        <select name="shift" required>
            <option value="1">Shift 1</option>
            <option value="2">Shift 2</option>
            <option value="3">Shift 3</option>
        </select>
        <button type="submit">Analisa</button>
    </form>

    <img id="previewImg" style="display:none;">
    <div id="metricBox" class="metric-box" style="display:none;"></div>
    <table id="resultTable" style="display:none;">
        <thead>
            <tr><th>Blok</th><th>Label diharapkan</th><th>Label ditemukan Ollama</th><th>Kotak terbaca</th><th>Waktu</th><th>Penilaian</th></tr>
        </thead>
        <tbody id="resultBody"></tbody>
    </table>

    <div id="summaryBox" style="display:none;">
        <button onclick="copySummary()">Salin Ringkasan Evaluasi</button>
        <div id="summaryText"></div>
    </div>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        let lastData = null;
        let grades = {};

        document.getElementById('photoInput').addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;
            const img = document.getElementById('previewImg');
            img.src = URL.createObjectURL(file);
            img.style.display = 'block';
        });

        document.getElementById('uploadForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            document.getElementById('metricBox').style.display = 'none';
            document.getElementById('resultTable').style.display = 'none';
            document.getElementById('summaryBox').style.display = 'none';
            grades = {};

            const res = await fetch("{{ route('paper-scan.eksplorasi3.analyze') }}", {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                body: formData,
            });
            const data = await res.json();
            lastData = data;
            render(data);
        });

        function render(data) {
            const m = document.getElementById('metricBox');
            if (data.status !== 'success') {
                m.innerHTML = `<b>Status: ${data.status}</b> — ${data.reason || data.message || ''}`;
                m.style.display = 'block';
                return;
            }

            m.innerHTML = `
                <span><b>Blok dgn PERSIS 6 kotak:</b> ${data.meta.n_blocks_ok}/${data.meta.n_blocks_total}</span>
                <span><b>Blok dgn label jam COCOK:</b> ${data.meta.n_label_match}/${data.meta.n_blocks_total}</span>
                <span><b>Waktu total:</b> ${data.meta.elapsed_seconds}s</span>
            `;
            m.style.display = 'block';

            const body = document.getElementById('resultBody');
            body.innerHTML = '';
            data.data.blocks.forEach((b) => {
                const found = b.parsed ? b.parsed.jam_label_ditemukan : '-';
                const mismatch = found !== b.expected_jam_label;
                const kotak = b.parsed ? JSON.stringify(b.parsed.kotak) : '(gagal: ' + (b.error || 'unknown') + ')';
                const tr = document.createElement('tr');
                tr.id = `row-${b.block_idx}`;
                if (mismatch) tr.classList.add('label-mismatch');
                tr.innerHTML = `
                    <td>${b.block_idx}</td>
                    <td>${b.expected_jam_label}</td>
                    <td>${found}${mismatch ? ' ⚠️' : ''}</td>
                    <td><code>${kotak}</code></td>
                    <td>${b.elapsed_sec ? b.elapsed_sec.toFixed(1) : '-'}s</td>
                    <td>
                        <button class="correct" onclick="grade(${b.block_idx}, true)">✓</button>
                        <button class="wrong" onclick="grade(${b.block_idx}, false)">✗</button>
                    </td>
                `;
                body.appendChild(tr);
            });
            document.getElementById('resultTable').style.display = 'table';
        }

        function grade(idx, isCorrect) {
            grades[idx] = isCorrect;
            const el = document.getElementById(`row-${idx}`);
            el.classList.remove('graded-correct', 'graded-wrong');
            el.classList.add(isCorrect ? 'graded-correct' : 'graded-wrong');
            buildSummary();
        }

        function buildSummary() {
            const total = lastData.data.blocks.length;
            const graded = Object.keys(grades).length;
            const correct = Object.values(grades).filter(v => v).length;
            const text =
`=== Eksplorasi 3 (Full Ollama, tanpa geometri) ===
Blok dgn struktur valid (persis 6 kotak): ${lastData.meta.n_blocks_ok}/${total}
Blok dgn label jam COCOK (bukti Ollama tidak nyasar): ${lastData.meta.n_label_match}/${total}
Blok yang sudah dinilai manual: ${graded}/${total}
Blok BENAR (isi cocok foto asli): ${correct}/${graded || 1} (${graded ? (correct/graded*100).toFixed(1) : 0}%)
Waktu proses total: ${lastData.meta.elapsed_seconds} detik
Jumlah panggilan Ollama: ${total} (1 per blok, FOTO UTUH dikirim berulang)`;
            document.getElementById('summaryText').textContent = text;
            document.getElementById('summaryBox').style.display = 'block';
        }

        function copySummary() {
            navigator.clipboard.writeText(document.getElementById('summaryText').textContent);
            alert('Ringkasan disalin ke clipboard.');
        }
    </script>
</body>
</html>