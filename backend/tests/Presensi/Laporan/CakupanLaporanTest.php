<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Presensi\Laporan\CakupanLaporan;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\RekapUjiTrait;

/**
 * PRD 5B §7.3.6 (MAKE-041/042): lingkup role laporan & rekap — `unit_satker` `0`/unit/`sat_<id>` divalidasi (422), role 3
 * dipaksa satker akun (parameter diabaikan), tanpa satker → unit akun, tanpa keduanya atau satker tidak kanonik → 403.
 *
 * @internal
 */
final class CakupanLaporanTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use RekapUjiTrait;

    public function testLingkup(): void
    {
        $unit   = $this->buatUnit();
        $satker = $this->buatSatker($unit);
        $c      = new CakupanLaporan($this->db);

        $this->assertSame('0', $c->lingkup($this->adminRekap(), null)->kode());
        $this->assertSame((string) $unit, $c->lingkup($this->adminRekap(Role::ADMIN_VIEW_ESELON1), (string) $unit)->kode());
        $this->assertSame('sat_' . $satker, $c->lingkup($this->adminRekap(), 'sat_' . $satker)->kode());
        $this->assertSame('sat_' . $satker, $c->lingkup($this->adminRekap(Role::ADMIN_SATKER, (string) $satker), '0')->kode(), 'role 3 parameter diabaikan');
        $this->assertSame((string) $unit, $c->lingkup($this->adminRekap(Role::ADMIN_SATKER, null, (string) $unit), 'sat_' . $satker)->kode(), 'role 3 tanpa satker → unit akun');

        foreach (['sat_0', 'sat_99999999', '99999999', 'x', ['a']] as $nilai) {
            try {
                $c->lingkup($this->adminRekap(), $nilai);
                $this->fail('422 ' . json_encode($nilai));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('unit_satker', $e->getErrors());
            }
        }

        foreach ([$this->adminRekap(Role::ADMIN_SATKER), $this->adminRekap(Role::ADMIN_SATKER, '07')] as $auth) {
            try {
                $c->lingkup($auth, '0');
                $this->fail('403');
            } catch (ApiException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }
}
