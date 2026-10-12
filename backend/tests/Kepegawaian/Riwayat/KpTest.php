<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Kp;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Pendidikan;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use Closure;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatBtcTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-08 Riwayat Kenaikan Pangkat (WS-1 M2 MAKE-005): B-TC generik, snapshot multi-target kp/cpns/pns, validasi TMT
 * periode (CR-025, DoD B-08) dan aturan legacy `L_kp.php` lewat Definisi nyata. PegawaiScope +
 * AttachmentService/LocalStorageAdapter nyata (MAKE-009), lewat RiwayatUjiTrait::pasangEngineNyata().
 *
 * @internal
 */
final class KpTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatBtcTrait;
    use RiwayatUjiTrait;

    private Kp $d;
    private string $nip;
    private AuthContext $admin;
    private AuthContext $adminSatker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->d = new Kp();
        $this->pasangEngineNyata([$this->d, new Pendidikan()]);
        $this->siapkanMasterKp();
        // Admin satker di satker yang sama dengan pegawai (PegawaiScope nyata: lingkup = satker akun).
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

    // ---- B-TC ----------------------------------------------------------

    protected function btcDefinisi(): RiwayatDefinisi
    {
        return $this->d;
    }

    protected function btcPayload(string $nip): array
    {
        return $this->dataKpNyata(3, '2015-04-01');
    }

    protected function btcBaris(string $nip): array
    {
        return $this->dataKpNyata(3, '2015-04-01') + ['jenis_kp' => 'Reguler', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda'];
    }

    /**
     * Scope produksi (PegawaiScope MAKE-009) yang sudah terpasang.
     */
    protected function btcScopeRole3(array $nipDalam): null
    {
        return null;
    }

    // ---- Aturan B-08 ---------------------------------------------------

    public function testIzinMatriksV2(): void
    {
        foreach (Role::all() as $role) {
            $admin = in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true);
            $lihat = $admin || in_array($role, [Role::PEGAWAI, Role::ADMIN_VIEW_ESELON1, Role::MENTERI], true);

            $this->assertSame($lihat, $this->d->boleh(AksiRiwayat::Lihat, $role), "lihat {$role}");

            foreach ([AksiRiwayat::Tambah, AksiRiwayat::Ubah, AksiRiwayat::Hapus, AksiRiwayat::Proses] as $aksi) {
                $this->assertSame($admin, $this->d->boleh($aksi, $role), "{$aksi->value} {$role}");
            }
        }
    }

    public function testTmtKpWajibPeriodeAprilOktober(): void
    {
        // DoD B-08 / SRS-FR-025: KP reguler bertanggal selain 1 April / 1 Oktober ditolak, dengan periode terdekat.
        try {
            $this->tambah($this->dataKpNyata(3, '2024-05-01'));
            $this->fail('422 diharapkan');
        } catch (ValidationException $e) {
            $this->assertSame(['tmtsk'], array_keys($e->getErrors() ?? []));
            $this->assertStringContainsString('2024', (string) ($e->getErrors()['tmtsk'][0] ?? ''));
        }

        $this->tambah($this->dataKpNyata(3, '2024-10-01'));
        // Pengangkatan CPNS/PNS bebas tanggal (JenisKp::wajibTmtPeriode).
        $this->tambah($this->dataKpNyata(1, '2010-03-15'));
    }

    public function testTmtDataLamaTidakDivalidasiUlangBilaTidakBerubah(): void
    {
        // K-CR025-10: impor legacy ber-TMT non-periode tetap bisa diubah kolom lain; mengubah TMT ke non-periode ditolak.
        $id = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['tmtsk' => '2014-05-17', 'status' => 1]));
        // Legacy: ubah tanpa berkas hanya boleh bila baris sudah punya lampiran SK (validate_param() :500-505).
        $this->lampiranUji->simpan($this->nip, Kp::LAMPIRAN_SK_KP, $id, $this->berkasUji('sk.pdf'), $this->d->lampiran()[0]);

        $row = $this->engine()->ubah($this->admin, $this->nip, 'kp', $id, ['no_sk' => 'SK-REVISI']);
        $this->assertSame('SK-REVISI', $row['no_sk']);

        $this->assertErrors(['tmtsk'], fn () => $this->engine()->ubah($this->admin, $this->nip, 'kp', $id, ['tmtsk' => '2014-06-01']));

        // Mengganti jenis dari pengangkatan (bebas tanggal) ke reguler memeriksa ulang TMT yang ada.
        $cpns = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['id_jenis_kp' => 1, 'tmtsk' => '2010-03-15', 'status' => 1]));
        $this->assertErrors(['tmtsk'], fn () => $this->engine()->ubah($this->admin, $this->nip, 'kp', $cpns, ['id_jenis_kp' => 3]));
    }

    public function testPengangkatanCpnsPnsHanyaSekaliDanTmtTidakKembar(): void
    {
        $cpns = $this->tambah($this->dataKpNyata(1, '2010-03-01'));

        $this->assertErrors(['id_jenis_kp'], fn () => $this->tambah($this->dataKpNyata(1, '2011-03-01')));
        $this->assertErrors(['tmtsk'], fn () => $this->tambah($this->dataKpNyata(2, '2010-03-01')));

        // Baris ditolak/dihapus tidak dihitung (legacy status NOT IN (2, 10)).
        $this->engine()->hapus($this->admin, $this->nip, 'kp', (int) $cpns['id_riwayat_kp']);
        $this->tambah($this->dataKpNyata(1, '2010-03-01'));
    }

    public function testSnapshotMultiTargetDanNamaMaster(): void
    {
        $cpns = $this->tambah($this->dataKpNyata(1, '2010-03-01'));
        $pns  = $this->tambah($this->dataKpNyata(2, '2011-04-01'));
        $reg  = $this->tambah($this->dataKpNyata(3, '2015-04-01'));

        $this->assertSame((string) $reg['id_riwayat_kp'], (string) $this->snapshotUji('pegawai_kp', $this->nip)['id_riwayat_kp']);
        $this->assertSame((string) $cpns['id_riwayat_kp'], (string) $this->snapshotUji('pegawai_cpns', $this->nip)['id_riwayat_kp']);
        $this->assertSame((string) $pns['id_riwayat_kp'], (string) $this->snapshotUji('pegawai_pns', $this->nip)['id_riwayat_kp']);

        $snap = $this->snapshotUji('pegawai_kp', $this->nip);
        $this->assertSame(['Reguler', 'III', 'a', 'III/a', 'Penata Muda', 'SK/2015-04-01'], [$snap['jenis_kp'], $snap['gol'], $snap['ruang'], $snap['gol_ruang'], $snap['pangkat'], $snap['no_sk']]);
        $this->assertSame(['8', '3', '2900000'], [(string) $snap['mker_th'], (string) $snap['mker_bl'], (string) $snap['gaji_pokok']]);
    }

    public function testSkKpWajib(): void
    {
        $this->assertErrors(['berkas.11'], fn () => $this->engine()->tambah($this->admin, $this->nip, 'kp', $this->dataKpNyata(3, '2015-04-01')));

        // Ubah baris yang belum punya SK (mis. data impor) wajib menyertakan SK (legacy validate_param() :500-505).
        $id = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['status' => 1]));
        $this->assertErrors(['berkas.11'], fn () => $this->engine()->ubah($this->admin, $this->nip, 'kp', $id, ['no_sk' => 'SK-2']));
        $this->assertSame('SK-2', $this->engine()->ubah($this->admin, $this->nip, 'kp', $id, ['no_sk' => 'SK-2'], [Kp::LAMPIRAN_SK_KP => $this->berkasUji('sk.pdf')])['no_sk']);
    }

    public function testFieldWajibLegacy(): void
    {
        try {
            $this->tambah(['id_jenis_kp' => 3, 'id_pangkat' => $this->idPangkatUji, 'tmtsk' => '2015-04-01']);
            $this->fail('422 diharapkan');
        } catch (ValidationException $e) {
            $this->assertSame(['no_sk', 'tgl_sk', 'gaji_pokok', 'mker_th', 'mker_bl'], array_keys($e->getErrors() ?? []));
        }
    }

    public function testAdminSatkerTidakBisaMenghapusBarisDisetujui(): void
    {
        $id = (int) $this->tambah($this->dataKpNyata(3, '2015-04-01'))['id_riwayat_kp'];

        try {
            $this->engine()->hapus($this->adminSatker, $this->nip, 'kp', $id);
            $this->fail('403 diharapkan');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Role 3 tetap boleh mengubahnya (legacy Kp.php edit 1, 3).
        $this->assertSame('SK-X', $this->engine()->ubah($this->adminSatker, $this->nip, 'kp', $id, ['no_sk' => 'SK-X'])['no_sk']);
        $this->assertSame('SK-X', $this->snapshotUji('pegawai_kp', $this->nip)['no_sk']);
    }

    public function testRole1MemilihStatusSaatUbah(): void
    {
        // Legacy kp/form.php:59-74 (keputusan user 09-10-2026): role 1 memilih status saat edit.
        $id = (int) $this->tambah($this->dataKpNyata(3, '2015-04-01'))['id_riwayat_kp'];

        $row = $this->engine()->ubah($this->admin, $this->nip, 'kp', $id, ['status' => '2']);
        $this->assertSame('2', (string) $row['status']);
        $this->assertNull($this->snapshotUji('pegawai_kp', $this->nip), 'keluar dari status 1 → snapshot dihitung ulang');

        $row = $this->engine()->ubah($this->admin, $this->nip, 'kp', $id, ['status' => 0]);
        $this->assertSame('0', (string) $row['status']);

        $row = $this->engine()->ubah($this->admin, $this->nip, 'kp', $id, ['status' => '1', 'no_sk' => 'SK-OK']);
        $this->assertSame('1', (string) $row['status']);
        $this->assertSame('1', (string) $row['show_notif'], 'transisi 0 → 1 menandai notifikasi');
        $this->assertSame('SK-OK', $this->snapshotUji('pegawai_kp', $this->nip)['no_sk']);

        // Tanpa field status: tidak berubah.
        $this->assertSame('1', (string) $this->engine()->ubah($this->admin, $this->nip, 'kp', $id, ['no_sk' => 'SK-2'])['status']);

        // 10, nilai asing, dan 3 saat flag Diproses nonaktif → 422.
        foreach (['10', '9', '3', 'x'] as $nilai) {
            $this->assertErrors(['status'], fn () => $this->engine()->ubah($this->admin, $this->nip, 'kp', $id, ['status' => $nilai]));
        }
    }

    public function testRole3TidakMemilihStatus(): void
    {
        // Role 3 mengikuti statusSetelahUbah() (→ 1); field status dari klien diabaikan.
        $id = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['status' => 0]));
        $this->lampiranUji->simpan($this->nip, Kp::LAMPIRAN_SK_KP, $id, $this->berkasUji('sk.pdf'), $this->d->lampiran()[0]);

        $this->assertSame('1', (string) $this->engine()->ubah($this->adminSatker, $this->nip, 'kp', $id, ['status' => '2'])['status']);
    }

    public function testDaftarUrutStatusLaluTmt(): void
    {
        $a = $this->tambah($this->dataKpNyata(3, '2019-04-01'));
        $b = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['tmtsk' => '2023-04-01', 'status' => 0]));
        $c = $this->tambah($this->dataKpNyata(3, '2015-04-01'));

        $ids = array_map('intval', array_column($this->engine()->daftar($this->admin, $this->nip, 'kp'), 'id_riwayat_kp'));

        $this->assertSame([$b, (int) $c['id_riwayat_kp'], (int) $a['id_riwayat_kp']], $ids);
    }

    /**
     * @return array<string, int|string>
     */
    private function dataKpNyata(int $idJenisKp, string $tmt): array
    {
        return [
            'id_jenis_kp' => $idJenisKp,
            'id_pangkat'  => $this->idPangkatUji,
            'tmtsk'       => $tmt,
            'tgl_sk'      => $tmt,
            'no_sk'       => 'SK/' . $tmt,
            'gaji_pokok'  => 2900000,
            'mker_th'     => 8,
            'mker_bl'     => 3,
        ];
    }

    /**
     * @param array<string, mixed>          $data
     * @param array<int, UploadedFile>|null $berkas null = SK KP PDF
     *
     * @return array<string, mixed>
     */
    private function tambah(array $data, ?array $berkas = null): array
    {
        return $this->engine()->tambah($this->admin, $this->nip, 'kp', $data, $berkas ?? [Kp::LAMPIRAN_SK_KP => $this->berkasUji('sk.pdf')]);
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
