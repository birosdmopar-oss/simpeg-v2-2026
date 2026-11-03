<?php

declare(strict_types=1);

namespace App\Libraries\Push;

use App\Jobs\FcmTopikJob;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Queue\Interfaces\QueueInterface;
use Throwable;

/**
 * Produsen job `fcm-topik` (PRD Fase 6 §7.4, F6-12) — dipanggil SESUDAH commit presensi/LKH; aturan topik ikut legacy:
 *
 * - presensi masuk [K `L:1834-1878`]: lepas `pegawai_blm_masuk`, tambah `pegawai_blm_pulang`;
 * - presensi pulang [K `L:1976-2044`]: lepas `pegawai_blm_pulang`, tambah `pegawai_blm_masuk`; LKH tanggal itu sudah ada
 *   (`riwayat_lckh` nip + tgl_laporan) → lepas `pegawai_blm_lkh`, belum → tambah `pegawai_blm_lkh`;
 * - LKH dibuat [K `L_lkh.php:430-445`]: lepas `pegawai_blm_lkh`.
 *
 * Hanya mengantre bila `integrations.push.driver = fcm` (driver mock tidak punya topik). Kegagalan mengantre dicatat sebagai
 * peringatan dan TIDAK dilempar — presensi/LKH tetap berhasil (AC-04.3).
 */
final class AntreTopikFcm
{
    /** @var Closure(): QueueInterface */
    private Closure $antrean;

    private string $driver;

    /**
     * @param (Closure(): QueueInterface)|null $antrean override antrean (test)
     * @param string|null                      $driver  override driver push (test); bawaan `integrations.push.driver`
     */
    public function __construct(?Closure $antrean = null, ?string $driver = null)
    {
        $this->antrean = $antrean ?? static fn (): QueueInterface => service('queue');
        $this->driver  = $driver ?? config(\Config\Integrations::class)->push['driver'];
    }

    public function presensi(BaseConnection $db, string $nip, string $tgl, bool $masuk): void
    {
        if ($masuk) {
            $this->antre($nip, [TopikFcm::BLM_PULANG], [TopikFcm::BLM_MASUK]);

            return;
        }

        try {
            $adaLkh = $db->table('riwayat_lckh')->where(['nip' => $nip, 'tgl_laporan' => $tgl])->countAllResults() > 0;
        } catch (Throwable $e) {
            log_message('warning', 'Topik FCM presensi pulang tidak diantre: {pesan}', ['pesan' => $e->getMessage()]);

            return;
        }

        $this->antre(
            $nip,
            $adaLkh ? [TopikFcm::BLM_MASUK] : [TopikFcm::BLM_MASUK, TopikFcm::BLM_LKH],
            $adaLkh ? [TopikFcm::BLM_PULANG, TopikFcm::BLM_LKH] : [TopikFcm::BLM_PULANG],
        );
    }

    public function lkhDibuat(string $nip): void
    {
        $this->antre($nip, [], [TopikFcm::BLM_LKH]);
    }

    /**
     * @param list<string> $tambah
     * @param list<string> $lepas
     */
    private function antre(string $nip, array $tambah, array $lepas): void
    {
        if ($this->driver !== 'fcm' || $nip === '') {
            return;
        }

        try {
            if (! ($this->antrean)()->push(FcmTopikJob::ANTREAN, FcmTopikJob::NAMA, ['nip' => $nip, 'tambah' => $tambah, 'lepas' => $lepas])->getStatus()) {
                log_message('warning', 'Antrean menolak job fcm-topik.');
            }
        } catch (Throwable $e) {
            log_message('warning', 'Job fcm-topik gagal diantre: {pesan}', ['pesan' => $e->getMessage()]);
        }
    }
}
