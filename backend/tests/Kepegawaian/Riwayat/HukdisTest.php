<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Hukdis;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use App\Models\AuditLogModel;
use Closure;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatBtcTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-14 Riwayat Hukuman Disiplin (WS-1 M3 MAKE-006): B-TC generik, snapshot `pegawai_hukdis`, tanggal berakhir dihitung
 * otomatis dari masa sanksi jenis (DoD B-14, SRS-FR-027, K5b) dan aturan legacy `L_hukdis.php` lewat Definisi nyata.
 *
 * Hukdis tidak punya alur proses (legacy, izin `proses` kosong) dan status selalu 1, jadi butir B-TC approval/tolak
 * ditimpa: proses → 403, tambah langsung Disetujui dan mengisi snapshot.
 *
 * @internal
 */
final class HukdisTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatBtcTrait;
    use RiwayatUjiTrait;

    private const TINGKAT_RINGAN   = 1;
    private const TINGKAT_SEDANG   = 2;
    private const JENIS_TEGURAN    = 1;
    private const JENIS_TANPA_MASA = 2;
    private const JENIS_PENUNDAAN  = 3;

    private Hukdis $d;
    private string $nip;
    private AuthContext $admin;
    private AuthContext $adminSatker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->d = new Hukdis();
        $this->pasangEngineNyata([$this->d]);
        $this->siapkanMasterKp();
        $this->siapkanMasterHukdis();
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
        return $this->dataHukdis('2024-01-01');
    }

    protected function btcBaris(string $nip): array
    {
        return $this->dataHukdis('2024-01-01') + ['tingkat_hukdis' => 'Ringan', 'jenis_hukdis' => 'Teguran Tertulis'];
    }

    /**
     * Scope produksi (PegawaiScope MAKE-009) yang sudah terpasang (pola KpTest).
     */
    protected function btcScopeRole3(array $nipDalam): null
    {
        return null;
    }

    /**
     * Pengganti B-TC #2/#3: tanpa proses (legacy) — role 1/3 menambah langsung berstatus 1 dan snapshot terisi;
     * proses dijawab 403.
     */
    public function testBtcApprovalRole1DanRole3SnapshotSetelahApprovalFinal(): void
    {
        $nip = $this->nip;

        foreach ([$this->admin, $this->adminSatker] as $i => $auth) {
            $this->assertFalse($this->d->boleh(AksiRiwayat::Proses, $auth->role()), 'legacy: hukdis tanpa proses');

            $row = $this->engine()->tambah($auth, $nip, 'hukdis', $this->dataHukdis($i === 0 ? '2020-01-01' : '2024-01-01'), $this->berkasSk());

            $this->assertSame('1', (string) $row['status']);
            $this->assertSame((string) $row['id_riwayat_hukdis'], (string) $this->snapshotUji('pegawai_hukdis', $nip)['id_riwayat_hukdis']);
            $this->assertStatusApi(403, fn () => $this->engine()->proses($auth, $nip, 'hukdis', (int) $row['id_riwayat_hukdis'], 'setujui', null));
        }
    }

    public function testBtcTolakStatus2SnapshotTidakTersentuh(): void
    {
        $id = $this->sisipRiwayat($this->d, $this->nip, $this->btcBaris($this->nip) + ['status' => 0]);

        $this->assertStatusApi(403, fn () => $this->engine()->proses($this->admin, $this->nip, 'hukdis', $id, 'tolak', 'Tidak sesuai'));
        $this->assertNull($this->snapshotUji('pegawai_hukdis', $this->nip));
    }

    public function testBtcAuditTercatatTermasukSnapshot(): void
    {
        $row = $this->engine()->tambah($this->admin, $this->nip, 'hukdis', $this->dataHukdis('2024-01-01'), $this->berkasSk());

        $audit = $this->auditUji('riwayat_hukdis', (string) $row['id_riwayat_hukdis']);
        $this->assertSame(AuditLogModel::EVENT_CREATE, $audit[0]['event'] ?? null);
        $this->assertNotSame([], $this->auditUji('pegawai_hukdis', $this->nip), 'audit snapshot pegawai_hukdis');
    }

    // ---- Aturan B-14 ---------------------------------------------------

    public function testIzinMatriksV2Role1Dan3HapusHanyaRole1(): void
    {
        foreach (Role::all() as $role) {
            $admin = in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true);

            foreach ([AksiRiwayat::Lihat, AksiRiwayat::Tambah, AksiRiwayat::Ubah] as $aksi) {
                $this->assertSame($admin, $this->d->boleh($aksi, $role), "{$aksi->value} {$role}");
            }

            // [K] hapus ikut legacy Hukdis.php:171-190 userAuth(['1']) — keputusan user 12-10-2026 (deviasi Matriks v2).
            $this->assertSame($role === Role::SUPER_ADMIN, $this->d->boleh(AksiRiwayat::Hapus, $role), "hapus {$role}");

            $this->assertFalse($this->d->boleh(AksiRiwayat::Proses, $role), "proses {$role}");
        }
    }

    public function testTanggalBerakhirDihitungOtomatisDariMasaSanksi(): void
    {
        // DoD B-14 / MTC-021: TMT 1 Januari 2026 + 12 bulan = 1 Januari 2027; masa teks diisi.
        $row = $this->tambah($this->dataHukdis('2026-01-01', ['masa_hukuman' => '', 'akhir_hukdis' => '']));

        $this->assertSame('2027-01-01', $row['akhir_hukdis']);
        $this->assertSame('1 Tahun', $row['masa_hukuman']);

        // Akhir dijepit akhir bulan (TanggalBisnis): 31 Agustus + 6 bulan (jenis penundaan) = 28 Februari.
        $row = $this->tambah($this->dataHukdis('2025-08-31', ['id_tingkat_hukdis' => self::TINGKAT_SEDANG, 'id_jenis_hukdis' => self::JENIS_PENUNDAAN]));
        $this->assertSame('2026-02-28', $row['akhir_hukdis']);
        $this->assertSame('6 Bulan', $row['masa_hukuman']);
    }

    public function testNilaiManualDipertahankanDanAkhirHarusSetelahTmt(): void
    {
        $row = $this->tambah($this->dataHukdis('2026-01-01', ['masa_hukuman' => '9 bulan sesuai SK', 'akhir_hukdis' => '2026-10-01']));
        $this->assertSame(['9 bulan sesuai SK', '2026-10-01'], [$row['masa_hukuman'], $row['akhir_hukdis']]);

        $this->assertErrors(['akhir_hukdis'], fn () => $this->tambah($this->dataHukdis('2027-01-01', ['akhir_hukdis' => '2027-01-01'])));
    }

    public function testUbahMengosongkanAkhirMenghitungUlang(): void
    {
        $id = (int) $this->tambah($this->dataHukdis('2026-01-01', ['akhir_hukdis' => '2026-06-01']))['id_riwayat_hukdis'];

        $row = $this->engine()->ubah($this->admin, $this->nip, 'hukdis', $id, ['tmtsk' => '2026-02-01', 'akhir_hukdis' => '']);

        $this->assertSame('2027-02-01', $row['akhir_hukdis']);
        $this->assertSame('2027-02-01', $this->snapshotUji('pegawai_hukdis', $this->nip)['akhir_hukdis']);
    }

    public function testJenisTanpaMasaWajibIsiManual(): void
    {
        $data = $this->dataHukdis('2026-01-01', ['id_jenis_hukdis' => self::JENIS_TANPA_MASA, 'masa_hukuman' => '', 'akhir_hukdis' => '']);

        $this->assertErrors(['akhir_hukdis', 'masa_hukuman'], fn () => $this->tambah($data));

        $row = $this->tambah(['masa_hukuman' => '3 Bulan', 'akhir_hukdis' => '2026-04-01'] + $data);
        $this->assertSame('2026-04-01', $row['akhir_hukdis']);
    }

    public function testJenisHarusMilikTingkatTerpilih(): void
    {
        $this->assertErrors(['id_jenis_hukdis'], fn () => $this->tambah($this->dataHukdis('2026-01-01', ['id_tingkat_hukdis' => self::TINGKAT_SEDANG])));
    }

    public function testNamaMasterDisalinDanSnapshot(): void
    {
        $this->tambah($this->dataHukdis('2020-01-01'));
        $row = $this->tambah($this->dataHukdis('2024-01-01'));

        $this->assertSame(['Ringan', 'Teguran Tertulis', 'III/a', 'Penata Muda'], [$row['tingkat_hukdis'], $row['jenis_hukdis'], $row['gol_ruang'], $row['pangkat']]);

        $snap = $this->snapshotUji('pegawai_hukdis', $this->nip);
        $this->assertSame((string) $row['id_riwayat_hukdis'], (string) $snap['id_riwayat_hukdis'], 'snapshot = TMT terbaru');
        $this->assertSame(['Teguran Tertulis', '2025-01-01', 'Pasal 3'], [$snap['jenis_hukdis'], $snap['akhir_hukdis'], $snap['aturan_dilanggar']]);
    }

    public function testStatusSelaluDisetujuiSaatUbah(): void
    {
        // Legacy Hukdis.php:130: ubah menulis status 1 (data impor berstatus 0 ikut menjadi 1 dan masuk snapshot).
        $id = $this->sisipRiwayat($this->d, $this->nip, $this->btcBaris($this->nip) + ['status' => 0, 'akhir_hukdis' => '2025-01-01', 'masa_hukuman' => '1 Tahun']);
        $this->lampiranUji->simpan($this->nip, Hukdis::LAMPIRAN_SK_HUKDIS, $id, $this->berkasUji('sk.pdf'), $this->d->lampiran()[0]);

        $row = $this->engine()->ubah($this->admin, $this->nip, 'hukdis', $id, ['no_sk' => 'SK-REVISI', 'status' => '2']);

        $this->assertSame('1', (string) $row['status']);
        $this->assertSame('SK-REVISI', $this->snapshotUji('pegawai_hukdis', $this->nip)['no_sk']);
    }

    public function testTmtKembarDanFieldWajibLegacy(): void
    {
        $this->tambah($this->dataHukdis('2024-01-01'));
        $this->assertErrors(['tmtsk'], fn () => $this->tambah($this->dataHukdis('2024-01-01')));

        try {
            $this->tambah(['tmtsk' => '2025-01-01']);
            $this->fail('422 diharapkan');
        } catch (ValidationException $e) {
            $this->assertSame(['id_tingkat_hukdis', 'id_jenis_hukdis', 'id_pangkat', 'no_sk', 'tgl_sk', 'aturan_dilanggar', 'alasan_hukuman'], array_keys($e->getErrors() ?? []));
        }
    }

    public function testSkHukdisWajib(): void
    {
        $this->assertErrors(['berkas.7'], fn () => $this->engine()->tambah($this->admin, $this->nip, 'hukdis', $this->dataHukdis('2024-01-01')));
    }

    public function testHapusHanyaRole1(): void
    {
        $id = (int) $this->tambah($this->dataHukdis('2024-01-01'))['id_riwayat_hukdis'];

        // Role 3 ditolak di izin Definisi (bukan kunci baris Disetujui), juga untuk baris impor berstatus 0.
        $this->assertStatusApi(403, fn () => $this->engine()->hapus($this->adminSatker, $this->nip, 'hukdis', $id));
        $impor = $this->sisipRiwayat($this->d, $this->nip, $this->btcBaris($this->nip) + ['status' => 0, 'tmtsk' => '2025-01-01']);
        $this->assertStatusApi(403, fn () => $this->engine()->hapus($this->adminSatker, $this->nip, 'hukdis', $impor));

        $this->engine()->hapus($this->admin, $this->nip, 'hukdis', $id);
        $this->assertNull($this->snapshotUji('pegawai_hukdis', $this->nip));
    }

    /**
     * Master G-06: tingkat 1 Ringan (jenis 1 Teguran Tertulis 12 bulan, jenis 2 Teguran Lisan tanpa masa), tingkat 2
     * Sedang (jenis 3 Penundaan 6 bulan).
     */
    private function siapkanMasterHukdis(): void
    {
        foreach ([self::TINGKAT_RINGAN => 'Ringan', self::TINGKAT_SEDANG => 'Sedang'] as $id => $nama) {
            $this->db->table('tingkat_hukdis')->insert(['id_tingkat_hukdis' => $id, 'tingkat_hukdis' => $nama, 'order' => $id, 'status' => 1]);
        }

        foreach ([
            [self::JENIS_TEGURAN, self::TINGKAT_RINGAN, 'Teguran Tertulis', 12],
            [self::JENIS_TANPA_MASA, self::TINGKAT_RINGAN, 'Teguran Lisan', null],
            [self::JENIS_PENUNDAAN, self::TINGKAT_SEDANG, 'Penundaan', 6],
        ] as [$id, $tingkat, $nama, $masa]) {
            $this->db->table('jenis_hukdis')->insert([
                'id_jenis_hukdis'   => $id, 'id_tingkat_hukdis' => $tingkat, 'jenis_hukdis' => $nama,
                'masa_sanksi_bulan' => $masa, 'order' => $id, 'status' => 1,
            ]);
        }
    }

    /**
     * @param array<string, int|string> $override
     *
     * @return array<string, int|string>
     */
    private function dataHukdis(string $tmt, array $override = []): array
    {
        return $override + [
            'id_tingkat_hukdis' => self::TINGKAT_RINGAN,
            'id_jenis_hukdis'   => self::JENIS_TEGURAN,
            'id_pangkat'        => $this->idPangkatUji,
            'tmtsk'             => $tmt,
            'tgl_sk'            => $tmt,
            'no_sk'             => 'SK/' . $tmt,
            'aturan_dilanggar'  => 'Pasal 3',
            'alasan_hukuman'    => 'Tidak masuk kerja',
        ];
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function berkasSk(): array
    {
        return [Hukdis::LAMPIRAN_SK_HUKDIS => $this->berkasUji('sk.pdf')];
    }

    /**
     * @param array<string, mixed>          $data
     * @param array<int, UploadedFile>|null $berkas null = SK hukdis PDF
     *
     * @return array<string, mixed>
     */
    private function tambah(array $data, ?array $berkas = null): array
    {
        return $this->engine()->tambah($this->admin, $this->nip, 'hukdis', $data, $berkas ?? $this->berkasSk());
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
            $this->assertSame($status, $e->getStatusCode());

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
