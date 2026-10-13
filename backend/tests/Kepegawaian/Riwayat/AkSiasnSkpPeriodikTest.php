<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Ak;
use App\Libraries\Kepegawaian\Riwayat\Definisi\AkSiasn;
use App\Libraries\Kepegawaian\Riwayat\Definisi\SkpPeriodik;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use Closure;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * Jenis hasil tarik SIASN/e-Kinerja (WS-1 M5 MAKE-008), keputusan user 13-10-2026 ([K] ikut legacy):
 * - AK SIASN (B-15): ubah hanya role 1 dan hanya "pairing" `id_jabatan` (jabatan JF tertentu aktif), status tetap,
 *   snapshot `pegawai_ak_siasn`; daftar status 0/1; tanpa tambah/hapus/proses.
 * - SKP periodik (B-12a): daftar hanya status 1; satu-satunya ubah = unggah arsip `f_arsip_1` (PDF 1 MB) oleh role 1,
 *   status tetap; tanpa tambah/hapus/proses.
 *
 * @internal
 */
final class AkSiasnSkpPeriodikTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatUjiTrait;

    private string $nip;
    private AuthContext $admin;
    private AuthContext $adminSatker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pasangEngineNyata([new AkSiasn(), new SkpPeriodik()]);
        Services::injectMock('storageAdapter', new LocalStorageAdapter((string) $this->akarLampiranRiwayatUji));
        $idSatker          = $this->buatSatker();
        $this->nip         = $this->buatPegawaiDiSatker($idSatker);
        $this->admin       = $this->aktor(Role::SUPER_ADMIN)[1];
        $this->adminSatker = $this->aktor(Role::ADMIN_SATKER, [], $idSatker)[1];
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();

        parent::tearDown();
    }

    public function testIzinKeputusan1310(): void
    {
        $ak  = new AkSiasn();
        $skp = new SkpPeriodik();

        foreach (Role::all() as $role) {
            $this->assertSame(in_array($role, [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::PPPK], true), $ak->boleh(AksiRiwayat::Lihat, $role), "ak-siasn lihat {$role}");
            $this->assertSame(in_array($role, [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PPPK], true), $skp->boleh(AksiRiwayat::Lihat, $role), "skp-periodik lihat {$role}");

            foreach ([$ak, $skp] as $d) {
                $this->assertSame($role === Role::SUPER_ADMIN, $d->boleh(AksiRiwayat::Ubah, $role));

                foreach ([AksiRiwayat::Tambah, AksiRiwayat::Hapus, AksiRiwayat::Proses] as $aksi) {
                    $this->assertFalse($d->boleh($aksi, $role), "{$d->jenis()} {$aksi->value} {$role}");
                }
            }
        }
    }

    public function testAkSiasnPairingJabatanJftStatusTetapDanSnapshot(): void
    {
        $jft   = $this->jabatanGroup(Ak::GROUP_JFT);
        $bukan = $this->jabatanGroup(3);
        $id    = $this->sisipAkSiasn(['status' => 1, 'tgl_sk' => '2024-01-01']);
        $this->sisipAkSiasn(['status' => 1, 'tgl_sk' => '2023-01-01']);

        $this->assertErrors(['id_jabatan'], fn () => $this->engine()->ubah($this->admin, $this->nip, 'ak-siasn', $id, ['id_jabatan' => $bukan]));
        $this->assertErrors(['id_jabatan'], fn () => $this->engine()->ubah($this->admin, $this->nip, 'ak-siasn', $id, ['id_jabatan' => '']));
        $this->assertStatusApi(403, fn () => $this->engine()->ubah($this->adminSatker, $this->nip, 'ak-siasn', $id, ['id_jabatan' => $jft]));

        $row = $this->engine()->ubah($this->admin, $this->nip, 'ak-siasn', $id, ['id_jabatan' => $jft, 'no_sk' => 'Palsu', 'status' => 2]);

        $this->assertSame([(string) $jft, 'SK/2024-01-01', '1'], [(string) $row['id_jabatan'], $row['no_sk'], (string) $row['status']]);
        $snapshot = $this->snapshotUji('pegawai_ak_siasn', $this->nip);
        $this->assertSame([(string) $id, (string) $jft], [(string) $snapshot['id_riwayat_ak_siasn'], (string) $snapshot['id_jabatan']]);
    }

    public function testAkSiasnDaftarStatus0Dan1TerbaruDulu(): void
    {
        $lama = $this->sisipAkSiasn(['status' => 1, 'tgl_sk' => '2022-01-01']);
        $baru = $this->sisipAkSiasn(['status' => 0, 'tgl_sk' => '2023-01-01']);
        $this->sisipAkSiasn(['status' => 2, 'tgl_sk' => '2024-01-01']);

        $rows = $this->engine()->daftar($this->admin, $this->nip, 'ak-siasn');

        $this->assertSame([(string) $baru, (string) $lama], array_map(static fn (array $r): string => (string) $r['id_riwayat_ak_siasn'], $rows));
        $this->assertStatusApi(403, fn () => $this->engine()->tambah($this->admin, $this->nip, 'ak-siasn', ['id_jabatan' => $this->jabatanGroup(Ak::GROUP_JFT)]));
        $this->assertStatusApi(403, fn () => $this->engine()->proses($this->admin, $this->nip, 'ak-siasn', $baru, 'setujui', null));
    }

    public function testSkpPeriodikDaftarHanyaStatus1Berurutan(): void
    {
        $akhir = $this->sisipSkpPeriodik(['tahun_skp' => 2024, 'periode_awal_skp' => '2024-07-01']);
        $awal  = $this->sisipSkpPeriodik(['tahun_skp' => 2024, 'periode_awal_skp' => '2024-01-01']);
        $this->sisipSkpPeriodik(['tahun_skp' => 2023, 'status' => 0]);
        $this->sisipSkpPeriodik(['tahun_skp' => 2023, 'status' => 2]);

        $rows = $this->engine()->daftar($this->admin, $this->nip, 'skp-periodik');

        $this->assertSame([(string) $awal, (string) $akhir], array_map(static fn (array $r): string => (string) $r['id_riwayat_skp_periodik'], $rows));
    }

    public function testSkpPeriodikUnggahArsipPdfStatusTetap(): void
    {
        $id = $this->sisipSkpPeriodik(['tahun_skp' => 2024]);

        $this->assertErrors(['berkas.f_arsip_1'], fn () => $this->engine()->ubah($this->admin, $this->nip, 'skp-periodik', $id, []));
        $this->assertErrors(['berkas.f_arsip_1'], fn () => $this->unggah($id, $this->berkasUji('skp.jpg', false)));
        $this->assertErrors(['berkas.f_arsip_1'], fn () => $this->unggah($id, $this->berkasUji('skp.pdf', true, 1024 * 1024)));
        $this->assertStatusApi(403, fn () => $this->engine()->ubah($this->adminSatker, $this->nip, 'skp-periodik', $id, [], ['f_arsip_1' => $this->berkasUji('skp.pdf')]));

        $row = $this->unggah($id, $this->berkasUji('skp.pdf'));

        $this->assertSame('1', (string) $row['status']);
        $this->assertStringEndsWith('.pdf', (string) $row['f_arsip_1']);

        // Ganti arsip: tidak wajib unggah lagi bila sudah ada, berkas baru menggantikan.
        $baru = $this->unggah($id, $this->berkasUji('skp2.pdf'));
        $this->assertNotSame($row['f_arsip_1'], $baru['f_arsip_1']);
        $this->assertStatusApi(403, fn () => $this->engine()->tambah($this->admin, $this->nip, 'skp-periodik', []));
    }

    /**
     * @return array<string, mixed>
     */
    private function unggah(int $id, UploadedFile $berkas): array
    {
        return $this->engine()->ubah($this->admin, $this->nip, 'skp-periodik', $id, [], [SkpPeriodik::KOLOM_ARSIP => $berkas]);
    }

    /**
     * Jabatan baru berkelompok $group (baris `group_jabatan` ber-id itu dibuat bila belum ada).
     */
    private function jabatanGroup(int $group): int
    {
        if ($this->db->table('group_jabatan')->where('id_group_jabatan', $group)->countAllResults() === 0) {
            $this->db->table('group_jabatan')->insert(['id_group_jabatan' => $group, 'group_jabatan' => 'Group Uji ' . $group, 'status' => 1]);
        }

        $id = $this->buatJabatan()['id_jabatan'];
        $this->db->table('jabatan')->where('id_jabatan', $id)->update(['id_group_jabatan' => $group]);

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sisipAkSiasn(array $data): int
    {
        $tgl = (string) ($data['tgl_sk'] ?? '2024-01-01');

        return $this->sisipRiwayat(new AkSiasn(), $this->nip, $data + [
            'id_rw_siasn' => 'rw-' . bin2hex(random_bytes(6)), 'no_sk' => 'SK/' . $tgl, 'kredit_baru_total' => 12.5, 'nama_jabatan' => 'Jabatan SIASN',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sisipSkpPeriodik(array $data): int
    {
        return $this->sisipRiwayat(new SkpPeriodik(), $this->nip, $data + [
            'id_pns'      => 'pns-uji', 'nama' => 'Pegawai Uji', 'periode_awal_skp' => '2024-01-01', 'periode_akhir_skp' => '2024-06-30',
            'hasil_kerja' => 'Baik', 'perilaku_kerja' => 'Baik', 'hasil_akhir' => 'Baik', 'status' => 1,
        ]);
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

    private function assertStatusApi(int $status, Closure $aksi): void
    {
        try {
            $aksi();
        } catch (ApiException $e) {
            $this->assertSame($status, $e->getStatusCode());

            return;
        }

        $this->fail("Diharapkan {$status}");
    }
}
