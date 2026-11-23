<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Libraries\Presensi\SlipGaji\KolomGaji;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\ImporSlipUjiTrait;
use Tests\Support\Presensi\XlsxMentah;

/**
 * CR-177 — berkas impor Slip Gaji yang kecil tetapi "lebar" (sel di kolom XFD) ditolak 422 `errors.berkas` di pratinjau,
 * bukan 500 karena memori/waktu habis, dan berkas pratinjau tidak tertinggal. Rincian pembaca:
 * `Tests\Unit\Presensi\Slip\PembacaExcelSlipBatasBacaTest`. Data & NIP sintetis.
 *
 * CR-181 — berkas yang rumusnya terlalu berat untuk dihitung (satu sel `=SUM(GPP!J2:J1048576)`) juga 422 `errors.berkas`,
 * bukan 500 karena waktu/memori habis. Rincian: `Tests\Unit\Presensi\Slip\PembacaExcelSlipAnggaranRumusTest`.
 *
 * @internal
 */
final class ImporSlipBatasBacaTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use ImporSlipUjiTrait;
    use PegawaiFixtureTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanImporSlip();
    }

    protected function tearDown(): void
    {
        $this->bersihkanImporSlip();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testBerkasLebarDitolak422DiPratinjau(): void
    {
        $nip    = $this->buatPegawai(['nama' => 'Fajar Nugroho']);
        $admin  = $this->adminSlip();
        $gpp    = ['nip', 'bulan', 'tahun', 'nama', ...array_keys(KolomGaji::SUMBER_GPP)];
        $tk     = ['nip', 'bulan', 'tahun', ...array_keys(KolomGaji::SUMBER_TK)];
        $isiGpp = [$nip, 9, 2026, 'Fajar Nugroho', ...array_fill(0, count(KolomGaji::SUMBER_GPP), 0)];
        $path   = $this->tempSlip('.xlsx');
        XlsxMentah::tulis($path, [
            'GPP' => substr(XlsxMentah::baris(1, $gpp), 0, -strlen('</row>')) . XlsxMentah::sel('XFD1', 'x') . '</row>' . XlsxMentah::baris(2, $isiGpp) . XlsxMentah::baris(10001, $isiGpp),
            'TK'  => XlsxMentah::baris(1, $tk) . XlsxMentah::baris(2, [$nip, 9, 2026, ...array_fill(0, count(KolomGaji::SUMBER_TK), 0)]),
        ]);

        memory_reset_peak_usage();
        $awal = memory_get_peak_usage();
        $res  = $this->unggahSlip($admin, $this->unggahanSlip($path, 'slip_lebar.xlsx'));

        $res->assertStatus(422);
        $this->assertSame(['berkas' => ['Jumlah kolom melebihi batas 256 per sheet.']], $this->json($res)['errors']);
        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage() - $awal, 'kenaikan puncak memori');
        $this->assertSame([], $this->isiDirPratinjau(), 'berkas pratinjau tidak tertinggal');
        $this->assertSame(0, $this->db->table('gaji_pegawai')->countAllResults());
    }

    public function testRumusTerlaluBeratDitolak422DiPratinjau(): void
    {
        $nip   = $this->buatPegawai(['nama' => 'Fajar Nugroho']);
        $admin = $this->adminSlip();
        $gpp   = ['nip', 'bulan', 'tahun', 'nama', ...array_keys(KolomGaji::SUMBER_GPP)];
        $tk    = ['nip', 'bulan', 'tahun', ...array_keys(KolomGaji::SUMBER_TK)];
        $isiTk = XlsxMentah::baris(2, [$nip, 9, 2026, ...array_fill(0, count(KolomGaji::SUMBER_TK), 0)]);
        $path  = $this->tempSlip('.xlsx');
        XlsxMentah::tulis($path, [
            'GPP' => XlsxMentah::baris(1, $gpp) . XlsxMentah::baris(2, [$nip, 9, 2026, 'Fajar Nugroho', ...array_fill(0, count(KolomGaji::SUMBER_GPP), 0)]),
            'TK'  => XlsxMentah::baris(1, $tk) . substr($isiTk, 0, -strlen('</row>')) . XlsxMentah::rumus('M2', '=SUM(GPP!J2:J1048576)') . '</row>',
        ]);

        memory_reset_peak_usage();
        $awalMemori = memory_get_peak_usage();
        $awal       = microtime(true);
        $res        = $this->unggahSlip($admin, $this->unggahanSlip($path, 'slip_rumus.xlsx'));

        $res->assertStatus(422);
        $this->assertSame(['berkas' => ['Rumus di sheet GPP/TK terlalu berat untuk dihitung. Tempel sebagai nilai (Paste Values), lalu unggah ulang.']], $this->json($res)['errors']);
        $this->assertLessThan(5.0, microtime(true) - $awal, 'ditolak sebelum dihitung');
        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage() - $awalMemori, 'kenaikan puncak memori');
        $this->assertSame([], $this->isiDirPratinjau(), 'berkas pratinjau tidak tertinggal');
        $this->assertSame(0, $this->db->table('gaji_pegawai')->countAllResults());
    }
}
