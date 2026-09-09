<!DOCTYPE html>
<html>
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Tes Eksplorasi 2 - Hybrid</title>
    <style>
        body { font-family: sans-serif; max-width: 900px; margin: 40px auto; }
        .block-card { border:1px solid #ccc; border-radius:6px; padding:10px; margin-bottom:10px; display:flex; gap:15px; align-items:center; }
        .block-card img { max-width:280px; border:1px solid #999; }
        .block-card button { border:none; padding:6px 12px; border-radius:4px; cursor:pointer; margin-right:6px; }
        .block-card button.correct { background:#2e7d32; color:#fff; }
        .block-card button.wrong { background:#c62828; color:#fff; }
        .block-card.graded-correct { background:#e8f5e9; }
        .block-card.graded-wrong { background:#ffebee; }
        #summaryText { white-space:pre-wrap; background:#f4f4f4; border:1px dashed #999; padding:10px; margin-top:10px; font-family:monospace; }
        .metric-box { background:#f4f4f4; padding:12px; border-radius:6px; margin-top:15px; }
        .metric-box span { display:inline-block; margin-right:20px; }
    </style>
</head>
<body>
    <h2>Tes Eksplorasi 2 (hybrid: geometri kasar + Ollama baca 6 kotak/blok)</h2>

    <form id="uploadForm">
        <input type="file" name="photo" accept="image/*" required>
        <select name="shift" required>
            <option value="1">Shift 1</option>
            <option value="2">Shift 2</option>
            <option value="3">Shift 3</option>
        </select>
        <button type="submit">Analisa</button>
    </form>

    <div id="metricBox" class="metric-box" style="display:none;"></div>
    <div id="blocksContainer" style="margin-top:20px;"></div>

    <div id="summaryBox" style="display:none;">
        <button onclick="copySummary()">Salin Ringkasan Evaluasi</button>
        <div id="summaryText"></div>
    </div>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        let lastData = null;
        let grades = {}; // block_idx -> true/false

        document.getElementById('uploadForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            document.getElementById('metricBox').style.display = 'none';
            document.getElementById('blocksContainer').innerHTML = 'Memproses... (8 panggilan Ollama, bisa 1-2 menit)';
            document.getElementById('summaryBox').style.display = 'none';
            grades = {};

            const res = await fetch("{{ route('paper-scan.eksplorasi2.analyze') }}", {
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
            const c = document.getElementById('blocksContainer');
            c.innerHTML = '';

            if (data.status !== 'success') {
                m.innerHTML = `<b>Status: ${data.status}</b> — ${data.reason || data.message || ''}`;
                m.style.display = 'block';
                return;
            }

            m.innerHTML = `
                <span><b>Confidence geometri:</b> ${data.meta.detection_confidence}</span>
                <span><b>Blok dgn PERSIS 6 kotak:</b> ${data.meta.n_blocks_ok}/${data.meta.n_blocks_total}</span>
                <span><b>Waktu total:</b> ${data.meta.elapsed_seconds}s</span>
            `;
            m.style.display = 'block';

            data.data.blocks.forEach((b) => {
                const div = document.createElement('div');
                div.className = 'block-card';
                div.id = `block-${b.block_idx}`;
                const kotak = b.parsed ? JSON.stringify(b.parsed.kotak) : '(gagal: ' + (b.error || 'unknown') + ')';
                div.innerHTML = `
                    <img src="data:image/jpeg;base64,${b.crop_image_b64 || ''}">
                    <div style="flex:1;">
                        <b>Blok ${b.block_idx} (${b.expected_jam_label})</b><br>
                        Jam terbaca: ${b.parsed ? b.parsed.jam_label : '-'}<br>
                        Kotak: <code>${kotak}</code><br>
                        Waktu: ${b.elapsed_sec ? b.elapsed_sec.toFixed(1) : '-'}s<br>
                        <button class="correct" onclick="grade(${b.block_idx}, true)">✓ Sesuai foto</button>
                        <button class="wrong" onclick="grade(${b.block_idx}, false)">✗ Tidak sesuai</button>
                    </div>
                `;
                c.appendChild(div);
            });
        }

        function grade(idx, isCorrect) {
            grades[idx] = isCorrect;
            const el = document.getElementById(`block-${idx}`);
            el.classList.remove('graded-correct', 'graded-wrong');
            el.classList.add(isCorrect ? 'graded-correct' : 'graded-wrong');
            buildSummary();
        }

        function buildSummary() {
            const total = lastData.data.blocks.length;
            const graded = Object.keys(grades).length;
            const correct = Object.values(grades).filter(v => v).length;
            const text =
`=== Eksplorasi 2 (Hybrid: Geometri + Ollama 6-kotak/blok) ===
Confidence geometri: ${lastData.meta.detection_confidence}
Blok dgn struktur valid (persis 6 kotak): ${lastData.meta.n_blocks_ok}/${total}
Blok yang sudah dinilai manual: ${graded}/${total}
Blok BENAR (isi cocok foto asli): ${correct}/${graded || 1} (${graded ? (correct/graded*100).toFixed(1) : 0}%)
Waktu proses total: ${lastData.meta.elapsed_seconds} detik
Jumlah panggilan Ollama: ${total} (1 per blok jam, crop kecil)`;
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