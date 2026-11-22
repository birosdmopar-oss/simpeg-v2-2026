<?php

declare(strict_types=1);

namespace Tests\Layanan\Cuti;

use App\Database\Migrations\AddFkNipLayananFase4;
use App\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\CutiUjiTrait;

/**
 * CR-171 (G3): FK ditahan DBV-022 `riwayat_cuti.nip_atasan_langsung` / `nip_yang_menyetujui` → `pegawai` dipasang sementara.
 * Approver lolos validasi() (di luar transaksi), lalu dihapus sebelum tulis (seam `sebelumTransaksi()`): INSERT/UPDATE kena 1452
 * → 422 di field approver yang hilang (bukan 500), tanpa baris tertulis/berubah.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class CutiFkApproverTest extends DatabaseTestCase
{
    use CutiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private ?AddFkNipLayananFase4 $fk = null;

    protected function tearDown(): void
    {
        if ($this->fk !== null) {
            $this->fk->down();
            $this->fk = null;
        }

        parent::tearDown();
    }

    public function testTambahApproverHilangSebelumInsert422(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $this->pasangFk();

        $svc = $this->cutiService(null, fn () => $this->hapusPegawai($a['al']['nip']));

        $this->assertField422('nip_atasan_langsung', fn () => $svc->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null));
        $this->assertSame(0, $this->db->table('riwayat_cuti')->where('nip', $a['pemohon']['nip'])->countAllResults());
    }

    public function testUbahApproverHilangSebelumUpdate422(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $this->pasangFk();
        $id    = (int) $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null)['id_riwayat_cuti'];
        $pyb2  = $this->buatPejabat();
        $sidik = $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->get()->getRowArray();

        $svc = $this->cutiService(null, fn () => $this->hapusPegawai($pyb2['nip']));

        $this->assertField422('nip_yang_menyetujui', fn () => $svc->ubah($a['pemohon']['auth'], $id, $this->isianCuti($a['al']['nip'], $pyb2['nip']), null));
        $this->assertSame($sidik, $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->get()->getRowArray(), 'baris tidak berubah (rollback)');
    }

    private function pasangFk(): void
    {
        require_once APPPATH . 'Database/MigrationsDitahan/AddFkNipLayananFase4.php';

        $this->fk = new AddFkNipLayananFase4();
        $this->fk->up();
    }

    /**
     * Hapus fisik pegawai tanpa menunggu anak lain (pmj, akun): yang diuji hanya FK approver cuti.
     */
    private function hapusPegawai(string $nip): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $this->db->table('pegawai')->where('nip', $nip)->delete();
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function assertField422(string $field, callable $aksi): void
    {
        try {
            $aksi();
            $this->fail("Seharusnya 422 errors.{$field}");
        } catch (ValidationException $e) {
            $this->assertSame([$field => ['Pegawai tidak ditemukan.']], $e->getErrors());
        }
    }
}
