<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Libraries\Dashboard\Beranda\BawahanPegawai;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-087 (F-13 M2b) — bawahan atasan di beranda pegawai (`L_user::dashboard_pns` :1100-1180) [K]: Eselon IV → bawahan
 * `pmj.id_atasan_es_4` = jabatan atasan; koordinator (jenis 2) → `id_atasan_es_4_koord`; Eselon I tanpa bawahan (cabang mati
 * legacy); presensi hari ini dari `dh_online`; AK hanya bawahan JF, `kurang` = sekarang − target (≤ 0). Data sintetis.
 *
 * @internal
 */
final class BawahanPegawaiTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private const HARI_INI = '2026-11-10';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'Struktural', 2 => 'Fungsional'] as $id => $nama) {
            if ($this->db->table('group_jabatan')->where('id_group_jabatan', $id)->countAllResults() === 0) {
                $this->db->table('group_jabatan')->insert(['id_group_jabatan' => $id, 'group_jabatan' => $nama, 'status' => 1]);
            }
        }

        foreach ([1 => 'E.I', 4 => 'E.IV'] as $id => $nama) {
            if ($this->db->table('sub_group_jabatan')->where('id_sub_group_jabatan', $id)->countAllResults() === 0) {
                $this->db->table('sub_group_jabatan')->insert(['id_sub_group_jabatan' => $id, 'id_group_jabatan' => 1, 'sub_group_jabatan' => $nama, 'status' => 1]);
            }
        }
    }

    public function testEselonIvPresensiHariIniDanAkHanyaJf(): void
    {
        $satker = $this->buatSatker();
        $atasan = $this->buatPegawaiDiSatker($satker, ['nama' => 'Atasan']);
        $jab    = $this->snapshot($atasan, ['id_group_jabatan' => 1, 'id_sub_group_jabatan' => 4])['id_jabatan'];

        $staf = $this->buatPegawaiDiSatker($satker, ['nip' => '199001012020011001', 'nama' => 'Staf Umum', 'glr_akhir' => 'S.E.']);
        $this->snapshot($staf, ['id_atasan_es_4' => $jab]);
        $jf = $this->buatPegawaiDiSatker($satker, ['nip' => '199001012020011002', 'nama' => 'Fungsional']);
        $this->snapshot($jf, ['id_atasan_es_4' => $jab, 'id_group_jabatan' => 2]);
        $nonaktif = $this->buatPegawaiDiSatker($satker, ['nama' => 'Nonaktif', 'status' => 2]);
        $this->snapshot($nonaktif, ['id_atasan_es_4' => $jab]);
        $lain = $this->buatPegawaiDiSatker($satker, ['nama' => 'Bawahan Lain']);
        $this->snapshot($lain, ['id_atasan_es_4' => $this->buatJabatan($satker)['id_jabatan']]);

        $this->db->table('dh_online')->insert(['nip' => $staf, 'tgl' => self::HARI_INI, 'jam_masuk' => '07:31:00']);
        $this->db->table('dh_online')->insert(['nip' => $jf, 'tgl' => '2026-11-09', 'jam_masuk' => '07:00:00', 'jam_pulang' => '16:00:00']);

        $hasil = (new BawahanPegawai($this->db, self::HARI_INI))->untuk($atasan);

        $this->assertTrue($hasil['atasan']);
        $this->assertSame([
            ['nama' => 'Staf Umum, S.E.', 'jam_masuk' => '07:31:00', 'jam_pulang' => ''],
            ['nama' => 'Fungsional', 'jam_masuk' => '', 'jam_pulang' => ''],
        ], $hasil['presensi']);
        $this->assertSame([$jf], array_column($hasil['ak'], 'nip'), 'AK hanya bawahan JF');
        $ak = $hasil['ak'][0];
        $this->assertSame(['order', 'nama', 'nip', 'gol_ruang', 'satker', 'jabatan', 'ak_now', 'ak_kp', 'kurang'], array_keys($ak));
        $this->assertEqualsWithDelta(min(0.0, $ak['ak_now'] - $ak['ak_kp']), $ak['kurang'], 1e-9);
    }

    public function testKoordinatorJenis2LewatIdAtasanEs4Koord(): void
    {
        $satker = $this->buatSatker();
        $koord  = $this->buatJabatanKoordinasi($satker);
        $atasan = $this->buatPegawaiDiSatker($satker);
        $this->snapshot($atasan, ['jenis_jabatan_koord' => 2, 'id_jabatan_koord' => $koord]);
        $staf = $this->buatPegawaiDiSatker($satker, ['nama' => 'Anggota Tim']);
        $this->snapshot($staf, ['id_atasan_es_4_koord' => $koord]);

        $hasil = (new BawahanPegawai($this->db, self::HARI_INI))->untuk($atasan);

        $this->assertSame(['Anggota Tim'], array_column($hasil['presensi'], 'nama'));
    }

    public function testEselonIDanPegawaiBiasaTanpaBawahan(): void
    {
        $satker = $this->buatSatker();
        $es1    = $this->buatPegawaiDiSatker($satker);
        $jab    = $this->snapshot($es1, ['id_group_jabatan' => 1, 'id_sub_group_jabatan' => 1])['id_jabatan'];
        $staf   = $this->buatPegawaiDiSatker($satker);
        $this->snapshot($staf, ['id_atasan_es_1' => $jab]);
        $biasa = $this->buatPegawaiDiSatker($satker);

        $kosong = ['atasan' => false, 'presensi' => [], 'ak' => []];
        $this->assertSame($kosong, (new BawahanPegawai($this->db, self::HARI_INI))->untuk($es1), '[K] cabang Eselon I legacy tidak tercapai');
        $this->assertSame($kosong, (new BawahanPegawai($this->db, self::HARI_INI))->untuk($biasa));
        $this->assertSame($kosong, (new BawahanPegawai($this->db, self::HARI_INI))->untuk('199999999999999999'));
    }

    /**
     * @param array<string, mixed> $ubah
     *
     * @return array<string, mixed> baris pmj sesudah diubah
     */
    private function snapshot(string $nip, array $ubah): array
    {
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->update($ubah);

        return $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->get()->getRowArray();
    }
}
