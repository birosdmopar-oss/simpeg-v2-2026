<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi\Klien;

use App\Libraries\Integrasi\Klien\AllowlistIp;
use App\Libraries\Integrasi\Klien\HakKlien;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MAKE-058 — aturan murni allowlist IP (DBV-026-Q2, AC-F.3) dan jenis ↔ hak (DBV-026 §13.2 A3, audit B4).
 *
 * @internal
 */
final class AllowlistIpDanHakKlienTest extends CIUnitTestCase
{
    public function testNullBerartiSemuaIpTetapiKosongTidakPernah(): void
    {
        $this->assertNull(AllowlistIp::normalisasi(null));
        $this->assertTrue(AllowlistIp::cocok('203.0.113.7', null));
        $this->assertFalse(AllowlistIp::cocok('203.0.113.7', ''), 'string kosong gagal tertutup');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function masukanTidakSah(): array
    {
        return [
            'kosong'           => [''],
            'spasi saja'       => ['   '],
            'entri kosong'     => ['203.0.113.1,,203.0.113.2'],
            'koma ujung'       => ['203.0.113.1,'],
            'bukan ip'         => ['server.contoh.test'],
            'oktet 256'        => ['203.0.113.256'],
            'prefiks 33'       => ['203.0.113.0/33'],
            'prefiks 129'      => ['2001:db8::/129'],
            'prefiks kosong'   => ['203.0.113.0/'],
            'prefiks negatif'  => ['203.0.113.0/-1'],
            'prefiks huruf'    => ['203.0.113.0/ab'],
            'prefiks nol awal' => ['203.0.113.0/08'],
            'terlalu panjang'  => [implode(',', array_map(static fn (int $i): string => '10.' . intdiv($i, 250) . '.' . ($i % 250) . '.0/24', range(1, 120)))],
        ];
    }

    #[DataProvider('masukanTidakSah')]
    public function testMasukanTidakSahDitolak(string $mentah): void
    {
        $this->expectException(InvalidArgumentException::class);
        AllowlistIp::normalisasi($mentah);
    }

    public function testNormalisasiTanpaSpasiTanpaGandaDanKanonik(): void
    {
        $this->assertSame('203.0.113.1,10.0.0.0/8,2001:db8::1,2001:db8::/32', AllowlistIp::normalisasi(' 203.0.113.1 , 10.0.0.0/8, 203.0.113.1 ,2001:0DB8:0:0:0:0:0:1,2001:DB8::/32 '));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function kecocokan(): array
    {
        return [
            'ipv4 persis cocok'               => ['203.0.113.7', '203.0.113.7', true],
            'ipv4 persis beda'                => ['203.0.113.8', '203.0.113.7', false],
            'cidr /24 dalam'                  => ['203.0.113.200', '203.0.113.0/24', true],
            'cidr /24 luar'                   => ['203.0.114.1', '203.0.113.0/24', false],
            'cidr /20 batas bawah'            => ['203.0.112.0', '203.0.112.0/20', true],
            'cidr /20 batas atas'             => ['203.0.127.255', '203.0.112.0/20', true],
            'cidr /20 di luar'                => ['203.0.128.0', '203.0.112.0/20', false],
            'cidr /0 semua ipv4'              => ['198.51.100.9', '0.0.0.0/0', true],
            'cidr /32 satu alamat'            => ['203.0.113.7', '203.0.113.7/32', true],
            'ipv6 persis'                     => ['2001:db8::1', '2001:db8::1', true],
            'ipv6 ditulis berbeda'            => ['2001:0db8:0:0:0:0:0:1', '2001:db8::1', true],
            'ipv6 cidr /32 dalam'             => ['2001:db8:ffff::1', '2001:db8::/32', true],
            'ipv6 cidr /32 luar'              => ['2001:db9::1', '2001:db8::/32', false],
            'ipv6 tidak cocok allowlist ipv4' => ['2001:db8::1', '203.0.113.0/24', false],
            'ipv4 tidak cocok allowlist ipv6' => ['203.0.113.7', '2001:db8::/32', false],
            'salah satu dari beberapa'        => ['10.1.2.3', '203.0.113.7,10.0.0.0/8', true],
            'ip klien tidak sah'              => ['bukan-ip', '203.0.113.0/24', false],
            'ip klien kosong'                 => ['', '203.0.113.0/24', false],
            'klien ipv4-mapped vs entri ipv4' => ['::ffff:203.0.113.7', '203.0.113.0/24', true],
            'klien ipv4-mapped di luar'       => ['::ffff:198.51.100.7', '203.0.113.0/24', false],
            'entri ipv4-mapped cidr'          => ['203.0.113.7', '::ffff:203.0.113.0/120', true],
            'entri ipv4-mapped persis'        => ['203.0.113.7', '::ffff:203.0.113.7', true],
        ];
    }

    #[DataProvider('kecocokan')]
    public function testKecocokan(string $ip, string $allowlist, bool $harapan): void
    {
        $this->assertSame($harapan, AllowlistIp::cocok($ip, AllowlistIp::normalisasi($allowlist)), "{$ip} vs {$allowlist}");
    }

    public function testEntriTersimpanRusakGagalTertutup(): void
    {
        foreach (['10.0.0.0/', '10.0.0.0/x', '10.0.0.0/-1', '10.0.0.0/99', '0.0.0.0/', '/8', '', ' , '] as $rusak) {
            $this->assertFalse(AllowlistIp::cocok('10.1.2.3', $rusak), "entri rusak [{$rusak}] tidak boleh meloloskan siapa pun");
        }

        $this->assertTrue(AllowlistIp::cocok('10.1.2.3', ',10.0.0.0/8'), 'entri kosong dilewati, entri sah tetap berlaku');
    }

    public function testUraiHakUrutanSetTanpaGanda(): void
    {
        $this->assertSame(['simapi', 'stat', 'sinkron'], HakKlien::urai('sinkron, STAT,simapi,stat'));
        $this->assertSame(['dm'], HakKlien::urai('dm'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hakTidakSah(): array
    {
        return ['tak dikenal' => ['simapi,admin'], 'kosong' => [''], 'entri kosong' => ['stat,,dm'], 'spasi' => ['  ']];
    }

    #[DataProvider('hakTidakSah')]
    public function testUraiHakTidakSahDitolak(string $mentah): void
    {
        $this->expectException(InvalidArgumentException::class);
        HakKlien::urai($mentah);
    }

    public function testJenisHakCocokB4(): void
    {
        foreach ([[1, ['simapi']], [2, ['simapi', 'stat']], [3, ['stat', 'dm']], [3, ['esign']]] as [$jenis, $hak]) {
            HakKlien::periksa($jenis, $hak);
            $this->addToAssertionCount(1);
        }

        foreach ([[1, ['stat']], [2, []], [3, ['simapi']], [3, ['simapi', 'dm']], [4, ['simapi']], [0, []]] as [$jenis, $hak]) {
            try {
                HakKlien::periksa($jenis, $hak);
                $this->fail("jenis {$jenis} dengan hak " . implode(',', $hak) . ' harus ditolak');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDariKolom(): void
    {
        $this->assertSame([], HakKlien::dariKolom(''));
        $this->assertSame(['simapi', 'stat'], HakKlien::dariKolom('simapi,stat'));
    }
}
