<?php

declare(strict_types=1);

namespace Tests\Support\Presensi;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\Slip\SlipGajiAdminService;
use App\Libraries\Presensi\Slip\SlipGajiService;
use App\Libraries\Presensi\Slip\UangMakanSlipInterface;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use Closure;
use Config\Encryption;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Bantuan test akses Slip Gaji (MAKE-031, PRD 5A §7.3–7.4): kunci enkripsi uji (pola `ResetEmailTestTrait`, tanpa ubah
 * workflow CI), pegawai bersatker, baris `gaji_pegawai` sintetis, AuthContext sintetis, service dengan jam WIB disuntik.
 * Dipakai bersama PegawaiFixtureTrait di kelas `DatabaseTestCase`.
 */
trait SlipUjiTrait
{
    protected static string $kunciEnkripsiUji = 'phpunit-only-encryption-key-slip-0123456789abcdef';

    protected function siapkanSlip(): void
    {
        config(Encryption::class)->key = self::$kunciEnkripsiUji;
        Services::resetSingle('encrypter');
    }

    protected function bersihkanSlip(): void
    {
        config(Encryption::class)->key = (new Encryption())->key;
        Services::resetSingle('encrypter');
    }

    /**
     * Pegawai di satker baru (`pegawai_mutasi_jabatan` berisi nama jabatan/unit/satker snapshot).
     *
     * @return array{nip: string, id_satker: int}
     */
    protected function pegawaiSlip(string $nama = 'Budi Santoso', ?int $satker = null): array
    {
        $satker ??= $this->buatSatker();
        $nip = $this->buatPegawai(['nama' => $nama, 'glr_awal' => 'Dr.', 'glr_akhir' => 'M.Si.'], ['id_satker' => $satker, 'jabatan' => 'Analis Uji', 'unit' => 'Biro Uji', 'satker' => 'Satker Uji ' . $satker]);

        return ['nip' => $nip, 'id_satker' => $satker];
    }

    /**
     * Baris slip sintetis; nominal bawaan GPP/TK + uang makan 0.
     *
     * @param array<string, mixed> $isi
     */
    protected function slip(string $nip, int $tahun, int $bulan, array $isi = []): int
    {
        $nominal = array_fill_keys(KolomGaji::semuaField(), '0.00');
        $this->db->table('gaji_pegawai')->insert(array_merge($nominal, [
            'nip'   => $nip, 'bulan' => $bulan, 'tahun' => $tahun, 'gjpokok' => '3000000.00', 'bersih_gpp' => '2700000.00',
            'kotor' => '2000000.00', 'bersih_tk' => '1900000.00', 'created_at' => '2026-09-01 00:00:00',
        ], $isi));

        return (int) $this->db->insertID();
    }

    protected function authPegawaiSlip(string $nip, int $role = Role::PEGAWAI, int $id = 8001): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => (string) $id, 'role' => $role, 'nip' => $nip]);

        return $auth;
    }

    protected function authAdminSlip(int $role = Role::SUPER_ADMIN, ?string $satker = null, int $id = 8101): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => (string) $id, 'role' => $role, 'nip' => null, 'id_satker' => $satker]);

        return $auth;
    }

    /**
     * @param (Closure(int): void)|null $kaitBuka
     */
    protected function slipPegawai(string $wib = '2026-10-27 09:00:00', ?UangMakanSlipInterface $uangMakan = null, ?Closure $kaitBuka = null): SlipGajiService
    {
        return new SlipGajiService(null, null, self::jamWib($wib), null, $uangMakan, null, $kaitBuka);
    }

    protected function slipAdmin(string $wib = '2026-10-27 09:00:00'): SlipGajiAdminService
    {
        return new SlipGajiAdminService(null, null, self::jamWib($wib));
    }

    /**
     * @return Closure(): DateTimeImmutable
     */
    protected static function jamWib(string $wib): Closure
    {
        return static fn (): DateTimeImmutable => new DateTimeImmutable($wib, new DateTimeZone('Asia/Jakarta'));
    }
}
