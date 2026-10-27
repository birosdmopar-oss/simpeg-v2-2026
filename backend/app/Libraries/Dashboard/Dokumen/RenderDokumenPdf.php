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

    /**
     * Bobot lebar kolom PDF (relatif; dinormalkan ke TEPAT 100% bersama kolom No). NIP 18 digit selalu muat satu baris
     * (CR-064 T9); kolom teks bebas (nama, jabatan, unit/satker, pendidikan, diklat, satker) cukup lebar untuk kata
     * terpanjang yang lazim ("Pengembangan", "Kepemimpinan") tanpa terpotong di tengah (CR-066 R3-2, diuji terhadap
     * lebar huruf TCPDF); kolom tanpa bobot memakai BOBOT_BAWAAN.
     *
     * @var array<string, int>
     */
    public const BOBOT = [
        'no'            => 3,
        'nama'          => 10,
        'nip'           => 11,
        'pangkat_gol'   => 8,
        'tmtsk_pangkat' => 6,
        'jabatan'       => 11,
        'tmtsk_jabatan' => 6,
        'unit_satker'   => 11,
        'pendidikan'    => 9,
        'tmtsk_cpns'    => 6,
        'masa_kerja'    => 6,
        'lahir_usia'    => 7,
        'diklat'        => 9,
        'tgl_pensiun'   => 6,
        'tgl_lahir'     => 7,
        'satker'        => 14,
        'eselon'        => 6,
        'pensiun_th'    => 6,
        'pensiun_bl'    => 6,
        'usia_th'       => 6,
        'usia_bl'       => 6,
    ];

    public const BOBOT_BAWAAN = 8;

    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * @param array<string, string>        $kolom   key → label (KolomDokumen::kolomPdf)
     * @param list<array<string, string>>  $baris   hasil KolomDokumen::baris()
     * @param array{judul?: string, subjudul?: string, teks?: string, proyeksi?: array{tahun: int, sampai: int}} $ekstra  judul bebas (Baperjakat), rentang proyeksi (Pensiun)
     */
    public function render(string $jenis, array $kolom, array $baris, LingkupStatistik $lingkup, string $hariIni, string $pencetak, array $ekstra = []): string
    {
        $judul = $ekstra['judul'] ?? KolomDokumen::JUDUL[$jenis] ?? 'Dokumen';
        $html  = view('pdf/dokumen_daftar', [
            'judul'    => $judul,
            'subjudul' => $ekstra['subjudul'] ?? $this->subjudul($lingkup, $jenis, $ekstra['proyeksi'] ?? null),
            'teks'     => $ekstra['teks'] ?? '',
            'periode'  => KolomDokumen::tanggal($hariIni),
            'jumlah'   => count($baris),
            'tabel'    => self::tabel($kolom, $baris),
        ]);
        $kaki = self::kaki($pencetak);

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
     * Kaki setiap halaman: "Dicetak <waktu> oleh <username> — SIMPEG v2" di kiri dan "Halaman x dari y" di kanan
     * (token diisi `TcpdfRenderer::Footer`, CR-064 T7/T8; PRD §7.2.6).
     */
    public static function kaki(string $pencetak): string
    {
        return '<table width="100%"><tr>'
            . '<td width="75%" style="font-size:7pt;">Dicetak ' . esc(date('d M Y H:i')) . ' oleh ' . esc($pencetak) . ' &mdash; SIMPEG v2</td>'
            . '<td width="25%" style="font-size:7pt;text-align:right;">Halaman ' . DokumenPdf::TOKEN_HALAMAN . ' dari ' . DokumenPdf::TOKEN_TOTAL . '</td>'
            . '</tr></table>';
    }

    /**
     * Lebar kolom (persen bulat) untuk "No" + $kolom; jumlah TEPAT 100 (sisa pembulatan ke kolom terlebar), supaya
     * header dan baris data sejajar dan tidak ada sel kosong di ujung kanan.
     *
     * @param list<string> $kunci key kolom data (tanpa No)
     *
     * @return array{no: int, kolom: array<string, int>}
     */
    public static function lebarKolom(array $kunci): array
    {
        $bobot = ['no' => self::BOBOT['no']];

        foreach ($kunci as $k) {
            $bobot[$k] = self::BOBOT[$k] ?? self::BOBOT_BAWAAN;
        }

        $total    = array_sum($bobot);
        $lebar    = array_map(static fn (int $b): int => (int) floor($b / $total * 100), $bobot);
        $sisa     = 100 - array_sum($lebar);
        $terlebar = array_search(max($lebar), $lebar, true);
        $lebar[$terlebar === false ? 'no' : $terlebar] += $sisa;

        $no = $lebar['no'];
        unset($lebar['no']);

        return ['no' => $no, 'kolom' => $lebar];
    }

    /**
     * Tabel HTML writeHTML: nomor urut + kolom; font 7pt, header diulang tiap halaman (`thead`). Lebar setiap kolom
     * dihitung SEKALI (`lebarKolom`) dan dipasang sama pada `<th>` DAN `<td>` — TCPDF menghitung ulang lebar baris data
     * bila hanya header yang diberi lebar (CR-064 T1).
     *
     * @param array<string, string>       $kolom
     * @param list<array<string, string>> $baris
     */
    public static function tabel(array $kolom, array $baris): string
    {
        $lebar = self::lebarKolom(array_keys($kolom));
        $h     = '<table border="1" cellpadding="3" cellspacing="0" style="font-size:7pt;" width="100%"><thead><tr style="background-color:#e5e7eb;font-weight:bold;"><th width="' . $lebar['no'] . '%">No</th>';

        foreach ($kolom as $k => $label) {
            $h .= '<th width="' . $lebar['kolom'][$k] . '%">' . esc($label) . '</th>';
        }

        $h .= '</tr></thead><tbody>';
        $no = 0;

        foreach ($baris as $r) {
            $no++;
            $h .= '<tr><td width="' . $lebar['no'] . '%">' . $no . '</td>';

            foreach (array_keys($kolom) as $k) {
                $h .= '<td width="' . $lebar['kolom'][$k] . '%">' . esc($r[$k] ?? '') . '</td>';
            }

            $h .= '</tr>';
        }

        if ($baris === []) {
            $h .= '<tr><td colspan="' . (count($kolom) + 1) . '" style="text-align:center;">Tidak ada data</td></tr>';
        }

        return $h . '</tbody></table>';
    }

    /**
     * @param array{tahun: int, sampai: int}|null $proyeksi rentang proyeksi (Pensiun) → disebut di subjudul
     */
    private function subjudul(LingkupStatistik $lingkup, string $jenis, ?array $proyeksi = null): string
    {
        $nama = $lingkup->nama;

        if ($jenis === KolomDokumen::PENSIUN) {
            return $proyeksi === null ? $nama : $nama . ' · Proyeksi pensiun ' . $proyeksi['tahun'] . ($proyeksi['sampai'] === $proyeksi['tahun'] ? '' : ' s.d. ' . $proyeksi['sampai']);
        }

        return $lingkup->tipe === LingkupStatistik::KEMENTERIAN ? 'Seluruh ' . $nama : $nama;
    }
}
