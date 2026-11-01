<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Uji paritas UP-1 (PRD 5B §10 M7, F5B-9; dijalankan di 5C MAKE-054 / cutover, Q5C-06): membandingkan XLSX rekap Tukin
 * **legacy** (`laporan_tukin_us_skp` ekspor `L_presensi.php:13139-13486`) dengan XLSX rekap Tukin **v2**
 * (`GET presensi/tukin/rekap/xlsx`, {@see EksporTukinXlsx}) untuk bulan & lingkup yang sama. Kedua berkas bertata letak
 * sama: header baris 7–8 (`C7` = "NIP"), data mulai baris 9 sampai kolom NIP kosong (legenda/tanda tangan di bawahnya
 * diabaikan); kolom F..K = TL, PSW, TK, TA, CUTI, TB; L/M/N = total presensi/SKP/potongan; O = keterangan.
 *
 * Pembanding per NIP: hanya di legacy, hanya di v2, galat v2 (kolom angka kosong), beda angka (selisih > toleransi per
 * kolom), sama. Keterangan tidak dibandingkan (teks legacy berisi `<br/>` dan teks penyesuaian hard-code), hanya dibawa
 * untuk telaah. Hasil memakai **NIP tersamar** (`#` + 8 heksa SHA-256) kecuali diminta penuh — laporan paritas tanpa data
 * pribadi (Q5C-06).
 *
 * Rekap IL (CR-085): legacy `laporan_tukin_us_skp_il` memakai kode ekspor XLSX yang sama (`L_presensi.php:15097-15440`),
 * jadi pembacaannya sama. Jenis rekap ditentukan operator (`--il`); hanya berkas v2 yang divalidasi
 * ({@see self::v2RekapIl()}) karena judul legacy IL tidak dapat dibedakan dari rekap DH lingkup kementerian.
 */
final class ParitasRekapTukin
{
    /** Kolom angka yang dibandingkan → huruf kolom XLSX. */
    public const KOLOM = ['TL' => 'F', 'PSW' => 'G', 'TK' => 'H', 'TA' => 'I', 'CUTI' => 'J', 'TB' => 'K', 'total_presensi' => 'L', 'total_skp' => 'M', 'total_potongan' => 'N'];

    private const BARIS_DATA = 9;

    private function __construct()
    {
    }

    /**
     * Baca baris data rekap Tukin dari berkas XLSX (sheet aktif).
     *
     * @return array<int|string, array{angka: array<string, float>|null, keterangan: string}> per NIP (NIP angka menjadi kunci int); `angka` null = baris
     *                                                                                    tanpa angka (galat v2)
     */
    public static function baca(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Berkas tidak ditemukan: {$path}");
        }

        $pembaca = IOFactory::createReaderForFile($path);
        $pembaca->setReadDataOnly(true);

        return self::bacaSheet($pembaca->load($path)->getActiveSheet());
    }

    /**
     * Berkas **v2** adalah rekap IL bila judul A1 memuat "INSTANSI LAIN" ({@see RekapTukinService}; [V2], legacy IL tidak
     * menulisnya). Berkas legacy **tidak** dapat dikenali andal: `laporan_tukin_us_skp_il` menulis judul
     * "LAPORAN TUNJANGAN KINERJA\n<NAMA KEMENTERIAN>\nPeriode: …" (`L_presensi.php:13803`, `:15101`) — sama persis dengan
     * rekap DH lingkup kementerian (`:12075`, `:13143`); nama kementerian bisa kosong, dan judul DH unit/satker pun kosong
     * bila unit tidak ditemukan (`:12015`, `:12102`). Karena itu jenis rekap ditentukan operator lewat `--il`; hanya berkas
     * v2 yang divalidasi.
     */
    public static function v2RekapIl(string $path): bool
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Berkas tidak ditemukan: {$path}");
        }

        $pembaca = IOFactory::createReaderForFile($path);
        $pembaca->setReadDataOnly(true);

        return str_contains(strtoupper((string) $pembaca->load($path)->getActiveSheet()->getCell('A1')->getValue()), 'INSTANSI LAIN');
    }

    /**
     * @return array<int|string, array{angka: array<string, float>|null, keterangan: string}>
     */
    public static function bacaSheet(Worksheet $sheet): array
    {
        if (strtoupper(trim((string) $sheet->getCell('C7')->getValue())) !== 'NIP') {
            throw new InvalidArgumentException('Bukan XLSX rekap Tukin: sel C7 bukan "NIP".');
        }

        $hasil = [];

        for ($r = self::BARIS_DATA; ; $r++) {
            $nip = trim((string) $sheet->getCell("C{$r}")->getValue());

            if ($nip === '') {
                break;
            }

            if (isset($hasil[$nip])) {
                throw new InvalidArgumentException("NIP ganda di baris {$r}.");
            }

            $angka = [];

            foreach (self::KOLOM as $kunci => $kol) {
                $nilai         = $sheet->getCell("{$kol}{$r}")->getValue();
                $angka[$kunci] = is_numeric($nilai) ? (float) $nilai : null;
            }

            $kosong      = array_filter($angka, static fn (?float $v): bool => $v === null);
            $hasil[$nip] = [
                'angka'      => count($kosong) === count($angka) ? null : array_map(static fn (?float $v): float => $v ?? 0.0, $angka),
                'keterangan' => trim((string) $sheet->getCell("O{$r}")->getValue()),
            ];
        }

        return $hasil;
    }

    /**
     * @param array<int|string, array{angka: array<string, float>|null, keterangan: string}> $legacy
     * @param array<int|string, array{angka: array<string, float>|null, keterangan: string}> $v2
     *
     * @return array{ringkas: array{legacy: int, v2: int, sama: int, beda: int, hanya_legacy: int, hanya_v2: int, galat_v2: int, galat_legacy: int},
     *               temuan: list<array{nip: string, jenis: string, kolom: array<string, array{legacy: float, v2: float}>, keterangan_legacy: string, keterangan_v2: string}>}
     */
    public static function bandingkan(array $legacy, array $v2, float $toleransi = 0.01, bool $nipPenuh = false): array
    {
        $ringkas = ['legacy' => count($legacy), 'v2' => count($v2), 'sama' => 0, 'beda' => 0, 'hanya_legacy' => 0, 'hanya_v2' => 0, 'galat_v2' => 0, 'galat_legacy' => 0];
        $temuan  = [];
        $semua   = array_unique(array_merge(array_map('strval', array_keys($legacy)), array_map('strval', array_keys($v2))));
        sort($semua, SORT_STRING);

        foreach ($semua as $nip) {
            $l     = $legacy[$nip] ?? null;
            $b     = $v2[$nip] ?? null;
            $kolom = [];

            if ($b === null) {
                $jenis = 'hanya_legacy';
            } elseif ($l === null) {
                $jenis = 'hanya_v2';
            } elseif ($b['angka'] === null) {
                $jenis = 'galat_v2';
            } elseif ($l['angka'] === null) {
                $jenis = 'galat_legacy';
            } else {
                foreach (array_keys(self::KOLOM) as $k) {
                    if (abs($l['angka'][$k] - $b['angka'][$k]) > $toleransi + 1e-9) {
                        $kolom[$k] = ['legacy' => $l['angka'][$k], 'v2' => $b['angka'][$k]];
                    }
                }

                $jenis = $kolom === [] ? 'sama' : 'beda';
            }

            $ringkas[$jenis]++;

            if ($jenis !== 'sama') {
                $temuan[] = [
                    'nip'               => $nipPenuh ? $nip : self::samarkan($nip),
                    'jenis'             => $jenis,
                    'kolom'             => $kolom,
                    'keterangan_legacy' => $l['keterangan'] ?? '',
                    'keterangan_v2'     => $b['keterangan'] ?? '',
                ];
            }
        }

        return ['ringkas' => $ringkas, 'temuan' => $temuan];
    }

    /** NIP tersamar stabil antar-proses: `#` + 8 heksa pertama SHA-256. */
    public static function samarkan(string $nip): string
    {
        return '#' . substr(hash('sha256', $nip), 0, 8);
    }
}
