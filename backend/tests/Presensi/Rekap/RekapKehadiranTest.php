<?php

declare(strict_types=1);

namespace Tests\Presensi\Rekap;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\RekapUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §7.4.1, AC-D09b.1/.5/.6 (MAKE-042): rekap kehadiran atas data DB — lingkup (role 1 `0`/unit/satker; role 3
 * dipaksa satker akun, parameter diabaikan; role 3 tanpa lingkup 403), kolom ringkas sesuai klasifikasi (TB [V2], cuti,
 * konket, TK, hadir, TL/PSW dengan jam `web_config`), filter jenis pegawai, validasi 422, paginasi, jumlah kueri tetap,
 * XLSX ringkas & harian dibaca ulang. September 2026 (1 Sep = Selasa).
 *
 * @internal
 */
final class RekapKehadiranTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use RekapUjiTrait;
    use TukinDataUjiTrait;

    private int $unit;

    private int $satkerA;

    private int $satkerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetWebConfig();
        $this->siapkanJenisPegawai();
        $this->unit    = $this->buatUnit();
        $this->satkerA = $this->buatSatker($this->unit);
        $this->satkerB = $this->buatSatker($this->unit);
    }

    protected function tearDown(): void
    {
        $this->resetWebConfig();
        parent::tearDown();
    }

    public function testKolomRingkasDanLingkup(): void
    {
        $a = $this->pegawaiRekap($this->satkerA);
        $b = $this->pegawaiRekap($this->satkerB);

        $this->hadir($a, '2026-09-01');
        $this->hadir($a, '2026-09-02', '08:31:00', '16:00:00'); // TL
        $this->hadir($a, '2026-09-03', '07:30:00', '15:00:00'); // PSW
        $this->cutiRekap($a, '2026-09-07', '2026-09-07');
        $this->konketRekap($a, '2026-09-08', 13, 2);
        $this->konketRekap($a, '2026-09-09', 10);
        $this->tbPalsu[$a] = ['2026-09-10'];

        $data = $this->serviceKehadiran()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $this->satkerA]);

        $this->assertSame(['tipe' => 'satker', 'id' => $this->satkerA, 'kode' => 'sat_' . $this->satkerA], array_intersect_key($data['lingkup'], ['tipe' => 1, 'id' => 1, 'kode' => 1]));
        $this->assertSame(1, $data['total']);
        $this->assertSame([
            'nip'       => $a, 'hari_kerja' => 22, 'hadir' => 4, 'tl' => 1, 'psw' => 1, 'tk' => 16, 'sakit' => 1, 'dinas_luar' => 0,
            'lain_lain' => 1, 'cuti' => 1, 'tidak_hadir' => 18, 'tb' => 1,
        ], array_diff_key($data['items'][0], ['nama' => 1, 'jabatan' => 1]));

        $this->assertSame(2, $this->serviceKehadiran()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'unit_satker' => (string) $this->unit])['total']);
        $this->assertSame(2, $this->serviceKehadiran()->daftar($this->adminRekap(Role::ADMIN_VIEW_ESELON1), ['bulan' => '2026-09'])['total'], 'bawaan 0 = kementerian');

        // Role 3: parameter diabaikan, dipaksa satker akun (tidak bocor).
        $r3 = $this->serviceKehadiran()->daftar($this->adminRekap(Role::ADMIN_SATKER, (string) $this->satkerB), ['bulan' => '2026-09', 'unit_satker' => '0']);
        $this->assertSame([$b], array_column($r3['items'], 'nip'));
        $this->assertSame(2, $this->serviceKehadiran()->daftar($this->adminRekap(Role::ADMIN_SATKER, null, (string) $this->unit), ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $this->satkerA])['total'], 'tanpa satker → unit akun');

        try {
            $this->serviceKehadiran()->daftar($this->adminRekap(Role::ADMIN_SATKER), ['bulan' => '2026-09']);
            $this->fail('role 3 tanpa lingkup harus 403');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function testPopulasiJenisPegawaiStatusDanJabatan(): void
    {
        $pns    = $this->pegawaiRekap($this->satkerA);
        $pppk   = $this->pegawaiRekap($this->satkerA, ['id_jenis_pegawai' => 6]);
        $baru   = $this->pegawaiRekap($this->satkerA, ['created_at' => '2026-10-02 08:00:00']);
        $keluar = $this->pegawaiRekap($this->satkerA, ['status' => 2, 'tmt_status' => '2026-09-15']);
        $lama   = $this->pegawaiRekap($this->satkerA, ['status' => 2, 'tmt_status' => '2026-08-01']);
        $pindah = $this->pegawaiRekap($this->satkerA, [], ['tmtsk' => '2015-01-01']);
        $this->buatRiwayatMutasiJabatan($pindah, ['tmtsk' => '2026-10-01', 'id_jabatan' => $this->buatJabatan($this->satkerB)['id_jabatan']]);

        $nip = static fn (array $d): array => array_column($d['items'], 'nip');
        $d   = $this->serviceKehadiran()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $this->satkerA]);
        sort($d['items']);

        $harap = [$pns, $keluar, $pindah];
        sort($harap);
        $dapat = $nip($d);
        sort($dapat);
        $this->assertSame($harap, $dapat, 'PNS saja (bawaan), status 2 dengan tmt_status > awal, created ≤ akhir, jabatan per akhir bulan');

        $this->assertSame([$pppk], $nip($this->serviceKehadiran()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $this->satkerA, 'jenis_pegawai' => ['6']])));
        $this->assertSame(4, $this->serviceKehadiran()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $this->satkerA, 'jenis_pegawai' => ['1', '6']])['total']);
        $this->assertNotContains($baru, $nip($this->serviceKehadiran()->daftar($this->adminRekap(), ['bulan' => '2026-09'])));
        $this->assertNotContains($lama, $nip($this->serviceKehadiran()->daftar($this->adminRekap(), ['bulan' => '2026-09'])));
    }

    /**
     * Keputusan Horii 28-10-2026 (ikut legacy): presensi pegawai yang dibuat setelah awal bulan tidak dibaca — ringkas
     * `DATE(created_at) <= awal` (`L_presensi.php:599`), grid harian `created_at < awal` (`:1053`); pegawai tetap di
     * populasi, seluruh hari kerja TK. Rekap uang makan tetap menghitung pegawai baru (F5B-2).
     */
    public function testPresensiPegawaiBaruTidakDibacaIkutLegacy(): void
    {
        $baru = $this->pegawaiRekap($this->satkerA, ['created_at' => '2026-09-15 08:00:00']);
        $tgl1 = $this->pegawaiRekap($this->satkerA, ['created_at' => '2026-09-01 08:00:00']);
        $lama = $this->pegawaiRekap($this->satkerA, ['created_at' => '2026-08-31 23:59:59']);

        foreach ([$baru, $tgl1, $lama] as $n) {
            $this->hadir($n, '2026-09-16');
        }

        $item = [];

        foreach ($this->serviceKehadiran()->daftar($this->adminRekap(), ['bulan' => '2026-09'])['items'] as $i) {
            $item[$i['nip']] = [$i['hadir'], $i['tk']];
        }

        $this->assertSame([0, 22], $item[$baru], 'dibuat 15 Sep → TK sebulan');
        $this->assertSame([1, 21], $item[$tgl1], 'ringkas: DATE(created_at) <= tanggal 1 → dibaca');
        $this->assertSame([1, 21], $item[$lama]);

        $grid = [];

        foreach ($this->baca($this->serviceKehadiran()->xlsxHarian($this->adminRekap(), ['bulan' => '2026-09'])['isi']) as $baris) {
            $grid[(string) ($baris[2] ?? '')] = $baris[5 + 15] ?? null;
        }

        $this->assertSame(['TK', 'TK', 'H'], [$grid[$baru], $grid[$tgl1], $grid[$lama]], 'harian: created_at < tanggal 1 (tanggal 1 jam 08:00 tidak dibaca)');

        $uangMakan = $this->serviceUangMakan()->daftar($this->adminRekap(), ['bulan' => '2026-09']);
        $this->assertSame(3, $uangMakan['total'], 'uang makan (F5B-2) tetap menghitung pegawai baru');
    }

    public function testValidasi422(): void
    {
        foreach ([
            [['bulan' => '2026-9'], 'bulan'], [[], 'bulan'], [['bulan'                        => '2026-11'], 'bulan'],
            [['bulan' => '2026-09', 'unit_satker' => 'sat_999999'], 'unit_satker'], [['bulan' => '2026-09', 'unit_satker' => 'abc'], 'unit_satker'],
            [['bulan' => '2026-09', 'jenis_pegawai' => ['99']], 'jenis_pegawai'], [['bulan'   => '2026-09', 'jenis_pegawai' => ['x']], 'jenis_pegawai'],
        ] as [$query, $field]) {
            try {
                $this->serviceKehadiran()->daftar($this->adminRekap(), $query);
                $this->fail('422 ' . $field);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->getErrors(), json_encode($query));
            }
        }
    }

    public function testJamWebConfigDanPuasaBerlaku(): void
    {
        $a = $this->pegawaiRekap($this->satkerA);
        $this->hadir($a, '2026-09-01', '07:45:00', '16:00:00');

        $saring = fn (): int => $this->serviceKehadiran()->daftar($this->adminRekap(), ['bulan' => '2026-09'])['items'][0]['tl'];
        $this->assertSame(1, $saring());

        $this->aturWebConfig(['jam_masuk_normal' => '08:00:00']);
        $this->assertSame(0, $saring(), 'ubah jam masuk lewat WebConfigService langsung berlaku');

        $this->aturWebConfig(['jam_masuk_normal' => '07:30:00', 'rentang_puasa' => '2026-09-01..2026-09-05']);
        $this->assertSame(0, $saring(), 'puasa: masuk 08:00 dari web_config');
    }

    public function testPaginasiDanJumlahKueriTetap(): void
    {
        $nip = [];

        for ($i = 0; $i < 7; $i++) {
            $nip[] = $n = $this->pegawaiRekap($this->satkerA);
            $this->hadir($n, '2026-09-01');
            $this->cutiRekap($n, '2026-09-02', '2026-09-02');
            $this->konketRekap($n, '2026-09-03', 8);
        }

        $s = $this->serviceKehadiran();
        $s->daftar($this->adminRekap(), ['bulan' => '2026-09', 'per_page' => '2']); // pemanasan cache

        $halaman2 = $s->daftar($this->adminRekap(), ['bulan' => '2026-09', 'per_page' => '3', 'page' => '2']);
        $this->assertSame([7, 3], [$halaman2['total'], count($halaman2['items'])]);

        $kecil = $this->hitungSelect(fn () => $s->xlsx($this->adminRekap(), ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $this->satkerA]));
        for ($i = 0; $i < 5; $i++) {
            $this->pegawaiRekap($this->satkerA);
        }
        $besar = $this->hitungSelect(fn () => $s->xlsx($this->adminRekap(), ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $this->satkerA]));
        $this->assertSame($kecil, $besar, 'jumlah kueri tidak tumbuh dengan jumlah pegawai');
    }

    public function testXlsxRingkasDanHarianDibacaUlang(): void
    {
        $a = $this->pegawaiRekap($this->satkerA, ['nip' => '199001012020121001']);
        $this->hadir($a, '2026-09-01');
        $this->cutiRekap($a, '2026-09-02', '2026-09-02');
        $this->konketRekap($a, '2026-09-03', 8);
        $this->konketRekap($a, '2026-09-04', 13);
        $this->konketRekap($a, '2026-09-08', 10);
        $this->tbPalsu[$a] = ['2026-09-09'];
        $this->db->table('hari_libur')->insert(['tgl_mulai' => '2026-09-10', 'tgl_akhir' => '2026-09-10', 'nama_libur' => 'Libur uji', 'status' => 1]);
        $query = ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $this->satkerA];

        $ringkas = $this->serviceKehadiran()->xlsx($this->adminRekap(), $query);
        $this->assertSame('rekap_kehadiran_sat_' . $this->satkerA . '_2026-09.xlsx', $ringkas['nama']);
        $sheet = $this->baca($ringkas['isi']);
        $this->assertSame(['NO', 'NAMA', 'NIP', 'JABATAN', 'HARI KERJA', 'HADIR', 'TL', 'PSW', 'TK', 'SAKIT', 'DINAS LUAR', 'LAIN LAIN', 'CUTI', 'TIDAK HADIR', 'TB'], $sheet[4]);
        $this->assertSame('Rekap Presensi Pegawai Negeri Sipil', $sheet[0][0]);
        $this->assertSame('Periode: September 2026', $sheet[2][0]);
        $this->assertSame(['1', '199001012020121001', '21', '2', '0', '0', '16', '1', '1', '1', '1', '19', '1'], array_map('strval', [$sheet[5][0], $sheet[5][2], ...array_slice($sheet[5], 4)]));

        $harian = $this->serviceKehadiran()->xlsxHarian($this->adminRekap(), $query);
        $this->assertSame('rekap_kehadiran_harian_sat_' . $this->satkerA . '_2026-09.xlsx', $harian['nama']);
        $grid = $this->baca($harian['isi']);
        $this->assertStringStartsWith("LAPORAN KEHADIRAN PEGAWAI NEGERI SIPIL\n", (string) $grid[0][0]);
        $this->assertSame(['NO', 'NAMA', 'NIP', 'UNIT ES.I', 'UNIT ES.II', 'TANGGAL'], array_slice($grid[6], 0, 6));
        $this->assertSame('KETERANGAN', $grid[6][35]);
        $this->assertSame(range(1, 30), array_map('intval', array_slice($grid[7], 5, 30)));
        $this->assertSame(['H', 'CT', 'DL', 'LL', null, null, 'TK', 'SKT', 'TB', null, 'TK'], array_slice($grid[8], 5, 11), '5–6 akhir pekan & 10 libur kosong');
        $this->assertSame('199001012020121001', $grid[8][2]);
        $this->assertNull($grid[8][35], 'KETERANGAN kosong [K]');
    }

    /**
     * @return list<list<mixed>>
     */
    private function baca(string $isi): array
    {
        $berkas = tempnam(sys_get_temp_dir(), 'rkp');
        file_put_contents((string) $berkas, $isi);

        try {
            return IOFactory::load((string) $berkas)->getActiveSheet()->toArray(null, false, false, false);
        } finally {
            @unlink((string) $berkas);
        }
    }

    /**
     * @param callable(): mixed $aksi
     */
    private function hitungSelect(callable $aksi): int
    {
        $jumlah   = 0;
        $pencatat = static function (Query $q) use (&$jumlah): void {
            if (stripos(ltrim($q->getQuery()), 'SELECT') === 0) {
                $jumlah++;
            }
        };
        Events::on('DBQuery', $pencatat);

        try {
            $aksi();
        } finally {
            Events::removeListener('DBQuery', $pencatat);
        }

        return $jumlah;
    }
}
