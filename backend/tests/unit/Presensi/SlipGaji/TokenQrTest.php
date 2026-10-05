<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\SlipGaji;

use App\Libraries\Presensi\SlipGaji\TokenQr;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * CR-027 (Q-1, Q-3) — format token QR 64 heksadesimal huruf kecil dan pembentukan dari 32 byte acak milik service.
 *
 * @internal
 */
final class TokenQrTest extends CIUnitTestCase
{
    public function testTokenValid(): void
    {
        $this->assertTrue(TokenQr::valid(str_repeat('a', 64)));
        $this->assertTrue(TokenQr::valid(str_repeat('0123456789abcdef', 4)));

        foreach ([str_repeat('A', 64), str_repeat('a', 63), str_repeat('a', 65), 'no-token', '', str_repeat('a', 64) . "\n", ' ' . str_repeat('a', 63), str_repeat('g', 64)] as $token) {
            $this->assertFalse(TokenQr::valid($token), var_export($token, true));
        }
    }

    public function testTokenDariByteAcak(): void
    {
        $this->assertSame(str_repeat('0', 64), TokenQr::dariByteAcak(str_repeat("\x00", 32)));
        $this->assertSame(str_repeat('ab', 32), TokenQr::dariByteAcak(str_repeat("\xAB", 32)));
        $this->assertTrue(TokenQr::valid(TokenQr::dariByteAcak(str_repeat("\xFF", 32))));

        foreach ([31, 33, 0] as $panjang) {
            try {
                TokenQr::dariByteAcak(str_repeat("\x01", $panjang));
                $this->fail("Tidak melempar untuk {$panjang} byte");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString((string) $panjang, $e->getMessage());
            }
        }
    }
}
