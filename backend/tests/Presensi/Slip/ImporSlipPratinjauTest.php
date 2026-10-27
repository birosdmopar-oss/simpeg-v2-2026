<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Libraries\Presensi\Slip\ImporSlipService;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\ImporSlipUjiTrait;

/**
 * PRD 5A §7.2.2–7.2.3 (AC-D10.1, AC-D10.9): unggah + pratinjau impor Slip Gaji — token pratinjau 64 heks, berkas
 * tersimpan dengan nama acak (bukan nama klien), ringkasan & baris dengan nilai desimal kanonik, nama bergelar dari DB,
 * filter periode (lengkap/tidak lengkap/tidak ada di berkas), format `.xlsx` & `.xls`, satu pratinjau aktif per pengguna,
 * dan tidak ada data `gaji_pegawai` yang ditulis saat pratinjau.
 *
 * @internal
 */
final class ImporSlipPratinjauTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use ImporSlipUjiTrait;
    use PegawaiFixtureTrait;

    private string $nip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanImporSlip();
        $this->nip = $this->buatPegawai(['nama' => 'Budi Santoso', 'glr_awal' => 'Dr.', 'glr_akhir' => 'M.Si.']);
    }

    protected function tearDown(): void
    {
        $this->bersihkanImporSlip();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testPratinjauSah(): void
    {
        $berkas = $this->berkasSlip(
            [$this->barisGpp($this->nip, 9, 2026, 'Budi Santoso', ['gjpokok' => 4500000.5, 'tjistri' => '1.234.567'])],
            [$this->barisTk($this->nip, 9, 2026, 'Budi Santoso')],
            namaKlien: '../../rahasia.xlsx',
        );

        $res = $this->unggahSlip($this->adminSlip(), $berkas);
        $res->assertStatus(200);
        $data = $this->json($res)['data'];

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['token_pratinjau']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['kedaluwarsa_at']);
        $this->assertTrue($data['timpa_bawaan']);
        $this->assertNull($data['filter']);
        $this->assertSame(['valid' => 1, 'duplikat' => 0, 'galat' => 0], $data['ringkasan']);
        $this->assertCount(1, $data['baris']);

        $baris = $data['baris'][0];
        $this->assertSame($this->nip, $baris['nip']);
        $this->assertSame('Dr. Budi Santoso, M.Si.', $baris['nama']);
        $this->assertSame('2026-09', $baris['periode']);
        $this->assertSame('valid', $baris['status']);
        $this->assertSame(['sisip', 'sisip'], [$baris['rencana_bila_timpa'], $baris['rencana_bila_tidak_timpa']]);
        $this->assertSame([], $baris['galat']);
        $this->assertCount(28, $baris['nilai']);
        $this->assertSame('4500000.50', $baris['nilai']['gjpokok'], 'float mentah 4500000.5 (AC-D10.9)');
        $this->assertSame('1234567.00', $baris['nilai']['tjistri'], 'teks 1.234.567');
        $this->assertSame('1900000.00', $baris['nilai']['bersih_tk']);

        $isi = $this->isiDirPratinjau();
        sort($isi);
        $nama = hash('sha256', $data['token_pratinjau']);
        $this->assertSame([$nama . '.json', $nama . '.xlsx'], $isi, 'nama simpan = sha256(token), bukan nama klien');
        $this->assertSame(0, $this->db->table('gaji_pegawai')->countAllResults(), 'pratinjau tidak menulis gaji_pegawai');
    }

    public function testFormatXls(): void
    {
        $data = $this->dataOk($this->unggahSlip($this->adminSlip(), $this->berkasSlip(
            [$this->barisGpp($this->nip, 9, 2026, 'Budi Santoso')],
            [$this->barisTk($this->nip, 9, 2026, 'Budi Santoso')],
            'Xls',
        )));

        $this->assertSame(['valid' => 1, 'duplikat' => 0, 'galat' => 0], $data['ringkasan']);
        $this->assertContains(hash('sha256', $data['token_pratinjau']) . '.xls', $this->isiDirPratinjau());
    }

    public function testNipNumerikDitolak(): void
    {
        $gpp           = [$this->barisGpp($this->nip, 9, 2026, 'Budi Santoso'), $this->barisGpp('199001012020011999', 9, 2026, 'Lain')];
        $gpp[1]['nip'] = 199001012020011999;
        $tk            = [$this->barisTk($this->nip, 9, 2026, 'Budi Santoso')];

        $data = $this->dataOk($this->unggahSlip($this->adminSlip(), $this->berkasSlip($gpp, $tk)));

        $this->assertSame(['valid' => 1, 'duplikat' => 0, 'galat' => 1], $data['ringkasan'], json_encode($data['baris']) ?: '');
        $kode = array_merge(...array_map(static fn (array $b): array => array_column($b['galat'], 'kode'), $data['baris']));
        $this->assertContains('nip_bukan_teks', $kode, 'NIP sel numerik ditolak (AC-D10.9)');
    }

    public function testNamaKosongBilaKolomNamaAda(): void
    {
        $data = $this->dataOk($this->unggahSlip($this->adminSlip(), $this->berkasSlip(
            [$this->barisGpp($this->nip, 9, 2026, null)],
            [$this->barisTk($this->nip, 9, 2026, 'Budi Santoso')],
        )));

        $this->assertSame('galat', $data['baris'][0]['status']);
        $this->assertSame(['nama_kosong'], array_column($data['baris'][0]['galat'], 'kode'));
    }

    public function testFilterPeriode(): void
    {
        $admin  = $this->adminSlip();
        $berkas = fn () => $this->berkasSlip(
            [$this->barisGpp($this->nip, 8, 2026, 'Budi'), $this->barisGpp($this->nip, 9, 2026, 'Budi')],
            [$this->barisTk($this->nip, 8, 2026, 'Budi'), $this->barisTk($this->nip, 9, 2026, 'Budi')],
        );

        $data = $this->dataOk($this->unggahSlip($admin, $berkas(), ['filter_bulan' => '9', 'filter_tahun' => '2026']));
        $this->assertSame('2026-09', $data['filter']);
        $this->assertSame(['2026-09'], array_column($data['baris'], 'periode'));

        $res = $this->unggahSlip($admin, $berkas(), ['filter_bulan' => '9']);
        $res->assertStatus(422);
        $this->assertSame('filter_periode_tidak_lengkap', $this->json($res)['code']);
        $this->assertArrayHasKey('filter_bulan', $this->json($res)['errors']);

        $res = $this->unggahSlip($admin, $berkas(), ['filter_bulan' => '13', 'filter_tahun' => '2026']);
        $res->assertStatus(422);
        $this->assertSame('periode_tidak_valid', $this->json($res)['code']);

        $res = $this->unggahSlip($admin, $berkas(), ['filter_bulan' => '1', 'filter_tahun' => '2026']);
        $res->assertStatus(422);
        $this->assertSame('periode_tidak_ada_di_berkas', $this->json($res)['code']);
        $this->assertSame([], $this->isiDirPratinjau(), 'pratinjau gagal dihapus; pratinjau sebelumnya juga diganti');
    }

    public function testSatuPratinjauAktifPerPengguna(): void
    {
        $admin  = $this->adminSlip();
        $lain   = $this->adminSlip();
        $berkas = fn () => $this->berkasSlip([$this->barisGpp($this->nip, 9, 2026, 'Budi')], [$this->barisTk($this->nip, 9, 2026, 'Budi')]);

        $pertama = $this->dataOk($this->unggahSlip($admin, $berkas()))['token_pratinjau'];
        $milikLain = $this->dataOk($this->unggahSlip($lain, $berkas()))['token_pratinjau'];
        $kedua   = $this->dataOk($this->unggahSlip($admin, $berkas()))['token_pratinjau'];

        $this->assertNotSame($pertama, $kedua);
        $this->assertCount(4, $this->isiDirPratinjau(), 'pratinjau lama pengguna sama terhapus; milik pengguna lain tetap');

        $res = $this->commitSlip($admin, $pertama);
        $res->assertStatus(404);
        $this->assertSame('pratinjau_tidak_ditemukan', $this->json($res)['code']);
        $this->assertSame(ImporSlipService::PESAN_PRATINJAU_TIDAK_ADA, $this->json($res)['message']);
        $this->assertNotSame('', $milikLain);
    }

    public function testSheetWajibDanHeader(): void
    {
        $admin = $this->adminSlip();

        $res = $this->unggahSlip($admin, $this->berkasSlip([$this->barisGpp($this->nip, 9, 2026, 'Budi')], []));
        $res->assertStatus(422);
        $this->assertArrayHasKey('berkas', $this->json($res)['errors'], 'sheet TK tanpa baris data');
        $this->assertSame([], $this->isiDirPratinjau());
    }
}
