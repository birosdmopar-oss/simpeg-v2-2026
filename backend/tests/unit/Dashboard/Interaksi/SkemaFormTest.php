<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard\Interaksi;

use App\Exceptions\ValidationException;
use App\Libraries\Dashboard\Interaksi\FormDigital\SkemaForm;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F-06 skema form v2 (MAKE-083, Q7-18): normalisasi tiap tipe, batas, id stabil, teks polos, pembanding kunci Q7-25.
 *
 * @internal
 */
final class SkemaFormTest extends CIUnitTestCase
{
    public function testNormalisasiSemuaTipeDanIdDibuatServer(): void
    {
        $s = SkemaForm::normalisasi([
            ['tipe' => 'teks', 'label' => ' Nama ', 'wajib' => true],
            ['id' => 'q1', 'tipe' => 'paragraf', 'label' => 'Saran', 'maks' => 1000, 'bantuan' => 'Opsional'],
            ['tipe' => 'pilihan_tunggal', 'label' => 'Setuju?', 'opsi' => ['Ya', ' Tidak ']],
            ['tipe' => 'pilihan_ganda', 'label' => 'Minat', 'opsi' => ['A', 'B']],
            ['tipe' => 'tanggal', 'label' => 'Tanggal'],
            ['tipe' => 'angka', 'label' => 'Umur', 'min' => 18, 'maks_angka' => 70],
            ['tipe' => 'berkas', 'label' => 'Bukti', 'berkas' => ['tipe' => ['png', 'pdf'], 'maks_mb' => 2]],
        ]);

        $this->assertSame(1, $s['versi']);
        // id kosong → q<n> pertama yang belum dipakai; q1 yang dikirim klien dipertahankan.
        $this->assertSame(['q2', 'q1', 'q3', 'q4', 'q5', 'q6', 'q7'], array_column($s['pertanyaan'], 'id'));
        $this->assertSame(['id' => 'q2', 'tipe' => 'teks', 'label' => 'Nama', 'wajib' => true, 'maks' => 255], $s['pertanyaan'][0]);
        $this->assertSame(1000, $s['pertanyaan'][1]['maks']);
        $this->assertSame(['Ya', 'Tidak'], $s['pertanyaan'][2]['opsi']);
        $this->assertSame(['min' => 18, 'maks_angka' => 70], array_intersect_key($s['pertanyaan'][5], ['min' => 0, 'maks_angka' => 0]));
        $this->assertSame(['tipe' => ['pdf', 'png'], 'maks_mb' => 2], $s['pertanyaan'][6]['berkas']);
        $this->assertSame($s, SkemaForm::normalisasi($s), 'idempoten');
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function skemaTidakValid(): iterable
    {
        yield 'kosong' => [[], 'form.pertanyaan'];
        yield 'bukan list' => [['a' => 1], 'form.pertanyaan'];
        yield 'tipe asing' => [[['tipe' => 'html', 'label' => 'x']], 'form.pertanyaan.0.tipe'];
        yield 'label kosong' => [[['tipe' => 'teks', 'label' => ' ']], 'form.pertanyaan.0.label'];
        yield 'label ber-HTML' => [[['tipe' => 'teks', 'label' => '<script>x</script>']], 'form.pertanyaan.0.label'];
        yield 'id format' => [[['id' => 'Q-1', 'tipe' => 'teks', 'label' => 'x']], 'form.pertanyaan.0.id'];
        yield 'id ganda' => [[['id' => 'a', 'tipe' => 'teks', 'label' => 'x'], ['id' => 'a', 'tipe' => 'teks', 'label' => 'y']], 'form.pertanyaan.1.id'];
        yield 'teks maks > 255' => [[['tipe' => 'teks', 'label' => 'x', 'maks' => 256]], 'form.pertanyaan.0.maks'];
        yield 'opsi kosong' => [[['tipe' => 'pilihan_tunggal', 'label' => 'x', 'opsi' => []]], 'form.pertanyaan.0.opsi'];
        yield 'opsi ganda' => [[['tipe' => 'pilihan_ganda', 'label' => 'x', 'opsi' => ['a', 'a']]], 'form.pertanyaan.0.opsi'];
        yield 'opsi > 50' => [[['tipe' => 'pilihan_ganda', 'label' => 'x', 'opsi' => array_map('strval', range(1, 51))]], 'form.pertanyaan.0.opsi'];
        yield 'angka min > maks' => [[['tipe' => 'angka', 'label' => 'x', 'min' => 5, 'maks_angka' => 1]], 'form.pertanyaan.0.maks_angka'];
        yield 'berkas jenis asing' => [[['tipe' => 'berkas', 'label' => 'x', 'berkas' => ['tipe' => ['exe']]]], 'form.pertanyaan.0.berkas.tipe'];
        yield 'berkas 3 MB (bukan 1/2/5)' => [[['tipe' => 'berkas', 'label' => 'x', 'berkas' => ['maks_mb' => 3]]], 'form.pertanyaan.0.berkas.maks_mb'];
        yield 'berkas > 5 MB' => [[['tipe' => 'berkas', 'label' => 'x', 'berkas' => ['maks_mb' => 6]]], 'form.pertanyaan.0.berkas.maks_mb'];
        yield '> 100 pertanyaan' => [array_fill(0, 101, ['tipe' => 'teks', 'label' => 'x']), 'form.pertanyaan'];
    }

    /**
     * @dataProvider skemaTidakValid
     */
    public function testSkemaTidakValid422(mixed $masukan, string $field): void
    {
        try {
            SkemaForm::normalisasi($masukan);
            $this->fail('seharusnya 422');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertArrayHasKey($field, (array) $e->getErrors());
        }
    }

    public function testSamaMengabaikanUrutanKunciTetapiPekaIsiDanUrutanPertanyaan(): void
    {
        $a = SkemaForm::normalisasi([['id' => 'a', 'tipe' => 'teks', 'label' => 'A'], ['id' => 'b', 'tipe' => 'tanggal', 'label' => 'B']]);
        $b = SkemaForm::dariJson((string) json_encode(['pertanyaan' => [['label' => 'A', 'tipe' => 'teks', 'wajib' => false, 'maks' => 255, 'id' => 'a'], ['tipe' => 'tanggal', 'id' => 'b', 'label' => 'B', 'wajib' => false]], 'versi' => 1]));

        $this->assertTrue(SkemaForm::sama($a, $b));
        $this->assertFalse(SkemaForm::sama($a, SkemaForm::normalisasi([['id' => 'a', 'tipe' => 'teks', 'label' => 'A diubah'], ['id' => 'b', 'tipe' => 'tanggal', 'label' => 'B']])));
        $this->assertFalse(SkemaForm::sama($a, SkemaForm::normalisasi([['id' => 'b', 'tipe' => 'tanggal', 'label' => 'B'], ['id' => 'a', 'tipe' => 'teks', 'label' => 'A']])));
    }

    public function testDariJsonRusakMenjadiSkemaKosong(): void
    {
        $this->assertSame(['versi' => 1, 'pertanyaan' => []], SkemaForm::dariJson('[{"bukan":"skema"'));
    }
}
