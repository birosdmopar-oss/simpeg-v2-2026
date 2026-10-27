<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use App\Exceptions\ValidationException;
use App\Libraries\Dashboard\Dokumen\ProyeksiPensiun;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F7-20 proyeksi pensiun (MAKE-037): rentang `tahun`/`sampai` (bawaan tahun berjalan, maks +4), 422 rentang/bentuk,
 * baris masuk bila TMT pensiun pada rentang, urutan TMT ASC.
 *
 * @internal
 */
final class ProyeksiPensiunTest extends CIUnitTestCase
{
    public function testRentangBawaanDanEksplisit(): void
    {
        $this->assertSame(['tahun' => 2026, 'sampai' => 2026], ProyeksiPensiun::rentang('', '', '2026-10-21'));
        $this->assertSame(['tahun' => 2027, 'sampai' => 2027], ProyeksiPensiun::rentang('2027', '', '2026-10-21'));
        $this->assertSame(['tahun' => 2026, 'sampai' => 2030], ProyeksiPensiun::rentang('2026', '2030', '2026-10-21'));
    }

    public function testRentangTidakValid422(): void
    {
        foreach ([['20x6', '', 'tahun'], ['1999', '', 'tahun'], ['2026', '2025', 'sampai'], ['2026', '2031', 'sampai'], ['2026', 'abc', 'sampai']] as [$t, $s, $field]) {
            try {
                ProyeksiPensiun::rentang($t, $s, '2026-10-21');
                $this->fail("tahun={$t} sampai={$s} harus 422");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, (array) $e->getErrors(), "tahun={$t} sampai={$s}");
            }
        }
    }

    public function testMasukRentangDanUrutan(): void
    {
        $pns = static fn (string $lahir, string $nip, int $satker = 1): array => ['nip' => $nip, 'tgl_lahir' => $lahir, 'id_jenis_pegawai' => '1', 'id_jabatan' => 1, 'id_satker' => $satker];

        // Lahir 1968-03-15, BUP 58 → TMT 2026-04-01.
        $this->assertTrue(ProyeksiPensiun::masuk($pns('1968-03-15', 'a'), 2026, 2026));
        $this->assertFalse(ProyeksiPensiun::masuk($pns('1968-03-15', 'a'), 2027, 2028));
        $this->assertTrue(ProyeksiPensiun::masuk($pns('1969-12-31', 'b'), 2026, 2028), 'TMT 2028-01-01 masuk rentang 2026..2028');
        $this->assertFalse(ProyeksiPensiun::masuk(['nip' => 'c', 'tgl_lahir' => '1968-03-15', 'id_jenis_pegawai' => '6', 'id_jabatan' => 1], 2026, 2026), 'PPPK tidak masuk');
        $this->assertFalse(ProyeksiPensiun::masuk(['nip' => 'd', 'tgl_lahir' => '1968-03-15', 'id_jenis_pegawai' => '1'], 2026, 2026), 'tanpa jabatan tidak masuk');

        $rows = [$pns('1968-06-10', 'x', 2), $pns('1968-03-15', 'y', 2), $pns('1968-03-15', 'w', 1)];
        usort($rows, [ProyeksiPensiun::class, 'bandingkan']);
        $this->assertSame(['w', 'y', 'x'], array_column($rows, 'nip'), 'TMT ASC, lalu satker, lalu NIP');
    }
}
