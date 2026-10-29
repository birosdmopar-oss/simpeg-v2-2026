<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Mesin impor legacy → v2 (MAKE-056). Sumber = skema salinan legacy [K] apa adanya; tujuan = DB v2 sesudah
 * `migrate --all`. Transformasi per baris di PHP (stripslashes PHP = DBV-011 §2.2 #3; sama di MySQL 8 dan MariaDB),
 * tulis per batch, satu transaksi per tier. Aturan umum per kolom:
 *
 * 1. Kolom disalin = irisan kolom sumber dan tujuan; kolom tujuan NOT NULL tanpa DEFAULT yang tidak ada di sumber → gagal.
 * 2. Pemetaan berkas keputusan (`nilai`) dipakai lebih dulu bila ada (GAP-6).
 * 3. Kolom tanggal/stempel tujuan: nilai nol → NULL (nullable) atau pengganti dari berkas keputusan, kalau tidak gagal
 *    (R3); DATETIME tujuan dikonversi menurut tipe kolom SUMBER dan `--zona-sumber` (R1a/R1b/R2).
 * 4. Kolom FK tujuan: `0` / `''` → NULL (DBV-011 §2.10).
 * 5. Kolom teks: stripslashes PHP (kecuali `tanpaStripslashes`); `kolomNama` dinormalisasi seperti aplikasi.
 * 6. `status` (bila `statusDomain` tidak null): di luar domain → berkas keputusan, kalau tidak gagal.
 *
 * Kedua koneksi diawali sesi R4 (`time_zone = '+00:00'`, strict). Tujuan wajib kosong, kecuali tabel `bolehSeed`.
 */
final class PengimporLegacy
{
    private const SQL_MODE = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    private const TIPE_WAKTU = ['date', 'datetime', 'timestamp'];
    private const TIPE_TEKS  = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'];

    private readonly LaporanImpor $laporan;

    public function __construct(
        private readonly BaseConnection $sumber,
        private readonly BaseConnection $tujuan,
        private readonly string $zonaSumber,
        private readonly Keputusan $keputusan,
        private readonly int $ukuranBatch = 1000,
    ) {
        if (! in_array($zonaSumber, [Transformasi::ZONA_WIB, Transformasi::ZONA_UTC], true)) {
            throw new ImporGagal('--zona-sumber wajib WIB atau UTC (GAP-2).');
        }

        $this->laporan = new LaporanImpor();
    }

    /**
     * @param list<int> $tier
     */
    public function jalankan(array $tier, bool $dryRun): LaporanImpor
    {
        $this->aturSesi($this->sumber);
        $this->aturSesi($this->tujuan);

        foreach ($tier as $t) {
            $this->jalankanTier($t, $dryRun);
        }

        return $this->laporan;
    }

    private function aturSesi(BaseConnection $db): void
    {
        $db->query("SET SESSION sql_mode = '" . self::SQL_MODE . "'");
        $db->query("SET SESSION time_zone = '+00:00'");
        $cek = $db->query('SELECT NOW() = UTC_TIMESTAMP() AS ok')->getRowArray();

        if ((int) ($cek['ok'] ?? 0) !== 1) {
            throw new ImporGagal('Sesi R4 gagal: NOW() <> UTC_TIMESTAMP().');
        }
    }

    private function jalankanTier(int $tier, bool $dryRun): void
    {
        $defs  = DaftarTabel::tier($tier);
        $rinci = [];

        foreach ($defs as $def) {
            $rinci[$def->tabel] = $this->praCek($def);
        }

        if (! $dryRun) {
            $this->tujuan->transBegin();
        }

        try {
            foreach ($defs as $def) {
                $this->imporTabel($tier, $def, $rinci[$def->tabel], $dryRun);
            }
        } catch (Throwable $e) {
            if (! $dryRun) {
                $this->tujuan->transRollback();
                $this->tujuan->resetTransStatus();
            }

            throw $e;
        }

        if (! $dryRun) {
            $this->tujuan->transCommit();
        }
    }

    /**
     * @return array{ada: bool, kolom: array<string, array{tipeSumber: string, tipeTujuan: string, nullable: bool}>, fk: list<string>, seed: array<string, string>}|null
     */
    private function praCek(DefinisiTabel $def): ?array
    {
        $kolomSumber = $this->kolom($this->sumber, $def->tabel);

        if ($kolomSumber === []) {
            if ($def->opsional) {
                return null;
            }

            throw new ImporGagal("Tabel sumber {$def->tabel} tidak ada.");
        }

        $kolomTujuan = $this->kolom($this->tujuan, $this->tujuan->prefixTable($def->tabel));

        if ($kolomTujuan === []) {
            throw new ImporGagal("Tabel tujuan {$def->tabel} tidak ada (jalankan migrate --all).");
        }

        $kolom = [];

        foreach ($kolomTujuan as $nama => $t) {
            if (isset($kolomSumber[$nama])) {
                $kolom[$nama] = ['tipeSumber' => $kolomSumber[$nama]['tipe'], 'tipeTujuan' => $t['tipe'], 'nullable' => $t['nullable']];

                continue;
            }

            $diisi = $def->urutan !== null && $def->urutan['kolom'] === $nama;

            if (! $t['nullable'] && ! $t['adaDefault'] && ! $t['auto'] && ! $diisi) {
                throw new ImporGagal("Kolom {$def->tabel}.{$nama} NOT NULL tanpa DEFAULT tidak ada di sumber.");
            }
        }

        if (! isset($kolom[$def->pk])) {
            throw new ImporGagal("Kolom PK {$def->tabel}.{$def->pk} tidak ada di sumber.");
        }

        $seed = [];
        $isi  = (int) $this->tujuan->table($def->tabel)->countAllResults();

        if ($isi > 0) {
            if (! $def->bolehSeed || $def->kolomNama === []) {
                throw new ImporGagal("Tabel tujuan {$def->tabel} tidak kosong ({$isi} baris).");
            }

            foreach ($this->tujuan->table($def->tabel)->select([$def->pk, $def->kolomNama[0]])->get()->getResultArray() as $r) {
                $seed[(string) $r[$def->pk]] = (string) $r[$def->kolomNama[0]];
            }
        }

        return ['ada' => true, 'kolom' => $kolom, 'fk' => $this->kolomFk($this->tujuan->prefixTable($def->tabel)), 'seed' => $seed];
    }

    /**
     * @param array{ada: bool, kolom: array<string, array{tipeSumber: string, tipeTujuan: string, nullable: bool}>, fk: list<string>, seed: array<string, string>}|null $rinci
     */
    private function imporTabel(int $tier, DefinisiTabel $def, ?array $rinci, bool $dryRun): void
    {
        if ($rinci === null) {
            $this->laporan->mulai($tier, $def, 0, 'tidak ada di sumber (opsional)');

            return;
        }

        $jumlah = (int) $this->sumber->query('SELECT COUNT(*) AS n FROM ' . $this->sumber->escapeIdentifiers($def->tabel))->getRowArray()['n'];
        $this->laporan->mulai($tier, $def, $jumlah, $dryRun ? 'dry-run' : '');

        $daftarKolom = array_keys($rinci['kolom']);
        $pilih       = implode(', ', array_map(fn (string $k): string => $this->sumber->escapeIdentifiers($k), $daftarKolom));
        $pk          = $this->sumber->escapeIdentifiers($def->pk);
        $tabel       = $this->sumber->escapeIdentifiers($def->tabel);
        $urutan      = [];
        $tunda       = [];
        $terakhir    = null;

        do {
            $sql   = "SELECT {$pilih} FROM {$tabel}" . ($terakhir === null ? '' : " WHERE {$pk} > ?") . " ORDER BY {$pk} LIMIT {$this->ukuranBatch}";
            $baris = $this->sumber->query($sql, $terakhir === null ? [] : [$terakhir])->getResultArray();
            $tulis = [];

            foreach ($baris as $mentah) {
                $terakhir = $mentah[$def->pk];
                $hasil    = $this->ubahBaris($def, $rinci, $mentah);

                if (is_string($hasil)) {
                    $this->laporan->dibuang($def->tabel, $hasil);

                    continue;
                }

                if ($def->urutan !== null && ! isset($rinci['kolom'][$def->urutan['kolom']])) {
                    $lingkup                      = implode('|', array_map(static fn (string $k): string => (string) ($hasil[$k] ?? ''), $def->urutan['lingkup']));
                    $urutan[$lingkup]             = ($urutan[$lingkup] ?? 0) + 1;
                    $hasil[$def->urutan['kolom']] = $urutan[$lingkup];
                }

                foreach ($def->kolomTunda as $k) {
                    if (($hasil[$k] ?? null) !== null) {
                        $tunda[]   = [$def->pk => $hasil[$def->pk], $k => $hasil[$k]];
                        $hasil[$k] = null;
                    }
                }

                $id = (string) $hasil[$def->pk];

                if (isset($rinci['seed'][$id])) {
                    $nama = (string) ($hasil[$def->kolomNama[0]] ?? '');

                    if (mb_strtolower($nama) !== mb_strtolower($rinci['seed'][$id])) {
                        throw new ImporGagal("Seed {$def->tabel} id {$id} berbeda dengan sumber (GAP-3).");
                    }

                    $this->laporan->dilewati($def->tabel);

                    continue;
                }

                $tulis[] = $hasil;
            }

            if ($tulis !== [] && ! $dryRun) {
                $this->tujuan->table($def->tabel)->insertBatch($tulis);
            }

            $this->laporan->ditulis($def->tabel, count($tulis));
        } while (count($baris) === $this->ukuranBatch);

        if ($tunda !== [] && ! $dryRun) {
            foreach ($tunda as $u) {
                $id = $u[$def->pk];
                unset($u[$def->pk]);
                $this->tujuan->table($def->tabel)->where($def->pk, $id)->update($u);
            }
        }
    }

    /**
     * @param array{ada: bool, kolom: array<string, array{tipeSumber: string, tipeTujuan: string, nullable: bool}>, fk: list<string>, seed: array<string, string>} $rinci
     * @param array<string, mixed> $mentah
     *
     * @return array<string, mixed>|string
     */
    private function ubahBaris(DefinisiTabel $def, array $rinci, array $mentah): array|string
    {
        $hasil = [];

        foreach ($rinci['kolom'] as $k => $tipe) {
            $hasil[$k] = $this->ubahNilai($def, $k, $tipe, $rinci['fk'], $mentah[$k]);
        }

        if ($def->statusDomain !== null && array_key_exists('status', $hasil)) {
            $hasil['status'] = $this->keputusan->status($def->tabel, $hasil['status'], $def->statusDomain);
        }

        return $def->khusus === null ? $hasil : ($def->khusus)($hasil, $this->keputusan);
    }

    /**
     * @param array{tipeSumber: string, tipeTujuan: string, nullable: bool} $tipe
     * @param list<string>                                                 $fk
     */
    private function ubahNilai(DefinisiTabel $def, string $kolom, array $tipe, array $fk, mixed $nilai): mixed
    {
        $peta = $this->keputusan->nilai($def->tabel, $kolom, $nilai);

        if ($peta['ada']) {
            return $peta['nilai'];
        }

        if (in_array($tipe['tipeTujuan'], self::TIPE_WAKTU, true) && $nilai !== null) {
            if (Transformasi::tanggalNol((string) $nilai)) {
                if ($tipe['nullable']) {
                    return null;
                }

                return $this->keputusan->tanggalNol($def->tabel, $kolom)
                    ?? throw new ImporGagal("Tanggal nol di {$def->tabel}.{$kolom} (NOT NULL) belum diputuskan (R3).");
            }

            return $tipe['tipeTujuan'] === 'datetime'
                ? Transformasi::waktuUtc((string) $nilai, $tipe['tipeSumber'], $this->zonaSumber)
                : $nilai;
        }

        if (in_array($kolom, $fk, true)) {
            $nilai = Transformasi::rujukan($nilai);
        }

        if ($nilai !== null && in_array($tipe['tipeTujuan'], self::TIPE_TEKS, true) && in_array($tipe['tipeSumber'], self::TIPE_TEKS, true)) {
            $nilai = in_array($kolom, $def->tanpaStripslashes, true) ? (string) $nilai : Transformasi::stripslashes((string) $nilai);

            if (in_array($kolom, $def->kolomNama, true)) {
                $nilai = Transformasi::nama($nilai);
            }
        }

        return $nilai;
    }

    /**
     * @return array<string, array{tipe: string, nullable: bool, adaDefault: bool, auto: bool}>
     */
    private function kolom(BaseConnection $db, string $tabel): array
    {
        $rows = $db->query(
            'SELECT COLUMN_NAME AS n, DATA_TYPE AS t, IS_NULLABLE AS nl, COLUMN_DEFAULT AS d, EXTRA AS e'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$db->getDatabase(), $tabel],
        )->getResultArray();

        $out = [];

        foreach ($rows as $r) {
            $out[(string) $r['n']] = [
                'tipe'       => strtolower((string) $r['t']),
                'nullable'   => $r['nl'] === 'YES',
                'adaDefault' => $r['d'] !== null,
                'auto'       => str_contains(strtolower((string) $r['e']), 'auto_increment'),
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function kolomFk(string $tabel): array
    {
        $rows = $this->tujuan->query(
            'SELECT DISTINCT COLUMN_NAME AS n FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$this->tujuan->getDatabase(), $tabel],
        )->getResultArray();

        return array_map(static fn (array $r): string => (string) $r['n'], $rows);
    }
}
