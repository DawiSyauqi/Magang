# Fungsi & konstanta bersama skrip pemasangan E6 (di-dot-source, jangan dijalankan langsung).

$TORCH_VERSI = "2.12.0"
$TORCH_INDEX_CUDA = "https://download.pytorch.org/whl/cu126"   # CUDA 12.6: RTX 3060, driver NVIDIA >= 528 (disarankan terbaru)
$TORCH_INDEX_CPU = "https://download.pytorch.org/whl/cpu"
$MODEL_REPO = "microsoft/trocr-small-handwritten"
$PROYEK = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
$E6_DIR = Join-Path $PROYEK "scripts\eksplorasi6"
$VENV = Join-Path $E6_DIR ".venv"
$VENV_PY = Join-Path $VENV "Scripts\python.exe"
$MODEL_DIR = Join-Path $E6_DIR "model\trocr-small-handwritten"

# Cari Python 3.10 64-bit (kode & paket E6 diuji di 3.10.11).
function Cari-Python([string]$pilihan) {
    if ($pilihan) { return $pilihan }
    $kandidat = @()
    if (Get-Command py -ErrorAction SilentlyContinue) {
        try { $p = (& py -3.10 -c "import sys;print(sys.executable)" 2>$null); if ($LASTEXITCODE -eq 0 -and $p) { $kandidat += $p.Trim() } } catch {}
    }
    $kandidat += @(
        "$env:LOCALAPPDATA\Programs\Python\Python310\python.exe",
        "C:\Program Files\Python310\python.exe",
        "C:\Python310\python.exe"
    )
    foreach ($k in $kandidat) { if ($k -and (Test-Path $k)) { return $k } }
    $g = Get-Command python -ErrorAction SilentlyContinue
    if ($g) {
        $v = (& $g.Source -c "import sys;print('%d.%d' % sys.version_info[:2])")
        if ($v -eq "3.10") { return $g.Source }
        Write-Warning "python di PATH versi $v (disarankan 3.10). Pakai -Python <path python.exe> bila ingin memaksa."
    }
    throw "Python 3.10 64-bit tidak ditemukan. Pasang dari https://www.python.org/downloads/release/python-31011/ (atau paket\python-3.10.11-amd64.exe), centang 'Add python.exe to PATH'."
}

# Unduh snapshot model ke folder biasa (bukan cache HF) supaya bisa dibaca
# akun mana pun (mis. SYSTEM di Task Scheduler) tanpa internet.
function Unduh-Model([string]$py, [string]$tujuan) {
    New-Item -ItemType Directory -Force $tujuan | Out-Null
    $kode = "from huggingface_hub import snapshot_download as s; s('$MODEL_REPO', local_dir=r'$tujuan', ignore_patterns=['*.msgpack','*.h5','*.ot','README.md','.gitattributes'])"
    & $py -c $kode
    if ($LASTEXITCODE -ne 0) { throw "Gagal mengunduh model $MODEL_REPO" }
    foreach ($f in @("config.json", "preprocessor_config.json", "sentencepiece.bpe.model", "tokenizer_config.json")) {
        if (-not (Test-Path (Join-Path $tujuan $f))) { throw "Model tidak lengkap: $f tidak ada di $tujuan" }
    }
    if (-not ((Test-Path (Join-Path $tujuan "pytorch_model.bin")) -or (Test-Path (Join-Path $tujuan "model.safetensors")))) {
        throw "Model tidak lengkap: file bobot tidak ada di $tujuan"
    }
}
