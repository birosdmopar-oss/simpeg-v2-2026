<?php

declare(strict_types=1);

namespace Tests\Layanan\Tb;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\Studi\BerkasStudi;
use App\Libraries\Layanan\Tb\TbService;
use Closure;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * C-07 Tugas Belajar CRUD (PRD Fase 4 §5.2, §6.7.2; AC-07.1) + slot berkas (keputusan reviewer CR 27-10 butir 3): pengaju
 * per role, validasi 422 (semua galat), snapshot & lama tahun server, tumpang tindih, slot hilang → 422 per slot, slot
 * dari kolom impor → lolos, ganti slot → baris lama hilang, hak ubah/hapus [K], lihat & mode daftar.
 *
 * @internal
 */
final class TbCrudTest extends DatabaseTestCase
{
    use LayananStudiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private const TB = AturanStudi::JENIS_TB;

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

    public function testPegawaiMengajukanUntukDiriSendiriDenganEmpatBerkas(): void
    {
        [$nip, $auth] = $this->aktorStudi(Role::PEGAWAI, ['nama' => 'Budi Uji', 'glr_akhir' => 'S.E.']);
        [$lain]       = $this->aktorStudi(Role::PEGAWAI);

        $tb = (new TbService())->tambah($auth, $this->isianStudi(['nip' => $lain, 'status' => '1', 'lama_tahun' => '9']), $this->berkasSemuaSlot(self::TB));

        $this->assertSame([$nip, 0, 'Budi Uji, S.E.', 'S.1', '1.90'], [$tb['nip'], (int) $tb['status'], $tb['nama'], $tb['jenjang_pendidikan_singkat'], (string) $tb['lama_tahun']]);
        $this->assertSame(['file_1', 'file_2', 'file_3', 'file_sp'], $this->tagLampiran(BerkasStudi::KODE_TB, (int) $tb['id_riwayat_tb']));
        $this->assertSame([null, null, null, null], [$tb['file_sp'], $tb['file_1'], $tb['file_2'], $tb['file_3']], 'kolom file_* tidak diisi v2 (DQ-20)');
        $this->assertSame(['Menunggu PIC', 'warning'], [$tb['label_status'], $tb['nada_status']]);
        $this->assertCount(4, $tb['lampiran']);
        $this->assertSame(0, $tb['status_penuh']);
    }

    public function testPttBolehMengajukan(): void
    {
        [, $auth] = $this->aktorStudi(Role::PTT, ['id_jenis_pegawai' => 2]);

        $this->assertSame(0, (int) (new TbService())->tambah($auth, $this->isianStudi(), $this->berkasSemuaSlot(self::TB))['status']);
    }

    public function testValidasiMengumpulkanSemuaGalat(): void
    {
        [, $auth] = $this->aktorStudi(Role::PEGAWAI);

        $errors = $this->galat(fn () => (new TbService())->tambah($auth, $this->isianStudi([
            'tgl_akhir'             => '2026-11-01',
            'id_jenjang_pendidikan' => '3',
            'institusi_pendidikan'  => ' ',
        ]), $this->berkasSemuaSlot(self::TB)));

        $this->assertSame(['id_jenjang_pendidikan', 'institusi_pendidikan', 'tgl_akhir'], $this->kunci($errors));
        $this->assertSame('Tanggal akhir tidak boleh sebelum tanggal mulai.', $errors['tgl_akhir'][0]);
    }

    public function testSlotHilangMenghasilkan422PerSlot(): void
    {
        [, $auth] = $this->aktorStudi(Role::PEGAWAI);

        $errors = $this->galat(fn () => (new TbService())->tambah($auth, $this->isianStudi(), $this->berkasSemuaSlot(self::TB, ['file_sp', 'file_1'])));

        $this->assertSame(['file_2', 'file_3'], $this->kunci($errors));
        $this->assertSame('Surat Pernyataan Bermaterai wajib diunggah.', $errors['file_3'][0]);
    }

    public function testBerkasTanpaEkstensiDitolakPadaSlotnya(): void
    {
        [, $auth] = $this->aktorStudi(Role::PEGAWAI);
        $berkas   = $this->berkasSemuaSlot(self::TB);

        $berkas['file_2'] = $this->berkasPdf('tanpa_ekstensi');

        $this->assertSame(['file_2'], $this->kunci($this->galat(fn () => (new TbService())->tambah($auth, $this->isianStudi(), $berkas))));
    }

    public function testRole1BebasBerkasDanHanyaUntukPns(): void
    {
        [$pns]  = $this->aktorStudi(Role::PEGAWAI);
        [$ptt]  = $this->aktorStudi(Role::PTT, ['id_jenis_pegawai' => 2]);
        [, $su] = $this->aktorStudi(Role::SUPER_ADMIN);

        $tb = (new TbService())->tambah($su, $this->isianStudi(['nip' => $pns]), []);
        $this->assertSame([$pns, 0], [$tb['nip'], (int) $tb['status']]);

        $this->assertSame(['nip'], $this->kunci($this->galat(fn () => (new TbService())->tambah($su, $this->isianStudi(['nip' => $ptt]), []))));
    }

    public function testRole1Status1WajibDataSkDanMemberiEfekFinal(): void
    {
        [$nip]  = $this->aktorStudi(Role::PEGAWAI);
        [, $su] = $this->aktorStudi(Role::SUPER_ADMIN);
        $isian  = $this->isianStudi(['nip' => $nip, 'status' => '1', 'tgl_mulai' => '2026-09-01', 'tgl_akhir' => '2028-08-31']);

        $this->assertSame(['ds_tb', 'no_ds_tb', 'no_sk', 'tgl_sk'], $this->kunci($this->galat(fn () => (new TbService())->tambah($su, $isian, []))));

        $tb = (new TbService())->tambah($su, $isian + $this->dataSkTb(), []);

        $this->assertSame([1, 1, 'SK/TB/001'], [(int) $tb['status'], (int) $tb['show_pl'], $tb['no_sk']]);
        $this->assertSame(2, (int) $this->db->table('pegawai')->where('nip', $nip)->get()->getRow()->id_jenis_status, 'hari ini dalam rentang → Tugas Belajar');
        $this->assertSame(1, $tb['status_penuh']);
    }

    public function testRole3HanyaDalamLingkup(): void
    {
        $idSatker  = $this->buatSatker();
        [, $admin] = $this->aktorStudi(Role::ADMIN_SATKER, [], $idSatker);
        [$dalam]   = $this->aktorStudi(Role::PEGAWAI, [], $idSatker);
        [$luar]    = $this->aktorStudi(Role::PEGAWAI, [], $this->buatSatker());

        $this->assertSame($dalam, (new TbService())->tambah($admin, $this->isianStudi(['nip' => $dalam, 'status' => '1']), $this->berkasSemuaSlot(self::TB))['nip']);
        $this->assertSame(0, (int) $this->db->table('riwayat_tb')->where('nip', $dalam)->get()->getRow()->status, 'role 3 selalu status 0');

        $this->expectException(ForbiddenException::class);
        (new TbService())->tambah($admin, $this->isianStudi(['nip' => $luar]), $this->berkasSemuaSlot(self::TB));
    }

    public function testTumpangTindihDenganStatusAktifDitolak(): void
    {
        [, $auth] = $this->aktorStudi(Role::PEGAWAI);
        (new TbService())->tambah($auth, $this->isianStudi(), $this->berkasSemuaSlot(self::TB));

        $errors = $this->galat(fn () => (new TbService())->tambah($auth, $this->isianStudi(['tgl_mulai' => '2027-01-01', 'tgl_akhir' => '2027-06-30']), $this->berkasSemuaSlot(self::TB)));

        $this->assertSame(['tgl_mulai'], $this->kunci($errors));
    }

    public function testGantiSlotMenghapusBarisLama(): void
    {
        [, $auth] = $this->aktorStudi(Role::PEGAWAI);
        $tb       = (new TbService())->tambah($auth, $this->isianStudi(), $this->berkasSemuaSlot(self::TB));
        $id       = (int) $tb['id_riwayat_tb'];
        $lama     = $this->idLampiran($id, 'file_1');

        $baru = (new TbService())->ubah($auth, $id, $this->isianStudi(['institusi_pendidikan' => 'Universitas Baru']), ['file_1' => $this->berkasPdf('pengganti.pdf')]);

        $this->assertSame('Universitas Baru', $baru['institusi_pendidikan']);
        $this->assertSame(['file_1', 'file_2', 'file_3', 'file_sp'], $this->tagLampiran(BerkasStudi::KODE_TB, $id));
        $this->assertSame(0, $this->db->table('document_attachment')->where('id_attachment', $lama)->countAllResults(), 'baris lama slot itu hilang');
        $this->assertNotSame($lama, $this->idLampiran($id, 'file_1'));
    }

    public function testSlotTerisiDariKolomImporLolosSaatUbah(): void
    {
        [$nip, $auth] = $this->aktorStudi(Role::PEGAWAI);
        [, $su]       = $this->aktorStudi(Role::SUPER_ADMIN);
        $id           = (int) (new TbService())->tambah($su, $this->isianStudi(['nip' => $nip]), [])['id_riwayat_tb'];

        // Data impor: tiga slot berisi path legacy, slot file_3 kosong.
        $this->db->table('riwayat_tb')->where('id_riwayat_tb', $id)->update(['file_sp' => 'arsip/sp.pdf', 'file_1' => 'arsip/1.pdf', 'file_2' => 'arsip/2.pdf']);

        $this->assertSame(['file_3'], $this->kunci($this->galat(fn () => (new TbService())->ubah($auth, $id, $this->isianStudi(), []))));

        $tb = (new TbService())->ubah($auth, $id, $this->isianStudi(), ['file_3' => $this->berkasPdf()]);
        $this->assertSame(['file_3'], $this->tagLampiran(BerkasStudi::KODE_TB, (int) $tb['id_riwayat_tb']));
        $this->assertSame('arsip/sp.pdf', $tb['file_sp'], 'kolom impor dipertahankan');
    }

    public function testPemilikUbahHanyaSebelumTanggalMulai(): void
    {
        [$nip, $auth] = $this->aktorStudi(Role::PEGAWAI);
        $tb           = (new TbService())->tambah($auth, $this->isianStudi(), $this->berkasSemuaSlot(self::TB));
        $id           = (int) $tb['id_riwayat_tb'];

        $this->db->table('riwayat_tb')->where('id_riwayat_tb', $id)->update(['tgl_mulai' => self::HARI_INI]);

        $this->assertSame(['status'], $this->kunci($this->galat(fn () => (new TbService())->ubah($auth, $id, $this->isianStudi(['tgl_mulai' => self::HARI_INI]), []))));
        $this->assertFalse((new TbService())->detail($auth, $id)['aksi']['ubah']);
        $this->assertSame($nip, (string) $this->db->table('riwayat_tb')->where('id_riwayat_tb', $id)->get()->getRow()->nip);
    }

    public function testHapusPemilikHanyaStatus0DanRole1Semua(): void
    {
        [$nip, $auth] = $this->aktorStudi(Role::PEGAWAI);
        [, $su]       = $this->aktorStudi(Role::SUPER_ADMIN);
        $id           = (int) (new TbService())->tambah($auth, $this->isianStudi(), $this->berkasSemuaSlot(self::TB))['id_riwayat_tb'];

        (new TbService())->hapus($auth, $id);
        $this->assertSame(0, $this->db->table('riwayat_tb')->where('id_riwayat_tb', $id)->countAllResults());
        $this->assertSame([], $this->tagLampiran(BerkasStudi::KODE_TB, $id), 'berkas ikut dihapus');
        $this->assertSame(1, $this->db->table('audit_logs')->where('entity', 'riwayat_tb')->where('entity_id', (string) $id)->where('event', 'delete')->countAllResults());

        $setuju = (int) (new TbService())->tambah($su, $this->isianStudi(['nip' => $nip, 'status' => '1', 'tgl_mulai' => '2026-09-01', 'tgl_akhir' => '2028-08-31']) + $this->dataSkTb(), [])['id_riwayat_tb'];
        $this->assertSame(['status'], $this->kunci($this->galat(fn () => (new TbService())->hapus($auth, $setuju))));

        (new TbService())->hapus($su, $setuju);
        $this->assertSame(1, (int) $this->db->table('pegawai')->where('nip', $nip)->get()->getRow()->id_jenis_status, 'hapus TB disetujui dalam rentang → status kembali Aktif');
    }

    public function testLihatDetailPemilikPicAdminLingkupLainnya404(): void
    {
        $idSatker       = $this->buatSatker();
        [, $auth]       = $this->aktorStudi(Role::PEGAWAI, [], $idSatker);
        [$picNip, $pic] = $this->aktorStudi(Role::PTT, ['id_jenis_pegawai' => 2], $this->buatSatker());
        [, $lain]       = $this->aktorStudi(Role::PEGAWAI);
        [, $admin3]     = $this->aktorStudi(Role::ADMIN_SATKER, [], $idSatker);
        $this->jadikanPic($picNip, PetugasLayananService::JENIS_TB);
        $id = (int) (new TbService())->tambah($auth, $this->isianStudi(), $this->berkasSemuaSlot(self::TB))['id_riwayat_tb'];

        $this->assertTrue((new TbService())->detail($pic, $id)['aksi']['proses'], 'PIC unit lain melihat & boleh proses (D14)');
        $this->assertFalse((new TbService())->detail($admin3, $id)['aksi']['proses']);

        $this->expectException(NotFoundException::class);
        (new TbService())->detail($lain, $id);
    }

    public function testModeDaftar(): void
    {
        [, $a]          = $this->aktorStudi(Role::PEGAWAI);
        [, $b]          = $this->aktorStudi(Role::PEGAWAI);
        [$picNip, $pic] = $this->aktorStudi(Role::PEGAWAI);
        $this->jadikanPic($picNip, PetugasLayananService::JENIS_TB);
        (new TbService())->tambah($a, $this->isianStudi(), $this->berkasSemuaSlot(self::TB));
        (new TbService())->tambah($b, $this->isianStudi(), $this->berkasSemuaSlot(self::TB));

        $this->assertSame(1, (new TbService())->daftar($a, [])['total'], 'saya = milik sendiri');
        $antrean = (new TbService())->daftar($pic, ['mode' => 'pic']);
        $this->assertSame([2, true], [$antrean['total'], $antrean['can_process']]);

        $this->expectException(ForbiddenException::class);
        (new TbService())->daftar($a, ['mode' => 'pic']);
    }

    public function testModeAdminUntukPegawaiDitolak(): void
    {
        [, $a] = $this->aktorStudi(Role::PEGAWAI);

        $this->expectException(ForbiddenException::class);
        (new TbService())->daftar($a, ['mode' => 'admin']);
    }

    /**
     * @return array<string, list<string>>
     */
    private function galat(Closure $aksi): array
    {
        try {
            $aksi();
        } catch (ValidationException $e) {
            return $e->getErrors() ?? [];
        }

        $this->fail('Seharusnya 422');
    }

    /**
     * @param array<string, mixed> $errors
     *
     * @return list<string>
     */
    private function kunci(array $errors): array
    {
        $kunci = array_keys($errors);
        sort($kunci);

        return $kunci;
    }

    private function idLampiran(int $id, string $tag): int
    {
        return (int) $this->db->table('document_attachment')->where('id_riwayat', BerkasStudi::KODE_TB)->where('id_entri', (string) $id)->where('tag', $tag)
            ->get()->getRow()->id_attachment;
    }

    public function testOpsiMemuatPenandaAdalahPicTb(): void
    {
        [, $auth]       = $this->aktorStudi(Role::PEGAWAI);
        [$picNip, $pic] = $this->aktorStudi(Role::PEGAWAI);
        $this->jadikanPic($picNip, PetugasLayananService::JENIS_TB);

        $this->assertFalse((new TbService())->opsi($auth, null)['adalah_pic']);
        $this->assertTrue((new TbService())->opsi($pic, null)['adalah_pic']);
    }
}
