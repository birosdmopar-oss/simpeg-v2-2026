<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi\Stat;

use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Libraries\PemindaiKemurnian;

/**
 * MAKE-061 — penjaga arsitektur: kelas aturan `services/s_stat` murni (tanpa DB, HTTP, session, jam sistem — "hari ini"
 * diinjeksi). Daftar eksplisit, karena `StatService` (DB) sengaja berada di folder yang sama. Pengecualian seperti Tukin:
 * `::createFromFormat` hanya dengan format literal berawalan `!` (`KalenderStat::parse()`).
 *
 * @internal
 */
final class StatMurniTest extends CIUnitTestCase
{
    private const BERKAS_MURNI = ['KalenderStat.php', 'RekomendasiKp.php', 'RekomendasiKgb.php', 'RekomendasiPensiun.php', 'PenyajiStat.php'];

    public function testKelasAturanStatMurni(): void
    {
        $pemindai = new PemindaiKemurnian(createFromFormatBerjangkar: true);
        $folder   = APPPATH . 'Libraries/Integrasi/Stat/';

        foreach (self::BERKAS_MURNI as $berkas) {
            $this->assertFileExists($folder . $berkas);
            $this->assertSame([], $pemindai->pindai((string) file_get_contents($folder . $berkas), $berkas), $berkas);
        }

        $this->assertNotSame([], $pemindai->pindai((string) file_get_contents($folder . 'StatService.php'), 'StatService.php'), 'StatService memang ber-DB');
    }
}
