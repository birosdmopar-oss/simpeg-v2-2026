<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\SnapshotSyncInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Kp;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Pendidikan;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use App\Libraries\Kepegawaian\Riwayat\RiwayatRegistry;
use Closure;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatBtcTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-10 Riwayat Pendidikan (WS-1 M2 MAKE-005): B-TC generik + aturan legacy `L_pendidikan.php` lewat Definisi nyata.
 * PegawaiScope + AttachmentService/LocalStorageAdapter nyata (MAKE-009), lewat RiwayatUjiTrait::pasangEngineNyata().
 *
 * @internal
 */
final class PendidikanTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatBtcTrait;
    use RiwayatUjiTrait;

    private Pendidikan $d;
    private int $idSatker;
    private string $nip;
    private AuthContext $pegawai;
    private AuthContext $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->d = new Pendidikan();
        $this->pasangEngineNyata([$this->d, new Kp()]);
        $this->siapkanMasterPendidikan();
        $this->idSatker              = $this->buatSatker();
        [$this->nip, $this->pegawai] = $this->aktor(Role::PEGAWAI, [], $this->idSatker);
        $this->admin                 = $this->aktor(Role::SUPER_ADMIN)[1];
        // Legacy: role 2 hanya boleh mengisi pendidikan selama masih CPNS (bolehAksiUntuk).
        $this->jadikanPegawaiCpns($this->nip);
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();

        parent::tearDown();
    }

    // ---- B-TC ----------------------------------------------------------

    protected function btcDefinisi(): RiwayatDefinisi
    {
        return $this->d;
    }

    protected function btcPayload(string $nip): array
    {
        return $this->dataS1();
    }

    protected function btcBaris(string $nip): array
    {
        return $this->dataS1() + ['jenjang_pendidikan_singkat' => 'S1', 'bidang_pendidikan' => 'Pariwisata', 'jurusan_pendidikan' => 'Perhotelan'];
    }

    protected function btcBerkas(): array
    {
        return [Pendidikan::LAMPIRAN_IJAZAH => $this->berkasUji()];
    }

    /**
     * Scope produksi (PegawaiScope MAKE-009) yang sudah terpasang.
     */
    protected function btcScopeRole3(array $nipDalam): null
    {
        return null;
    }

    protected function btcSiapkanPegawaiMandiri(string $nip): void
    {
        $this->jadikanPegawaiCpns($nip);
    }

    /**
     * Lihat riwayat pegawai lain di satker lain: izin Matriks v2 (1, 2, 3, 6, 7) × PegawaiScope — hanya role 1.
     */
    protected function btcHarapanLihat(int $role): bool
    {
        return $role === Role::SUPER_ADMIN;
    }

    // ---- Aturan B-10 ---------------------------------------------------

    public function testIzinMatriksV2(): void
    {
        foreach (Role::all() as $role) {
            $semua = in_array($role, [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK], true);

            $this->assertSame($semua, $this->d->boleh(AksiRiwayat::Lihat, $role), "lihat {$role}");
            $this->assertSame($semua, $this->d->boleh(AksiRiwayat::Tambah, $role), "tambah {$role}");
            $this->assertSame(in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true), $this->d->boleh(AksiRiwayat::Proses, $role), "proses {$role}");
        }
    }

    public function testRole2HanyaSelamaCpns(): void
    {
        // M-1 (keputusan user 12-10, legacy Pendidikan.php:70-72, :127-131, :226-231, :460-465).
        $id = (int) $this->tambah($this->pegawai, $this->dataS1())['id_riwayat_pendidikan'];

        $this->jadikanPegawaiCpns($this->nip, false);
        $this->assertStatus(403, fn () => $this->tambah($this->pegawai, $this->dataS1(['tgl_lulus' => '2019-08-30'])));
        $this->assertStatus(403, fn () => $this->engine()->ubah($this->pegawai, $this->nip, 'pendidikan', $id, ['institusi_pendidikan' => 'X']));
        $this->assertStatus(403, fn () => $this->engine()->hapus($this->pegawai, $this->nip, 'pendidikan', $id));
        $this->assertCount(1, $this->engine()->daftar($this->pegawai, $this->nip, 'pendidikan'), 'lihat tetap boleh');

        // Tanpa snapshot pegawai_kp = bukan CPNS (legacy $isCPNS false).
        $this->db->table('pegawai_kp')->where('nip', $this->nip)->delete();
        $this->assertStatus(403, fn () => $this->tambah($this->pegawai, $this->dataS1(['tgl_lulus' => '2019-08-30'])));

        // Descriptor mengikuti hook: tab tampil, tombol tulis mati.
        $tab = array_column(service('riwayatRegistry')->descriptorUntuk($this->pegawai, $this->nip), null, 'jenis')['pendidikan'];
        $this->assertSame([true, false, false, false], [$tab['can_view'], $tab['can_create'], $tab['can_edit'], $tab['can_delete']]);

        // Admin & role 7 (PPPK) tidak terpengaruh.
        [, $adminSatker] = $this->aktor(Role::ADMIN_SATKER, [], $this->idSatker);
        $this->tambah($this->admin, $this->dataS1(['tgl_lulus' => '2019-08-30']));
        $this->engine()->ubah($adminSatker, $this->nip, 'pendidikan', $id, ['institusi_pendidikan' => 'Y']);
        [$nipPppk, $pppk] = $this->aktor(Role::PPPK);
        $this->engine()->tambah($pppk, $nipPppk, 'pendidikan', $this->dataS1(), [Pendidikan::LAMPIRAN_IJAZAH => $this->berkasUji()]);
    }

    public function testRole1MemilihStatusSaatUbahDanTolakWajibAlasan(): void
    {
        // M-2 + L-1 (keputusan user 12-10; legacy views/hr/employee/rwy/pendidikan/form.php:59-76).
        $id = (int) $this->tambah($this->pegawai, $this->dataS1())['id_riwayat_pendidikan'];

        $this->assertErrors(['reason_note'], fn () => $this->engine()->ubah($this->admin, $this->nip, 'pendidikan', $id, ['status' => '2']));
        $this->assertErrors(['reason_note'], fn () => $this->engine()->ubah($this->admin, $this->nip, 'pendidikan', $id, ['status' => '2', 'reason_note' => str_repeat('x', 256)]));

        $row = $this->engine()->ubah($this->admin, $this->nip, 'pendidikan', $id, ['status' => '2', 'reason_note' => ' Ijazah tidak sah ']);
        $this->assertSame(['2', 'Ijazah tidak sah'], [(string) $row['status'], $row['reason_note']]);

        $row = $this->engine()->ubah($this->admin, $this->nip, 'pendidikan', $id, ['status' => 1]);
        $this->assertSame('1', (string) $row['status']);
        $this->assertSame((string) $id, (string) $this->snapshotUji('pegawai_pendidikan', $this->nip)['id_riwayat_pendidikan']);

        // Role 3 tidak memilih status (→ 1, field status diabaikan).
        [, $adminSatker] = $this->aktor(Role::ADMIN_SATKER, [], $this->idSatker);
        $this->assertSame('1', (string) $this->engine()->ubah($adminSatker, $this->nip, 'pendidikan', $id, ['status' => '0'])['status']);
    }

    public function testStatusNullDataLamaDenganDefinisiNyata(): void
    {
        // L-4: baris impor ber-status NULL diperlakukan seperti 0 (UL_PEGAWAI → 0, role 3 → 1, role 1 tetap NULL).
        $baris = [];

        foreach (['2001-06-30', '2005-06-30', '2009-06-30'] as $tgl) {
            $id = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['tgl_lulus' => $tgl, 'status' => null]));
            $this->lampiranUji->simpan($this->nip, Pendidikan::LAMPIRAN_IJAZAH, $id, $this->berkasUji(), $this->d->lampiran()[0]);
            $baris[] = $id;
        }

        [, $adminSatker] = $this->aktor(Role::ADMIN_SATKER, [], $this->idSatker);

        $this->assertSame('0', (string) $this->engine()->ubah($this->pegawai, $this->nip, 'pendidikan', $baris[0], ['no_ijazah' => 'A'])['status']);
        $this->assertSame('1', (string) $this->engine()->ubah($adminSatker, $this->nip, 'pendidikan', $baris[1], ['no_ijazah' => 'B'])['status']);
        $this->assertSame((string) $baris[1], (string) $this->snapshotUji('pegawai_pendidikan', $this->nip)['id_riwayat_pendidikan']);
        $this->assertNull($this->engine()->ubah($this->admin, $this->nip, 'pendidikan', $baris[2], ['no_ijazah' => 'C'])['status']);
    }

    public function testDuplikatDiperiksaAtasNilaiAkhirSaatBarisDitolakDiaktifkan(): void
    {
        // M-3: baris ditolak bertanggal sama dengan baris aktif; PUT parsial tanpa tgl_lulus yang mengaktifkannya → 422.
        $this->tambah($this->admin, $this->dataS1());
        $ditolak = $this->sisipRiwayat($this->d, $this->nip, $this->btcBaris($this->nip) + ['status' => 2, 'reason_note' => 'x']);

        $this->assertErrors(['tgl_lulus'], fn () => $this->engine()->ubah($this->pegawai, $this->nip, 'pendidikan', $ditolak, ['institusi_pendidikan' => 'Z']));
    }

    public function testIjazahWajibTranskripDanGelarOpsional(): void
    {
        $this->assertErrors(['berkas.14'], fn () => $this->tambah($this->pegawai, $this->dataS1(), []));
        $this->assertErrors(['berkas.14'], fn () => $this->tambah($this->pegawai, $this->dataS1(), [Pendidikan::LAMPIRAN_TRANSKRIP_NILAI => $this->berkasUji()]));

        $row = $this->tambah($this->pegawai, $this->dataS1(), [
            Pendidikan::LAMPIRAN_IJAZAH            => $this->berkasUji('ijazah.pdf'),
            Pendidikan::LAMPIRAN_TRANSKRIP_NILAI   => $this->berkasUji('transkrip.pdf'),
            Pendidikan::LAMPIRAN_PENCANTUMAN_GELAR => $this->berkasUji('gelar.pdf'),
        ]);
        $id = (string) $row['id_riwayat_pendidikan'];

        foreach ([14, 39, 40] as $kode) {
            $this->assertCount(1, $this->lampiranUji->daftar($this->nip, $kode, $id), "kode {$kode}");
        }

        // Ubah tanpa berkas baru: Ijazah sudah ada (legacy vp() edit hanya mewajibkan bila belum ada).
        $this->engine()->ubah($this->pegawai, $this->nip, 'pendidikan', (int) $id, ['institusi_pendidikan' => 'Politeknik Pariwisata']);
    }

    public function testLampiranNyataTersimpanDanDibersihkanSaatRollback(): void
    {
        // Lampiran nyata (AttachmentService + LocalStorageAdapter): commit → berkas ada & baris document_attachment;
        // rollback (snapshot gagal) → baris riwayat & lampiran batal, berkas di disk direkonsiliasi selesaikan().
        $row = $this->tambah($this->admin, $this->dataS1());
        $this->assertCount(1, $this->berkasLampiranRiwayatUji());
        $this->assertSame(1, $this->db->table('document_attachment')->where('NIP', $this->nip)->where('id_entri', (string) $row['id_riwayat_pendidikan'])->countAllResults());

        Services::injectMock('snapshotSync', new class () implements SnapshotSyncInterface {
            public function sinkronkan(RiwayatDefinisi $definisi, string $nip, ?AuthContext $pelaku = null): void
            {
                throw new RuntimeException('snapshot gagal');
            }
        });

        try {
            $this->tambah($this->admin, $this->dataS1(['tgl_lulus' => '2018-08-30']));
            $this->fail('exception diharapkan');
        } catch (RuntimeException $e) {
            $this->assertSame('snapshot gagal', $e->getMessage());
        }

        $this->assertCount(1, $this->berkasLampiranRiwayatUji(), 'berkas unggahan transaksi gagal dihapus');
        $this->assertSame(1, $this->db->table('document_attachment')->where('NIP', $this->nip)->countAllResults());
        $this->assertSame(1, $this->db->table('riwayat_pendidikan')->where('nip', $this->nip)->countAllResults());
    }

    public function testHapusLunakMempertahankanLampiran(): void
    {
        // Keputusan WS-1 (retensi): hapus riwayat = status 10, lampiran baris itu tetap tersimpan (audit/pemulihan);
        // endpoint lampiran MAKE-009 menjawab 404 untuk baris status 10.
        $row = $this->tambah($this->pegawai, $this->dataS1());
        $this->engine()->hapus($this->pegawai, $this->nip, 'pendidikan', (int) $row['id_riwayat_pendidikan']);

        $this->assertCount(1, $this->berkasLampiranRiwayatUji());
        $this->assertSame(1, $this->db->table('document_attachment')->where('NIP', $this->nip)->countAllResults());
    }

    public function testBatasLampiran5Mb(): void
    {
        $this->assertErrors(['berkas.14'], fn () => $this->tambah($this->pegawai, $this->dataS1(), [14 => $this->berkasUji('besar.pdf', true, 5 * 1024 * 1024)]));
        $this->assertErrors(['berkas.14'], fn () => $this->tambah($this->pegawai, $this->dataS1(), [14 => $this->berkasUji('ijazah.txt', false)]));
    }

    public function testBidangDanJurusanWajibUntukJenjangDiAtasSma(): void
    {
        $tanpa = ['id_bidang_pendidikan' => '', 'id_jurusan_pendidikan' => ''] + $this->dataS1();

        $this->assertErrors(['id_bidang_pendidikan', 'id_jurusan_pendidikan'], fn () => $this->tambah($this->pegawai, $tanpa));

        // Jenjang 1-3: bidang/jurusan tidak wajib, nem disimpan dan ipk dibuang (sp() :833-834).
        $sd = $this->tambah($this->pegawai, ['id_jenjang_pendidikan' => 1, 'id_bidang_pendidikan' => '', 'id_jurusan_pendidikan' => '', 'nem' => '45.5', 'ipk' => '3.5'] + $this->dataS1(['tgl_lulus' => '2000-06-30']));
        $this->assertSame('45.50', $sd['nem']);
        $this->assertNull($sd['ipk']);
        $this->assertSame('SD', $sd['jenjang_pendidikan_singkat']);
    }

    public function testIsianLainnyaUntukKode98Dan1185(): void
    {
        $lain = ['id_bidang_pendidikan' => 98, 'id_jurusan_pendidikan' => 1185] + $this->dataS1();

        $this->assertErrors(['bidang_pendidikan_lain', 'jurusan_pendidikan_lain'], fn () => $this->tambah($this->pegawai, $lain));

        $row = $this->tambah($this->pegawai, ['bidang_pendidikan_lain' => 'Kuliner', 'jurusan_pendidikan_lain' => 'Tata Boga'] + $lain);
        $this->assertSame(['Kuliner', 'Tata Boga', 'Lainnya', 'Lainnya'], [$row['bidang_pendidikan_lain'], $row['jurusan_pendidikan_lain'], $row['bidang_pendidikan'], $row['jurusan_pendidikan']]);

        // Isian "lain" dibuang bila kodenya bukan 98/1185 (sp() :822, :829).
        $biasa = $this->tambah($this->pegawai, ['bidang_pendidikan_lain' => 'X', 'jurusan_pendidikan_lain' => 'Y'] + $this->dataS1(['tgl_lulus' => '2015-09-01']));
        $this->assertNull($biasa['bidang_pendidikan_lain']);
        $this->assertNull($biasa['jurusan_pendidikan_lain']);
    }

    public function testJurusanHarusDiBawahBidang(): void
    {
        $this->assertErrors(['id_jurusan_pendidikan'], fn () => $this->tambah($this->pegawai, ['id_bidang_pendidikan' => 98, 'bidang_pendidikan_lain' => 'X'] + $this->dataS1()));
    }

    public function testTanggalIjazahTidakBolehKembar(): void
    {
        $id = (int) $this->tambah($this->pegawai, $this->dataS1())['id_riwayat_pendidikan'];

        $this->assertErrors(['tgl_lulus'], fn () => $this->tambah($this->pegawai, $this->dataS1()));

        // Baris sendiri tidak dihitung; baris ditolak (status 2) membebaskan tanggalnya.
        $this->engine()->ubah($this->pegawai, $this->nip, 'pendidikan', $id, ['tgl_lulus' => '2012-08-30', 'institusi_pendidikan' => 'Kampus']);
        $this->engine()->proses($this->admin, $this->nip, 'pendidikan', $id, 'tolak', 'Ijazah tidak terbaca');
        $this->tambah($this->pegawai, $this->dataS1());
    }

    public function testSnapshotJenjangTertinggiDanNamaMaster(): void
    {
        $s2 = $this->tambah($this->admin, $this->dataS1(['id_jenjang_pendidikan' => 7, 'tgl_lulus' => '2016-03-01']));
        $this->tambah($this->admin, $this->dataS1(['tgl_lulus' => '2020-08-30']));

        $snap = $this->snapshotUji('pegawai_pendidikan', $this->nip);
        $this->assertSame((string) $s2['id_riwayat_pendidikan'], (string) $snap['id_riwayat_pendidikan'], 'S2 mengalahkan S1 yang lebih baru');
        $this->assertSame(['S2', 'Pariwisata', 'Perhotelan', 'Politeknik Uji'], [$snap['jenjang_pendidikan_singkat'], $snap['bidang_pendidikan'], $snap['jurusan_pendidikan'], $snap['institusi_pendidikan']]);
    }

    public function testDaftarUrutStatusLaluTanggalLulus(): void
    {
        $a = $this->tambah($this->admin, $this->dataS1(['tgl_lulus' => '2016-03-01']));
        $b = $this->tambah($this->pegawai, $this->dataS1(['tgl_lulus' => '2010-03-01']));
        $c = $this->tambah($this->admin, $this->dataS1(['tgl_lulus' => '2008-03-01']));

        $ids = array_map('intval', array_column($this->engine()->daftar($this->pegawai, $this->nip, 'pendidikan'), 'id_riwayat_pendidikan'));

        $this->assertSame([(int) $b['id_riwayat_pendidikan'], (int) $c['id_riwayat_pendidikan'], (int) $a['id_riwayat_pendidikan']], $ids);
    }

    public function testRegistryProduksiMenemukanDefinisiNyata(): void
    {
        $registry = RiwayatRegistry::dariFolder(
            $this->scopeUji,
            APPPATH . 'Libraries/Kepegawaian/Riwayat/Definisi',
            'App\Libraries\Kepegawaian\Riwayat\Definisi',
        );

        // Urut urutanTab() (seri → slug): jabatan 10 (WS-2 MAKE-012), kp 20, kgb 30, pendidikan 40, keluarga 50, alamat 55, diklat 60, seminar 65,
        // tanda-jasa 70, organisasi 75, hukdis 90, lalu usulan karpeg/kariskarsu (bawaan 100) — MAKE-005..007.
        $this->assertSame(['jabatan', 'kp', 'kgb', 'pendidikan', 'keluarga', 'alamat', 'diklat', 'seminar', 'tanda-jasa', 'organisasi', 'skp', 'skp-periodik', 'ak', 'ak-siasn', 'hukdis', 'kariskarsu', 'karpeg'], array_map(static fn (RiwayatDefinisi $d): string => $d->jenis(), $registry->semua()));
        $this->assertSame('pendidikan', $registry->definisiUntukLampiran(14)?->jenis());
        $this->assertSame('kp', $registry->definisiUntukLampiran(11)?->jenis());
        $this->assertSame('jabatan', $registry->definisiUntukLampiran(9)?->jenis());

        $tabs = array_column($registry->descriptorUntuk($this->pegawai, $this->nip), null, 'jenis');
        $this->assertTrue($tabs['pendidikan']['can_create']);
        $this->assertFalse($tabs['pendidikan']['can_process']);
        $this->assertTrue($tabs['kp']['can_view']);
        $this->assertFalse($tabs['kp']['can_create']);
    }

    public function testNotifikasiPengajuan(): void
    {
        $this->tambah($this->pegawai, $this->dataS1());

        $this->assertSame(RiwayatEngine::NOTIF_DIAJUKAN, $this->notifikasiUji[0]['tipe']);
        $this->assertSame('Riwayat Pendidikan', $this->notifikasiUji[0]['label']);
    }

    /**
     * @param array<string, int|string> $override
     *
     * @return array<string, int|string>
     */
    private function dataS1(array $override = []): array
    {
        return $override + [
            'id_jenjang_pendidikan' => 6,
            'id_bidang_pendidikan'  => 1,
            'id_jurusan_pendidikan' => 10,
            'institusi_pendidikan'  => 'Politeknik Uji',
            'no_ijazah'             => 'IJZ-001',
            'tgl_lulus'             => '2012-08-30',
            'ipk'                   => '3.45',
        ];
    }

    /**
     * @param array<string, mixed>          $data
     * @param array<int, UploadedFile>|null $berkas null = Ijazah PDF
     *
     * @return array<string, mixed>
     */
    private function tambah(AuthContext $auth, array $data, ?array $berkas = null): array
    {
        return $this->engine()->tambah($auth, $this->nip, 'pendidikan', $data, $berkas ?? [Pendidikan::LAMPIRAN_IJAZAH => $this->berkasUji()]);
    }

    private function assertStatus(int $kode, Closure $aksi): void
    {
        try {
            $aksi();
        } catch (ApiException $e) {
            $this->assertSame($kode, $e->getStatusCode(), $e->getMessage());

            return;
        }

        $this->fail("Diharapkan {$kode}.");
    }

    private function engine(): RiwayatEngine
    {
        /** @var RiwayatEngine $engine */
        $engine = service('riwayatService');

        return $engine;
    }

    /**
     * @param list<string> $fields
     */
    private function assertErrors(array $fields, Closure $aksi): void
    {
        try {
            $aksi();
        } catch (ValidationException $e) {
            $this->assertSame($fields, array_keys($e->getErrors() ?? []), (string) json_encode($e->getErrors()));

            return;
        }

        $this->fail('Diharapkan 422 untuk ' . implode(', ', $fields));
    }
}
