<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Libraries\Html\HtmlSanitizer;
use App\Libraries\MasterData\WebConfigImport;
use App\Libraries\MasterData\WebConfigTypes as T;
use CodeIgniter\Test\CIUnitTestCase;
use Config\WebConfig;
use InvalidArgumentException;
use LogicException;

/**
 * DBV-006/CR-030 — unit test tipe data `web_config` (DoD G-09 / Exit Fase 2: "unit test G-09 tipe data config"):
 * normalisasi + validasi per tipe, konversi nilai tersimpan, validasi katalog Config\WebConfig, dan aturan impor legacy
 * (stripslashes, path logo tidak diimpor, key tak dikenal/REVIEW).
 *
 * @internal
 */
final class WebConfigTypesTest extends CIUnitTestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new HtmlSanitizer();
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: mixed, 2: string}>
     */
    public static function validValues(): iterable
    {
        $int = ['type' => T::INTEGER, 'min' => 0, 'max' => 10_000_000];
        $dec = ['type' => T::DECIMAL, 'min' => 0, 'max' => 100, 'scale' => 2];

        yield 'text trim' => [['type' => T::TEXT, 'maxLength' => 10], '  Kemenpar  ', 'Kemenpar'];
        yield 'text batas karakter multibyte' => [['type' => T::TEXT, 'maxLength' => 3], 'ééé', 'ééé'];
        yield 'textarea CRLF' => [['type' => T::TEXTAREA], "Jl. A\r\nJakarta\r", "Jl. A\nJakarta"];
        yield 'integer string' => [$int, '37000', '37000'];
        yield 'integer JSON' => [$int, 37000, '37000'];
        yield 'integer nol' => [$int, '0', '0'];
        yield 'integer batas atas' => [$int, '10000000', '10000000'];
        yield 'decimal bulat' => [$dec, '2', '2'];
        yield 'decimal nol berlebih' => [$dec, '1.50', '1.5'];
        yield 'decimal .00' => [$dec, '3.00', '3'];
        yield 'decimal JSON float' => [$dec, 1.25, '1.25'];
        yield 'decimal JSON float bulat' => [$dec, 2.0, '2'];
        yield 'decimal 100' => [$dec, '100', '100'];
        yield 'id_ref' => [['type' => T::ID_REF, 'ref' => 'unit'], '8', '8'];
        yield 'id_ref INT max' => [['type' => T::ID_REF, 'ref' => 'unit'], '2147483647', '2147483647'];
        yield 'url https' => [['type' => T::URL], ' https://contoh.go.id/logo.png ', 'https://contoh.go.id/logo.png'];
        yield 'asset path' => [['type' => T::ASSET_PATH], 'kop/logo-kiri.png', 'kop/logo-kiri.png'];
        yield 'asset jpeg' => [['type' => T::ASSET_PATH], 'logo_kanan.JPEG', 'logo_kanan.JPEG'];
        yield 'time HH:MM' => [['type' => T::TIME], '07:30', '07:30:00'];
        yield 'time HH:MM:SS' => [['type' => T::TIME], '23:59:59', '23:59:59'];
        yield 'email list' => [['type' => T::EMAIL_LIST], ' a@contoh.id , b@contoh.id ', 'a@contoh.id,b@contoh.id'];
    }

    /**
     * @param array<string, mixed> $def
     *
     * @dataProvider validValues
     */
    public function testNormalizeAcceptsValidValues(array $def, mixed $input, string $expected): void
    {
        $this->assertSame($expected, T::normalize($def, $input, $this->sanitizer));
        $this->assertTrue(T::isValidStored($def, $expected, $this->sanitizer), 'hasil normalisasi = bentuk tersimpan sah');
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: mixed}>
     */
    public static function invalidValues(): iterable
    {
        $int = ['type' => T::INTEGER, 'min' => 0, 'max' => 10_000_000];
        $dec = ['type' => T::DECIMAL, 'min' => 0, 'max' => 100, 'scale' => 2];

        foreach ([T::TEXT, T::TEXTAREA, T::INTEGER, T::TIME, T::URL] as $type) {
            yield "{$type} kosong" => [['type' => $type] + $int, ''];
            yield "{$type} spasi" => [['type' => $type] + $int, '   '];
            yield "{$type} null" => [['type' => $type] + $int, null];
            yield "{$type} array" => [['type' => $type] + $int, ['x']];
            yield "{$type} bool" => [['type' => $type] + $int, true];
        }

        yield 'text baris baru' => [['type' => T::TEXT, 'maxLength' => 50], "a\nb"];
        yield 'text terlalu panjang' => [['type' => T::TEXT, 'maxLength' => 3], 'abcd'];
        yield 'text bukan UTF-8' => [['type' => T::TEXT, 'maxLength' => 50], "\xC3\x28"];
        yield 'textarea > 65535 byte' => [['type' => T::TEXTAREA], str_repeat('é', 32768)];
        yield 'integer teks (MTC-012)' => [$int, 'tiga puluh ribu'];
        yield 'integer pemisah ribuan' => [$int, '37.000'];
        yield 'integer desimal' => [$int, '1.5'];
        yield 'integer float JSON' => [$int, 1.5];
        yield 'integer nol di depan' => [$int, '037000'];
        yield 'integer negatif' => [$int, '-1'];
        yield 'integer lewat batas' => [$int, '10000001'];
        yield 'integer notasi E' => [$int, '1e5'];
        yield 'decimal koma' => [$dec, '1,5'];
        yield 'decimal 3 digit' => [$dec, '1.255'];
        yield 'decimal > 100' => [$dec, '100.01'];
        yield 'decimal negatif' => [$dec, '-0.5'];
        yield 'decimal titik saja' => [$dec, '1.'];
        yield 'decimal nol depan' => [$dec, '01.5'];
        yield 'decimal INF' => [$dec, INF];
        yield 'id_ref nol' => [['type' => T::ID_REF, 'ref' => 'unit'], '0'];
        yield 'id_ref nol depan' => [['type' => T::ID_REF, 'ref' => 'unit'], '08'];
        yield 'id_ref > INT' => [['type' => T::ID_REF, 'ref' => 'unit'], '2147483648'];
        yield 'url tanpa skema' => [['type' => T::URL], 'contoh.go.id/logo.png'];
        yield 'url javascript' => [['type' => T::URL], 'javascript:alert(1)'];
        yield 'url ftp' => [['type' => T::URL], 'ftp://contoh.go.id/a.png'];
        yield 'asset absolut legacy' => [['type' => T::ASSET_PATH], '/var/www/html/assets/images/logo.png'];
        yield 'asset Windows' => [['type' => T::ASSET_PATH], 'C:\\www\\logo.png'];
        yield 'asset traversal' => [['type' => T::ASSET_PATH], 'kop/../../.env.png'];
        yield 'asset bukan gambar' => [['type' => T::ASSET_PATH], 'kop/logo.php'];
        yield 'time 24:00' => [['type' => T::TIME], '24:00'];
        yield 'time 7:00' => [['type' => T::TIME], '7:00'];
        yield 'time detik 60' => [['type' => T::TIME], '06:00:60'];
        yield 'email tidak valid' => [['type' => T::EMAIL_LIST], 'a@contoh.id,bukan-email'];
        yield 'email koma ganda' => [['type' => T::EMAIL_LIST], 'a@contoh.id,,b@contoh.id'];
        yield 'email ganda' => [['type' => T::EMAIL_LIST], 'a@contoh.id,A@contoh.id'];
        yield 'email > 20' => [['type' => T::EMAIL_LIST], implode(',', array_map(static fn (int $i): string => "u{$i}@contoh.id", range(1, 21)))];
        yield 'html tanpa isi terlihat' => [['type' => T::HTML], '<script>alert(1)</script><p> </p>'];
    }

    /**
     * @param array<string, mixed> $def
     *
     * @dataProvider invalidValues
     */
    public function testNormalizeRejectsInvalidValues(array $def, mixed $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        T::normalize($def, $input, $this->sanitizer);
    }

    public function testHtmlIsSanitizedNotEscaped(): void
    {
        $def = ['type' => T::HTML];

        $this->assertSame('KEMENTERIAN<br />PARIWISATA', T::normalize($def, 'KEMENTERIAN<br/>PARIWISATA<script>x</script>', $this->sanitizer));
        $this->assertSame(
            'Jl. Merdeka<br /><a href="https://contoh.go.id">contoh.go.id</a>',
            T::normalize($def, 'Jl. Merdeka<br/><a href="https://contoh.go.id" onclick="x()">contoh.go.id</a>', $this->sanitizer),
        );
        $this->assertFalse(T::isValidStored($def, '<b onclick="x()">A</b>', $this->sanitizer), 'HTML tersimpan belum bersih = tidak sah');
    }

    public function testCast(): void
    {
        $this->assertSame(37000, T::cast(['type' => T::INTEGER], '37000'));
        $this->assertSame(8, T::cast(['type' => T::ID_REF], '8'));
        $this->assertSame(1.5, T::cast(['type' => T::DECIMAL], '1.5'));
        $this->assertSame(['a@contoh.id', 'b@contoh.id'], T::cast(['type' => T::EMAIL_LIST], 'a@contoh.id,b@contoh.id'));
        $this->assertSame([], T::cast(['type' => T::EMAIL_LIST], ''));
        $this->assertSame('06:00:00', T::cast(['type' => T::TIME], '06:00:00'));
        $this->assertSame('', T::cast(['type' => T::TEXT], ''));
        $this->assertNull(T::cast(['type' => T::INTEGER], null));
        $this->assertNull(T::cast(['type' => T::DECIMAL], ''));
    }

    public function testCatalogIsValidAndCoversLegacyKeys(): void
    {
        $keys = config(WebConfig::class)->keys;
        T::assertCatalog($keys);

        // 24 key kode baseline (J4.04 §2) + pdf_header_nama_unit (Slip Gaji) + 10 key L_presensi.php:1483 (DBV-006).
        $this->assertCount(35, $keys);

        foreach (['TL1/PSW1', 'TL2/PSW2', 'TL3/PSW3', 'TA', 'TK', 'LKH'] as $name) {
            $this->assertSame(T::DECIMAL, $keys[$name]['type'], $name);
        }

        foreach (['uang_makan_gol_1', 'uang_makan_gol_2', 'uang_makan_gol_3', 'uang_makan_gol_4'] as $name) {
            $this->assertSame(T::INTEGER, $keys[$name]['type'], $name);
        }

        foreach (['logo_kementerian_pdf', 'logo_wonderful_pdf'] as $name) {
            $this->assertSame(T::ASSET_PATH, $keys[$name]['type'], $name);
        }

        foreach (['pdf_header_nama_kementerian', 'pdf_header_nama_unit', 'pdf_header_alamat_kementerian'] as $name) {
            $this->assertSame(T::HTML, $keys[$name]['type'], $name);
        }

        $this->assertSame('06:00:00', $keys['email_sent_time']['default'], 'fallback legacy Cron.php:483-484');
    }

    /**
     * @return iterable<string, array{0: array<string, array<string, mixed>>}>
     */
    public static function brokenCatalogs(): iterable
    {
        $ok = ['label' => 'L', 'group' => 'G', 'description' => 'D', 'type' => T::TEXT, 'default' => ''];

        yield 'nama tidak sah' => [['nama-dengan-strip' => $ok]];
        yield 'nama slash di akhir' => [['TL1/' => $ok]];
        yield 'tanpa label' => [['a' => ['label' => ''] + $ok]];
        yield 'tipe asing' => [['a' => ['type' => 'json'] + $ok]];
        yield 'tanpa default' => [['a' => array_diff_key($ok, ['default' => 1])]];
        yield 'default bukan string' => [['a' => ['default' => 0] + $ok]];
        yield 'integer tanpa batas' => [['a' => ['type' => T::INTEGER, 'default' => null] + $ok]];
        yield 'min > max' => [['a' => ['type' => T::INTEGER, 'default' => null, 'min' => 5, 'max' => 1] + $ok]];
        yield 'decimal tanpa scale' => [['a' => ['type' => T::DECIMAL, 'default' => null, 'min' => 0, 'max' => 1] + $ok]];
        yield 'id_ref tanpa ref' => [['a' => ['type' => T::ID_REF, 'default' => null] + $ok]];
        yield 'default tidak sesuai tipe' => [['a' => ['type' => T::TIME, 'default' => '6 pagi'] + $ok]];
    }

    /**
     * @param array<string, array<string, mixed>> $catalog
     *
     * @dataProvider brokenCatalogs
     */
    public function testBrokenCatalogIsRejected(array $catalog): void
    {
        $this->expectException(LogicException::class);
        T::assertCatalog($catalog);
    }

    // ------------------------------------------------------------------
    // Aturan impor legacy (G-09 Bagian 5)
    // ------------------------------------------------------------------

    public function testImportStripsSlashesAndNormalizes(): void
    {
        $catalog = config(WebConfig::class)->keys;

        $row = WebConfigImport::row($catalog, 'nama_kementerian', "Kementerian Pariwisata \\'Uji\\'", 'Nama \\"resmi\\"', $this->sanitizer);
        $this->assertSame(WebConfigImport::IMPORT, $row['action']);
        $this->assertSame("Kementerian Pariwisata 'Uji'", $row['config_value']);
        $this->assertSame('Nama "resmi"', $row['remark']);

        $html = WebConfigImport::row($catalog, 'pdf_header_alamat_kementerian', 'Jl. A<br/><a href=\\"https://contoh.go.id\\">x</a>', null, $this->sanitizer);
        $this->assertSame(WebConfigImport::IMPORT, $html['action']);
        $this->assertSame('Jl. A<br /><a href="https://contoh.go.id">x</a>', $html['config_value']);

        $persen = WebConfigImport::row($catalog, 'TK', '1.50', null, $this->sanitizer);
        $this->assertSame([WebConfigImport::IMPORT, '1.5'], [$persen['action'], $persen['config_value']]);
    }

    public function testImportSkipsOrFlagsForReview(): void
    {
        $catalog = config(WebConfig::class)->keys;

        $logo = WebConfigImport::row($catalog, 'logo_kementerian_pdf', '/var/www/legacy/assets/images/pdf-images/logo.jpg', 'Logo', $this->sanitizer);
        $this->assertSame(WebConfigImport::SKIP, $logo['action']);
        $this->assertNull($logo['config_value']);

        $this->assertSame(WebConfigImport::SKIP, WebConfigImport::row($catalog, 'nama_menteri', '', null)['action']);
        $this->assertSame(WebConfigImport::SKIP, WebConfigImport::row($catalog, 'nama_menteri', null, null)['action']);

        $unknown = WebConfigImport::row($catalog, 'key_tak_dikenal', 'x', null);
        $this->assertSame(WebConfigImport::REVIEW, $unknown['action']);
        $this->assertSame('x', $unknown['config_value']);

        $bad = WebConfigImport::row($catalog, 'uang_makan_gol_1', 'Rp 37.000', null);
        $this->assertSame(WebConfigImport::REVIEW, $bad['action']);
        $this->assertStringContainsString('integer', $bad['reason']);

        $url = WebConfigImport::row($catalog, 'logo_kementerian_url', 'https://contoh.go.id/logo.png', null);
        $this->assertSame([WebConfigImport::REVIEW, 'https://contoh.go.id/logo.png'], [$url['action'], $url['config_value']]);

        $this->assertSame(WebConfigImport::REVIEW, WebConfigImport::row($catalog, 'email_sent_time', '06:00:00', null)['action']);
        $this->assertSame(WebConfigImport::REVIEW, WebConfigImport::row($catalog, 'email_ultah_ad', 'a@contoh.id', null)['action']);
    }
}
