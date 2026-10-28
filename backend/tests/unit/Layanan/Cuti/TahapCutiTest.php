<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\Cuti;

use App\Libraries\Layanan\Cuti\CutiPenyaji;
use App\Libraries\Layanan\Cuti\StatusCuti;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CR-073 — stepper cuti PRD Fase 4 §6.11.4: langkah terakhir selalu berlabel "Selesai" (sama dengan TB/IB/PMK); hasil
 * final terlihat dari badge dan label tahap yang memutus ("Ditolak oleh …"/"Ditangguhkan oleh …").
 *
 * @internal
 */
final class TahapCutiTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{array<string, int>, bool, list<string>, list<string>}>
     */
    public static function kasus(): iterable
    {
        yield 'pengajuan dua langkah' => [['status' => 0, 'status_al' => 0, 'status_pyb' => 0], false,
            ['Diajukan', 'Atasan Langsung', 'Pejabat Berwenang', 'Selesai'], ['done', 'current', 'todo', 'todo']];
        yield 'disetujui dua langkah' => [['status' => StatusCuti::DISETUJUI, 'status_al' => 1, 'status_pyb' => 1], false,
            ['Diajukan', 'Atasan Langsung', 'Pejabat Berwenang', 'Selesai'], ['done', 'done', 'done', 'done']];
        yield 'ditolak atasan' => [['status' => StatusCuti::DITOLAK, 'status_al' => 2, 'status_pyb' => 0], false,
            ['Diajukan', 'Ditolak oleh Atasan Langsung', 'Pejabat Berwenang', 'Selesai'], ['done', 'done', 'todo', 'done']];
        yield 'ditangguhkan pyb' => [['status' => StatusCuti::DITANGGUHKAN, 'status_al' => 1, 'status_pyb' => 4], false,
            ['Diajukan', 'Atasan Langsung', 'Ditangguhkan oleh Pejabat Berwenang', 'Selesai'], ['done', 'done', 'done', 'done']];
        yield 'ditolak langkah tunggal' => [['status' => StatusCuti::DITOLAK, 'status_al' => 0, 'status_pyb' => 2], true,
            ['Diajukan', 'Ditolak oleh Pejabat Berwenang', 'Selesai'], ['done', 'done', 'done']];
    }

    /**
     * @param array<string, int> $cuti
     * @param list<string>       $label
     * @param list<string>       $state
     */
    #[DataProvider('kasus')]
    public function testLangkahTerakhirTetapSelesai(array $cuti, bool $langkahTunggal, array $label, array $state): void
    {
        $tahap = CutiPenyaji::tahap($cuti, $langkahTunggal);

        $this->assertSame($label, array_column($tahap, 'label'));
        $this->assertSame($state, array_column($tahap, 'state'));
        $this->assertSame('selesai', $tahap[array_key_last($tahap)]['kode']);
    }
}
