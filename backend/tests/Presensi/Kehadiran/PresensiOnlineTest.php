<?php

declare(strict_types=1);

namespace Tests\Presensi\Kehadiran;

use App\Exceptions\ApiException;
use App\Libraries\Presensi\Geo\Haversine;
use App\Libraries\Presensi\Kehadiran\KodeGalatPresensi;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\PresensiUjiTrait;

/**
 * PRD 5A §7.5 (AC-D02.1, .2, .5, .6, .9): presensi online — tepat di batas radius & di dalam radius tersimpan dengan
 * koordinat/akurasi/`id_lokasi_*`/`sumber = 1`; di luar radius → 422 `di_luar_radius` (nama, jarak, radius) tanpa tulis;
 * masuk first-wins, pulang last-wins, pulang tanpa masuk lalu masuk; tanpa aturan → tanpa syarat koordinat; `cek-lokasi`
 * memberi putusan yang sama dan tidak menulis; koordinat/akurasi tidak sah → 422; audit `dh_online` `{nip}:{tgl}`.
 *
 * @internal
 */
final class PresensiOnlineTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use PresensiUjiTrait;

    private const LAT = '-6.2000000';

    private const LNG = '106.8166667';

    private string $nip;

    private int $lokasi;

    private float $radius;

    protected function setUp(): void
    {
        parent::setUp();
        $p            = $this->pegawaiPresensi();
        $this->nip    = $p['nip'];
        $this->radius = Haversine::jarakMeter((float) self::LAT, (float) self::LNG, -6.2005, 106.8166667);
        $this->lokasi = $this->lokasi(self::LAT, self::LNG, $this->radius, nama: 'Kantor Pusat');
        $this->aturan([$this->lokasi], ['0'], [1]);
    }

    public function testTepatDiBatasRadiusTersimpan(): void
    {
        [$status, $data] = $this->online('2026-10-27 07:15:00')->masuk($this->authPegawai($this->nip), ['latitude' => -6.2005, 'longitude' => '106.8166667', 'akurasi' => 12.5]);

        $this->assertSame(201, $status);
        $this->assertSame(['2026-10-27', '07:15:00', null, $this->lokasi], [$data['tgl'], $data['jam_masuk'], $data['jam_pulang'], $data['id_lokasi_masuk']]);
        $this->assertSame((int) round($this->radius), $data['jarak_m']);

        $row = $this->barisDh($this->nip, '2026-10-27');
        $this->assertIsArray($row);
        $this->assertSame(['-6.2005000', '106.8166667', '12.50', (string) $this->lokasi, '1'], [
            $row['lat_masuk'], $row['lng_masuk'], $row['akurasi_masuk'], (string) $row['id_lokasi_masuk'], (string) $row['sumber'],
        ]);
        $this->assertSame('9001', (string) $row['created_by']);

        $audit = $this->db->table('audit_logs')->where(['entity' => 'dh_online', 'entity_id' => "{$this->nip}:2026-10-27", 'event' => 'create'])->countAllResults();
        $this->assertSame(1, $audit);
    }

    public function testDiLuarRadius422TanpaTulis(): void
    {
        try {
            $this->online('2026-10-27 07:15:00')->masuk($this->authPegawai($this->nip), ['latitude' => -6.2100, 'longitude' => 106.8166667]);
            $this->fail('harus 422');
        } catch (ApiException $e) {
            $this->assertSame([422, KodeGalatPresensi::DI_LUAR_RADIUS], [$e->getStatusCode(), $e->getKode()]);
            $this->assertMatchesRegularExpression('/^Anda berada di luar radius lokasi presensi\. Lokasi terdekat: Kantor Pusat \(jarak ±1112 m, radius [\d.]+ m\)\.$/u', $e->getMessage());
            $this->assertArrayHasKey('lokasi', (array) $e->getErrors());
        }

        $this->assertNull($this->barisDh($this->nip, '2026-10-27'));
    }

    public function testSalahSatuLokasiCukupDanKoordinatWajib(): void
    {
        $jauh = $this->lokasi('-7.0000000', '110.0000000', 50, nama: 'Cabang');
        $this->aturan([$jauh], ['0'], [1]);

        [$status] = $this->online('2026-10-27 08:00:00')->masuk($this->authPegawai($this->nip), ['latitude' => self::LAT, 'longitude' => self::LNG]);
        $this->assertSame(201, $status);

        try {
            $this->online('2026-10-27 13:00:00')->pulang($this->authPegawai($this->nip), []);
            $this->fail('koordinat wajib');
        } catch (ApiException $e) {
            $this->assertSame(KodeGalatPresensi::KOORDINAT_WAJIB, $e->getKode());
            $this->assertArrayHasKey('latitude', (array) $e->getErrors());
        }
    }

    public function testMasukFirstWinsPulangLastWins(): void
    {
        $auth = $this->authPegawai($this->nip);
        $di   = ['latitude' => self::LAT, 'longitude' => self::LNG];

        $this->online('2026-10-27 07:00:00')->masuk($auth, $di);

        try {
            $this->online('2026-10-27 08:00:00')->masuk($auth, $di);
            $this->fail('masuk kedua');
        } catch (ApiException $e) {
            $this->assertSame(KodeGalatPresensi::SUDAH_PRESENSI_MASUK, $e->getKode());
            $this->assertSame('Presensi masuk tanggal 27-10-2026 sudah terekam pukul 07:00.', $e->getMessage());
        }

        [$s1] = $this->online('2026-10-27 16:00:00')->pulang($auth, ['latitude' => -6.2001, 'longitude' => self::LNG, 'akurasi' => '5']);
        [$s2] = $this->online('2026-10-27 17:30:00')->pulang($auth, ['latitude' => -6.2002, 'longitude' => self::LNG]);
        $this->assertSame([200, 200], [$s1, $s2]);

        $row = $this->barisDh($this->nip, '2026-10-27');
        $this->assertSame(['07:00:00', '17:30:00', '-6.2002000', null], [$row['jam_masuk'], $row['jam_pulang'], $row['lat_pulang'], $row['akurasi_pulang']], 'pulang menimpa jam & koordinat');
    }

    public function testPulangTanpaMasukLaluMasuk(): void
    {
        $auth = $this->authPegawai($this->nip);
        $di   = ['latitude' => self::LAT, 'longitude' => self::LNG];

        [$s1, $d1] = $this->online('2026-10-27 12:31:00')->pulang($auth, $di);
        $this->assertSame([201, null, '12:31:00'], [$s1, $d1['jam_masuk'], $d1['jam_pulang']]);

        [$s2, $d2] = $this->online('2026-10-27 12:30:59')->masuk($auth, $di);
        $this->assertSame([200, '12:30:59', '12:31:00'], [$s2, $d2['jam_masuk'], $d2['jam_pulang']]);
    }

    public function testTanpaAturanTanpaSyaratKoordinat(): void
    {
        $lain = $this->pegawaiPresensi(0, 2)['nip']; // jenis pegawai 2 tidak termasuk target_jp aturan
        $auth = $this->authPegawai($lain);

        [$status] = $this->online('2026-10-27 07:00:00')->masuk($auth, []);
        $this->assertSame(201, $status);
        $this->assertNull($this->barisDh($lain, '2026-10-27')['lat_masuk']);

        [, $data] = $this->online('2026-10-27 15:00:00')->pulang($auth, ['latitude' => '-6.3', 'longitude' => '106.9', 'akurasi' => 30]);
        $this->assertNull($data['id_lokasi_pulang']);
        $this->assertSame(['-6.3000000', '30.00'], [$this->barisDh($lain, '2026-10-27')['lat_pulang'], $this->barisDh($lain, '2026-10-27')['akurasi_pulang']], 'koordinat sah tetap disimpan');

        foreach ([['latitude' => 'abc', 'longitude' => 1], ['latitude' => 91, 'longitude' => 1], ['latitude' => 1]] as $buruk) {
            try {
                $this->online('2026-10-28 07:00:00')->masuk($auth, $buruk);
                $this->fail('koordinat tidak sah');
            } catch (ApiException $e) {
                $this->assertSame(KodeGalatPresensi::KOORDINAT_TIDAK_SAH, $e->getKode());
            }
        }

        try {
            $this->online('2026-10-28 07:00:00')->masuk($auth, ['akurasi' => -1]);
            $this->fail('akurasi tidak sah');
        } catch (ApiException $e) {
            $this->assertArrayHasKey('akurasi', (array) $e->getErrors());
        }
    }

    public function testCekLokasiSamaDenganRekamDanTidakMenulis(): void
    {
        $auth = $this->authPegawai($this->nip);
        $di   = $this->online('2026-10-27 07:00:00')->cekLokasi($auth, ['latitude' => -6.2005, 'longitude' => 106.8166667]);
        $luar = $this->online('2026-10-27 07:00:00')->cekLokasi($auth, ['latitude' => -6.2100, 'longitude' => 106.8166667]);

        $this->assertTrue($di['wajib_geofence']);
        $this->assertTrue($di['dalam_radius']);
        $this->assertFalse($luar['dalam_radius']);
        $this->assertSame(['id' => $this->lokasi, 'nama' => 'Kantor Pusat', 'jarak_m' => 1112], array_diff_key($luar['lokasi_terdekat'], ['radius' => 0]));
        $this->assertSame(0, $this->db->table('dh_online')->countAllResults(), 'cek-lokasi tidak menulis');
        $this->assertSame(0, $this->db->table('audit_logs')->where('entity', 'dh_online')->countAllResults());

        $hariIni = $this->online('2026-10-27 07:00:00')->hariIni($auth);
        $this->assertSame(['2026-10-27', '07:00:00', 0, true, false, null, true], [
            $hariIni['tanggal_lokal'], $hariIni['jam_lokal'], $hariIni['zonasi_menit'], $hariIni['boleh_masuk'], $hariIni['boleh_pulang'],
            $hariIni['presensi'], $hariIni['wajib_geofence'],
        ]);
        $this->assertSame([$this->lokasi], array_column($hariIni['lokasi'], 'id_lokasi_presensi'));
    }
}
