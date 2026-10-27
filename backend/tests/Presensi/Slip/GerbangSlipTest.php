<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Exceptions\ApiException;
use App\Libraries\Presensi\Slip\SlipGajiService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\SlipUjiTrait;

/**
 * PRD 5A §7.3.1 (AC-D11.1): gerbang rilis slip — tanggal 4 pukul 09:59:59 WIB periode berjalan belum ada di daftar dan
 * status/buka/pdf → 422 `periode_belum_rilis`; pukul 10:00:00 WIB tersedia; lintas bulan & tahun; awal Januari 2024;
 * `$sekarang` ber-zona selain WIB tetap dinilai WIB; format periode salah / sebelum awal → 422 per kode.
 *
 * @internal
 */
final class GerbangSlipTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SlipUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSlip();
    }

    protected function tearDown(): void
    {
        $this->bersihkanSlip();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function ambang(): iterable
    {
        yield 'Oktober sebelum' => ['2026-10-04 09:59:59', 'Asia/Jakarta', '2026-10', false];
        yield 'Oktober tepat' => ['2026-10-04 10:00:00', 'Asia/Jakarta', '2026-10', true];
        yield 'Januari lintas tahun sebelum' => ['2027-01-04 09:59:59', 'Asia/Jakarta', '2027-01', false];
        yield 'Januari lintas tahun tepat' => ['2027-01-04 10:00:00', 'Asia/Jakarta', '2027-01', true];
        yield 'Januari 2024 tepat' => ['2024-01-04 10:00:00', 'Asia/Jakarta', '2024-01', true];
        yield 'UTC 02:59:59 = 09:59:59 WIB' => ['2026-10-04 02:59:59', 'UTC', '2026-10', false];
        yield 'UTC 03:00:00 = 10:00 WIB' => ['2026-10-04 03:00:00', 'UTC', '2026-10', true];
        yield 'WIT tetap dinilai WIB' => ['2026-10-04 11:30:00', 'Asia/Jayapura', '2026-10', false];
    }

    #[DataProvider('ambang')]
    public function testAmbangRilis(string $waktu, string $zona, string $periode, bool $tersedia): void
    {
        $nip             = $this->pegawaiSlip()['nip'];
        [$tahun, $bulan] = array_map('intval', explode('-', $periode));
        $this->slip($nip, $tahun, $bulan);

        $slip = new SlipGajiService(null, null, static fn (): DateTimeImmutable => new DateTimeImmutable($waktu, new DateTimeZone($zona)));
        $auth = $this->authPegawaiSlip($nip);

        $this->assertSame($tersedia, in_array($periode, array_column($slip->daftar($auth)['items'], 'periode'), true), 'daftar');

        foreach ([fn () => $slip->status($auth, $periode), fn () => $slip->buka($auth, $periode, '203.0.113.1')] as $aksi) {
            try {
                $aksi();
                $this->assertTrue($tersedia, 'seharusnya ditolak gerbang');
            } catch (ApiException $e) {
                $this->assertFalse($tersedia, $e->getMessage());
                $this->assertSame([422, 'periode_belum_rilis'], [$e->getStatusCode(), $e->getKode()]);
            }
        }

        if (! $tersedia) {
            try {
                $slip->pdf($auth, $periode);
                $this->fail('pdf belum rilis');
            } catch (ApiException $e) {
                $this->assertSame('periode_belum_rilis', $e->getKode());
            }
        }
    }

    public function testPeriodeTidakSahDanTidakAda(): void
    {
        $nip  = $this->pegawaiSlip()['nip'];
        $slip = $this->slipPegawai('2026-10-27 09:00:00');
        $auth = $this->authPegawaiSlip($nip);

        foreach (['' => 'periode_wajib', '2026-13' => 'periode_tidak_valid', '2023-12' => 'periode_sebelum_awal'] as $teks => $kode) {
            try {
                $slip->status($auth, $teks);
                $this->fail($teks);
            } catch (ApiException $e) {
                $this->assertSame([422, $kode], [$e->getStatusCode(), $e->getKode()], $teks);
                $this->assertArrayHasKey('periode', (array) $e->getErrors());
            }
        }

        try {
            $slip->status($auth, '2026-08');
            $this->fail('tidak ada baris');
        } catch (ApiException $e) {
            $this->assertSame([404, 'slip_tidak_ditemukan', 'Slip gaji periode Agustus 2026 belum tersedia.'], [$e->getStatusCode(), $e->getKode(), $e->getMessage()]);
        }

        $daftar = $slip->daftar($auth);
        $this->assertSame('2026-10', $daftar['periode_terbaru']);
        $this->assertSame('2026-10', $daftar['items'][0]['periode'], 'urut terbaru dulu');
        $this->assertSame('2024-01', $daftar['items'][count($daftar['items']) - 1]['periode']);
        $this->assertFalse($daftar['items'][0]['ada_data']);
    }
}
