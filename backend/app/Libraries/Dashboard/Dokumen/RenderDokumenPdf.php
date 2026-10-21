<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Dokumen;

use App\Libraries\Dashboard\Statistik\LingkupStatistik;
use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\KopDokumen;
use App\Libraries\Pdf\Pdf;
use CodeIgniter\Database\BaseConnection;

/**
 * PDF dokumen daftar F-09 (PRD Fase 7 F7-15, §7.2.7): lanskap A4, sinkron (TCPDF via `Pdf::renderer()`), kop
 * `KopDokumen`, kaki "Dicetak … oleh …". Tabel HTML dibangun bertahap (string builder, bukan view beriterasi) agar
 * ribuan baris tidak membebani memori view; semua nilai di-`esc()`.
 */
final class RenderDokumenPdf
{
    public const ORIENTASI = 'L';

    public const UKURAN = 'A4';

    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * @param array<string, string>        $kolom   key → label (KolomDokumen::kolomPdf)
     * @param list<array<string, string>>  $baris   hasil KolomDokumen::baris()
     * @param array{judul?: string, subjudul?: string, teks?: string} $ekstra  judul bebas (Baperjakat)
     */
    public function render(string $jenis, array $kolom, array $baris, LingkupStatistik $lingkup, string $hariIni, string $pencetak, array $ekstra = []): string
    {
        $judul = $ekstra['judul'] ?? KolomDokumen::JUDUL[$jenis] ?? 'Dokumen';
        $html  = view('pdf/dokumen_daftar', [
            'judul'    => $judul,
            'subjudul' => $ekstra['subjudul'] ?? $this->subjudul($lingkup, $jenis),
            'teks'     => $ekstra['teks'] ?? '',
            'periode'  => KolomDokumen::tanggal($hariIni),
            'jumlah'   => count($baris),
            'tabel'    => self::tabel($kolom, $baris),
        ]);
        $kaki = '<table width="100%"><tr><td style="font-size:7pt;">Dicetak ' . esc(date('d M Y H:i')) . ' oleh ' . esc($pencetak) . ' &mdash; SIMPEG v2</td></tr></table>';

        $idUnit   = $lingkup->tipe === LingkupStatistik::UNIT ? $lingkup->id : null;
        $idSatker = $lingkup->tipe === LingkupStatistik::SATKER ? $lingkup->id : null;

        return Pdf::renderer()->render(new DokumenPdf(
            $judul,
            $html,
            KopDokumen::html($idUnit, $idSatker, $this->db),
            $kaki,
            self::ORIENTASI,
            self::UKURAN,
            32.0,
            10.0,
        ));
    }

    /**
     * Tabel HTML writeHTML: nomor urut + kolom; font 7pt, header diulang tiap halaman (`thead`).
     *
     * @param array<string, string>       $kolom
     * @param list<array<string, string>> $baris
     */
    public static function tabel(array $kolom, array $baris): string
    {
        $h     = '<table border="1" cellpadding="3" cellspacing="0" style="font-size:7pt;" width="100%"><thead><tr style="background-color:#e5e7eb;font-weight:bold;"><th width="4%">No</th>';
        $lebar = (int) floor(96 / max(1, count($kolom)));

        foreach ($kolom as $label) {
            $h .= '<th width="' . $lebar . '%">' . esc($label) . '</th>';
        }

        $h .= '</tr></thead><tbody>';
        $no = 0;

        foreach ($baris as $r) {
            $no++;
            $h .= '<tr><td>' . $no . '</td>';

            foreach (array_keys($kolom) as $k) {
                $h .= '<td>' . esc($r[$k] ?? '') . '</td>';
            }

            $h .= '</tr>';
        }

        if ($baris === []) {
            $h .= '<tr><td colspan="' . (count($kolom) + 1) . '" style="text-align:center;">Tidak ada data</td></tr>';
        }

        return $h . '</tbody></table>';
    }

    private function subjudul(LingkupStatistik $lingkup, string $jenis): string
    {
        $nama = $lingkup->nama;

        return $jenis === KolomDokumen::PENSIUN ? $nama : ($lingkup->tipe === LingkupStatistik::KEMENTERIAN ? 'Seluruh ' . $nama : $nama);
    }
}
