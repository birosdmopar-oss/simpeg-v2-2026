<?php

declare(strict_types=1);

namespace App\Libraries\Push;

use App\Libraries\Integrasi\Http\KlienHttp;
use App\Libraries\Integrasi\Http\Permintaan;
use InvalidArgumentException;
use RuntimeException;

/**
 * Keanggotaan topik presensi FCM (PRD Fase 6 §7.4, F6-12; MAKE-066) — padanan antrean `andr_topic_queue` legacy
 * [K `L:1834-1878`, `L:1976-2044`, `L_lkh.php:430-445`]: token Android pegawai dimasukkan/dikeluarkan dari topik lewat
 * endpoint Instance ID `iid/v1:batchAdd|batchRemove` `{to: "/topics/<topik>", registration_tokens}`.
 *
 * - Auth: token OAuth service account dari {@see FcmV1Adapter::tokenAkses()} + header `access_token_auth: true` (cara
 *   Firebase Admin SDK; legacy memakai server key yang sudah dihentikan Google). HTTP 401 membuang token akses tersimpan.
 * - Token per permintaan maks. {@see self::BATAS} (batas Instance ID); POST tidak di-retry otomatis (BP-3) — retry = job.
 * - Galat per token (`results[].error`, mis. NOT_FOUND) dihitung, tidak menggagalkan job; token tidak dihapus (legacy juga
 *   tidak). Pengiriman pesan ke topik tetap di luar SIMPEG (Q6-11). Isi token & token akses tidak pernah masuk log.
 */
final class TopikFcm
{
    public const BLM_MASUK = 'pegawai_blm_masuk';

    public const BLM_PULANG = 'pegawai_blm_pulang';

    public const BLM_LKH = 'pegawai_blm_lkh';

    public const TOPIK = [self::BLM_MASUK, self::BLM_PULANG, self::BLM_LKH];

    public const URL_TAMBAH = 'https://iid.googleapis.com/iid/v1:batchAdd';

    public const URL_LEPAS = 'https://iid.googleapis.com/iid/v1:batchRemove';

    public const BATAS = 1000;

    public function __construct(private readonly KlienHttp $klien, private readonly FcmV1Adapter $fcm)
    {
    }

    /**
     * Instans dari Services; gagal bila driver push bukan `fcm` (mock tidak punya token akses).
     */
    public static function dariLayanan(): self
    {
        $fcm = service('pushNotifGateway');

        if (! $fcm instanceof FcmV1Adapter) {
            throw new RuntimeException('Topik FCM hanya tersedia dengan integrations.push.driver = fcm.');
        }

        return new self(service('klienHttp'), $fcm);
    }

    /**
     * @param list<string> $tokens token Android pegawai
     *
     * @return array{ok: int, galat: int}
     */
    public function ubah(string $topik, bool $tambah, array $tokens): array
    {
        if (! in_array($topik, self::TOPIK, true)) {
            throw new InvalidArgumentException('Topik FCM tidak dikenal.');
        }

        $hasil = ['ok' => 0, 'galat' => 0];

        foreach (array_chunk($tokens, self::BATAS) as $potong) {
            $akses   = $this->fcm->tokenAkses();
            $respons = $this->klien->kirim(new Permintaan(
                metode: 'POST',
                url: $tambah ? self::URL_TAMBAH : self::URL_LEPAS,
                layanan: 'fcm',
                operasi: $tambah ? 'topik_tambah' : 'topik_lepas',
                header: ['Authorization' => 'Bearer ' . $akses, 'access_token_auth' => 'true', 'Content-Type' => 'application/json'],
                json: ['to' => '/topics/' . $topik, 'registration_tokens' => $potong],
                refId: $topik,
                rahasia: [$akses, ...$potong],
            ));

            if ($respons->status === 401) {
                $this->fcm->lupakanTokenAkses();
            }

            if (! $respons->sukses()) {
                throw new RuntimeException("Topik FCM {$topik} gagal: HTTP {$respons->status}. Rincian teredaksi di integrasi_log.");
            }

            $daftar = $respons->json()['results'] ?? [];

            foreach (is_array($daftar) ? $daftar : [] as $r) {
                is_array($r) && isset($r['error']) ? $hasil['galat']++ : $hasil['ok']++;
            }
        }

        return $hasil;
    }
}
