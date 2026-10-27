<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\Pmk;

use App\Libraries\Layanan\Pmk\AturanPmk;
use App\Libraries\Layanan\Pmk\MasaKerjaPmk;
use App\Libraries\Layanan\Pmk\StatusPmk;
use App\Libraries\Layanan\Pmk\TransisiPmk;
use App\Libraries\Layanan\Pmk\ValidasiPmk;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MAKE-024 — PMK murni (PRD Fase 4 v1.1 §6.9, D16, Q-15 P-3/P-5, Q-16, Q-23): kalkulator `mker` [K `Local.php:4574-4601`],
 * validasi pengajuan, state machine 0 → 3 → {1, 2} / 0 → {1, 2} dengan final terkunci, single end node.
 *
 * @internal
 */
final class PmkMurniTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: int}>
     */
    public static function mker(): array
    {
        return [
            '01-01-2024 s.d. 31-12-2025 [K]'          => ['2024-01-01', '2025-12-31', 1, 11],
            'kabisat 29-02 s.d. 28-02 [K]'            => ['2024-02-29', '2025-02-28', 0, 11],
            'sebulan kurang sehari [K] 0/0'           => ['2024-03-01', '2024-03-31', 0, 0],
            'tepat sebulan'                           => ['2024-03-01', '2024-04-01', 0, 1],
            'akhir bulan 31-01 s.d. 29-02'            => ['2024-01-31', '2024-02-29', 0, 0],
            'lintas tahun 15-11-2019 s.d. 2023-01-14' => ['2019-11-15', '2023-01-14', 3, 1],
        ];
    }

    #[DataProvider('mker')]
    public function testMasaKerja(string $awal, string $akhir, int $th, int $bl): void
    {
        $this->assertSame(['mker_th' => $th, 'mker_bl' => $bl], MasaKerjaPmk::hitung($awal, $akhir));
        $this->assertSame($th === 0 && $bl === 0, MasaKerjaPmk::kurangSebulan($th, $bl));
    }

    /**
     * @return array<string, string>
     */
    private static function sah(array $timpa = []): array
    {
        return $timpa + ['jenis_pmk' => 'Swasta', 'instansi' => 'PT Uji', 'tgl_awal' => '2010-01-01', 'tgl_akhir' => '2014-12-31', 'mker_th' => '4', 'mker_bl' => '11'];
    }

    public function testValidasiPengajuan(): void
    {
        $a = AturanPmk::bawaan();

        $this->assertSame([], ValidasiPmk::periksa(self::sah(), '2015-03-01', $a));
        $this->assertSame([], ValidasiPmk::periksa(self::sah(['mker_th' => '0', 'mker_bl' => '0']), null, $a), '"0" lolos [K]; TMT tak diketahui tidak dicek');

        $this->assertSame([
            'jenis_pmk' => ValidasiPmk::PESAN_JENIS_WAJIB,
            'instansi'  => ValidasiPmk::PESAN_INSTANSI_WAJIB,
            'tgl_awal'  => 'Tanggal awal wajib diisi.',
            'tgl_akhir' => 'Tanggal akhir wajib diisi.',
            'mker_th'   => 'Masa kerja (th.) wajib diisi.',
            'mker_bl'   => 'Masa kerja (bl.) wajib diisi.',
        ], ValidasiPmk::periksa([], '2015-03-01', $a));

        $this->assertSame(['jenis_pmk' => ValidasiPmk::PESAN_JENIS_TIDAK_VALID], ValidasiPmk::periksa(self::sah(['jenis_pmk' => 'swasta']), null, $a), 'enum peka huruf [V2 P-5]');
        $this->assertSame(['tgl_akhir' => ValidasiPmk::PESAN_URUTAN], ValidasiPmk::periksa(self::sah(['tgl_akhir' => '2009-12-31']), null, $a));

        foreach (['-1', '1.5', '256', 'x'] as $nilai) {
            $this->assertArrayHasKey('mker_bl', ValidasiPmk::periksa(self::sah(['mker_bl' => $nilai]), null, $a), $nilai);
        }
    }

    public function testBatasTmtCpns(): void
    {
        $a = AturanPmk::bawaan();

        // [V2 P-3] periode harus berakhir sebelum TMT CPNS: akhir = TMT − 1 boleh, akhir = TMT ditolak.
        $this->assertSame([], ValidasiPmk::periksa(self::sah(['tgl_akhir' => '2015-02-28']), '2015-03-01', $a));
        $errors = ValidasiPmk::periksa(self::sah(['tgl_akhir' => '2015-03-01']), '2015-03-01', $a);
        $this->assertSame(['tgl_akhir' => ValidasiPmk::pesanTmtCpns('2015-03-01')], $errors);
        $this->assertStringContainsString('beririsan dengan TMT CPNS (', $errors['tgl_akhir']);

        // Legacy hanya menolak bila tgl_awal > TMT; v2 juga menolak periode yang melewati TMT.
        $this->assertArrayHasKey('tgl_akhir', ValidasiPmk::periksa(self::sah(['tgl_awal' => '2014-01-01', 'tgl_akhir' => '2016-01-01']), '2015-03-01', $a));
    }

    public function testTumpangTindihSemuaStatusKecualiDitolak(): void
    {
        $lain = [
            ['id' => 1, 'tgl_awal' => '2012-01-01', 'tgl_akhir' => '2013-01-01', 'status' => 2],
            ['id' => 2, 'tgl_awal' => '2014-12-31', 'tgl_akhir' => '2015-06-30', 'status' => 1],
        ];

        $this->assertSame(2, ValidasiPmk::bentrok('2010-01-01', '2014-12-31', $lain)['id'] ?? null, 'ujung bersentuhan, status 1 dihitung');
        $this->assertNull(ValidasiPmk::bentrok('2010-01-01', '2014-12-31', $lain, 2));
        $this->assertNull(ValidasiPmk::bentrok('2012-06-01', '2012-07-01', $lain), 'ditolak (2) tidak dihitung');

        foreach ([0, 3] as $status) {
            $this->assertNotNull(ValidasiPmk::bentrok('2012-06-01', '2012-07-01', [['tgl_awal' => '2012-01-01', 'tgl_akhir' => '2013-01-01', 'status' => $status]]));
        }
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: int|null}> dari, aksi, ke (null = errors.status)
     */
    public static function transisi(): array
    {
        return [
            '0 proses → 3'     => [0, TransisiPmk::AKSI_PROSES, 3],
            '0 setujui → 1'    => [0, TransisiPmk::AKSI_SETUJUI, 1],
            '0 tolak → 2'      => [0, TransisiPmk::AKSI_TOLAK, 2],
            '3 setujui → 1'    => [3, TransisiPmk::AKSI_SETUJUI, 1],
            '3 tolak → 2'      => [3, TransisiPmk::AKSI_TOLAK, 2],
            '3 proses ditolak' => [3, TransisiPmk::AKSI_PROSES, null],
            '1 final'          => [1, TransisiPmk::AKSI_TOLAK, null],
            '2 final'          => [2, TransisiPmk::AKSI_SETUJUI, null],
        ];
    }

    #[DataProvider('transisi')]
    public function testTransisi(int $dari, string $aksi, ?int $ke): void
    {
        $hasil = TransisiPmk::proses($dari, $aksi, 'alasan', AturanPmk::bawaan());

        if ($ke === null) {
            $this->assertSame(['status', TransisiPmk::PESAN_STATUS], [$hasil->kode, $hasil->pesan]);

            return;
        }

        $this->assertTrue($hasil->valid);
        $this->assertSame($ke, $hasil->detail['status']);
        $this->assertSame(StatusPmk::final($ke), $hasil->detail['final']);
        $this->assertSame(1, $hasil->detail['show_notif']);
        $this->assertSame($aksi === TransisiPmk::AKSI_TOLAK ? 'alasan' : null, $hasil->detail['reason_note']);
    }

    public function testGalatAksiDanAlasan(): void
    {
        $a = AturanPmk::bawaan();

        $this->assertSame('aksi', TransisiPmk::proses(0, 'tangguhkan', null, $a)->kode);
        $this->assertSame(['reason_note', TransisiPmk::PESAN_REASON_WAJIB], [TransisiPmk::proses(3, TransisiPmk::AKSI_TOLAK, ' ', $a)->kode, TransisiPmk::PESAN_REASON_WAJIB]);
        $this->assertSame('reason_note', TransisiPmk::proses(0, TransisiPmk::AKSI_TOLAK, str_repeat('x', 256), $a)->kode);
        $this->assertTrue(TransisiPmk::proses(0, TransisiPmk::AKSI_TOLAK, str_repeat('x', 255), $a)->valid);
    }

    /**
     * Single end node (AC-09.3): BFS semua urutan aksi sah dari 0 berakhir di {1, 2} dalam ≤ 2 keputusan; setiap status
     * non-final punya aksi; status final tanpa aksi.
     */
    public function testSingleEndNode(): void
    {
        $antrean = [[0, 0]];
        $akhir   = [];

        while ($antrean !== []) {
            [$status, $kedalaman] = array_shift($antrean);

            if (StatusPmk::final($status)) {
                $this->assertLessThanOrEqual(2, $kedalaman);
                $this->assertSame([], TransisiPmk::aksiTersedia($status));
                $akhir[] = $status;

                continue;
            }

            $this->assertNotSame([], TransisiPmk::aksiTersedia($status), "status {$status} tanpa aksi");

            foreach (TransisiPmk::aksiTersedia($status) as $aksi) {
                $hasil = TransisiPmk::proses($status, $aksi, 'alasan', AturanPmk::bawaan());
                $this->assertTrue($hasil->valid);
                $antrean[] = [$hasil->detail['status'], $kedalaman + 1];
            }
        }

        $this->assertEqualsCanonicalizing([1, 1, 2, 2], $akhir, 'empat jalur: 0→1, 0→2, 0→3→1, 0→3→2');
    }

    public function testStatusPredikatDanLabel(): void
    {
        $this->assertTrue(StatusPmk::detailBolehDibuka(0), 'Q-16 [V2]');
        $this->assertFalse(StatusPmk::bolehHapus(StatusPmk::SELESAI));
        $this->assertTrue(StatusPmk::bolehHapus(StatusPmk::PROSES));
        $this->assertSame('Menunggu PIC', StatusPmk::labelUi(0));
        $this->assertSame('Proses', StatusPmk::label(3));
        $this->assertSame('Diproses', StatusPmk::labelUi(3));
    }
}
