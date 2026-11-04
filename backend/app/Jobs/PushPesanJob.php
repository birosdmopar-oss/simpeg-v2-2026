<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Push\PenerimaTokenFcm;
use App\Libraries\Push\PengirimPushFcm;
use CodeIgniter\Queue\BaseJob;
use CodeIgniter\Queue\Interfaces\JobInterface;
use RuntimeException;

/**
 * Job `push-pesan` di antrean `push` (PRD Fase 6 §7.4 F6-13, MAKE-066) — padanan `Cron::process_queue_andr_notif` legacy
 * [K `C:987`] untuk produsen {@see \App\Libraries\Push\AntrePushPesan} (titik sambung Halo Simpeg Fase 8). Data
 * `{nip: list, judul, isi, data, kanal}`; token Android per NIP dan/atau token web akun ber-NIP itu dicari saat job berjalan.
 *
 * Tanpa token → selesai tanpa galat. Semua kiriman gagal → dilempar agar worker mencoba ulang sampai {@see self::TRIES}
 * lalu `queue_jobs_failed` (legacy menghapus antrean sebelum kirim dan tidak pernah mencoba ulang). Token tidak sah dihapus.
 */
class PushPesanJob extends BaseJob implements JobInterface
{
    public const ANTREAN = 'push';

    public const NAMA = 'push-pesan';

    public const TRIES = 3;

    public const HASIL_TERKIRIM = 'terkirim';

    public const HASIL_TANPA_TOKEN = 'tanpa_token';

    protected int $retryAfter = 60;

    protected int $tries = self::TRIES;

    public function process(): string
    {
        $nips  = array_values(array_map('strval', is_array($this->data['nip'] ?? null) ? $this->data['nip'] : []));
        $kanal = (string) ($this->data['kanal'] ?? 'android');
        $data  = is_array($this->data['data'] ?? null) ? array_map('strval', $this->data['data']) : [];
        $db    = db_connect();
        $token = new PenerimaTokenFcm($db);

        $penerima = array_merge(
            $kanal === 'web' ? [] : $token->android($nips),
            $kanal === 'android' ? [] : $token->webAkunNip($nips),
        );

        if ($penerima === []) {
            return self::HASIL_TANPA_TOKEN;
        }

        $hasil = (new PengirimPushFcm(service('pushNotifGateway'), $db))->kirim($penerima, (string) ($this->data['judul'] ?? ''), (string) ($this->data['isi'] ?? ''), $data);

        if ($hasil['sukses'] === 0 && $hasil['gagal'] > 0) {
            throw new RuntimeException("Push pesan gagal: {$hasil['pesan']}");
        }

        return self::HASIL_TERKIRIM;
    }
}
