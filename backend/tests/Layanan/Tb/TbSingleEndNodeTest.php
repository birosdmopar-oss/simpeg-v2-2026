<?php

declare(strict_types=1);

namespace Tests\Layanan\Tb;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\Studi\StatusStudi;
use App\Libraries\Layanan\Tb\TbApprovalService;
use App\Libraries\Layanan\Tb\TbService;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * Single end node TB (PRD Fase 4 §6.12.1, §10.1 #3; AC-07.5) atas service ber-DB: setiap jalur sah dari pengajuan
 * berakhir di status final 1/2 (pengajuan 0 → setujui/tolak; input role 1 langsung 1); status final tanpa transisi keluar
 * (proses 422 `errors.status`, ubah status oleh role 1 ditolak); status non-final (0) selalu punya pelaku (PIC aktif
 * melihatnya di antrean `mode=pic` tanpa batas unit).
 *
 * @internal
 */
final class TbSingleEndNodeTest extends DatabaseTestCase
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
        [$nip, $pemohon] = $this->aktorStudi(Role::PEGAWAI, [], $this->buatSatker());
        [$picNip, $pic]  = $this->aktorStudi(Role::PTT, ['id_jenis_pegawai' => 2], $this->buatSatker());
        [, $su]          = $this->aktorStudi(Role::SUPER_ADMIN);
        $this->jadikanPic($picNip, PetugasLayananService::JENIS_TB);

        $jalur = [
            'ajukan → setujui' => [fn (): int => $this->ajukan($pemohon, '2027-01-01'), ['aksi' => 'setujui'] + $this->dataSkTb(), StatusStudi::DISETUJUI],
            'ajukan → tolak'   => [fn (): int => $this->ajukan($pemohon, '2029-01-01'), ['aksi' => 'tolak', 'reason_note' => 'Tidak memenuhi'], StatusStudi::DITOLAK],
        ];

        foreach ($jalur as $nama => [$buat, $aksi, $akhir]) {
            $id = $buat();
            $this->assertSame(StatusStudi::PENGAJUAN, $this->statusTb($id), $nama);
            $this->assertContains($id, array_map(static fn (array $r): int => (int) $r['id_riwayat_tb'], (new TbService())->daftar($pic, ['mode' => 'pic', 'per_page' => '100'])['items']), "{$nama}: status 0 punya pelaku (PIC lain unit)");

            (new TbApprovalService())->proses($pic, $id, $aksi);
            $this->assertSame($akhir, $this->statusTb($id), $nama);
            $this->assertFinalTerkunci($pic, $su, $id);
        }

        $langsung = (int) (new TbService())->tambah($su, $this->isianStudi(['nip' => $nip, 'status' => '1', 'tgl_mulai' => '2031-01-01', 'tgl_akhir' => '2032-01-01']) + $this->dataSkTb(), [])['id_riwayat_tb'];
        $this->assertSame(StatusStudi::DISETUJUI, $this->statusTb($langsung), 'input role 1 status 1');
        $this->assertFinalTerkunci($pic, $su, $langsung);
    }

    private function assertFinalTerkunci(\App\Libraries\Auth\AuthContext $pic, \App\Libraries\Auth\AuthContext $su, int $id): void
    {
        foreach ([['aksi' => 'setujui'] + $this->dataSkTb(), ['aksi' => 'tolak', 'reason_note' => 'Ulang']] as $aksi) {
            try {
                (new TbApprovalService())->proses($pic, $id, $aksi);
                $this->fail('Status final tidak boleh diproses');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->getErrors() ?? []);
            }
        }

        $status = $this->statusTb($id);

        if ($status === StatusStudi::DISETUJUI) {
            $row = $this->db->table('riwayat_tb')->where('id_riwayat_tb', $id)->get()->getRowArray();

            try {
                (new TbService())->ubah($su, $id, $this->isianStudi(['status' => '0', 'tgl_mulai' => (string) $row['tgl_mulai'], 'tgl_akhir' => (string) $row['tgl_akhir']]), []);
                $this->fail('Status final tidak boleh dikembalikan ke 0');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->getErrors() ?? []);
            }
        }

        $this->assertSame($status, $this->statusTb($id));
    }

    private function ajukan(\App\Libraries\Auth\AuthContext $auth, string $mulai): int
    {
        $akhir = (string) ((int) substr($mulai, 0, 4) + 1) . substr($mulai, 4);

        return (int) (new TbService())->tambah($auth, $this->isianStudi(['tgl_mulai' => $mulai, 'tgl_akhir' => $akhir]), $this->berkasSemuaSlot(AturanStudi::JENIS_TB))['id_riwayat_tb'];
    }

    private function statusTb(int $id): int
    {
        return (int) $this->db->table('riwayat_tb')->where('id_riwayat_tb', $id)->get()->getRow()->status;
    }
}
