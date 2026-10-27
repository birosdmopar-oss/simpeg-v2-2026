<?php

declare(strict_types=1);

namespace Tests\Layanan\Tb;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\Studi\BerkasStudi;
use App\Libraries\Layanan\Tb\TbApprovalService;
use App\Libraries\Layanan\Tb\TbService;
use Closure;
use Config\Services;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;
use Tests\Support\Layanan\NotifikasiRekam;

/**
 * C-07 proses PIC TB (PRD Fase 4 §6.7.3; AC-07.5, AC-07.6): PIC aktif unit mana pun memproses status 0 → 1/2; setujui butuh
 * data SK, menulis `show_pl`, `approved_by`, `show_notif`, efek final `id_jenis_status` hanya bila hari ini dalam rentang,
 * SK unggahan kode 47; tolak butuh `reason_note` ≤ 255 byte; final terkunci; bukan PIC / PIC nonaktif → 403. Titik
 * sambung notifikasi: pengajuan → semua PIC aktif, keputusan → pemohon (+alasan), push sesudah commit.
 *
 * @internal
 */
final class TbApprovalTest extends DatabaseTestCase
{
    use LayananStudiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private NotifikasiRekam $notif;

    private string $nip;

    private AuthContext $pemohon;

    private string $picNip;

    private AuthContext $pic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();
        $this->notif = new NotifikasiRekam();
        Services::injectMock('notifikasiLayanan', $this->notif);

        [$this->nip, $this->pemohon] = $this->aktorStudi(Role::PEGAWAI);
        [$this->picNip, $this->pic]  = $this->aktorStudi(Role::ADMIN_SATKER, [], $this->buatSatker());
        $this->jadikanPic($this->picNip, PetugasLayananService::JENIS_TB);
    }

    protected function tearDown(): void
    {
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testPengajuanMemberitahuSemuaPicAktifSaja(): void
    {
        [$picLain]     = $this->aktorStudi(Role::PEGAWAI);
        [$picNonaktif] = $this->aktorStudi(Role::PEGAWAI);
        $this->jadikanPic($picLain, PetugasLayananService::JENIS_TB);
        $this->jadikanPic($picNonaktif, PetugasLayananService::JENIS_TB, 0);

        $this->ajukan();

        $this->assertCount(1, $this->notif->kirim);
        $kirim = $this->notif->kirim[0];
        $this->assertSame(['tb', 1, 0, 'Permohonan Tugas Belajar Baru'], [$kirim['modul'], $kirim['tipe'], $kirim['status'], $kirim['judul']]);
        $this->assertEqualsCanonicalizing([$this->picNip, $picLain], $kirim['penerima']);
        $this->assertCount(1, $this->notif->enqueue, 'push di-enqueue sesudah commit');
    }

    public function testSetujuiMenulisDataSkDanEfekFinalDalamRentang(): void
    {
        $id = $this->ajukan(['tgl_mulai' => '2026-11-01']);
        $this->db->table('riwayat_tb')->where('id_riwayat_tb', $id)->update(['tgl_mulai' => '2026-10-01']);

        (new TbApprovalService())->proses($this->pic, $id, ['aksi' => 'setujui', 'perihal_ds_tb' => 'Izin studi'] + $this->dataSkTb(), $this->berkasPdf('sk.pdf'));

        $row = $this->db->table('riwayat_tb')->where('id_riwayat_tb', $id)->get()->getRowArray();
        $this->assertSame([1, 1, 1, 'SK/TB/001', 'Izin studi', null], [(int) $row['status'], (int) $row['show_pl'], (int) $row['show_notif'], $row['no_sk'], $row['perihal_ds_tb'], $row['reason_note']]);
        $this->assertNotNull($row['approved_by']);
        $this->assertSame(2, $this->jenisStatus(), 'hari ini dalam rentang → id_jenis_status 2');
        $this->assertSame(1, $this->db->table('document_attachment')->where('id_riwayat', BerkasStudi::KODE_SURAT_TB)->where('id_entri', (string) $id)->countAllResults());

        $keputusan = end($this->notif->kirim);
        $this->assertSame([[$this->nip], 'Tugas Belajar Disetujui', 2, 1], [$keputusan['penerima'], $keputusan['judul'], $keputusan['tipe'], $keputusan['status']]);
    }

    public function testSetujuiTbMasaDepanTidakMengubahStatusPegawai(): void
    {
        $id = $this->ajukan();

        (new TbApprovalService())->proses($this->pic, $id, ['aksi' => 'setujui'] + $this->dataSkTb());

        $this->assertSame(1, $this->jenisStatus(), 'TB mulai 2026-12-01 → tanpa cron, status tetap [K]');
    }

    public function testSetujuiTanpaDataSkDitolakSemuaField(): void
    {
        $id = $this->ajukan();

        $this->assertSame(['ds_tb', 'no_ds_tb', 'no_sk', 'tgl_sk'], $this->kunci(fn () => (new TbApprovalService())->proses($this->pic, $id, ['aksi' => 'setujui'])));
    }

    public function testTolakWajibAlasanDanMemberitahuPemohonDenganAlasan(): void
    {
        $id = $this->ajukan();

        $this->assertSame(['reason_note'], $this->kunci(fn () => (new TbApprovalService())->proses($this->pic, $id, ['aksi' => 'tolak', 'reason_note' => '  '])));
        $this->assertSame(['reason_note'], $this->kunci(fn () => (new TbApprovalService())->proses($this->pic, $id, ['aksi' => 'tolak', 'reason_note' => str_repeat('x', 256)])));

        (new TbApprovalService())->proses($this->pic, $id, ['aksi' => 'tolak', 'reason_note' => 'Berkas kurang']);

        $row = $this->db->table('riwayat_tb')->where('id_riwayat_tb', $id)->get()->getRowArray();
        $this->assertSame([2, 'Berkas kurang', 1], [(int) $row['status'], $row['reason_note'], (int) $row['show_notif']]);
        $this->assertSame(1, $this->jenisStatus());

        $keputusan = end($this->notif->kirim);
        $this->assertSame(['Tugas Belajar Ditolak', 'Berkas kurang'], [$keputusan['judul'], $keputusan['reason_note']]);
        $this->assertStringEndsWith('. Ket: Berkas kurang', $keputusan['isi']);
    }

    public function testStatusFinalTidakBisaDiprosesLagi(): void
    {
        $id = $this->ajukan();
        (new TbApprovalService())->proses($this->pic, $id, ['aksi' => 'tolak', 'reason_note' => 'Tidak sesuai']);

        $this->assertSame(['status'], $this->kunci(fn () => (new TbApprovalService())->proses($this->pic, $id, ['aksi' => 'setujui'] + $this->dataSkTb())));
    }

    public function testAksiTidakDikenal422(): void
    {
        $id = $this->ajukan();

        $this->assertSame(['aksi'], $this->kunci(fn () => (new TbApprovalService())->proses($this->pic, $id, ['aksi' => 'tangguhkan'])));
    }

    public function testBukanPicDitolak403(): void
    {
        $id     = $this->ajukan();
        [, $su] = $this->aktorStudi(Role::SUPER_ADMIN);

        $this->expectException(ForbiddenException::class);
        (new TbApprovalService())->proses($su, $id, ['aksi' => 'setujui'] + $this->dataSkTb());
    }

    public function testPicNonaktifDitolak(): void
    {
        $id = $this->ajukan();
        $this->db->table('petugas_layanan')->where('nip', $this->picNip)->update(['status' => 0]);

        $this->expectException(\App\Exceptions\NotFoundException::class);
        (new TbApprovalService())->proses($this->pic, $id, ['aksi' => 'setujui'] + $this->dataSkTb());
    }

    public function testRollbackTidakMengantrekanPush(): void
    {
        $id                   = $this->ajukan();
        $this->notif->enqueue = [];

        try {
            (new TbApprovalService())->proses($this->pic, $id, ['aksi' => 'setujui']);
        } catch (ValidationException) {
        }

        $this->assertSame([], $this->notif->enqueue);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function ajukan(array $override = []): int
    {
        return (int) (new TbService())->tambah($this->pemohon, $this->isianStudi($override), $this->berkasSemuaSlot(AturanStudi::JENIS_TB))['id_riwayat_tb'];
    }

    private function jenisStatus(): int
    {
        return (int) $this->db->table('pegawai')->where('nip', $this->nip)->get()->getRow()->id_jenis_status;
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
