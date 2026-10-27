<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti\Servis;

use App\Libraries\Layanan\Cuti\StatusCuti;
use Throwable;

/**
 * Listener event `auth.login` (PRD Fase 4 v1.3 §6.4.7, Q-07): setiap login sukses mereset penanda "tunda" pengingat
 * persetujuan cuti milik NIP yang login, seperti flashdata `justLogin` legacy (`MY_Controller.php:363-453`).
 *
 * Hanya baris NIP itu, hanya tahap/status yang berlaku: `skip_al` pada status 0 (tahap AL) dan `skip_pyb` pada status
 * 0 dan 3 (tahap PyB). Ditulis lewat query builder tanpa audit (state tampilan, bukan data bisnis) agar tidak menambah
 * baris audit selama login. Dijalankan sinkron di request login; kegagalan di sini tidak boleh menggagalkan login
 * (pemanggil membungkus `Events::trigger` dengan try/catch).
 */
final class ResetTundaCuti
{
    /**
     * @param array<string, mixed> $payload `{id_pengguna: int, nip: ?string}`
     */
    public static function saatLogin(array $payload): void
    {
        $nip = $payload['nip'] ?? null;

        if (! is_string($nip) || $nip === '') {
            return;
        }

        $db = db_connect();

        $db->table('riwayat_cuti')
            ->where('nip_atasan_langsung', $nip)
            ->where('status', StatusCuti::PENGAJUAN)
            ->where('skip_al', 1)
            ->update(['skip_al' => 0]);

        $db->table('riwayat_cuti')
            ->where('nip_yang_menyetujui', $nip)
            ->whereIn('status', [StatusCuti::PENGAJUAN, StatusCuti::DISETUJUI_AL])
            ->where('skip_pyb', 1)
            ->update(['skip_pyb' => 0]);
    }

    /**
     * Pembungkus aman untuk pendaftaran di `Config\Events`: galat apa pun hanya dicatat.
     *
     * @param array<string, mixed> $payload
     */
    public static function tangani(array $payload): void
    {
        try {
            self::saatLogin($payload);
        } catch (Throwable $e) {
            log_message('error', 'ResetTundaCuti gagal: {class}', ['class' => $e::class]);
        }
    }
}
