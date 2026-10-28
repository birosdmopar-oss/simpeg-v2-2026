<?php

declare(strict_types=1);

namespace Tests\Layanan\Ib;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Libraries\Layanan\Ib\IbService;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\Studi\BerkasStudi;
use Closure;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * C-08 Izin Belajar CRUD (PRD Fase 4 §5.3, §6.8.1; AC-08.1, AC-08.2): role 2/7 diri sendiri, role 1/3 input admin (3
 * lingkup) dengan berkas berfungsi (IB-01); status selalu 0 (role 1 status 1 → 422); tiga slot berkas wajib semua role
 * termasuk role 1; tanpa slot `file_3`; slot dari kolom impor lolos; hak ubah/hapus sama TB; hapus fisik + berkas.
 *
 * @internal
 */
final class IbCrudTest extends DatabaseTestCase
{
    use LayananStudiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private const IB = AturanStudi::JENIS_IB;

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

    public function testPppkMengajukanDenganTigaBerkasStatus0(): void
    {
        [$nip, $auth] = $this->aktorStudi(Role::PPPK, ['id_jenis_pegawai' => 6]);

        $ib = (new IbService())->tambah($auth, $this->isianStudi(), $this->berkasSemuaSlot(self::IB));

        $this->assertSame([$nip, 0, 'Menunggu PIC'], [$ib['nip'], (int) $ib['status'], $ib['label_status']]);
        $this->assertSame(['file_1', 'file_2', 'file_sp'], $this->tagLampiran(BerkasStudi::KODE_IB, (int) $ib['id_riwayat_ib']));
        $this->assertArrayNotHasKey('status_penuh', $ib, 'IB tanpa efek presensi');
        $this->assertArrayNotHasKey('perpanjangan', $ib);
    }

    public function testInputAdminSelaluStatus0DanBerkasWajib(): void
    {
        [$nip]  = $this->aktorStudi(Role::PEGAWAI);
        [, $su] = $this->aktorStudi(Role::SUPER_ADMIN);

        $this->assertSame(['file_1', 'file_2', 'file_sp'], $this->kunci(fn () => (new IbService())->tambah($su, $this->isianStudi(['nip' => $nip]), [])), 'role 1 tidak dibebaskan [K L_ib.php:705]');
        $this->assertSame(['status'], $this->kunci(fn () => (new IbService())->tambah($su, $this->isianStudi(['nip' => $nip, 'status' => '1']), $this->berkasSemuaSlot(self::IB))));

        $ib = (new IbService())->tambah($su, $this->isianStudi(['nip' => $nip]), $this->berkasSemuaSlot(self::IB));
        $this->assertSame([$nip, 0], [$ib['nip'], (int) $ib['status']], 'input admin berfungsi dengan berkas (IB-01)');
    }

    public function testRole3DalamLingkupSaja(): void
    {
        $idSatker  = $this->buatSatker();
        [, $admin] = $this->aktorStudi(Role::ADMIN_SATKER, [], $idSatker);
        [$dalam]   = $this->aktorStudi(Role::PPPK, ['id_jenis_pegawai' => 6], $idSatker);
        [$luar]    = $this->aktorStudi(Role::PPPK, ['id_jenis_pegawai' => 6], $this->buatSatker());

        $this->assertSame($dalam, (new IbService())->tambah($admin, $this->isianStudi(['nip' => $dalam]), $this->berkasSemuaSlot(self::IB))['nip']);

        $this->expectException(ForbiddenException::class);
        (new IbService())->tambah($admin, $this->isianStudi(['nip' => $luar]), $this->berkasSemuaSlot(self::IB));
    }

    public function testSlotIbTanpaFile3DanSlotImporLolos(): void
    {
        [, $auth] = $this->aktorStudi(Role::PEGAWAI);
        $id       = (int) (new IbService())->tambah($auth, $this->isianStudi(), $this->berkasSemuaSlot(self::IB))['id_riwayat_ib'];

        $this->assertSame(['file_1', 'file_2', 'file_sp'], $this->tagLampiran(BerkasStudi::KODE_IB, $id));

        $this->db->table('document_attachment')->where('id_riwayat', BerkasStudi::KODE_IB)->where('id_entri', (string) $id)->where('tag', 'file_2')->delete();
        $this->assertSame(['file_2'], $this->kunci(fn () => (new IbService())->ubah($auth, $id, $this->isianStudi(), [])));

        $this->db->table('riwayat_ib')->where('id_riwayat_ib', $id)->update(['file_2' => 'arsip/ib/2.pdf']);
        $this->assertSame('Universitas Uji', (new IbService())->ubah($auth, $id, $this->isianStudi(), [])['institusi_pendidikan']);
    }

    public function testHapusMenghapusBerkas(): void
    {
        [, $auth] = $this->aktorStudi(Role::PPPK, ['id_jenis_pegawai' => 6]);
        $id       = (int) (new IbService())->tambah($auth, $this->isianStudi(), $this->berkasSemuaSlot(self::IB))['id_riwayat_ib'];

        (new IbService())->hapus($auth, $id);

        $this->assertSame(0, $this->db->table('riwayat_ib')->where('id_riwayat_ib', $id)->countAllResults());
        $this->assertSame([], $this->tagLampiran(BerkasStudi::KODE_IB, $id));
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

    public function testOpsiMemuatPenandaAdalahPicIb(): void
    {
        [, $auth]       = $this->aktorStudi(Role::PPPK);
        [$picNip, $pic] = $this->aktorStudi(Role::PPPK);
        $this->jadikanPic($picNip, PetugasLayananService::JENIS_IB);

        $this->assertFalse((new IbService())->opsi($auth, null)['adalah_pic']);
        $this->assertTrue((new IbService())->opsi($pic, null)['adalah_pic']);
    }
}
