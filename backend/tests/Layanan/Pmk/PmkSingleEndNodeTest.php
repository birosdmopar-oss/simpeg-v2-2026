<?php

declare(strict_types=1);

namespace Tests\Layanan\Pmk;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Pmk\PmkApprovalService;
use App\Libraries\Layanan\Pmk\PmkPengajuanService;
use App\Libraries\Layanan\Pmk\StatusPmk;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * Single end node PMK (PRD Fase 4 §6.9.2, §6.12.1; AC-09.3) atas service ber-DB: keempat jalur sah 0 → 1, 0 → 3 → 1,
 * 0 → 2, 0 → 3 → 2 berakhir di status final; 1/2 tanpa transisi keluar; status non-final 0 dan 3 punya pelaku (PIC PMK
 * lain unit di antrean `mode=pic`).
 *
 * @internal
 */
final class PmkSingleEndNodeTest extends DatabaseTestCase
{
    use LayananStudiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();
    }

    protected function tearDown(): void
    {
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testSemuaJalurBerakhirDiStatusFinal(): void
    {
        [, $pemohon]    = $this->aktorStudi(Role::PEGAWAI, [], $this->buatSatker());
        [$picNip, $pic] = $this->aktorStudi(Role::PEGAWAI, [], $this->buatSatker());
        $this->jadikanPic($picNip, PetugasLayananService::JENIS_PMK);

        $jalur = [
            '0 → 1'     => [['setujui'], StatusPmk::SELESAI],
            '0 → 3 → 1' => [['proses', 'setujui'], StatusPmk::SELESAI],
            '0 → 2'     => [['tolak'], StatusPmk::DITOLAK],
            '0 → 3 → 2' => [['proses', 'tolak'], StatusPmk::DITOLAK],
        ];
        $tahun = 1990;

        foreach ($jalur as $nama => [$langkah, $akhir]) {
            $berkas = [];

            foreach (array_keys(PmkPengajuanService::BERKAS) as $kolom) {
                $berkas[$kolom] = $this->berkasPdf("{$kolom}.pdf");
            }

            $id = (int) (new PmkPengajuanService())->tambah($pemohon, ['jenis_pmk' => 'Swasta', 'instansi' => 'PT', 'tgl_awal' => "{$tahun}-01-01", 'tgl_akhir' => "{$tahun}-12-31", 'mker_th' => '0', 'mker_bl' => '11'], $berkas)['id_riwayat_pmk_req'];
            $tahun++;

            foreach ($langkah as $aksi) {
                $this->assertContains($id, $this->antrean($pic), "{$nama}: status non-final punya pelaku");
                (new PmkApprovalService())->proses($pic, $id, ['aksi' => $aksi, 'reason_note' => 'Alasan uji']);
            }

            $this->assertSame($akhir, $this->statusPmk($id), $nama);
            $this->assertNotContains($id, $this->antrean($pic), "{$nama}: final keluar dari antrean");

            foreach (['proses', 'setujui', 'tolak'] as $aksi) {
                try {
                    (new PmkApprovalService())->proses($pic, $id, ['aksi' => $aksi, 'reason_note' => 'Ulang']);
                    $this->fail("{$nama}: final tidak boleh {$aksi}");
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('status', $e->getErrors() ?? []);
                }
            }
        }
    }

    /**
     * @return list<int>
     */
    private function antrean(\App\Libraries\Auth\AuthContext $pic): array
    {
        return array_map(static fn (array $r): int => (int) $r['id_riwayat_pmk_req'], (new PmkPengajuanService())->daftar($pic, ['mode' => 'pic', 'per_page' => '100'])['items']);
    }

    private function statusPmk(int $id): int
    {
        return (int) $this->db->table('riwayat_pmk_req')->where('id_riwayat_pmk_req', $id)->get()->getRow()->status;
    }
}
