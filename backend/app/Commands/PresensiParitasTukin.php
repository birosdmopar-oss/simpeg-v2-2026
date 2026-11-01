<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\OpsiCli;
use App\Libraries\Presensi\Laporan\ParitasRekapTukin;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

/**
 * Uji paritas UP-1 rekap Tukin legacy vs v2 (PRD 5B §10 M7; prosedur di `backend/docs/progress/05-Presensi.md` bagian
 * "Prosedur paritas UP-1"):
 *
 *   php spark presensi:paritas-tukin <legacy.xlsx> <v2.xlsx> [--toleransi=0.01] [--csv=<path>] [--nip-penuh] [--il]
 *
 * `--il` = rekap Instansi Lain (CR-085): berkas v2 wajib rekap IL; tanpa `--il` berkas v2 wajib rekap DH
 * menurut judul berkas **v2** ({@see ParitasRekapTukin::v2RekapIl()}); berkas legacy tidak dapat dikenali andal (judul
 * legacy IL = judul DH lingkup kementerian), jadi pastikan berkas legacy diekspor dari menu Laporan Tukin Instansi Lain.
 * Berkas v2 tidak cocok → kode keluar 2.
 *
 * Hanya membaca dua berkas lokal; tidak menyentuh database. Keluaran memakai NIP tersamar kecuali `--nip-penuh` (khusus
 * telaah lokal, jangan dibagikan). Kode keluar 0 = semua baris sama, 1 = ada temuan, 2 = masukan salah (termasuk opsi
 * tak dikenal / tanpa nilai). Opsi bernilai menerima `--k=v` maupun `--k v` (CR-080, `OpsiCli`).
 */
class PresensiParitasTukin extends BaseCommand
{
    protected $group       = 'Presensi';
    protected $name        = 'presensi:paritas-tukin';
    protected $description = 'Bandingkan XLSX rekap Tukin legacy dengan XLSX rekap Tukin v2 (uji paritas UP-1).';
    protected $usage       = 'presensi:paritas-tukin <legacy.xlsx> <v2.xlsx> [--toleransi=0.01] [--csv=<path>] [--nip-penuh] [--il]';
    protected $arguments   = [
        'legacy' => 'XLSX ekspor rekap Tukin legacy (laporan_tukin_us_skp, atau _il dengan --il)',
        'v2'     => 'XLSX GET presensi/tukin/rekap/xlsx (atau rekap IL dengan --il) untuk bulan & lingkup yang sama',
    ];
    protected $options = [
        '--toleransi' => 'Selisih maksimum per kolom persen yang dianggap sama (bawaan 0.01)',
        '--csv'       => 'Tulis temuan ke berkas CSV',
        '--nip-penuh' => 'Tampilkan NIP penuh (telaah lokal saja; bawaan NIP tersamar)',
        '--il'        => 'Bandingkan rekap Instansi Lain (berkas v2 wajib rekap IL; legacy dari menu Laporan Tukin Instansi Lain)',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['toleransi' => true, 'csv' => true, 'nip-penuh' => false, 'il' => false]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return 2;
        }

        $toleransi = $opsi['toleransi'] ?? '0.01';
        $csv       = $opsi['csv'] ?? null;
        $nipPenuh  = isset($opsi['nip-penuh']);

        if (count($argumen) !== 2 || ! is_numeric($toleransi) || (float) $toleransi < 0) {
            CLI::error('Pemakaian: ' . $this->usage);

            return 2;
        }

        [$legacy, $v2] = $argumen;

        $jenis = isset($opsi['il']) ? 'il' : 'dh';

        try {
            if (ParitasRekapTukin::v2RekapIl($v2) !== ($jenis === 'il')) {
                CLI::error(sprintf('Berkas v2 bukan rekap %s%s.', strtoupper($jenis), $jenis === 'il' ? '' : ' (untuk rekap Instansi Lain pakai --il)'));

                return 2;
            }

            $hasil = ParitasRekapTukin::bandingkan(ParitasRekapTukin::baca($legacy), ParitasRekapTukin::baca($v2), (float) $toleransi, $nipPenuh);
        } catch (InvalidArgumentException|ReaderException $e) {
            CLI::error($e->getMessage());

            return 2;
        }

        $r = $hasil['ringkas'];
        CLI::write(sprintf(
            'Rekap %s — baris legacy: %d, v2: %d | sama: %d, beda: %d, hanya legacy: %d, hanya v2: %d, galat v2: %d, galat legacy: %d (toleransi %s)',
            strtoupper($jenis),
            $r['legacy'],
            $r['v2'],
            $r['sama'],
            $r['beda'],
            $r['hanya_legacy'],
            $r['hanya_v2'],
            $r['galat_v2'],
            $r['galat_legacy'],
            $toleransi,
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
