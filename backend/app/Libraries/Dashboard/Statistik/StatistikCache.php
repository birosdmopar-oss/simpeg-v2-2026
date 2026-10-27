<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Statistik;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\CacheService;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use Config\Services;
use Throwable;

/**
 * Key, TTL, dan invalidasi cache statistik F-03 (PRD Fase 7 F7-12; F0-14 `CacheService`, driver file).
 *
 * Key SELALU memuat lingkup dan, untuk Admin Satker (role 3), satker/unit akun — sehingga hasil satu satker tidak pernah
 * tampil untuk satker lain. TTL 900 detik menjadi pengaman untuk jalur tulis yang tidak lewat titik invalidasi
 * (koreksi NIP `NipCascadeService` tidak disentuh paket ini; koreksi NIP tidak mengubah agregat).
 *
 * Titik invalidasi (semua `invalidateMatching('dashboard_stat_*')`):
 *  (a) listener `Events::on(RiwayatEngine::EVENT_NOTIFIKASI, [StatistikCache::class, 'invalidasiDariEvent'])` di
 *      `Config\Events` — setiap transisi riwayat/snapshot;
 *  (b) hook `afterInsert/afterUpdate/afterDelete` `PegawaiModel::invalidasiStatistik()` — tulis tabel `pegawai`.
 * Jalur tulis lain (mis. `RiwayatEngine::tambah` alur Admin yang langsung Disetujui tanpa event, SiklusPegawai, koreksi
 * NIP) diterima basi paling lama TTL (keputusan reviewer CR 21-10-2026; PRD §13 pertanyaan terbuka).
 * Kegagalan cache (mis. folder tidak bisa ditulis) hanya dicatat ke log; respons tidak berubah.
 */
final class StatistikCache
{
    public const TTL = 900;

    public const PREFIKS = 'dashboard_stat_';

    public const POLA = self::PREFIKS . '*';

    public static function key(string $jenis, LingkupStatistik $lingkup, AuthContext $auth): string
    {
        return self::PREFIKS . $jenis . '_' . $lingkup->tipe . '_' . ($lingkup->id ?? 0) . '_' . self::segmenScope($auth);
    }

    /**
     * Segmen scope akun pada key: `all` (lihat semua), `s<id_satker>` / `u<id_unit>` (Admin Satker), `none` (fail-closed).
     */
    public static function segmenScope(AuthContext $auth): string
    {
        $role = $auth->role();

        if ($role !== null && in_array($role, PegawaiScope::ROLE_LIHAT_SEMUA, true)) {
            return 'all';
        }

        if ($role === Role::ADMIN_SATKER) {
            $satker = self::keInt($auth->idSatker());

            if ($satker !== null) {
                return 's' . $satker;
            }

            if ($auth->idSatker() !== null && $auth->idSatker() !== '') {
                return 'none';
            }

            $unit = self::keInt($auth->idUnit());

            return $unit === null ? 'none' : 'u' . $unit;
        }

        return 'none';
    }

    /**
     * Hapus seluruh cache statistik. Aman dipanggil dari hook/listener: kegagalan hanya dicatat.
     */
    public static function invalidasi(?CacheService $cache = null): void
    {
        try {
            ($cache ?? Services::cacheService())->invalidateMatching(self::POLA);
        } catch (Throwable $e) {
            log_message('warning', '[dashboard] invalidasi cache statistik gagal: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * Listener event riwayat (`RiwayatEngine::EVENT_NOTIFIKASI`); isi payload tidak dipakai — semua tipe memicu.
     *
     * @param array<string, mixed> $payload
     */
    public static function invalidasiDariEvent(array $payload = []): void
    {
        self::invalidasi();
    }

    /**
     * Angka kanonik (tanpa nol di depan, tanpa spasi) → int; selain itu null — sama dengan `PegawaiScope` (fail-closed).
     */
    private static function keInt(?string $nilai): ?int
    {
        return $nilai !== null && preg_match('/^(0|[1-9][0-9]*)$/', $nilai) === 1 ? (int) $nilai : null;
    }
}
