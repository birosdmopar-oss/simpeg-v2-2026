<?php

declare(strict_types=1);

namespace Tests\Presensi\Kehadiran;

use App\Exceptions\ApiException;
use App\Libraries\Presensi\Kehadiran\KodeGalatPresensi;
use App\Libraries\Presensi\Kehadiran\ZonaSatker;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\PresensiUjiTrait;

/**
 * PRD 5A §7.5.2 (AC-D02.3): zonasi satker 0/60/120 menit → `tgl`/`jam_*` lokal; pergantian tanggal (22:30 WIB + 120 menit
 * = 00:30 hari berikutnya → di luar jendela pulang → 422); satker non-aktif tetap memakai zonasinya; pegawai tanpa jabatan
 * → zonasi 0; jam klien diabaikan (instan server).
 *
 * @internal
 */
final class PresensiZonasiTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use PresensiUjiTrait;

    public function testZonasiMenggeserJamLokal(): void
    {
        foreach ([0 => '07:00:00', 60 => '08:00:00', 120 => '09:00:00'] as $zonasi => $jam) {
            $nip      = $this->pegawaiPresensi($zonasi)['nip'];
            [, $data] = $this->online('2026-10-27 07:00:00')->masuk($this->authPegawai($nip), ['jam_masuk' => '01:00:00', 'tgl' => '2020-01-01']);

            $this->assertSame(['2026-10-27', $jam], [$data['tgl'], $data['jam_masuk']], "zonasi {$zonasi}");
        }
    }

    public function testPergantianTanggalDiLuarJendela(): void
    {
        $nip = $this->pegawaiPresensi(120)['nip'];

        try {
            $this->online('2026-10-27 22:30:00')->pulang($this->authPegawai($nip), []);
            $this->fail('00:30 lokal di luar jendela pulang');
        } catch (ApiException $e) {
            $this->assertSame(KodeGalatPresensi::DI_LUAR_JENDELA, $e->getKode());
        }

        $hariIni = $this->online('2026-10-27 22:30:00')->hariIni($this->authPegawai($nip));
        $this->assertSame(['2026-10-28', '00:30:00', 120], [$hariIni['tanggal_lokal'], $hariIni['jam_lokal'], $hariIni['zonasi_menit']]);
    }

    public function testSatkerNonaktifDanTanpaJabatan(): void
    {
        $p = $this->pegawaiPresensi(60);
        $this->db->table('satker')->where('id_satker', $p['id_satker'])->update(['status' => 2]);
        $this->assertSame(60, (new ZonaSatker($this->db))->zonasi($p['nip']), 'satker non-aktif tetap dipakai');

        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $p['nip'])->delete();
        $this->assertSame(0, (new ZonaSatker($this->db))->zonasi($p['nip']), 'tanpa jabatan → 0');
    }

    public function testWaktuLokalDariInstanApaPun(): void
    {
        $utc = new DateTimeImmutable('2026-10-27 00:00:00', new DateTimeZone('UTC'));

        $this->assertSame('2026-10-27 07:00:00', ZonaSatker::waktuLokal($utc, 0)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-27 09:00:00', ZonaSatker::waktuLokal($utc, 120)->format('Y-m-d H:i:s'));
    }
}
