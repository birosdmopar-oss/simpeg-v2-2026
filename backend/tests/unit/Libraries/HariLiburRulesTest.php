<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Libraries\MasterData\HariLiburRules;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * DBV-003/CR-010 — aturan murni hari libur: format & validitas tanggal, rentang, dan overlap inklusif (legacy
 * L_presensi.php:20526: rentang bersebelahan tidak bentrok).
 *
 * @internal
 */
final class HariLiburRulesTest extends CIUnitTestCase
{
    public function testIsValidDate(): void
    {
        foreach (['2026-01-01', '2024-02-29', '1900-01-01', '2100-12-31', '2026-12-31'] as $valid) {
            $this->assertTrue(HariLiburRules::isValidDate($valid), $valid);
        }

        $invalid = [
            '2026-02-30', '2025-02-29', '2026-13-01', '2026-00-10', '2026-01-00', '2026-1-1', '2026-01-1', '', '31-12-2026',
            '2026/01/01', '1899-12-31', '2101-01-01', "2026-01-01\n", '2026-01-01 00:00:00', ' 2026-01-01', '20260-01-01',
        ];

        foreach ($invalid as $date) {
            $this->assertFalse(HariLiburRules::isValidDate($date), var_export($date, true));
        }
    }

    public function testValidRange(): void
    {
        $this->assertTrue(HariLiburRules::validRange('2026-01-01', '2026-01-01'));
        $this->assertTrue(HariLiburRules::validRange('2026-12-31', '2027-01-02'));
        $this->assertFalse(HariLiburRules::validRange('2026-01-02', '2026-01-01'));
    }

    public function testOverlapsIsInclusiveAndSymmetric(): void
    {
        [$mulai, $akhir] = ['2026-03-10', '2026-03-15'];

        $cases = [
            'sama persis'        => [['2026-03-10', '2026-03-15'], true],
            'di dalam'           => [['2026-03-11', '2026-03-12'], true],
            'melingkupi'         => [['2026-03-09', '2026-03-16'], true],
            'irisan kiri'        => [['2026-03-05', '2026-03-10'], true],
            'irisan kanan'       => [['2026-03-15', '2026-03-20'], true],
            'satu hari di awal'  => [['2026-03-10', '2026-03-10'], true],
            'satu hari di akhir' => [['2026-03-15', '2026-03-15'], true],
            'bersebelahan kiri'  => [['2026-03-05', '2026-03-09'], false],
            'bersebelahan kanan' => [['2026-03-16', '2026-03-20'], false],
            'terpisah'           => [['2026-04-01', '2026-04-02'], false],
        ];

        foreach ($cases as $name => [[$mulaiB, $akhirB], $expected]) {
            $this->assertSame($expected, HariLiburRules::overlaps($mulai, $akhir, $mulaiB, $akhirB), $name);
            $this->assertSame($expected, HariLiburRules::overlaps($mulaiB, $akhirB, $mulai, $akhir), "{$name} (dibalik)");
        }
    }
}
