<?php

declare(strict_types=1);

namespace Tests\Dashboard;

use App\Constants\Role;
use App\Libraries\Dashboard\Dokumen\KolomDokumen;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\ListQuery;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Dashboard\DokumenUjiTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * F-09 daftar JSON (MAKE-037, PRD §7.2.11 DokumenEndpointTest): cakupan per jenis (F7-19), urutan DUK/Nominatif
 * (F7-17/F7-18), proyeksi pensiun rentang (F7-20), paginasi + pencarian (ListQuery), lingkup, 404/422.
 *
 * @internal
 */
final class DokumenEndpointTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use DokumenUjiTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    protected $seed = AuthSeeder::class;

    private const BASE = 'api/v1/dokumen';

    private int $tahunIni;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangCacheUji();
        $this->siapkanMasterDokumen();
        $this->tahunIni = (int) substr(TanggalBisnis::hariIni(), 0, 4);

        // Unit A / Satker A1: Citra IV/a eselon II (BUP 60, TMT pensiun tahun ini), Budi III/a TMT 2018 (BUP 58 tahun
        // depan), Dedi CPNS III/a (order 8), Eka PTT, Hana PNS nonaktif. Satker A2: Fajar PPPK gol V. Unit B: Gina II/a.
        $this->pegawaiDokumen('A1', ['nama' => 'Citra', 'jenis' => 1, 'jk' => 2, 'lahir' => $this->lahirBup(60, 0), 'pangkat' => 'IV/a', 'tmt_pangkat' => '2020-04-01', 'eselon' => 1, 'kelas' => 12, 'cpns' => '2000-03-01', 'jenjang' => 'S2', 'diklat' => 'PKN II']);
        $this->pegawaiDokumen('A1', ['nama' => 'Budi', 'jenis' => 1, 'lahir' => $this->lahirBup(58, 1), 'pangkat' => 'III/a', 'tmt_pangkat' => '2018-04-01', 'kelas' => 9, 'cpns' => '2010-01-01', 'kgb' => '2024-01-01', 'tanda_jasa' => 'SLKS X']);
        $this->pegawaiDokumen('A1', ['nama' => 'Dedi', 'jenis' => 1, 'lahir' => '1998-05-05', 'pangkat' => 'CPNS III/a', 'tmt_pangkat' => '2024-03-01', 'cpns' => '2024-03-01']);
        $this->pegawaiDokumen('A1', ['nama' => 'Eka', 'jenis' => 2, 'lahir' => '1995-01-01']);
        $this->pegawaiDokumen('A1', ['nama' => 'Hana', 'jenis' => 1, 'lahir' => '1980-01-01', 'status' => 2, 'pangkat' => 'IV/a']);
        $this->pegawaiDokumen('A2', ['nama' => 'Fajar', 'jenis' => 6, 'lahir' => '1990-01-01', 'gol_pppk' => 'V']);
        $this->pegawaiDokumen('B1', ['nama' => 'Gina', 'jenis' => 1, 'lahir' => '1992-02-02', 'pangkat' => 'II/a', 'tmt_pangkat' => '2015-04-01', 'kelas' => 9]);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    public function testDukHanyaPnsUrutGolonganTmtPangkat(): void
    {
        $data = $this->dataDokumen($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk'));

        $this->assertSame('Daftar Urut Kepangkatan', $data['judul']);
        $this->assertSame('kementerian', $data['lingkup']['tipe']);
        $this->assertSame(['Citra', 'Budi', 'Dedi', 'Gina'], $this->namaItems($data), 'IV/a → III/a → CPNS III/a (order 8) → II/a; PTT/PPPK/nonaktif tidak masuk');
        $this->assertSame(4, $data['total']);
        $this->assertSame(array_keys(KolomDokumen::KOLOM_LENGKAP), array_keys($data['kolom']));
        $this->assertSame(['no', ...array_keys(KolomDokumen::KOLOM_LENGKAP)], array_keys($data['items'][0]));
        $this->assertSame([1, 'IV/a', '01 Apr 2020', 'E.II', 'PKN II', 'S2'], [$data['items'][0]['no'], $data['items'][0]['gol_ruang'], $data['items'][0]['tmtsk_pangkat'], $data['items'][0]['eselon'], $data['items'][0]['diklat'], $data['items'][0]['strata']]);
        $this->assertSame('SLKS X', $data['items'][1]['tanda_jasa']);
        $this->assertSame(['2', '01 Jan 2024'], [substr($data['items'][1]['kgb_th'], 0, 1), KolomDokumen::tanggal('2024-01-01')]);
        $this->assertSame(TanggalBisnis::hariIni(), $data['periode']);
    }

    public function testNominatifSemuaAktifUrutUnitSatkerKelas(): void
    {
        $data = $this->dataDokumen($this->asRole(Role::PIMPINAN)->get(self::BASE . '/nominatif'));

        $this->assertSame(6, $data['total'], 'PNS + CPNS + PTT + PPPK aktif; nonaktif tidak');
        $nama = $this->namaItems($data);
        $this->assertSame('Gina', end($nama), 'Unit B terakhir (unit.order)');
        $this->assertSame(['Citra', 'Budi'], array_slice($nama, 0, 2), 'kelas 12 lalu 9 di satker A1');
        $this->assertSame('Fajar', $nama[4], 'satker A2 setelah A1');
        $this->assertContains('Eka', $nama);
    }

    public function testProyeksiPensiunRentangTahun(): void
    {
        $ini = $this->dataDokumen($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/pensiun'));
        $this->assertSame(['Citra'], $this->namaItems($ini), 'bawaan = tahun berjalan; BUP 60 Eselon II');
        $this->assertSame(['tahun' => $this->tahunIni, 'sampai' => $this->tahunIni], $ini['parameter']);
        $this->assertSame(array_keys(KolomDokumen::KOLOM_PENSIUN), array_keys($ini['kolom']));
        $this->assertSame('60', $ini['items'][0]['pensiun_th']);

        $dua = $this->dataDokumen($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/pensiun', ['tahun' => (string) $this->tahunIni, 'sampai' => (string) ($this->tahunIni + 1)]));
        $this->assertSame(['Citra', 'Budi'], $this->namaItems($dua), 'urut TMT pensiun; PPPK dan tanpa jabatan tidak masuk');
        $this->assertSame('58', $dua['items'][1]['pensiun_th']);

        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/pensiun', ['tahun' => (string) $this->tahunIni, 'sampai' => (string) ($this->tahunIni + 5)])->assertStatus(422);
        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/pensiun', ['tahun' => 'abcd'])->assertStatus(422);
    }

    public function testBaperjakatDaftarSemuaAktifDenganKolomLengkap(): void
    {
        $data = $this->dataDokumen($this->asRole(Role::ADMIN_VIEW_ESELON1)->get(self::BASE . '/baperjakat'));

        $this->assertSame(6, $data['total']);
        $this->assertSame(['Citra', 'Budi', 'Gina'], array_slice($this->namaItems($data), 0, 3), 'kelas 12 → 9 (order pangkat IV/a? tidak: kelas sama 9 → pangkat order III/a 9 > II/a 5)');
        $this->assertSame(array_keys(KolomDokumen::KOLOM_LENGKAP), array_keys($data['kolom']));
    }

    public function testPaginasiPencarianLingkupDanGalat(): void
    {
        $hal2 = $this->dataDokumen($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk', ['per_page' => '2', 'page' => '2']));
        $this->assertSame(['Dedi', 'Gina'], $this->namaItems($hal2));
        $this->assertSame([4, 2, 2, 3], [$hal2['total'], $hal2['page'], $hal2['per_page'], $hal2['items'][0]['no']]);

        $cari = $this->dataDokumen($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/nominatif', ['search' => 'gin']));
        $this->assertSame(['Gina'], $this->namaItems($cari));

        $unitA = $this->dataDokumen($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk', ['lingkup' => 'unit', 'id' => (string) $this->unit['A']]));
        $this->assertSame(['Citra', 'Budi', 'Dedi'], $this->namaItems($unitA));
        $this->assertSame(['tipe' => 'unit', 'id' => $this->unit['A'], 'nama' => 'Unit A'], $unitA['lingkup']);

        $satkerA2 = $this->dataDokumen($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/nominatif', ['lingkup' => 'satker', 'id' => (string) $this->satker['A2']]));
        $this->assertSame(['Fajar'], $this->namaItems($satkerA2));

        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/kp')->assertStatus(404);
        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk', ['lingkup' => 'unit', 'id' => '999999'])->assertStatus(404);
        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk', ['lingkup' => 'provinsi'])->assertStatus(422);
        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk', ['page' => '0'])->assertStatus(422);
        $r = $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk', ['search' => ['x']]);
        $r->assertStatus(422);
        $this->assertSame(ListQuery::INVALID_MESSAGE, $this->json($r)['message']);
    }

    /**
     * Tanggal lahir supaya TMT pensiun (BUP $bup) jatuh pada tahun ini + $geserTahun: lahir = awal tahun target −
     * $bup tahun − 1 bulan + aman di tengah bulan.
     */
    private function lahirBup(int $bup, int $geserTahun): string
    {
        $target = $this->tahunIni + $geserTahun;

        // TMT = tanggal 1 bulan sesudah bulan ulang tahun ke-BUP → lahir pada Mei (target − BUP) memberi TMT 1 Juni target.
        return sprintf('%04d-05-15', $target - $bup);
    }
}
