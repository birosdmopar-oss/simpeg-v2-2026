<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use Throwable;

/**
 * Pra-cek struktur berkas OLE/CFB (`.xls`) sebelum reader vendor dibuka (CR-189; README Presensi BB-2).
 *
 * Latar belakang: `IOFactory::createReaderForFile` → `Xls::canRead` → `Shared\OLERead::read()` (PhpSpreadsheet 5.10) memercayai
 * field header CFB tanpa membandingkannya dengan ukuran berkas: jumlah blok BBD (0x2C), blok root (0x30), blok SBD (0x3C),
 * blok ekstensi MSAT (0x44) dan jumlahnya (0x48), entri MSAT, serta blok awal aliran di direktori. Indeks sektor dipakai
 * sebagai `getInt4d($rantai, $indeks * 4)`, dan fungsi itu MENGGANJAL string dengan nol sampai posisi yang diminta, jadi
 * satu indeks 0x00FFFFFF–0x7FFFFFFF di berkas ±150 KB meminta alokasi 64 MB–8 GB (fatal memori, 500; bukan 422), sebelum
 * pra-cek `.xls` BB-8 sempat berjalan.
 *
 * Kelas ini MENIRU jalur baca `OLERead::read()` + `getStream()` untuk aliran yang dipakai reader Xls (Workbook, Root
 * Entry, SummaryInformation, DocumentSummaryInformation) dan memeriksa setiap indeks sebelum dipakai: indeks sektor harus
 * di dalam berkas (jumlah sektor = (ukuran − 512) / 512 dibulatkan ke atas) atau penanda akhir rantai (−2); setiap rantai
 * dijalani dengan himpunan kunjungan (sehingga panjangnya ≤ jumlah sektor dan putaran ditolak); sektor yang
 * disambung vendor menjadi tabel rantai (BBD, SBD) dan direktori harus utuh di dalam berkas, supaya penyambungan vendor
 * tidak bergeser. Semua alokasi vendor jadi terikat oleh ukuran berkas (≤ batas unggah).
 *
 * TIDAK mengubah nilai hasil baca: berkas yang lolos dibaca vendor persis seperti sebelumnya. Yang ditolak hanya berkas
 * yang pasti gagal atau meledak di vendor (indeks di luar berkas, rantai melingkar atau putus, bagian terpotong). Ragu →
 * tolak. Berkas yang tanda tangan 8 byte pertamanya BUKAN CFB tidak diperiksa di sini (dilewatkan ke reader seperti
 * sebelumnya; reader lain tidak membaca struktur OLE).
 */
final class PemeriksaOle
{
    private const TANDA        = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const SEKTOR       = 0x200; // OLERead::BIG_BLOCK_SIZE
    private const ENTRI_SEKTOR = self::SEKTOR / 4; // entri rantai per sektor
    private const AKHIR        = -2; // ENDOFCHAIN, satu-satunya penanda yang dipakai vendor
    private const MSAT_HEADER  = 109; // entri MSAT di header: (512 - 0x4C) / 4
    private const AMBANG_KECIL = 0x1000; // OLERead::SMALL_BLOCK_THRESHOLD
    private const BLOK_ENTRI   = 0x80; // OLERead::PROPERTY_STORAGE_BLOCK_SIZE

    /** @var resource|null */
    private $berkas;
    private int $ukuran    = 0;
    private int $sektor    = 0;
    private int $jumlahBbd = 0;

    /** @var array<int, int> sektor BBD menurut urutan */
    private array $bbd = [];

    /** @var list<int> sektor SBD menurut urutan rantai */
    private array $sbd = [];

    /**
     * Berkas yang bukan CFB → true (bukan urusan pra-cek ini). Berkas CFB → true hanya bila struktur yang dibaca vendor
     * terikat oleh ukuran berkas.
     */
    public static function lolos(string $path): bool
    {
        $pemeriksa = new self();

        try {
            return $pemeriksa->periksa($path);
        } catch (Throwable) {
            return false;
        } finally {
            $pemeriksa->tutup();
        }
    }

    private function tutup(): void
    {
        if (is_resource($this->berkas)) {
            fclose($this->berkas);
        }

        $this->berkas = null;
    }

    private function periksa(string $path): bool
    {
        $berkas = @fopen($path, 'rb');

        if ($berkas === false) {
            return true; // tak terbuka: biarkan jalur lama menolaknya dengan pesan yang sama
        }

        $this->berkas = $berkas;

        if (fread($berkas, 8) !== self::TANDA) {
            return true;
        }

        $ukuran = filesize($path);

        if ($ukuran === false || $ukuran < self::SEKTOR) {
            return false;
        }

        $this->ukuran = $ukuran;
        $this->sektor = intdiv($ukuran - self::SEKTOR + self::SEKTOR - 1, self::SEKTOR);

        $this->jumlahBbd = $this->int4(0x2C) ?? -1;
        $akar            = $this->int4(0x30);
        $sbd             = $this->int4(0x3C);
        $ekstensi        = $this->int4(0x44);
        $jumlahEkstensi  = $this->int4(0x48);

        if ($akar === null || $sbd === null || $ekstensi === null || $jumlahEkstensi === null
            || $this->jumlahBbd < 1 || $this->jumlahBbd > $this->sektor
            || $jumlahEkstensi < 0 || $jumlahEkstensi > $this->sektor) {
            return false;
        }

        if (! $this->bacaMsat($ekstensi, $jumlahEkstensi)) {
            return false;
        }

        // SBD dijalani vendor di setiap `read()`; direktori (root) juga.
        $this->sbd = [];

        if ($sbd !== self::AKHIR) {
            $rantai = $this->jalaniBesar($sbd);

            if ($rantai === null || ! $this->semuaUtuh($rantai)) {
                return false;
            }

            $this->sbd = $rantai;
        }

        $dir = $this->jalaniBesar($akar);

        if ($dir === null || $dir === [] || ! $this->semuaUtuh($dir)) {
            return false;
        }

        $aliran = $this->bacaDirektori($dir);

        return $aliran !== null && $this->periksaAliran($aliran);
    }

    /**
     * Tabel BBD seperti vendor: `bbdBlocks` = 109 bila ada blok ekstensi, selain itu jumlah BBD; blok ekstensi dijalani
     * `jumlahEkstensi` kali (≤ jumlah sektor), dan setiap blok ekstensi yang DIPAKAI vendor harus di dalam berkas.
     */
    private function bacaMsat(int $ekstensi, int $jumlahEkstensi): bool
    {
        $entri  = [];
        $jumlah = $jumlahEkstensi !== 0 ? self::MSAT_HEADER : $this->jumlahBbd;
        $pos    = 0x4C;

        for ($i = 0; $i < $jumlah; $i++) {
            $nilai = $this->int4($pos);

            if ($nilai === null) {
                return false;
            }

            $entri[$i] = $nilai;
            $pos += 4;
        }

        for ($j = 0; $j < $jumlahEkstensi; $j++) {
            $pos  = ($ekstensi + 1) * self::SEKTOR;
            $baca = min($this->jumlahBbd - $jumlah, self::ENTRI_SEKTOR - 1);

            // Vendor hanya membuka blok ekstensi bila ada entri yang dibaca atau penunjuk berikutnya yang diambil; header
            // yang mengisi 0x44/0x48 tetapi tidak pernah dipakai (BBD ≤ 109) dibaca vendor tanpa menyentuh ekstensi itu.
            if (($baca > 0 || $jumlah + $baca < $this->jumlahBbd) && ($ekstensi < 0 || $ekstensi >= $this->sektor)) {
                return false;
            }

            for ($i = $jumlah; $i < $jumlah + $baca; $i++) {
                $nilai = $this->int4($pos);

                if ($nilai === null) {
                    return false;
                }

                $entri[$i] = $nilai;
                $pos += 4;
            }

            $jumlah += $baca;

            if ($jumlah < $this->jumlahBbd) {
                $berikut = $this->int4($pos);

                if ($berikut === null) {
                    return false;
                }

                $ekstensi = $berikut;
            }
        }

        // Hanya $jumlahBbd entri pertama yang dipakai vendor untuk menyusun tabel rantai besar.
        for ($i = 0; $i < $this->jumlahBbd; $i++) {
            if (! isset($entri[$i]) || $entri[$i] < 0 || $entri[$i] >= $this->sektor || ! $this->utuh($entri[$i])) {
                return false;
            }

            $this->bbd[$i] = $entri[$i];
        }

        return true;
    }

    /**
     * @return list<int>|null urutan sektor rantai besar dari `$mulai`; null bila indeks di luar berkas, rantai putus
     *                        (entri di luar tabel BBD atau bukan sektor/akhir) atau berputar
     */
    private function jalaniBesar(int $mulai): ?array
    {
        $lihat  = [];
        $urutan = [];
        $blok   = $mulai;

        while ($blok !== self::AKHIR) {
            if ($blok < 0 || $blok >= $this->sektor || isset($lihat[$blok])) {
                return null;
            }

            $lihat[$blok] = true;
            $urutan[]     = $blok;
            $blok         = $this->entriBesar($blok);

            if ($blok === null) {
                return null;
            }
        }

        return $urutan;
    }

    /** Entri tabel rantai besar (gabungan sektor BBD); null bila di luar tabel. */
    private function entriBesar(int $blok): ?int
    {
        if ($blok < 0 || $blok >= $this->jumlahBbd * self::ENTRI_SEKTOR) {
            return null;
        }

        return $this->int4(($this->bbd[intdiv($blok, self::ENTRI_SEKTOR)] + 1) * self::SEKTOR + ($blok % self::ENTRI_SEKTOR) * 4);
    }

    /**
     * @return list<int>|null urutan blok kecil dari `$mulai`; indeks harus di dalam tabel SBD yang tersusun
     */
    private function jalaniKecil(int $mulai): ?array
    {
        $batas  = count($this->sbd) * self::ENTRI_SEKTOR;
        $lihat  = [];
        $urutan = [];
        $blok   = $mulai;

        while ($blok !== self::AKHIR) {
            if ($blok < 0 || $blok >= $batas || isset($lihat[$blok])) {
                return null;
            }

            $lihat[$blok] = true;
            $urutan[]     = $blok;
            $blok         = $this->int4(($this->sbd[intdiv($blok, self::ENTRI_SEKTOR)] + 1) * self::SEKTOR + ($blok % self::ENTRI_SEKTOR) * 4);

            if ($blok === null) {
                return null;
            }
        }

        return $urutan;
    }

    /**
     * Entri direktori seperti `OLERead::readPropertySets()`: nama dibersihkan dari NUL, huruf besar; entri terakhir yang
     * cocok menang (vendor menimpa indeksnya).
     *
     * @param list<int> $dir
     *
     * @return array{akar: array{int, int}|null, aliran: list<array{int, int}>}|null `[blokAwal, ukuran]`
     */
    private function bacaDirektori(array $dir): ?array
    {
        $akar  = null;
        $pakai = ['wrk' => null, 'ringkas' => null, 'dokumen' => null];

        foreach ($dir as $sektor) {
            for ($i = 0; $i < self::SEKTOR / self::BLOK_ENTRI; $i++) {
                $d = $this->baca(($sektor + 1) * self::SEKTOR + $i * self::BLOK_ENTRI, self::BLOK_ENTRI);

                if ($d === null) {
                    return null;
                }

                $namaUkuran = ord($d[0x40]) | (ord($d[0x41]) << 8);
                $nama       = str_replace("\x00", '', substr($d, 0, $namaUkuran));
                $awal       = $this->dariBiner(substr($d, 0x74, 4));
                $ukuran     = $this->dariBiner(substr($d, 0x78, 4));
                $besar      = strtoupper($nama);
                $catat      = [$awal, $ukuran];

                if ($besar === 'WORKBOOK' || $besar === 'BOOK') {
                    $pakai['wrk'] = $catat;
                } elseif ($besar === 'ROOT ENTRY' || $besar === 'R') {
                    $akar = $catat;
                }

                if ($nama === chr(5) . 'SummaryInformation') {
                    $pakai['ringkas'] = $catat;
                }

                if ($nama === chr(5) . 'DocumentSummaryInformation') {
                    $pakai['dokumen'] = $catat;
                }
            }
        }

        return ['akar' => $akar, 'aliran' => array_values(array_filter($pakai))];
    }

    /**
     * `getStream()` vendor: aliran < 4096 byte lewat rantai blok kecil di dalam aliran Root Entry; selain itu rantai
     * besar. `$aliran['aliran']` = `[blokAwal, ukuran]` Workbook, SummaryInformation, DocumentSummaryInformation.
     *
     * @param array{akar: array{int, int}|null, aliran: list<array{int, int}>} $aliran
     */
    private function periksaAliran(array $aliran): bool
    {
        foreach ($aliran['aliran'] as [$awal, $ukuran]) {
            if ($ukuran < self::AMBANG_KECIL) {
                // Aliran kecil memuat aliran Root Entry (rantai besar dari blok awal Root Entry) lebih dulu.
                if ($aliran['akar'] === null || $this->jalaniBesar($aliran['akar'][0]) === null || $this->jalaniKecil($awal) === null) {
                    return false;
                }

                continue;
            }

            if ($this->jalaniBesar($awal) === null) {
                return false;
            }
        }

        return true;
    }

    /** Sektor `$indeks` seluruhnya di dalam berkas. */
    private function utuh(int $indeks): bool
    {
        return ($indeks + 2) * self::SEKTOR <= $this->ukuran;
    }

    /** @param list<int> $sektor */
    private function semuaUtuh(array $sektor): bool
    {
        foreach ($sektor as $s) {
            if (! $this->utuh($s)) {
                return false;
            }
        }

        return true;
    }

    private function baca(int $pos, int $panjang): ?string
    {
        if ($pos < 0 || $pos + $panjang > $this->ukuran || ! is_resource($this->berkas) || fseek($this->berkas, $pos) !== 0) {
            return null;
        }

        $data = fread($this->berkas, $panjang);

        return $data !== false && strlen($data) === $panjang ? $data : null;
    }

    /** Bilangan bulat 32 bit bertanda little-endian di `$pos` (seperti `OLERead::getInt4d`); null di luar berkas. */
    private function int4(int $pos): ?int
    {
        $data = $this->baca($pos, 4);

        return $data === null ? null : $this->dariBiner($data);
    }

    private function dariBiner(string $empat): int
    {
        $nilai = ord($empat[0]) | (ord($empat[1]) << 8) | (ord($empat[2]) << 16) | (ord($empat[3]) << 24);

        return $nilai >= 0x80000000 ? $nilai - 0x100000000 : $nilai;
    }
}
