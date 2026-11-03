<?php

declare(strict_types=1);

namespace Tests\Unit\Impor;

use App\Libraries\Impor\DaftarTabel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * MAKE-056 B3 (review P-4) — tier SIASN wajib paling akhir: `siasn_upload_arsip` merujuk riwayat dan
 * `document_attachment` (DBV-027 K-6). Tier baru MAKE-069 tidak boleh disisipkan sesudahnya.
 *
 * @internal
 */
final class UrutanTierTest extends CIUnitTestCase
{
    public function testTierSiasnPalingAkhirDanSesudahInduknya(): void
    {
        $tier = [];

        for ($t = 1; $t <= DaftarTabel::TIER_MAKS; $t++) {
            foreach (DaftarTabel::tier($t) as $def) {
                $tier[$def->tabel] ??= $t;
            }
        }

        $siasn = ['siasn_ref_unor', 'siasn_referensi', 'double_riwayat_kp', 'siasn_upload_arsip'];

        foreach ($siasn as $tabel) {
            $this->assertSame(DaftarTabel::TIER_MAKS, $tier[$tabel] ?? null, "{$tabel} di tier terakhir");
        }

        $this->assertSame($siasn, array_keys(array_filter($tier, static fn (int $t): bool => $t === DaftarTabel::TIER_MAKS)), 'tier terakhir hanya berisi tabel SIASN');

        foreach (['riwayat_mutasi_jabatan', 'riwayat_diklat', 'document_attachment'] as $induk) {
            if (isset($tier[$induk])) {
                $this->assertLessThan(DaftarTabel::TIER_MAKS, $tier[$induk], "{$induk} sebelum tier SIASN");
            }
        }

        $this->assertArrayHasKey('riwayat_mutasi_jabatan', $tier);
    }
}
