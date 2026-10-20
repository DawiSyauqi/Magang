#!/usr/bin/env python3
"""
EKSPLORASI 6 -- layanan_e6.py
Layanan latar belakang "AI Baca Kertas": model TrOCR dimuat SEKALI, lalu
memproses antrian foto yg ditulis Laravel ke folder riwayat scan -- tanpa
web server/port, tanpa paket tambahan (cukup paket E6 yg sudah ada).

Antrian = folder (lihat app/Services/Scan/ScanStore.php):
    <root>/<YYYY-MM>/<id>/foto.jpg      foto asli dari petugas
    <root>/<YYYY-MM>/<id>/status.json   {"status": "menunggu" | "diproses" | "selesai" |
                                          "perlu_foto_ulang" | "gagal", ...}
    <root>/<YYYY-MM>/<id>/hasil-ai.json envelope SAMA dgn baca_fullpage_eksplorasi.py
    <root>/<YYYY-MM>/<id>/crops/        kanvas, field, dan kotak yg dibaca model
    <root>/layanan.json                 detak (heartbeat) -- Laravel menganggap
                                        layanan hidup bila diperbarui < 20 dtk lalu

Pemakaian:
    python layanan_e6.py --root ../../storage/app/scan-history [--idle-menit 30] [--device auto]
--idle-menit 0 = tidak pernah berhenti sendiri (disarankan di server produksi,
dijalankan lewat Task Scheduler "At startup"). Laravel menyalakan layanan ini
sendiri bila belum hidup saat ada foto baru.
"""

import argparse
import json
import os
import sys
import threading
import time
import traceback
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

from baca_fullpage_eksplorasi import DEFAULT_MODEL, TrOCRReader, baca_foto, load_referensi, print  # noqa: E402

DETAK_DETIK = 5        # seberapa sering layanan.json diperbarui saat menganggur
JEDA_PINDAI = 1.0      # jeda antar pemindaian antrian (detik)


def sekarang():
    return datetime.now().astimezone().isoformat(timespec="seconds")


def tulis_json(path, data):
    """Tulis atomik (file sementara + replace) supaya Laravel tidak pernah
    membaca JSON setengah jadi."""
    path = Path(path)
    tmp = path.with_name(path.name + f".{os.getpid()}.tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=1), encoding="utf-8")
    for _ in range(20):  # Windows: replace bisa gagal sesaat bila file sedang dibaca
        try:
            os.replace(tmp, path)
            return
        except PermissionError:
            time.sleep(0.05)
    os.replace(tmp, path)


def baca_json(path):
    try:
        return json.loads(Path(path).read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return None


def versi_kode():
    """Penanda versi kode layanan: waktu ubah terbaru file .py di folder E6.
    Bila berubah (git pull), layanan berhenti sendiri supaya dinyalakan ulang
    dengan kode baru (oleh Task Scheduler / Laravel) -- proses lama tidak terus
    memakai kode di memori."""
    folder = Path(__file__).parent
    return max(int(p.stat().st_mtime) for p in folder.glob("*.py"))


def pilih_device(pilihan):
    if pilihan != "auto":
        return pilihan
    try:
        import torch
        return "cuda" if torch.cuda.is_available() else "cpu"
    except Exception:  # noqa: BLE001
        return "cpu"


class Layanan:
    def __init__(self, args):
        self.root = Path(args.root).resolve()
        self.idle_detik = args.idle_menit * 60
        self.device = pilih_device(args.device)
        self.args = args
        self.ref = None
        self.reader = None
        self.mulai = sekarang()
        self.versi = versi_kode()
        self.diproses = 0
        self.galat_siap = None
        self.sedang = None
        self.terakhir_detak = 0.0
        self.kunci_detak = threading.Lock()
        self.berhenti = threading.Event()

    # ---------------------------------------------------------- detak & kunci
    def detak(self, paksa=False):
        with self.kunci_detak:
            if self.berhenti.is_set() or (not paksa and time.time() - self.terakhir_detak < DETAK_DETIK):
                return
            self.terakhir_detak = time.time()
            self._tulis_detak()

    def _detak_latar(self):
        # Membaca 1 foto di CPU bisa > 20 dtk; tanpa detak dari thread ini
        # Laravel mengira layanan mati di tengah proses dan menyalakan layanan kedua.
        while not self.berhenti.wait(DETAK_DETIK):
            self.detak()

    def _tulis_detak(self):
        tulis_json(self.root / "layanan.json", {
            "pid": os.getpid(), "mulai": self.mulai, "detak": sekarang(), "detak_unix": int(time.time()),
            "device": self.device, "model": self.args.trocr_model, "model_dimuat": self.reader is not None,
            "diproses": self.diproses, "sedang": self.sedang, "galat": self.galat_siap,
            "idle_menit": self.args.idle_menit, "versi_kode": self.versi,
        })

    def layanan_lain_hidup(self):
        info = baca_json(self.root / "layanan.json")
        if not info or info.get("pid") == os.getpid():
            return False
        return time.time() - int(info.get("detak_unix", 0)) < 20

    # ------------------------------------------------------------- model
    def reader_factory(self):
        if self.reader is None:
            self.reader = TrOCRReader(self.args.trocr_model, device=self.device, num_threads=self.args.threads)
            print(f"[layanan] model dimuat di {self.device} ({self.reader.load_seconds:.1f} dtk)")
            self.detak(paksa=True)
        return self.reader

    # ------------------------------------------------------------- antrian
    def cari_menunggu(self):
        daftar = []
        for st in self.root.glob("*/*/status.json"):
            data = baca_json(st)
            if data and data.get("status") == "menunggu":
                daftar.append((data.get("dibuat", ""), st.parent))
        daftar.sort()
        return [d for _, d in daftar]

    def pulihkan_terputus(self):
        """Foto berstatus 'diproses' saat layanan baru mulai = layanan sebelumnya
        mati di tengah jalan (ditutup paksa / PC restart) -> antrikan ulang."""
        for st in self.root.glob("*/*/status.json"):
            data = baca_json(st)
            if data and data.get("status") == "diproses":
                data.update({"status": "menunggu", "dipulihkan": sekarang()})
                tulis_json(st, data)
                print(f"[layanan] {st.parent.name}: terputus, diantrikan ulang")

    def proses(self, folder):
        st_path = folder / "status.json"
        status = baca_json(st_path) or {}
        if status.get("status") != "menunggu":
            return
        status.update({"status": "diproses", "mulai": sekarang(), "device": self.device, "pid": os.getpid()})
        tulis_json(st_path, status)
        self.sedang = folder.name
        self.detak(paksa=True)
        print(f"[layanan] memproses {folder.name}")

        t0 = time.time()
        crops = folder / "crops"
        crops.mkdir(exist_ok=True)
        # "shift" = hanya baris shift yang dipilih petugas saat unggah; "bertinta" = semua baris bertinta.
        baris = status.get("baris") or ("shift" if status.get("shift_paksa") else "bertinta")
        try:
            if self.ref is None:
                self.ref = load_referensi(self.args.ref_dir)
            env = baca_foto(str(folder / "foto.jpg"), self.ref, self.reader_factory,
                            shift_paksa=status.get("shift_paksa"), baris=baris,
                            save_dir=crops, stem="foto", trocr_model=self.args.trocr_model)
            meta = env.setdefault("meta", {})
            meta["device"] = self.device
            # Dicatat supaya Laravel bisa memastikan mode baca yang benar-benar dipakai.
            meta["baris_dipakai"] = baris
            meta["shift_paksa"] = status.get("shift_paksa")
            meta["versi_kode"] = self.versi
            tulis_json(folder / "hasil-ai.json", env)
            if env["status"] == "needs_retake":
                status.update({"status": "perlu_foto_ulang", "pesan": env.get("reason")})
            else:
                status.update({"status": "selesai", "pesan": None,
                               "n_kotak_ragu": env["data"]["n_kotak_ragu"],
                               "perlu_tinjau_grid": env["status"] == "needs_manual_review"})
            self.galat_siap = None
        except Exception as e:  # noqa: BLE001 -- 1 foto gagal tidak mematikan layanan
            traceback.print_exc()
            pesan = f"{type(e).__name__}: {e}"
            status.update({"status": "gagal", "pesan": pesan})
            if self.reader is None:  # gagal memuat model = lingkungan Python belum lengkap
                self.galat_siap = pesan
        status.update({"selesai": sekarang(), "detik_proses": round(time.time() - t0, 1)})
        tulis_json(st_path, status)
        self.diproses += 1
        self.sedang = None
        self.detak(paksa=True)
        print(f"[layanan] {folder.name}: {status['status']} ({status['detik_proses']} dtk)")

    # ------------------------------------------------------------- loop
    def jalan(self):
        self.root.mkdir(parents=True, exist_ok=True)
        if self.layanan_lain_hidup():
            print("[layanan] layanan lain sudah berjalan -- keluar.")
            return
        self.detak(paksa=True)
        threading.Thread(target=self._detak_latar, daemon=True).start()
        self.pulihkan_terputus()
        print(f"[layanan] mulai (root={self.root}, device={self.device}, idle={self.args.idle_menit} mnt)")
        terakhir_kerja = time.time()
        while True:
            if versi_kode() != self.versi:
                print("[layanan] kode layanan diperbarui -- berhenti supaya dinyalakan ulang dengan kode baru.")
                break
            antrian = self.cari_menunggu()
            if antrian:
                for folder in antrian:
                    if versi_kode() != self.versi:
                        break  # sisa antrian diproses layanan baru
                    self.proses(folder)
                terakhir_kerja = time.time()
                continue
            if self.idle_detik and time.time() - terakhir_kerja > self.idle_detik:
                print("[layanan] menganggur -- berhenti (dinyalakan lagi otomatis oleh Laravel).")
                break
            self.detak()
            time.sleep(JEDA_PINDAI)
        with self.kunci_detak:
            self.berhenti.set()
        info = baca_json(self.root / "layanan.json") or {}
        if info.get("pid") == os.getpid():
            info.update({"detak_unix": 0, "berhenti": sekarang()})
            tulis_json(self.root / "layanan.json", info)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", required=True, help="Folder riwayat scan (storage/app/scan-history).")
    ap.add_argument("--idle-menit", type=float, default=30)
    ap.add_argument("--device", default="auto", help="auto | cpu | cuda")
    ap.add_argument("--ref-dir", default=str(Path(__file__).parent / "reference"))
    ap.add_argument("--trocr-model", default=DEFAULT_MODEL)
    ap.add_argument("--threads", type=int, default=None)
    ap.add_argument("--log", default=None, help="Tulis log ke file ini (dipakai saat dinyalakan Laravel).")
    args = ap.parse_args()
    if args.log:
        # print() modul E6 & traceback menulis ke sys.stderr saat dipanggil -> ikut ke file.
        sys.stderr = open(args.log, "a", encoding="utf-8", buffering=1)
    Layanan(args).jalan()


if __name__ == "__main__":
    main()
