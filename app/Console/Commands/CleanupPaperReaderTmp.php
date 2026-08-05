<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupPaperReaderTmp extends Command
{
    protected $signature = 'paper-scan:cleanup-tmp {--ttl-minutes=30}';
    protected $description = 'Hapus foto sementara paper-reader-tmp yang lebih tua dari TTL (sesi shift-confirmation kedaluwarsa)';

    public function handle(): void
    {
        $ttlMinutes = (int) $this->option('ttl-minutes');
        $disk = Storage::disk('local');
        $now = now();
        $deleted = 0;

        foreach ($disk->files('paper-reader-tmp') as $file) {
            $lastModified = $disk->lastModified($file);
            $ageMinutes = $now->diffInMinutes(\Illuminate\Support\Carbon::createFromTimestamp($lastModified));

            if ($ageMinutes >= $ttlMinutes) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info("Selesai: {$deleted} foto sementara kedaluwarsa dihapus.");
    }
}