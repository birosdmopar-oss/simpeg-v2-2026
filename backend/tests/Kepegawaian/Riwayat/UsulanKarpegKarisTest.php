<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Kariskarsu;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Karpeg;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Keluarga;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use Closure;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;
use ZipArchive;

/**
 * B-17 Usulan Karpeg / Karis-Karsu (WS-1 M4 MAKE-007): alur usulan mandiri (pemilik role 2/7 mengajukan, role 1
 * memproses, antrean role 1/4/5 tanpa lingkup), berkas kolom `file_*` lewat hook engine `kolomBerkas()` + KolomBerkas
 * (termasuk docx/xlsx dari isi berkas), unduh berkas kolom, syarat keluarga Karis/Karsu, dan bukan tab Detail Pegawai.
 *
 * @internal
 */
final class UsulanKarpegKarisTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatUjiTrait;

    private string $nip;
    private AuthContext $pegawai;
    private AuthContext $admin;

    /**
     * @var array<string, mixed>
     */
    private array $akunPegawai;

    /**
     * @var array<string, mixed>
     */
    private array $akunAdmin;

    private LocalStorageAdapter $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pasangEngineNyata([new Karpeg(), new Kariskarsu(), new Keluarga()]);
        $this->storage = new LocalStorageAdapter((string) $this->akarLampiranRiwayatUji);
        Services::injectMock('storageAdapter', $this->storage);

        [$this->nip, $this->pegawai, $this->akunPegawai] = $this->aktor(Role::PEGAWAI);
        [, $this->admin, $this->akunAdmin]               = $this->aktor(Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();

        parent::tearDown();
    }

    public function testIzinKeputusanUser(): void
    {
        foreach ([new Karpeg(), new Kariskarsu()] as $d) {
            foreach (Role::all() as $role) {
                $this->assertSame(in_array($role, [1, 2, 4, 5, 7], true), $d->boleh(AksiRiwayat::Lihat, $role), "lihat {$role}");
                $this->assertSame(in_array($role, [2, 7], true), $d->boleh(AksiRiwayat::Tambah, $role), "tambah {$role}");
                $this->assertSame($role === Role::SUPER_ADMIN, $d->boleh(AksiRiwayat::Proses, $role), "proses {$role}");
            }
        }
    }

    public function testAjukanBerkasKolomWajibDanOffice(): void
    {
        $this->assertErrors(['berkas.file_3'], fn () => $this->ajukan(['file_3' => null]));
        $this->assertErrors(['berkas.file_surat_hilang'], fn () => $this->ajukan([], Karpeg::KEHILANGAN));
        $this->assertErrors(['berkas.file_1'], fn () => $this->ajukan(['file_1' => $this->berkasUji('sk.txt', false)]));
        $this->assertErrors(['berkas.file_9'], fn () => $this->ajukan(['file_9' => $this->berkasUji()]));

        $row = $this->ajukan(['file_1' => $this->office('sk.docx', 'word'), 'file_2' => $this->office('sk.xlsx', 'xl')]);

        $this->assertSame('0', (string) $row['status']);
        $this->assertStringEndsWith('.docx', (string) $row['file_1']);
        $this->assertStringEndsWith('.xlsx', (string) $row['file_2']);
        $this->assertTrue($this->storage->ada((string) $row['file_5']));
    }

    public function testUbahSaatDitolakMengajukanUlangDanMenggantiBerkas(): void
    {
        $row = $this->ajukan();
        $id  = (int) $row['id_riwayat_karpeg'];
        $this->engine()->proses($this->admin, $this->nip, 'karpeg', $id, 'tolak', 'Pas foto buram');

        $baru = $this->engine()->ubah($this->pegawai, $this->nip, 'karpeg', $id, [], ['file_5' => $this->berkasUji('foto.pdf')]);

        $this->assertSame('0', (string) $baru['status'], 'ditolak → diajukan ulang');
        $this->assertNotSame($row['file_5'], $baru['file_5']);
        $this->assertFalse($this->storage->ada((string) $row['file_5']), 'berkas lama dihapus setelah commit');
        $this->assertTrue($this->storage->ada((string) $baru['file_5']));

        // Disetujui → terkunci untuk pemilik (ubah & hapus).
        $this->engine()->proses($this->admin, $this->nip, 'karpeg', $id, 'setujui', null);
        $this->assertStatusApi(403, fn () => $this->engine()->ubah($this->pegawai, $this->nip, 'karpeg', $id, ['jenis_permohonan' => Karpeg::PERTAMA]));
        $this->assertStatusApi(403, fn () => $this->engine()->hapus($this->pegawai, $this->nip, 'karpeg', $id));
    }

    public function testHapusStatus10DanProsesHanyaRole1(): void
    {
        $id          = (int) $this->ajukan()['id_riwayat_karpeg'];
        [, $eselon1] = $this->aktor(Role::ADMIN_VIEW_ESELON1);

        $this->assertStatusApi(403, fn () => $this->engine()->proses($eselon1, $this->nip, 'karpeg', $id, 'setujui', null));
        $this->assertStatusApi(403, fn () => $this->engine()->tambah($this->admin, $this->nip, 'karpeg', ['jenis_permohonan' => Karpeg::PERTAMA], $this->berkasLengkap()));

        $this->engine()->hapus($this->pegawai, $this->nip, 'karpeg', $id);
        $this->assertSame('10', (string) $this->db->table('riwayat_karpeg')->where('id_riwayat_karpeg', $id)->get()->getRow()->status);
    }

    public function testAntreanSayaDanUnduhBerkas(): void
    {
        $id = (int) $this->ajukan()['id_riwayat_karpeg'];

        $this->get('api/v1/usulan/karpeg')->assertStatus(401);
        $this->asUser($this->akunPegawai)->get('api/v1/usulan/karpeg')->assertStatus(403);
        $this->asUser($this->akunAdmin)->get('api/v1/usulan/karpeg/saya')->assertStatus(403);

        $antrean = $this->json($this->asUser($this->akunAdmin)->get('api/v1/usulan/karpeg'))['data'];
        $this->assertTrue($antrean['can_process']);
        $this->assertSame([$id], array_map('intval', array_column($antrean['items'], 'id_riwayat_karpeg')));
        $this->assertArrayHasKey('nama', $antrean['items'][0]);
        $this->assertSame([], $this->json($this->asUser($this->akunAdmin)->get('api/v1/usulan/karpeg?status=1'))['data']['items']);
        $this->asUser($this->akunAdmin)->get('api/v1/usulan/karpeg?status=10')->assertStatus(422);

        [, , $akunEselon1] = $this->aktor(Role::ADMIN_VIEW_ESELON1);
        $this->assertFalse($this->json($this->asUser($akunEselon1)->get('api/v1/usulan/karpeg'))['data']['can_process']);

        $saya = $this->json($this->asUser($this->akunPegawai)->get('api/v1/usulan/karpeg/saya'))['data'];
        $this->assertSame([$this->nip, true, true], [$saya['pegawai']['nip'], $saya['can_create'], $saya['syarat_terpenuhi']]);
        $this->assertCount(1, $saya['items']);

        $unduh = $this->asUser($this->akunAdmin)->get("api/v1/pegawai/{$this->nip}/riwayat/karpeg/{$id}/berkas/file_1");
        $unduh->assertStatus(200);
        $this->assertStringStartsWith('%PDF', (string) $unduh->response()->getBody());
        $this->asUser($this->akunAdmin)->get("api/v1/pegawai/{$this->nip}/riwayat/karpeg/{$id}/berkas/status")->assertStatus(404);
        $this->asUser($this->akunAdmin)->get("api/v1/pegawai/{$this->nip}/riwayat/karpeg/{$id}/berkas/file_surat_hilang")->assertStatus(404);

        [, , $akunLain] = $this->aktor(Role::PEGAWAI);
        $this->asUser($akunLain)->get("api/v1/pegawai/{$this->nip}/riwayat/karpeg/{$id}/berkas/file_1")->assertStatus(403);
    }

    public function testKariskarsuMensyaratkanKeluargaDanBerkasOpsional(): void
    {
        $saya = $this->json($this->asUser($this->akunPegawai)->get('api/v1/usulan/kariskarsu/saya'))['data'];
        $this->assertFalse($saya['syarat_terpenuhi']);
        $this->assertErrors(['keluarga'], fn () => $this->engine()->tambah($this->pegawai, $this->nip, 'kariskarsu', [], $this->berkasLengkap()));

        $this->sisipRiwayat(new Keluarga(), $this->nip, ['urutan_perkawinan' => 1, 'tgl_perkawinan' => '2015-05-05', 'nama_pasangan' => 'Pasangan', 'status' => 1]);
        $row = $this->engine()->tambah($this->pegawai, $this->nip, 'kariskarsu', [], $this->berkasLengkap());

        $this->assertSame([null, null, '0'], [$row['file_surat_hilang'], $row['file_alasan'], (string) $row['status']]);
    }

    public function testUsulanBukanTab(): void
    {
        $this->assertSame(['keluarga'], array_column(service('riwayatRegistry')->descriptorUntuk($this->admin, $this->nip), 'jenis'));
    }

    public function testUnduhBerkasBarisDihapusDanPathLegacy404(): void
    {
        $id = (int) $this->ajukan()['id_riwayat_karpeg'];
        $this->engine()->hapus($this->pegawai, $this->nip, 'karpeg', $id);
        $this->asUser($this->akunAdmin)->get("api/v1/pegawai/{$this->nip}/riwayat/karpeg/{$id}/berkas/file_1")->assertStatus(404);

        // Data impor legacy: path arsip remote (diawali '/', berspasi) → 404, bukan 500 (isu cutover).
        $legacy = $this->sisipRiwayat(new Karpeg(), $this->nip, [
            'jenis_permohonan' => Karpeg::PERTAMA, 'file_1' => '/arsip/' . $this->nip . '/karpeg/sk cpns.pdf', 'file_2' => 'x', 'file_3' => 'x',
            'file_4'           => 'x', 'file_5' => 'x', 'status' => 0,
        ]);
        $this->asUser($this->akunAdmin)->get("api/v1/pegawai/{$this->nip}/riwayat/karpeg/{$legacy}/berkas/file_1")->assertStatus(404);
    }

    public function testRollbackMenghapusBerkasKolomBaru(): void
    {
        // Definisi uji: pola Karpeg, tetapi tambah langsung Disetujui dan afterApprove gagal → transaksi engine rollback.
        $gagal = new class () extends RiwayatDefinisi {
            public function jenis(): string
            {
                return 'karpeg';
            }

            public function label(): string
            {
                return 'Karpeg Uji Gagal';
            }

            public function tabel(): string
            {
                return 'riwayat_karpeg';
            }

            public function primaryKey(): string
            {
                return 'id_riwayat_karpeg';
            }

            public function fields(): array
            {
                return (new Karpeg())->fields();
            }

            public function izin(): array
            {
                return ['tambah' => [Role::PEGAWAI]];
            }

            public function alur(): AlurRiwayat
            {
                return AlurRiwayat::Admin;
            }

            public function pemetaanStatus(): array
            {
                return Karpeg::statusTanpaDiproses();
            }

            public function kolomBerkas(): array
            {
                return (new Karpeg())->kolomBerkas();
            }

            public function afterApprove(array $baris, AuthContext $auth): void
            {
                throw new RuntimeException('gagal sesudah tulis');
            }
        };
        $this->lepasEngine();
        $this->pasangEngineNyata([$gagal]);
        $this->storage = new LocalStorageAdapter((string) $this->akarLampiranRiwayatUji);
        Services::injectMock('storageAdapter', $this->storage);

        try {
            $this->engine()->tambah($this->pegawai, $this->nip, 'karpeg', ['jenis_permohonan' => Karpeg::PERTAMA], $this->berkasLengkap());
            $this->fail('RuntimeException diharapkan');
        } catch (RuntimeException $e) {
            $this->assertSame('gagal sesudah tulis', $e->getMessage());
        }

        $this->assertSame(0, $this->db->table('riwayat_karpeg')->where('nip', $this->nip)->countAllResults());
        $this->assertSame([], $this->berkasDiStorage(), 'berkas baru dihapus setelah rollback');
    }

    /**
     * @return list<string>
     */
    private function berkasDiStorage(): array
    {
        $akar = (string) $this->akarLampiranRiwayatUji;

        if (! is_dir($akar)) {
            return [];
        }

        $hasil = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($akar, FilesystemIterator::SKIP_DOTS)) as $berkas) {
            $hasil[] = (string) $berkas;
        }

        return $hasil;
    }

    /**
     * @param array<string, UploadedFile|null> $ganti
     *
     * @return array<string, mixed>
     */
    private function ajukan(array $ganti = [], string $jenisPermohonan = Karpeg::PERTAMA): array
    {
        $berkas = array_filter(array_merge($this->berkasLengkap(), $ganti), static fn (?UploadedFile $f): bool => $f !== null);

        return $this->engine()->tambah($this->pegawai, $this->nip, 'karpeg', ['jenis_permohonan' => $jenisPermohonan], $berkas);
    }

    /**
     * @return array<string, UploadedFile>
     */
    private function berkasLengkap(): array
    {
        $berkas = [];

        foreach (['file_1', 'file_2', 'file_3', 'file_4', 'file_5'] as $kolom) {
            $berkas[$kolom] = $this->berkasUji($kolom . '.pdf');
        }

        return $berkas;
    }

    /**
     * Berkas OOXML minimal yang dikenali libmagic dari isinya ([Content_Types].xml tersimpan, _rels, folder `word`/`xl`).
     */
    private function office(string $nama, string $folder): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ooxml');
        $zip  = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
        $zip->setCompressionName('[Content_Types].xml', ZipArchive::CM_STORE);
        $zip->addFromString('_rels/.rels', '<Relationships/>');
        $zip->addFromString($folder . '/document.xml', '<x/>');
        $zip->close();

        return new UploadedFile($path, $nama, 'application/octet-stream', (int) filesize($path), UPLOAD_ERR_OK);
    }

    private function engine(): RiwayatEngine
    {
        /** @var RiwayatEngine $engine */
        $engine = service('riwayatService');

        return $engine;
    }

    private function assertStatusApi(int $status, Closure $aksi): void
    {
        try {
            $aksi();
        } catch (ApiException $e) {
            $this->assertSame($status, $e->getStatusCode(), $e->getMessage());

            return;
        }

        $this->fail("Diharapkan {$status}");
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
