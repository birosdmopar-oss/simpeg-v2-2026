<?php
/**
 * Template PDF tanda terima usulan Karpeg / Karis-Karsu (MAKE-013) — port `views/hr/employee/rwy/{karpeg,kariskarsu}/
 * print/tt_pdf.php` legacy untuk writeHTML TCPDF. Semua data di-escape.
 *
 * @var string                $judul   KARPEG | KARIS/KARSU
 * @var array<string, mixed>  $baris   baris usulan (engine detail)
 * @var string                $nama
 * @var array<string, mixed>  $pegawai pangkat, gol_ruang, jabatan, unit, satker
 * @var string                $noTiket
 * @var string                $kode
 * @var array<string, string> $berkas  kolom berkas => label kelengkapan
 * @var string                $qr
 * @var string                $dicetak
 */
$tgl = static fn (?string $v): string => $v === null || $v === '' ? '-' : date('d-m-Y H:i:s', (int) strtotime($v));
?>
<table width="100%" cellpadding="0">
    <tr>
        <td width="79%">
            <table width="100%" cellpadding="2">
                <tr><td colspan="3" style="text-align:center;font-weight:bold;">TANDA TERIMA PERMOHONAN <?= esc($judul) ?> ONLINE</td></tr>
                <tr><td width="24%">No. Tiket Permohonan</td><td width="4%">:</td><td width="72%"><b><?= esc($noTiket) ?></b></td></tr>
                <?php if (isset($baris['jenis_permohonan'])): ?>
                <tr><td>Jenis Permohonan</td><td>:</td><td><?= esc((string) $baris['jenis_permohonan']) ?></td></tr>
                <?php endif ?>
                <tr><td>NIP</td><td>:</td><td><?= esc((string) $baris['nip']) ?></td></tr>
                <tr><td>Nama</td><td>:</td><td><?= esc($nama) ?></td></tr>
                <tr><td>Pangkat / Gol. Ruang</td><td>:</td><td><?= esc(trim(($pegawai['pangkat'] ?? '') . ' / ' . ($pegawai['gol_ruang'] ?? ''), ' /')) ?></td></tr>
                <tr><td>Jabatan</td><td>:</td><td><?= esc((string) ($pegawai['jabatan'] ?? '')) ?></td></tr>
                <tr><td>Unit Kerja Es.I</td><td>:</td><td><?= esc((string) ($pegawai['unit'] ?? '')) ?></td></tr>
                <tr><td>Unit Kerja Es.II</td><td>:</td><td><?= esc((string) ($pegawai['satker'] ?? '')) ?></td></tr>
                <tr><td>Tgl. Permohonan</td><td>:</td><td><?= esc($tgl($baris['created_at'] ?? null)) ?></td></tr>
                <tr><td>Tgl. Diterima</td><td>:</td><td><?= esc($tgl($baris['updated_at'] ?? null)) ?></td></tr>
            </table>
        </td>
        <td width="21%" style="text-align:center;">
            <table width="100%" cellpadding="2">
                <tr><td style="font-size:8pt;border:1px solid #000000;">Lembar Untuk: Pemohon</td></tr>
                <tr><td style="font-size:8pt;">Dicetak tanggal : <?= esc($dicetak) ?></td></tr>
                <tr><td style="font-size:8pt;">Kode Permohonan :<br /><?= esc($kode) ?></td></tr>
                <tr><td><?= $qr ?></td></tr>
            </table>
        </td>
    </tr>
</table>
<div style="font-size:20px;">&nbsp;</div>
<table border="1" width="100%" cellpadding="5">
    <tr style="font-weight:bold;font-size:8pt;">
        <td width="5%">NO</td>
        <td width="74%">KELENGKAPAN</td>
        <td width="21%" style="text-align:center;">ADA / TIDAK ADA</td>
    </tr>
    <?php $no = 1; foreach ($berkas as $kolom => $label): ?>
    <tr>
        <td><?= $no++ ?>.</td>
        <td><?= esc($label) ?></td>
        <td style="text-align:center;"><?= (string) ($baris[$kolom] ?? '') !== '' ? 'ADA' : 'TIDAK ADA' ?></td>
    </tr>
    <?php endforeach ?>
</table>
<div style="font-size:30px;">&nbsp;</div>
<table width="100%" cellpadding="1">
    <tr><td colspan="2">Catatan</td></tr>
    <tr><td width="3%">1.</td><td width="97%">Waktu yang dibutuhkan untuk menyelesaikan <?= esc($judul) ?> di Biro SDMO adalah 30 Hari Kerja (waktu penyelesaian belum ditambah dengan waktu penyelesaian dari pihak BKN).</td></tr>
    <tr><td>2.</td><td>Jika pegawai memiliki pertanyaan dapat menanyakan kepada Admin <?= esc($judul) ?> melalui fitur Halo Simpeg.</td></tr>
</table>
