<?php

declare(strict_types=1);

namespace App\Libraries\Push;

use App\Jobs\PushRiwayatJob;
use Closure;
use CodeIgniter\Queue\Interfaces\QueueInterface;
use Throwable;

/**
 * Produsen job `push-riwayat` (PRD Fase 6 §7.4, F6-13; MAKE-066) — dipanggil SESUDAH commit:
 *
 * - pelanggan event `riwayatNotifikasi` mesin riwayat ({@see self::dariEvent}, didaftarkan di `Config/Events.php`) — tipe
 *   1/2/3 = `$type` `fcmQueue_rwy` legacy;
 * - LKH dan Konket (service di luar mesin riwayat) memanggil {@see self::antre} langsung pada titik legacy
 *   [K `L_lkh.php:331/542/1067`, `L_konket.php:370/563/718/868/1888`].
 *
 * Hanya mengantre bila `integrations.push.driver = fcm` dan jenis punya push di legacy ({@see TemplatPushRiwayat}).
 * Kegagalan mengantre dicatat sebagai peringatan dan TIDAK dilempar — operasi bisnis tetap berhasil (BP-7, AC-04.3).
 */
final class AntrePushRiwayat
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

    /**
     * Listener `Events::on(RiwayatEngine::EVENT_NOTIFIKASI, …)`.
     *
     * @param array<string, mixed> $payload `{tipe, jenis, nip, id, status, aksi, reason_note}`
     */
    public static function dariEvent(array $payload): void
    {
        (new self())->antre((int) ($payload['tipe'] ?? 0), (string) ($payload['jenis'] ?? ''), (int) ($payload['id'] ?? 0), isset($payload['aksi']) ? (string) $payload['aksi'] : null);
    }

    public function antre(int $tipe, string $jenis, int $id, ?string $aksi = null): void
    {
        if ($this->driver !== 'fcm' || $id <= 0 || TemplatPushRiwayat::penerima($tipe, $jenis) === null || ! TemplatPushRiwayat::didukung($jenis)) {
            return;
        }

        try {
            if (! ($this->antrean)()->push(PushRiwayatJob::ANTREAN, PushRiwayatJob::NAMA, ['tipe' => $tipe, 'jenis' => $jenis, 'id' => $id, 'aksi' => $aksi])->getStatus()) {
                log_message('warning', 'Antrean menolak job push-riwayat.');
            }
        } catch (Throwable $e) {
            log_message('warning', 'Job push-riwayat gagal diantre: {pesan}', ['pesan' => $e->getMessage()]);
        }
    }
}
