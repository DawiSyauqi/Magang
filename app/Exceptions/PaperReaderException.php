<?php

namespace App\Exceptions;

use Exception;

/**
 * Dilempar oleh PaperReaderService ketika proses pembacaan kertas gagal --
 * baik karena proses Python tidak bisa dijalankan sama sekali (exit code
 * ganjil, timeout, dsb) maupun karena skrip Python sendiri melaporkan
 * kegagalan lewat envelope {"success": false, ...}.
 *
 * $context berisi detail teknis (stderr, exit code, error_type dari Python)
 * untuk keperluan logging -- JANGAN ditampilkan mentah-mentah ke pengguna
 * akhir di layar (Tahap 6/7), cukup pesan generik + sarankan coba lagi/foto
 * ulang. Detail teknis ini yang masuk ke storage/logs/laravel.log.
 */
class PaperReaderException extends Exception
{
    /** @var array<string, mixed> */
    protected array $context;

    public function __construct(string $message, array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
