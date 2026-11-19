<?php

declare(strict_types=1);

namespace Tests\Presensi\Kehadiran;

use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Presensi\Kehadiran\KodeGalatPresensi;
use App\Libraries\Presensi\Kehadiran\PresensiManualService;
use App\Libraries\Presensi\Kehadiran\PresensiOnlineService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\PresensiUjiTrait;

/**
 * PRD 5A §7.5.6 (AC-D02.10): dua `masuk` bersamaan → tepat satu sukses, satu 422 `sudah_presensi_masuk`, tidak ada 500;
 * dua `pulang` bersamaan → keduanya sukses (last-wins); tambah manual bersamaan → 422 duplikat lewat jalur tangkap 1062
 * (AC-D03.4). Permintaan lain disimulasikan lewat koneksi DB kedua yang
 * menulis & commit tepat setelah pemeriksaan awal, sebelum transaksi tulis (seam `kaitSebelumTulis`). Koneksi kedua
 * melihat/menulis data test → `db-isolasi-penuh`.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class PresensiKonkurensiTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use PresensiUjiTrait;

    public function testMasukBersamaanSatuSukses(): void
    {
        $nip  = $this->pegawaiPresensi()['nip'];
        $lain = db_connect('tests', false);
        $kait = static function (string $n, string $tgl) use ($lain): void {
            $lain->table('dh_online')->insert(['nip' => $n, 'tgl' => $tgl, 'jam_masuk' => '07:00:00', 'sumber' => 1, 'created_at' => gmdate('Y-m-d H:i:s')]);
        };

        try {
            $this->service('2026-10-27 07:00:01', $kait)->masuk($this->authPegawai($nip), []);
            $this->fail('permintaan kedua harus 422');
        } catch (ApiException $e) {
            $this->assertSame([422, KodeGalatPresensi::SUDAH_PRESENSI_MASUK], [$e->getStatusCode(), $e->getKode()]);
        } finally {
            $lain->close();
        }

        $this->assertSame('07:00:00', $this->barisDh($nip, '2026-10-27')['jam_masuk'] ?? null, 'pemenang = permintaan pertama');
        $this->assertSame(1, $this->db->table('dh_online')->where('nip', $nip)->countAllResults());
    }

    public function testPulangBersamaanLastWins(): void
    {
        $nip  = $this->pegawaiPresensi()['nip'];
        $lain = db_connect('tests', false);
        $kait = static function (string $n, string $tgl) use ($lain): void {
            $lain->table('dh_online')->insert(['nip' => $n, 'tgl' => $tgl, 'jam_pulang' => '16:00:00', 'sumber' => 1, 'created_at' => gmdate('Y-m-d H:i:s')]);
        };

        try {
            [$status, $data] = $this->service('2026-10-27 16:00:05', $kait)->pulang($this->authPegawai($nip), []);
        } finally {
            $lain->close();
        }

        $this->assertSame([200, '16:00:05'], [$status, $data['jam_pulang']]);
    }

    /**
     * M8 MAKE-035 (AC-D03.4, jalur tangkap 1062): tambah manual `(nip, tgl)` yang lolos pra-cek tetapi didahului
     * permintaan lain sebelum INSERT → 422 `errors.tgl` pesan duplikat (bukan 500), hanya satu baris.
     */
    public function testTambahManualBersamaanDuplikat422(): void
    {
        $nip  = $this->pegawaiPresensi()['nip'];
        $lain = db_connect('tests', false);
        $kait = static function (string $n, string $tgl) use ($lain): void {
            $lain->table('dh_online')->insert(['nip' => $n, 'tgl' => $tgl, 'jam_masuk' => '07:00:00', 'sumber' => 2, 'created_at' => gmdate('Y-m-d H:i:s')]);
        };
        $jam = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-27 09:00:00', new DateTimeZone('Asia/Jakarta'));

        try {
            (new PresensiManualService(null, null, $jam, $kait))->tambah($this->authAdmin(4675), ['nip' => $nip, 'tgl' => '2026-10-01', 'jam_masuk' => '08:00']);
            $this->fail('tambah kedua harus 422 duplikat');
        } catch (ValidationException $e) {
            $this->assertSame([PresensiManualService::PESAN_DUPLIKAT], $e->getErrors()['tgl'] ?? null);
        } finally {
            $lain->close();
        }

        $this->assertSame('07:00:00', $this->barisDh($nip, '2026-10-01')['jam_masuk'] ?? null, 'baris pemenang tidak tertimpa');
        $this->assertSame(1, $this->db->table('dh_online')->where('nip', $nip)->countAllResults());
    }

    /**
     * CR-161 (B-09): ketuk ganda. Permintaan ini menjadi korban deadlock (1213) saat mengunci baris, sementara permintaan
     * pemenang commit. Transaksi diulang sekali dan menemukan baris pemenang → 422 `sudah_presensi_masuk`, bukan 500;
     * tidak ada entri audit dari permintaan yang kalah.
     */
    public function testMasukKorbanDeadlockDiulangSekaliMenjadi422(): void
    {
        $nip  = $this->pegawaiPresensi()['nip'];
        $lain = db_connect('tests', false);
        $kali = 0;
        $kait = static function (int $percobaan) use ($lain, $nip, &$kali): void {
            $kali++;

            if ($percobaan === 0) {
                throw new DatabaseException('Deadlock found when trying to get lock; try restarting transaction', 1213);
            }

            $lain->table('dh_online')->insert(['nip' => $nip, 'tgl' => '2026-10-27', 'jam_masuk' => '07:00:00', 'sumber' => 1, 'created_at' => gmdate('Y-m-d H:i:s')]);
        };

        try {
            $this->service('2026-10-27 07:00:01', null, $kait)->masuk($this->authPegawai($nip), []);
            $this->fail('korban deadlock harus 422 sesudah diulang');
        } catch (ApiException $e) {
            $this->assertSame([422, KodeGalatPresensi::SUDAH_PRESENSI_MASUK], [$e->getStatusCode(), $e->getKode()]);
        } finally {
            $lain->close();
        }

        $this->assertSame(2, $kali, 'transaksi diulang tepat sekali');
        $this->assertSame('07:00:00', $this->barisDh($nip, '2026-10-27')['jam_masuk'] ?? null);
        $this->assertSame(0, $this->jumlahAudit($nip, '2026-10-27'), 'permintaan yang kalah tidak meninggalkan audit');
    }

    /**
     * CR-161 (B-09): lock wait timeout (1205) pada percobaan pertama → diulang sekali dan berhasil; respons kasus normal
     * (201 + jam) dan tepat satu entri audit.
     */
    public function testPulangLockWaitDiulangSekaliSatuAudit(): void
    {
        $nip  = $this->pegawaiPresensi()['nip'];
        $kali = 0;
        $kait = static function (int $percobaan) use (&$kali): void {
            $kali++;

            if ($percobaan === 0) {
                throw new DatabaseException('Lock wait timeout exceeded; try restarting transaction', 1205);
            }
        };

        [$status, $data] = $this->service('2026-10-27 16:00:05', null, $kait)->pulang($this->authPegawai($nip), []);

        $this->assertSame([201, null, '16:00:05'], [$status, $data['jam_masuk'], $data['jam_pulang']]);
        $this->assertSame(2, $kali);
        $this->assertSame(1, $this->db->table('dh_online')->where('nip', $nip)->countAllResults());
        $this->assertSame(1, $this->jumlahAudit($nip, '2026-10-27'), 'satu entri audit per tulis');
    }

    /**
     * CR-161 (B-09): galat kunci yang berulang berhenti sesudah satu kali ulang (seperti duplikat), tanpa baris dan tanpa
     * audit; galat DB lain tidak diulang.
     */
    public function testGalatKunciBerulangBerhentiDanGalatLainTidakDiulang(): void
    {
        $nip  = $this->pegawaiPresensi()['nip'];
        $kali = 0;
        $kait = static function () use (&$kali): void {
            $kali++;

            throw new DatabaseException('Deadlock found when trying to get lock; try restarting transaction', 1213);
        };

        try {
            $this->service('2026-10-27 07:00:01', null, $kait)->masuk($this->authPegawai($nip), []);
            $this->fail('galat kunci berulang harus dilempar');
        } catch (RuntimeException $e) {
            $this->assertSame('Presensi bentrok berulang.', $e->getMessage());
        }

        $this->assertSame(2, $kali, 'hanya satu kali ulang');

        $kali = 0;
        $lain = static function () use (&$kali): void {
            $kali++;

            throw new DatabaseException('Column cannot be null', 1048);
        };

        try {
            $this->service('2026-10-27 07:00:01', null, $lain)->masuk($this->authPegawai($nip), []);
            $this->fail('galat DB lain harus diteruskan');
        } catch (DatabaseException $e) {
            $this->assertSame(1048, $e->getCode());
        }

        $this->assertSame(1, $kali, 'galat bukan kunci tidak diulang');
        $this->assertNull($this->barisDh($nip, '2026-10-27'));
        $this->assertSame(0, $this->jumlahAudit($nip, '2026-10-27'));
    }

    private function jumlahAudit(string $nip, string $tgl): int
    {
        return $this->db->table('audit_logs')->where(['entity' => 'dh_online', 'entity_id' => "{$nip}:{$tgl}"])->countAllResults();
    }

    private function service(string $wib, ?\Closure $kait, ?\Closure $kaitAwalTransaksi = null): PresensiOnlineService
    {
        return new PresensiOnlineService(null, null, static fn (): DateTimeImmutable => new DateTimeImmutable($wib, new DateTimeZone('Asia/Jakarta')), $kait, $kaitAwalTransaksi);
    }
}
