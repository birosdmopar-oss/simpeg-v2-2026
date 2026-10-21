<?php

declare(strict_types=1);

namespace Tests\Dashboard;

use App\Constants\Role;
use App\Libraries\Dashboard\Statistik\StatistikCache;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use App\Models\Kepegawaian\PegawaiModel;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Dashboard\StatistikUjiTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Kepegawaian\Riwayat\UjiKp;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * F-03 cache & invalidasi (MAKE-036, PRD §7.1.8 StatistikCacheInvalidasiTest, F7-12): permintaan kedua dari cache;
 * tulis `pegawai` lewat PegawaiModel (hook b) dan transisi riwayat lewat RiwayatEngine (event, listener a di
 * Config\Events) menghapus cache — statistik berubah tanpa menunggu TTL.
 *
 * @internal
 */
final class StatistikCacheInvalidasiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use RiwayatUjiTrait;
    use StatistikUjiTrait;

    protected $seed = AuthSeeder::class;

    private const BASE = 'api/v1/statistik';

    private string $lahir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangCacheUji();
        $this->siapkanMasterStatistik();

        // Listener Config\Events bisa sudah dilepas test lain dalam proses yang sama (RiwayatUjiTrait::lepasEngine
        // memanggil Events::removeAllListeners); pasang ulang hanya bila hilang — saat berjalan sendiri harus sudah ada.
        $ada = array_filter(Events::listeners(RiwayatEngine::EVENT_NOTIFIKASI), static fn ($l): bool => $l === [StatistikCache::class, 'invalidasiDariEvent']);

        if ($ada === []) {
            Events::on(RiwayatEngine::EVENT_NOTIFIKASI, [StatistikCache::class, 'invalidasiDariEvent']);
        }

        $this->lahir = date('Y-m-d', strtotime(TanggalBisnis::hariIni() . ' -40 years -100 days'));
        $this->pegawaiStatistik('A1', ['jenis' => 1, 'lahir' => $this->lahir, 'pangkat' => 'III/a']);
        $this->pegawaiStatistik('B1', ['jenis' => 1, 'lahir' => $this->lahir]);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    public function testPermintaanKeduaDariCache(): void
    {
        $pertama = $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja'));
        $kedua   = $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja'));

        $this->assertFalse($pertama['cache']['hit']);
        $this->assertTrue($kedua['cache']['hit']);
        $this->assertSame($pertama['baris'], $kedua['baris']);
        $this->assertNotNull($this->cacheUji->get('dashboard_stat_unit-kerja_kementerian_0_all'));
    }

    public function testTulisPegawaiLewatModelMenghapusCache(): void
    {
        $this->assertSame(2, $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja'))['total']);
        $this->assertTrue($this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja'))['cache']['hit']);

        // Pegawai baru lewat PegawaiModel (jalur B-05) + snapshot jabatan → hook afterInsert.
        $model = new PegawaiModel();
        $nip   = $this->nipSintetis();
        $auth  = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($this->satker['A1'], ['id_jenis_pegawai' => 1]), Role::SUPER_ADMIN));
        $model->withActor($auth, fn () => $model->insert([
            'nip' => $nip, 'nama' => 'Pegawai Baru', 'tgl_lahir' => $this->lahir, 'jenis_kelamin' => 2, 'status' => 1, 'id_jenis_pegawai' => 1,
        ]));
        $this->db->table('pegawai_mutasi_jabatan')->insert(['nip' => $nip, 'jenis_jabatan' => 1, 'jenis_mutasi' => 1, 'tmtsk' => '2015-01-01', 'id_unit' => $this->unit['A'], 'id_satker' => $this->satker['A1']]);

        $sesudah = $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja'));
        $this->assertFalse($sesudah['cache']['hit'], 'cache dihapus oleh hook model');
        $this->assertSame(4, $sesudah['total'], '2 awal + 1 akun fixture + 1 pegawai baru');

        // Nonaktifkan lewat model → hook afterUpdate.
        $this->assertTrue($this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/jenis-kelamin'))['cache']['hit'] === false);
        $this->assertTrue($this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/jenis-kelamin'))['cache']['hit']);
        $model->withActor($auth, fn () => $model->update($nip, ['status' => 2]));

        $jk = $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/jenis-kelamin'));
        $this->assertFalse($jk['cache']['hit']);
        $this->assertSame(['l' => 2, 'p' => 0], $this->nilaiBaris($jk, 'Unit A'), 'pegawai baru (P) nonaktif tidak dihitung lagi');
    }

    public function testTransisiRiwayatLewatEngineMenghapusCache(): void
    {
        $this->pasangEngineNyata([new UjiKp()]);

        // Master KP untuk UjiKp: jenis KP + pangkat III/a milik fixture statistik (nama pangkat UNIQUE).
        foreach ([1 => 'CPNS', 2 => 'PNS', 3 => 'Reguler', 6 => 'Lainnya'] as $id => $nama) {
            $this->buatJenisKp($id, $nama);
        }

        $this->idPangkatUji = $this->pangkat['III/a'];

        $nip   = $this->pegawaiStatistik('A2', ['jenis' => 1, 'lahir' => $this->lahir]);
        $admin = $this->admin();

        $awal = $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/golongan'));
        $this->assertSame(['III/a'], array_column($awal['seri'], 'label'));
        $this->assertTrue($this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/golongan'))['cache']['hit']);

        // Riwayat KP Menunggu lalu disetujui lewat engine → snapshot pegawai_kp disinkronkan + event NOTIF_DIPROSES.
        $id = $this->sisipRiwayat(new UjiKp(), $nip, $this->barisKp(3, '2024-04-01') + ['status' => 0]);
        service('riwayatService')->proses($admin, $nip, 'kp', $id, 'setujui', null);

        $this->assertNotSame([], $this->notifikasiUji, 'event riwayat terpicu');
        $sesudah = $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/golongan'));
        $this->assertFalse($sesudah['cache']['hit'], 'cache dihapus oleh listener event riwayat');
        $this->assertSame(['pns_iii_a' => 2], $this->nilaiBaris($sesudah, 'Unit A'), 'pangkat baru dari snapshot ikut dihitung');

        $this->lepasEngine();
    }

    public function testKueriTulisLangsungKeSnapshotMenghapusCache(): void
    {
        $nip = $this->pegawaiStatistik('B1', ['jenis' => 1, 'lahir' => $this->lahir]);

        $this->assertSame(3, $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja'))['total']);
        $this->assertTrue($this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja'))['cache']['hit']);

        // Pindah satker lewat query builder (tanpa model, tanpa event) → listener DBQuery (c).
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->update(['id_unit' => $this->unit['A'], 'id_satker' => $this->satker['A2']]);

        $sesudah = $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja'));
        $this->assertFalse($sesudah['cache']['hit']);
        $this->assertSame(['pns' => 2, 'pppk' => 0], $this->nilaiBaris($sesudah, 'Unit A'));
        $this->assertSame(['pns' => 1, 'pppk' => 0], $this->nilaiBaris($sesudah, 'Unit B'), 'tinggal pegawai B1 dari setUp');

        // Kueri baca dan tulis ke tabel lain tidak menghapus cache.
        $this->assertTrue($this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja'))['cache']['hit']);
        $this->db->table('unit')->where('id_unit', $this->unit['C'])->update(['unit' => 'Unit C Diganti']);
        $this->db->table('pegawai')->where('nip', $nip)->get();
        $this->assertTrue($this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja'))['cache']['hit']);
    }

    public function testEventTanpaPayloadRelevanTetapMenghapusCache(): void
    {
        $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/usia'));
        $this->assertNotNull($this->cacheUji->get('dashboard_stat_usia_kementerian_0_all'));

        Events::trigger(RiwayatEngine::EVENT_NOTIFIKASI, ['tipe' => 1, 'jenis' => 'pendidikan', 'nip' => '0', 'id' => 1]);

        $this->assertNull($this->cacheUji->get('dashboard_stat_usia_kementerian_0_all'));
    }
}
