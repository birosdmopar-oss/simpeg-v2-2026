<?php

declare(strict_types=1);

namespace Tests\Support\Presensi;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\Kehadiran\PresensiManualService;
use App\Libraries\Presensi\Kehadiran\PresensiOnlineService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Bantuan test presensi 5A (MAKE-032, PRD 5A §7.5–7.6): pegawai di satker berzonasi, lokasi & aturan lokasi presensi,
 * AuthContext sintetis, dan service dengan jam disuntik (waktu dinyatakan sebagai jam WIB). Dipakai bersama
 * PegawaiFixtureTrait di kelas `DatabaseTestCase`.
 */
trait PresensiUjiTrait
{
    /**
     * Pegawai di satker baru berzonasi `$zonasi` menit (jenis pegawai `$jenisPegawai`, dibuat bila belum ada).
     *
     * @param array<string, mixed> $override kolom `pegawai`
     *
     * @return array{nip: string, id_satker: int, id_unit: int}
     */
    protected function pegawaiPresensi(int $zonasi = 0, int $jenisPegawai = 1, array $override = []): array
    {
        $this->jenisPegawai($jenisPegawai);
        $unit   = $this->buatUnit();
        $satker = $this->buatSatker($unit, ['zonasi' => $zonasi]);
        $nip    = $this->buatPegawai(['id_jenis_pegawai' => $jenisPegawai, ...$override], ['id_satker' => $satker, 'id_unit' => $unit]);

        return ['nip' => $nip, 'id_satker' => $satker, 'id_unit' => $unit];
    }

    protected function jenisPegawai(int $id): void
    {
        if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', $id)->countAllResults() === 0) {
            $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => $id, 'jenis_pegawai' => 'Jenis Uji ' . $id, 'status' => 1]);
        }
    }

    protected function lokasi(string $lat, string $lng, float $radius, int $status = 1, string $nama = 'Kantor Uji'): int
    {
        $this->db->table('lokasi_presensi')->insert(['nama_lokasi' => $nama, 'latitude' => $lat, 'longitude' => $lng, 'radius' => $radius, 'status' => $status]);

        return (int) $this->db->insertID();
    }

    /**
     * Aturan `dm_user_lokasi_presensi` (kolom target berupa JSON array teks).
     *
     * @param list<int|string> $lokasi
     * @param list<int|string> $uns
     * @param list<int|string> $jp
     * @param list<string>     $peg
     * @param list<string>     $excl
     */
    protected function aturan(array $lokasi, array $uns = [], array $jp = [], array $peg = [], array $excl = [], ?string $hari = null, int $status = 1): int
    {
        $json = static fn (array $v): ?string => $v === [] ? null : json_encode(array_map('strval', $v));

        $this->db->table('dm_user_lokasi_presensi')->insert([
            'target_lp'    => (string) $json($lokasi), 'target_lp_desc' => '[]',
            'target_uns'   => $json($uns), 'target_jp' => $json($jp), 'target_peg' => $json($peg), 'excl_peg' => $json($excl),
            'hari_berlaku' => $hari, 'status' => $status,
        ]);

        return (int) $this->db->insertID();
    }

    protected function authPegawai(string $nip, int $role = Role::PEGAWAI, int $idPengguna = 9001): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => (string) $idPengguna, 'role' => $role, 'nip' => $nip]);

        return $auth;
    }

    protected function authAdmin(int $idPengguna, int $role = Role::SUPER_ADMIN, ?string $satker = null): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => (string) $idPengguna, 'role' => $role, 'nip' => null, 'id_satker' => $satker]);

        return $auth;
    }

    /**
     * Service presensi online dengan jam WIB `$wib` (`Y-m-d H:i:s`).
     */
    protected function online(string $wib): PresensiOnlineService
    {
        return new PresensiOnlineService(null, null, static fn (): DateTimeImmutable => new DateTimeImmutable($wib, new DateTimeZone('Asia/Jakarta')));
    }

    protected function manual(string $wib = '2026-10-27 09:00:00'): PresensiManualService
    {
        return new PresensiManualService(null, null, static fn (): DateTimeImmutable => new DateTimeImmutable($wib, new DateTimeZone('Asia/Jakarta')));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function barisDh(string $nip, string $tgl): ?array
    {
        return $this->db->table('dh_online')->where(['nip' => $nip, 'tgl' => $tgl])->get()->getRowArray();
    }
}
