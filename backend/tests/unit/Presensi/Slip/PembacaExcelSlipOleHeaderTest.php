<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Slip;

use App\Libraries\Presensi\Slip\BerkasImporDitolak;
use App\Libraries\Presensi\Slip\PembacaExcelSlip;
use App\Libraries\Presensi\Slip\PemeriksaOle;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use CodeIgniter\Test\CIUnitTestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * CR-189 — struktur OLE/CFB `.xls` divalidasi terhadap ukuran berkas SEBELUM reader vendor dibuka. `Xls::canRead` →
 * `Shared\OLERead::read()` memercayai field header (jumlah dan indeks sektor BBD/MSAT/SBD/direktori, blok awal aliran) dan
 * mengganjal string dengan nol sampai posisi indeks: `.xls` ±150 KB berheader palsu dulu meminta alokasi sampai 8 GB
 * (fatal memori, 500, bukan 422) sebelum pra-cek `.xls` berjalan.
 *
 * Berkas uji dirakit dari `.xls` sah buatan writer PhpSpreadsheet (ada aliran besar Workbook dan aliran kecil
 * SummaryInformation) dengan field header/direktori/rantai diubah satu per satu, lalu dibaca di proses PHP tersendiri
 * (`tests/_support/Presensi/anak_baca_slip.php`) pada `memory_limit` 512M (syarat deploy) dan 256M dan `max_execution_time`
 * 30, supaya galat fatal dan kemacetan terlihat sebagai kegagalan test. Yang harus: 422 `PESAN_TIDAK_TERBACA` (pesan yang
 * sudah ada), cepat, puncak memori kecil.
 *
 * @internal
 */
final class PembacaExcelSlipOleHeaderTest extends CIUnitTestCase
{
    private const NIP          = '199001012020011001';
    private const MB           = 1024 * 1024;
    private const BATAS_PUNCAK = 48 * self::MB;
    private const BATAS_DETIK  = 10.0;
    private const BATAS_MEMORI = ['512M', '256M'];
    private const LUAR         = 0x00FFFFFF; // indeks sektor jauh di luar berkas (±64 MB bila diganjal)
    private const LUAR_BESAR   = 0x3FFFFFFF; // ±4 GB bila diganjal

    /**
     * @var list<string>
     */
    private array $sementara = [];

    protected function tearDown(): void
    {
        foreach ($this->sementara as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * Setiap mutasi header/direktori/rantai menghasilkan berkas yang ditolak 422 dengan pesan baku, cepat, dan tanpa
     * lonjakan memori. Sebelum CR-189 mayoritas fatal memori (hasil ukur ada di laporan CR); sisanya (rantai melingkar,
     * berkas terpotong) sudah 422 lewat pengecualian vendor dan di sini sebagai pagar regresi.
     */
    #[DataProvider('mutasi')]
    public function testHeaderPalsuDitolakCepatDiProsesAnak(string $nama): void
    {
        $path = $this->temp('.xls');
        file_put_contents($path, $this->rakit($nama));

        $this->assertLessThan(5 * self::MB, filesize($path), 'berkas di bawah batas unggah 5 MB');

        foreach (self::BATAS_MEMORI as $batas) {
            $awal  = microtime(true);
            $hasil = $this->bacaDiProsesAnak($path, $batas);

            $this->assertSame('DITOLAK', $hasil['status'], $nama . ' pada memory_limit ' . $batas);
            $this->assertSame(PembacaExcelSlip::PESAN_TIDAK_TERBACA, $hasil['pesan']);
            $this->assertLessThan(self::BATAS_PUNCAK, $hasil['puncak'], 'puncak memori pada memory_limit ' . $batas);
            $this->assertLessThan(self::BATAS_DETIK, microtime(true) - $awal);
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function mutasi(): iterable
    {
        yield 'jumlah BBD 0x2C = 200 (> jumlah sektor)' => ['bbd_200'];
        yield 'jumlah BBD 0x2C = 0x7FFFFFFF' => ['bbd_maks'];
        yield 'jumlah BBD 0x2C = 0' => ['bbd_nol'];
        yield 'jumlah BBD negatif' => ['bbd_negatif'];
        yield 'repro reviewer: BBD 200 + MSAT ekstensi 0x00FFFFFF × 1' => ['repro'];
        yield 'jumlah BBD 150 (> 109) + blok ekstensi MSAT 0x44 di luar berkas, jumlah 0x48 = 1' => ['ekstensi_dipakai_luar'];
        yield 'jumlah BBD melebihi jumlah sektor berkas' => ['bbd_lebih'];
        yield 'jumlah ekstensi 0x48 = 0x7FFFFFFF' => ['ekstensi_maks'];
        yield 'jumlah ekstensi 0x48 negatif' => ['ekstensi_negatif'];
        yield 'blok root 0x30 di luar berkas' => ['root_luar'];
        yield 'blok root 0x30 = 0x7FFFFFFE' => ['root_maks'];
        yield 'blok root 0x30 = FREESECT (-1)' => ['root_bebas'];
        yield 'blok SBD 0x3C di luar berkas' => ['sbd_luar'];
        yield 'blok SBD 0x3C = 0x7FFFFFFF' => ['sbd_maks'];
        yield 'entri MSAT header 0x4C di luar berkas' => ['msat_luar'];
        yield 'entri MSAT header 0x4C = 0x7FFFFFFF' => ['msat_maks'];
        yield 'entri MSAT header 0x4C negatif' => ['msat_negatif'];
        yield 'rantai root melingkar' => ['rantai_root_putar'];
        yield 'rantai root putus (FREESECT)' => ['rantai_root_putus'];
        yield 'rantai root menunjuk di luar tabel' => ['rantai_root_luar'];
        yield 'rantai Workbook melingkar' => ['rantai_workbook_putar'];
        yield 'blok awal Workbook di luar berkas' => ['workbook_luar'];
        yield 'blok awal Workbook = 0x3FFFFFFF' => ['workbook_besar'];
        yield 'blok awal aliran kecil (SummaryInformation) di luar tabel SBD' => ['kecil_luar'];
        yield 'blok awal aliran kecil = 0x3FFFFFFF' => ['kecil_besar'];
        yield 'berkas terpotong tepat sesudah header' => ['potong_header'];
        yield 'berkas terpotong di tengah' => ['potong_tengah'];
        yield 'hanya tanda tangan CFB 8 byte' => ['tanda_saja'];
    }

    /**
     * Pagar: `.xls` sah (writer PhpSpreadsheet) tetap diterima, dengan nilai yang sama persis seperti reader vendor
     * langsung (tanpa pra-cek), termasuk di proses anak pada memory_limit 512M dan 256M.
     */
    public function testXlsSahTetapDiterima(): void
    {
        $path = $this->temp('.xls');
        file_put_contents($path, $this->xlsSah());

        $this->assertTrue(PemeriksaOle::lolos($path));

        $hasil = (new PembacaExcelSlip(10000))->baca($path);
        $this->assertTrue($hasil->valid);
        /** @var array{GPP: array<int, list<mixed>>, TK: array<int, list<mixed>>} $nilai */
        $nilai = $hasil->nilai;
        $this->assertSame(self::NIP, (string) $nilai['GPP'][2][0]);
        $this->assertSame('Budi Uji', $nilai['GPP'][2][3]);

        foreach (self::BATAS_MEMORI as $batas) {
            $this->assertSame('DITERIMA', $this->bacaDiProsesAnak($path, $batas)['status']);
        }
    }

    /**
     * Pagar kesetiaan ke vendor: header yang mengisi blok ekstensi MSAT (0x44/0x48) tetapi tidak memakainya (BBD ≤ 109, jadi
     * vendor tak pernah membuka blok itu) tetap diterima, dengan nilai identik. Berkas jenis ini sudah ada di lapangan
     * (nilai 0x00FFFFFF/1 di 147 KB) dan dibaca vendor tanpa masalah; menolaknya menyimpang dari main.
     */
    public function testEkstensiTakDipakaiTetapDiterima(): void
    {
        $sah = $this->temp('.xls');
        file_put_contents($sah, $this->xlsSah());
        $ekstensi = $this->temp('.xls');
        file_put_contents($ekstensi, self::isi(self::isi($this->xlsSah(), 0x44, self::LUAR), 0x48, 1));

        $this->assertTrue(PemeriksaOle::lolos($ekstensi));
        $this->assertEquals((new PembacaExcelSlip(10000))->baca($sah)->nilai, (new PembacaExcelSlip(10000))->baca($ekstensi)->nilai);

        foreach (self::BATAS_MEMORI as $batas) {
            $this->assertSame('DITERIMA', $this->bacaDiProsesAnak($ekstensi, $batas)['status']);
        }
    }

    /**
     * Pagar: berkas yang bukan CFB tidak disentuh pra-cek (lolos), jadi `.xlsx`, teks, dan berkas kosong berjalan seperti
     * sebelumnya (tolak/terima oleh jalur lama). Berkas CFB yang terpotong sebelum 512 byte ditolak.
     */
    public function testBerkasBukanCfbTidakDisentuh(): void
    {
        $teks = $this->temp('.xls');
        file_put_contents($teks, 'bukan berkas excel');
        $kosong = $this->temp('.xls');
        file_put_contents($kosong, '');
        $zip = $this->temp('.xlsx');
        file_put_contents($zip, "PK\x03\x04" . str_repeat("\0", 64));

        foreach ([$teks, $kosong, $zip] as $path) {
            $this->assertTrue(PemeriksaOle::lolos($path), basename($path));
        }

        $pendek = $this->temp('.xls');
        file_put_contents($pendek, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 100));
        $this->assertFalse(PemeriksaOle::lolos($pendek));

        try {
            (new PembacaExcelSlip(10000))->baca($teks);
            $this->fail('berkas harus ditolak');
        } catch (BerkasImporDitolak $e) {
            $this->assertSame(PembacaExcelSlip::PESAN_TIDAK_TERBACA, $e->getMessage());
        }
    }

    /**
     * Berkas CFB palsu lewat `PembacaExcelSlip::baca` langsung (tanpa proses anak): pesan baku, kejadian dicatat tanpa
     * rincian isi. Satu kasus cukup; sebaran kasus ada di test proses anak.
     */
    public function testBacaMenolakDenganPesanBaku(): void
    {
        $path = $this->temp('.xls');
        file_put_contents($path, $this->rakit('repro'));

        try {
            (new PembacaExcelSlip(10000))->baca($path);
            $this->fail('berkas harus ditolak');
        } catch (BerkasImporDitolak $e) {
            $this->assertSame(PembacaExcelSlip::PESAN_TIDAK_TERBACA, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------------------------------------------------------

    /**
     * Perakit berkas uji: salinan `.xls` sah dengan satu perubahan.
     */
    private function rakit(string $nama): string
    {
        $b = $this->xlsSah();

        switch ($nama) {
            case 'bbd_200':
                return self::isi($b, 0x2C, 200);

            case 'bbd_maks':
                return self::isi($b, 0x2C, 0x7FFFFFFF);

            case 'bbd_nol':
                return self::isi($b, 0x2C, 0);

            case 'bbd_negatif':
                return self::isi($b, 0x2C, -5);

            case 'repro':
                return self::isi(self::isi(self::isi($b, 0x2C, 200), 0x44, self::LUAR), 0x48, 1);

            case 'ekstensi_dipakai_luar':
                return self::isi(self::isi(self::isi($b, 0x2C, 150), 0x44, self::LUAR), 0x48, 1);

            case 'bbd_lebih':
                return self::isi($b, 0x2C, intdiv(strlen($b) - 512 + 511, 512) + 1);

            case 'ekstensi_maks':
                return self::isi(self::isi($b, 0x44, self::LUAR), 0x48, 0x7FFFFFFF);

            case 'ekstensi_negatif':
                return self::isi($b, 0x48, -1);

            case 'root_luar':
                return self::isi($b, 0x30, self::LUAR);

            case 'root_maks':
                return self::isi($b, 0x30, 0x7FFFFFFE);

            case 'root_bebas':
                return self::isi($b, 0x30, -1);

            case 'sbd_luar':
                return self::isi($b, 0x3C, self::LUAR);

            case 'sbd_maks':
                return self::isi($b, 0x3C, 0x7FFFFFFF);

            case 'msat_luar':
                return self::isi($b, 0x4C, self::LUAR);

            case 'msat_maks':
                return self::isi($b, 0x4C, 0x7FFFFFFF);

            case 'msat_negatif':
                return self::isi($b, 0x4C, -3);

            case 'rantai_root_putar':
                $root = self::int($b, 0x30);

                return self::isiRantai($b, $root, $root);

            case 'rantai_root_putus':
                return self::isiRantai($b, self::int($b, 0x30), -1);

            case 'rantai_root_luar':
                return self::isiRantai($b, self::int($b, 0x30), self::LUAR);

            case 'rantai_workbook_putar':
                $awal = self::int($b, self::posisiEntri($b, 'Workbook') + 0x74);

                return self::isiRantai($b, $awal, $awal);

            case 'workbook_luar':
                return self::isi($b, self::posisiEntri($b, 'Workbook') + 0x74, self::LUAR);

            case 'workbook_besar':
                return self::isi($b, self::posisiEntri($b, 'Workbook') + 0x74, self::LUAR_BESAR);

            case 'kecil_luar':
                return self::isi($b, self::posisiEntri($b, "\x05SummaryInformation") + 0x74, 5000);

            case 'kecil_besar':
                return self::isi($b, self::posisiEntri($b, "\x05SummaryInformation") + 0x74, self::LUAR_BESAR);

            case 'potong_header':
                return substr($b, 0, 512);

            case 'potong_tengah':
                return substr($b, 0, 4096);

            case 'tanda_saja':
                return substr($b, 0, 8);
        }

        throw new RuntimeException('Mutasi tidak dikenal: ' . $nama);
    }

    /**
     * `.xls` sah: writer PhpSpreadsheet dengan sheet GPP dan TK. Ada aliran besar (Workbook), aliran kecil
     * (SummaryInformation) dan SBD, jadi semua cabang pembacaan vendor terlewati.
     */
    private function xlsSah(): string
    {
        $buku = new Spreadsheet();
        $gpp  = $buku->getActiveSheet()->setTitle(KolomGaji::SHEET_GPP);
        $gpp->fromArray([['nip', 'bulan', 'tahun', 'nama', ...array_keys(KolomGaji::SUMBER_GPP)], [self::NIP, 9, 2026, 'Budi Uji', ...array_fill(0, count(KolomGaji::SUMBER_GPP), 0)]], null, 'A1', true);
        $gpp->setCellValueExplicit('A2', self::NIP, 's');
        $tk = $buku->createSheet()->setTitle(KolomGaji::SHEET_TK);
        $tk->fromArray([['nip', 'bulan', 'tahun', ...array_keys(KolomGaji::SUMBER_TK)], [self::NIP, 9, 2026, ...array_fill(0, count(KolomGaji::SUMBER_TK), 0)]], null, 'A1', true);
        $tk->setCellValueExplicit('A2', self::NIP, 's');

        // Sheet ketiga (tidak dimuat pembaca) membuat berkas lebih dari 200 sektor, supaya jumlah BBD palsu bisa di atas
        // 109 dan tetap di bawah jumlah sektor berkas.
        $acuan = $buku->createSheet()->setTitle('REF');
        $acuan->fromArray(array_map(static fn (int $i): array => ['acuan-' . $i . '-' . str_repeat('x', 40)], range(1, 4000)), null, 'A1', true);

        $path = $this->temp('.xls');
        IOFactory::createWriter($buku, 'Xls')->save($path);
        $buku->disconnectWorksheets();
        $isi = (string) file_get_contents($path);
        @unlink($path);

        return $isi;
    }

    /** Bilangan bulat 32 bit bertanda little-endian. */
    private static function int(string $data, int $pos): int
    {
        /** @var array{1: int} $u */
        $u = unpack('l', substr($data, $pos, 4));

        return $u[1];
    }

    private static function isi(string $data, int $pos, int $nilai): string
    {
        return substr_replace($data, pack('V', $nilai & 0xFFFFFFFF), $pos, 4);
    }

    /** Posisi berkas entri direktori bernama `$nama`, dicari di sektor-sektor rantai root. */
    private static function posisiEntri(string $data, string $nama): int
    {
        foreach (self::rantaiBesar($data, self::int($data, 0x30)) as $sektor) {
            for ($i = 0; $i < 4; $i++) {
                $pos = ($sektor + 1) * 512 + $i * 128;
                $d   = substr($data, $pos, 128);
                $n   = ord($d[0x40]) | (ord($d[0x41]) << 8);

                if (str_replace("\0", '', substr($d, 0, $n)) === $nama) {
                    return $pos;
                }
            }
        }

        throw new RuntimeException('Entri direktori tidak ditemukan: ' . $nama);
    }

    /**
     * @return list<int>
     */
    private static function rantaiBesar(string $data, int $mulai): array
    {
        $urutan = [];

        for ($b = $mulai; $b !== -2 && count($urutan) < 1000; $b = self::entriRantai($data, $b)) {
            $urutan[] = $b;
        }

        return $urutan;
    }

    /** Entri tabel rantai besar (gabungan sektor BBD pertama dst. dari header). */
    private static function entriRantai(string $data, int $blok): int
    {
        return self::int($data, (self::int($data, 0x4C + intdiv($blok, 128) * 4) + 1) * 512 + ($blok % 128) * 4);
    }

    /** Ubah entri rantai besar `$blok` menjadi `$nilai`. */
    private static function isiRantai(string $data, int $blok, int $nilai): string
    {
        return self::isi($data, (self::int($data, 0x4C + intdiv($blok, 128) * 4) + 1) * 512 + ($blok % 128) * 4, $nilai);
    }

    /**
     * @return array{status: string, pesan: string, puncak: int}
     */
    private function bacaDiProsesAnak(string $path, string $batasMemori): array
    {
        $perintah = escapeshellarg(PHP_BINARY) . ' -d memory_limit=' . $batasMemori . ' -d max_execution_time=30 -d display_errors=1 '
            . escapeshellarg(SUPPORTPATH . 'Presensi/anak_baca_slip.php') . ' ' . escapeshellarg($path) . ' 2>&1';
        $baris = [];
        $kode  = 0;
        exec($perintah, $baris, $kode);

        $hasil = json_decode((string) end($baris), true);

        if ($kode !== 0 || ! is_array($hasil) || ! isset($hasil['status'], $hasil['pesan'], $hasil['puncak'])) {
            $this->fail('proses anak (memory_limit ' . $batasMemori . ') tidak selesai: ' . mb_substr(implode(' | ', $baris), 0, 300));
        }

        return ['status' => (string) $hasil['status'], 'pesan' => (string) $hasil['pesan'], 'puncak' => (int) $hasil['puncak']];
    }

    private function temp(string $ekstensi): string
    {
        $dasar = tempnam(sys_get_temp_dir(), 'slip');

        if ($dasar === false) {
            throw new RuntimeException('Berkas sementara gagal dibuat.');
        }

        @unlink($dasar);
        $path              = $dasar . $ekstensi;
        $this->sementara[] = $path;

        return $path;
    }
}
