<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\ImporSlipUjiTrait;

/**
 * PRD 5A §7.2.4 (AC-D10.1, AC-D10.5, AC-D10.8): commit pratinjau impor Slip Gaji — hanya lewat token milik pemanggil,
 * baris valid disisip (28 kolom desimal, `created_by`, stempel UTC), audit ringkasan `import` tanpa nominal, berkas
 * pratinjau dihapus, token sekali pakai, validasi ulang dengan data terkini (pegawai pindah satker), berkas diubah di
 * disk → 404, `timpa` bukan boolean → 422, baris klien tidak diterima.
 *
 * @internal
 */
final class ImporSlipCommitTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use ImporSlipUjiTrait;
    use PegawaiFixtureTrait;

    private int $satker;

    private string $nip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanImporSlip();
        $this->satker = $this->buatSatker();
        $this->nip    = $this->buatPegawaiDiSatker($this->satker, ['nama' => 'Siti Aminah']);
    }

    protected function tearDown(): void
    {
        $this->bersihkanImporSlip();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testCommitSisipAuditDanHapusBerkas(): void
    {
        $admin = $this->adminSlip();
        $token = $this->pratinjau($admin, $this->nip, ['gjpokok' => 4500000.5]);

        $data = $this->dataOk($this->commitSlip($admin, $token, ['baris' => [['nip' => '1', 'status' => 'valid']]]));

        $this->assertSame([1, 0, 0, 0], [$data['disisip'], $data['diperbarui'], $data['dilewati'], $data['gagal']]);
        $this->assertSame([['nip' => $this->nip, 'periode' => '2026-09', 'hasil' => 'sisip', 'galat' => []]], $data['baris']);

        $row = $this->db->table('gaji_pegawai')->where('nip', $this->nip)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame(['9', '2026'], [(string) $row['bulan'], (string) $row['tahun']]);
        $this->assertSame('4500000.50', $row['gjpokok']);
        $this->assertSame('1900000.00', $row['bersih_tk']);
        $this->assertSame((string) $admin['id_pengguna'], (string) $row['created_by']);
        $this->assertNull($row['viewed_at']);
        $this->assertNull($row['qr_token']);
        $this->assertSame(1, $this->db->table('gaji_pegawai')->countAllResults(), 'baris dari body klien diabaikan');

        $audit = $this->db->table('audit_logs')->where('entity', 'gaji_pegawai_impor')->where('event', 'import')->get()->getResultArray();
        $this->assertCount(1, $audit);
        $this->assertSame(substr(hash('sha256', $token), 0, 8), $audit[0]['entity_id']);
        $after = json_decode((string) $audit[0]['after_json'], true);
        $this->assertEqualsCanonicalizing(['nama_berkas', 'filter', 'timpa', 'disisip', 'diperbarui', 'dilewati', 'gagal', 'periode'], array_keys($after), 'kolom JSON diurutkan ulang MySQL');
        $this->assertSame(['2026-09'], $after['periode']);
        $this->assertTrue($after['timpa']);
        $this->assertStringNotContainsString('4500000', (string) $audit[0]['after_json'], 'audit tanpa nominal');
        $this->assertSame((string) $admin['id_pengguna'], (string) $audit[0]['id_pengguna_actor']);

        $this->assertSame([], $this->isiDirPratinjau(), 'berkas + sidecar dihapus setelah commit');

        $ulang = $this->commitSlip($admin, $token);
        $ulang->assertStatus(404);
        $this->assertSame('pratinjau_tidak_ditemukan', $this->json($ulang)['code'], 'token sekali pakai');
    }

    public function testTokenTidakSahAtauMilikOrangLain(): void
    {
        $admin = $this->adminSlip();
        $token = $this->pratinjau($admin, $this->nip);

        foreach ([str_repeat('a', 64), 'bukan-token', strtoupper($token)] as $palsu) {
            $res = $this->commitSlip($admin, $palsu);
            $res->assertStatus(404);
            $this->assertSame('pratinjau_tidak_ditemukan', $this->json($res)['code']);
        }

        $res = $this->commitSlip($this->adminSlip(), $token);
        $res->assertStatus(404);
        $this->assertSame('pratinjau_tidak_ditemukan', $this->json($res)['code'], 'bukan milik pemanggil → pesan sama');
        $this->assertSame(0, $this->db->table('gaji_pegawai')->countAllResults());
        $this->assertNotSame([], $this->isiDirPratinjau(), 'pratinjau pemilik tetap ada');
    }

    public function testTimpaHarusBoolean(): void
    {
        $admin = $this->adminSlip();
        $token = $this->pratinjau($admin, $this->nip);

        foreach (['ya', 1, 'true'] as $nilai) {
            $res = $this->commitSlip($admin, $token, ['timpa' => $nilai]);
            $res->assertStatus(422);
            $this->assertArrayHasKey('timpa', $this->json($res)['errors']);
        }

        $this->assertNotSame([], $this->isiDirPratinjau(), '422 sebelum memuat pratinjau: belum dihapus');
    }

    public function testValidasiUlangDataTerkini(): void
    {
        $admin = $this->adminSlip(Role::ADMIN_SATKER, (string) $this->satker);
        $token = $this->pratinjau($admin, $this->nip);

        // Setelah pratinjau, pegawai dipindah ke satker lain (AC-D10.8): commit memakai cakupan terkini.
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $this->nip)->update(['id_satker' => $this->buatSatker()]);

        $data = $this->dataOk($this->commitSlip($admin, $token));
        $this->assertSame([0, 0, 0, 1], [$data['disisip'], $data['diperbarui'], $data['dilewati'], $data['gagal']]);
        $this->assertSame(['nip_di_luar_cakupan'], array_column($data['baris'][0]['galat'], 'kode'));
        $this->assertSame(0, $this->db->table('gaji_pegawai')->countAllResults());
    }

    public function testBerkasDiubahDiDisk(): void
    {
        $admin = $this->adminSlip();
        $token = $this->pratinjau($admin, $this->nip);
        $path  = config('Presensi')->direktoriPratinjau . hash('sha256', $token) . '.xlsx';

        file_put_contents($path, 'diubah', FILE_APPEND);

        $res = $this->commitSlip($admin, $token);
        $res->assertStatus(404);
        $this->assertSame('pratinjau_tidak_ditemukan', $this->json($res)['code']);
        $this->assertSame(0, $this->db->table('gaji_pegawai')->countAllResults());
    }

    /**
     * @param array<string, mixed> $gpp
     */
    private function pratinjau(array $admin, string $nip, array $gpp = []): string
    {
        $data = $this->dataOk($this->unggahSlip($admin, $this->berkasSlip(
            [$this->barisGpp($nip, 9, 2026, 'Siti Aminah', $gpp)],
            [$this->barisTk($nip, 9, 2026, 'Siti Aminah')],
        )));

        return (string) $data['token_pratinjau'];
    }
}
