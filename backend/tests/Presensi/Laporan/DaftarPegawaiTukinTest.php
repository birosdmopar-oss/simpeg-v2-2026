<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\LaporanTukinUjiTrait;
use Tests\Support\Presensi\RekapUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §7.3.1 AC-D08.1/.3 (MAKE-041): daftar pegawai Tukin — pegawai aktif dengan jabatan jenis 1, lingkup (role 3 dipaksa
 * satkernya di server), filter jenis pegawai & pencarian, paginasi server, bentuk item, `bulan_tersedia` 2024-04 s.d. bulan
 * berjalan (setelah tanggal 15 termasuk bulan depan).
 *
 * @internal
 */
final class DaftarPegawaiTukinTest extends DatabaseTestCase
{
    use LaporanTukinUjiTrait;
    use PegawaiFixtureTrait;
    use RekapUjiTrait;
    use TukinDataUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLaporanTukin();
    }

    protected function tearDown(): void
    {
        $this->bersihkanLaporanTukin();
        parent::tearDown();
    }

    public function testDaftarLingkupFilterDanItem(): void
    {
        $satkerA = $this->buatSatker($this->buatUnit());
        $satkerB = $this->buatSatker($this->buatUnit());
        $a       = $this->buatPegawaiDiSatker($satkerA, ['nama' => 'Ani Uji', 'id_jenis_pegawai' => 1]);
        $b       = $this->buatPegawaiDiSatker($satkerB, ['nama' => 'Budi Uji', 'id_jenis_pegawai' => 6]);
        $this->buatPegawaiDiSatker($satkerA, ['nama' => 'Cici Nonaktif', 'status' => 2]);

        $semua = $this->serviceDaftarTukin()->daftar($this->adminRekap(), []);
        $this->assertSame(2, $semua['total']);
        $item = array_column($semua['items'], null, 'nip')[$a];
        $this->assertSame(['nip', 'nama', 'gol_ruang', 'pangkat', 'jabatan', 'eselon', 'unit', 'satker', 'pendidikan', 'jenis_pegawai', 'aksi'], array_keys($item));
        $this->assertSame(['lihat_laporan' => true], $item['aksi']);

        $this->assertSame([$b], array_column($this->serviceDaftarTukin()->daftar($this->adminRekap(), ['jenis_pegawai' => ['6']])['items'], 'nip'));
        $this->assertSame([$a], array_column($this->serviceDaftarTukin()->daftar($this->adminRekap(), ['search' => 'ani'])['items'], 'nip'));
        $this->assertSame([$b], array_column($this->serviceDaftarTukin()->daftar($this->adminRekap(Role::ADMIN_SATKER, (string) $satkerB), ['unit_satker' => 'sat_' . $satkerA])['items'], 'nip'), 'role 3 dipaksa satkernya');

        $hal = $this->serviceDaftarTukin()->daftar($this->adminRekap(), ['per_page' => '1', 'page' => '2']);
        $this->assertSame([2, 1], [$hal['total'], count($hal['items'])]);

        $this->expectException(ValidationException::class);
        $this->serviceDaftarTukin()->daftar($this->adminRekap(), ['jenis_pegawai' => ['99']]);
    }

    public function testBulanTersedia(): void
    {
        $sebelum15 = array_column($this->serviceDaftarTukin('2026-10-15 09:00:00')->bulanTersedia(), 'bulan');
        $sesudah15 = array_column($this->serviceDaftarTukin('2026-10-16 09:00:00')->bulanTersedia(), 'bulan');

        $this->assertSame(['2024-04', '2026-10'], [$sebelum15[0], end($sebelum15)]);
        $this->assertSame('2026-11', end($sesudah15));
        $this->assertSame(['bulan' => '2024-04', 'label' => 'April 2024'], $this->serviceDaftarTukin()->bulanTersedia()[0]);
    }
}
