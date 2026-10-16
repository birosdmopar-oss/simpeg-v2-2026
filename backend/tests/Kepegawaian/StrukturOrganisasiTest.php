<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use Closure;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-19 Struktur Organisasi ikut templat legacy so_012025 (WS-2 M4 MAKE-012; keputusan user 13-10-2026): format
 * kementerian / unit (deputi) / satker (UPT), pejabat definitif dari riwayat jabatan (aturan legacy), Plt lalu Plh,
 * teks "Nama, Gol, NIP", pilihan unit/satker, akses semua pengguna login.
 *
 * @internal
 */
final class StrukturOrganisasiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatUjiTrait;

    private int $idPeriode;
    private int $unitSek;
    private int $unitDep;
    private int $satSek;
    private int $satDep;

    /**
     * @var array<string, int>
     */
    private array $jab = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->pastikan('group_jabatan', ['id_group_jabatan' => 1, 'group_jabatan' => 'Struktural', 'status' => 1]);
        $this->pastikan('jenis_status', ['id_jenis_status' => 1, 'jenis_status' => 'Aktif Bekerja', 'status_pegawai' => 1]);
        $this->pastikan('sub_group_jabatan', ['id_sub_group_jabatan' => 1, 'id_group_jabatan' => 1, 'sub_group_jabatan' => 'Eselon I', 'need_satker' => 1, 'status' => 1]);

        $this->unitSek = $this->buatUnit(['unit' => 'Sekretariat Uji']);
        $this->unitDep = $this->buatUnit(['unit' => 'Deputi Uji']);
        $this->satSek  = $this->buatSatker($this->unitSek, ['satker' => 'Biro Uji']);
        $this->satDep  = $this->buatSatker($this->unitDep, ['satker' => 'Direktorat Uji']);

        $this->db->table('periode_struktur_jabatan')->insert(['periode_struktur_jabatan' => 'Periode Lama Uji', 'status' => 2]);
        $this->db->table('periode_struktur_jabatan')->insert(['periode_struktur_jabatan' => 'Periode Uji ' . $this->unitSek, 'status' => 1]);
        $this->idPeriode = (int) $this->db->insertID();

        $this->jabatan('sekretaris', 'Sekretaris Kementerian', $this->satSek, 1, null, 2);
        $this->jabatan('staf_ahli', 'Staf Ahli Menteri Bidang Uji', $this->satSek, 1, null, 2);
        $this->jabatan('deputi', 'Deputi Bidang Uji', $this->satDep, 1, null, 2);
        $this->jabatan('kabiro', 'Kepala Biro Uji', $this->satSek, 2, 'sekretaris', 3);
        $this->jabatan('sesdep', 'Sekretaris Deputi Uji', $this->satDep, 2, 'deputi', 3);
        $this->jabatan('direktur', 'Direktur Uji', $this->satDep, 2, 'deputi', 3);
        $this->jabatan('subdit', 'Kepala Subdirektorat Uji', $this->satDep, 3, 'direktur', 4);
        $this->jabatan('seksi', 'Kepala Seksi Uji', $this->satDep, 4, 'subdit', 5);
    }

    public function testKementerianPejabatDefinitifPltPlhDanLowong(): void
    {
        $sek = $this->pejabatDefinitif('sekretaris', ['nama' => 'Budi', 'glr_awal' => 'Dr.', 'glr_akhir' => 'M.Si.'], 'IV/e');
        // Pegawai nonaktif (status 2, TMT status lampau) tidak dihitung → Plt mengisi Kepala Biro.
        $this->pejabatDefinitif('kabiro', ['status' => 2, 'tmt_status' => '2020-01-01']);
        $this->penugasan('plt', $this->buatPegawai(['nama' => 'Pelaksana Tugas']), 'kabiro');
        // Plt kedaluwarsa dan Plt berstatus 2 (Tidak Aktif, [V2]) diabaikan; Plh berlaku → Deputi.
        $this->penugasan('plt', $this->buatPegawai(), 'deputi', '-30 days', '-1 day');
        $this->penugasan('plt', $this->buatPegawai(['nama' => 'Plt Nonaktif']), 'deputi', status: 2);
        $this->penugasan('plh', $this->buatPegawai(['nama' => 'Pelaksana Harian']), 'deputi');

        $hasil = (new StrukturService())->pohon($this->admin());

        $this->assertSame(['kementerian', '0', $this->idPeriode], [$hasil['format'], $hasil['id_unit_satker'], $hasil['periode']['id_periode_struktur_jabatan'] ?? null]);
        $data = $hasil['data'];
        $this->assertSame("Dr. Budi, M.Si., IV/e, {$sek}", $data['sekretaris']['pejabat']);
        $this->assertFalse($data['sekretaris']['is_plt']);
        $this->assertSame(['Kepala Biro Uji'], array_column($data['sekretaris']['list_es2'], 'jabatan'));
        $kabiro = $data['sekretaris']['list_es2'][0];
        $this->assertSame(['plt', true, 'Biro Uji'], [$kabiro['jenis'], $kabiro['is_plt'], $kabiro['satker']]);
        $this->assertStringStartsWith('Plt. Pelaksana Tugas', $kabiro['pejabat']);
        $this->assertSame(['Staf Ahli Menteri Bidang Uji'], array_column($data['staf_ahli'], 'jabatan'));
        $this->assertSame('', $data['staf_ahli'][0]['pejabat'], 'lowong');
        $this->assertSame(['Deputi Bidang Uji'], array_column($data['deputi'], 'jabatan'));
        $this->assertStringStartsWith('Plh. Pelaksana Harian', $data['deputi'][0]['pejabat']);
        $this->assertSame(['Sekretaris Deputi Uji', 'Direktur Uji'], array_column($data['deputi'][0]['list_es2'], 'jabatan'));
    }

    public function testUnitDeputiBerjenjangDenganSesdep(): void
    {
        $this->pejabatDefinitif('seksi', ['nip' => '123456789100000001', 'nama' => 'Akun Dummy']);

        $hasil = (new StrukturService())->pohon($this->admin(), ['id_unit_satker' => (string) $this->unitDep]);

        $this->assertSame(['deputi', 'Deputi Uji'], [$hasil['format'], $hasil['judul']]);
        $data = $hasil['data'];
        $this->assertSame('Deputi Bidang Uji', $data['es1']['jabatan']);
        $this->assertSame(['Sekretariat Deputi Uji'], array_column($data['es2_sek'], 'jabatan'), 'Sekretaris → Sekretariat');
        $this->assertSame(['Direktur Uji'], array_column($data['es2'], 'jabatan'));
        $this->assertSame(['Subdirektorat Uji'], array_column($data['es2'][0]['es3'], 'jabatan'), 'awalan "Kepala " dibuang');
        $this->assertSame(['Seksi Uji'], array_column($data['es2'][0]['es3'][0]['es4'], 'jabatan'));
        $this->assertSame('Akun Dummy', $data['es2'][0]['es3'][0]['es4'][0]['pejabat'], 'NIP dummy legacy → nama saja');
    }

    public function testSatkerUptEs3DenganEs4(): void
    {
        // Satker hanya bisa dipilih bila legacy menawarkannya: semua satker unit 6.
        $this->pastikan('unit', ['id_unit' => 6, 'unit' => 'UPT Uji', 'status' => 1]);
        $satUpt = $this->buatSatker(6, ['satker' => 'Balai Uji']);
        $this->jabatan('kabalai', 'Kepala Balai Uji', $satUpt, 2, null, 3);
        $this->jabatan('kasubbag', 'Kepala Subbagian Uji', $satUpt, 3, 'kabalai', 4);
        $this->jabatan('kaurusan', 'Kepala Urusan Uji', $satUpt, 4, 'kasubbag', 5);

        $hasil = (new StrukturService())->pohon($this->admin(), ['id_unit_satker' => "sat_{$satUpt}"]);

        $this->assertSame(['upt', 'Balai Uji'], [$hasil['format'], $hasil['judul']]);
        $this->assertSame('Balai Uji', $hasil['data']['es2']['jabatan']);
        $this->assertSame(['Subbagian Uji'], array_column($hasil['data']['es3'], 'jabatan'));
        $this->assertSame(['Urusan Uji'], array_column($hasil['data']['es3'][0]['es4'], 'jabatan'));
        $this->assertContains("sat_{$satUpt}", array_column($hasil['pilihan'], 'value'));
        $this->assertTrue($hasil['pilihan'][array_search('6', array_column($hasil['pilihan'], 'value'), true)]['disabled'], 'unit 6 tidak bisa dipilih langsung (legacy)');
    }

    public function testPilihanTidakTersediaDitolakDanTanpaPeriodeKosong(): void
    {
        $this->assertSame(422, $this->statusDari(fn () => (new StrukturService())->pohon($this->admin(), ['id_unit_satker' => "sat_{$this->satDep}"])));
        // Unit 6 tampil di pilihan tetapi `disabled` (legacy) → ditolak; nilai non-string → 422.
        $this->pastikan('unit', ['id_unit' => 6, 'unit' => 'UPT Uji', 'status' => 1]);
        $this->assertSame(422, $this->statusDari(fn () => (new StrukturService())->pohon($this->admin(), ['id_unit_satker' => '6'])));
        $this->assertSame(422, $this->statusDari(fn () => (new StrukturService())->pohon($this->admin(), ['id_unit_satker' => ['0']])));

        $this->db->table('periode_struktur_jabatan')->where('status', 1)->update(['status' => 2]);
        $hasil = (new StrukturService())->pohon($this->admin());
        $this->assertNull($hasil['periode']);
        $this->assertSame([], $hasil['data']['deputi']);
    }

    public function testPerBulanPejabatPadaTanggalAcuanDanPdf(): void
    {
        Time::setTestNow('2026-10-13 10:00:00', 'Asia/Jakarta');

        try {
            $nip = $this->pejabatDefinitif('sekretaris', ['nama' => 'Baru Dilantik'], 'IV/c', '2026-05-02');

            $april = (new StrukturService())->pohon($this->admin(), ['bulan' => '2026-04']);
            $this->assertSame(['2026-04', '2026-04-30', ''], [$april['bulan'], $april['tanggal'], $april['data']['sekretaris']['pejabat']], 'belum dilantik pada akhir April');

            $kini = (new StrukturService())->pohon($this->admin());
            $this->assertSame(['2026-10', '2026-10-13'], [$kini['bulan'], $kini['tanggal']]);
            $this->assertStringContainsString($nip, $kini['data']['sekretaris']['pejabat']);
            // Keputusan user 13-10-2026: mulai 2025-01 sampai templat legacy lama di-port.
            $this->assertSame(['2026-10', '2025-01'], [$kini['bulan_tersedia'][0]['value'], $kini['bulan_tersedia'][count($kini['bulan_tersedia']) - 1]['value']]);

            // CR-050 (QA C-F1): bentuk array juga 422 (bukan diam-diam bulan berjalan).
            foreach (['2024-12', '2026-11', 'Okt', ['2026-09'], 5] as $salah) {
                $this->assertSame(422, $this->statusDari(fn () => (new StrukturService())->pohon($this->admin(), ['bulan' => $salah])), (string) json_encode($salah));
            }

            $pdf = (new StrukturService())->pdf($this->admin(), ['id_unit_satker' => (string) $this->unitDep]);
            $this->assertStringStartsWith('%PDF-', $pdf['isi']);
            $this->assertSame("struktur_organisasi_{$this->unitDep}_2026-10.pdf", $pdf['nama']);

            [, , $akun] = $this->aktor(Role::PEGAWAI);
            $res        = $this->asUser($akun)->get('api/v1/struktur-organisasi/pdf?bulan=2026-09');
            $res->assertStatus(200);
            $this->assertStringStartsWith('%PDF-', (string) $res->response()->getBody());
        } finally {
            Time::setTestNow();
        }
    }

    public function testEndpointTanpaLogin401(): void
    {
        $this->get('api/v1/struktur-organisasi')->assertStatus(401);
    }

    public function testEndpointSemuaRoleLogin(): void
    {
        foreach (Role::all() as $role) {
            [, , $akun] = $this->aktor($role);
            $this->asUser($akun)->get('api/v1/struktur-organisasi')->assertStatus(200);
        }

        [, , $akun] = $this->aktor(Role::PEGAWAI);
        $this->asUser($akun)->get('api/v1/struktur-organisasi?id_unit_satker=abc')->assertStatus(422);
    }

    /**
     * @param array<string, mixed> $baris
     */
    private function pastikan(string $tabel, array $baris): void
    {
        $pk = (string) array_key_first($baris);

        if ($this->db->table($tabel)->where($pk, $baris[$pk])->countAllResults() === 0) {
            $this->db->table($tabel)->insert($baris);
        }
    }

    private function jabatan(string $kunci, string $nama, int $satker, int $eselon, ?string $atasan, int $level): void
    {
        $sub = $eselon === 1 ? 1 : $this->subGroup($eselon);
        $this->db->table('jabatan')->insert(['id_group_jabatan' => 1, 'id_sub_group_jabatan' => $sub, 'id_satker' => $satker, 'jabatan' => $nama, 'status' => 1]);
        $this->jab[$kunci] = (int) $this->db->insertID();
        $this->db->table('struktur_jabatan')->insert([
            'id_periode_struktur_jabatan' => $this->idPeriode,
            'id_jabatan'                  => $this->jab[$kunci],
            'id_jabatan_atasan'           => $atasan === null ? null : $this->jab[$atasan],
            'periode_struktur_jabatan'    => 'Periode Uji',
            'jabatan'                     => $nama,
            'level_struktur'              => $level,
            'urutan'                      => 1,
            'status'                      => 1,
        ]);
    }

    private function subGroup(int $eselon): int
    {
        $nama = "Eselon {$eselon} Uji";
        $row  = $this->db->table('sub_group_jabatan')->where('sub_group_jabatan', $nama)->get()->getRowArray();

        if ($row !== null) {
            return (int) $row['id_sub_group_jabatan'];
        }

        $this->db->table('sub_group_jabatan')->insert(['id_group_jabatan' => 1, 'sub_group_jabatan' => $nama, 'need_satker' => 1, 'status' => 1]);

        return (int) $this->db->insertID();
    }

    /**
     * Pegawai (jenis status 1, dibuat 2020) + riwayat jabatan disetujui pada jabatan $kunci.
     *
     * @param array<string, mixed> $pegawai
     */
    private function pejabatDefinitif(string $kunci, array $pegawai = [], ?string $gol = null, string $tmt = '2021-01-01'): string
    {
        $nip = $this->buatPegawai($pegawai + ['id_jenis_status' => 1, 'created_at' => '2020-01-01 00:00:00']);
        $this->buatRiwayatMutasiJabatan($nip, ['id_jabatan' => $this->jab[$kunci], 'id_group_jabatan' => 1, 'tmtsk' => $tmt]);

        if ($gol !== null) {
            $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'gol_ruang' => $gol, 'tmtsk' => '2020-04-01', 'status' => 1]);
        }

        return $nip;
    }

    private function penugasan(string $jenis, string $nip, string $jabatan, string $mulai = '-1 day', string $akhir = '+30 days', int $status = 1): void
    {
        $this->db->table("pegawai_{$jenis}")->insert([
            "nip_{$jenis}"  => $nip,
            'jenis_jabatan' => 1,
            'id_jabatan'    => $this->jab[$jabatan],
            'tgl_mulai'     => Time::today()->modify($mulai)->toDateString(),
            'tgl_akhir'     => Time::today()->modify($akhir)->toDateString(),
            'status'        => $status,
        ]);
    }

    private function statusDari(Closure $aksi): int
    {
        try {
            $aksi();
        } catch (ApiException $e) {
            return $e->getStatusCode();
        }

        return 200;
    }
}
