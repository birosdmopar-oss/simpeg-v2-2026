<?php
/**
 * Templat PDF Daftar Riwayat Hidup (B-20 penutup, MAKE-014) — port `views/hr/employee/print/drh_pdf.php` legacy untuk
 * writeHTML TCPDF. Semua data di-escape (`esc`); $fotoTag = tag <img> base64 dari DrhPegawai (atau '').
 *
 * CR-065: setiap bagian (judul + tabelnya) dibungkus `<div nobr="true">`: bila tidak muat di sisa halaman, TCPDF
 * memindahkan judul bersama tabelnya ke halaman berikut (judul tidak tertinggal sendirian di dasar halaman). Bagian yang
 * lebih panjang dari satu halaman tetap terpecah normal, dimulai di halaman baru. `nobr` tabel di dalamnya diabaikan
 * TCPDF (nobr bersarang), jadi tidak dipasang lagi.
 *
 * @var string                    $nama
 * @var array<string, mixed>      $du
 * @var string                    $lahir
 * @var string                    $alamat
 * @var list<array<string, mixed>> $bagian
 * @var array<string, mixed>|null $kgb
 * @var string                    $fotoTag
 */

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;

$tgl = static fn (mixed $v): string => is_string($v) && $v !== '' && ! str_starts_with($v, '0000') ? TanggalBisnis::formatIndonesia(substr($v, 0, 10)) : '';
$uang = static fn (mixed $v): string => is_numeric($v) && (float) $v > 0 ? 'Rp' . number_format((float) $v, 0, ',', '.') . ',-' : '';
?>
<div style="font-size:11pt;font-weight:bold;text-align:center;">DAFTAR RIWAYAT HIDUP</div>
<?php if ($fotoTag !== ''): ?>
<div style="text-align:center;"><?= $fotoTag ?></div>
<?php endif ?>
<div style="font-size:6pt;">&nbsp;</div>
<div nobr="true">
<h4>A. DATA PRIBADI</h4>
<table width="100%" cellpadding="3" border="1">
    <tr><td width="23%"><b>NIP</b></td><td width="77%"><?= esc((string) $du['nip']) ?></td></tr>
    <tr><td><b>NAMA</b></td><td><?= esc($nama) ?></td></tr>
    <tr><td><b>AGAMA</b></td><td><?= esc((string) ($du['agama'] ?? '')) ?></td></tr>
    <tr><td><b>TEMPAT LAHIR</b></td><td><?= esc($lahir) ?></td></tr>
    <tr><td><b>TANGGAL LAHIR</b></td><td><?= esc($tgl($du['tgl_lahir'] ?? null)) ?></td></tr>
    <tr><td><b>JENIS KELAMIN</b></td><td><?= (int) ($du['jenis_kelamin'] ?? 0) === 1 ? 'LAKI-LAKI' : 'PEREMPUAN' ?></td></tr>
    <tr><td><b>ALAMAT RUMAH</b></td><td><?= nl2br(esc($alamat)) ?></td></tr>
</table>
</div>
<?php foreach ($bagian as $b): ?>
<div nobr="true">
<?php if ($b['judul'] !== ''): ?>
<h4><?= esc($b['kode'] . '. ' . $b['judul']) ?></h4>
<?php endif ?>
<?php if (isset($b['sub'])): ?>
<h5><?= esc($b['sub']) ?></h5>
<?php endif ?>
<table width="100%" cellpadding="3" border="1">
    <tr style="font-weight:bold;">
        <?php foreach ($b['kolom'] as [$label, $lebar]): ?><td width="<?= (int) $lebar ?>%"><?= esc($label) ?></td><?php endforeach ?>
    </tr>
    <?php if ($b['baris'] === []): ?>
    <tr><td width="100%" style="text-align:center;">-</td></tr>
    <?php endif ?>
    <?php foreach ($b['baris'] as $baris): ?>
    <tr>
        <?php foreach (array_values($baris) as $i => $sel): ?><td width="<?= (int) ($b['kolom'][$i][1] ?? 10) ?>%"><?= nl2br(esc((string) $sel)) ?></td><?php endforeach ?>
    </tr>
    <?php endforeach ?>
</table>
</div>
<?php if ($b['kode'] === 'I'): ?>
<div nobr="true">
<h4>J. KENAIKAN GAJI BERKALA TERAKHIR</h4>
<table width="100%" cellpadding="3" border="1">
    <tr><td width="20%"><b>TMT</b></td><td width="80%"><?= esc($tgl($kgb['tmtsk'] ?? null)) ?></td></tr>
    <tr><td><b>MKER. GOLONGAN</b></td><td><?= isset($kgb['mker_gol_th']) && (int) $kgb['mker_gol_th'] > 0 ? esc((string) $kgb['mker_gol_th']) . ' Tahun' : '' ?></td></tr>
    <tr><td><b>GAJI POKOK</b></td><td><?= esc($uang($kgb['gaji_pokok'] ?? null)) ?></td></tr>
</table>
</div>
<?php endif ?>
<?php endforeach ?>
