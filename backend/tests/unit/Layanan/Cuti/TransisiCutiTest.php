<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\Cuti;

use App\Libraries\Layanan\Cuti\AturanCuti;
use App\Libraries\Layanan\Cuti\EfekFinalCuti;
use App\Libraries\Layanan\Cuti\PengingatCuti;
use App\Libraries\Layanan\Cuti\StatusCuti;
use App\Libraries\Layanan\Cuti\TransisiCuti;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * MAKE-022 Tahap 2b — state machine cuti murni PRD Fase 4 v1.1 §6.4.2 (T4–T10, single end node), §6.4.3 (galat body),
 * T16; efek final §6.4.5 (T18); batas tunda §6.4.7 (T17).
 *
 * @internal
 */
final class TransisiCutiTest extends CIUnitTestCase
{
    public function testT4AlSetujuiDuaLangkahKe3TanpaMenulisStatusPyb(): void
    {
        $hasil = TransisiCuti::proses(0, false, 'al', 'setujui', 1);

        $this->assertTrue($hasil->valid);
        $this->assertSame(3, $hasil->detail['status']);
        $this->assertSame(1, $hasil->detail['status_al']);
        $this->assertNull($hasil->detail['status_pyb']);
        $this->assertFalse($hasil->detail['final']);
        $this->assertFalse($hasil->detail['efek_final']);
        $this->assertSame('show_atasan', $hasil->detail['flag_tampil']);
    }

    public function testT5T6AlTolakDanTangguhkanLangsungFinal(): void
    {
        $tolak = TransisiCuti::proses(0, false, 'al', 'tolak', 1, 'Beban kerja');
        $this->assertSame([2, 2, null, true, false], self::ringkas($tolak->detail));
        $this->assertSame('Beban kerja', $tolak->detail['reason_note']);

        $tangguh = TransisiCuti::proses(0, false, 'al', 'tangguhkan', 1);
        $this->assertSame([4, 4, null, true, true], self::ringkas($tangguh->detail));
    }

    public function testT7LangkahTunggalKeputusanPertamaFinal(): void
    {
        foreach (['setujui' => 1, 'tolak' => 2, 'tangguhkan' => 4] as $aksi => $status) {
            $hasil = TransisiCuti::proses(0, true, 'pyb', $aksi, 1, $aksi === 'tolak' ? 'alasan' : null);
            $this->assertTrue($hasil->valid, $aksi);
            $this->assertSame([$status, $status, $status, true, $status !== 2], self::ringkas($hasil->detail));
            $this->assertSame('show_pejabat', $hasil->detail['flag_tampil']);
        }

        // Langkah tunggal: tahap AL tidak ada.
        $this->assertFalse(TransisiCuti::proses(0, true, 'al', 'setujui', 1)->valid);
    }

    public function testT8T10PybDari3(): void
    {
        foreach (['setujui' => 1, 'tolak' => 2, 'tangguhkan' => 4] as $aksi => $status) {
            $hasil = TransisiCuti::proses(3, false, 'pyb', $aksi, 1, $aksi === 'tolak' ? 'alasan' : null);
            $this->assertTrue($hasil->valid, $aksi);
            $this->assertSame([$status, null, $status, true, $status !== 2], self::ringkas($hasil->detail));
        }
    }

    public function testTahapSalahDanFinalDitolak(): void
    {
        $pesan = TransisiCuti::PESAN_TAHAP;

        // PyB memproses 0 dua langkah; AL memproses 3; siapa pun memproses 1/2/4.
        $this->assertSame(['status', $pesan], self::galat(TransisiCuti::proses(0, false, 'pyb', 'setujui', 1)));
        $this->assertSame(['status', $pesan], self::galat(TransisiCuti::proses(3, false, 'al', 'setujui', 1)));

        foreach ([1, 2, 4] as $final) {
            foreach (['al', 'pyb'] as $tahap) {
                $this->assertSame(['status', $pesan], self::galat(TransisiCuti::proses($final, false, $tahap, 'setujui', 1)));
                $this->assertSame(['status', $pesan], self::galat(TransisiCuti::proses($final, true, $tahap, 'setujui', 1)));
            }
        }
    }

    public function testGalatBody(): void
    {
        $this->assertSame(['aksi', TransisiCuti::PESAN_AKSI], self::galat(TransisiCuti::proses(0, false, 'al', 'approve', 1)));
        $this->assertSame(['aksi', TransisiCuti::PESAN_AKSI], self::galat(TransisiCuti::proses(0, false, 'al', '', 1)));
        $this->assertSame(['reason_note', TransisiCuti::PESAN_REASON_WAJIB], self::galat(TransisiCuti::proses(0, false, 'al', 'tolak', 1)));
        $this->assertSame(['reason_note', TransisiCuti::PESAN_REASON_WAJIB], self::galat(TransisiCuti::proses(0, false, 'al', 'tolak', 1, '   ')));
        $this->assertSame(['reason_note', TransisiCuti::PESAN_REASON_PANJANG], self::galat(TransisiCuti::proses(0, false, 'al', 'tolak', 1, str_repeat('é', 128))));
        $this->assertTrue(TransisiCuti::proses(0, false, 'al', 'tolak', 1, str_repeat('a', 255))->valid);

        foreach ([2, 3, 4, 5, 6] as $jenis) {
            $this->assertSame(['aksi', TransisiCuti::PESAN_TANGGUHKAN_BUKAN_TAHUNAN], self::galat(TransisiCuti::proses(0, false, 'al', 'tangguhkan', $jenis)));
        }
    }

    public function testT16LangkahTunggalDanPelaku(): void
    {
        $this->assertTrue(TransisiCuti::langkahTunggal(null, '198'));
        $this->assertTrue(TransisiCuti::langkahTunggal('', '198'));
        $this->assertTrue(TransisiCuti::langkahTunggal('198', '198'));
        $this->assertFalse(TransisiCuti::langkahTunggal('197', '198'));

        $this->assertSame('al', TransisiCuti::tahapAktif(0, false));
        $this->assertSame('pyb', TransisiCuti::tahapAktif(0, true));
        $this->assertSame('pyb', TransisiCuti::tahapAktif(3, false));
        $this->assertNull(TransisiCuti::tahapAktif(1, false));

        $this->assertSame('197', TransisiCuti::nipPelaku(0, '197', '198'));
        $this->assertSame('198', TransisiCuti::nipPelaku(3, '197', '198'));
        $this->assertSame('198', TransisiCuti::nipPelaku(0, '198', '198'));
        $this->assertSame('197', TransisiCuti::nipPelaku(0, '197', null)); // PyB kosong → AL langkah tunggal
        $this->assertNull(TransisiCuti::nipPelaku(2, '197', '198'));
    }

    /**
     * Single end node (PRD §6.4.2): enumerasi BFS semua urutan aksi sah dari 0 (dua langkah dan tunggal); setiap jalur
     * berakhir di {1,2,4} dalam ≤ 2 keputusan, dan setiap status non-final punya ≥ 1 aksi sah oleh pelaku tahap aktif.
     */
    public function testSingleEndNodeSemuaJalur(): void
    {
        foreach ([false, true] as $tunggal) {
            $antrean   = [[0, 0]]; // [status, jumlah keputusan]
            $jalur     = 0;
            $dikunjung = [];

            while ($antrean !== []) {
                [$status, $kedalaman] = array_shift($antrean);

                if (StatusCuti::final($status)) {
                    $this->assertLessThanOrEqual(2, $kedalaman);
                    $jalur++;

                    continue;
                }

                $this->assertLessThan(2, $kedalaman, 'status non-final setelah 2 keputusan');
                $tahap = TransisiCuti::tahapAktif($status, $tunggal);
                $this->assertNotNull($tahap);
                $sah = 0;

                foreach (TransisiCuti::AKSI as $aksi) {
                    $hasil = TransisiCuti::proses($status, $tunggal, (string) $tahap, $aksi, 1, 'alasan');

                    if (! $hasil->valid) {
                        continue;
                    }

                    $sah++;
                    $this->assertTrue(StatusCuti::valid($hasil->detail['status']));
                    $antrean[] = [$hasil->detail['status'], $kedalaman + 1];
                }

                $this->assertGreaterThanOrEqual(1, $sah, "status {$status} tanpa aksi sah");
                $dikunjung[$status] = true;
            }

            $this->assertSame($tunggal ? 3 : 5, $jalur, $tunggal ? 'tunggal' : 'dua langkah');
            $this->assertSame($tunggal ? [0] : [0, 3], array_keys($dikunjung));
        }
    }

    public function testKeteranganPetugasApprovalPemisahBarisBaru(): void
    {
        $al  = TransisiCuti::barisKeterangan('setujui', 'Budi', 'Kepala Bagian');
        $pyb = TransisiCuti::barisKeterangan('tolak', 'Sari', 'Kepala Biro');

        $this->assertSame('- Disetujui Budi - (Kepala Bagian)', $al);
        $this->assertSame($al, TransisiCuti::keteranganPetugas('- teks lama', $al, 'al'));
        $this->assertSame($al . "\n" . $pyb, TransisiCuti::keteranganPetugas($al, $pyb, 'pyb'));
        $this->assertSame($pyb, TransisiCuti::keteranganPetugas(null, $pyb, 'pyb'));
        $this->assertStringNotContainsString('<br', TransisiCuti::keteranganPetugas($al, $pyb, 'pyb'));
    }

    public function testEfekFinalHanyaKe1Dan4(): void
    {
        $this->assertTrue(EfekFinalCuti::adaEfek(1));
        $this->assertTrue(EfekFinalCuti::adaEfek(4));
        $this->assertFalse(EfekFinalCuti::adaEfek(2));
        $this->assertFalse(EfekFinalCuti::adaEfek(3));
        $this->assertFalse(EfekFinalCuti::adaEfek(0));

        $this->assertSame(
            ['potong_saldo' => false, 'cltn_aktif' => false, 'surat' => false],
            EfekFinalCuti::daftar(1, 3, '2027-02-01', '2027-02-05', '2027-02-02'),
        );
        $this->assertSame(
            ['potong_saldo' => true, 'cltn_aktif' => false, 'surat' => true],
            EfekFinalCuti::daftar(1, 1, '2027-02-01', '2027-02-05', '2027-02-02'),
        );
        $this->assertSame(
            ['potong_saldo' => false, 'cltn_aktif' => true, 'surat' => true],
            EfekFinalCuti::daftar(6, 1, '2027-02-01', '2029-02-01', '2027-02-02'),
        );
    }

    public function testT18CltnAktifHariIniInklusif(): void
    {
        $this->assertTrue(EfekFinalCuti::cltnAktif(6, 1, '2027-02-01', '2029-01-31', '2027-02-01'));  // TM = hari ini
        $this->assertTrue(EfekFinalCuti::cltnAktif(6, 1, '2025-02-01', '2027-02-01', '2027-02-01'));  // TA = hari ini
        $this->assertFalse(EfekFinalCuti::cltnAktif(6, 1, '2027-02-02', '2029-01-31', '2027-02-01')); // TM = besok
        $this->assertFalse(EfekFinalCuti::cltnAktif(6, 4, '2027-02-01', '2029-01-31', '2027-02-01')); // bukan status 1
        $this->assertFalse(EfekFinalCuti::cltnAktif(1, 1, '2027-02-01', '2029-01-31', '2027-02-01')); // bukan CLTN
    }

    public function testT17BatasTundaTepat1200Boleh(): void
    {
        $aturan = AturanCuti::bawaan();

        $this->assertSame('2026-11-02 12:00:00', PengingatCuti::batasTunda('2026-11-02', $aturan));
        $this->assertTrue(PengingatCuti::bolehTunda('2026-11-02 12:00:00', '2026-11-02', $aturan));
        $this->assertFalse(PengingatCuti::bolehTunda('2026-11-02 12:00:01', '2026-11-02', $aturan));
        $this->assertTrue(PengingatCuti::bolehTunda('2026-11-01 23:59:59', '2026-11-02', $aturan));

        // Objek waktu dikonversi ke WIB (UTC+7): 05:00:00 UTC = 12:00:00 WIB.
        $this->assertTrue(PengingatCuti::bolehTunda(new DateTimeImmutable('2026-11-02 05:00:00', new DateTimeZone('UTC')), '2026-11-02', $aturan));
        $this->assertFalse(PengingatCuti::bolehTunda(new DateTimeImmutable('2026-11-02 05:00:01', new DateTimeZone('UTC')), '2026-11-02', $aturan));
        $this->assertTrue(PengingatCuti::bolehTunda(new DateTimeImmutable('2026-11-02 12:00:00', new DateTimeZone('Asia/Jakarta')), '2026-11-02', $aturan));

        $this->assertSame('skip_al', PengingatCuti::flagTahap('al'));
        $this->assertSame('skip_pyb', PengingatCuti::flagTahap('pyb'));
        $this->assertSame('Anda hanya dapat melakukan skip sebelum tanggal 2026-11-02 jam 12:00:00.', PengingatCuti::pesanTolak('2026-11-02', $aturan));
    }

    public function testStatusCutiDomain(): void
    {
        $this->assertSame([0, 1, 2, 3, 4], StatusCuti::SEMUA);
        $this->assertTrue(StatusCuti::menunggu(0));
        $this->assertTrue(StatusCuti::menunggu(3)); // T9: status 3 ikut "menunggu"
        $this->assertFalse(StatusCuti::menunggu(4));
        $this->assertSame('Diproses / Disetujui AL', StatusCuti::label(3));
        $this->assertNotContains(10, StatusCuti::SEMUA);
        $this->assertNotContains(3, StatusCuti::DOMAIN_AL);
    }

    /**
     * @param array<string, mixed> $detail
     *
     * @return array{int, int|null, int|null, bool, bool}
     */
    private static function ringkas(array $detail): array
    {
        return [$detail['status'], $detail['status_al'], $detail['status_pyb'], $detail['final'], $detail['efek_final']];
    }

    /**
     * @return array{string|null, string|null}
     */
    private static function galat(\App\Libraries\Kepegawaian\Kalkulasi\HasilKalkulasi $hasil): array
    {
        return [$hasil->valid ? null : $hasil->kode, $hasil->pesan];
    }
}
