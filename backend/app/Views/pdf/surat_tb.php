<?php

declare(strict_types=1);

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;

/**
 * Surat Tugas Belajar (PRD Fase 4 §6.7.6) — port templat legacy `views/hr/employee/rwy/tb/print/surat_tb.php` [K];
 * semua nilai di-escape. Kop dari `KopDokumen` (pemanggil).
 *
 * @var array<string, mixed>                                    $tb
 * @var array{nip: string, nama: string, jabatan: string}|null $kabiro
 * @var string                                                  $tujuan  unit kepegawaian (tujuan laporan)
 */
$tgl = static fn (?string $t): string => $t === null || $t === '' ? '' : TanggalBisnis::formatIndonesia(substr($t, 0, 10));
$e   = static fn (mixed $v): string => esc((string) ($v ?? ''));

$dasar = "{$e($tb['ds_tb'])} nomor: {$e($tb['no_ds_tb'])}";

if (($tb['tgl_ds_tb'] ?? '') !== '' && $tb['tgl_ds_tb'] !== null) {
    $dasar .= ' tanggal ' . $tgl((string) $tb['tgl_ds_tb']);
}

if (($tb['perihal_ds_tb'] ?? '') !== '' && $tb['perihal_ds_tb'] !== null) {
    $dasar .= ' perihal ' . $e($tb['perihal_ds_tb']);
}

$laporan = $e($tujuan) . ' u.p. ' . $e($tb['up']) . (($tb['tembusan'] ?? '') !== '' && $tb['tembusan'] !== null ? ' dengan tembusan ' . $e($tb['tembusan']) : '');
?>
<style>
.tabel td { vertical-align: top; line-height: 1.3; font-size: 11.5px; }
</style>
<div style="font-size: 11.5px; text-align: center;"><b><u>SURAT TUGAS BELAJAR</u></b><br/>Nomor: <?= $e($tb['no_sk']) ?></div>
<br/><br/>
<table class="tabel" width="100%" cellpadding="1">
    <tr><td width="15%">DASAR</td><td width="3%" style="text-align: center;">:</td><td width="3%">1.</td><td width="79%"><?= $dasar ?>;</td></tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td><td>2.</td><td>Untuk Kepentingan Dinas.</td></tr>
</table>
<br/>
<div style="font-size: 11.5px; font-weight: bold; text-align: center;">MEMBERIKAN IZIN</div>
<br/>
<table class="tabel" width="100%" cellpadding="1">
    <tr><td width="15%">KEPADA</td><td width="3%" style="text-align: center;">:</td><td width="3%">-</td><td width="15%">Nama</td><td width="3%" style="text-align: center;">:</td><td width="61%"><?= $e($tb['nama']) ?></td></tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td><td>-</td><td>NIP</td><td style="text-align: center;">:</td><td><?= $e($tb['nip']) ?></td></tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td><td>-</td><td>Pangkat/Gol</td><td style="text-align: center;">:</td><td><?= $e($tb['pangkat']) ?> (<?= $e($tb['gol_ruang']) ?>)</td></tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td><td>-</td><td>Jabatan</td><td style="text-align: center;">:</td><td><?= $e($tb['jabatan']) ?></td></tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td><td>-</td><td>Satuan Kerja</td><td style="text-align: center;">:</td><td><?= $e($tb['satker']) ?></td></tr>
</table>
<br/><br/>
<table class="tabel" width="100%" cellpadding="1">
    <tr>
        <td width="15%">UNTUK</td><td width="3%" style="text-align: center;">:</td><td width="3%">1.</td>
        <td width="79%" style="text-align: justify;">Mengikuti Program Studi <i><?= $e($tb['jurusan_pendidikan']) ?></i> (<?= $e($tb['jenjang_pendidikan_singkat']) ?>) di <i><?= $e($tb['institusi_pendidikan']) ?></i>, dengan ketentuan sebagai berikut :<br/>
            <table class="tabel" width="100%" cellpadding="1">
                <tr><td width="3%">a.</td><td width="97%" style="text-align: justify;">Biaya selama mengikuti pendidikan ditanggung sepenuhnya oleh <?= $e($tb['lembaga_yang_mendanai']) ?>;</td></tr>
                <tr><td>b.</td><td style="text-align: justify;">Jangka waktu pendidikan selama <?= $e($tb['lama_tahun']) ?> tahun terhitung mulai tanggal <?= $tgl((string) $tb['tgl_mulai']) ?> s.d. <?= $tgl((string) $tb['tgl_akhir']) ?> dihitung penuh sebagai masa kerja untuk menetapkan gaji dan pensiun, sedangkan gaji aktifnya dibayarkan kepada keluarganya atau yang dikuasakan sesuai ketentuan peraturan yang berlaku;</td></tr>
                <tr><td>c.</td><td style="text-align: justify;">Kepada yang bersangkutan dibebaskan dari tugas sehari-hari untuk menyelesaikan pendidikan sesuai jadwal yang telah ditentukan;</td></tr>
                <tr><td>d.</td><td style="text-align: justify;">Secara periodik 6 (enam) bulan sekali wajib membuat laporan secara tertulis hasil yang dicapai, dengan melampirkan Kartu Hasil Studi (KHS), dan disampaikan kepada <?= $laporan ?></td></tr>
            </table>
        </td>
    </tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td><td>2.</td><td style="text-align: justify;">Setelah menyelesaikan pendidikan, dan dalam waktu 30 hari kerja wajib mengajukan laporan secara tertulis disampaikan kepada <?= $laporan ?></td></tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td><td>3.</td><td style="text-align: justify;">Bersedia mengabdikan diri kembali di instansi asal. Pengabdian diri dimaksud sekurang-kurangnya selama 5 (lima) tahun. Apabila masa tugas belajar lebih dari 2 (dua) tahun kewajiban bekerja menggunakan rumus 2 x n + 1, dimana n adalah masa tugas belajar.</td></tr>
</table>
<br/><br/><br/>
<table class="tabel" width="100%" cellpadding="1">
    <tr>
        <td width="65%">&nbsp;</td>
        <td width="35%" style="text-align: center;">Ditetapkan di Jakarta<br/>pada tanggal <?= $tgl((string) ($tb['updated_at'] ?? $tb['created_at'] ?? '')) ?><br/><?= $e($kabiro['jabatan'] ?? '') ?><br/><br/><br/><br/><br/><br/><br/><u><?= $e($kabiro['nama'] ?? '') ?></u><br/>NIP. <?= $e($kabiro['nip'] ?? '') ?></td>
    </tr>
</table>
<?php if (($tb['tembusan'] ?? '') !== '' && $tb['tembusan'] !== null): ?>
<br/><div style="font-size: 11.5px; line-height: 1.2;">Tembusan Yth :<br/><?= nl2br($e($tb['tembusan'])) ?></div>
<?php endif ?>
