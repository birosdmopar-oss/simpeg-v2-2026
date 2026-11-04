<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use App\Libraries\Push\AntrePushRiwayat;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * review MAKE-066 P7 — pendaftaran pelanggan push riwayat di `Config/Events.php` dijaga test tersendiri: listener dikosongkan lalu
 * berkas konfigurasi dimuat ulang (tidak bergantung pada urutan test lain yang melepas listener).
 *
 * @internal
 */
final class EventsPushTerdaftarTest extends CIUnitTestCase
{
    public function testConfigEventsMendaftarkanAntrePushRiwayat(): void
    {
        Events::removeAllListeners();
        require APPPATH . 'Config/Events.php';

        $this->assertContains([AntrePushRiwayat::class, 'dariEvent'], Events::listeners(RiwayatEngine::EVENT_NOTIFIKASI));
    }
}
