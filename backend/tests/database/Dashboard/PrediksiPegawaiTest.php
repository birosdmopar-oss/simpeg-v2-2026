<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use App\Libraries\Dashboard\Beranda\PrediksiPegawai;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-087 (F-13 M2b) — kartu prediksi beranda pegawai ikut `L_user::dashboard_pns|dashboard_pppk` [K]: role 2 Pensiun (BUP
 * jabatan) / Naik Pangkat (next_kp: tanggal atau "pangkat puncak"; JF → AK) / Tanda Jasa (next_tj) / KGB (next_kgb); role 7
 * Pensiun = akhir masa perjanjian + KGB; role 6 kosong; KP/TJ/KGB disembunyikan bila tidak sebelum pensiun; teks "Bulan Tahun"
 * dan sisa "N Tahun M Bulan". Hari ini 10-11-2026. Data sintetis.
 *
 * @internal
 */
final class PrediksiPegawaiTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private const HARI_INI = '2026-11-10';

    private int $idPangkatIIIa;

    private int $idPangkatIVe;

    private int $idJenjangS1;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'PNS', 6 => 'PPPK', 2 => 'PTT'] as $id => $nama) {
            if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', $id)->countAllResults() === 0) {
                $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => $id, 'jenis_pegawai' => $nama, 'status' => 1]);
            }
        }

        if ($this->db->table('jenis_kp')->where('id_jenis_kp', 3)->countAllResults() === 0) {
            $this->db->table('jenis_kp')->insert(['id_jenis_kp' => 3, 'jenis_kp' => 'Reguler', 'status' => 1]);
        }

        $this->db->table('pangkat')->insert(['pangkat' => 'Penata Muda', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'order' => 16, 'status' => 1]);
        $this->idPangkatIIIa = (int) $this->db->insertID();
        $this->db->table('pangkat')->insert(['pangkat' => 'Pembina Utama', 'gol' => 'IV', 'ruang' => 'e', 'gol_ruang' => 'IV/e', 'order' => 24, 'status' => 1]);
        $this->idPangkatIVe = (int) $this->db->insertID();
        $this->db->table('jenjang_pendidikan')->insert(['jenjang_pendidikan_singkat' => 'S.1', 'jenjang_pendidikan' => 'Strata 1', 'status' => 1]);
        $this->idJenjangS1 = (int) $this->db->insertID();
    }

    public function testPnsEmpatKartuDenganTeksDanSisa(): void
    {
        $nip = $this->pns('1970-05-20');            // BUP 58 → 2028-06-01
        $this->cpns($nip, '2017-03-01');            // TJ X 2027-08-01; KGB acuan CPNS → 2027-03-01
        $this->kp($nip, 'III/a', '2023-04-01');     // reguler +4 → 2027-04-01

        $kartu = $this->perKode((new PrediksiPegawai($this->db, self::HARI_INI))->kartu(Role::PEGAWAI, $nip));

        $this->assertSame(['pensiun', 'kp', 'tj', 'kgb'], array_keys($kartu));
        $this->assertSame(['2028-06-01', 'Juni 2028', '1 Tahun 6 Bulan'], [$kartu['pensiun']['tanggal'], $kartu['pensiun']['teks'], $kartu['pensiun']['sisa']]);
        $this->assertSame(['2027-04-01', 'April 2027', '4 Bulan', 'ST', false], [$kartu['kp']['tanggal'], $kartu['kp']['teks'], $kartu['kp']['sisa'], $kartu['kp']['jenis'], $kartu['kp']['pangkat_puncak']]);
        $this->assertSame(['2027-08-01', 'Agustus 2027', '8 Bulan'], [$kartu['tj']['tanggal'], $kartu['tj']['teks'], $kartu['tj']['sisa']]);
        $this->assertSame(['2027-03-01', 'Maret 2027', '3 Bulan'], [$kartu['kgb']['tanggal'], $kartu['kgb']['teks'], $kartu['kgb']['sisa']]);
    }

    public function testKpKgbTjSetelahPensiunDisembunyikan(): void
    {
        $nip = $this->pns('1968-11-20');            // BUP 58 → 2026-12-01
        $this->cpns($nip, '2017-03-01');
        $this->kp($nip, 'III/a', '2023-04-01');

        $kartu = $this->perKode((new PrediksiPegawai($this->db, self::HARI_INI))->kartu(Role::PEGAWAI, $nip));

        $this->assertSame('2026-12-01', $kartu['pensiun']['tanggal']);
        $this->assertSame([null, null, null], [$kartu['kp']['tanggal'], $kartu['tj']['tanggal'], $kartu['kgb']['tanggal']]);
    }

    public function testPangkatPuncakTanpaTanggal(): void
    {
        $nip = $this->pns('1970-05-20');
        $this->kp($nip, 'IV/e', '2020-04-01');

        $kp = $this->perKode((new PrediksiPegawai($this->db, self::HARI_INI))->kartu(Role::PEGAWAI, $nip))['kp'];

        $this->assertSame([null, true, 'ST'], [$kp['tanggal'], $kp['pangkat_puncak'], $kp['jenis']]);
    }

    public function testTanpaSnapshotKpBukanPangkatPuncak(): void
    {
        $kp = $this->perKode((new PrediksiPegawai($this->db, self::HARI_INI))->kartu(Role::PEGAWAI, $this->pns('1970-05-20')))['kp'];

        $this->assertSame([null, false], [$kp['tanggal'], $kp['pangkat_puncak']]);
    }

    public function testPppkPensiunDariAkhirPerjanjianDanKgb(): void
    {
        $nip = $this->bup($this->buatPegawaiDiSatker($this->buatSatker(), ['id_jenis_pegawai' => 6, 'tgl_lahir' => '1990-01-01']));
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->update(['mhpk_akhir' => '2027-12-31', 'tmtsk' => '2025-01-01']);

        $kartu = $this->perKode((new PrediksiPegawai($this->db, self::HARI_INI))->kartu(Role::PPPK, $nip));

        $this->assertSame(['pensiun', 'kgb'], array_keys($kartu));
        $this->assertSame(['2027-12-31', 'Desember 2027'], [$kartu['pensiun']['tanggal'], $kartu['pensiun']['teks']]);
        $this->assertSame('2027-01-01', $kartu['kgb']['tanggal'], 'PPPK: acuan TMT jabatan + 2 tahun');
    }

    public function testPttDanRoleAdminTanpaKartu(): void
    {
        $nip = $this->pns('1970-05-20');
        $p   = new PrediksiPegawai($this->db, self::HARI_INI);

        $this->assertSame([], $p->kartu(Role::PTT, $nip));
        $this->assertSame([], $p->kartu(Role::SUPER_ADMIN, $nip));
        $this->assertSame([], $p->kartu(Role::PEGAWAI, '199999999999999999'), 'NIP tidak ada');
    }

    private function pns(string $lahir): string
    {
        return $this->bup($this->buatPegawaiDiSatker($this->buatSatker(), ['id_jenis_pegawai' => 1, 'tgl_lahir' => $lahir]));
    }

    /**
     * BUP tetap 58 (id grup/subgrup fixture bisa terbaca struktural Eselon I/II) dan bukan jabatan fungsional.
     */
    private function bup(string $nip): string
    {
        $pmj = $this->db->table('pegawai_mutasi_jabatan')->select('id_jabatan, id_group_jabatan')->where('nip', $nip)->get()->getRowArray();
        $this->db->table('jabatan')->where('id_jabatan', $pmj['id_jabatan'])->update(['umur_pensiun' => 58]);
        $this->assertNotSame(PrediksiPegawai::ID_GROUP_JF, (int) $pmj['id_group_jabatan'], 'prasyarat fixture: bukan JF');

        return $nip;
    }

    private function cpns(string $nip, string $tmt): void
    {
        $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'tmtsk' => $tmt, 'id_jenis_kp' => 3, 'id_pangkat' => $this->idPangkatIIIa, 'status' => 1]);
        $this->db->table('pegawai_cpns')->insert([
            'nip'      => $nip, 'id_riwayat_kp' => (int) $this->db->insertID(), 'tmtsk' => $tmt, 'tgl_sk' => $tmt,
            'jenis_kp' => 'CPNS', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda',
        ]);
    }

    private function kp(string $nip, string $golRuang, string $tmt): void
    {
        $idPangkat = $golRuang === 'IV/e' ? $this->idPangkatIVe : $this->idPangkatIIIa;
        $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'tmtsk' => $tmt, 'id_jenis_kp' => 3, 'id_pangkat' => $idPangkat, 'status' => 1]);
        [$gol, $ruang] = explode('/', $golRuang);
        $this->db->table('pegawai_kp')->insert([
            'nip'      => $nip, 'id_riwayat_kp' => (int) $this->db->insertID(), 'id_jenis_kp' => 3, 'id_pangkat' => $idPangkat, 'tmtsk' => $tmt, 'tgl_sk' => $tmt,
            'jenis_kp' => 'Reguler', 'gol' => $gol, 'ruang' => $ruang, 'gol_ruang' => $golRuang, 'pangkat' => 'Fixture',
        ]);
        $this->db->table('pegawai_pendidikan')->insert(['nip' => $nip, 'id_jenjang_pendidikan' => $this->idJenjangS1, 'jenjang_pendidikan_singkat' => 'S.1', 'tgl_lulus' => '2010-08-01']);
    }

    /**
     * @param list<array<string, mixed>> $kartu
     *
     * @return array<string, array<string, mixed>>
     */
    private function perKode(array $kartu): array
    {
        return array_column($kartu, null, 'kode');
    }
}
