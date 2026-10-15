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
use App\Libraries\Kepegawaian\Riwayat\Definisi\Kp;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Skp;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use Closure;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatBtcTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-15 Riwayat Angka Kredit (WS-1 M5 MAKE-008): B-TC generik, hak akses keputusan 13-10 (lihat 1/2/3/4/7 — role 2 hanya
 * JF tertentu, tulis & proses role 1), isian turunan server (legacy `L_ak.php` form()), periode beririsan, satu PAK
 * menunggu per jabatan, file PAK kolom berkas, snapshot `pegawai_ak`, daftar status 0/1.
 *
 * Butir B-TC approval ditimpa: proses hanya role 1 (role 3 → 403).
 *
 * @internal
 */
final class AkTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatBtcTrait;
    use RiwayatUjiTrait;

    private Ak $d;
    private string $nip;
    private AuthContext $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->d = new Ak();
        $this->pasangEngineNyata([$this->d, new AkSiasn()]);
        Services::injectMock('storageAdapter', new LocalStorageAdapter((string) $this->akarLampiranRiwayatUji));
        $this->nip   = $this->buatPegawaiDiSatker($this->buatSatker());
        $this->admin = $this->aktor(Role::SUPER_ADMIN)[1];
        $this->jadikanGroup($this->nip, Ak::GROUP_JFT);
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
        return $this->dataAk('2024-01-01', '2024-06-30');
    }

    protected function btcBaris(string $nip): array
    {
        return $this->dataAk('2023-01-01', '2023-06-30') + ['nilai_ak' => 15, 'file_pak' => 'arsip/' . $nip . '/ak/pak.pdf'];
    }

    /**
     * @return array<string, UploadedFile>
     */
    protected function btcBerkas(): array
    {
        return [Ak::KOLOM_BERKAS => $this->berkasUji('pak.pdf')];
    }

    protected function btcScopeRole3(array $nipDalam): null
    {
        return null;
    }

    /**
     * Pengganti B-TC #2: proses hanya role 1 (keputusan 13-10); role 3 → 403. Approval final mengisi snapshot dan
     * `appr_by`/`appr_at`.
     */
    public function testBtcApprovalRole1DanRole3SnapshotSetelahApprovalFinal(): void
    {
        $idSatker    = $this->buatSatker();
        $nip         = $this->buatPegawaiDiSatker($idSatker);
        $adminSatker = $this->aktor(Role::ADMIN_SATKER, [], $idSatker)[1];
        $id          = $this->sisipRiwayat($this->d, $nip, $this->btcBaris($nip) + ['status' => 0]);

        $this->assertFalse($this->d->boleh(AksiRiwayat::Proses, Role::ADMIN_SATKER));
        $this->assertStatusApi(403, fn () => $this->engine()->proses($adminSatker, $nip, 'ak', $id, 'setujui', null));

        $row = $this->engine()->proses($this->admin, $nip, 'ak', $id, 'setujui', null);

        $this->assertSame('1', (string) $row['status']);
        $this->assertSame((string) $this->admin->idPengguna(), (string) $row['appr_by']);
        $this->assertNotNull($row['appr_at']);
        $this->assertSame((string) $id, (string) $this->snapshotUji('pegawai_ak', $nip)['id_riwayat_ak']);
    }

    // ---- Aturan B-15 ---------------------------------------------------

    public function testIzinKeputusan1310(): void
    {
        foreach (Role::all() as $role) {
            $lihat = in_array($role, [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::PPPK], true);
            $this->assertSame($lihat, $this->d->boleh(AksiRiwayat::Lihat, $role), "lihat {$role}");

            foreach ([AksiRiwayat::Tambah, AksiRiwayat::Ubah, AksiRiwayat::Hapus, AksiRiwayat::Proses] as $aksi) {
                $this->assertSame($role === Role::SUPER_ADMIN, $this->d->boleh($aksi, $role), "{$aksi->value} {$role}");
            }
        }
    }

    /**
     * Role 2 dan 7 (keputusan user 13-10-2026) hanya melihat AK/AK SIASN bila pejabat JF tertentu.
     */
    public function testRole2Dan7MelihatHanyaBilaJft(): void
    {
        foreach ([Role::PEGAWAI, Role::PPPK] as $role) {
            [$nip, , $akun] = $this->aktor($role);
            $this->jadikanGroup($nip, 3);

            $this->asUser($akun)->get("api/v1/pegawai/{$nip}/riwayat/ak")->assertStatus(403);
            $this->asUser($akun)->get("api/v1/pegawai/{$nip}/riwayat/ak-siasn")->assertStatus(403);
            $this->assertSame([], $this->tabAk($akun, $nip), "role {$role} non-JFT");

            $this->jadikanGroup($nip, Ak::GROUP_JFT);

            $this->asUser($akun)->get("api/v1/pegawai/{$nip}/riwayat/ak")->assertStatus(200);
            $this->asUser($akun)->get("api/v1/pegawai/{$nip}/riwayat/ak-siasn")->assertStatus(200);
            $tabs = $this->tabAk($akun, $nip);
            $this->assertSame(['ak', 'ak-siasn'], array_keys($tabs), "role {$role} JFT");
            $this->assertSame([true, false, false], [$tabs['ak']['can_view'], $tabs['ak']['can_create'], $tabs['ak']['can_process']]);
        }
    }

    public function testTambahHanyaUntukKelompokJabatanJf(): void
    {
        $this->jadikanGroup($this->nip, 1);
        [, , $akun] = $this->aktor(Role::SUPER_ADMIN);

        $this->assertStatusApi(403, fn () => $this->tambah($this->dataAk('2024-01-01', '2024-06-30')));
        $this->assertFalse($this->tabAk($akun, $this->nip)['ak']['can_create']);

        $this->jadikanGroup($this->nip, 3);
        $this->assertTrue($this->tabAk($akun, $this->nip)['ak']['can_create']);
        $this->assertSame('1', (string) $this->tambah($this->dataAk('2024-01-01', '2024-06-30'))['status']);
    }

    /**
     * [V2] Isian turunan diisi server dari data pegawai saat ini; nilai kiriman klien untuk kolom itu diabaikan.
     */
    public function testIsianTurunanDiisiServer(): void
    {
        $idJabatan = $this->siapkanIsianTurunan();

        $row = $this->tambah($this->dataAk('2024-01-01', '2024-06-30') + [
            'nama' => 'Palsu', 'jabatan' => 'Palsu', 'nilai_ak' => 999, 'ak_kum_kp' => 999, 'jenis_ak' => 1, 'tmt_jab' => '2000-01-01',
        ]);

        $this->assertSame('Dr. Budi Uji, M.Si.', $row['nama']);
        $this->assertSame([(string) $idJabatan, (string) $this->pmj($this->nip)['jabatan'], 'JF-0001', '2018-01-01'], [(string) $row['id_jabatan'], $row['jabatan'], $row['no_induk_jf'], $row['tmt_jab']]);
        $this->assertSame([(string) $this->idPangkatUji, 'Penata Muda', 'III/a', '2019-01-01'], [(string) $row['id_pangkat'], $row['pangkat'], $row['gol_ruang'], $row['tmt_pang']]);
        // Masa kerja sampai tanggal PAK 2024-07-15.
        $this->assertSame(['6', '6', '5', '6'], [(string) $row['mker_th_jab'], (string) $row['mker_bl_jab'], (string) $row['mker_th_pang'], (string) $row['mker_bl_pang']]);
        $this->assertSame([37.5, 0.0, 2, (string) $this->admin->idPengguna()], [(float) $row['nilai_ak'], (float) $row['ak_kum_kp'], (int) $row['jenis_ak'], (string) $row['created_by']]);
        $this->assertSame((string) $row['id_riwayat_ak'], (string) $this->snapshotUji('pegawai_ak', $this->nip)['id_riwayat_ak']);
    }

    /**
     * MAKE-017: pratinjau isian turunan form tambah = nilai yang diisi server saat tambah (logika bersama), masa kerja
     * sampai tgl_pak query, AK Lama bawaan = AK terakhir jabatan + pangkat sama.
     */
    public function testPratinjauSamaDenganIsianTurunanTambah(): void
    {
        $idJabatan = $this->siapkanIsianTurunan();
        $idRak     = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['status' => 1, 'id_jabatan' => $idJabatan, 'id_pangkat' => $this->idPangkatUji, 'nilai_ak' => 30]));
        $this->db->table('pegawai_ak')->insert(['nip' => $this->nip, 'id_riwayat_ak' => $idRak, 'id_jabatan' => $idJabatan, 'id_pangkat' => $this->idPangkatUji, 'nilai_ak' => 30]);
        [, , $akun] = $this->aktor(Role::SUPER_ADMIN);

        $result = $this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/riwayat/ak/pratinjau?tgl_pak=2024-07-15");
        $result->assertStatus(200);
        $p = $this->json($result)['data'];

        $this->assertSame(['Dr. Budi Uji, M.Si.', '2018-01-01', '2019-01-01', 30], [$p['nama'], $p['tmt_jab'], $p['tmt_pang'], (int) $p['ak_terakhir']]);
        $this->assertSame([6, 6, 5, 6], [$p['mker_th_jab'], $p['mker_bl_jab'], $p['mker_th_pang'], $p['mker_bl_pang']]);

        $row = $this->tambah(['ak_terakhir' => $p['ak_terakhir']] + $this->dataAk('2024-01-01', '2024-06-30'));
        foreach (['nama', 'id_jabatan', 'jabatan', 'no_induk_jf', 'tmt_jab', 'id_pangkat', 'pangkat', 'gol_ruang', 'tmt_pang', 'mker_th_jab', 'mker_bl_jab', 'mker_th_pang', 'mker_bl_pang', 'ak_kum_kp', 'ak_kum_next_jen'] as $kolom) {
            $this->assertSame((string) $row[$kolom], (string) $p[$kolom], "pratinjau {$kolom} = nilai simpan");
        }

        // Tanpa tgl_pak: masa kerja null.
        $tanpa = $this->json($this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/riwayat/ak/pratinjau"))['data'];
        $this->assertNull($tanpa['mker_th_jab']);
    }

    public function testPratinjauIzinTambahDanValidasi(): void
    {
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        foreach (['tgl_pak=15-07-2024', 'tgl_pak=2024-02-30', 'tgl_pak[]=2024-07-15'] as $query) {
            $result = $this->asUser($super)->get("api/v1/pegawai/{$this->nip}/riwayat/ak/pratinjau?{$query}");
            $result->assertStatus(422);
            $this->assertArrayHasKey('tgl_pak', $this->json($result)['errors'], $query);
        }

        // NIP tidak ada (role 1, lingkup mencakup semua) → 404.
        $this->asUser($super)->get('api/v1/pegawai/199912312099121999/riwayat/ak/pratinjau')->assertStatus(404);
        // Izin aksi tambah lebih dulu: AK SIASN tanpa izin tambah → 403.
        $this->asUser($super)->get("api/v1/pegawai/{$this->nip}/riwayat/ak-siasn/pratinjau")->assertStatus(403);

        // Izin = aksi tambah: role 3 (tanpa izin tambah AK) dan pegawai JFT sendiri → 403.
        $idSatker          = $this->buatSatker();
        $dalam             = $this->buatPegawaiDiSatker($idSatker);
        [, , $adminSatker] = $this->aktor(Role::ADMIN_SATKER, [], $idSatker);
        $this->asUser($adminSatker)->get("api/v1/pegawai/{$dalam}/riwayat/ak/pratinjau")->assertStatus(403);
        [$nip, , $pegawai] = $this->aktor(Role::PEGAWAI);
        $this->jadikanGroup($nip, Ak::GROUP_JFT);
        $this->asUser($pegawai)->get("api/v1/pegawai/{$nip}/riwayat/ak/pratinjau")->assertStatus(403);

        // Kelompok jabatan bukan JF (syarat tambah legacy) → 403.
        $this->jadikanGroup($this->nip, 1);
        $this->asUser($super)->get("api/v1/pegawai/{$this->nip}/riwayat/ak/pratinjau")->assertStatus(403);

        // Jenis ber-izin tambah tanpa hook pratinjauTambah() → 404.
        $this->lepasEngine();
        $this->pasangEngineNyata([new Skp()]);
        $this->asUser($super)->get("api/v1/pegawai/{$this->nip}/riwayat/skp/pratinjau")->assertStatus(404);
    }

    public function testPeriodeBeririsanDanSatuPengajuanMenungguPerJabatan(): void
    {
        $this->tambah($this->dataAk('2024-01-01', '2024-06-30'));
        $this->assertErrors(['periode_awal'], fn () => $this->tambah($this->dataAk('2024-06-30', '2024-12-31')));

        // Baris ditolak tidak memblok periode.
        $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['status' => 2, 'periode_awal' => '2025-01-01', 'periode_akhir' => '2025-06-30']));
        $this->tambah($this->dataAk('2025-01-01', '2025-06-30'));

        // PAK menunggu untuk jabatan saat ini → tambah ditolak; untuk jabatan lain tidak.
        $lain = $this->buatJabatan()['id_jabatan'];
        $id   = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['status' => 0, 'id_jabatan' => $lain]));
        $this->tambah($this->dataAk('2026-01-01', '2026-03-31'));
        $this->db->table('riwayat_ak')->where('id_riwayat_ak', $id)->update(['id_jabatan' => $this->pmj($this->nip)['id_jabatan']]);
        $this->assertErrors(['id_jabatan'], fn () => $this->tambah($this->dataAk('2026-04-01', '2026-06-30')));
    }

    public function testFilePakWajibDanBatas(): void
    {
        $this->assertErrors(['berkas.file_pak'], fn () => $this->engine()->tambah($this->admin, $this->nip, 'ak', $this->dataAk('2024-01-01', '2024-06-30')));
        $this->assertErrors(['berkas.file_pak'], fn () => $this->tambah($this->dataAk('2024-01-01', '2024-06-30'), $this->berkasUji('pak.txt', false)));
        $this->assertErrors(['berkas.file_pak'], fn () => $this->tambah($this->dataAk('2024-01-01', '2024-06-30'), $this->berkasUji('pak.pdf', true, 1024 * 1024)));
    }

    public function testUbahAdminMenyetujuiDanMenghitungUlang(): void
    {
        $id  = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['status' => 0, 'tmt_jab' => '2020-01-01']));
        $row = $this->engine()->ubah($this->admin, $this->nip, 'ak', $id, ['pengajuan_ak' => 20, 'tgl_pak' => '2023-07-01']);

        $this->assertSame(['1', 45.0, '3', '6'], [(string) $row['status'], (float) $row['nilai_ak'], (string) $row['mker_th_jab'], (string) $row['mker_bl_jab']]);
        $this->assertSame((string) $id, (string) $this->snapshotUji('pegawai_ak', $this->nip)['id_riwayat_ak']);
        // appr_* hanya lewat proses (legacy edit tidak mengisinya).
        $this->assertNull($row['appr_by']);

        // Tanggal PAK tidak berubah → masa kerja tidak dihitung ulang walau TMT berubah di baris.
        $this->db->table('riwayat_ak')->where('id_riwayat_ak', $id)->update(['tmt_jab' => '2010-01-01']);
        $row = $this->engine()->ubah($this->admin, $this->nip, 'ak', $id, ['keterangan' => 'catatan', 'tgl_pak' => '2023-07-01']);
        $this->assertSame(['3', '6', 45.0], [(string) $row['mker_th_jab'], (string) $row['mker_bl_jab'], (float) $row['nilai_ak']]);
    }

    /**
     * Baris Current AK legacy (`jenis_ak` 1) tidak pernah dihitung ulang; `jenis_ak` tidak di-reset ([V2]).
     */
    public function testCurrentAkJenis1TidakDihitungUlang(): void
    {
        $id = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['status' => 1, 'jenis_ak' => Ak::JENIS_CURRENT, 'nilai_ak' => 123.5]));
        $this->engine()->ubah($this->admin, $this->nip, 'ak', $id, ['keterangan' => 'awal']);
        $snapshot = $this->snapshotUji('pegawai_ak', $this->nip);

        $row = $this->engine()->ubah($this->admin, $this->nip, 'ak', $id, ['keterangan' => 'diubah']);
        $this->assertSame([123.5, '1'], [(float) $row['nilai_ak'], (string) $row['jenis_ak']]);

        // FE mengirim seluruh isian (termasuk AK lama/baru) — tetap tidak dihitung ulang.
        $row = $this->engine()->ubah($this->admin, $this->nip, 'ak', $id, $this->dataAk('2023-01-01', '2023-06-30') + ['keterangan' => 'diubah']);
        $this->assertSame([123.5, '1'], [(float) $row['nilai_ak'], (string) $row['jenis_ak']]);
        $this->assertSame([123.5, (string) $snapshot['id_riwayat_ak']], [(float) $this->snapshotUji('pegawai_ak', $this->nip)['nilai_ak'], (string) $this->snapshotUji('pegawai_ak', $this->nip)['id_riwayat_ak']]);
    }

    /**
     * Keputusan user 13-10-2026 (ikut legacy process_ak()): appr_at/appr_by diisi saat setujui DAN tolak (afterProses).
     */
    public function testProsesTolakJugaMengisiApproval(): void
    {
        $id  = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['status' => 0]));
        $row = $this->engine()->proses($this->admin, $this->nip, 'ak', $id, 'tolak', 'Berkas tidak sesuai');

        $this->assertSame(['2', (string) $this->admin->idPengguna()], [(string) $row['status'], (string) $row['appr_by']]);
        $this->assertNotNull($row['appr_at']);
        $this->assertNull($this->snapshotUji('pegawai_ak', $this->nip));
    }

    public function testDaftarHanyaStatus0Dan1TerbaruDulu(): void
    {
        $lama = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['status' => 1, 'tgl_pak' => '2022-01-01']));
        $baru = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['status' => 0, 'tgl_pak' => '2023-01-01']));
        $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['status' => 2, 'tgl_pak' => '2024-01-01']));

        $rows = $this->engine()->daftar($this->admin, $this->nip, 'ak');

        $this->assertSame([(string) $baru, (string) $lama], array_map(static fn (array $r): string => (string) $r['id_riwayat_ak'], $rows));
    }

    public function testSnapshotTerbaruMenurutTanggalPak(): void
    {
        $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['status' => 1, 'tgl_pak' => '2024-01-01']));
        $id = $this->sisipRiwayat($this->d, $this->nip, array_merge($this->btcBaris($this->nip), ['status' => 0, 'tgl_pak' => '2022-01-01', 'periode_awal' => '2021-01-01', 'periode_akhir' => '2021-06-30']));

        $this->engine()->proses($this->admin, $this->nip, 'ak', $id, 'setujui', null);

        $this->assertSame('2024-01-01', $this->snapshotUji('pegawai_ak', $this->nip)['tgl_pak']);
    }

    public function testMasaKerja(): void
    {
        $this->assertSame([3, 6], Ak::masaKerja('2020-01-01', '2023-07-01'));
        $this->assertSame([0, 0], Ak::masaKerja('2024-01-01', '2023-07-01'));
        $this->assertSame([null, null], Ak::masaKerja(null, '2023-07-01'));
    }

    /**
     * Data pegawai untuk isian turunan: nama bergelar, rantai TMT jabatan (2018-01-01), TMT pangkat dari KP disetujui
     * pertama berpangkat sama (2019-01-01, bukan TMT snapshot 2021-04-01), no induk JF.
     *
     * @return int id_jabatan pegawai saat ini
     */
    private function siapkanIsianTurunan(): int
    {
        $this->siapkanMasterKp();
        $this->db->table('pegawai')->where('nip', $this->nip)->update(['glr_awal' => 'Dr.', 'nama' => 'Budi Uji', 'glr_akhir' => 'M.Si.']);
        $idJabatan = (int) $this->pmj($this->nip)['id_jabatan'];
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $this->nip)->update(['tmtsk' => '2020-06-01', 'no_induk_jft' => 'JF-0001']);
        // Rantai TMT jabatan: 2018-01-01 (jabatan sama) ← 2016-01-01 (jabatan lain, berhenti di sini).
        $this->buatRiwayatMutasiJabatan($this->nip, ['tmtsk' => '2018-01-01']);
        $this->buatRiwayatMutasiJabatan($this->nip, ['tmtsk' => '2016-01-01', 'id_jabatan' => $this->buatJabatan()['id_jabatan']]);
        $this->buatRiwayatMutasiJabatan($this->nip, ['tmtsk' => '2015-01-01']);
        // TMT pangkat: KP disetujui pertama berpangkat sama (2019-01-01), bukan TMT snapshot (2021-04-01).
        $this->db->table('pegawai_kp')->insert(['nip' => $this->nip, 'id_pangkat' => $this->idPangkatUji, 'tmtsk' => '2021-04-01', 'tgl_sk' => '2021-03-01',
            'jenis_kp'                                => 'Reguler', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda']);
        $this->sisipRiwayat(new Kp(), $this->nip, $this->barisKp(3, '2019-01-01') + ['status' => 1]);
        $this->sisipRiwayat(new Kp(), $this->nip, $this->barisKp(3, '2018-01-01') + ['status' => 2]);

        return $idJabatan;
    }

    /**
     * @return array<string, float|int|string>
     */
    private function dataAk(string $awal, string $akhir): array
    {
        return [
            'no_hpak'          => 'PAK/' . $awal, 'tgl_pak' => '2024-07-15', 'periode_awal' => $awal, 'periode_akhir' => $akhir,
            'flag_instansi_ym' => 0, 'instansi_ym' => 'Kementerian Uji', 'nama_pym' => 'Pejabat Uji', 'jab_pym' => 'Kepala Biro Uji',
            'ak_terakhir'      => 25, 'pengajuan_ak' => 12.5,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function tambah(array $data, ?UploadedFile $berkas = null): array
    {
        return $this->engine()->tambah($this->admin, $this->nip, 'ak', $data, [Ak::KOLOM_BERKAS => $berkas ?? $this->berkasUji('pak.pdf')]);
    }

    /**
     * Snapshot jabatan pegawai $nip berkelompok jabatan $group (baris `group_jabatan` ber-id itu dibuat bila belum ada).
     */
    private function jadikanGroup(string $nip, int $group): void
    {
        if ($this->db->table('group_jabatan')->where('id_group_jabatan', $group)->countAllResults() === 0) {
            $this->db->table('group_jabatan')->insert(['id_group_jabatan' => $group, 'group_jabatan' => 'Group Uji ' . $group, 'status' => 1]);
        }

        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->update(['id_group_jabatan' => $group]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pmj(string $nip): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->get()->getRowArray();

        return $row;
    }

    /**
     * Descriptor tab AK/AK SIASN untuk $akun.
     *
     * @param array<string, mixed> $akun
     *
     * @return array<string, array<string, mixed>>
     */
    private function tabAk(array $akun, string $nip): array
    {
        $tabs  = $this->json($this->asUser($akun)->get("api/v1/pegawai/{$nip}/riwayat"))['data']['tabs'];
        $hasil = [];

        foreach ($tabs as $tab) {
            $hasil[$tab['jenis']] = $tab;
        }

        return $hasil;
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
