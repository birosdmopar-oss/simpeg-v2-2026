<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\SlipGaji;

use App\Constants\Role;

/**
 * CR-027 (K-CR027-9) — kebijakan hak akses Slip Gaji, keputusan user 01-10-2026 "ikut legacy" dengan cakupan satker
 * (H-5):
 *
 * - Pegawai (role 2/6/7, `Role::UL_PEGAWAI`): lihat, buka, dan cetak slip milik NIP **dari token login**; parameter NIP
 *   dari klien diabaikan.
 * - Admin role 1: semua pegawai. Admin role 3: hanya pegawai yang `id_satker` jabatan saat ini sama dengan `id_satker`
 *   akun admin (pola `UserService`; admin tanpa `id_satker` ditolak, fail-closed; di luar cakupan → 403).
 * - Role 4/5/8: tanpa akses.
 * - Verifikasi QR publik tidak memakai kelas ini.
 *
 * Deviasi dari Matriks_Role_x_Endpoint / FSD / 05-Presensi (D-10 role 1 saja, D-11 role 1 dan 2) dicatat di README.
 */
final class AksesSlip
{
    /**
     * Role pengelola Slip Gaji (konstanta legacy `UL_ADMIN`).
     *
     * @var list<int>
     */
    public const ROLE_PENGELOLA = [Role::SUPER_ADMIN, Role::ADMIN_SATKER];

    private function __construct()
    {
    }

    /**
     * Role pegawai yang memakai alur "slip saya" (daftar, buka, cetak milik sendiri).
     */
    public static function bolehMelihatMilikSendiri(int $role): bool
    {
        return in_array($role, Role::UL_PEGAWAI, true);
    }

    /**
     * Role yang boleh memakai jalur admin (kelola, lihat, koreksi, buka ulang, impor); cakupan per pegawai tetap
     * diperiksa dengan {@see self::dalamCakupan()}.
     */
    public static function bolehMengelola(int $role): bool
    {
        return in_array($role, self::ROLE_PENGELOLA, true);
    }

    /**
     * Pegawai target berada dalam cakupan admin: role 1 → selalu; role 3 → `id_satker` admin tidak kosong dan sama
     * persis (string) dengan `id_satker` jabatan saat ini pegawai; role lain → tidak pernah.
     */
    public static function dalamCakupan(int $role, ?string $satkerAdmin, ?string $satkerPegawai): bool
    {
        return match ($role) {
            Role::SUPER_ADMIN  => true,
            Role::ADMIN_SATKER => $satkerAdmin !== null && $satkerAdmin !== '' && $satkerAdmin === $satkerPegawai,
            default            => false,
        };
    }
}
