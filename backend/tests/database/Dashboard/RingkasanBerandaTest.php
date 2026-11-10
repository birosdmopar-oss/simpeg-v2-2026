<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use App\Libraries\CacheService;
use App\Libraries\Dashboard\Beranda\RingkasanBeranda;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use Config\Dashboard;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-087 (F-13 M1, PRD Fase 7 v2.0 §5.10) — `GET api/v1/dashboard/ringkasan` keluarga admin: tile komposisi + status hari
 * ini berurutan seperti `views/user/dashboard_ad.php`, persen terhadap total aktif, TB kementerian termasuk perpanjangan
 * (role 3 tanpa, Q7-41), lingkup role 3 satker/unit akun (`pmj`), letak pengecualian NIP per lingkup ikut legacy
 * (Q7-41; NIP SINTETIS), saklar tampilan komposisi `donut`/`angka` (Q7-36), cache hanya angka tetap. Hari ini = 10-11-2026 WIB.
 *
 * @internal
 */
final class RingkasanBerandaTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    private const URL = 'api/v1/dashboard/ringkasan';

    private const HARI_INI = '2026-11-10';

    private Dashboard $configAsli;

    protected function setUp(): void
    {
        parent::setUp();
        Time::setTestNow('2026-11-10 09:00:00', 'Asia/Jakarta');
        $this->configAsli                           = clone config(Dashboard::class);
        config(Dashboard::class)->ttlRingkasanDetik = 0;
        config(Dashboard::class)->nipDisembunyikan  = '';
        config(Dashboard::class)->tampilanKomposisi = Dashboard::KOMPOSISI_DONUT;

        // Master jenis pegawai 1..6 (nama sintetis) untuk FK pegawai.id_jenis_pegawai; baris bawaan migration dipakai bila ada.
        foreach ([1 => 'PNS', 2 => 'PTT', 3 => 'Non PNS', 4 => 'Staf Khusus', 5 => 'Tenaga Ahli', 6 => 'PPPK'] as $id => $nama) {
            if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', $id)->countAllResults() === 0) {
                $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => $id, 'jenis_pegawai' => $nama, 'status' => 1]);
            }
        }

        // jenis_status 3 = CLTN (FK pegawai.id_jenis_status); nama sintetis bila belum ada baris bawaan.
        if ($this->db->table('jenis_status')->where('id_jenis_status', 3)->countAllResults() === 0) {
            $this->db->table('jenis_status')->insert(['id_jenis_status' => 3, 'jenis_status' => 'CLTN', 'status_pegawai' => 1, 'order' => 3, 'status' => 1]);
        }
    }

    protected function tearDown(): void
    {
        $asli                    = config(Dashboard::class);
        $asli->ttlRingkasanDetik = $this->configAsli->ttlRingkasanDetik;
        $asli->nipDisembunyikan  = $this->configAsli->nipDisembunyikan;
        $asli->tampilanKomposisi = $this->configAsli->tampilanKomposisi;
        Time::setTestNow();
        Services::reset(true);
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testKementerianUrutanLabelJumlahPersenDanStatusHariIni(): void
    {
        $satker = $this->buatSatker();
        $pns    = [$this->pegawai($satker, 1), $this->pegawai($satker, 1), $this->pegawai($satker, 1)];
        $this->pegawai($satker, 2);
        $this->pegawai($satker, 3);
        $this->pegawai($satker, 4);
        $this->pegawai($satker, 5);
        $this->pegawai($satker, 6);
        $this->pegawai($satker, 1, ['status' => 2]); // tidak aktif: tidak dihitung
        $this->pegawai($satker, 1, ['id_jenis_status' => 3]); // CLTN (tetap PNS aktif)

        $this->tb($pns[0], '2026-09-01', '2026-11-10');                         // aktif (batas akhir inklusif)
        $this->tb($pns[1], '2025-09-01', '2026-08-31', '2026-09-01', '2027-08-31'); // aktif lewat perpanjangan (kementerian)
        $this->tb($pns[2], '2026-11-11', '2027-11-10');                         // belum mulai
        $this->ib($pns[0], '2026-11-10', '2026-12-31');
        $this->cuti($pns[1], '2026-11-09', '2026-11-10');
        $this->cuti($pns[2], '2026-11-08', '2026-11-09'); // sudah lewat
        $this->dh($pns[0], self::HARI_INI);
        $this->dh($pns[1], '2026-11-09');

        $data = $this->dataSebagai($this->buatAkunTanpaNip('admin.beranda', Role::SUPER_ADMIN));

        $this->assertSame('admin', $data['keluarga']);
        $this->assertSame(['jenis' => 'kementerian', 'id' => null], $data['lingkup']);
        $this->assertSame('donut', $data['tampilan_komposisi']);
        $this->assertSame(self::HARI_INI, $data['tanggal']);
        $this->assertSame(9, $data['total_aktif']);
        $this->assertSame(array_column(RingkasanBeranda::TILE, 'label'), array_column($data['tile'], 'label'));
        $this->assertSame([
            'pns' => 4, 'ptt' => 1, 'non_pns' => 1, 'staf_khusus' => 1, 'tenaga_ahli' => 1,
            'tb'  => 2, 'ib' => 1, 'cltn' => 1, 'cuti' => 1, 'presensi_online' => 1, 'pppk' => 1,
        ], $this->jumlah($data));
        $this->assertSame(44, $this->tile($data, 'pns')['persen'], 'round(4/9×100)');
        $this->assertSame(11, $this->tile($data, 'pppk')['persen']);
        $this->assertSame('komposisi', $this->tile($data, 'pppk')['kelompok']);
        $this->assertSame('hari_ini', $this->tile($data, 'cltn')['kelompok']);
    }

    public function testTanpaPegawaiPersenNolTanpaPembagianNol(): void
    {
        $data = $this->dataSebagai($this->buatAkunTanpaNip('admin.kosong', Role::ADMIN_VIEW_ESELON1));

        $this->assertSame(0, $data['total_aktif']);

        foreach ($data['tile'] as $t) {
            $this->assertSame([0, 0], [$t['jumlah'], $t['persen']], $t['kode']);
        }
    }

    public function testRole4Role5Role8LingkupKementerian(): void
    {
        $this->pegawai($this->buatSatker(), 1);

        foreach ([Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN] as $role) {
            $data = $this->dataSebagai($this->buatAkunTanpaNip('admin.r' . $role, $role));
            $this->assertSame(['jenis' => 'kementerian', 'id' => null], $data['lingkup'], (string) $role);
            $this->assertSame(1, $data['total_aktif']);
        }
    }

    public function testPengecualianNipKementerianSemuaTileKecualiTotal(): void
    {
        $satker   = $this->buatSatker();
        $sembunyi = $this->pegawai($satker, 1);
        $this->pegawai($satker, 1);
        $this->tb($sembunyi, '2026-01-01', '2026-12-31');
        $this->dh($sembunyi, self::HARI_INI);
        config(Dashboard::class)->nipDisembunyikan = ' ' . $sembunyi . ' , 199999999999999999 ';

        $data = $this->dataSebagai($this->buatAkunTanpaNip('admin.sembunyi', Role::SUPER_ADMIN));

        $this->assertSame(2, $data['total_aktif'], 'total kementerian tanpa pengecualian [K]');
        $this->assertSame(1, $this->tile($data, 'pns')['jumlah']);
        $this->assertSame(0, $this->tile($data, 'tb')['jumlah']);
        $this->assertSame(0, $this->tile($data, 'presensi_online')['jumlah']);
        $this->assertStringNotContainsString($sembunyi, json_encode($data, JSON_THROW_ON_ERROR), 'NIP tidak bocor ke respons');
    }

    public function testRole3SatkerLingkupTbTanpaPerpanDanLetakPengecualian(): void
    {
        $unit     = $this->buatUnit();
        $satker   = $this->buatSatker($unit);
        $lain     = $this->buatSatker($unit);
        $sembunyi = $this->pegawai($satker, 1);
        $biasa    = $this->pegawai($satker, 1);
        $luar     = $this->pegawai($lain, 1);
        $this->tb($sembunyi, '2026-01-01', '2026-12-31');                         // aktif, NIP disembunyikan → TETAP dihitung
        $this->tb($biasa, '2025-09-01', '2026-08-31', '2026-09-01', '2027-08-31'); // hanya perpanjangan → role 3 TIDAK
        $this->tb($luar, '2026-01-01', '2026-12-31');                             // satker lain
        $this->dh($sembunyi, self::HARI_INI);
        config(Dashboard::class)->nipDisembunyikan = $sembunyi;

        $akun = $this->buatAkunTanpaNip('admin.satker', Role::ADMIN_SATKER, ['id_unit' => (string) $unit, 'id_satker' => (string) $satker]);
        $data = $this->dataSebagai($akun);

        $this->assertSame(['jenis' => 'satker', 'id' => $satker], $data['lingkup']);
        $this->assertSame(1, $data['total_aktif'], 'total satker MEMAKAI pengecualian [K]');
        $this->assertSame(1, $this->tile($data, 'pns')['jumlah']);
        $this->assertSame(1, $this->tile($data, 'tb')['jumlah'], 'TB role 3: tanpa perpanjangan, tanpa pengecualian');
        $this->assertSame(1, $this->tile($data, 'presensi_online')['jumlah'], 'presensi role 3 tanpa pengecualian');
    }

    public function testRole3UnitTotalTanpaPengecualian(): void
    {
        $unit     = $this->buatUnit();
        $lainUnit = $this->buatUnit();
        $sembunyi = $this->pegawai($this->buatSatker($unit), 1);
        $this->pegawai($this->buatSatker($unit), 2);
        $this->pegawai($this->buatSatker($lainUnit), 1);
        config(Dashboard::class)->nipDisembunyikan = $sembunyi;

        $akun = $this->buatAkunTanpaNip('admin.unit', Role::ADMIN_SATKER, ['id_unit' => (string) $unit, 'id_satker' => null]);
        $data = $this->dataSebagai($akun);

        $this->assertSame(['jenis' => 'unit', 'id' => $unit], $data['lingkup']);
        $this->assertSame(2, $data['total_aktif'], 'total unit TANPA pengecualian [K]');
        $this->assertSame(0, $this->tile($data, 'pns')['jumlah']);
        $this->assertSame(1, $this->tile($data, 'ptt')['jumlah']);
    }

    public function testRole3SatkerTidakKanonikTanpaDataTidakJatuhKeUnit(): void
    {
        $unit = $this->buatUnit();
        $this->pegawai($this->buatSatker($unit), 1);

        $akun = $this->buatAkunTanpaNip('admin.rusak', Role::ADMIN_SATKER, ['id_unit' => (string) $unit, 'id_satker' => 'S01']);
        $data = $this->dataSebagai($akun);

        $this->assertSame(['jenis' => 'satker', 'id' => null], $data['lingkup']);
        $this->assertSame(0, $data['total_aktif']);
    }

    public function testSaklarTampilanKomposisiAngkaDataSama(): void
    {
        $this->pegawai($this->buatSatker(), 1);
        $akun  = $this->buatAkunTanpaNip('admin.saklar', Role::SUPER_ADMIN);
        $donut = $this->dataSebagai($akun);

        config(Dashboard::class)->tampilanKomposisi = Dashboard::KOMPOSISI_ANGKA;
        $angka                                      = $this->dataSebagai($akun);

        config(Dashboard::class)->tampilanKomposisi = 'lain';
        $lain                                       = $this->dataSebagai($akun);

        $this->assertSame(['donut', 'angka', 'donut'], [$donut['tampilan_komposisi'], $angka['tampilan_komposisi'], $lain['tampilan_komposisi']]);
        unset($donut['tampilan_komposisi'], $angka['tampilan_komposisi']);
        $this->assertSame($donut, $angka, 'data/API sama untuk kedua mode');
    }

    public function testCacheHanyaAngkaTetapHariIniSelaluBaru(): void
    {
        Services::injectMock('cacheService', new CacheService(new MockCache(), 'uji_'));
        config(Dashboard::class)->ttlRingkasanDetik = 900;
        $satker                                     = $this->buatSatker();
        $nip                                        = $this->pegawai($satker, 1);
        $akun                                       = $this->buatAkunTanpaNip('admin.cache', Role::SUPER_ADMIN);

        $awal = $this->dataSebagai($akun);
        $this->pegawai($satker, 1);
        $this->dh($nip, self::HARI_INI);
        $kedua = $this->dataSebagai($akun);

        $this->assertSame([1, 1], [$awal['total_aktif'], $this->tile($awal, 'pns')['jumlah']]);
        $this->assertSame([1, 1], [$kedua['total_aktif'], $this->tile($kedua, 'pns')['jumlah']], 'komposisi dari cache');
        $this->assertSame(1, $this->tile($kedua, 'presensi_online')['jumlah'], 'hari ini tidak di-cache');
    }

    public function testKeluargaPegawaiJumlahKartuPrediksiPerRole(): void
    {
        $harapan = [Role::PEGAWAI => ['pensiun', 'kp', 'tj', 'kgb'], Role::PTT => [], Role::PPPK => ['pensiun', 'kgb']];

        foreach (Role::UL_PEGAWAI as $role) {
            $nip  = $this->pegawai($this->buatSatker(), 1);
            $akun = $this->buatAkunUntuk($nip, $role);
            $res  = $this->withHeaders(['Authorization' => 'Bearer ' . $this->issueTokensForUser($this->db->table('pengguna')->where('id_pengguna', $akun)->get()->getRowArray())['access_token']])->get(self::URL);
            $res->assertStatus(200);
            $data = json_decode($res->getJSON(), true)['data'];
            $this->assertSame(['keluarga', 'tanggal', 'prediksi', 'bawahan'], array_keys($data), (string) $role);
            $this->assertSame(['atasan' => false, 'presensi' => [], 'ak' => []], $data['bawahan'], 'tanpa bawahan');
            $this->assertSame('pegawai', $data['keluarga']);
            $this->assertSame($harapan[$role], array_column($data['prediksi'], 'kode'), (string) $role);
        }
    }

    public function testTanpaToken401(): void
    {
        $this->get(self::URL)->assertStatus(401);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function pegawai(int $idSatker, int $jenis, array $override = []): string
    {
        return $this->buatPegawaiDiSatker($idSatker, array_merge(['id_jenis_pegawai' => $jenis], $override));
    }

    private function tb(string $nip, string $mulai, string $akhir, ?string $mulaiPerpan = null, ?string $akhirPerpan = null): void
    {
        $this->db->table('riwayat_tb')->insert($this->pendidikan() + [
            'nip'              => $nip, 'status' => 1, 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir,
            'tgl_mulai_perpan' => $mulaiPerpan, 'tgl_akhir_perpan' => $akhirPerpan,
        ]);
    }

    private function ib(string $nip, string $mulai, string $akhir): void
    {
        $this->db->table('riwayat_ib')->insert($this->pendidikan() + [
            'nip' => $nip, 'nama' => 'Fixture', 'status' => 1, 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir,
        ]);
    }

    private function cuti(string $nip, string $mulai, string $akhir): void
    {
        $this->db->table('riwayat_cuti')->insert([
            'nip'       => $nip, 'status' => 1, 'id_jenis_cuti' => 1, 'nama_jenis_cuti' => 'Cuti Tahunan',
            'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir, 'lama_cuti' => 1,
        ]);
    }

    private function dh(string $nip, string $tgl): void
    {
        $this->db->table('dh_online')->insert(['nip' => $nip, 'tgl' => $tgl, 'jam_masuk' => '07:30:00']);
    }

    /**
     * @return array<string, int|string>
     */
    private function pendidikan(): array
    {
        return [
            'jenjang_pendidikan_singkat' => 'S2', 'institusi_pendidikan' => 'Institut Fixture', 'jurusan_pendidikan' => 'Fixture',
            'dalam_negeri'               => 1, 'lokasi_pendidikan' => 'Kota Fixture', 'lembaga_yang_mendanai' => 'Fixture',
        ];
    }

    /**
     * @param array<string, mixed> $akun
     *
     * @return array<string, mixed>
     */
    private function dataSebagai(array $akun): array
    {
        $res = $this->asUser($akun)->get(self::URL);
        $res->assertStatus(200);

        return json_decode($res->getJSON(), true)['data'];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, int>
     */
    private function jumlah(array $data): array
    {
        return array_column($data['tile'], 'jumlah', 'kode');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function tile(array $data, string $kode): array
    {
        foreach ($data['tile'] as $t) {
            if ($t['kode'] === $kode) {
                return $t;
            }
        }

        $this->fail("tile {$kode} tidak ada");
    }
}
