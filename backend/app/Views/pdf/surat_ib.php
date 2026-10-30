<?php

declare(strict_types=1);

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;

/**
 * Surat Izin Belajar (PRD Fase 4 §6.8.1) — port templat legacy `views/hr/employee/rwy/ib/print/surat_ib.php` [K];
 * semua nilai di-escape. Kop dari `KopDokumen` (pemanggil).
 *
 * @var array<string, mixed>                                    $ib
 * @var array{nip: string, nama: string, jabatan: string}|null $kabiro
 * @var string                                                  $tujuan unit kepegawaian (tujuan laporan)
 */
$tgl = static fn (?string $t): string => $t === null || $t === '' ? '' : TanggalBisnis::formatIndonesia(\App\Libraries\Waktu\StempelUtc::tanggalWib($t) ?? substr($t, 0, 10));
$e   = static fn (mixed $v): string => esc((string) ($v ?? ''));
$ada = static fn (mixed $v): bool => $v !== null && trim((string) $v) !== '';

$dasar = "{$e($ib['ds_ib'])} nomor: {$e($ib['no_ds_ib'])}";

if ($ada($ib['tgl_ds_ib'] ?? null)) {
    $dasar .= ' tanggal ' . $tgl((string) $ib['tgl_ds_ib']);
}

if ($ada($ib['perihal_ds_ib'] ?? null)) {
    $dasar .= ' perihal ' . $e($ib['perihal_ds_ib']);
}

// Tahun akademik [K]: tahun tgl_mulai s.d. tahun tgl_akhir dipisah "/".
$tahunAkademik = implode('/', range((int) substr((string) $ib['tgl_mulai'], 0, 4), max((int) substr((string) $ib['tgl_mulai'], 0, 4), (int) substr((string) $ib['tgl_akhir'], 0, 4))));
$laporan       = $e($tujuan) . ' u.p. ' . $e($ib['up']) . ($ada($ib['tembusan'] ?? null) ? ' dengan tembusan ' . $e($ib['tembusan']) : '');
?>
<style>
.tabel td { vertical-align: top; line-height: 1.3; font-size: 11.5px; }
</style>
<div style="font-size: 11.5px; text-align: center;"><b><u>SURAT IZIN BELAJAR</u></b><br/>Nomor: <?= $e($ib['no_sk']) ?></div>
<br/><br/>
<table class="tabel" width="100%" cellpadding="1">
    <tr><td width="15%">DASAR</td><td width="3%" style="text-align: center;">:</td><td width="3%">1.</td><td width="79%"><?= $dasar ?>;</td></tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td><td>2.</td><td>Untuk Kepentingan Dinas.</td></tr>
</table>
<br/>
<div style="font-size: 11.5px; font-weight: bold; text-align: center;">MEMBERIKAN IZIN</div>
<br/>
<table class="tabel" width="100%" cellpadding="1">
    <tr><td width="15%">KEPADA</td><td width="3%" style="text-align: center;">:</td><td width="3%">-</td><td width="15%">Nama</td><td width="3%" style="text-align: center;">:</td><td width="61%"><?= $e($ib['nama']) ?></td></tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td><td>-</td><td>NIP</td><td style="text-align: center;">:</td><td><?= $e($ib['nip']) ?></td></tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td><td>-</td><td>Pangkat/Gol</td><td style="text-align: center;">:</td><td><?= $e($ib['pangkat']) ?> (<?= $e($ib['gol_ruang']) ?>)</td></tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td><td>-</td><td>Jabatan</td><td style="text-align: center;">:</td><td><?= $e($ib['jabatan']) ?></td></tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td><td>-</td><td>Satuan Kerja</td><td style="text-align: center;">:</td><td><?= $e($ib['satker']) ?></td></tr>
</table>
<br/><br/>
<table class="tabel" width="100%" cellpadding="1">
    <tr>
        <td width="15%">UNTUK</td><td width="3%" style="text-align: center;">:</td><td width="3%">1.</td>
        <td width="79%" style="text-align: justify;">Mengikuti Program Studi <i><?= $e($ib['jurusan_pendidikan']) ?></i> (<?= $e($ib['jenjang_pendidikan_singkat']) ?>) di <i><?= $e($ib['institusi_pendidikan']) ?></i> tahun akademik <?= $tahunAkademik ?>, dengan ketentuan sebagai berikut :<br/>
            <table class="tabel" width="100%" cellpadding="1">
                <tr><td width="3%">a.</td><td width="97%" style="text-align: justify;">Dilaksanakan di luar jam kerja;</td></tr>
                <tr><td>b.</td><td style="text-align: justify;">Dilaksanakan atas biaya sendiri;</td></tr>
                <tr><td>c.</td><td style="text-align: justify;">Tidak mengganggu kelancaran tugas pokok dan tidak mengurangi tanggung jawab yang harus dilaksanakan sehari-hari sesuai tugas dan fungsi jabatan.</td></tr>
            </table>
        </td>
    </tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td><td>2.</td><td style="text-align: justify;">Secara periodik 6 (enam) bulan sekali wajib membuat laporan secara tertulis hasil yang dicapai, dengan melampirkan kartu hasil Studi (KHS), dan disampaikan kepada <?= $laporan ?></td></tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td><td>3.</td><td style="text-align: justify;">Setelah selesai melaporkan hasilnya kepada Dinas/satuan Kerja.</td></tr>
</table>
<br/><br/><br/>
<table class="tabel" width="100%" cellpadding="1">
    <tr>
        <td width="65%">&nbsp;</td>
        <td width="35%" style="text-align: center;">Ditetapkan di Jakarta<br/>pada tanggal <?= $tgl((string) ($ib['updated_at'] ?? $ib['created_at'] ?? '')) ?><br/><?= $e($kabiro['jabatan'] ?? '') ?><br/><br/><br/><br/><br/><br/><br/><u><?= $e($kabiro['nama'] ?? '') ?></u><br/>NIP. <?= $e($kabiro['nip'] ?? '') ?></td>
    </tr>
</table>
<?php if ($ada($ib['tembusan'] ?? null)): ?>
<br/><div style="font-size: 11.5px; line-height: 1.2;">Tembusan Yth :<br/><?= nl2br($e($ib['tembusan'])) ?></div>
<?php endif ?>
