<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\AngkaKredit;

use App\Libraries\Kepegawaian\AngkaKredit\AkPermenpanrb;
use CodeIgniter\Test\CIUnitTestCase;
use Config\AngkaKredit;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MAKE-021: konstanta PermenPAN-RB 1/2023 (`Config\AngkaKredit`, bawaan = legacy getAKAccu() :2049-2110) dan aturan
 * AK untuk KP per jenjang × golongan (termasuk golongan di atas batas jenjang → AK terbesar jenjang).
 *
 * @internal
 */
final class AkPermenpanrbKonstantaTest extends CIUnitTestCase
{
    public function testKonstantaBawaanSamaDenganLegacy(): void
    {
        $c = new AngkaKredit();

        $this->assertSame([
            'Pemula'       => ['II/a' => 15],
            'Terampil'     => ['II/b' => 20, 'II/c' => 40, 'II/d' => 60],
            'Mahir'        => ['III/a' => 50, 'III/b' => 100],
            'Penyelia'     => ['III/c' => 100],
            'Ahli Pertama' => ['III/a' => 50, 'III/b' => 100],
            'Ahli Muda'    => ['III/c' => 100, 'III/d' => 200],
            'Ahli Madya'   => ['IV/a' => 150, 'IV/b' => 300, 'IV/c' => 450],
            'Ahli Utama'   => ['IV/d' => 200],
        ], $c->akUntukKp);
        $this->assertSame(['II/a' => 10, 'II/b' => 11, 'II/c' => 12, 'II/d' => 13, 'III/a' => 14, 'III/b' => 15, 'III/c' => 16, 'III/d' => 17, 'IV/a' => 18, 'IV/b' => 19, 'IV/c' => 20, 'IV/d' => 21, 'IV/e' => 22], $c->nilaiGolongan);
        $this->assertSame(['Pemula' => 10, 'Terampil' => 13, 'Mahir' => 15, 'Penyelia' => 17, 'Ahli Pertama' => 15, 'Ahli Muda' => 17, 'Ahli Madya' => 20, 'Ahli Utama' => 22], $c->batasGolonganJenjang);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: float}>
     */
    public static function kasusProvider(): array
    {
        return [
            'Ahli Pertama III/a'                     => ['Ahli Pertama', 'III/a', 50.0],
            'Ahli Pertama III/b'                     => ['Ahli Pertama', 'III/b', 100.0],
            'Ahli Pertama III/c (di atas batas)'     => ['Ahli Pertama', 'III/c', 100.0],
            'Ahli Madya IV/d (di atas batas) → maks' => ['Ahli Madya', 'IV/d', 450.0],
            'Ahli Utama IV/e (dalam batas, tak ada)' => ['Ahli Utama', 'IV/e', 0.0],
            'Terampil II/a (dalam batas, tak ada)'   => ['Terampil', 'II/a', 0.0],
            'Pemula II/a'                            => ['Pemula', 'II/a', 15.0],
            'golongan tak dikenal (nilai 0)'         => ['Ahli Muda', 'X/x', 0.0],
            'golongan kosong'                        => ['Ahli Muda', '', 0.0],
            'jenjang tak dikenal'                    => ['Ahli Super', 'III/a', 0.0],
            'jenjang kosong'                         => ['', 'III/a', 0.0],
        ];
    }

    #[DataProvider('kasusProvider')]
    public function testAkUntukKp(string $jenjang, string $gol, float $harapan): void
    {
        $this->assertSame($harapan, (new AkPermenpanrb(null, new AngkaKredit()))->akUntukKp($jenjang, $gol));
    }

    public function testKonstantaDariConfigBisaDiganti(): void
    {
        $c                         = new AngkaKredit();
        $c->akUntukKp['Ahli Muda'] = ['III/c' => 120];

        $this->assertSame(120.0, (new AkPermenpanrb(null, $c))->akUntukKp('Ahli Muda', 'III/c'));
    }
}
