#!/usr/bin/env python3
"""
EKSPLORASI 2 (refine) -- terminal_test_refine_eksplorasi.py
Sama spt terminal_test.py, tp memakai grid hasil REFINE, crop per blok
diluruskan (perspective warp), + ink-gate & ink-hint (lihat
ollama_block_pipeline_refine_eksplorasi.py).

Cara pakai:
    python terminal_test_refine_eksplorasi.py --image path/foto.jpg --shift 1 \
        --ref-dir reference --model qwen2.5vl:3b
    # tanpa Ollama sama sekali (cek crop & deteksi tinta saja):
    python terminal_test_refine_eksplorasi.py --image path/foto.jpg --shift 1 --crops-only
"""

import argparse
import csv
import json
import sys
import time
from datetime import datetime
from pathlib import Path

import cv2

sys.path.insert(0, str(Path(__file__).parent))
from crop_blocks_refine_eksplorasi import crop_jam_blocks_refined  # noqa: E402
from ollama_block_pipeline_refine_eksplorasi import (  # noqa: E402
    ROW_BLOCK_LABELS, add_common_args, load_reference, read_block,
)
from refine_grid_eksplorasi import INK_EMPTY_THRESHOLD  # noqa: E402


def main():
    ap = argparse.ArgumentParser()
    add_common_args(ap)
    ap.add_argument("--ref-dir", default="reference")
    ap.add_argument("--output-dir", default="output_eksplorasi2")
    ap.add_argument("--crops-only", action="store_true",
                     help="Simpan crop + cetak deteksi tinta saja, tanpa memanggil Ollama.")
    ap.add_argument("--no-interactive", action="store_true")
    args = ap.parse_args()

    out_dir = Path(args.output_dir)
    out_dir.mkdir(exist_ok=True)

    ref_dir = Path(args.ref_dir)
    if not (ref_dir / "ref_template.npz").exists():
        print(f"ERROR: {ref_dir / 'ref_template.npz'} tidak ditemukan. Jalankan calibrate_reference_cli.py dulu.")
        sys.exit(1)
    kp_pts, des, ref_w, ref_h, anchors = load_reference(ref_dir)

    print(f"\n{'='*60}")
    print(f"EKSPLORASI 2 (REFINE) -- {Path(args.image).name} (shift {args.shift}, model={args.model})")
    print(f"prompt={args.prompt}, ink-gate={'OFF' if args.no_ink_gate else 'ON'}, ink-hint={'OFF' if args.no_ink_hint else 'ON'}, "
          f"overlay={'OFF' if args.no_overlay else 'ON'}")
    print(f"{'='*60}")

    t0 = time.time()
    det, crops = crop_jam_blocks_refined(args.image, args.shift, kp_pts, des, ref_w, ref_h, anchors,
                                         with_overlay=not args.no_overlay)
    if crops is None:
        print(f"Status geometri gagal: {det['status']} ({det.get('reason')})")
        return

    refine = det.get("refine", {})
    print(f"Geometri: status={det['status']}, confidence={det.get('confidence')}, "
          f"refined={det.get('refined')} ({refine.get('quality') or refine.get('reason')})")
    if not det.get("refined"):
        print("  [!] Refine gagal -- crop memakai posisi versi lama, ink-gate tdk aktif.")
    print()

    stem = Path(args.image).stem
    labels = ROW_BLOCK_LABELS[args.shift]
    results = []
    n_ok = n_skipped = n_manual_correct = n_manual_graded = 0

    for i, (crop, meta) in enumerate(zip(crops, det["block_crops_meta"])):
        crop_path = out_dir / f"{stem}_shift{args.shift}_refined_block{i}.jpg"
        cv2.imwrite(str(crop_path), crop)
        ink = meta.get("cell_ink")
        ink_str = " ".join("#" if r >= INK_EMPTY_THRESHOLD else "." for r in ink) if ink else "-"

        print(f"--- Blok {i} ({labels[i]}) ---")
        print(f"  Crop disimpan : {crop_path.resolve()}")
        print(f"  Tinta per kotak: {ink_str}")

        if args.crops_only:
            results.append({"block_idx": i, "cell_ink": ink})
            print()
            continue

        res = read_block(args, crop, meta, labels[i],
                         ink_gate=not args.no_ink_gate, ink_hint=not args.no_ink_hint)
        if res.get("validation", {}).get("n_kolom_ok"):
            n_ok += 1
        if res.get("skipped_ollama"):
            n_skipped += 1
        parsed = res.get("parsed") or {}
        if res.get("skipped_ollama"):
            print("  Ollama        : DILEWATI (6 kotak kosong)")
        else:
            print(f"  Ollama        : {res.get('elapsed_sec', 0):.1f}s")
        print(f"  Jam terbaca   : {parsed.get('jam_label', '-')}")
        print(f"  Kotak         : {res.get('kotak', res.get('error', '?'))}")
        gate = res.get("ink_gate", {})
        if gate.get("overridden"):
            print(f"  [ink-gate] jawaban model di kotak KOSONG dibuang: {gate['overridden']}")
        if gate.get("ink_but_null"):
            print(f"  [!] kotak {gate['ink_but_null']} ada tintanya tp model jawab null -- cek manual")
        if res.get("validation", {}).get("x_count_suspicious"):
            print(f"  [!] {res['validation']['n_x_in_block']} 'x' dalam 1 blok -- kemungkinan halusinasi.")

        manual = None
        if not args.no_interactive:
            while True:
                ans = input("  Sesuai foto asli (buka crop di atas)? [y/n]: ").strip().lower()
                if ans in ("y", "n"):
                    manual = (ans == "y")
                    n_manual_graded += 1
                    n_manual_correct += int(manual)
                    break
                print("  Jawab y atau n.")
        print()
        results.append({"block_idx": i, "response": res, "manual_grade": manual})

    elapsed_total = time.time() - t0
    print(f"{'='*60}")
    if args.crops_only:
        print(f"Crop & deteksi tinta selesai ({elapsed_total:.1f}s), Ollama tidak dipanggil.")
    else:
        print(f"RINGKASAN: {n_ok}/8 blok struktur valid | {n_skipped} blok dilewati (kosong) | "
              f"{n_manual_correct}/{n_manual_graded if n_manual_graded else '?'} manual benar | "
              f"waktu total {elapsed_total:.1f}s")
    print(f"{'='*60}\n")

    log_result(out_dir, args, det, results, elapsed_total, n_ok, n_skipped, n_manual_correct, n_manual_graded)


def log_result(out_dir, args, det, results, elapsed_total, n_ok, n_skipped, n_manual_correct, n_manual_graded):
    log_path = out_dir / "evaluation_log_refine.csv"
    is_new = not log_path.exists()
    refine = det.get("refine", {})
    row = {
        "timestamp": datetime.now().isoformat(timespec="seconds"),
        "image": Path(args.image).name,
        "shift": args.shift,
        "model": "-" if args.crops_only else args.model,
        "geometry_confidence": det.get("confidence"),
        "refined": det.get("refined"),
        "refine_quality": refine.get("quality") or refine.get("reason"),
        "ink_gate": not args.no_ink_gate,
        "ink_hint": not args.no_ink_hint,
        "overlay": not args.no_overlay,
        "prompt": args.prompt,
        "n_blocks_struktur_ok": n_ok,
        "n_blocks_skipped_empty": n_skipped,
        "n_blocks_manual_correct": n_manual_correct,
        "n_blocks_manual_graded": n_manual_graded,
        "elapsed_total_sec": round(elapsed_total, 1),
    }
    with open(log_path, "a", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=row.keys())
        if is_new:
            writer.writeheader()
        writer.writerow(row)

    detail_path = out_dir / f"{Path(args.image).stem}_shift{args.shift}_refined_detail.json"
    for r in results:
        r.get("response", {}).pop("crop_image_b64", None)
    with open(detail_path, "w") as f:
        json.dump(results, f, indent=2, ensure_ascii=False, default=str)

    print(f"Log ringkasan -> {log_path.resolve()}")
    print(f"Detail lengkap -> {detail_path.resolve()}")


if __name__ == "__main__":
    main()
