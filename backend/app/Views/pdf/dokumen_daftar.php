<?php
/**
 * Template PDF dokumen daftar F-09 (MAKE-037): DUK, Nominatif, Proyeksi Pensiun, Baperjakat — lanskap A4 writeHTML
 * TCPDF. Judul/subjudul/teks di-escape di sini; $tabel sudah berupa HTML ter-escape dari RenderDokumenPdf::tabel().
 *
 * @var string $judul
 * @var string $subjudul
 * @var string $teks
 * @var string $periode
 * @var int    $jumlah
 * @var string $tabel
 */
?>
<table width="100%" cellpadding="0">
    <tr><td style="font-size:11pt;font-weight:bold;text-align:center;"><?= esc(mb_strtoupper($judul)) ?></td></tr>
    <?php if ($subjudul !== ''): ?>
    <tr><td style="font-size:9pt;text-align:center;"><?= esc($subjudul) ?></td></tr>
    <?php endif; ?>
    <tr><td style="font-size:8pt;text-align:center;">Data per <?= esc($periode) ?> &middot; <?= esc((string) $jumlah) ?> pegawai</td></tr>
</table>
<?php if ($teks !== ''): ?>
<div style="font-size:8pt;">&nbsp;</div>
<p style="font-size:8pt;"><?= nl2br(esc($teks)) ?></p>
<?php endif; ?>
<div style="font-size:6pt;">&nbsp;</div>
<?= $tabel ?>
