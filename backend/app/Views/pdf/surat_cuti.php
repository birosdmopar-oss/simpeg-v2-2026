<?php

declare(strict_types=1);

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;

/**
 * Formulir permintaan dan pemberian cuti (PRD Fase 4 §6.5.2) — port templat legacy
 * `views/hr/employee/rwy/cuti/pdf/surat_cuti.php` [K]; semua nilai di-escape. Kop dari `KopDokumen` (pemanggil).
 * Penanda `##` pada sel penanda tangan PyB adalah tag koordinat tanda tangan (opsi `tag_koordinat` gateway e-sign).
 *
 * @var array<string, mixed> $dataset  baris `riwayat_cuti`
 * @var string               $tglSurat tanggal pengajuan (WIB, Y-m-d)
 * @var string               $qr       tag <img> QR dari Pdf::qrImg()
 */
$tgl = static fn (?string $t): string => $t === null || $t === '' ? '' : TanggalBisnis::formatIndonesia(substr($t, 0, 10));
$e   = static fn (mixed $v): string => esc((string) ($v ?? ''));
$nl  = static fn (mixed $v): string => nl2br(esc(trim((string) ($v ?? ''))));
$jenis = (int) $dataset['id_jenis_cuti'];
$tahun = substr((string) $dataset['tgl_mulai'], 0, 4);
$tanda = static fn (int $j) => $jenis === $j ? $tahun : '&nbsp;&nbsp;';
$kosong = '&nbsp;&nbsp;';
?>
<style>
.big-title { width: 100%; font-size: 14px; text-align: center; font-weight: bold; }
.table-bordered { width: 100%; border-collapse: collapse; }
.table-bordered td { vertical-align: middle; line-height: 120%; border: 1px solid #000; }
table td { vertical-align: top; line-height: 1.1; font-size: 10px; }
table td.td-title { font-weight: bold; }
</style>

<table width="100%" cellpadding="0" nobr="true">
    <tr>
        <td width="55%">&nbsp;</td>
        <td width="45%" style="text-align: center;">
            Jakarta, <?= $tgl($tglSurat) ?><br>
            Kepada<br>
            Yth. <?= $e($dataset['jabatan_yang_menyetujui']) ?><br>
            di Jakarta
        </td>
    </tr>
</table>
<br/>
<div style="font-size: 9pt; font-weight: bold; text-align: center;">FORMULIR PERMINTAAN DAN PEMBERIAN CUTI</div>
<br/>
<table class="table-bordered" width="100%" cellpadding="3" nobr="true">
    <tr><td colspan="4" width="100%" class="td-title">I. DATA PEGAWAI</td></tr>
    <tr>
        <td width="13%">Nama</td><td width="50%"><?= $e($dataset['nama']) ?></td>
        <td width="13%">NIP</td><td width="24%"><?= $e($dataset['nip']) ?></td>
    </tr>
    <tr>
        <td>Jabatan</td><td><?= $e($dataset['jabatan']) ?></td>
        <td>Masa Kerja</td><td><?= $e($dataset['mker_th']) ?> TAHUN <?= $e($dataset['mker_bl']) ?> BULAN</td>
    </tr>
    <tr><td>Satuan Kerja</td><td colspan="3"><?= $e($dataset['satker']) ?></td></tr>
</table>
<div style="font-size: 5pt; line-height: .8;">&nbsp;</div>
<table class="table-bordered" width="100%" cellpadding="3" nobr="true">
    <tr><td colspan="4" width="100%" class="td-title">II. JENIS CUTI YANG DIAMBIL</td></tr>
    <tr>
        <td width="35%">1. Cuti Tahunan</td><td width="15%" style="text-align: center;"><?= $tanda(1) ?></td>
        <td width="35%">2. Cuti Besar</td><td width="15%" style="text-align: center;"><?= $tanda(2) ?></td>
    </tr>
    <tr>
        <td>3. Cuti Sakit</td><td style="text-align: center;"><?= $tanda(3) ?></td>
        <td>4. Cuti Melahirkan</td><td style="text-align: center;"><?= $tanda(4) ?></td>
    </tr>
    <tr>
        <td>5. Cuti Karena Alasan Penting</td><td style="text-align: center;"><?= $tanda(5) ?></td>
        <td>6. Cuti di Luar Tanggungan Negara</td><td style="text-align: center;"><?= $tanda(6) ?></td>
    </tr>
</table>
<div style="font-size: 5pt; line-height: .8;">&nbsp;</div>
<table class="table-bordered" width="100%" cellpadding="3" nobr="true">
    <tr><td width="100%" class="td-title">III. ALASAN CUTI</td></tr>
    <tr><td><?= trim((string) ($dataset['keterangan'] ?? '')) !== '' ? $nl($dataset['keterangan']) : $kosong ?></td></tr>
</table>
<div style="font-size: 5pt; line-height: .8;">&nbsp;</div>
<table class="table-bordered" width="100%" cellpadding="3" nobr="true">
    <tr><td colspan="6" width="100%" class="td-title">IV. LAMANYA CUTI</td></tr>
    <tr>
        <td width="10%">Selama</td>
        <td width="15%" style="text-align: right;"><?= $e($dataset['lama_cuti']) ?> <?= $e($dataset['satuan_lama_cuti']) ?></td>
        <td width="20%">mulai tanggal</td>
        <td width="24%"><?= $tgl((string) $dataset['tgl_mulai']) ?></td>
        <td width="7%" style="text-align: center;">s/d</td>
        <td width="24%"><?= $tgl((string) $dataset['tgl_akhir']) ?></td>
    </tr>
</table>
<div style="font-size: 5pt; line-height: .8;">&nbsp;</div>
<table class="table-bordered" width="100%" cellpadding="3" nobr="true">
    <tr><td colspan="5" width="100%" class="td-title">V. CATATAN CUTI</td></tr>
    <tr>
        <td colspan="3" width="45%">1. CUTI TAHUNAN</td>
        <td width="40%">2. CUTI BESAR</td>
        <td width="15%"><?= $kosong ?></td>
    </tr>
    <tr>
        <td width="10%">Tahun</td><td width="15%">Sisa</td><td width="20%">Keterangan</td>
        <td width="40%">3. CUTI SAKIT</td><td width="15%"><?= $kosong ?></td>
    </tr>
    <tr>
        <td>N-2</td><td><?= $jenis === 1 ? '0' : '' ?></td><td></td>
        <td>4. CUTI MELAHIRKAN</td><td></td>
    </tr>
    <tr>
        <td>N-1</td><td><?= $jenis === 1 ? $e($dataset['sisa_cuti_n1']) : '' ?></td><td></td>
        <td>5. CUTI KARENA ALASAN PENTING</td><td></td>
    </tr>
    <tr>
        <td>N</td><td><?= $jenis === 1 ? $e($dataset['sisa_cuti']) : '' ?></td><td></td>
        <td>6. CUTI DI LUAR TANGGUNGAN NEGARA</td><td></td>
    </tr>
</table>
<div style="font-size: 5pt; line-height: .8;">&nbsp;</div>
<table class="table-bordered" width="100%" cellpadding="3" nobr="true">
    <tr><td colspan="3" width="100%" class="td-title">VI. ALAMAT SELAMA MENJALANKAN CUTI</td></tr>
    <tr>
        <td rowspan="2" width="55%"><?= $nl($dataset['alamat_saat_cuti']) ?></td>
        <td width="10%">TELP</td>
        <td width="35%"><?= $e($dataset['no_telp_saat_cuti']) ?></td>
    </tr>
    <tr>
        <td colspan="2" width="45%" style="text-align: center;">Hormat Saya,<br/><?= $e($dataset['nama']) ?><br/>NIP. <?= $e($dataset['nip']) ?></td>
    </tr>
</table>
<div style="font-size: 5pt; line-height: .8;">&nbsp;</div>
<table class="table-bordered" width="100%" cellpadding="3" nobr="true">
    <tr><td colspan="4" width="100%" class="td-title">VII. PERTIMBANGAN ATASAN LANGSUNG</td></tr>
    <tr>
        <td width="18%" style="text-align: center;">DISETUJUI</td>
        <td width="18%" style="text-align: center;">PERUBAHAN</td>
        <td width="19%" style="text-align: center;">DITANGGUHKAN</td>
        <td width="45%" style="text-align: center;">TIDAK DISETUJUI</td>
    </tr>
    <tr>
        <td><?= (string) $dataset['status_al'] === '1' ? '<b>DISETUJUI</b>' : $kosong ?></td>
        <td><?= $kosong ?></td>
        <td><?= (string) $dataset['status_al'] === '4' ? '<b>DITANGGUHKAN</b>' : $kosong ?></td>
        <td><?= $kosong ?></td>
    </tr>
    <tr>
        <td colspan="3" width="55%" style="line-height: 1;"><?= trim((string) ($dataset['reason_note_al'] ?? '')) !== '' ? $nl($dataset['reason_note_al']) : $kosong ?></td>
        <td width="45%" style="text-align: center; line-height: 1;"><?= $e($dataset['nama_atasan_langsung']) ?><br/>NIP. <?= $e($dataset['nip_atasan_langsung']) ?></td>
    </tr>
</table>
<div style="font-size: 5pt; line-height: .8;">&nbsp;</div>
<table class="table-bordered" width="100%" cellpadding="3" nobr="true">
    <tr><td colspan="4" width="100%" class="td-title">VIII. KEPUTUSAN PEJABAT YANG BERWENANG MEMBERIKAN CUTI</td></tr>
    <tr>
        <td width="18%" style="text-align: center;">DISETUJUI</td>
        <td width="18%" style="text-align: center;">PERUBAHAN</td>
        <td width="19%" style="text-align: center;">DITANGGUHKAN</td>
        <td width="45%" style="text-align: center;">TIDAK DISETUJUI</td>
    </tr>
    <tr>
        <td><?= (string) $dataset['status_pyb'] === '1' ? '<b>DISETUJUI</b>' : $kosong ?></td>
        <td><?= $kosong ?></td>
        <td><?= (string) $dataset['status_pyb'] === '4' ? '<b>DITANGGUHKAN</b>' : $kosong ?></td>
        <td><?= $kosong ?></td>
    </tr>
    <tr>
        <td colspan="3" width="55%" style="line-height: 1;"><?= trim((string) ($dataset['reason_note_pyb'] ?? '')) !== '' ? $nl($dataset['reason_note_pyb']) : $kosong ?></td>
        <td width="45%" style="text-align: center; line-height: 1;"><div style="font-size: 2px; line-height: 30;"><span>TTD</span>##</div><div style="font-size: 8px; line-height: .2;">&nbsp;</div><?= $e($dataset['nama_yang_menyetujui']) ?><br/>NIP. <?= $e($dataset['nip_yang_menyetujui']) ?></td>
    </tr>
</table>
<br/>
<table width="100%" cellpadding="0" nobr="true">
    <tr><td width="75%" style="font-size: 7pt;">Dokumen ini dihasilkan SIMPEG dan sah setelah ditandatangani secara elektronik.</td><td width="25%" style="text-align: right;"><?= $qr ?></td></tr>
</table>
