<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Jabatan;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use Closure;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatBtcTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-07 Riwayat Jabatan / Mutasi (WS-2 M4 MAKE-012) di atas engine-v1: B-TC generik, aturan legacy `L_jabatan.php`
 * (wajib bersyarat, TMT unik jenis ≠ 3, salinan nama G-02, PLT, PPPK, lampiran 9/36/41) dan snapshot
 * `pegawai_mutasi_jabatan` (DBV-012 §5.1). PegawaiScope + AttachmentService/LocalStorageAdapter nyata lewat
 * RiwayatUjiTrait::pasangEngineNyata().
 *
 * ID group jabatan legacy di-hard-code (1 Struktural, 2 JFT, 3 JFU) dan satker 39 (dipekerjakan) — dibuat eksplisit
 * di setUp() agar fixture lain (AUTO_INCREMENT) tidak kebetulan memakai ID itu.
 *
 * @internal
 */
final class JabatanTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatBtcTrait;
    use RiwayatUjiTrait;

    private Jabatan $d;
    private string $nip;
    private AuthContext $admin;
    private AuthContext $adminSatker;

    /**
     * @var array{id_unit: int, id_satker: int, id_group_jabatan: int, id_sub_group_jabatan: int, id_jabatan: int}
     */
    private array $struk;

    /**
     * @var array{id_sub_group_jabatan: int, id_jabatan: int}
     */
    private array $jft;

    private int $idRumpun;

    protected function setUp(): void
    {
        parent::setUp();

        $this->d = new Jabatan();
        $this->pasangEngineNyata([$this->d]);
        $this->siapkanMasterJabatan();

        $this->nip         = $this->buatPegawaiDiSatker($this->struk['id_satker']);
        $this->admin       = $this->aktor(Role::SUPER_ADMIN)[1];
        $this->adminSatker = $this->aktor(Role::ADMIN_SATKER, [], $this->struk['id_satker'])[1];
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();

        parent::tearDown();
    }

    // ---- B-TC ----------------------------------------------------------

    protected function btcDefinisi(): RiwayatDefinisi
    {
        return $this->d;
    }

    protected function btcPayload(string $nip): array
    {
        return $this->dataStruk('2016-01-01');
    }

    /**
     * Rantai jabatan snapshot pegawai $nip sendiri: setujui tidak memindahkan pegawai keluar satker admin satker (lingkup
     * PegawaiScope dibaca dari pegawai_mutasi_jabatan).
     */
    protected function btcBaris(string $nip): array
    {
        $pmj = $this->snapshotUji('pegawai_mutasi_jabatan', $nip) ?? [];
        $ids = array_intersect_key($pmj, array_flip(['id_group_jabatan', 'id_sub_group_jabatan', 'id_unit', 'id_satker', 'id_jabatan', 'unit', 'satker', 'jabatan']));

        return array_merge($this->dataStruk('2016-01-01'), $ids);
    }

    protected function btcBerkas(): array
    {
        return [Jabatan::LAMPIRAN_SK => $this->berkasUji('sk.pdf')];
    }

    protected function btcScopeRole3(array $nipDalam): null
    {
        return null;
    }

    /**
     * Matriks v2 (1, 2, 3, 4, 5) × PegawaiScope: pegawai lain di satker lain terlihat oleh role 1, 4, 5.
     */
    protected function btcHarapanLihat(int $role): bool
    {
        return in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_VIEW_ESELON1, Role::MENTERI], true);
    }

    // ---- Aturan B-07 ---------------------------------------------------

    public function testIzinLihatMatriksTulisIkutLegacy(): void
    {
        // Keputusan user 13-10-2026: lihat Matriks v2; tulis/proses legacy (add 1, edit 1/3, delete 1, process 1/3).
        $harapan = [
            'lihat'  => [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI],
            'tambah' => [Role::SUPER_ADMIN],
            'ubah'   => [Role::SUPER_ADMIN, Role::ADMIN_SATKER],
            'hapus'  => [Role::SUPER_ADMIN],
            'proses' => [Role::SUPER_ADMIN, Role::ADMIN_SATKER],
        ];

        foreach (Role::all() as $role) {
            foreach ($harapan as $aksi => $roles) {
                $this->assertSame(in_array($role, $roles, true), $this->d->boleh(AksiRiwayat::from($aksi), $role), "{$aksi} {$role}");
            }
        }

        try {
            $this->engine()->tambah($this->adminSatker, $this->nip, 'jabatan', $this->dataStruk('2018-03-01'), [Jabatan::LAMPIRAN_SK => $this->berkasUji('sk.pdf')]);
            $this->fail('403 diharapkan');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function testTambahMenyalinNamaMasterDanSnapshot(): void
    {
        $row = $this->tambah($this->dataStruk('2018-03-01'));

        $this->assertSame('1', (string) $row['status']);
        $this->assertSame(
            ['Unit Jabatan Uji', 'Satker Jabatan Uji', 'Struktural', 'Eselon III Uji', 'Kepala Bagian Uji', 'Rumpun Uji', '9'],
            [$row['unit'], $row['satker'], $row['group_jabatan'], $row['sub_group_jabatan'], $row['jabatan'], $row['rumpun_jabatan'], (string) $row['kelas_jabatan']],
        );
        $this->assertNull($row['no_induk_jft'], 'atribut JFT hanya untuk group JFT');

        $snap = $this->snapshotUji('pegawai_mutasi_jabatan', $this->nip);
        $this->assertSame((string) $row['id_riwayat_mutasi_jabatan'], (string) $snap['id_riwayat_mutasi_jabatan']);
        $this->assertSame(['Kepala Bagian Uji', '2018-03-01', (string) $this->struk['id_satker']], [$snap['jabatan'], $snap['tmtsk'], (string) $snap['id_satker']]);
    }

    public function testKlienTidakBisaMenimpaNamaMasterJenisNomenklatur(): void
    {
        $row = $this->tambah($this->dataStruk('2018-03-01') + ['jabatan' => 'Palsu', 'unit' => 'Palsu', 'nama_instansi' => 'Palsu']);

        $this->assertSame(['Kepala Bagian Uji', 'Unit Jabatan Uji'], [$row['jabatan'], $row['unit']]);
        $this->assertNotSame('Palsu', $row['nama_instansi']);
    }

    public function testPltTidakMasukSnapshotDanBerawalanPlt(): void
    {
        $aktif = $this->tambah($this->dataStruk('2018-03-01'));
        $plt   = $this->tambah(['jenis_jabatan' => Jabatan::JENIS_PLT] + $this->dataStruk('2020-01-01'));

        $this->assertSame('PLT Kepala Bagian Uji', $plt['jabatan']);
        $this->assertSame((string) $aktif['id_riwayat_mutasi_jabatan'], (string) $this->snapshotUji('pegawai_mutasi_jabatan', $this->nip)['id_riwayat_mutasi_jabatan']);

        // TMT boleh kembar dengan Plt (legacy hanya memeriksa jenis ≠ 3).
        $this->tambah(['jenis_jabatan' => Jabatan::JENIS_PLT] + $this->dataStruk('2018-03-01'));
    }

    public function testTmtTidakBolehKembarKecualiDitolakAtauDihapus(): void
    {
        $a = $this->tambah($this->dataStruk('2018-03-01'));

        $this->assertErrors(['tmtsk'], fn () => $this->tambah($this->dataStruk('2018-03-01')));

        $this->engine()->hapus($this->admin, $this->nip, 'jabatan', (int) $a['id_riwayat_mutasi_jabatan']);
        $this->tambah($this->dataStruk('2018-03-01'));
    }

    public function testSnapshotTmtTerbaruLaluYangDitulisBelakanganMenang(): void
    {
        $lama = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['tmtsk' => '2019-01-01', 'status' => 1, 'updated_at' => '2020-01-01 00:00:00']));
        $baru = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['tmtsk' => '2019-01-01', 'status' => 0, 'updated_at' => '2019-06-01 00:00:00']));
        $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['tmtsk' => '2010-01-01', 'status' => 1]));

        // Baris bertanggal sama yang disetujui belakangan (updated_at terbaru) menang — seri ">=" legacy.
        $this->engine()->proses($this->admin, $this->nip, 'jabatan', $baru, 'setujui', null);
        $this->assertSame((string) $baru, (string) $this->snapshotUji('pegawai_mutasi_jabatan', $this->nip)['id_riwayat_mutasi_jabatan']);

        // Keluar dari status 1 → dipilih ulang: baris lama bertanggal sama.
        $this->engine()->hapus($this->admin, $this->nip, 'jabatan', $baru);
        $this->assertSame((string) $lama, (string) $this->snapshotUji('pegawai_mutasi_jabatan', $this->nip)['id_riwayat_mutasi_jabatan']);
    }

    public function testWajibBersyaratNomenklatur(): void
    {
        $this->assertErrors(
            ['jenis_mutasi', 'id_group_jabatan', 'id_sub_group_jabatan', 'id_unit', 'id_satker', 'id_rumpun_jabatan', 'id_jabatan'],
            fn () => $this->tambah(['jenis_jabatan' => 1, 'tmtsk' => '2018-03-01', 'no_sk' => 'SK-1', 'tgl_sk' => '2018-02-01']),
        );
    }

    public function testInstansiLainTeksBebasDanKolomNomenklaturKosong(): void
    {
        $this->assertErrors(['nama_instansi', 'unit', 'satker', 'jabatan'], fn () => $this->tambah([
            'jenis_jabatan' => 2, 'tmtsk' => '2012-01-01', 'no_sk' => 'SK-L', 'tgl_sk' => '2012-01-01',
        ]));

        // Isian nomenklatur yang ikut terkirim dibuang untuk Instansi Lain.
        $row = $this->tambah(array_merge($this->dataStruk('2012-01-01'), [
            'jenis_jabatan' => 2, 'nama_instansi' => 'Kementerian Lain', 'unit' => 'Unit L', 'satker' => 'Satker L', 'jabatan' => 'Analis L',
        ]));

        $this->assertSame(['Kementerian Lain', 'Unit L', 'Satker L', 'Analis L'], [$row['nama_instansi'], $row['unit'], $row['satker'], $row['jabatan']]);
        $this->assertNull($row['id_jabatan']);
        $this->assertNull($row['id_satker']);
        $this->assertSame('Rumpun Uji', $row['rumpun_jabatan']);
    }

    public function testJftWajibAtributDanSkPembebasanSementara(): void
    {
        $jft = ['id_group_jabatan' => 2, 'id_sub_group_jabatan' => $this->jft['id_sub_group_jabatan'], 'id_jabatan' => $this->jft['id_jabatan']] + $this->dataStruk('2021-01-01');

        $this->assertErrors(['jenis_jabatan_koord', 'no_induk_jft', 'status_jft'], fn () => $this->tambah($jft));

        $jft += ['jenis_jabatan_koord' => 3, 'no_induk_jft' => 'JFT-01', 'status_jft' => 2];
        $this->assertErrors(['berkas.36'], fn () => $this->tambah($jft));

        $row = $this->tambah($jft, [Jabatan::LAMPIRAN_SK => $this->berkasUji('sk.pdf'), Jabatan::LAMPIRAN_PS_JFT => $this->berkasUji('ps.pdf')]);
        $this->assertSame(['JFT-01', '2'], [$row['no_induk_jft'], (string) $row['status_jft']]);
    }

    public function testJabatanKoordinasiDisalinDanDivalidasi(): void
    {
        $koord = $this->buatJabatanKoordinasi($this->struk['id_satker'], ['jabatan' => 'Koordinator Uji']);
        $data  = ['id_group_jabatan' => 2, 'id_sub_group_jabatan' => $this->jft['id_sub_group_jabatan'], 'id_jabatan' => $this->jft['id_jabatan'],
            'jenis_jabatan_koord'    => 1, 'no_induk_jft' => 'JFT-02', 'status_jft' => 1] + $this->dataStruk('2021-01-01');

        $this->assertErrors(['id_jabatan_koord'], fn () => $this->tambah($data));
        $this->assertErrors(['id_jabatan_koord'], fn () => $this->tambah($data + ['id_jabatan_koord' => 999999]));

        $row = $this->tambah($data + ['id_jabatan_koord' => $koord]);
        $this->assertSame([(string) $koord, 'Koordinator Uji'], [(string) $row['id_jabatan_koord'], $row['jabatan_koord']]);
    }

    public function testAtasanDiturunkanDariStrukturDanEksplisitDivalidasi(): void
    {
        // Rantai: Es.I (level 2) → Es.II (level 3) → jabatan uji (level 4).
        $this->db->table('periode_struktur_jabatan')->insert(['periode_struktur_jabatan' => 'Periode Atasan Uji', 'status' => 1]);
        $periode = (int) $this->db->insertID();
        $es1     = $this->buatJabatan($this->struk['id_satker'])['id_jabatan'];
        $es2     = $this->buatJabatan($this->struk['id_satker'])['id_jabatan'];
        $simpul  = fn (int $jab, ?int $atasan, int $level) => $this->db->table('struktur_jabatan')->insert([
            'id_periode_struktur_jabatan' => $periode, 'id_jabatan' => $jab, 'id_jabatan_atasan' => $atasan,
            'periode_struktur_jabatan'    => 'Periode Atasan Uji', 'jabatan' => 'x', 'level_struktur' => $level, 'urutan' => 1, 'status' => 1,
        ]);
        $simpul($es1, null, 2);
        $simpul($es2, $es1, 3);
        $simpul($this->struk['id_jabatan'], $es2, 4);

        $row = $this->tambah($this->dataStruk('2018-03-01'));
        $this->assertSame([(string) $es1, (string) $es2, null, null], [(string) $row['id_atasan_es_1'], (string) $row['id_atasan_es_2'], $row['id_atasan_es_3'], $row['id_atasan_es_4']]);
        $this->assertNotNull($row['atasan_es_2']);

        // Eksplisit: harus simpul struktur periode aktif ber-level yang sesuai.
        $this->assertErrors(['id_atasan_es_1'], fn () => $this->tambah(['id_atasan_es_1' => $es2] + $this->dataStruk('2019-03-01')));
        $es2Lain = $this->buatJabatan($this->struk['id_satker'])['id_jabatan'];
        $simpul($es2Lain, $es1, 3);
        $row = $this->tambah(['id_atasan_es_2' => $es2Lain] + $this->dataStruk('2019-03-01'));
        $this->assertSame((string) $es2Lain, (string) $row['id_atasan_es_2'], 'nilai dari klien menang atas turunan');
        $this->assertSame((string) $es1, (string) $row['id_atasan_es_1']);

        // Ubah tanpa perubahan posisi tidak menurunkan ulang.
        $ubah = $this->engine()->ubah($this->admin, $this->nip, 'jabatan', (int) $row['id_riwayat_mutasi_jabatan'], ['no_sk' => 'SK-B']);
        $this->assertSame((string) $es2Lain, (string) $ubah['id_atasan_es_2']);
    }

    public function testJabatanHarusDiSubGroupDanSatkerTerpilih(): void
    {
        $lain = $this->buatJabatan();

        $this->assertErrors(['id_jabatan'], fn () => $this->tambah(['id_jabatan' => $lain['id_jabatan']] + $this->dataStruk('2018-03-01')));
    }

    public function testSatkerDipekerjakanMemakaiJabatanTeks(): void
    {
        $data = ['id_unit' => $this->idUnitDipekerjakan(), 'id_satker' => Jabatan::SATKER_DIPEKERJAKAN] + $this->dataStruk('2018-03-01');
        unset($data['id_jabatan']);

        $this->assertErrors(['jabatan'], fn () => $this->tambah($data));

        $row = $this->tambah($data + ['jabatan' => 'Tenaga Ahli Dipekerjakan']);
        $this->assertSame('Tenaga Ahli Dipekerjakan', $row['jabatan']);
        $this->assertNull($row['id_jabatan']);
    }

    public function testPppkWajibGolonganDanNonPppkDikosongkan(): void
    {
        $idGol = (int) $this->db->table('gol_pppk')->select('id_gol_pppk')->get()->getRow()?->id_gol_pppk;

        if ($idGol === 0) {
            $this->db->table('gol_pppk')->insert(['gol_pppk' => 'IX', 'status' => 1]);
            $idGol = (int) $this->db->insertID();
        }

        // Non-PPPK: golongan & masa perjanjian dibuang (legacy set_param).
        $row = $this->tambah($this->dataStruk('2018-03-01') + ['id_gol_pppk' => $idGol, 'pmk' => 2020]);
        $this->assertSame([null, null, null], [$row['id_gol_pppk'], $row['gol_pppk'], $row['pmk']]);

        if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', Jabatan::JENIS_PEGAWAI_PPPK)->countAllResults() === 0) {
            $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => Jabatan::JENIS_PEGAWAI_PPPK, 'jenis_pegawai' => 'PPPK', 'status' => 1]);
        }

        $this->db->table('pegawai')->where('nip', $this->nip)->update(['id_jenis_pegawai' => Jabatan::JENIS_PEGAWAI_PPPK]);
        $this->assertErrors(['id_gol_pppk'], fn () => $this->tambah($this->dataStruk('2019-03-01')));

        $row = $this->tambah($this->dataStruk('2019-03-01') + ['id_gol_pppk' => $idGol, 'pmk' => 2020]);
        $this->assertSame([(string) $idGol, '2020'], [(string) $row['id_gol_pppk'], (string) $row['pmk']]);
        $this->assertNotNull($row['gol_pppk']);
    }

    public function testSkWajibDanLampiranTigaKode(): void
    {
        $this->assertErrors(['berkas.9'], fn () => $this->engine()->tambah($this->admin, $this->nip, 'jabatan', $this->dataStruk('2018-03-01')));

        $kode = array_map(static fn ($a): int => $a->idRiwayat, $this->d->lampiran());
        $this->assertSame([9, 36, 41], $kode);
        $this->assertSame(['ser_dosen', 'f_spmt', 'f_bapel'], array_keys($this->d->kolomBerkas()));
    }

    public function testAdminSatkerTidakBisaMenghapusTetapiBisaMengubah(): void
    {
        $id = (int) $this->tambah($this->dataStruk('2018-03-01'))['id_riwayat_mutasi_jabatan'];

        try {
            $this->engine()->hapus($this->adminSatker, $this->nip, 'jabatan', $id);
            $this->fail('403 diharapkan');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame('SK-X', $this->engine()->ubah($this->adminSatker, $this->nip, 'jabatan', $id, ['no_sk' => 'SK-X'])['no_sk']);
        $this->assertSame('SK-X', $this->snapshotUji('pegawai_mutasi_jabatan', $this->nip)['no_sk']);
    }

    public function testRole1MemilihStatusSaatUbah(): void
    {
        $id  = (int) $this->tambah($this->dataStruk('2018-03-01'))['id_riwayat_mutasi_jabatan'];
        $row = $this->engine()->ubah($this->admin, $this->nip, 'jabatan', $id, ['status' => '2', 'reason_note' => 'SK keliru']);

        $this->assertSame('2', (string) $row['status']);
        $this->assertNull($this->snapshotUji('pegawai_mutasi_jabatan', $this->nip), 'satu-satunya baris keluar dari status 1');
    }

    public function testDaftarUrutStatusLaluTmt(): void
    {
        $a = (int) $this->tambah($this->dataStruk('2019-03-01'))['id_riwayat_mutasi_jabatan'];
        $b = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['tmtsk' => '2023-01-01', 'status' => 0]));
        $c = (int) $this->tambah($this->dataStruk('2015-03-01'))['id_riwayat_mutasi_jabatan'];

        $ids = array_map('intval', array_column($this->engine()->daftar($this->admin, $this->nip, 'jabatan'), 'id_riwayat_mutasi_jabatan'));
        $this->assertSame([$b, $c, $a], $ids);
    }

    // ---- Helper ----------------------------------------------------------

    private function siapkanMasterJabatan(): void
    {
        foreach ([1 => 'Struktural', 2 => 'Jabatan Fungsional Tertentu', 3 => 'Jabatan Fungsional Umum'] as $id => $nama) {
            if ($this->db->table('group_jabatan')->where('id_group_jabatan', $id)->countAllResults() === 0) {
                $this->db->table('group_jabatan')->insert(['id_group_jabatan' => $id, 'group_jabatan' => $nama, 'status' => 1]);
            }
        }

        $idUnit   = $this->buatUnit(['unit' => 'Unit Jabatan Uji']);
        $idSatker = $this->buatSatker($idUnit, ['satker' => 'Satker Jabatan Uji']);
        $subStruk = $this->sisip('sub_group_jabatan', ['id_group_jabatan' => 1, 'sub_group_jabatan' => 'Eselon III Uji', 'need_satker' => 1, 'status' => 1]);
        $subJft   = $this->sisip('sub_group_jabatan', ['id_group_jabatan' => 2, 'sub_group_jabatan' => 'JFT Uji', 'need_satker' => 2, 'status' => 1]);

        if ($this->db->table('kelas_jabatan')->where('kelas_jabatan', 9)->countAllResults() === 0) {
            $this->db->table('kelas_jabatan')->insert(['kelas_jabatan' => 9, 'tukin' => 1000000, 'status' => 1]);
        }

        $this->struk = [
            'id_unit'              => $idUnit,
            'id_satker'            => $idSatker,
            'id_group_jabatan'     => 1,
            'id_sub_group_jabatan' => $subStruk,
            'id_jabatan'           => $this->sisip('jabatan', ['id_group_jabatan' => 1, 'id_sub_group_jabatan' => $subStruk, 'id_satker' => $idSatker, 'kelas_jabatan' => 9, 'jabatan' => 'Kepala Bagian Uji', 'status' => 1]),
        ];
        $this->jft = [
            'id_sub_group_jabatan' => $subJft,
            'id_jabatan'           => $this->sisip('jabatan', ['id_group_jabatan' => 2, 'id_sub_group_jabatan' => $subJft, 'jabatan' => 'Analis Uji', 'status' => 1]),
        ];
        $this->idRumpun = $this->buatRumpunJabatan(['rumpun_jabatan' => 'Rumpun Uji']);
    }

    private function idUnitDipekerjakan(): int
    {
        $row = $this->db->table('satker')->where('id_satker', Jabatan::SATKER_DIPEKERJAKAN)->get()->getRowArray();

        if ($row !== null) {
            return (int) $row['id_unit'];
        }

        $idUnit = $this->buatUnit();
        $this->db->table('satker')->insert(['id_satker' => Jabatan::SATKER_DIPEKERJAKAN, 'id_unit' => $idUnit, 'satker' => 'Dipekerjakan Uji', 'status' => 1]);

        return $idUnit;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sisip(string $tabel, array $data): int
    {
        $this->db->table($tabel)->insert($data);

        return (int) $this->db->insertID();
    }

    /**
     * @return array<string, int|string>
     */
    private function dataStruk(string $tmt): array
    {
        return [
            'jenis_jabatan'        => Jabatan::JENIS_NOMENKLATUR,
            'jenis_mutasi'         => 1,
            'tmtsk'                => $tmt,
            'no_sk'                => 'SK/JAB/' . $tmt,
            'tgl_sk'               => $tmt,
            'id_group_jabatan'     => $this->struk['id_group_jabatan'],
            'id_sub_group_jabatan' => $this->struk['id_sub_group_jabatan'],
            'id_unit'              => $this->struk['id_unit'],
            'id_satker'            => $this->struk['id_satker'],
            'id_jabatan'           => $this->struk['id_jabatan'],
            'id_rumpun_jabatan'    => $this->idRumpun,
        ];
    }

    /**
     * @param array<string, mixed>          $data
     * @param array<int, UploadedFile>|null $berkas null = SK jabatan PDF
     *
     * @return array<string, mixed>
     */
    private function tambah(array $data, ?array $berkas = null): array
    {
        return $this->engine()->tambah($this->admin, $this->nip, 'jabatan', $data, $berkas ?? [Jabatan::LAMPIRAN_SK => $this->berkasUji('sk.pdf')]);
    }

    private function engine(): RiwayatEngine
    {
        /** @var RiwayatEngine $engine */
        $engine = service('riwayatService');

        return $engine;
    }

    /**
     * @param list<string> $fields
     */
    private function assertErrors(array $fields, Closure $aksi): void
    {
        try {
            $aksi();
        } catch (ValidationException $e) {
            $this->assertSame($fields, array_keys($e->getErrors() ?? []), (string) json_encode($e->getErrors()));

            return;
        }

        $this->fail('Diharapkan 422 untuk ' . implode(', ', $fields));
    }
}
