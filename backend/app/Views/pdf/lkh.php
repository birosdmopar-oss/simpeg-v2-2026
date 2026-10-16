<?php
/**
 * Template PDF LKH (B-12b, MAKE-013) — port `views/hr/employee/rwy/lkh/print/lkh_pdf.php` legacy untuk writeHTML TCPDF.
 * Semua data di-escape (`esc`). $qr = tag <img> QR dari Pdf::qrImg().
 *
 * @var array<string, mixed> $lkh
 * @var string               $qr
 */

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;

$status = match ((int) $lkh['status']) {
    1       => 'Disetujui',
    2       => 'Ditolak',
    3       => 'Direvisi',
    default => '-',
};
?>
<table width="100%" cellpadding="0">
    <tr><td style="font-size:10.5pt;font-weight:bold;text-align:center;">LAPORAN KERJA HARIAN</td></tr>
    <tr><td style="font-size:8pt;text-align:center;">Tanggal : <?= esc(TanggalBisnis::formatIndonesia((string) $lkh['tgl_laporan'])) ?></td></tr>
</table>
<div style="font-size:15px;">&nbsp;</div>
<table width="100%" cellpadding="2">
    <tr><td width="18%">NIP</td><td width="3%">:</td><td width="79%"><?= esc((string) $lkh['nip']) ?></td></tr>
    <tr><td>Nama</td><td>:</td><td><?= esc((string) $lkh['nama']) ?></td></tr>
    <tr><td>Unit Kerja Eselon I</td><td>:</td><td><?= esc((string) ($lkh['unit'] ?? '')) ?></td></tr>
    <tr><td>Unit Kerja Eselon II</td><td>:</td><td><?= esc((string) ($lkh['satker'] ?? '')) ?></td></tr>
</table>
<div style="font-size:10px;">&nbsp;</div>
<table border="1" width="100%" cellpadding="5">
    <tr style="font-weight:bold;">
        <td width="5%">NO</td>
        <td width="30%">KEGIATAN</td>
        <td width="28%">OUTPUT KEGIATAN</td>
        <td width="17%" style="text-align:center;">JUMLAH DISELESAIKAN</td>
        <td width="10%" style="text-align:center;">JAM MULAI</td>
        <td width="10%" style="text-align:center;">JAM SELESAI</td>
    </tr>
    <?php foreach ($lkh['kegiatan'] ?? [] as $i => $k): ?>
    <tr>
        <td><?= $i + 1 ?>.</td>
        <td><?= nl2br(esc((string) $k['kegiatan'])) ?></td>
        <td><?= nl2br(esc((string) $k['output'])) ?></td>
        <td style="text-align:center;"><?= nl2br(esc((string) $k['jumlah_diselesaikan'])) ?></td>
        <td style="text-align:center;"><?= esc((string) $k['jam_mulai']) ?></td>
        <td style="text-align:center;"><?= esc((string) $k['jam_selesai']) ?></td>
    </tr>
    <?php endforeach ?>
</table>
<div style="font-size:15px;">&nbsp;</div>
<table width="100%" cellpadding="0" nobr="true">
    <tr><td>Catatan:</td></tr>
    <tr><td><?= ($lkh['catatan'] ?? '') !== '' && $lkh['catatan'] !== null ? nl2br(esc((string) $lkh['catatan'])) : '-' ?></td></tr>
</table>
<div style="font-size:20px;">&nbsp;</div>
<table width="100%" cellpadding="0" nobr="true">
    <tr><td width="66%">&nbsp;</td><td width="34%" style="text-align:center;"><b><?= esc($status) ?></b> oleh,</td></tr>
    <tr><td></td><td style="text-align:center;"><?= $qr ?></td></tr>
    <tr><td></td><td style="text-align:center;"><?= esc((string) $lkh['nama_atasan']) ?><br /><?= esc((string) ($lkh['nip_atasan'] ?? '')) ?></td></tr>
</table>
