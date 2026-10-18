<?php

namespace App\Services\Scan;

use App\Services\PaperExtraction\MesinAliasStore;

/**
 * Pencocokan tulisan tangan di header formulir (hasil E6) ke data master,
 * pengganti MesinResolver + OperatorMatcher alur lama — TANPA Ollama:
 *
 * Mesin (tulisan → RESRCENO MFRESMAS), berurutan:
 *   1. alias tersimpan (storage/app/mesin_aliases.json, dikelola di Admin →
 *      Alias Mesin) — hasil konfirmasi petugas sebelumnya;
 *   2. kode sama setelah dirapikan ("dd 16", "DD-016" → DD16);
 *   3. kemiripan huruf (similar_text) dengan kode/nama mesin, hanya bila cukup yakin.
 *   Saat petugas menyimpan dengan mesin lain dari usulan, pasangan
 *   tulisan → kode disimpan sebagai alias (ingat()), jadi lembar berikutnya
 *   langsung benar.
 *
 * Operator (tulisan nama → NIK TMMESINOP): kemiripan nama (logika
 * OperatorMatcher lama), dipakai sebagai USULAN yang wajib diperiksa petugas
 * (E6 membaca nama operator kurang andal).
 */
class PencocokHeader
{
    public const MIN_MESIN = 80.0;

    public const MIN_OPERATOR = 70.0;

    public function __construct(private ?MesinAliasStore $alias = null)
    {
    }

    /**
     * @param  array<string, string>  $mesin  kode => nama
     * @return array{kode: ?string, sumber: ?string, skor: ?float} sumber: alias|kode|mirip|null
     */
    public function mesin(?string $tulisan, array $mesin): array
    {
        $kosong = ['kode' => null, 'sumber' => null, 'skor' => null];
        $raw = trim((string) $tulisan);
        if ($raw === '' || ! $mesin) {
            return $kosong;
        }

        if ($this->alias && ($hit = $this->alias->find($raw)) && isset($mesin[$hit['resrceno']])) {
            return ['kode' => $hit['resrceno'], 'sumber' => 'alias', 'skor' => 100.0];
        }

        if ($kode = E6ResultAdapter::cocokkanMesin($raw, array_keys($mesin))) {
            return ['kode' => $kode, 'sumber' => 'kode', 'skor' => 100.0];
        }

        $needle = $this->rapikan($raw);
        $terbaik = null;
        foreach ($mesin as $kode => $nama) {
            $skor = max($this->mirip($needle, $this->rapikan($kode)), $this->mirip($needle, $this->rapikan((string) $nama)));
            if (! $terbaik || $skor > $terbaik[1]) {
                $terbaik = [(string) $kode, $skor];
            }
        }

        return $terbaik && $terbaik[1] >= self::MIN_MESIN
            ? ['kode' => $terbaik[0], 'sumber' => 'mirip', 'skor' => round($terbaik[1], 1)]
            : $kosong;
    }

    /**
     * @param  array<string, string>  $operator  NIK => nama
     * @return array{nik: ?string, nama: ?string, skor: ?float, yakin: bool}
     */
    public function operator(?string $tulisan, array $operator): array
    {
        $raw = trim((string) $tulisan);
        if ($raw === '' || ! $operator) {
            return ['nik' => null, 'nama' => null, 'skor' => null, 'yakin' => false];
        }
        $needle = $this->rapikan($raw);
        $terbaik = null;
        foreach ($operator as $nik => $nama) {
            $skor = max($this->mirip($needle, $this->rapikan((string) $nama)), (string) $nik === $raw ? 100.0 : 0.0);
            if (! $terbaik || $skor > $terbaik[2]) {
                $terbaik = [(string) $nik, (string) $nama, $skor];
            }
        }

        return ['nik' => $terbaik[0], 'nama' => $terbaik[1], 'skor' => round($terbaik[2], 1), 'yakin' => $terbaik[2] >= self::MIN_OPERATOR];
    }

    /** Simpan tulisan → kode mesin sebagai alias (dipanggil setelah petugas menyimpan). */
    public function ingat(?string $tulisan, string $kode): bool
    {
        $raw = trim((string) $tulisan);
        if (! $this->alias || $raw === '' || strcasecmp($this->rapikan($raw), $this->rapikan($kode)) === 0) {
            return false; // tulisan sudah sama dengan kodenya — tidak perlu alias
        }
        $ada = $this->alias->find($raw);
        if ($ada && $ada['resrceno'] === $kode) {
            return false;
        }
        $this->alias->put($raw, $kode);

        return true;
    }

    private function rapikan(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($s)));
    }

    /** Skor 0–100, simetris (rata-rata dua arah) seperti OperatorMatcher lama. */
    private function mirip(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        similar_text($a, $b, $ab);
        similar_text($b, $a, $ba);

        return ($ab + $ba) / 2;
    }
}
