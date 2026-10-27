<?php

declare(strict_types=1);

namespace Tests\Layanan\Tb;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Layanan\Studi\BerkasStudi;
use App\Libraries\Layanan\Tb\PerpanjanganTbService;
use App\Libraries\Layanan\Tb\TbService;
use Closure;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * C-07 perpanjangan TB (PRD Fase 4 §6.7.4; AC-07.2): baris terpisah dengan `id_riwayat_tb` induk; ringkasan
 * `tgl_*_perpan` induk = MAX pada tambah/ubah/hapus (pengganti trigger); `tgl_akhir` induk tidak berubah; role 1 saja;
 * induk harus disetujui; berkas wajib → kode 48; hapus induk menghapus perpanjangan + berkasnya.
 *
 * @internal
 */
final class TbPerpanjanganTest extends DatabaseTestCase
{
    use LayananStudiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private AuthContext $su;

    private string $nip;

    private AuthContext $pemohon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();
        [, $this->su]                = $this->aktorStudi(Role::SUPER_ADMIN);
        [$this->nip, $this->pemohon] = $this->aktorStudi(Role::PEGAWAI);
    }

    protected function tearDown(): void
    {
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testTambahUbahHapusMenyegarkanRingkasanInduk(): void
    {
        $id  = $this->tbDisetujui();
        $svc = new PerpanjanganTbService();

        $p1 = $svc->tambah($this->su, $id, ['tgl_akhir_perpan' => '2029-05-31'], $this->berkasPdf('perpan1.pdf'));
        $this->assertSame(['2028-12-01', '2029-05-31', '2028-11-30'], $this->ringkasan($id), 'mulai bawaan = tgl_akhir + 1 hari [K]');

        $p2 = $svc->tambah($this->su, $id, ['tgl_akhir_perpan' => '2029-11-30'], $this->berkasPdf('perpan2.pdf'));
        $this->assertSame(['2029-06-01', '2029-11-30', '2028-11-30'], $this->ringkasan($id), 'mulai bawaan = akhir perpanjangan + 1; MAX');
        $this->assertSame(2, $this->db->table('riwayat_tb_perpan')->where('id_riwayat_tb', $id)->countAllResults(), 'baris terpisah');
        $this->assertSame(1, $this->db->table('document_attachment')->where('id_riwayat', BerkasStudi::KODE_TB_PERPAN)->where('id_entri', (string) $p2)->countAllResults());
        $this->assertNotSame('', (string) $this->db->table('riwayat_tb_perpan')->where('id_riwayat_tb_perpan', $p2)->get()->getRow()->file_sperpan);

        $svc->ubah($this->su, $p2, ['tgl_mulai_perpan' => '2029-06-01', 'tgl_akhir_perpan' => '2030-02-28']);
        $this->assertSame(['2029-06-01', '2030-02-28', '2028-11-30'], $this->ringkasan($id));

        $svc->hapus($this->su, $p2);
        $this->assertSame(['2028-12-01', '2029-05-31', '2028-11-30'], $this->ringkasan($id));
        $this->assertSame(0, $this->db->table('document_attachment')->where('id_riwayat', BerkasStudi::KODE_TB_PERPAN)->where('id_entri', (string) $p2)->countAllResults());

        $svc->hapus($this->su, $p1);
        $this->assertSame([null, null, '2028-11-30'], $this->ringkasan($id), 'tanpa perpanjangan → NULL');
    }

    public function testValidasiDanIndukHarusDisetujui(): void
    {
        $svc = new PerpanjanganTbService();
        $id  = $this->tbDisetujui();

        $this->assertSame(['file_sperpan', 'tgl_akhir_perpan'], $this->kunci(fn () => $svc->tambah($this->su, $id, [], null)));
        $this->assertSame(['tgl_akhir_perpan'], $this->kunci(fn () => $svc->tambah($this->su, $id, ['tgl_mulai_perpan' => '2029-01-01', 'tgl_akhir_perpan' => '2028-12-31'], $this->berkasPdf())));

        $pengajuan = (int) (new TbService())->tambah($this->su, $this->isianStudi(['nip' => $this->nip, 'tgl_mulai' => '2030-01-01', 'tgl_akhir' => '2031-01-01']), [])['id_riwayat_tb'];
        $this->assertSame(['status'], $this->kunci(fn () => $svc->tambah($this->su, $pengajuan, ['tgl_akhir_perpan' => '2031-06-30'], $this->berkasPdf())));
    }

    public function testHanyaRole1(): void
    {
        $id = $this->tbDisetujui();

        $this->expectException(ForbiddenException::class);
        (new PerpanjanganTbService())->tambah($this->pemohon, $id, ['tgl_akhir_perpan' => '2029-05-31'], $this->berkasPdf());
    }

    public function testHapusIndukMenghapusPerpanjanganDanBerkasnya(): void
    {
        $id = $this->tbDisetujui();
        $p1 = (new PerpanjanganTbService())->tambah($this->su, $id, ['tgl_akhir_perpan' => '2029-05-31'], $this->berkasPdf());

        (new TbService())->hapus($this->su, $id);

        $this->assertSame(0, $this->db->table('riwayat_tb_perpan')->where('id_riwayat_tb', $id)->countAllResults());
        $this->assertSame(0, $this->db->table('document_attachment')->where('id_riwayat', BerkasStudi::KODE_TB_PERPAN)->where('id_entri', (string) $p1)->countAllResults());
    }

    public function testDetailMemuatPerpanjanganDanLampirannya(): void
    {
        $id = $this->tbDisetujui();
        (new PerpanjanganTbService())->tambah($this->su, $id, ['tgl_akhir_perpan' => '2029-05-31'], $this->berkasPdf());

        $detail = (new TbService())->detail($this->su, $id);

        $this->assertCount(1, $detail['perpanjangan']);
        $this->assertCount(1, $detail['perpanjangan'][0]['lampiran']);
        $this->assertTrue($detail['aksi']['perpanjangan']);
    }

    private function tbDisetujui(): int
    {
        return (int) (new TbService())->tambah($this->su, $this->isianStudi(['nip' => $this->nip, 'status' => '1']) + $this->dataSkTb(), [])['id_riwayat_tb'];
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: string}
     */
    private function ringkasan(int $id): array
    {
        $row = $this->db->table('riwayat_tb')->select('tgl_mulai_perpan, tgl_akhir_perpan, tgl_akhir')->where('id_riwayat_tb', $id)->get()->getRowArray();

        return [$row['tgl_mulai_perpan'], $row['tgl_akhir_perpan'], (string) $row['tgl_akhir']];
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
