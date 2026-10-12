<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Kgb;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Kp;
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
 * B-09 Riwayat KGB (WS-1 M3 MAKE-006): B-TC generik, snapshot `pegawai_kgb`, validasi jarak TMT KGB minimal 2 tahun
 * dari KGB/KP terakhir (DoD B-09, CR-025 SRS-FR-026, keputusan #10) dan aturan legacy `L_kgb.php` lewat Definisi nyata.
 *
 * @internal
 */
final class KgbTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatBtcTrait;
    use RiwayatUjiTrait;

    private Kgb $d;
    private string $nip;
    private AuthContext $admin;
    private AuthContext $adminSatker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->d = new Kgb();
        $this->pasangEngine(null, [$this->d, new Kp()]);
        $this->siapkanMasterKp();
        $this->nip         = $this->buatPegawai();
        $this->admin       = $this->aktor(Role::SUPER_ADMIN)[1];
        $this->adminSatker = $this->aktor(Role::ADMIN_SATKER)[1];
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
        return $this->dataKgb('2016-04-01');
    }

    protected function btcBaris(string $nip): array
    {
        return $this->dataKgb('2016-04-01');
    }

    // ---- Aturan B-09 ---------------------------------------------------

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

    public function testJarakMinimalDuaTahunDariKpAtauKgbTerakhir(): void
    {
        // DoD B-09: acuan = KP/KGB terakhir (keputusan #10).
        $this->sisipKp(3, '2020-04-01');

        $this->assertErrors(['tmtsk'], fn () => $this->tambah($this->dataKgb('2021-04-01')), 'TMT KP terakhir');
        $this->tambah($this->dataKgb('2022-04-01'));

        // KGB yang baru disimpan menjadi acuan berikutnya.
        $this->assertErrors(['tmtsk'], fn () => $this->tambah($this->dataKgb('2023-04-01')), 'TMT KGB terakhir');
        $this->tambah($this->dataKgb('2024-04-01'));

        // Acuan = pendahulu (TMT ≤ baru), jadi riwayat lama tetap bisa diisi (K-CR025-7).
        $this->tambah($this->dataKgb('2018-04-01'));
    }

    public function testPengangkatanCpnsMenjadiAcuan(): void
    {
        $this->sisipKp(1, '2010-03-01');

        $this->assertErrors(['tmtsk'], fn () => $this->tambah($this->dataKgb('2011-03-01')), 'TMT CPNS');
        $this->tambah($this->dataKgb('2012-03-01'));
    }

    public function testAcuanMengabaikanBarisDitolakDanDihapus(): void
    {
        $this->sisipKp(3, '2020-04-01', 2);
        $this->sisipRiwayat($this->d, $this->nip, $this->dataKgb('2020-10-01') + ['status' => 10]);

        $this->assertSame('2021-04-01', $this->tambah($this->dataKgb('2021-04-01'))['tmtsk']);
    }

    public function testTmtKembarDitolak(): void
    {
        $this->tambah($this->dataKgb('2016-04-01'));

        $this->assertErrors(['tmtsk'], fn () => $this->tambah($this->dataKgb('2016-04-01')), 'sudah terdaftar');
    }

    public function testTmtDataLamaTidakDivalidasiUlangBilaTidakBerubah(): void
    {
        // K-CR025-10: data impor berjarak < 2 tahun tetap bisa diubah kolom lain; mengubah TMT-nya divalidasi.
        $this->sisipKp(3, '2020-04-01');
        $id = $this->sisipRiwayat($this->d, $this->nip, $this->dataKgb('2021-01-01') + ['status' => 1]);
        $this->lampiranUji->simpan($this->nip, Kgb::LAMPIRAN_SK_KGB, $id, $this->berkasUji('sk.pdf'), $this->d->lampiran()[0]);

        $this->assertSame('SK-REVISI', $this->engine()->ubah($this->admin, $this->nip, 'kgb', $id, ['no_sk' => 'SK-REVISI'])['no_sk']);
        $this->assertSame('SK-REVISI', $this->engine()->ubah($this->admin, $this->nip, 'kgb', $id, ['tmtsk' => '2021-01-01'])['no_sk']);
        $this->assertErrors(['tmtsk'], fn () => $this->engine()->ubah($this->admin, $this->nip, 'kgb', $id, ['tmtsk' => '2021-02-01']), 'minimal 2 tahun');
    }

    public function testSkKgbWajib(): void
    {
        $this->assertErrors(['berkas.10'], fn () => $this->engine()->tambah($this->admin, $this->nip, 'kgb', $this->dataKgb('2016-04-01')));

        // Ubah baris yang belum punya SK (mis. data impor) wajib menyertakan SK (legacy vp() :481-489).
        $id = $this->sisipRiwayat($this->d, $this->nip, $this->dataKgb('2016-04-01') + ['status' => 1]);
        $this->assertErrors(['berkas.10'], fn () => $this->engine()->ubah($this->admin, $this->nip, 'kgb', $id, ['no_sk' => 'SK-2']));
        $this->assertSame('SK-2', $this->engine()->ubah($this->admin, $this->nip, 'kgb', $id, ['no_sk' => 'SK-2'], [Kgb::LAMPIRAN_SK_KGB => $this->berkasUji('sk.pdf')])['no_sk']);
    }

    public function testFieldWajibLegacy(): void
    {
        try {
            $this->tambah(['tmtsk' => '2016-04-01']);
            $this->fail('422 diharapkan');
        } catch (ValidationException $e) {
            $this->assertSame(['no_sk', 'tgl_sk', 'gaji_pokok', 'jym'], array_keys($e->getErrors() ?? []));
        }
    }

    public function testSnapshotTmtTerbaruDanMasaKerjaNolMenjadiNull(): void
    {
        $lama = $this->tambah($this->dataKgb('2016-04-01'));
        $baru = $this->tambah(['mker_gol_th' => 0] + $this->dataKgb('2018-04-01'));

        $this->assertNull($baru['mker_gol_th'], 'legacy sp(): masa kerja 0 = NULL');

        $snap = $this->snapshotUji('pegawai_kgb', $this->nip);
        $this->assertSame((string) $baru['id_riwayat_kgb'], (string) $snap['id_riwayat_kgb']);
        $this->assertSame(['SK/2018-04-01', '3100000', 'Kepala Biro SDM'], [$snap['no_sk'], (string) $snap['gaji_pokok'], $snap['jym']]);

        // Hapus baris aktif → snapshot jatuh ke KGB sebelumnya.
        $this->engine()->hapus($this->admin, $this->nip, 'kgb', (int) $baru['id_riwayat_kgb']);
        $this->assertSame((string) $lama['id_riwayat_kgb'], (string) $this->snapshotUji('pegawai_kgb', $this->nip)['id_riwayat_kgb']);
    }

    public function testRole1MemilihStatusRole3Menjadi1(): void
    {
        // Legacy kgb/form.php:59-74: role 1 memilih status saat edit; role 3 → 1 (Kgb.php edit).
        $id = (int) $this->tambah($this->dataKgb('2016-04-01'))['id_riwayat_kgb'];

        // Memilih 2 Ditolak mewajibkan reason_note (engine-v1 MAKE-005).
        $this->assertErrors(['reason_note'], fn () => $this->engine()->ubah($this->admin, $this->nip, 'kgb', $id, ['status' => '2']));
        $this->assertSame('2', (string) $this->engine()->ubah($this->admin, $this->nip, 'kgb', $id, ['status' => '2', 'reason_note' => 'SK keliru'])['status']);
        $this->assertNull($this->snapshotUji('pegawai_kgb', $this->nip));

        $this->assertSame('1', (string) $this->engine()->ubah($this->adminSatker, $this->nip, 'kgb', $id, ['status' => '2'])['status']);
        $this->assertNotNull($this->snapshotUji('pegawai_kgb', $this->nip));
    }

    public function testMengaktifkanKembaliBarisDitolakDiperiksaDuplikatNilaiAkhir(): void
    {
        // Pola Kp (MAKE-005): PUT parsial tanpa tmtsk tetap memeriksa duplikat atas nilai akhir.
        $this->tambah($this->dataKgb('2016-04-01'));
        $ditolak = $this->sisipRiwayat($this->d, $this->nip, $this->dataKgb('2016-04-01') + ['status' => 2]);
        $this->lampiranUji->simpan($this->nip, Kgb::LAMPIRAN_SK_KGB, $ditolak, $this->berkasUji('sk.pdf'), $this->d->lampiran()[0]);

        $this->assertErrors(['tmtsk'], fn () => $this->engine()->ubah($this->admin, $this->nip, 'kgb', $ditolak, ['status' => '1']), 'sudah terdaftar');
    }

    public function testAdminSatkerTidakBisaMenghapusBarisDisetujui(): void
    {
        $id = (int) $this->tambah($this->dataKgb('2016-04-01'))['id_riwayat_kgb'];

        try {
            $this->engine()->hapus($this->adminSatker, $this->nip, 'kgb', $id);
            $this->fail('403 diharapkan');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->engine()->hapus($this->admin, $this->nip, 'kgb', $id);
        $this->assertSame([], $this->engine()->daftar($this->admin, $this->nip, 'kgb'));
    }

    public function testDaftarUrutTmt(): void
    {
        $a = (int) $this->tambah($this->dataKgb('2020-04-01'))['id_riwayat_kgb'];
        $b = $this->sisipRiwayat($this->d, $this->nip, $this->dataKgb('2022-04-01') + ['status' => 0]);
        $c = (int) $this->tambah($this->dataKgb('2016-04-01'))['id_riwayat_kgb'];

        $ids = array_map('intval', array_column($this->engine()->daftar($this->admin, $this->nip, 'kgb'), 'id_riwayat_kgb'));

        $this->assertSame([$c, $a, $b], $ids);
    }

    /**
     * @return array<string, int|string>
     */
    private function dataKgb(string $tmt): array
    {
        return [
            'tmtsk'       => $tmt,
            'no_sk'       => 'SK/' . $tmt,
            'tgl_sk'      => $tmt,
            'mker_gol_th' => 8,
            'gaji_pokok'  => 3100000,
            'jym'         => 'Kepala Biro SDM',
        ];
    }

    private function sisipKp(int $idJenisKp, string $tmt, int $status = 1): void
    {
        $this->sisipRiwayat(new Kp(), $this->nip, $this->barisKp($idJenisKp, $tmt) + ['status' => $status]);
    }

    /**
     * @param array<string, mixed>          $data
     * @param array<int, UploadedFile>|null $berkas null = SK KGB PDF
     *
     * @return array<string, mixed>
     */
    private function tambah(array $data, ?array $berkas = null): array
    {
        return $this->engine()->tambah($this->admin, $this->nip, 'kgb', $data, $berkas ?? [Kgb::LAMPIRAN_SK_KGB => $this->berkasUji('sk.pdf')]);
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
    private function assertErrors(array $fields, Closure $aksi, ?string $pesan = null): void
    {
        try {
            $aksi();
        } catch (ValidationException $e) {
            $errors = $e->getErrors() ?? [];
            $this->assertSame($fields, array_keys($errors), (string) json_encode($errors));

            if ($pesan !== null) {
                $this->assertStringContainsString($pesan, (string) ($errors[$fields[0]][0] ?? ''));
            }

            return;
        }

        $this->fail('Diharapkan 422 untuk ' . implode(', ', $fields));
    }
}
