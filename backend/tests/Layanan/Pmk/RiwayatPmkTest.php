<?php

declare(strict_types=1);

namespace Tests\Layanan\Pmk;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Layanan\Pmk\RiwayatPmkService;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * C-09 riwayat PMK final (PRD Fase 4 §5.4, §6.9.1; data impor/SIASN [K D16]): daftar role 2 diri sendiri / role 3 lingkup
 * / role 1 semua (+filter `nip`); role 1 menambah arsip SK (kode 42, wajib, 2 MB), menghapus arsip dengan minimal satu
 * tersisa, menghapus riwayat (fisik + audit + arsip); role lain → 403.
 *
 * @internal
 */
final class RiwayatPmkTest extends DatabaseTestCase
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

    public function testDaftarPerRole(): void
    {
        $idSatker    = $this->buatSatker();
        [$a, $authA] = $this->aktorStudi(Role::PEGAWAI, [], $idSatker);
        [$b]         = $this->aktorStudi(Role::PEGAWAI, [], $this->buatSatker());
        [, $admin3]  = $this->aktorStudi(Role::ADMIN_SATKER, [], $idSatker);
        [, $su]      = $this->aktorStudi(Role::SUPER_ADMIN);
        $this->riwayat($a);
        $this->riwayat($b);

        $this->assertSame(1, (new RiwayatPmkService())->daftar($authA, ['nip' => $b])['total'], 'role 2 hanya diri sendiri');
        $this->assertSame(1, (new RiwayatPmkService())->daftar($admin3, [])['total'], 'role 3 lingkup');
        $this->assertSame(2, (new RiwayatPmkService())->daftar($su, [])['total']);
        $this->assertSame(1, (new RiwayatPmkService())->daftar($su, ['nip' => $b])['total']);
    }

    public function testArsipSkMinimalSatuTersisa(): void
    {
        [$nip]  = $this->aktorStudi(Role::PEGAWAI);
        [, $su] = $this->aktorStudi(Role::SUPER_ADMIN);
        $id     = $this->riwayat($nip);
        $svc    = new RiwayatPmkService();

        $this->assertSame(['arsip'], array_keys($this->galat(fn () => $svc->tambahArsip($su, $id, null))));
        $this->assertSame(['arsip'], array_keys($this->galat(fn () => $svc->tambahArsip($su, $id, $this->berkasPdf('besar.pdf', 3 * 1024 * 1024)))), 'batas 2 MB (P-7)');

        $arsip = $svc->tambahArsip($su, $id, $this->berkasPdf('sk1.pdf'));
        $arsip = $svc->tambahArsip($su, $id, $this->berkasPdf('sk2.pdf'));
        $this->assertCount(2, $arsip);

        $svc->hapusArsip($su, $id, (int) $arsip[0]['id_attachment']);
        $this->assertSame(['arsip'], array_keys($this->galat(fn () => $svc->hapusArsip($su, $id, (int) $arsip[1]['id_attachment']))));
    }

    public function testHapusRiwayatRole1Saja(): void
    {
        [$nip, $auth] = $this->aktorStudi(Role::PEGAWAI);
        [, $su]       = $this->aktorStudi(Role::SUPER_ADMIN);
        $id           = $this->riwayat($nip);
        (new RiwayatPmkService())->tambahArsip($su, $id, $this->berkasPdf());

        try {
            (new RiwayatPmkService())->hapus($auth, $id);
            $this->fail('Pemilik tidak boleh menghapus riwayat PMK');
        } catch (ForbiddenException) {
        }

        (new RiwayatPmkService())->hapus($su, $id);
        $this->assertSame(0, $this->db->table('riwayat_pmk')->where('id_riwayat_pmk', $id)->countAllResults());
        $this->assertSame(0, $this->db->table('document_attachment')->where('id_riwayat', RiwayatPmkService::KODE_ARSIP)->where('id_entri', (string) $id)->countAllResults());

        $this->expectException(NotFoundException::class);
        (new RiwayatPmkService())->hapus($su, $id);
    }

    private function riwayat(string $nip): int
    {
        $this->db->table('riwayat_pmk')->insert(['nip' => $nip, 'no_sk' => 'SK/PMK/1', 'tgl_sk' => '2020-01-10', 'instansi' => 'PT Lama', 'tgl_awal' => '2010-01-01', 'tgl_akhir' => '2012-12-31', 'mker_th' => 2, 'mker_bl' => 11]);

        return (int) $this->db->insertID();
    }

    /**
     * @return array<string, list<string>>
     */
    private function galat(\Closure $aksi): array
    {
        try {
            $aksi();
        } catch (ValidationException $e) {
            return $e->getErrors() ?? [];
        }

        $this->fail('Seharusnya 422');
    }
}
