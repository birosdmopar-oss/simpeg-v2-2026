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
 * 6. `status` (bila `statusDomain` tidak null) dan kolom `domain`: di luar domain → berkas keputusan, kalau tidak gagal;
 *    NULL di kolom nullable dibiarkan.
 * 7. `kolomKode`: trim PHP (kode wilayah, DBV-011 §2.2 #7). Teks melebihi panjang kolom tujuan → gagal.
 * 8. Rujukan FK dicek per batch terhadap tabel induk di tujuan (audit DBV-019 = 0): yatim → NULL bila kolomnya
 *    `yatimKeNull` (dicatat), selain itu gagal dengan jumlah nilai yatim. Dry-run tidak memeriksa rujukan.
 *
 * Aturan khusus tabel boleh mengembalikan kunci `__koreksi` (list kode) untuk dicatat di laporan. Sesudah setiap tier
 * di-commit, counter AUTO_INCREMENT tujuan disamakan dengan sumber bila lebih kecil (DBV-011 §2.7; PK TINYINT dengan
 * counter ≥ 128 tidak disalin, dicatat PERIKSA). Langkah ini DDL, jadi berjalan di luar transaksi tier.
 *
 * Kedua koneksi diawali sesi R4 (`time_zone = '+00:00'`, strict). Tujuan wajib kosong, kecuali tabel `bolehSeed`.
 */
final class PengimporLegacy
{
    private const SQL_MODE = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    private const TIPE_WAKTU = ['date', 'datetime', 'timestamp'];
    private const TIPE_TEKS  = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'];

    private readonly LaporanImpor $laporan;
    private bool $dryRun = false;

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

        if ($ukuranBatch < 1) {
            throw new ImporGagal('Ukuran batch minimal 1.');
        }

        $this->laporan = new LaporanImpor();
    }

    /**
     * @param list<int> $tier
     */
    public function jalankan(array $tier, bool $dryRun): LaporanImpor
    {
        $this->dryRun = $dryRun;
        $this->aturSesi($this->sumber);
        $this->aturSesi($this->tujuan);

        foreach ($tier as $t) {
            $mulai = hrtime(true);
            $this->jalankanTier($t, $dryRun);
            $this->laporan->waktuTier($t, (hrtime(true) - $mulai) / 1e9);
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

            foreach ($defs as $def) {
                if ($rinci[$def->tabel] !== null) {
                    $this->salinCounter($def);
                }
            }
        }
    }

    /**
     * Counter AUTO_INCREMENT tujuan = counter sumber bila lebih besar (DBV-011 §2.7). Tidak pernah diturunkan.
     */
    private function salinCounter(DefinisiTabel $def): void
    {
        $sumber = $this->counter($this->sumber, $def->tabel);
        $tabel  = $this->tujuan->prefixTable($def->tabel);
        $tujuan = $this->counter($this->tujuan, $tabel);

        if ($sumber === null || $tujuan === null || $sumber <= $tujuan) {
            return;
        }

        $tipe = $this->tujuan->query(
            'SELECT DATA_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->tujuan->getDatabase(), $tabel, $def->pk],
        )->getRowArray();

        if (strtolower((string) ($tipe['t'] ?? '')) === 'tinyint' && $sumber >= 128) {
            $this->laporan->koreksi($def->tabel, "PERIKSA counter TINYINT {$sumber} tidak disalin");

            return;
        }

        $this->tujuan->query('ALTER TABLE ' . $this->tujuan->escapeIdentifiers($tabel) . ' AUTO_INCREMENT = ' . $sumber);
        $this->laporan->koreksi($def->tabel, "counter AUTO_INCREMENT {$tujuan}→{$sumber}");
    }

    private function counter(BaseConnection $db, string $tabel): ?int
    {
        if (! str_contains(strtolower($db->getVersion()), 'mariadb')) {
            $db->query('SET SESSION information_schema_stats_expiry = 0');
        }

        $r = $db->query(
            'SELECT AUTO_INCREMENT AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$db->getDatabase(), $tabel],
        )->getRowArray();

        return isset($r['n']) ? (int) $r['n'] : null;
    }

    /**
     * @return array{ada: bool, kolom: array<string, array{tipeSumber: string, tipeTujuan: string, nullable: bool, panjang: int|null}>, fk: array<string, array{tabel: string, kolom: string}>, seed: array<string, string>}|null
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
                $kolom[$nama] = ['tipeSumber' => $kolomSumber[$nama]['tipe'], 'tipeTujuan' => $t['tipe'], 'nullable' => $t['nullable'], 'panjang' => $t['panjang']];

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
     * @param array{ada: bool, kolom: array<string, array{tipeSumber: string, tipeTujuan: string, nullable: bool, panjang: int|null}>, fk: array<string, array{tabel: string, kolom: string}>, seed: array<string, string>}|null $rinci
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
        $kunci       = $def->kunciUrut === [] ? [$def->pk] : $def->kunciUrut;
        $urutPk      = implode(', ', array_map(fn (string $k): string => $this->sumber->escapeIdentifiers($k), $kunci));
        $tabel       = $this->sumber->escapeIdentifiers($def->tabel);
        $urutan      = [];
        $tunda       = [];
        $terakhir    = null;

        do {
            $syarat = $terakhir === null ? '' : " WHERE ({$urutPk}) > (" . implode(', ', array_fill(0, count($kunci), '?')) . ')';
            $sql    = "SELECT {$pilih} FROM {$tabel}{$syarat} ORDER BY {$urutPk} LIMIT {$this->ukuranBatch}";
            $baris  = $this->sumber->query($sql, $terakhir ?? [])->getResultArray();
            $tulis  = [];

            foreach ($baris as $mentah) {
                $terakhir = array_map(static fn (string $k): mixed => $mentah[$k], $kunci);
                $hasil    = $this->ubahBaris($def, $rinci, $mentah);

                if (is_string($hasil)) {
                    $this->laporan->dibuang($def->tabel, $hasil);

                    continue;
                }

                foreach ($hasil['__koreksi'] ?? [] as $kode) {
                    $this->laporan->koreksi($def->tabel, (string) $kode);
                }

                unset($hasil['__koreksi']);

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

            $tulis = $this->periksaRujukan($def, $rinci['fk'], $tulis);

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
     * @param array{ada: bool, kolom: array<string, array{tipeSumber: string, tipeTujuan: string, nullable: bool, panjang: int|null}>, fk: array<string, array{tabel: string, kolom: string}>, seed: array<string, string>} $rinci
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

        $domain = $def->domain + ($def->statusDomain === null ? [] : ['status' => $def->statusDomain]);

        foreach ($domain as $k => $nilaiSah) {
            if (array_key_exists($k, $hasil) && ($hasil[$k] !== null || ! $rinci['kolom'][$k]['nullable'])) {
                $hasil[$k] = $this->keputusan->domain($def->tabel, $k, $hasil[$k], $nilaiSah);
            }
        }

        return $def->khusus === null ? $hasil : ($def->khusus)($hasil, $this->keputusan);
    }

    /**
     * @param array{tipeSumber: string, tipeTujuan: string, nullable: bool, panjang: int|null} $tipe
     * @param array<string, array{tabel: string, kolom: string}>                       $fk
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

            return $tipe['tipeTujuan'] === 'datetime' && ! in_array($kolom, $def->waktuBisnis, true)
                ? Transformasi::waktuUtc((string) $nilai, $tipe['tipeSumber'], $this->zonaSumber)
                : $nilai;
        }

        if (isset($fk[$kolom])) {
            $nilai = Transformasi::rujukan($nilai);
        }

        if ($nilai !== null && in_array($tipe['tipeTujuan'], self::TIPE_TEKS, true) && in_array($tipe['tipeSumber'], self::TIPE_TEKS, true)) {
            $nilai = in_array($kolom, $def->tanpaStripslashes, true) ? (string) $nilai : Transformasi::stripslashes((string) $nilai);

            if (in_array($kolom, $def->kolomNama, true)) {
                $nilai = Transformasi::nama($nilai);
            } elseif (in_array($kolom, $def->kolomKode, true)) {
                $nilai = trim($nilai);
            }

            if ($tipe['panjang'] !== null && mb_strlen($nilai) > $tipe['panjang']) {
                throw new ImporGagal("Panjang {$def->tabel}.{$kolom} melebihi {$tipe['panjang']} karakter.");
            }
        }

        return $nilai;
    }

    /**
     * @return array<string, array{tipe: string, nullable: bool, adaDefault: bool, auto: bool, panjang: int|null}>
     */
    private function kolom(BaseConnection $db, string $tabel): array
    {
        $rows = $db->query(
            'SELECT COLUMN_NAME AS n, DATA_TYPE AS t, IS_NULLABLE AS nl, COLUMN_DEFAULT AS d, EXTRA AS e, CHARACTER_MAXIMUM_LENGTH AS p'
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
                'panjang'    => in_array(strtolower((string) $r['t']), ['char', 'varchar'], true) ? (int) $r['p'] : null,
            ];
        }

        return $out;
    }

    /**
     * FK tabel tujuan: kolom → tabel induk (ber-prefix) + kolom induk.
     *
     * @return array<string, array{tabel: string, kolom: string}>
     */
    private function kolomFk(string $tabel): array
    {
        $rows = $this->tujuan->query(
            'SELECT COLUMN_NAME AS n, REFERENCED_TABLE_NAME AS t, REFERENCED_COLUMN_NAME AS k FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$this->tujuan->getDatabase(), $tabel],
        )->getResultArray();

        $out = [];

        foreach ($rows as $r) {
            $out[(string) $r['n']] = ['tabel' => (string) $r['t'], 'kolom' => (string) $r['k']];
        }

        return $out;
    }

    /**
     * Rujukan FK satu batch harus ada di tabel induk tujuan (audit yatim DBV-019 = 0). FK ke tabel yang sama (kolom
     * tunda) dicek MySQL saat UPDATE. Dry-run tidak memeriksa rujukan, karena induk dari tier yang sama belum ditulis.
     *
     * @param array<string, array{tabel: string, kolom: string}> $fk
     * @param list<array<string, mixed>>                          $tulis
     *
     * @return list<array<string, mixed>>
     */
    private function periksaRujukan(DefinisiTabel $def, array $fk, array $tulis): array
    {
        if ($tulis === [] || $this->dryRun) {
            return $tulis;
        }

        foreach ($fk as $kolom => $induk) {
            if (in_array($kolom, $def->kolomTunda, true) || ! array_key_exists($kolom, $tulis[0])) {
                continue;
            }

            $nilai = array_values(array_unique(array_map('strval', array_filter(array_column($tulis, $kolom), static fn ($v): bool => $v !== null))));

            if ($nilai === []) {
                continue;
            }

            $kolomInduk = $this->tujuan->escapeIdentifiers($induk['kolom']);
            $ada        = array_map('strval', array_column(
                $this->tujuan->query(
                    "SELECT {$kolomInduk} AS v FROM " . $this->tujuan->escapeIdentifiers($induk['tabel']) . " WHERE {$kolomInduk} IN ?",
                    [$nilai],
                )->getResultArray(),
                'v',
            ));
            $yatim = array_values(array_diff($nilai, $ada));

            if ($yatim === []) {
                continue;
            }

            if (! in_array($kolom, $def->yatimKeNull, true)) {
                throw new ImporGagal(sprintf('Rujukan yatim %s.%s: %d nilai tidak ada di tabel induk (audit DBV-019).', $def->tabel, $kolom, count($yatim)));
            }

            foreach ($tulis as $i => $baris) {
                if ($baris[$kolom] !== null && in_array((string) $baris[$kolom], $yatim, true)) {
                    $tulis[$i][$kolom] = null;
                    $this->laporan->koreksi($def->tabel, "{$kolom} yatim→NULL");
                }
            }
        }

        return $tulis;
    }
}
