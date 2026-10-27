<?php

declare(strict_types=1);

namespace Tests\Layanan\Ib;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Layanan\Ib\IbApprovalService;
use App\Libraries\Layanan\Ib\IbService;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\Studi\BerkasStudi;
use Closure;
use Config\Services;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;
use Tests\Support\Layanan\NotifikasiRekam;

/**
 * C-08 proses PIC IB (PRD Fase 4 §6.8.1; AC-08.4): PIC IB aktif (jenis 4) memproses 0 → 1/2; setujui butuh data SK IB dan
 * menghasilkan Surat IB PDF di `document_attachment` kode 50 (diunduh lewat SuratIbService); tolak butuh alasan; tanpa
 * efek ke `pegawai.id_jenis_status`; PIC TB bukan PIC IB; notifikasi PIC jenis 4 & keputusan ke pemohon.
 *
 * @internal
 */
final class IbApprovalTest extends DatabaseTestCase
{
    use LayananStudiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private NotifikasiRekam $notif;

    private string $nip;

    private AuthContext $pemohon;

    private AuthContext $pic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();
        $this->notif = new NotifikasiRekam();
        Services::injectMock('notifikasiLayanan', $this->notif);

        [$this->nip, $this->pemohon] = $this->aktorStudi(Role::PPPK, ['id_jenis_pegawai' => 6]);
        [$picNip, $this->pic]        = $this->aktorStudi(Role::PEGAWAI, [], $this->buatSatker());
        $this->jadikanPic($picNip, PetugasLayananService::JENIS_IB);
    }

    protected function tearDown(): void
    {
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testSetujuiMembuatSuratIbTanpaEfekKePegawai(): void
    {
        $id = $this->ajukan();
        $this->assertSame('Permohonan Izin Belajar Baru', $this->notif->kirim[0]['judul']);

        (new IbApprovalService())->proses($this->pic, $id, ['aksi' => 'setujui', 'no_sk' => 'SK/IB/9', 'tgl_sk' => '2026-10-21', 'ds_ib' => 'Surat Kampus', 'no_ds_ib' => 'K/1', 'tgl_ds_ib' => '2026-10-01']);

        $row = $this->db->table('riwayat_ib')->where('id_riwayat_ib', $id)->get()->getRowArray();
        $this->assertSame([1, 1, 'SK/IB/9', '2026-10-01', null], [(int) $row['status'], (int) $row['show_pl'], $row['no_sk'], $row['tgl_ds_ib'], $row['reason_note']]);
        $this->assertSame(1, $this->db->table('document_attachment')->where('id_riwayat', BerkasStudi::KODE_SURAT_IB)->where('id_entri', (string) $id)->countAllResults(), 'surat IB tersimpan');
        $this->assertSame(1, (int) $this->db->table('pegawai')->where('nip', $this->nip)->get()->getRow()->id_jenis_status, 'tanpa efek pegawai (IB-5)');

        $detail = (new IbService())->detail($this->pemohon, $id);
        $this->assertCount(1, $detail['surat']);
        $this->assertTrue($detail['aksi']['unduh_surat']);
        $this->assertSame('Izin Belajar Disetujui', end($this->notif->kirim)['judul']);
    }

    public function testSetujuiTanpaDataSkIbDitolak(): void
    {
        $id = $this->ajukan();

        $this->assertSame(['ds_ib', 'no_ds_ib', 'no_sk', 'tgl_sk'], $this->kunci(fn () => (new IbApprovalService())->proses($this->pic, $id, ['aksi' => 'setujui'])));
        $this->assertSame(0, $this->db->table('document_attachment')->where('id_riwayat', BerkasStudi::KODE_SURAT_IB)->countAllResults());
    }

    public function testTolakWajibAlasan(): void
    {
        $id = $this->ajukan();

        $this->assertSame(['reason_note'], $this->kunci(fn () => (new IbApprovalService())->proses($this->pic, $id, ['aksi' => 'tolak'])));

        (new IbApprovalService())->proses($this->pic, $id, ['aksi' => 'tolak', 'reason_note' => 'Jadwal bentrok jam kerja']);
        $this->assertSame(2, (int) $this->db->table('riwayat_ib')->where('id_riwayat_ib', $id)->get()->getRow()->status);
        $this->assertSame('Izin Belajar Ditolak', end($this->notif->kirim)['judul']);
    }

    public function testPicTbBukanPicIb(): void
    {
        $id                 = $this->ajukan();
        [$picTbNip, $picTb] = $this->aktorStudi(Role::SUPER_ADMIN);
        $this->jadikanPic($picTbNip, PetugasLayananService::JENIS_TB);

        $this->expectException(ForbiddenException::class);
        (new IbApprovalService())->proses($picTb, $id, ['aksi' => 'tolak', 'reason_note' => 'x']);
    }

    private function ajukan(): int
    {
        return (int) (new IbService())->tambah($this->pemohon, $this->isianStudi(), $this->berkasSemuaSlot(AturanStudi::JENIS_IB))['id_riwayat_ib'];
    }

    /**
     * @return list<string>
     */
    private function kunci(Closure $aksi): array
    {
        try {
            $aksi();
        } catch (ValidationException $e) {
            $kunci = array_keys($e->getErrors() ?? []);
            sort($kunci);

            return $kunci;
        }

        $this->fail('Seharusnya 422');
    }
}
