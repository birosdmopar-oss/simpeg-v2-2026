<?php
/**
 * PDF rekap Tukin [V2] (PRD 5B §7.3.5, F5B-16): A4 lanskap, kolom sama dengan XLSX rekap (`EksporTukinXlsx`), legenda dan
 * blok penanda tangan selalu dicetak (F5B-13). Legacy hanya punya cetak HTML `print/laporan_tukin_us_skp.php`. Semua teks
 * di-escape.
 *
 * @var string                                                               $judul
 * @var string                                                               $lingkup
 * @var string                                                               $periode
 * @var list<array<string, mixed>>                                           $baris
 * @var array{kasus: string, jabatan: string, nama: string, nip: string}     $penandaTangan
 */

$angka = static fn (mixed $v): string => $v === null ? '' : esc((string) $v);
?>
<style>
    table.rekap { border-collapse: collapse; }
    table.rekap th, table.rekap td { border: 0.5px solid #666666; font-size: 7pt; padding: 2px; }
    table.rekap th { font-weight: bold; text-align: center; background-color: #eeeeee; }
    td.c { text-align: center; }
</style>
<p style="text-align:center; font-size:11pt; font-weight:bold;"><?= esc($judul) ?><br><?= esc(mb_strtoupper($lingkup)) ?><br>Periode: <?= esc($periode) ?></p>
<table class="rekap" cellpadding="2" width="100%">
    <thead>
        <tr>
            <th rowspan="2" width="3%">NO</th>
            <th rowspan="2" width="14%">NAMA</th>
            <th rowspan="2" width="10%">NIP</th>
            <th rowspan="2" width="11%">UNIT ES.I</th>
            <th rowspan="2" width="11%">UNIT ES.II</th>
            <th colspan="6" width="21%">JUMLAH PENGURANGAN (%)</th>
            <th rowspan="2" width="5%">TOTAL PENGURANGAN PRESENSI (%)</th>
            <th rowspan="2" width="5%">TOTAL PENGURANGAN SKP (%)</th>
            <th rowspan="2" width="5%">TOTAL POTONGAN (%) (PRESENSI + SKP)</th>
            <th rowspan="2" width="15%">KETERANGAN</th>
        </tr>
        <tr>
            <?php foreach (['TL', 'PSW', 'TK', 'TA', 'CUTI', 'TB'] as $k): ?>
                <th width="3.5%"><?= $k ?></th>
            <?php endforeach ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($baris as $i => $b): ?>
            <tr nobr="true">
                <td class="c" width="3%"><?= $i + 1 ?></td>
                <td width="14%"><?= esc((string) $b['nama']) ?></td>
                <td class="c" width="10%"><?= esc((string) $b['nip']) ?></td>
                <td width="11%"><?= esc((string) $b['unit_es1']) ?></td>
                <td width="11%"><?= esc((string) $b['unit_es2']) ?></td>
                <?php foreach (['TL', 'PSW', 'TK', 'TA', 'CUTI', 'TB'] as $k): ?>
                    <td class="c" width="3.5%"><?= $angka(is_array($b['pengurangan']) ? $b['pengurangan'][$k] : null) ?></td>
                <?php endforeach ?>
                <td class="c" width="5%"><?= $angka($b['total_presensi']) ?></td>
                <td class="c" width="5%"><?= $angka($b['total_skp']) ?></td>
                <td class="c" width="5%"><?= $angka($b['total_potongan']) ?></td>
                <td width="15%"><?= nl2br(esc(is_array($b['galat']) ? (string) $b['galat']['message'] : (string) $b['keterangan'])) ?></td>
            </tr>
        <?php endforeach ?>
    </tbody>
</table>
<br>
<table width="100%" style="font-size:8pt;">
    <tr>
        <td width="55%">Note<br>TL : Terlambat<br>PSW : Pulang Sebelum Waktunya<br>TK : Tanpa Keterangan<br>TA : Tidak Absen</td>
        <td width="45%" style="text-align:center;"><?= esc($penandaTangan['jabatan']) ?><br><br><br><br><br><u><?= esc($penandaTangan['nama']) ?></u><br><?= $penandaTangan['nip'] === '' ? '' : 'NIP. ' . esc($penandaTangan['nip']) ?></td>
    </tr>
</table>
