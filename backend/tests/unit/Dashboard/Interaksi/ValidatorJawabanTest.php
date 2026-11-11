<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard\Interaksi;

use App\Exceptions\ValidationException;
use App\Libraries\Dashboard\Interaksi\FormDigital\SkemaForm;
use App\Libraries\Dashboard\Interaksi\FormDigital\ValidatorJawaban;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F-06 validasi jawaban (MAKE-083): tiap tipe, kunci asing, wajib, opsi, tanggal, rentang angka, berkas wajib.
 *
 * @internal
 */
final class ValidatorJawabanTest extends CIUnitTestCase
{
    /**
     * @return array{versi: int, pertanyaan: list<array<string, mixed>>}
     */
    private function skema(): array
    {
        return SkemaForm::normalisasi([
            ['id' => 'nama', 'tipe' => 'teks', 'label' => 'Nama', 'wajib' => true, 'maks' => 10],
            ['id' => 'saran', 'tipe' => 'paragraf', 'label' => 'Saran'],
            ['id' => 'setuju', 'tipe' => 'pilihan_tunggal', 'label' => 'Setuju', 'opsi' => ['Ya', 'Tidak']],
            ['id' => 'minat', 'tipe' => 'pilihan_ganda', 'label' => 'Minat', 'opsi' => ['A', 'B', 'C']],
            ['id' => 'tgl', 'tipe' => 'tanggal', 'label' => 'Tanggal'],
            ['id' => 'umur', 'tipe' => 'angka', 'label' => 'Umur', 'min' => 18, 'maks_angka' => 70],
            ['id' => 'bukti', 'tipe' => 'berkas', 'label' => 'Bukti', 'wajib' => true],
        ]);
    }

    public function testJawabanValidDibersihkan(): void
    {
        $hasil = ValidatorJawaban::periksa($this->skema(), [
            'nama'   => ' Budi ',
            'saran'  => '',
            'setuju' => 'Ya',
            'minat'  => ['A', 'C'],
            'tgl'    => '2026-11-11',
            'umur'   => '30',
        ], ['bukti']);

        $this->assertSame(['nama' => 'Budi', 'setuju' => 'Ya', 'minat' => ['A', 'C'], 'tgl' => '2026-11-11', 'umur' => 30], $hasil);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: list<string>, 2: string}>
     */
    public static function jawabanTidakValid(): iterable
    {
        $dasar = ['nama' => 'Budi'];

        yield 'kunci asing' => [$dasar + ['lain' => 'x'], ['bukti'], 'jawaban.lain'];
        yield 'wajib kosong' => [['nama' => ' '], ['bukti'], 'jawaban.nama'];
        yield 'teks terlalu panjang' => [['nama' => str_repeat('a', 11)], ['bukti'], 'jawaban.nama'];
        yield 'teks bukan string' => [['nama' => ['a']], ['bukti'], 'jawaban.nama'];
        yield 'opsi di luar daftar' => [$dasar + ['setuju' => 'Mungkin'], ['bukti'], 'jawaban.setuju'];
        yield 'ganda bukan list' => [$dasar + ['minat' => 'A'], ['bukti'], 'jawaban.minat'];
        yield 'ganda duplikat' => [$dasar + ['minat' => ['A', 'A']], ['bukti'], 'jawaban.minat'];
        yield 'tanggal tidak ada' => [$dasar + ['tgl' => '2026-02-30'], ['bukti'], 'jawaban.tgl'];
        yield 'angka bukan angka' => [$dasar + ['umur' => 'tiga puluh'], ['bukti'], 'jawaban.umur'];
        yield 'angka di bawah min' => [$dasar + ['umur' => 17], ['bukti'], 'jawaban.umur'];
        yield 'angka di atas maks' => [$dasar + ['umur' => 71], ['bukti'], 'jawaban.umur'];
        yield 'berkas wajib tidak ada' => [$dasar, [], 'berkas_bukti'];
        yield 'berkas untuk pertanyaan non-berkas' => [$dasar, ['bukti', 'nama'], 'berkas_nama'];
    }

    /**
     * @dataProvider jawabanTidakValid
     *
     * @param array<string, mixed> $jawaban
     * @param list<string>         $berkas
     */
    public function testJawabanTidakValid422(array $jawaban, array $berkas, string $field): void
    {
        try {
            ValidatorJawaban::periksa($this->skema(), $jawaban, $berkas);
            $this->fail('seharusnya 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, (array) $e->getErrors());
        }
    }

    public function testFormatJawabanBukanMap(): void
    {
        $this->expectException(ValidationException::class);
        ValidatorJawaban::periksa($this->skema(), ['a', 'b'], ['bukti']);
    }
}
