<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use App\Libraries\Dashboard\Dokumen\UrutanDuk;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F7-17 urutan inti DUK (MAKE-037): golongan (`pangkat.order` DESC) → TMT pangkat ASC → TMT CPNS ASC → usia (tgl lahir
 * ASC) → NIP; tanpa pangkat di bawah; CPNS (order lebih rendah) di bawah PNS bergolongan lebih tinggi.
 *
 * @internal
 */
final class UrutanDukTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $atas
     * @param array<string, mixed> $bawah
     */
    private function assertDiAtas(array $atas, array $bawah, string $pesan): void
    {
        $this->assertLessThan(0, UrutanDuk::bandingkan($atas, $bawah), $pesan);
        $this->assertGreaterThan(0, UrutanDuk::bandingkan($bawah, $atas), $pesan . ' (kebalikan)');
    }

    public function testGolonganLebihTinggiDiAtas(): void
    {
        $this->assertDiAtas(
            ['pangkat_order' => 13, 'tmtsk_pangkat' => '2024-04-01', 'tmtsk_cpns' => '2010-01-01', 'tgl_lahir' => '1990-01-01', 'nip' => 'b'],
            ['pangkat_order' => 9, 'tmtsk_pangkat' => '2000-04-01', 'tmtsk_cpns' => '1995-01-01', 'tgl_lahir' => '1970-01-01', 'nip' => 'a'],
            'IV/a (order 13) di atas III/a (order 9) walau TMT lebih baru',
        );
    }

    public function testSamaGolonganTmtPangkatLebihLamaDiAtas(): void
    {
        $this->assertDiAtas(
            ['pangkat_order' => 9, 'tmtsk_pangkat' => '2018-04-01', 'tmtsk_cpns' => '2015-01-01', 'tgl_lahir' => '1992-01-01', 'nip' => 'b'],
            ['pangkat_order' => 9, 'tmtsk_pangkat' => '2022-10-01', 'tmtsk_cpns' => '2010-01-01', 'tgl_lahir' => '1980-01-01', 'nip' => 'a'],
            'TMT pangkat lebih lama di atas',
        );
    }

    public function testSamaTmtPangkatTmtCpnsLebihLamaDiAtas(): void
    {
        $this->assertDiAtas(
            ['pangkat_order' => 9, 'tmtsk_pangkat' => '2018-04-01', 'tmtsk_cpns' => '2008-03-01', 'tgl_lahir' => '1992-01-01', 'nip' => 'b'],
            ['pangkat_order' => 9, 'tmtsk_pangkat' => '2018-04-01', 'tmtsk_cpns' => '2012-03-01', 'tgl_lahir' => '1980-01-01', 'nip' => 'a'],
            'masa kerja (TMT CPNS) lebih lama di atas',
        );
    }

    public function testSamaSemuaUsiaLebihTuaDiAtasLaluNip(): void
    {
        $this->assertDiAtas(
            ['pangkat_order' => 9, 'tmtsk_pangkat' => '2018-04-01', 'tmtsk_cpns' => '2008-03-01', 'tgl_lahir' => '1980-05-05', 'nip' => 'z'],
            ['pangkat_order' => 9, 'tmtsk_pangkat' => '2018-04-01', 'tmtsk_cpns' => '2008-03-01', 'tgl_lahir' => '1985-05-05', 'nip' => 'a'],
            'lebih tua di atas',
        );
        $this->assertDiAtas(
            ['pangkat_order' => 9, 'tmtsk_pangkat' => '2018-04-01', 'tmtsk_cpns' => '2008-03-01', 'tgl_lahir' => '1985-05-05', 'nip' => '1'],
            ['pangkat_order' => 9, 'tmtsk_pangkat' => '2018-04-01', 'tmtsk_cpns' => '2008-03-01', 'tgl_lahir' => '1985-05-05', 'nip' => '2'],
            'NIP penstabil',
        );
    }

    public function testTanpaPangkatSelaluDiBawah(): void
    {
        $this->assertDiAtas(
            ['pangkat_order' => 1, 'tmtsk_pangkat' => '2024-04-01', 'tmtsk_cpns' => '', 'tgl_lahir' => '1999-01-01', 'nip' => 'b'],
            ['pangkat_order' => null, 'tmtsk_pangkat' => '', 'tmtsk_cpns' => '2000-01-01', 'tgl_lahir' => '1970-01-01', 'nip' => 'a'],
            'tanpa pangkat di bawah',
        );
        $this->assertSame(0, UrutanDuk::bandingkan(['nip' => 'x'], ['nip' => 'x']));
    }

    public function testCpnsDiBawahPnsGolonganLebihTinggiDanKosongDiBawahIsi(): void
    {
        // CPNS III/a (order 29 di master = tingkat lebih rendah dari III/a 9? Tidak: order = level pangkat; CPNS
        // memakai pangkat sendiri dengan order lebih rendah) — yang dibandingkan hanya `pangkat.order`.
        $this->assertDiAtas(
            ['pangkat_order' => 9, 'tmtsk_pangkat' => '2020-04-01', 'tmtsk_cpns' => '2018-01-01', 'tgl_lahir' => '1995-01-01', 'nip' => 'b'],
            ['pangkat_order' => 8, 'tmtsk_pangkat' => '2010-04-01', 'tmtsk_cpns' => '2008-01-01', 'tgl_lahir' => '1985-01-01', 'nip' => 'a'],
            'order 9 di atas order 8',
        );
        $this->assertDiAtas(
            ['pangkat_order' => 9, 'tmtsk_pangkat' => '2020-04-01', 'tmtsk_cpns' => '2018-01-01', 'tgl_lahir' => '1995-01-01', 'nip' => 'b'],
            ['pangkat_order' => 9, 'tmtsk_pangkat' => '', 'tmtsk_cpns' => '2008-01-01', 'tgl_lahir' => '1985-01-01', 'nip' => 'a'],
            'TMT kosong di bawah TMT terisi',
        );
    }
}
