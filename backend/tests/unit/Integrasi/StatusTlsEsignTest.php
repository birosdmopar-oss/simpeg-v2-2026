<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Integrasi\Log\StatusIntegrasi;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * MAKE-065 — `integrasi:status` menampilkan "TLS BSrE: TIDAK DIVERIFIKASI" hanya bila driver `bsre` dengan
 * `tlsVerify` false (keputusan Horii 03-11-2026, TLS BSrE ikut legacy).
 *
 * @internal
 */
final class StatusTlsEsignTest extends CIUnitTestCase
{
    public function testPeringatanHanyaUntukBsreDenganTlsMati(): void
    {
        $status = new StatusIntegrasi(db_connect());

        foreach ([false, 'false', '0'] as $mati) {
            $this->assertStringContainsString('TLS BSrE: TIDAK DIVERIFIKASI', (string) $status->peringatanTlsEsign(['driver' => 'bsre', 'tlsVerify' => $mati]));
        }

        $this->assertNull($status->peringatanTlsEsign(['driver' => 'bsre', 'tlsVerify' => true]));
        $this->assertNull($status->peringatanTlsEsign(['driver' => 'mock', 'tlsVerify' => false]));
    }
}
