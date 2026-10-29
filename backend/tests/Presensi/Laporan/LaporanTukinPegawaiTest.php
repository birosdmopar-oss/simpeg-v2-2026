<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Pdf\Pdf;
use App\Libraries\Presensi\Laporan\LaporanTukinService;
use Config\Presensi;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\LaporanTukinUjiTrait;
use Tests\Support\Presensi\RekapUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §7.3.2, AC-D08.1 (akses per pegawai) & AC-D08.6 (PDF per pegawai, MAKE-041): role 2/6/7 hanya NIP sendiri (403,
 * tidak dialihkan), role 3 lingkup satker (403), role 1/4/5 semua, NIP tidak ada 404, role 8 403; JSON persen tanpa uang
 * makan; PDF render legacy — tanpa "UANG MAKAN", "Rp." hanya baris konket kategori 13 yang dibayar menurut aturan slip
 * (kategori lain tidak; setelah penanda TB aktif di bulan kalender yang sama tidak; periode 16–15 melintasi dua bulan
 * kalender → tarif masing-masing bulan).
 *
 * @internal
 */
final class LaporanTukinPegawaiTest extends DatabaseTestCase
{
    use LaporanTukinUjiTrait;
    use PegawaiFixtureTrait;
    use RekapUjiTrait;
    use TukinDataUjiTrait;

    private int $satker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLaporanTukin();
        $this->satker = $this->buatSatker($this->buatUnit());
    }

    protected function tearDown(): void
    {
        $this->bersihkanLaporanTukin();
        parent::tearDown();
    }

    public function testAksesPerRole(): void
    {
        $milik = $this->pegawaiRekap($this->satker);
        $lain  = $this->pegawaiRekap($this->buatSatker($this->buatUnit()));
        $q     = ['bulan' => '2026-09'];

        foreach ([Role::PEGAWAI, Role::PTT, Role::PPPK] as $role) {
            $this->assertSame($milik, $this->serviceLaporanTukin()->laporan($this->akun($role, $milik), $milik, $q)['identitas']['nip']);
            $this->assertStatus(403, fn () => $this->serviceLaporanTukin()->laporan($this->akun($role, $milik), $lain, $q), "role {$role} NIP lain");
        }

        foreach ([Role::SUPER_ADMIN, Role::ADMIN_VIEW_ESELON1, Role::MENTERI] as $role) {
            $this->assertSame($lain, $this->serviceLaporanTukin()->laporan($this->adminRekap($role), $lain, $q)['identitas']['nip']);
        }

        $r3 = $this->adminRekap(Role::ADMIN_SATKER, (string) $this->satker);
        $this->assertSame($milik, $this->serviceLaporanTukin()->laporan($r3, $milik, $q)['identitas']['nip']);
        $this->assertStatus(403, fn () => $this->serviceLaporanTukin()->laporan($r3, $lain, $q), 'role 3 di luar satker');
        $this->assertStatus(404, fn () => $this->serviceLaporanTukin()->laporan($this->adminRekap(), '199912312099121099', $q), 'NIP tidak ada');
        $this->assertStatus(403, fn () => $this->serviceLaporanTukin()->laporan($this->adminRekap(Role::PIMPINAN), $lain, $q), 'role 8');
        $this->assertStatus(422, fn () => $this->serviceLaporanTukin()->laporan($this->adminRekap(), $lain, ['bulan' => '2024-03']), 'periode tidak didukung');
    }

    public function testJsonPersenTanpaUangMakan(): void
    {
        $n    = $this->pegawaiRekap($this->satker);
        $data = $this->serviceLaporanTukin()->laporan($this->adminRekap(), $n, ['bulan' => '2026-09']);

        $this->assertSame(['awal' => '2026-08-16', 'akhir' => '2026-09-15', 'dihitung_sampai' => '2026-09-15', 'sumber' => 'bawaan'], $data['periode']);
        $this->assertSame(['nip', 'nama', 'gol_ruang', 'satker', 'jabatan', 'kelas_jabatan', 'id_unit', 'id_satker', 'predikat_skp'], array_keys($data['identitas']));
        $this->assertSame('13.20', $data['total_presensi']);
        $json = (string) json_encode($data);
        $this->assertStringNotContainsString('uang_makan', $json, 'layar persen saja (F5B-8)');
        $this->assertStringNotContainsString('Rp', $json);
    }

    /**
     * CR-075: `harian[].kode` = `HasilHarian::kategori` apa adanya (urutan sama dengan `keterangan`) untuk color-coding FE;
     * `[]` bila tanpa kode kejadian (cuti tanpa potongan, konket, akhir pekan — baris non-kerja sejak CR-076). Field lain
     * tidak berubah.
     */
    public function testHarianMemuatKodeKejadian(): void
    {
        $n = $this->pegawaiRekap($this->satker);
        if ($this->db->table('jenis_cuti')->where('id_jenis_cuti', 3)->countAllResults() === 0) {
            $this->db->table('jenis_cuti')->insert(['id_jenis_cuti' => 3, 'nama_jenis_cuti' => 'Cuti Sakit', 'status' => 1]);
        }
        // Cuti sakit 17-08 s.d. 08-09: hari kerja ke-15+ (04-09 dst.) dipotong CS.
        $this->db->table('riwayat_cuti')->insert(['nip' => $n, 'id_jenis_cuti' => 3, 'nama_jenis_cuti' => 'Cuti Sakit', 'tgl_mulai' => '2026-08-17', 'tgl_akhir' => '2026-09-08', 'lama_cuti' => 17, 'status' => 1]);
        $this->hadir($n, '2026-09-09', '07:45:00', '15:50:00'); // TL1 + PSW1
        $this->hadir($n, '2026-09-10', null, '15:00:00');       // TPM + PSW2
        $this->konketRekap($n, '2026-09-14', 5);
        // 11-09 tanpa presensi → TK; 12-09 Sabtu → akhir pekan (CR-076).

        $harian = array_column($this->serviceLaporanTukin()->laporan($this->adminRekap(), $n, ['bulan' => '2026-09'])['harian'], null, 'tanggal');

        $this->assertSame([], $harian['2026-08-17']['kode'], 'cuti sakit hari pertama tanpa potongan');
        $this->assertSame(['CS'], $harian['2026-09-08']['kode']);
        $this->assertSame(['TL1', 'PSW1'], $harian['2026-09-09']['kode']);
        $this->assertSame(['TPM', 'PSW2'], $harian['2026-09-10']['kode']);
        $this->assertSame(['TK'], $harian['2026-09-11']['kode']);
        $this->assertSame([], $harian['2026-09-14']['kode'], 'konket');
        $this->assertSame(['akhir_pekan', []], [$harian['2026-09-12']['status'], $harian['2026-09-12']['kode']], 'akhir pekan');
        foreach (['2026-09-09', '2026-09-10', '2026-09-11'] as $t) {
            $this->assertSame(implode(' - ', $harian[$t]['kode']), $harian[$t]['keterangan'], "urutan kode = keterangan {$t}");
        }
        $this->assertSame(['tanggal', 'status', 'jam_masuk', 'jam_pulang', 'potongan', 'keterangan', 'kode', 'kategori_konket'], array_keys($harian['2026-09-09']));
    }

    /**
     * CR-076 (keputusan Horii 29-10-2026): `harian[]` memuat setiap tanggal periode seperti legacy
     * [K `L_presensi.php:7302-7345`] — urutan cek di luar masa kerja → libur ("(Libur) <nama>") → Sabtu/Minggu
     * (`akhir_pekan`); baris non-kerja tanpa jam/potongan/kode. Total sama dengan baris rekap (kalkulasi tidak berubah).
     */
    public function testHarianMemuatSetiapTanggalPeriode(): void
    {
        $n = $this->pegawaiRekap($this->satker);
        $this->db->table('hari_libur')->insertBatch([
            ['tgl_mulai' => '2026-08-17', 'tgl_akhir' => '2026-08-17', 'nama_libur' => 'Hari Kemerdekaan Uji', 'status' => 1],
            ['tgl_mulai' => '2026-08-22', 'tgl_akhir' => '2026-08-22', 'nama_libur' => 'Libur Sabtu Uji', 'status' => 1],
            ['tgl_mulai' => '2026-08-24', 'tgl_akhir' => '2026-08-24', 'nama_libur' => 'Libur Nonaktif', 'status' => 2],
        ]);
        $q    = ['bulan' => '2026-09'];
        $data = $this->serviceLaporanTukin()->laporan($this->adminRekap(), $n, $q);

        $tanggal = array_column($data['harian'], 'tanggal');
        $this->assertCount(31, $tanggal, '16-08 s.d. 15-09');
        $this->assertSame('2026-08-16', $tanggal[0]);
        $this->assertSame('2026-09-15', $tanggal[30]);
        $urut = $tanggal;
        sort($urut);
        $this->assertSame($urut, $tanggal, 'urut tanggal');

        $h = array_column($data['harian'], null, 'tanggal');
        $this->assertSame(['tanggal' => '2026-08-16', 'status' => 'akhir_pekan', 'jam_masuk' => null, 'jam_pulang' => null, 'potongan' => '0.00', 'keterangan' => 'Minggu', 'kode' => [], 'kategori_konket' => null], $h['2026-08-16']);
        $this->assertSame(['libur', '(Libur) Hari Kemerdekaan Uji'], [$h['2026-08-17']['status'], $h['2026-08-17']['keterangan']]);
        $this->assertSame(['libur', '(Libur) Libur Sabtu Uji'], [$h['2026-08-22']['status'], $h['2026-08-22']['keterangan']], 'libur didahulukan dari akhir pekan');
        $this->assertSame(['akhir_pekan', 'Minggu'], [$h['2026-08-23']['status'], $h['2026-08-23']['keterangan']]);
        $this->assertSame(['akhir_pekan', 'Sabtu'], [$h['2026-08-29']['status'], $h['2026-08-29']['keterangan']]);
        $this->assertSame('TK', $h['2026-08-24']['status'], 'libur nonaktif tetap hari kerja');

        $rekap = $this->serviceRekapTukin()->daftar($this->adminRekap(), $q)['items'][0];
        $this->assertSame($n, $rekap['nip']);
        $this->assertSame([$rekap['total_presensi'], $rekap['total_potongan']], [$data['total_presensi'], $data['total_potongan']], 'total tidak berubah oleh baris non-kerja');
        $this->assertSame(21, count(array_filter($data['harian'], static fn (array $b): bool => ! in_array($b['status'], ['libur', 'akhir_pekan'], true))), 'hari kerja = 31 − 2 libur − 8 akhir pekan');
    }

    /** CR-076: hari sebelum TMT (CPNS baru) = `di_luar_masa_kerja`, didahulukan dari libur & akhir pekan [K :7302-7310]. */
    public function testHariSebelumTmtDiLuarMasaKerja(): void
    {
        $n = $this->pegawaiRekap($this->satker, ['created_at' => '2026-10-01 00:00:00']);
        $this->db->table('riwayat_kp')->insert(['nip' => $n, 'tmtsk' => '2026-08-26', 'gol' => 'III', 'gol_ruang' => 'CPNS III/a', 'status' => 1]);
        $this->db->table('hari_libur')->insert(['tgl_mulai' => '2026-08-17', 'tgl_akhir' => '2026-08-17', 'nama_libur' => 'Libur Uji', 'status' => 1]);

        $h = array_column($this->serviceLaporanTukin()->laporan($this->adminRekap(), $n, ['bulan' => '2026-09'])['harian'], null, 'tanggal');

        foreach (['2026-08-16', '2026-08-17', '2026-08-22', '2026-08-25'] as $t) {
            $this->assertSame(['di_luar_masa_kerja', ''], [$h[$t]['status'], $h[$t]['keterangan']], $t);
        }
        $this->assertNotSame('di_luar_masa_kerja', $h['2026-08-26']['status'], 'mulai TMT');
        $this->assertSame(['akhir_pekan', 'Sabtu'], [$h['2026-08-29']['status'], $h['2026-08-29']['keterangan']]);
    }

    /** CR-076: PDF memuat baris non-kerja seperti legacy (`print/laporan_tukin_skp_pdf.php:188-205`) dengan warna palet layar. */
    public function testPdfMemuatBarisNonKerjaBerwarna(): void
    {
        $n = $this->pegawaiRekap($this->satker);
        $this->db->table('hari_libur')->insert(['tgl_mulai' => '2026-08-17', 'tgl_akhir' => '2026-08-17', 'nama_libur' => 'Hari Kemerdekaan Uji', 'status' => 1]);

        $this->serviceLaporanTukin()->pdf($this->adminRekap(), $n, ['bulan' => '2026-09']);
        $html = $this->pdfTertangkap[0]->html;

        $this->assertStringContainsString('(Libur) Hari Kemerdekaan Uji', $html);
        $this->assertStringContainsString('>Sabtu<', $html);
        $this->assertStringContainsString('>Minggu<', $html);
        $this->assertStringContainsString('bgcolor="' . LaporanTukinService::WARNA_BARIS_PDF['libur'] . '"', $html);
        $this->assertStringContainsString('bgcolor="' . LaporanTukinService::WARNA_BARIS_PDF['akhir_pekan'] . '"', $html);
        $this->assertSame(10, substr_count($html, 'bgcolor="'), '1 libur + 9 akhir pekan; baris presensi tanpa warna');
    }

    public function testPdfRupiahHanyaKonketKategori13YangDibayar(): void
    {
        $this->aturWebConfig(['uang_makan_gol_3' => '37000', 'uang_makan_gol_4' => '41000']);
        $a = $this->pegawaiRekap($this->satker);
        $this->db->table('riwayat_kp')->insertBatch([
            ['nip' => $a, 'tmtsk' => '2020-01-01', 'gol' => 'III', 'gol_ruang' => 'III/a', 'status' => 1],
            ['nip' => $a, 'tmtsk' => '2026-09-01', 'gol' => 'IV', 'gol_ruang' => 'IV/a', 'status' => 1],
        ]);
        $this->konketRekap($a, '2026-08-20', 13);
        $this->konketRekap($a, '2026-09-02', 13);
        $this->konketRekap($a, '2026-08-24', 5);

        $b = $this->pegawaiRekap($this->satker);
        $this->db->table('riwayat_kp')->insert(['nip' => $b, 'tmtsk' => '2020-01-01', 'gol' => 'III', 'gol_ruang' => 'III/a', 'status' => 1]);
        $this->tbRekap($b, '2025-01-01', '2026-08-18');
        $this->konketRekap($b, '2026-08-20', 13);

        $berkas = $this->serviceLaporanTukin()->pdf($this->adminRekap(), $a, ['bulan' => '2026-09']);
        $this->assertSame(['tukin_' . $a . '_2026-09.pdf', 'application/pdf'], [$berkas['nama'], $berkas['mime']]);
        $html = $this->pdfTertangkap[0]->html;

        $this->assertStringContainsString('Rp. 37.000', $html, 'Agustus: tarif gol III');
        $this->assertStringContainsString('Rp. 41.000', $html, 'September: tarif gol IV (KP 1 Sep)');
        $this->assertSame(2, substr_count($html, 'Rp. '), 'kategori 5 tidak dicetak');
        $this->assertStringNotContainsString('UANG MAKAN', $html);
        $this->assertStringContainsString('TOTAL PENGURANGAN TUKIN', $html);
        $this->assertStringContainsString('POTONGAN<br>PRESENSI', $html);

        $this->serviceLaporanTukin()->pdf($this->adminRekap(), $b, ['bulan' => '2026-09']);
        $this->assertStringNotContainsString('Rp. ', $this->pdfTertangkap[1]->html, 'konket 13 setelah penanda TB di bulan kalender yang sama');
    }

    /**
     * Keputusan Horii 28-10-2026: kop PDF per pegawai ikut legacy berlogo (`MY_PDF_HEADER_UNIT`) — logo `web_config`
     * `logo_kementerian_pdf` dari akar aset (tanpa path di-hard-code), nama kementerian & unit huruf besar, alamat unit;
     * PDF TCPDF nyata memuat objek gambar.
     */
    public function testKopBerlogoIkutLegacy(): void
    {
        $akar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kop_tukin_' . bin2hex(random_bytes(4));
        mkdir($akar);
        $gambar = imagecreatetruecolor(20, 20);
        imagepng($gambar, $akar . DIRECTORY_SEPARATOR . 'logo.png');
        imagedestroy($gambar);

        $config           = new Presensi();
        $config->akarAset = $akar . DIRECTORY_SEPARATOR;
        $this->aturWebConfig(['logo_kementerian_pdf' => 'logo.png', 'nama_kementerian' => 'Kementerian Uji']);
        $this->db->table('unit')->where('id_unit', $this->db->table('satker')->select('id_unit')->where('id_satker', $this->satker)->get()->getRow('id_unit'))->update(['alamat_pdf_header' => 'Jalan Uji No. 1<br>Jakarta']);
        $n       = $this->pegawaiRekap($this->satker);
        $service = new LaporanTukinService($this->db, self::jamRekap('2026-10-27 09:00:00'), null, null, $config);

        try {
            $service->pdf($this->adminRekap(), $n, ['bulan' => '2026-09']);
            $kop = $this->pdfTertangkap[0]->kopHtml;
            $this->assertStringContainsString('<img src="@', $kop, 'logo kiri tersemat');
            $this->assertStringContainsString('<b>KEMENTERIAN UJI</b>', $kop);
            $this->assertMatchesRegularExpression('/<b>UNIT FIXTURE [^<]*<\/b>/', $kop);
            $this->assertStringContainsString('Jalan Uji No. 1<br />' . "\n" . 'Jakarta', $kop);
            $this->assertStringContainsString('height: 2px; border-color: #000000', $kop, 'garis kop legacy');

            Pdf::reset();
            $pdf = $service->pdf($this->adminRekap(), $n, ['bulan' => '2026-09'])['isi'];
            $this->assertStringStartsWith('%PDF', $pdf);
            $this->assertMatchesRegularExpression('/\/Subtype\s*\/Image/', $pdf, 'objek gambar logo di PDF');
        } finally {
            @unlink($akar . DIRECTORY_SEPARATOR . 'logo.png');
            @rmdir($akar);
        }
    }

    private function akun(int $role, string $nip): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => '9301', 'role' => $role, 'nip' => $nip]);

        return $auth;
    }

    private function assertStatus(int $status, callable $aksi, string $pesan): void
    {
        try {
            $aksi();
            $this->fail($pesan . ': harus ' . $status);
        } catch (ApiException $e) {
            $this->assertSame($status, $e->getStatusCode(), $pesan);
        }
    }
}
