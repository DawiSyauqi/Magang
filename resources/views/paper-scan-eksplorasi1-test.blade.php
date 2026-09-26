<!DOCTYPE html>
<html>
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Tes Eksplorasi 1 - Geometri Murni</title>
    <style>
        body { font-family: sans-serif; max-width: 900px; margin: 40px auto; }
        .metric-box { background:#f4f4f4; padding:12px; border-radius:6px; margin-top:15px; }
        .metric-box span { display:inline-block; margin-right:20px; }
        .eval-panel { background:#eef7ee; padding:15px; border-radius:6px; margin-top:20px; }
        .eval-panel button.correct { background:#2e7d32; color:#fff; }
        .eval-panel button.wrong { background:#c62828; color:#fff; }
        .eval-panel button { border:none; padding:6px 14px; border-radius:4px; cursor:pointer; margin-right:8px; }
        #overlayImg { max-width:100%; border:1px solid #ccc; margin-top:10px; }
        #summaryText { white-space:pre-wrap; background:#fff; border:1px dashed #999; padding:10px; margin-top:10px; font-family:monospace; }
    </style>
</head>
<body>
    <h2>Tes Eksplorasi 1 (geometri murni, tanpa Ollama)</h2>

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
    <img id="overlayImg" style="display:none;">

    <div id="evalPanel" class="eval-panel" style="display:none;">
        <strong>Penilaian manual:</strong> apakah kotak biru/hijau di overlay di atas
        MENGURUNG BARIS "Lost time" DAN 8 GARIS HIJAU TEBAL benar-benar di batas
        blok jam (bandingkan dgn foto asli)?
        <div style="margin-top:10px;">
            <button class="correct" onclick="gradeGeometry(true)">✓ Presisi / Benar</button>
            <button class="wrong" onclick="gradeGeometry(false)">✗ Meleset</button>
        </div>
        <div id="gradeResult" style="margin-top:10px; font-weight:bold;"></div>
    </div>

    <div id="summaryBox" style="display:none;">
        <button onclick="copySummary()">Salin Ringkasan Evaluasi</button>
        <div id="summaryText"></div>
    </div>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        let lastData = null;
        let manualGrade = null;

        document.getElementById('uploadForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            document.getElementById('metricBox').style.display = 'none';
            document.getElementById('overlayImg').style.display = 'none';
            document.getElementById('evalPanel').style.display = 'none';
            document.getElementById('summaryBox').style.display = 'none';
            manualGrade = null;

            const res = await fetch("{{ route('paper-scan.eksplorasi1.analyze') }}", {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                body: formData,
            });
            const data = await res.json();
            lastData = data;

            const m = document.getElementById('metricBox');
            if (data.status !== 'success' && data.status !== 'needs_manual_review') {
                m.innerHTML = `<b>Status: ${data.status}</b> — ${data.reason || data.message || ''}`;
                m.style.display = 'block';
                return;
            }

            m.innerHTML = `
                <span><b>Status:</b> ${data.status}</span>
                <span><b>Confidence:</b> ${data.data.confidence}</span>
                <span><b>Rotasi diterapkan:</b> ${data.data.orientation_rot}°</span>
                <span><b>Inlier homography:</b> ${data.meta.n_inliers}/${data.meta.n_good_matches} (${(data.meta.inlier_ratio*100).toFixed(1)}%)</span>
                <span><b>Selisih OCR vs homography:</b> ${data.meta.ocr_cross_check_diff ?? 'N/A (OCR gagal)'}</span>
                <span><b>Waktu:</b> ${data.meta.elapsed_seconds}s</span>
            `;
            m.style.display = 'block';

            const img = document.getElementById('overlayImg');
            img.src = 'data:image/jpeg;base64,' + data.data.overlay_image_b64;
            img.style.display = 'block';

            document.getElementById('evalPanel').style.display = 'block';
            document.getElementById('gradeResult').textContent = '';
        });

        function gradeGeometry(isCorrect) {
            manualGrade = isCorrect;
            document.getElementById('gradeResult').textContent = isCorrect
                ? '✓ Ditandai: presisi/benar' : '✗ Ditandai: meleset';
            buildSummary();
        }

        function buildSummary() {
            if (!lastData || manualGrade === null) return;
            const d = lastData;
            const text =
`=== Eksplorasi 1 (Geometri Murni) ===
Status: ${d.status}
Confidence otomatis: ${d.data.confidence}
Penilaian manual geometri: ${manualGrade ? 'BENAR/PRESISI' : 'MELESET'}
Inlier homography: ${d.meta.n_inliers}/${d.meta.n_good_matches} (${(d.meta.inlier_ratio*100).toFixed(1)}%)
Selisih cross-check OCR: ${d.meta.ocr_cross_check_diff ?? 'N/A'}
Waktu proses: ${d.meta.elapsed_seconds} detik
Jumlah panggilan Ollama: 0 (murni geometri)`;
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