<?php

declare(strict_types=1);

namespace Tests\Support\Presensi;

use App\Constants\Role;
use App\Interfaces\Layanan\StatusTugasBelajarInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\Laporan\RekapKehadiranService;
use App\Libraries\Presensi\Laporan\RekapUangMakanService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Fixture rekap D-09b (MAKE-042): pegawai populasi bulan kalender (dibuat 2020, jenis PNS, jabatan berlaku di satker),
 * presensi/cuti/konket/TB sintetis, service dengan jam & TB disuntik. Dipakai bersama `PegawaiFixtureTrait`.
 */
trait RekapUjiTrait
{
    /** @var array<string, list<string>> NIP → tanggal bebas presensi TB untuk TB palsu */
    protected array $tbPalsu = [];

    protected function siapkanJenisPegawai(): void
    {
        foreach ([1 => 'Pegawai Negeri Sipil', 2 => 'PTT', 6 => 'PPPK'] as $id => $nama) {
            if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', $id)->countAllResults() === 0) {
                $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => $id, 'jenis_pegawai' => $nama, 'status' => 1]);
            }
        }
    }

    /**
     * Pegawai populasi DH di satker `$idSatker` dengan riwayat jabatan berlaku `jenis_jabatan = 1`.
     *
     * @param array<string, mixed> $pegawai kolom `pegawai`
     * @param array<string, mixed> $rmj     kolom `riwayat_mutasi_jabatan`
     */
    protected function pegawaiRekap(int $idSatker, array $pegawai = [], array $rmj = []): string
    {
        $nip = $this->buatPegawaiDiSatker($idSatker, array_merge(['created_at' => '2020-01-01 00:00:00', 'id_jenis_pegawai' => 1], $pegawai));
        $this->buatRiwayatMutasiJabatan($nip, $rmj);

        return $nip;
    }

    protected function hadir(string $nip, string $tgl, ?string $masuk = '07:30:00', ?string $pulang = '16:00:00'): void
    {
        $this->db->table('dh_online')->insert(['nip' => $nip, 'tgl' => $tgl, 'jam_masuk' => $masuk, 'jam_pulang' => $pulang, 'sumber' => 1]);
    }

    protected function cutiRekap(string $nip, string $mulai, string $akhir): void
    {
        if ($this->db->table('jenis_cuti')->where('id_jenis_cuti', 1)->countAllResults() === 0) {
            $this->db->table('jenis_cuti')->insert(['id_jenis_cuti' => 1, 'nama_jenis_cuti' => 'Cuti Tahunan', 'status' => 1]);
        }

        $this->db->table('riwayat_cuti')->insert(['nip' => $nip, 'id_jenis_cuti' => 1, 'nama_jenis_cuti' => 'Cuti Tahunan', 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir, 'lama_cuti' => 1, 'status' => 1]);
    }

    protected function konketRekap(string $nip, string $tgl, int $kategori, int $affectTukin = 1): void
    {
        $this->db->table('absen_ijin')->insert([
            'nip' => $nip, 'date_start' => $tgl . ' 00:00:00', 'date_end' => $tgl . ' 23:59:59', 'kategori' => $kategori, 'alasan' => 'Uji', 'status' => 'V', 'affect_tukin' => $affectTukin,
        ]);
    }

    protected function tbRekap(string $nip, string $mulai, string $akhir): int
    {
        $this->db->table('riwayat_tb')->insert([
            'nip'                  => $nip, 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir, 'status' => 1, 'jenjang_pendidikan_singkat' => 'S2',
            'institusi_pendidikan' => 'Universitas Uji', 'jurusan_pendidikan' => 'Jurusan Uji', 'dalam_negeri' => 1,
            'lokasi_pendidikan'    => 'Kota Uji', 'lembaga_yang_mendanai' => 'Lembaga Uji',
        ]);

        return (int) $this->db->insertID();
    }

    protected function adminRekap(int $role = Role::SUPER_ADMIN, ?string $satker = null, ?string $unit = null): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims(array_filter(['sub' => '9201', 'role' => $role, 'id_satker' => $satker, 'id_unit' => $unit], static fn ($v): bool => $v !== null));

        return $auth;
    }

    protected function serviceKehadiran(string $wib = '2026-10-27 09:00:00'): RekapKehadiranService
    {
        return new RekapKehadiranService($this->db, self::jamRekap($wib), $this->tbPalsu());
    }

    protected function serviceUangMakan(string $wib = '2026-10-27 09:00:00'): RekapUangMakanService
    {
        return new RekapUangMakanService($this->db, self::jamRekap($wib));
    }

    protected function tbPalsu(): StatusTugasBelajarInterface
    {
        $data = &$this->tbPalsu;

        return new class ($data) implements StatusTugasBelajarInterface {
            /** @param array<string, list<string>> $data */
            public function __construct(private array &$data)
            {
            }

            public function bebasPresensiPenuh(string $nip, string $tanggal): bool
            {
                return in_array($tanggal, $this->data[$nip] ?? [], true);
            }

            public function rentangBebasPresensi(string $nip, string $dari, string $sampai): array
            {
                return array_values(array_filter($this->data[$nip] ?? [], static fn (string $t): bool => $t >= $dari && $t <= $sampai));
            }

            public function rentangBebasPresensiBanyak(array $nip, string $dari, string $sampai): array
            {
                $hasil = [];

                foreach ($nip as $n) {
                    $hasil[$n] = $this->rentangBebasPresensi($n, $dari, $sampai);
                }

                return $hasil;
            }
        };
    }

    /**
     * @return \Closure(): DateTimeInterface
     */
    protected static function jamRekap(string $wib): \Closure
    {
        return static fn (): DateTimeInterface => new DateTimeImmutable($wib, new DateTimeZone('Asia/Jakarta'));
    }
}
