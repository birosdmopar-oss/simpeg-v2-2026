<?php

declare(strict_types=1);

namespace Tests\Presensi\Kehadiran;

use App\Exceptions\ApiException;
use App\Libraries\Presensi\Kehadiran\KodeGalatPresensi;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\PresensiUjiTrait;

/**
 * PRD 5A §7.5.5 (AC-D02.4): jendela waktu inklusif, waktu disuntik — masuk 03:59:59 ditolak / 04:00:00 diterima,
 * 12:30:59 diterima / 12:31:00 ditolak; pulang 12:30:59 ditolak / 12:31:00 diterima, 23:59:59 diterima. Pesan baku.
 *
 * @internal
 */
final class PresensiJendelaTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use PresensiUjiTrait;

    /**
     * @return iterable<string, array{0: bool, 1: string, 2: bool}>
     */
    public static function batas(): iterable
    {
        yield 'masuk 03:59:59' => [true, '03:59:59', false];
        yield 'masuk 04:00:00' => [true, '04:00:00', true];
        yield 'masuk 12:30:59' => [true, '12:30:59', true];
        yield 'masuk 12:31:00' => [true, '12:31:00', false];
        yield 'pulang 12:30:59' => [false, '12:30:59', false];
        yield 'pulang 12:31:00' => [false, '12:31:00', true];
        yield 'pulang 23:59:59' => [false, '23:59:59', true];
        yield 'pulang 00:00:00' => [false, '00:00:00', false];
    }

    #[DataProvider('batas')]
    public function testBatasJendela(bool $masuk, string $jam, bool $diterima): void
    {
        $nip     = $this->pegawaiPresensi()['nip'];
        $service = $this->online("2026-10-27 {$jam}");

        try {
            [$status] = $masuk ? $service->masuk($this->authPegawai($nip), []) : $service->pulang($this->authPegawai($nip), []);
            $this->assertTrue($diterima, "{$jam} seharusnya ditolak");
            $this->assertSame(201, $status);
        } catch (ApiException $e) {
            $this->assertFalse($diterima, "{$jam} seharusnya diterima: " . $e->getMessage());
            $this->assertSame([422, KodeGalatPresensi::DI_LUAR_JENDELA], [$e->getStatusCode(), $e->getKode()]);
            $this->assertSame(
                $masuk ? 'Presensi masuk hanya dapat direkam pukul 04:00–12:30 waktu setempat.' : 'Presensi pulang hanya dapat direkam pukul 12:31–23:59 waktu setempat.',
                $e->getErrors()['jam'][0] ?? null,
            );
            $this->assertSame(0, $this->db->table('dh_online')->countAllResults());
        }
    }
}
