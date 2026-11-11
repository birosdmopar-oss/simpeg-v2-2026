<?php

declare(strict_types=1);

namespace Tests\Unit\Impor;

use App\Libraries\Dashboard\Interaksi\FormDigital\SkemaForm;
use App\Libraries\Impor\AturanFormDigital;
use App\Libraries\Impor\KonversiGagal;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * MAKE-089 M2 — konverter formBuilder legacy → SkemaForm v2 (aturan ReadOne 11-11-2026) dan jawaban legacy → jawaban v2.
 * Data sintetis.
 *
 * @internal
 */
final class AturanFormDigitalTest extends CIUnitTestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function formLegacy(): array
    {
        return [
            ['type' => 'header', 'subtype' => 'h1', 'label' => 'Judul'],
            ['type' => 'text', 'name' => 'text-1600000000000-0', 'label' => '<b>Nama</b> &amp; Gelar', 'required' => true, 'maxlength' => '100'],
            ['type' => 'text', 'name' => 'TEXT_1600000000000_0', 'label' => 'Bentrok id', 'maxlength' => '900'],
            ['type' => 'textarea', 'name' => 'textarea-1', 'label' => 'Uraian'],
            ['type' => 'radio-group', 'name' => 'radio-group-1', 'label' => 'Setuju?', 'values' => [['label' => 'Ya', 'value' => 'y'], ['label' => 'Tidak', 'value' => 't']]],
            ['type' => 'select', 'name' => 'select-1', 'label' => 'Unit', 'multiple' => true, 'values' => [['label' => 'A', 'value' => 'a'], ['label' => 'B', 'value' => 'b']]],
            ['type' => 'checkbox-group', 'name' => 'checkbox-group-1', 'label' => 'Minat', 'values' => [['label' => 'X', 'value' => 'x'], ['label' => 'Y', 'value' => 'y']]],
            ['type' => 'date', 'name' => 'date-1', 'label' => 'Tanggal'],
            ['type' => 'number', 'name' => 'number-1', 'label' => 'Jumlah', 'min' => '5', 'max' => '1'],
            ['type' => 'file', 'name' => 'file-1', 'label' => 'Lampiran'],
            ['type' => 'paragraph', 'label' => 'Catatan statis'],
        ];
    }

    public function testKonversiSkemaLolosNormalisasiV2(): void
    {
        $k = AturanFormDigital::konversiSkema($this->formLegacy());

        $this->assertSame(2, $k['statis'], 'header + paragraph tidak dibawa');
        $this->assertSame($k['skema'], SkemaForm::normalisasi($k['skema']), 'hasil sudah kanonik v2');

        $p = array_column($k['skema']['pertanyaan'], null, 'id');
        $this->assertSame(['text_1600000000000_0', 'text_1600000000000_0_2', 'textarea_1', 'radio_group_1', 'select_1', 'checkbox_group_1', 'date_1', 'number_1', 'file_1'], array_keys($p));
        $this->assertSame(['teks', 100, true, 'Nama & Gelar'], [$p['text_1600000000000_0']['tipe'], $p['text_1600000000000_0']['maks'], $p['text_1600000000000_0']['wajib'], $p['text_1600000000000_0']['label']]);
        $this->assertSame(['paragraf', 900], [$p['text_1600000000000_0_2']['tipe'], $p['text_1600000000000_0_2']['maks']], 'maxlength > 255 → paragraf');
        $this->assertSame('pilihan_ganda', $p['select_1']['tipe'], 'select multiple → pilihan_ganda');
        $this->assertSame(['Ya', 'Tidak'], $p['radio_group_1']['opsi']);
        $this->assertArrayNotHasKey('min', $p['number_1'], 'min > max dibuang');
        $this->assertSame('berkas', $p['file_1']['tipe']);
        $this->assertSame('text_1600000000000_0_2', $k['nama']['TEXT_1600000000000_0']);
    }

    public function testTipeTakDikenalDanOpsiKosongGagal(): void
    {
        foreach ([[['type' => 'starRating', 'name' => 's', 'label' => 'x']], [['type' => 'radio-group', 'name' => 'r', 'label' => 'x', 'values' => []]], [['type' => 'header', 'label' => 'x']]] as $legacy) {
            try {
                AturanFormDigital::konversiSkema($legacy);
                $this->fail('wajib gagal');
            } catch (KonversiGagal) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testKonversiJawabanPetaOpsiDanBerkas(): void
    {
        $k       = AturanFormDigital::konversiSkema($this->formLegacy());
        $respons = json_encode([
            'text-1600000000000-0' => 'Andi é', 'radio-group-1' => 'y', 'select-1' => ['a', 'b'], 'checkbox-group-1' => ['x', 'z'],
            'date-1'               => '2026-01-02', 'number-1' => '7', 'kunci-asing' => 'x', 'file' => ['file-1' => 'assets/upload/fd/000000000000000001/5_1234_20260102.jpeg'],
        ]);

        $h = AturanFormDigital::konversiJawaban((string) $respons, $k, 5, '000000000000000001');
        $j = $h['jawaban'];

        $this->assertSame('Andi é', $j['text_1600000000000_0'], 'unicode utuh (tanpa stripslashes)');
        $this->assertSame('Ya', $j['radio_group_1']);
        $this->assertSame(['A', 'B'], $j['select_1']);
        $this->assertSame(['X', 'z'], $j['checkbox_group_1'], 'di luar opsi disimpan apa adanya');
        $this->assertSame(7, $j['number_1']);
        $this->assertMatchesRegularExpression('#^form-digital/5/[0-9a-f]{32}\.jpg$#', $j['file_1']['berkas']);
        $this->assertNull($j['file_1']['ukuran']);
        $this->assertStringNotContainsString('000000000000000001', (string) json_encode($j), 'NIP tidak bocor ke jawaban');
        $this->assertContains(AturanFormDigital::KOREKSI_DI_LUAR_OPSI, $h['koreksi']);
        $this->assertContains(AturanFormDigital::KOREKSI_KUNCI_LAIN, $h['koreksi']);
    }

    public function testResponsRusakDanBerkasTraversalGagal(): void
    {
        $k = AturanFormDigital::konversiSkema($this->formLegacy());

        foreach (['bukan json', '["list"]', (string) json_encode(['file' => ['file-1' => '../x.pdf']])] as $r) {
            try {
                AturanFormDigital::konversiJawaban($r, $k, 5, '000000000000000001');
                $this->fail('wajib gagal');
            } catch (KonversiGagal) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
