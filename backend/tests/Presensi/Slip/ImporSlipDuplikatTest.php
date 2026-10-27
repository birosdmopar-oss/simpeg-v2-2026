<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\ImporSlipUjiTrait;

/**
 * PRD 5A §7.2.3 butir 3–4, §7.2.4 butir 4 (AC-D10.3, AC-D10.5): kunci NIP + periode ganda dalam satu sheet → galat
 * `baris_ganda` menyebut kedua nomor baris; kunci yang sudah ada di DB → `duplikat`; commit `timpa` bawaan (true)
 * memperbarui 28 kolom tanpa menyentuh `viewed_at`/`viewed_ip`/`qr_token`/`qr_token_enkripsi`/`uang_makan*`;
 * `timpa = false` melewati.
 *
 * @internal
 */
final class ImporSlipDuplikatTest extends DatabaseTestCase
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
        $this->nip = $this->buatPegawai(['nama' => 'Agus Salim']);
    }

    protected function tearDown(): void
    {
        $this->bersihkanImporSlip();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testBarisGandaDalamBerkas(): void
    {
        $data = $this->dataOk($this->unggahSlip($this->adminSlip(), $this->berkasSlip(
            [$this->barisGpp($this->nip, 9, 2026, 'Agus Salim'), $this->barisGpp($this->nip, 9, 2026, 'Agus Salim', ['gjpokok' => 1])],
            [$this->barisTk($this->nip, 9, 2026, 'Agus Salim')],
        )));

        $this->assertSame(['valid' => 0, 'duplikat' => 0, 'galat' => 1], $data['ringkasan']);
        $galat = $data['baris'][0]['galat'];
        $this->assertSame(['baris_ganda'], array_column($galat, 'kode'));
        $this->assertStringContainsString('baris 2 dan baris 3', $galat[0]['pesan']);
    }

    public function testDuplikatTimpaBawaanMemperbaruiNominalSaja(): void
    {
        $id    = $this->slipAda();
        $admin = $this->adminSlip();
        $data  = $this->dataOk($this->unggahSlip($admin, $this->berkas()));

        $this->assertSame(['valid' => 0, 'duplikat' => 1, 'galat' => 0], $data['ringkasan']);
        $this->assertSame(['perbarui', 'lewati'], [$data['baris'][0]['rencana_bila_timpa'], $data['baris'][0]['rencana_bila_tidak_timpa']]);

        $hasil = $this->dataOk($this->commitSlip($admin, $data['token_pratinjau']));
        $this->assertSame([0, 1, 0, 0], [$hasil['disisip'], $hasil['diperbarui'], $hasil['dilewati'], $hasil['gagal']]);

        $row = $this->db->table('gaji_pegawai')->where('id', $id)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('5000000.00', $row['gjpokok'], 'nominal diganti');
        $this->assertSame('0.00', $row['tjanak'], 'kolom yang tidak ada di berkas = 0');
        $this->assertSame(['2026-10-01 03:00:00', '203.0.113.9', str_repeat('a', 64), '125000.00', '20', '8-2026'], [
            $row['viewed_at'], $row['viewed_ip'], $row['qr_token'], $row['uang_makan'], (string) $row['uang_makan_hari'], $row['uang_makan_periode'],
        ], 'status dibuka, token, dan uang makan tidak disentuh (S-6)');
        $this->assertSame('rahasia', $row['qr_token_enkripsi']);
        $this->assertSame((string) $admin['id_pengguna'], (string) $row['updated_by']);
        $this->assertSame(1, $this->db->table('gaji_pegawai')->countAllResults());
    }

    public function testDuplikatTanpaTimpaDilewati(): void
    {
        $id    = $this->slipAda();
        $admin = $this->adminSlip();
        $data  = $this->dataOk($this->unggahSlip($admin, $this->berkas()));

        $hasil = $this->dataOk($this->commitSlip($admin, $data['token_pratinjau'], ['timpa' => false]));
        $this->assertSame([0, 0, 1, 0], [$hasil['disisip'], $hasil['diperbarui'], $hasil['dilewati'], $hasil['gagal']]);
        $this->assertSame('lewati', $hasil['baris'][0]['hasil']);
        $this->seeInDatabase('gaji_pegawai', ['id' => $id, 'gjpokok' => '1000000.00', 'updated_by' => null]);
    }

    private function berkas(): \CodeIgniter\HTTP\Files\UploadedFile
    {
        return $this->berkasSlip(
            [$this->barisGpp($this->nip, 9, 2026, 'Agus Salim', ['gjpokok' => 5000000, 'tjistri' => null])],
            [$this->barisTk($this->nip, 9, 2026, 'Agus Salim')],
        );
    }

    private function slipAda(): int
    {
        $this->db->table('gaji_pegawai')->insert([
            'nip'               => $this->nip, 'bulan' => 9, 'tahun' => 2026, 'gjpokok' => '1000000.00', 'tjanak' => '50000.00',
            'viewed_at'         => '2026-10-01 03:00:00', 'viewed_ip' => '203.0.113.9', 'qr_token' => str_repeat('a', 64),
            'qr_token_enkripsi' => 'rahasia', 'uang_makan' => '125000.00', 'uang_makan_hari' => 20, 'uang_makan_periode' => '8-2026',
            'created_at'        => '2026-09-30 00:00:00',
        ]);

        return (int) $this->db->insertID();
    }
}
