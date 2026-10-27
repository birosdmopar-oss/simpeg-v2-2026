<?php

declare(strict_types=1);

namespace Tests\Layanan\Pmk;

use App\Constants\Role;
use App\Exceptions\NotFoundException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Pmk\PmkPengajuanService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * Unduh berkas kolom pengajuan PMK `GET pmk/pengajuan/{id}/berkas/{kolom}` ([V2] gap PRD §5.4, disetujui Horii 27-10-2026):
 * role 4..8 → 403; pengajuan tak terlihat → 404; `{kolom}` di luar empat kolom berkas → 404; path tersimpan yang ditolak
 * storage (traversal `../`) atau path impor legacy yang belum ada di storage → 404; sukses mengalirkan isi berkas dengan
 * nama aman (tanpa path internal), `nosniff`, `no-store`; PIC PMK boleh mengunduh.
 *
 * @internal
 */
final class PmkBerkasTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use LayananStudiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private string $nip;

    private AuthContext $pemohon;

    private int $id;

    private string $isiKontrak;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();

        [$this->nip, $this->pemohon] = $this->aktorStudi(Role::PEGAWAI);
        $berkas                      = [];

        foreach (array_keys(PmkPengajuanService::BERKAS) as $kolom) {
            $berkas[$kolom] = $this->berkasPdf("{$kolom}.pdf");
        }

        $this->isiKontrak = (string) file_get_contents($berkas['file_kontrak']->getTempName());
        $this->id         = (int) (new PmkPengajuanService())->tambah($this->pemohon, ['jenis_pmk' => 'Swasta', 'instansi' => 'PT Uji', 'tgl_awal' => '2010-01-01', 'tgl_akhir' => '2012-12-31', 'mker_th' => '2', 'mker_bl' => '11'], $berkas)['id_riwayat_pmk_req'];
    }

    protected function tearDown(): void
    {
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testPemilikDanPicMengunduhIsiBerkas(): void
    {
        $res = $this->sebagai($this->nip)->get("api/v1/pmk/pengajuan/{$this->id}/berkas/file_kontrak");

        $res->assertStatus(200);
        $this->assertSame($this->isiKontrak, (string) $res->response()->getBody());
        $this->assertStringStartsWith('application/pdf', $res->response()->getHeaderLine('Content-Type'));
        $this->assertSame("attachment; filename=\"pmk_{$this->id}_file_kontrak.pdf\"", $res->response()->getHeaderLine('Content-Disposition'), 'nama dibangkitkan server, tanpa path internal');
        $this->assertSame('nosniff', $res->response()->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $res->response()->getHeaderLine('Cache-Control'));

        [$picNip] = $this->aktorStudi(Role::ADMIN_SATKER, [], $this->buatSatker());
        $this->jadikanPic($picNip, PetugasLayananService::JENIS_PMK);
        $this->sebagai($picNip)->get("api/v1/pmk/pengajuan/{$this->id}/berkas/file_slip_gaji")->assertStatus(200);
    }

    public function testRole4Sampai8Ditolak403(): void
    {
        foreach ([Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            [$nip] = $this->aktorStudi($role);
            $this->sebagai($nip)->get("api/v1/pmk/pengajuan/{$this->id}/berkas/file_kontrak")->assertStatus(403);
        }
    }

    public function testTakTerlihat404(): void
    {
        [$lain] = $this->aktorStudi(Role::PEGAWAI);

        $this->sebagai($lain)->get("api/v1/pmk/pengajuan/{$this->id}/berkas/file_kontrak")->assertStatus(404);
        $this->sebagai($this->nip)->get('api/v1/pmk/pengajuan/999999/berkas/file_kontrak')->assertStatus(404);
    }

    public function testKolomDiLuarWhitelist404(): void
    {
        // `../../.env` ter-encode: bukan satu segmen kolom yang sah → 404 (bukan isi berkas mana pun).
        foreach (['reason_note', 'nip', 'file_bukti', 'file_kontrak.pdf', '../../.env', '..%2Ffile_kontrak'] as $kolom) {
            try {
                $this->sebagai($this->nip)->get("api/v1/pmk/pengajuan/{$this->id}/berkas/" . rawurlencode($kolom))->assertStatus(404);
            } catch (PageNotFoundException) {
                // Path ter-normalisasi keluar dari route (mis. `pmk/pengajuan/.env`) → aplikasi menjawab 404 (envelope).
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPathTraversalDanPathImporLegacy404(): void
    {
        foreach (['../../.env', 'arsip/../../app/Config/Database.php', '/etc/passwd', 'arsip/19900101/pmk/kontrak lama.pdf', 'arsip/legacy/ada-di-remote.pdf'] as $path) {
            $this->db->table('riwayat_pmk_req')->where('id_riwayat_pmk_req', $this->id)->update(['file_kontrak' => $path]);

            try {
                (new PmkPengajuanService())->berkas($this->pemohon, $this->id, 'file_kontrak');
                $this->fail("Path {$path} seharusnya 404");
            } catch (NotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->sebagai($this->nip)->get("api/v1/pmk/pengajuan/{$this->id}/berkas/file_kontrak")->assertStatus(404);
    }

    public function testKolomKosong404(): void
    {
        $this->db->table('riwayat_pmk_req')->where('id_riwayat_pmk_req', $this->id)->update(['file_paklaring' => null]);

        $this->sebagai($this->nip)->get("api/v1/pmk/pengajuan/{$this->id}/berkas/file_paklaring")->assertStatus(404);
    }

    private function sebagai(string $nip): static
    {
        $akun = $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->issueTokensForUser($akun)['access_token']]);
    }
}
