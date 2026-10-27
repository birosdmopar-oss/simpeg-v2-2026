<?php

declare(strict_types=1);

namespace Tests\Presensi\Kehadiran;

use App\Exceptions\ApiException;
use App\Libraries\Presensi\Geo\LokasiGeofence;
use App\Libraries\Presensi\Kehadiran\KodeGalatPresensi;
use App\Libraries\Presensi\Kehadiran\ResolverLokasiPresensi;
use DateTimeImmutable;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\PresensiUjiTrait;

/**
 * PRD 5A §7.5.3 (AC-D02.7): resolver aturan lokasi — `target_uns = 0` × `target_jp`; unit; `sat_<id>`; `target_peg`
 * (diabaikan saat `0`); `excl_peg`; `hari_berlaku` cocok/tidak; aturan status 2/10 diabaikan; lokasi status ≠ 1 diabaikan;
 * jenis pegawai di luar `target_jp` tidak tercakup; gabungan lokasi beberapa aturan; semua lokasi tidak sah → 422
 * `lokasi_tidak_valid` (fail-closed); pegawai non-aktif tidak tercakup.
 *
 * @internal
 */
final class ResolverLokasiPresensiTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use PresensiUjiTrait;

    /** Senin. */
    private const SENIN = '2026-10-26';

    /** @var array{nip: string, id_satker: int, id_unit: int} */
    private array $p;

    private int $l1;

    private int $l2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p  = $this->pegawaiPresensi(0, 1);
        $this->l1 = $this->lokasi('-6.2', '106.8', 100, nama: 'L1');
        $this->l2 = $this->lokasi('-6.3', '106.9', 100, nama: 'L2');
        $this->jenisPegawai(2);
    }

    public function testCakupanTarget(): void
    {
        $nip = $this->p['nip'];

        $kasus = [
            'seluruh kementerian + jp cocok'  => [fn () => $this->aturan([$this->l1], ['0'], [1]), [$this->l1]],
            'seluruh + jp tidak cocok'        => [fn () => $this->aturan([$this->l1], ['0'], [2]), []],
            'unit'                            => [fn () => $this->aturan([$this->l1], [(string) $this->p['id_unit']], [1]), [$this->l1]],
            'satker'                          => [fn () => $this->aturan([$this->l1], ['sat_' . $this->p['id_satker']], [1]), [$this->l1]],
            'unit lain'                       => [fn () => $this->aturan([$this->l1], ['999999'], [1]), []],
            'target pegawai'                  => [fn () => $this->aturan([$this->l1], ['999999'], [2], [$nip]), [$this->l1]],
            'target pegawai diabaikan saat 0' => [fn () => $this->aturan([$this->l1], ['0'], [2], [$nip]), []],
            'dikecualikan'                    => [fn () => $this->aturan([$this->l1], ['0'], [1], [], [$nip]), []],
            'hari berlaku cocok (Senin=1)'    => [fn () => $this->aturan([$this->l1], ['0'], [1], hari: '1,3'), [$this->l1]],
            'hari berlaku tidak cocok'        => [fn () => $this->aturan([$this->l1], ['0'], [1], hari: '2,3'), []],
            'aturan status 2'                 => [fn () => $this->aturan([$this->l1], ['0'], [1], status: 2), []],
            'aturan status 10'                => [fn () => $this->aturan([$this->l1], ['0'], [1], status: 10), []],
        ];

        foreach ($kasus as $label => [$buat, $harapan]) {
            $this->db->table('dm_user_lokasi_presensi')->emptyTable();
            $buat();
            $hasil = (new ResolverLokasiPresensi($this->db))->untuk($nip, new DateTimeImmutable(self::SENIN));
            $this->assertSame($harapan, array_map(static fn (LokasiGeofence $l): int => $l->id, $hasil['lokasi']), $label);
            $this->assertSame($harapan !== [], $hasil['tercakup'], $label);
        }
    }

    public function testGabunganDanStatusLokasi(): void
    {
        $nonaktif = $this->lokasi('-6.4', '106.7', 100, 2, 'Nonaktif');
        $this->aturan([$this->l1, $nonaktif], ['0'], [1]);
        $this->aturan([$this->l2, $this->l1], ['sat_' . $this->p['id_satker']], [1]);

        $hasil = (new ResolverLokasiPresensi($this->db))->untuk($this->p['nip'], new DateTimeImmutable(self::SENIN));
        $this->assertSame([$this->l1, $this->l2], array_map(static fn (LokasiGeofence $l): int => $l->id, $hasil['lokasi']));
    }

    public function testSemuaLokasiTidakSahFailClosed(): void
    {
        $rusak = $this->lokasi('bukan-angka', '106.8', 100, nama: 'Rusak');
        $this->aturan([$rusak], ['0'], [1]);

        $hasil = (new ResolverLokasiPresensi($this->db))->untuk($this->p['nip'], new DateTimeImmutable(self::SENIN));
        $this->assertSame([true, [], 1], [$hasil['tercakup'], $hasil['lokasi'], $hasil['tidak_sah']]);

        try {
            $this->online('2026-10-26 07:00:00')->masuk($this->authPegawai($this->p['nip']), ['latitude' => -6.2, 'longitude' => 106.8]);
            $this->fail('lokasi tidak valid');
        } catch (ApiException $e) {
            $this->assertSame([422, KodeGalatPresensi::LOKASI_TIDAK_VALID], [$e->getStatusCode(), $e->getKode()]);
        }
    }

    public function testPegawaiNonaktifTidakTercakup(): void
    {
        $this->aturan([$this->l1], ['0'], [1]);
        $this->db->table('pegawai')->where('nip', $this->p['nip'])->update(['status' => 2]);

        $this->assertFalse((new ResolverLokasiPresensi($this->db))->untuk($this->p['nip'], new DateTimeImmutable(self::SENIN))['tercakup']);
    }
}
