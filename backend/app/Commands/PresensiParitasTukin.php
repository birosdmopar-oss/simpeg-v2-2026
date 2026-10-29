<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Presensi\Laporan\ParitasRekapTukin;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

/**
 * Uji paritas UP-1 rekap Tukin legacy vs v2 (PRD 5B §10 M7; prosedur di `backend/docs/progress/05-Presensi.md` bagian
 * "Prosedur paritas UP-1"):
 *
 *   php spark presensi:paritas-tukin <legacy.xlsx> <v2.xlsx> [--toleransi=0.01] [--csv=<path>] [--nip-penuh]
 *
 * Hanya membaca dua berkas lokal; tidak menyentuh database. Keluaran memakai NIP tersamar kecuali `--nip-penuh` (khusus
 * telaah lokal, jangan dibagikan). Kode keluar 0 = semua baris sama, 1 = ada temuan, 2 = masukan salah.
 */
class PresensiParitasTukin extends BaseCommand
{
    protected $group       = 'Presensi';
    protected $name        = 'presensi:paritas-tukin';
    protected $description = 'Bandingkan XLSX rekap Tukin legacy dengan XLSX rekap Tukin v2 (uji paritas UP-1).';
    protected $usage       = 'presensi:paritas-tukin <legacy.xlsx> <v2.xlsx> [--toleransi=0.01] [--csv=<path>] [--nip-penuh]';
    protected $arguments   = [
        'legacy' => 'XLSX ekspor rekap Tukin legacy (laporan_tukin_us_skp)',
        'v2'     => 'XLSX GET presensi/tukin/rekap/xlsx untuk bulan & lingkup yang sama',
    ];
    protected $options = [
        '--toleransi' => 'Selisih maksimum per kolom persen yang dianggap sama (bawaan 0.01)',
        '--csv'       => 'Tulis temuan ke berkas CSV',
        '--nip-penuh' => 'Tampilkan NIP penuh (telaah lokal saja; bawaan NIP tersamar)',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        [$legacy, $v2] = [$params[0] ?? null, $params[1] ?? null];
        $toleransi     = $params['toleransi'] ?? CLI::getOption('toleransi') ?? '0.01';
        $csv           = $params['csv'] ?? CLI::getOption('csv');
        $nipPenuh      = array_key_exists('nip-penuh', $params) || CLI::getOption('nip-penuh') !== null;

        if (! is_string($legacy) || ! is_string($v2) || ! is_numeric($toleransi) || (float) $toleransi < 0) {
            CLI::error('Pemakaian: ' . $this->usage);

            return 2;
        }

        try {
            $hasil = ParitasRekapTukin::bandingkan(ParitasRekapTukin::baca($legacy), ParitasRekapTukin::baca($v2), (float) $toleransi, $nipPenuh);
        } catch (InvalidArgumentException|ReaderException $e) {
            CLI::error($e->getMessage());

            return 2;
        }

        $r = $hasil['ringkas'];
        CLI::write(sprintf(
            'Baris legacy: %d, v2: %d | sama: %d, beda: %d, hanya legacy: %d, hanya v2: %d, galat v2: %d, galat legacy: %d (toleransi %s)',
            $r['legacy'],
            $r['v2'],
            $r['sama'],
            $r['beda'],
            $r['hanya_legacy'],
            $r['hanya_v2'],
            $r['galat_v2'],
            $r['galat_legacy'],
            (string) $toleransi,
        ), $hasil['temuan'] === [] ? 'green' : 'yellow');

        foreach ($hasil['temuan'] as $t) {
            CLI::write($t['nip'] . ' ' . $t['jenis'] . ($t['kolom'] === [] ? '' : ' ' . self::teksKolom($t['kolom'])));
        }

        if (is_string($csv) && $csv !== '' && ! self::tulisCsv($csv, $hasil['temuan'])) {
            CLI::error("Gagal menulis {$csv}");

            return 2;
        }

        return $hasil['temuan'] === [] ? EXIT_SUCCESS : EXIT_ERROR;
    }

    /**
     * @param array<string, array{legacy: float, v2: float}> $kolom
     */
    private static function teksKolom(array $kolom): string
    {
        $teks = [];

        foreach ($kolom as $k => $n) {
            $teks[] = sprintf('%s legacy=%s v2=%s', $k, (string) $n['legacy'], (string) $n['v2']);
        }

        return implode('; ', $teks);
    }

    /**
     * @param list<array{nip: string, jenis: string, kolom: array<string, array{legacy: float, v2: float}>, keterangan_legacy: string, keterangan_v2: string}> $temuan
     */
    private static function tulisCsv(string $path, array $temuan): bool
    {
        $f = @fopen($path, 'wb');

        if ($f === false) {
            return false;
        }

        fputcsv($f, ['nip', 'jenis', 'kolom', 'keterangan_legacy', 'keterangan_v2'], ',', '"', '');

        foreach ($temuan as $t) {
            fputcsv($f, [$t['nip'], $t['jenis'], self::teksKolom($t['kolom']), $t['keterangan_legacy'], $t['keterangan_v2']], ',', '"', '');
        }

        return fclose($f);
    }
}
