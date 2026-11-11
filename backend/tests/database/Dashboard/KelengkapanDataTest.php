<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use App\Libraries\Dashboard\Beranda\KelengkapanData;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\FakeStorageAdapter;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-087 — kartu kelengkapan data beranda pegawai (`L_user::dashboard_pns` + `L_employee::kd_r*` [K], keputusan Q-D13..D17
 * semua A): hanya role 2 + PNS; kartu < 100% urut legacy; Pelatihan tidak pernah tampil; persen tanpa batas atas; Pangkat
 * memakai riwayat KP v2 berlampiran (berkas ada di storage); NIP awalan `123456789` tidak dikecualikan. Hari ini 10-11-2026.
 * Data sintetis.
 *
 * @internal
 */
final class KelengkapanDataTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private const HARI_INI = '2026-11-10';

    private FakeStorageAdapter $storage;

    /** @var array<string, int> singkat => id */
    private array $jenjang = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FakeStorageAdapter();

        foreach ([1 => 'PNS', 6 => 'PPPK'] as $id => $nama) {
            if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', $id)->countAllResults() === 0) {
                $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => $id, 'jenis_pegawai' => $nama, 'status' => 1]);
            }
        }

        foreach ([1 => 'Pengangkatan CPNS', 2 => 'Pengangkatan PNS', 3 => 'Reguler', 5 => 'Penyesuaian Ijazah'] as $id => $nama) {
            if ($this->db->table('jenis_kp')->where('id_jenis_kp', $id)->countAllResults() === 0) {
                $this->db->table('jenis_kp')->insert(['id_jenis_kp' => $id, 'jenis_kp' => $nama, 'status' => 1]);
            }
        }

        foreach ([26 => 'SL XXX', 27 => 'SL XX', 28 => 'SL X'] as $id => $nama) {
            if ($this->db->table('tanda_jasa')->where('id_tanda_jasa', $id)->countAllResults() === 0) {
                $this->db->table('tanda_jasa')->insert(['id_tanda_jasa' => $id, 'tanda_jasa' => $nama, 'status' => 1]);
            }
        }

        foreach (['SD', 'SLTP', 'SLTA', 'D.IV', 'S.1'] as $i => $singkat) {
            $ada = $this->db->table('jenjang_pendidikan')->where('jenjang_pendidikan_singkat', $singkat)->where('status', 1)->get()->getRowArray();

            if ($ada === null) {
                $this->db->table('jenjang_pendidikan')->insert(['jenjang_pendidikan_singkat' => $singkat, 'jenjang_pendidikan' => "Jenjang {$singkat}", 'order' => 90 + $i, 'status' => 1]);
                $this->jenjang[$singkat] = (int) $this->db->insertID();
            } else {
                $this->jenjang[$singkat] = (int) $ada['id_jenjang_pendidikan'];
            }
        }
    }

    public function testKartuDiBawahSeratusUrutLegacyDenganNilai(): void
    {
        $nip = $this->pns(); // Data Umum: nama, tgl_lahir, jenis_kelamin = 3/11 → 27

        // Pendidikan (jenjang akhir S.1 dari CPNS III/a): SLTA + D.IV (dihitung untuk S.1) = 2/4; SD ditolak tidak dihitung.
        $this->pendidikan($nip, 'SLTA', 1);
        $this->pendidikan($nip, 'D.IV', 1);
        $this->pendidikan($nip, 'SD', 2);

        // Pangkat: CPNS III/a berlampiran, PNS III/a tanpa lampiran, III/b berlampiran tetapi berkas hilang → 1/3.
        $cpns = $this->riwayatKp($nip, 1, 'III', 'a', 'CPNS III/a', '2016-03-01');
        $this->riwayatKp($nip, 2, 'III', 'a', 'III/a', '2017-03-01');
        $b = $this->riwayatKp($nip, 3, 'III', 'b', 'III/b', '2021-04-01');
        $this->lampiran($nip, $cpns, 'kp/cpns.pdf', true);
        $this->lampiran($nip, $b, 'kp/hilang.pdf', false);
        $this->snapshotCpns($nip, $cpns, '2016-03-01');

        // KGB dari CPNS 2016-03-01: jadwal 2018…2026 (5, entri 2028 dibuang); ada 2018 & 2020 (2022 dihapus) → 40.
        $this->kgb($nip, '2018-03-01', 1);
        $this->kgb($nip, '2020-03-01', 1);
        $this->kgb($nip, '2022-03-01', 10);
        // Tanda Jasa: masa kerja 10 tahun → wajib X; belum ada → 0.

        $kartu = (new KelengkapanData($this->db, self::HARI_INI, $this->storage))->kartu(Role::PEGAWAI, $nip);

        $this->assertSame([
            ['kode' => 'data-umum', 'label' => 'Data Umum', 'persen' => 27],
            ['kode' => 'alamat', 'label' => 'Data Alamat', 'persen' => 0],
            ['kode' => 'pendidikan', 'label' => 'Data Pendidikan', 'persen' => 50],
            ['kode' => 'kp', 'label' => 'Data Pangkat', 'persen' => 33],
            ['kode' => 'kgb', 'label' => 'Data KGB', 'persen' => 40],
            ['kode' => 'tanda-jasa', 'label' => 'Data Tanda Jasa', 'persen' => 0],
        ], $kartu, 'Keluarga (belum menikah) & Jabatan 100 → tanpa kartu; Pelatihan tidak pernah tampil (Q-D13)');
    }

    public function testKeluargaMenikahTanpaSnapshotDanAlamatAda(): void
    {
        $nip = $this->pns(['status_pernikahan' => 1]);
        $this->db->table('riwayat_alamat')->insert(['nip' => $nip, 'status' => 1]);
        $this->db->table('pegawai_alamat')->insert(['nip' => $nip, 'id_riwayat_alamat' => (int) $this->db->insertID()]);

        $kode = array_column((new KelengkapanData($this->db, self::HARI_INI, $this->storage))->kartu(Role::PEGAWAI, $nip), 'persen', 'kode');

        $this->assertSame(0, $kode['keluarga']);
        $this->assertArrayNotHasKey('alamat', $kode);
    }

    public function testKgbLebihDariSeratusTidakTampilTanpaBatasAtas(): void
    {
        $nip = $this->pns();
        $kp  = $this->riwayatKp($nip, 1, 'III', 'a', 'CPNS III/a', '2022-03-01');
        $this->snapshotCpns($nip, $kp, '2022-03-01');
        // jadwal dari 2022-03-01: 2024-03-01, 2026-03-01, (2028 dibuang) → 2; tiga tahun KGB → 150% → tidak tampil (Q-D14).
        foreach (['2023-03-01', '2024-03-01', '2025-03-01'] as $tmt) {
            $this->kgb($nip, $tmt, 1);
        }

        $kode = array_column((new KelengkapanData($this->db, self::HARI_INI, $this->storage))->kartu(Role::PEGAWAI, $nip), 'persen', 'kode');

        $this->assertArrayNotHasKey('kgb', $kode);
        $this->assertArrayNotHasKey('tanda-jasa', $kode, 'masa kerja < 10 tahun → 100');
    }

    public function testHanyaRole2DanPnsTanpaPengecualianAwalanNip(): void
    {
        $k = new KelengkapanData($this->db, self::HARI_INI, $this->storage);

        $pns  = $this->pns();
        $pppk = $this->buatPegawaiDiSatker($this->buatSatker(), ['id_jenis_pegawai' => 6]);

        $this->assertSame([], $k->kartu(Role::PPPK, $pppk), 'PPPK tanpa kartu (Q-D16)');
        $this->assertSame([], $k->kartu(Role::PEGAWAI, $pppk), 'role 2 non-PNS [K]');
        $this->assertSame([], $k->kartu(Role::PTT, $pns));
        $this->assertSame([], $k->kartu(Role::SUPER_ADMIN, $pns));
        $this->assertSame([], $k->kartu(Role::PEGAWAI, '199999999999999999'), 'NIP tidak ada');

        $awalan = $this->buatPegawaiDiSatker($this->buatSatker(), ['nip' => '123456789012345678', 'tgl_lahir' => '1990-01-01', 'id_jenis_pegawai' => 1]);
        $this->assertNotSame([], $k->kartu(Role::PEGAWAI, $awalan), 'awalan 123456789 tidak dikecualikan (Q-D17)');
    }

    /**
     * @param array<string, mixed> $override
     */
    private function pns(array $override = []): string
    {
        return $this->buatPegawaiDiSatker($this->buatSatker(), ['id_jenis_pegawai' => 1, 'tgl_lahir' => '1990-01-01'] + $override);
    }

    private function pendidikan(string $nip, string $singkat, int $status): void
    {
        $this->db->table('riwayat_pendidikan')->insert(['nip' => $nip, 'id_jenjang_pendidikan' => $this->jenjang[$singkat], 'tgl_lulus' => '2010-01-01', 'status' => $status]);
    }

    private function riwayatKp(string $nip, int $jenis, string $gol, string $ruang, string $golRuang, string $tmt): int
    {
        $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'id_jenis_kp' => $jenis, 'gol' => $gol, 'ruang' => $ruang, 'gol_ruang' => $golRuang, 'tmtsk' => $tmt, 'status' => 1]);

        return (int) $this->db->insertID();
    }

    private function lampiran(string $nip, int $idEntri, string $path, bool $adaBerkas): void
    {
        $this->db->table('document_attachment')->insert(['NIP' => $nip, 'id_riwayat' => 11, 'id_entri' => (string) $idEntri, 'path' => $path, 'file_ext' => '.pdf', 'file_size' => 2000]);

        if ($adaBerkas) {
            $this->storage->simpan($path, 'PDF');
        }
    }

    private function snapshotCpns(string $nip, int $idRiwayatKp, string $tmt): void
    {
        $this->db->table('pegawai_cpns')->insert([
            'nip'      => $nip, 'id_riwayat_kp' => $idRiwayatKp, 'tmtsk' => $tmt, 'tgl_sk' => $tmt,
            'jenis_kp' => 'CPNS', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda',
        ]);
    }

    private function kgb(string $nip, string $tmt, int $status): void
    {
        $this->db->table('riwayat_kgb')->insert(['nip' => $nip, 'tmtsk' => $tmt, 'tgl_sk' => $tmt, 'status' => $status]);
    }
}
