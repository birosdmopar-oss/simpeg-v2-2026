<?php
/**
 * PDF laporan Tukin per pegawai (PRD 5B §7.3.2, F5B-8) — render legacy `print/laporan_tukin_skp_pdf.php:55-257` [K]:
 * judul "LAPORAN TUNJANGAN KINERJA" + periode, identitas (NIP, NAMA, GOL. RUANG hanya PNS, SATUAN KERJA, JABATAN, PREDIKAT
 * KINERJA, POTONGAN KINERJA), tabel TANGGAL | JAM MASUK | JAM PULANG | KET. | POTONGAN PRESENSI; baris konket = tanggal +
 * keterangan `colspan=4` + sel "Rp." hanya bila hari dibayar uang makan (aturan slip); **tanpa** kolom "UANG MAKAN" dan
 * tanpa baris "TOTAL UANG MAKAN" (di legacy berada di dalam komentar HTML); "TOTAL PENGURANGAN TUKIN" =
 * "<total>%<akhiran penyesuaian>". Keterangan penyesuaian (DBV-024) dicetak di atas tabel. Semua teks di-escape.
 *
 * @var array<string, mixed> $d
 * @var string               $periode
 * @var array<string, int>   $rp      tanggal → sen
 * @var string               $akhiran
 */

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;

$tanggal = static fn (string $t): string => (int) substr($t, 8, 2) . ' ' . TanggalBisnis::NAMA_BULAN[(int) substr($t, 5, 2)] . ' ' . substr($t, 0, 4);
$rupiah  = static fn (int $sen): string => 'Rp. ' . number_format(intdiv($sen, 100), 0, ',', '.') . ($sen % 100 === 0 ? '' : ',' . str_pad((string) ($sen % 100), 2, '0', STR_PAD_LEFT));
$i       = $d['identitas'];
?>
<style>
    table.lt th, table.lt td { border: 0.5px solid #333333; font-size: 8.5pt; padding: 2px; }
    table.lt th { font-weight: bold; text-align: center; }
    td.c { text-align: center; }
</style>
<p style="text-align:center; font-size:10pt;"><b><u>LAPORAN TUNJANGAN KINERJA</u></b><br><span style="font-size:7pt;">Periode : <?= esc($periode) ?></span></p>
<table cellpadding="1" style="font-size:9pt;">
    <tr><td width="25%">NIP</td><td width="3%">:</td><td width="72%"><?= esc((string) $i['nip']) ?></td></tr>
    <tr><td>NAMA</td><td>:</td><td><?= esc((string) $i['nama']) ?></td></tr>
    <?php if ($i['gol_ruang'] !== null): ?>
        <tr><td>GOL. RUANG</td><td>:</td><td><?= esc((string) $i['gol_ruang']) ?></td></tr>
    <?php endif ?>
    <tr><td>SATUAN KERJA</td><td>:</td><td><?= esc((string) $i['satker']) ?></td></tr>
    <tr><td>JABATAN</td><td>:</td><td><?= esc((string) $i['jabatan']) ?></td></tr>
    <tr><td colspan="3"></td></tr>
    <tr><td>PREDIKAT KINERJA</td><td>:</td><td><?= esc((string) ($i['predikat_skp'] ?? '-')) ?></td></tr>
    <tr><td>POTONGAN KINERJA</td><td>:</td><td><?= esc((string) $d['total_skp']) ?></td></tr>
</table>
<?php if ($d['penyesuaian'] !== null): ?>
    <p style="color:#c00000; font-size:8.5pt;"><b><?= esc('Penyesuaian Tukin ' . $d['penyesuaian']['alasan'] . ' ' . $d['penyesuaian']['persen'] . '% (sebelum potongan lain).') ?></b></p>
<?php endif ?>
<br>
<table class="lt" cellpadding="2" width="100%">
    <tr nobr="true">
        <th width="20%">TANGGAL</th><th width="20%">JAM<br>MASUK</th><th width="20%">JAM<br>PULANG</th><th width="20%">KET.</th><th width="20%">POTONGAN<br>PRESENSI</th>
    </tr>
    <?php foreach ($d['harian'] as $h): ?>
        <?php if ($h['status'] === 'konket'): ?>
            <tr nobr="true">
                <td width="20%"><?= esc($tanggal($h['tanggal'])) ?></td>
                <td colspan="4" width="<?= isset($rp[$h['tanggal']]) ? '60%' : '80%' ?>"><?= esc((string) $h['keterangan']) ?></td>
                <?php if (isset($rp[$h['tanggal']])): ?>
                    <td width="20%"><?= esc($rupiah($rp[$h['tanggal']])) ?></td>
                <?php endif ?>
            </tr>
        <?php elseif ($h['status'] === 'cuti'): ?>
            <tr nobr="true">
                <td width="20%"><?= esc($tanggal($h['tanggal'])) ?></td>
                <td colspan="3" width="60%"><?= esc((string) $h['keterangan']) ?></td>
                <td class="c" width="20%"><?= esc((string) $h['potongan']) ?>%</td>
            </tr>
        <?php elseif (in_array($h['status'], ['tugas_belajar', 'libur', 'di_luar_masa_kerja'], true)): ?>
            <tr nobr="true">
                <td width="20%"><?= esc($tanggal($h['tanggal'])) ?></td>
                <td colspan="4" width="80%"><?= esc((string) $h['keterangan']) ?></td>
            </tr>
        <?php else: ?>
            <tr nobr="true">
                <td width="20%"><?= esc($tanggal($h['tanggal'])) ?></td>
                <td class="c" width="20%"><?= esc((string) ($h['jam_masuk'] ?? '')) ?></td>
                <td class="c" width="20%"><?= esc((string) ($h['jam_pulang'] ?? '')) ?></td>
                <td class="c" width="20%"><?= esc((string) $h['keterangan']) ?></td>
                <td class="c" width="20%"><?= esc((string) $h['potongan']) ?>%</td>
            </tr>
        <?php endif ?>
    <?php endforeach ?>
    <tr nobr="true">
        <td colspan="4" width="80%" style="text-align:right;"><b>TOTAL PENGURANGAN TUKIN</b></td>
        <td class="c" width="20%"><b><?= esc((string) $d['total_potongan'] . '%' . $akhiran) ?></b></td>
    </tr>
</table>
