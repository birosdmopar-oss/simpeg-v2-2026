<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Push\TokenFcmService;
use App\Libraries\Push\TopikFcm;
use CodeIgniter\Queue\BaseJob;
use CodeIgniter\Queue\Interfaces\JobInterface;

/**
 * Job `fcm-topik` di antrean `push` (PRD Fase 6 §7.4, F6-12): worker `php spark queue:work push` yang sudah ada. Data
 * `{nip, tambah: list<topik>, lepas: list<topik>}` dari {@see \App\Libraries\Push\AntreTopikFcm} sesudah commit presensi/LKH.
 *
 * Token = DISTINCT `fcm_token` Android (`platform` = 2) milik `nip`, terbaru dulu [K `L:1834-1840`]; tanpa token → selesai
 * tanpa galat (legacy tidak mengantre). `lepas` dijalankan sebelum `tambah`. Gagal HTTP/koneksi → dilempar agar worker
 * mencoba ulang sampai {@see self::TRIES}, lalu `queue_jobs_failed`; batchAdd/batchRemove idempoten sehingga aman diulang.
 */
class FcmTopikJob extends BaseJob implements JobInterface
{
    public const ANTREAN = 'push';

    public const NAMA = 'fcm-topik';

    public const TRIES = 3;

    public const HASIL_SELESAI = 'selesai';

    public const HASIL_TANPA_TOKEN = 'tanpa_token';

    protected int $retryAfter = 60;

    protected int $tries = self::TRIES;

    public function process(): string
    {
        $nip    = (string) ($this->data['nip'] ?? '');
        $tokens = $nip === '' ? [] : array_values(array_unique(array_filter(array_map(
            static fn (array $r): string => trim((string) $r['fcm_token']),
            db_connect()->table('firebase_token')->select('fcm_token')
                ->where(['nip' => $nip, 'platform' => TokenFcmService::PLATFORM_ANDROID])->where('fcm_token IS NOT NULL', null, false)
                ->orderBy('created_at', 'DESC')->get()->getResultArray(),
        ), static fn (string $t): bool => $t !== '')));

        if ($tokens === []) {
            return self::HASIL_TANPA_TOKEN;
        }

        $topik = TopikFcm::dariLayanan();

        foreach ($this->daftar('lepas') as $t) {
            $topik->ubah($t, false, $tokens);
        }

        foreach ($this->daftar('tambah') as $t) {
            $topik->ubah($t, true, $tokens);
        }

        return self::HASIL_SELESAI;
    }

    /**
     * @return list<string>
     */
    private function daftar(string $kunci): array
    {
        $isi = $this->data[$kunci] ?? [];

        return array_values(array_filter(is_array($isi) ? $isi : [], static fn (mixed $t): bool => in_array($t, TopikFcm::TOPIK, true)));
    }
}
