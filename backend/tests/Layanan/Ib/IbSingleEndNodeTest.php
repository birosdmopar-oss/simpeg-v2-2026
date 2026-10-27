<?php

declare(strict_types=1);

namespace Tests\Layanan\Ib;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\Layanan\Ib\IbApprovalService;
use App\Libraries\Layanan\Ib\IbService;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\Studi\StatusStudi;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * Single end node IB (PRD Fase 4 §6.8.2, §6.12.1; AC-08.4): pengajuan (pegawai maupun input admin) selalu 0 → setujui 1 /
 * tolak 2; tidak ada input "langsung disetujui"; 1/2 tanpa transisi keluar; status 0 punya pelaku (PIC IB lain unit di
 * antrean `mode=pic`).
 *
 * @internal
 */
final class IbSingleEndNodeTest extends DatabaseTestCase
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
        [$nip, $pemohon] = $this->aktorStudi(Role::PPPK, ['id_jenis_pegawai' => 6], $this->buatSatker());
        [, $su]          = $this->aktorStudi(Role::SUPER_ADMIN);
        [$picNip, $pic]  = $this->aktorStudi(Role::PEGAWAI, [], $this->buatSatker());
        $this->jadikanPic($picNip, PetugasLayananService::JENIS_IB);

        $jalur = [
            'pegawai → setujui' => [$pemohon, [], '2027-01-01', ['aksi' => 'setujui', 'no_sk' => 'S', 'tgl_sk' => '2026-10-20', 'ds_ib' => 'D', 'no_ds_ib' => 'N'], StatusStudi::DISETUJUI],
            'admin → tolak'     => [$su, ['nip' => $nip], '2029-01-01', ['aksi' => 'tolak', 'reason_note' => 'Tidak sesuai'], StatusStudi::DITOLAK],
        ];

        foreach ($jalur as $nama => [$aktor, $nipIsian, $mulai, $aksi, $akhir]) {
            $id = (int) (new IbService())->tambah($aktor, $this->isianStudi($nipIsian + ['tgl_mulai' => $mulai, 'tgl_akhir' => ((int) substr($mulai, 0, 4) + 1) . substr($mulai, 4)]), $this->berkasSemuaSlot(AturanStudi::JENIS_IB))['id_riwayat_ib'];
            $this->assertSame(StatusStudi::PENGAJUAN, $this->statusIb($id), "{$nama}: selalu mulai 0");
            $this->assertContains($id, array_map(static fn (array $r): int => (int) $r['id_riwayat_ib'], (new IbService())->daftar($pic, ['mode' => 'pic'])['items']), "{$nama}: punya pelaku");

            (new IbApprovalService())->proses($pic, $id, $aksi);
            $this->assertSame($akhir, $this->statusIb($id), $nama);

            try {
                (new IbApprovalService())->proses($pic, $id, ['aksi' => 'tolak', 'reason_note' => 'Ulang']);
                $this->fail('Final tidak boleh diproses');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->getErrors() ?? []);
            }

            $this->assertSame($akhir, $this->statusIb($id));
        }
    }

    private function statusIb(int $id): int
    {
        return (int) $this->db->table('riwayat_ib')->where('id_riwayat_ib', $id)->get()->getRow()->status;
    }
}
