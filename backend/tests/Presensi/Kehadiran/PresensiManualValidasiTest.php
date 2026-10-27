<?php

declare(strict_types=1);

namespace Tests\Presensi\Kehadiran;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Presensi\Kehadiran\PresensiManualService;
use Config\Presensi;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\PresensiUjiTrait;

/**
 * PRD 5A §7.6.2–7.6.3 (AC-D03.2, .3, .4, .6, .7): validasi presensi manual — `nip`/`tgl` wajib, pegawai harus ada,
 * format & tanggal ≤ hari ini lokal satker, minimal satu jam, format jam, masuk ≤ pulang, duplikat `(nip, tgl)` → 422
 * `errors.tgl`; ubah tidak mengubah `nip`/`tgl`, field di luar whitelist diabaikan, `sumber = 2`, jam yang berubah
 * mengosongkan bukti lokasi jam itu; ubah/hapus baris tidak ada → 404. Jendela & geofence tidak berlaku.
 *
 * @internal
 */
final class PresensiManualValidasiTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use PresensiUjiTrait;

    private string $nip;

    protected function setUp(): void
    {
        parent::setUp();
        config(Presensi::class)->akunPresensiManual = [4675];
        $this->nip                                  = $this->pegawaiPresensi(120)['nip'];
    }

    protected function tearDown(): void
    {
        config(Presensi::class)->akunPresensiManual = [4675, 4676];
        parent::tearDown();
    }

    public function testValidasiTambah(): void
    {
        $this->assertGalat([], ['nip' => 'NIP wajib diisi.', 'tgl' => 'Tanggal wajib diisi.', 'jam_masuk' => 'Isi minimal salah satu: jam masuk atau jam pulang.']);
        $this->assertGalat(['nip' => '199912312099121001', 'tgl' => '2026-02-30', 'jam_masuk' => '7:3'], [
            'nip' => 'Pegawai tidak ditemukan.', 'tgl' => 'Format tanggal harus YYYY-MM-DD.', 'jam_masuk' => 'Format jam harus HH:MM atau HH:MM:SS.',
        ]);
        $this->assertGalat(['nip' => $this->nip, 'tgl' => '2026-10-27', 'jam_masuk' => '17:00', 'jam_pulang' => '08:00'], [
            'jam_pulang' => 'Jam pulang tidak boleh lebih awal dari jam masuk.',
        ]);

        // Hari ini lokal satker zonasi 120: 23:00 WIB = 01:00 tanggal 28 → tanggal 28 boleh, 29 tidak.
        $service = $this->manual('2026-10-27 23:00:00');
        $service->tambah($this->authAdmin(4675), ['nip' => $this->nip, 'tgl' => '2026-10-28', 'jam_masuk' => '03:00']);

        try {
            $service->tambah($this->authAdmin(4675), ['nip' => $this->nip, 'tgl' => '2026-10-29', 'jam_masuk' => '03:00']);
            $this->fail('tanggal masa depan');
        } catch (ValidationException $e) {
            $this->assertSame(['Tanggal tidak boleh melebihi hari ini.'], $e->getErrors()['tgl'] ?? null);
        }
    }

    public function testTambahSumberManualTanpaJendelaDanDuplikat(): void
    {
        $data = $this->manual()->tambah($this->authAdmin(4675), [
            'nip' => $this->nip, 'tgl' => '2026-10-01', 'jam_masuk' => '02:15', 'jam_pulang' => '', 'sumber' => 1, 'lat_masuk' => '1', 'created_by' => 99,
        ]);

        $this->assertSame(['02:15:00', null, '2', null, null, '4675'], [
            $data['jam_masuk'], $data['jam_pulang'], (string) $data['sumber'], $data['lat_masuk'], $data['id_lokasi_masuk'], (string) $data['created_by'],
        ], 'jam di luar jendela diterima; field di luar whitelist diabaikan');

        try {
            $this->manual()->tambah($this->authAdmin(4675), ['nip' => $this->nip, 'tgl' => '2026-10-01', 'jam_pulang' => '16:00']);
            $this->fail('duplikat');
        } catch (ValidationException $e) {
            $this->assertSame([PresensiManualService::PESAN_DUPLIKAT], $e->getErrors()['tgl'] ?? null);
        }
    }

    public function testUbahMengosongkanBuktiLokasiJamYangBerubah(): void
    {
        $this->db->table('dh_online')->insert([
            'nip'        => $this->nip, 'tgl' => '2026-10-02', 'jam_masuk' => '07:00:00', 'jam_pulang' => '16:00:00', 'sumber' => 1,
            'lat_masuk'  => '-6.2000000', 'lng_masuk' => '106.8000000', 'akurasi_masuk' => '5.00',
            'lat_pulang' => '-6.3000000', 'lng_pulang' => '106.9000000', 'created_at' => '2026-10-02 00:00:00',
        ]);

        $data = $this->manual()->ubah($this->authAdmin(4675), $this->nip, '2026-10-02', ['jam_masuk' => '07:00', 'jam_pulang' => '17:00:30', 'nip' => '1', 'tgl' => '2020-01-01']);

        $this->assertSame([$this->nip, '2026-10-02', '07:00:00', '17:00:30', '2'], [$data['nip'], $data['tgl'], $data['jam_masuk'], $data['jam_pulang'], (string) $data['sumber']]);
        $this->assertSame('-6.2000000', $data['lat_masuk'], 'jam masuk tidak berubah → bukti lokasi masuk tetap');
        $this->assertNull($data['lat_pulang'], 'jam pulang berubah → bukti lokasi pulang dikosongkan');

        foreach ([['999', '2026-10-02'], [$this->nip, '2026-10-03'], [$this->nip, 'x']] as [$nip, $tgl]) {
            try {
                $this->manual()->ubah($this->authAdmin(4675), $nip, $tgl, ['jam_masuk' => '07:00']);
                $this->fail('404');
            } catch (NotFoundException $e) {
                $this->assertSame(PresensiManualService::PESAN_TIDAK_DITEMUKAN, $e->getMessage());
            }
        }

        $this->expectException(NotFoundException::class);
        $this->manual()->hapus($this->authAdmin(4675), $this->nip, '2026-10-03');
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $harapan field => pesan pertama
     */
    private function assertGalat(array $body, array $harapan): void
    {
        try {
            $this->manual()->tambah($this->authAdmin(4675), $body);
            $this->fail('harus 422');
        } catch (ValidationException $e) {
            $errors = (array) $e->getErrors();
            $this->assertSame($harapan, array_map(static fn (array $p): string => $p[0], $errors));
        }
    }
}
