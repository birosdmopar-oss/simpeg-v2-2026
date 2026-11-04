<?php

declare(strict_types=1);

namespace App\Libraries\Push;

use App\Jobs\PushPesanJob;
use Closure;
use CodeIgniter\Queue\Interfaces\QueueInterface;
use Throwable;

/**
 * Titik sambung push generik (PRD Fase 6 §7.4 F6-13, MAKE-066) — padanan `andr_notif_queue` legacy untuk produsen di luar
 * inbox layanan dan riwayat, terutama **Halo Simpeg (Fase 8)** [K `L_support.php:1080/1290/1415`: judul "Halo Simpeg", isi =
 * pesan percakapan, kanal Android]. Produsen memanggil {@see self::antre} SESUDAH commit; job `push-pesan` mencari token
 * per NIP saat berjalan ({@see PushPesanJob}).
 *
 * Produsen lain legacy: popup "Informasi" Android [K `Cron::queue_popup_notif` C:886-953, judul "Informasi", isi "Informasi
 * {keterangan}", data = baris popup + `file_url`] milik modul Popup Fase 7 (F-04) — memakai titik ini saat modul itu
 * dibangun; TIDAK dikirim di B5 (CR-099 P1).
 *
 * Kanal: `android` (bawaan, ikut legacy `andr_notif_queue`), `web`, atau `semua`. Job diantre untuk driver apa pun, sama
 * dengan push inbox Fase 4 (driver `mock` hanya mencatat payload). Kegagalan mengantre dicatat sebagai peringatan dan TIDAK
 * dilempar.
 */
final class AntrePushPesan
{
    public const KANAL = ['android', 'web', 'semua'];

    /** @var Closure(): QueueInterface */
    private Closure $antrean;

    /**
     * @param (Closure(): QueueInterface)|null $antrean override antrean (test)
     */
    public function __construct(?Closure $antrean = null)
    {
        $this->antrean = $antrean ?? static fn (): QueueInterface => service('queue');
    }

    /**
     * @param list<string>          $nips  NIP penerima
     * @param array<string, string> $data  data tambahan (pasangan string; `link` = tautan SPA relatif untuk web)
     * @param string                $kanal salah satu {@see self::KANAL}
     *
     * @return bool true bila job diantre
     */
    public function antre(array $nips, string $judul, string $isi, array $data = [], string $kanal = 'android'): bool
    {
        $nips = array_values(array_unique(array_filter(array_map(static fn (mixed $n): string => trim((string) $n), $nips), static fn (string $n): bool => $n !== '')));

        if ($nips === [] || trim($judul) === '' || ! in_array($kanal, self::KANAL, true)) {
            return false;
        }

        try {
            $ok = ($this->antrean)()->push(PushPesanJob::ANTREAN, PushPesanJob::NAMA, [
                'nip'   => $nips,
                'judul' => $judul,
                'isi'   => $isi,
                'data'  => array_map('strval', $data),
                'kanal' => $kanal,
            ])->getStatus();

            if (! $ok) {
                log_message('warning', 'Antrean menolak job push-pesan.');
            }

            return $ok;
        } catch (Throwable $e) {
            log_message('warning', 'Job push-pesan gagal diantre: {pesan}', ['pesan' => $e->getMessage()]);

            return false;
        }
    }
}
