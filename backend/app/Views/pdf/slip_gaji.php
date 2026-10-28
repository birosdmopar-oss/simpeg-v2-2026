<?php
/**
 * PDF slip gaji (PRD 5A §7.3.6) — port tata letak legacy `0cf7e67` `views/hr/employee/sl_gaji/print/cetak.php` [K]:
 * kotak dokumen berisi kop (logo kiri/kanan, nama kementerian & unit satu baris, alamat), judul "SLIP GAJI", identitas
 * + QR, rincian A. GPP (dua kolom penghasilan | potongan) → B. TK → C. Uang Makan → Ringkasan Penghasilan.
 *
 * Semua teks di-escape (`esc`); kop = HTML tersanitasi `web_config` (G-09) yang sengaja dirender sebagai HTML, dengan
 * tautan dibuang seperti legacy.
 *
 * @var App\Libraries\Presensi\Slip\DataPdfSlip                                $d
 * @var string|null                                                            $qr
 * @var array{jumlah_penghasilan_gpp: int, jumlah_potongan_gpp: int, bersih_gpp: int, bersih_tk: int, bersih_2: int, total_penghasilan: int} $ringkasan
 * @var array<string, string>                                                  $labelGpp
 * @var array<string, string>                                                  $labelTk
 * @var string                                                                 $labelUm
 * @var string                                                                 $tglCetak
 * @var string|null                                                            $dibuka
 * @var string                                                                 $logoKiri
 * @var string                                                                 $logoKanan
 */

use App\Libraries\Presensi\SlipGaji\FormatSlip;
use App\Libraries\Presensi\SlipGaji\NominalGaji;

$satuBaris = static fn (string $html): string => trim((string) preg_replace('/\s*<br\s*\/?>\s*/i', ' ', $html));
$namaKem   = mb_strtoupper(strip_tags($satuBaris($d->kop['nama_kementerian'])));
$namaUnit  = mb_strtoupper(strip_tags($satuBaris($d->kop['nama_unit'])));
$alamat    = (string) preg_replace('/<a\b[^>]*>(.*?)<\/a>/is', '$1', $d->kop['alamat']);
$rp        = static fn (int $sen): string => NominalGaji::formatAngka($sen);
$sen       = static fn (string $f): int => $d->sen[$f] ?? 0;

// Kelompok rincian [K print/cetak.php @771f8b4 :262-277; TK @0cf7e67 :274-277].
$gppGroups = [
    'Penghasilan' => [
        'Gaji Pokok'         => ['gjpokok'],
        'Tunjangan Keluarga' => ['tjistri', 'tjanak'],
        'Tunjangan Jabatan'  => ['tjstruk', 'tjfungs'],
        'Tunjangan Lainnya'  => ['tjupns', 'tjdaerah', 'tjberas', 'tjpencil', 'tjkompen', 'tjlain', 'tjpph', 'pembul'],
    ],
    'Potongan' => [
        'Iuran & Pajak'    => ['potpfkbul', 'potpfk2', 'potpfk10', 'potpph'],
        'Potongan Lainnya' => ['potswrum', 'potkelbtj', 'pottabrum', 'potlain'],
    ],
];
// TK hanya Penghasilan Kotor & Potongan; baris "Pajak" dan "Tunjangan Pajak" dihapus [K print/cetak.php @0cf7e67 :274-277]
// (CR-067). Kolom `pajak`/`tunj_pajak` tetap disimpan & diimpor; hanya tidak ditampilkan.
$tkGroups = ['Penghasilan' => ['kotor'], 'Potongan' => ['potongan']];

$baris = static fn (string $label, int $nilai, int $indent = 0, bool $neg = false): string => '<tr>'
    . '<td width="82%" align="left" style="padding-left: ' . $indent . 'px;">' . esc($label) . '</td>'
    . '<td width="6%" align="right">Rp.</td>'
    . '<td width="11.9%" align="right">' . ($neg ? '-&nbsp;' : '') . $rp($nilai) . '</td><td width="0.1%"></td></tr>';
$tint = static fn (string $label, int $nilai, string $bg, float $pt, bool $neg = false): string => '<tr bgcolor="' . $bg . '">'
    . '<td width="82%" align="left" style="font-size: ' . $pt . 'pt; padding-left: 4px;"><b>' . esc($label) . '</b></td>'
    . '<td width="6%" align="right" style="font-size: ' . $pt . 'pt;"><b>Rp.</b></td>'
    . '<td width="11.9%" align="right" style="font-size: ' . $pt . 'pt;"><b>' . ($neg ? '-&nbsp;' : '') . $rp($nilai) . '</b></td><td width="0.1%"></td></tr>';
$sp = static fn (int $px): string => '<tr><td colspan="4" style="font-size: ' . $px . 'px; line-height: ' . $px . 'px;">&nbsp;</td></tr>';
$section = static fn (string $judul): string => $sp(10)
    . '<tr><td colspan="4" style="border-top: 0.75px solid #777777; font-size: 1px; line-height: 1px;">&nbsp;</td></tr>'
    . $sp(10) . '<tr><td colspan="4" align="left" style="font-size: 8.5pt; font-weight: bold;">' . esc($judul) . '</td></tr>' . $sp(5);
$setengah = static fn (string $label, int $nilai, int $indent, bool $neg): string => '<tr>'
    . '<td width="76%" align="left" style="padding-left: ' . $indent . 'px;">' . esc($label) . '</td>'
    . '<td width="7%" align="right">Rp.</td><td width="17%" align="right">' . ($neg ? '-&nbsp;' : '') . $rp($nilai) . '</td></tr>';
$grup = static fn (string $judul): string => '<tr><td colspan="3" align="left" style="font-size: 7pt; font-weight: bold; color: #666666;">' . esc(mb_strtoupper($judul)) . '</td></tr>';
$sub  = static fn (string $judul): string => '<tr><td colspan="3" align="left" style="padding-left: 6px; font-size: 7.5pt;"><b>' . esc($judul) . '</b></td></tr>';
$tintSetengah = static fn (string $label, int $nilai, bool $neg = false): string => '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr bgcolor="#f3f4f6">'
    . '<td width="76%" align="left" style="font-size: 7.5pt; padding-left: 4px;"><b>' . esc($label) . '</b></td>'
    . '<td width="7%" align="right" style="font-size: 7.5pt;"><b>Rp.</b></td>'
    . '<td width="17%" align="right" style="font-size: 7.5pt;"><b>' . ($neg ? '-&nbsp;' : '') . $rp($nilai) . '</b></td></tr></table>';
$duaKolom = static fn (string $kiri, string $kanan): string => '<tr><td colspan="4"><table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
    . '<td width="47%" valign="top">' . $kiri . '</td><td width="6%"></td><td width="47%" valign="top">' . $kanan . '</td></tr></table></td></tr>';

$sisiGpp = [];
foreach ($gppGroups as $nama => $subGrup) {
    $pot  = $nama === 'Potongan';
    $html = $grup($nama);
    foreach ($subGrup as $judul => $field) {
        $datar = count($field) === 1 && $judul === $labelGpp[$field[0]];
        if (! $datar) {
            $html .= $sub($judul);
        }
        foreach ($field as $f) {
            $html .= $setengah($labelGpp[$f], $sen($f), $datar ? 6 : 16, $pot);
        }
    }
    $sisiGpp[$nama] = '<table width="100%" cellpadding="0" cellspacing="0" border="0">' . $html . '</table>';
}

$sisiTk = [];
foreach ($tkGroups as $nama => $field) {
    $pot  = $nama === 'Potongan';
    $html = $grup($nama);
    foreach ($field as $f) {
        $html .= $setengah($labelTk[$f], $sen($f), 6, $pot);
    }
    $sisiTk[$nama] = '<table width="100%" cellpadding="0" cellspacing="0" border="0">' . $html . '</table>';
}

$labelGppBersih = 'Gaji Pokok dan Pendapatan (GPP) Bersih';
$labelTkBersih  = 'Tunjangan Kinerja (TK) Bersih';
$identitas      = [
    'Nama' => $d->identitas['nama'], 'NIP' => $d->identitas['nip'], 'Pangkat / Gol' => $d->identitas['pangkat'],
    'Jabatan' => $d->identitas['jabatan'], 'Satuan Organisasi' => $d->identitas['unit'], 'Satuan Kerja' => $d->identitas['satker'],
];
?>
<style>
    body { font-family: helvetica; font-size: 8.5pt; color: #000000; }
    .h-title { font-size: 12pt; font-weight: bold; }
    .h-sub { font-size: 10.5pt; font-weight: bold; }
    .h-addr { font-size: 7.5pt; color: #111111; }
    .tbl-ident td { font-size: 8.5pt; line-height: 1.52; }
    .tbl-section td { font-size: 7.5pt; line-height: 1.4; }
</style>
<table width="100%" cellpadding="16" cellspacing="0" border="0" style="border: 1px solid #000000;">
<tr><td>
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td width="15%" align="left" valign="middle"><?= $logoKiri ?></td>
            <td width="70%" align="center" valign="middle">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr><td class="h-title" align="center"><?= esc($namaKem) ?></td></tr>
                    <tr><td class="h-sub" align="center"><?= esc($namaUnit) ?></td></tr>
                    <tr><td class="h-addr" align="center"><?= $alamat ?></td></tr>
                </table>
            </td>
            <td width="15%" align="right" valign="middle"><?= $logoKanan ?></td>
        </tr>
    </table>
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr><td style="border-top: 2.5px solid #000000; font-size: 1px; line-height: 1px;">&nbsp;</td></tr>
        <tr><td style="font-size: 1px; line-height: 10px;">&nbsp;</td></tr>
        <tr><td align="center" style="font-size: 8.5pt;">SLIP GAJI</td></tr>
        <tr><td style="font-size: 1px; line-height: 10px;">&nbsp;</td></tr>
    </table>

    <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td width="68%" valign="top"><table class="tbl-ident" width="100%" cellpadding="0" cellspacing="0" border="0">
<?php foreach ($identitas as $label => $nilai): ?>
                    <tr><td width="32%" align="left"><?= esc($label) ?></td><td width="3%" align="center">:</td><td width="65%" align="left"><?= esc($nilai) ?></td></tr>
<?php endforeach ?>
                </table>
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr><td style="font-size: 1px; line-height: 10px;">&nbsp;</td></tr>
                    <tr><td align="left" style="font-size: 8pt; line-height: 1.5;">adalah benar pegawai <?= esc($d->namaInstansi) ?>, mempunyai penghasilan bersih untuk bulan :</td></tr>
                </table>
                <table class="tbl-ident" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr><td width="32%" align="left">Bulan</td><td width="3%" align="center">:</td><td width="65%" align="left"><b><?= esc(explode(' ', $d->periode->label())[0]) ?></b></td></tr>
                    <tr><td align="left">Tahun</td><td align="center">:</td><td align="left"><b><?= esc((string) $d->periode->tahun) ?></b></td></tr>
                </table>
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr><td align="left" style="font-size: 8.5pt; line-height: 1.3;">Menurut Rincian di bawah ini :</td></tr>
                </table>
            </td>
            <td width="32%" valign="top" align="center">
<?php if ($qr !== null): ?>
                <table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="font-size: 1px; line-height: 1px;"><?= $qr ?></td></tr></table>
<?php endif ?>
                <table width="100%" cellpadding="3" cellspacing="0" border="0">
                    <tr><td width="24%"></td><td width="52%" align="center" style="border: 0.75px solid #777777; font-size: 7pt; color: #555555;">&nbsp;Generated by SIMPEG</td><td width="24%"></td></tr>
                </table>
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr><td align="center" style="font-size: 8.5pt; line-height: 1.3;">Jakarta, <?= esc($tglCetak) ?></td></tr>
<?php if ($dibuka !== null): ?>
                    <tr><td align="center" style="font-size: 7pt; color: #555555;">Dibuka: <?= esc($dibuka) ?></td></tr>
<?php endif ?>
                </table>
            </td>
        </tr>
    </table>

    <table class="tbl-section" width="100%" cellpadding="0" cellspacing="0" border="0">
        <?= $section('A. GAJI POKOK & PENDAPATAN (GPP)') ?>
        <?= $duaKolom($sisiGpp['Penghasilan'], $sisiGpp['Potongan']) ?>
        <?= $sp(3) ?>
        <?= $duaKolom($tintSetengah('Jumlah Penghasilan', $ringkasan['jumlah_penghasilan_gpp']), $tintSetengah('Jumlah Potongan', $ringkasan['jumlah_potongan_gpp'], true)) ?>
        <?= $sp(3) ?>
        <?= $tint($labelGppBersih, $ringkasan['bersih_gpp'], '#e4e9f0', 8) ?>

        <?= $section('B. TUNJANGAN KINERJA (TK)') ?>
        <?= $duaKolom($sisiTk['Penghasilan'], $sisiTk['Potongan']) ?>
        <?= $sp(3) ?>
        <?= $duaKolom($tintSetengah('Jumlah Penghasilan', $sen('kotor')), $tintSetengah('Jumlah Potongan', $sen('potongan'), true)) ?>
        <?= $sp(3) ?>
        <?= $tint($labelTkBersih, $ringkasan['bersih_tk'], '#e4e9f0', 8) ?>

        <?= $section('C. UANG MAKAN') ?>
        <?= $baris($labelUm, $sen('uang_makan'), 6) ?>

        <?= $section('RINGKASAN PENGHASILAN') ?>
        <tr><td colspan="4"><table width="100%" cellpadding="8" cellspacing="0" border="0" style="border: 1.2px solid #2c3e50;"><tr><td>
            <table class="tbl-section" width="100%" cellpadding="0" cellspacing="0" border="0">
                <?= $baris($labelGppBersih, $ringkasan['bersih_gpp']) ?>
                <?= $baris($labelTkBersih, $ringkasan['bersih_tk']) ?>
                <?= $baris('Uang Makan', $sen('uang_makan')) ?>
                <?= $sp(3) ?>
                <?= $tint(FormatSlip::labelTotal($d->periode), $ringkasan['total_penghasilan'], '#d5dbe3', 9) ?>
            </table>
        </td></tr></table></td></tr>
    </table>
</td></tr>
</table>
