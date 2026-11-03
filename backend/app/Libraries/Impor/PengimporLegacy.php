<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
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
 * 7. `kolomKode`: karakter tak terlihat dibuang lalu trim PHP (kode wilayah DBV-011 §2.2 #7; NIP CR-089, dicatat di
 *    laporan). Teks melebihi panjang kolom tujuan → gagal.
 * 8. Rujukan FK dicek per batch terhadap tabel induk di tujuan (audit DBV-019 = 0): yatim → NULL bila kolomnya
 *    `yatimKeNull` (dicatat), selain itu gagal dengan jumlah nilai yatim. Dry-run tidak memeriksa rujukan.
 *
 * 9. Indeks UNIQUE tujuan (selain PK) dicek di PHP per tabel, juga saat dry-run, terhadap baris seed dan baris yang akan
 *    ditulis: dua baris yang identik sesudah normalisasi (mis. nama beda spasi tengah) → gagal dengan nama tabel + indeks
 *    sebelum MySQL menolaknya (review DBV PR #32). Galat tulis di dalam transaksi menyebut tabel + kode galat.
 * 10. UPDATE kolom tunda menyertakan kolom ber-`ON UPDATE CURRENT_TIMESTAMP` dengan nilainya sendiri, supaya stempel
 *    hasil R1a tidak tertimpa waktu impor.
 *
 * Aturan khusus tabel boleh mengembalikan kunci `__koreksi` (list kode) untuk dicatat di laporan. Sesudah setiap tier
 * di-commit, counter AUTO_INCREMENT tujuan disamakan dengan sumber bila lebih kecil (DBV-011 §2.7; PK TINYINT dengan
 * counter ≥ 128 tidak disalin, dicatat PERIKSA). Langkah ini DDL, jadi berjalan di luar transaksi tier.
 *
 * Setiap hasil kueri/tulis diperiksa (R-1 review PR #32): dengan `DBDebug = false` CI4 tidak melempar exception, jadi
 * hasil `false`, `transStatus()` false, atau `transCommit()` false → {@see ImporGagal} dan tier di-rollback. Command
 * tetap memaksa `DBDebug = true` pada kedua koneksi.
 *
 * Kedua koneksi diawali sesi R4 (`time_zone = '+00:00'`, strict). Sejak CR-086 driver aplikasi `App\Database\MySQLi`
 * sudah memasang `time_zone = '+00:00'` saat connect di kedua koneksi (sumber salinan legacy dan tujuan); `aturSesi()`
 * tetap dipertahankan sebagai jaminan + verifikasi (`NOW() = UTC_TIMESTAMP()`). Skrip `mysqldump`/`mysql` CLI UP-1 tidak
 * memakai driver ini, jadi tidak terpengaruh. Tujuan wajib kosong, kecuali tabel `bolehSeed`.
 *
 * Sumber kedua opsional = salinan skema `siasn_simpeg` (`--sumber-siasn`, DBV-027 K-9) untuk definisi `skemaSiasn`; tanpa
 * koneksi itu tabelnya dilewati dan dilaporkan. Nama tabel sumber dicari tanpa beda huruf besar/kecil (`REF_UNOR`,
 * `*_ID` di server `lower_case_table_names` ≠ 0). `praPindai` (lintasan baca pertama) menandai baris yang tidak diimpor,
 * juga saat dry-run; `pascaImpor` menghitung INFO di tujuan sesudah commit.
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
        private readonly ?BaseConnection $sumberSiasn = null,
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

        if ($this->sumberSiasn !== null) {
            $this->aturSesi($this->sumberSiasn);
        }

        foreach ($tier as $t) {
            $mulai = hrtime(true);
            $this->jalankanTier($t, $dryRun);
            $this->laporan->waktuTier($t, (hrtime(true) - $mulai) / 1e9);
        }

        return $this->laporan;
    }

    private function aturSesi(BaseConnection $db): void
    {
        $this->perintah($db, "SET SESSION sql_mode = '" . self::SQL_MODE . "'");
        $this->perintah($db, "SET SESSION time_zone = '+00:00'");
        $cek = $this->kueri($db, 'SELECT NOW() = UTC_TIMESTAMP() AS ok')->getRowArray();

        if ((int) ($cek['ok'] ?? 0) !== 1) {
            throw new ImporGagal('Sesi R4 gagal: NOW() <> UTC_TIMESTAMP().');
        }
    }

    private function jalankanTier(int $tier, bool $dryRun): void
    {
        $defs  = DaftarTabel::tier($tier);
        $rinci = [];

        foreach ($defs as $def) {
            $rinci[$def->kunci()] = $this->dilewatiSiasn($def) ? null : $this->praCek($def);
        }

        if (! $dryRun) {
            $this->tujuan->transBegin();
        }

        try {
            foreach ($defs as $def) {
                $this->imporTabel($tier, $def, $rinci[$def->kunci()], $dryRun);
            }
        } catch (Throwable $e) {
            if (! $dryRun) {
                $this->tujuan->transRollback();
                $this->tujuan->resetTransStatus();
            }

            throw $e;
        }

        if (! $dryRun) {
            if ($this->tujuan->transStatus() === false) {
                $this->tujuan->transRollback();
                $this->tujuan->resetTransStatus();

                throw new ImporGagal("Tier {$tier} gagal di-commit (status transaksi gagal); tier di-rollback.");
            }

            if ($this->tujuan->transCommit() === false) {
                $this->tujuan->resetTransStatus();

                throw new ImporGagal("Tier {$tier} gagal di-commit.");
            }

            foreach ($defs as $def) {
                $r = $rinci[$def->kunci()];

                if ($r === null) {
                    continue;
                }

                $this->salinCounter($def, $r['sumber']);

                foreach ($def->pascaImpor === null ? [] : ($def->pascaImpor)($this->tujuan) as $kode => $n) {
                    if ($n > 0) {
                        $this->laporan->koreksi($def->kunci(), $kode, $n);
                    }
                }
            }
        }
    }

    private function dilewatiSiasn(DefinisiTabel $def): bool
    {
        return $def->skemaSiasn && $this->sumberSiasn === null;
    }

    private function sumberDari(DefinisiTabel $def): BaseConnection
    {
        return $def->skemaSiasn && $this->sumberSiasn !== null ? $this->sumberSiasn : $this->sumber;
    }

    /**
     * Nama tabel sumber sebenarnya (tanpa beda huruf besar/kecil); null bila tidak ada.
     */
    private function namaSumber(BaseConnection $db, string $nama): ?string
    {
        $rows = $this->kueri(
            $db,
            'SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND LOWER(TABLE_NAME) = LOWER(?)',
            [$db->getDatabase(), $nama],
        )->getResultArray();

        if (count($rows) > 1) {
            throw new ImporGagal("Tabel sumber {$nama} ada lebih dari satu (beda huruf besar/kecil).");
        }

        return $rows === [] ? null : (string) $rows[0]['n'];
    }

    /**
     * Counter AUTO_INCREMENT tujuan = counter sumber bila lebih besar (DBV-011 §2.7). Tidak pernah diturunkan.
     */
    private function salinCounter(DefinisiTabel $def, string $namaSumber): void
    {
        $sumber = $this->counter($this->sumberDari($def), $namaSumber);
        $tabel  = $this->tujuan->prefixTable($def->tabel);
        $tujuan = $this->counter($this->tujuan, $tabel);

        if ($sumber === null || $tujuan === null || $sumber <= $tujuan) {
            return;
        }

        $tipe = $this->kueri(
            $this->tujuan,
            'SELECT DATA_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->tujuan->getDatabase(), $tabel, $def->pk],
        )->getRowArray();

        if (strtolower((string) ($tipe['t'] ?? '')) === 'tinyint' && $sumber >= 128) {
            $this->laporan->koreksi($def->kunci(), "PERIKSA counter TINYINT {$sumber} tidak disalin");

            return;
        }

        $this->perintah($this->tujuan, 'ALTER TABLE ' . $this->tujuan->escapeIdentifiers($tabel) . ' AUTO_INCREMENT = ' . $sumber);
        $this->laporan->koreksi($def->kunci(), "counter AUTO_INCREMENT {$tujuan}→{$sumber}");
    }

    private function counter(BaseConnection $db, string $tabel): ?int
    {
        if (! str_contains(strtolower($db->getVersion()), 'mariadb')) {
            $this->perintah($db, 'SET SESSION information_schema_stats_expiry = 0');
        }

        $r = $this->kueri(
            $db,
            'SELECT AUTO_INCREMENT AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$db->getDatabase(), $tabel],
        )->getRowArray();

        return isset($r['n']) ? (int) $r['n'] : null;
    }

    /**
     * @return array{ada: bool, sumber: string, kolom: array<string, array{src: string, tipeSumber: string, tipeTujuan: string, nullable: bool, panjang: int|null}>, fk: array<string, array{tabel: string, kolom: string}>, seed: array<string, string>, unik: array<string, list<string>>, onUpdate: list<string>, barisSeed: list<array<string, mixed>>}|null
     */
    private function praCek(DefinisiTabel $def): ?array
    {
        $src        = $this->sumberDari($def);
        $namaSumber = $this->namaSumber($src, $def->sumber ?? $def->tabel);

        if ($namaSumber === null) {
            if ($def->opsional) {
                return null;
            }

            throw new ImporGagal('Tabel sumber ' . ($def->sumber ?? $def->tabel) . ' tidak ada.');
        }

        $kolomSumber = [];

        foreach ($this->kolom($src, $namaSumber) as $n => $t) {
            $kolomSumber[strtolower($n)] = ['nama' => $n] + $t;
        }

        $kolomTujuan = $this->kolom($this->tujuan, $this->tujuan->prefixTable($def->tabel));

        if ($kolomTujuan === []) {
            throw new ImporGagal("Tabel tujuan {$def->tabel} tidak ada (jalankan migrate --all).");
        }

        $kolom = [];

        foreach ($kolomTujuan as $nama => $t) {
            $asal = strtolower($def->petaKolom[$nama] ?? $nama);

            if (! array_key_exists($nama, $def->konstanta) && isset($kolomSumber[$asal])) {
                $kolom[$nama] = [
                    'src'        => $kolomSumber[$asal]['nama'],
                    'tipeSumber' => $kolomSumber[$asal]['tipe'],
                    'tipeTujuan' => $t['tipe'],
                    'nullable'   => $t['nullable'],
                    'panjang'    => $t['panjang'],
                ];

                continue;
            }

            $diisi = ($def->urutan !== null && $def->urutan['kolom'] === $nama) || array_key_exists($nama, $def->konstanta);

            if (! $t['nullable'] && ! $t['adaDefault'] && ! $t['auto'] && ! $diisi) {
                throw new ImporGagal("Kolom {$def->tabel}.{$nama} NOT NULL tanpa DEFAULT tidak ada di sumber.");
            }
        }

        if (! isset($kolom[$def->pk])) {
            throw new ImporGagal("Kolom PK {$def->tabel}.{$def->pk} tidak ada di sumber.");
        }

        $onUpdate  = array_keys(array_filter($kolomTujuan, static fn (array $t): bool => $t['onUpdate']));
        $seed      = [];
        $barisSeed = [];
        $isi       = $this->tujuan->table($def->tabel)->countAllResults();

        if (! is_int($isi)) {
            throw new ImporGagal($this->pesanGagal($this->tujuan, "hitung tujuan {$def->tabel}"));
        }

        if ($isi > 0) {
            if (! $def->bolehSeed || $def->kolomNama === []) {
                throw new ImporGagal("Tabel tujuan {$def->tabel} tidak kosong ({$isi} baris).");
            }

            $hasilSeed = $this->tujuan->table($def->tabel)->get();

            if (! $hasilSeed instanceof ResultInterface) {
                throw new ImporGagal($this->pesanGagal($this->tujuan, "baca seed {$def->tabel}"));
            }

            $barisSeed = $hasilSeed->getResultArray();

            foreach ($barisSeed as $r) {
                $seed[(string) $r[$def->pk]] = (string) $r[$def->kolomNama[0]];
            }
        }

        return [
            'ada'       => true,
            'sumber'    => $namaSumber,
            'kolom'     => $kolom,
            'fk'        => $this->kolomFk($this->tujuan->prefixTable($def->tabel)),
            'seed'      => $seed,
            'unik'      => $this->indeksUnik($this->tujuan->prefixTable($def->tabel)),
            'onUpdate'  => $onUpdate,
            'barisSeed' => $barisSeed,
        ];
    }

    /**
     * @param array{ada: bool, sumber: string, kolom: array<string, array{src: string, tipeSumber: string, tipeTujuan: string, nullable: bool, panjang: int|null}>, fk: array<string, array{tabel: string, kolom: string}>, seed: array<string, string>, unik: array<string, list<string>>, onUpdate: list<string>, barisSeed: list<array<string, mixed>>}|null $rinci
     */
    private function imporTabel(int $tier, DefinisiTabel $def, ?array $rinci, bool $dryRun): void
    {
        if ($rinci === null) {
            $this->laporan->mulai($tier, $def, 0, $this->dilewatiSiasn($def) ? 'dilewati: tanpa --sumber-siasn (DBV-027 K-9)' : 'tidak ada di sumber (opsional)');

            return;
        }

        $src    = $this->sumberDari($def);
        $tabel  = $src->escapeIdentifiers($rinci['sumber']);
        $jumlah = (int) $this->kueri($src, 'SELECT COUNT(*) AS n FROM ' . $tabel)->getRowArray()['n'];
        $this->laporan->mulai($tier, $def, $jumlah, $dryRun ? 'dry-run' : '');

        $pilih    = implode(', ', array_map(static fn (string $k, array $t): string => $src->escapeIdentifiers($t['src']) . ' AS ' . $src->escapeIdentifiers($k), array_keys($rinci['kolom']), $rinci['kolom']));
        $kunci    = $def->kunciUrut === [] ? [$def->pk] : $def->kunciUrut;
        $urutPk   = implode(', ', array_map(static fn (string $k): string => $src->escapeIdentifiers($rinci['kolom'][$k]['src']), $kunci));
        $buang    = $def->praPindai === null ? [] : ($def->praPindai)($src, $rinci['sumber'], $this->keputusan);
        $urutan   = [];
        $tunda    = [];
        $terakhir = null;
        $dilihat  = [];

        foreach ($rinci['barisSeed'] as $r) {
            $this->catatUnik($def, $rinci['unik'], $r, $dilihat);
        }

        do {
            $syarat = $terakhir === null ? '' : " WHERE ({$urutPk}) > (" . implode(', ', array_fill(0, count($kunci), '?')) . ')';
            $sql    = "SELECT {$pilih} FROM {$tabel}{$syarat} ORDER BY {$urutPk} LIMIT {$this->ukuranBatch}";
            $baris  = $this->kueri($src, $sql, $terakhir ?? [])->getResultArray();
            $tulis  = [];

            foreach ($baris as $mentah) {
                $terakhir = array_map(static fn (string $k): mixed => $mentah[$k], $kunci);

                if (isset($buang[(string) $mentah[$def->pk]])) {
                    $this->laporan->dibuang($def->kunci(), $buang[(string) $mentah[$def->pk]]);

                    continue;
                }

                $hasil = $this->ubahBaris($def, $rinci, $mentah);

                if (is_string($hasil)) {
                    $this->laporan->dibuang($def->kunci(), $hasil);

                    continue;
                }

                foreach ($hasil['__koreksi'] ?? [] as $kode) {
                    $this->laporan->koreksi($def->kunci(), (string) $kode);
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

                    $this->laporan->dilewati($def->kunci());

                    continue;
                }

                $tulis[] = $hasil;
            }

            foreach ($tulis as $r) {
                $this->catatUnik($def, $rinci['unik'], $r, $dilihat);
            }

            $tulis = $this->periksaRujukan($def, $rinci['fk'], $tulis);

            if ($tulis !== [] && ! $dryRun) {
                if ($this->tujuan->table($def->tabel)->insertBatch($tulis) === false || $this->tujuan->transStatus() === false) {
                    throw new ImporGagal($this->pesanGagal($this->tujuan, "tulis {$def->tabel}"));
                }
            }

            $this->laporan->ditulis($def->kunci(), count($tulis));
        } while (count($baris) === $this->ukuranBatch);

        if ($tunda !== [] && ! $dryRun) {
            foreach ($tunda as $u) {
                $id = $u[$def->pk];
                unset($u[$def->pk]);
                $ubah = $this->tujuan->table($def->tabel)->set($u);

                foreach ($rinci['onUpdate'] as $k) {
                    // Ditulis dengan nilainya sendiri → ON UPDATE CURRENT_TIMESTAMP tidak memicu (stempel R1a tetap).
                    $ubah->set($k, $this->tujuan->escapeIdentifiers($k), false);
                }

                if ($ubah->where($def->pk, $id)->update() === false || $this->tujuan->transStatus() === false) {
                    throw new ImporGagal($this->pesanGagal($this->tujuan, "isi kolom tunda {$def->tabel}"));
                }
            }
        }
    }

    /**
     * @param array{ada: bool, sumber: string, kolom: array<string, array{src: string, tipeSumber: string, tipeTujuan: string, nullable: bool, panjang: int|null}>, fk: array<string, array{tabel: string, kolom: string}>, seed: array<string, string>, unik: array<string, list<string>>, onUpdate: list<string>, barisSeed: list<array<string, mixed>>} $rinci
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

        $hasil = $def->konstanta + $hasil;

        return $def->khusus === null ? $hasil : ($def->khusus)($hasil, $this->keputusan);
    }

    /**
     * @param array{src: string, tipeSumber: string, tipeTujuan: string, nullable: bool, panjang: int|null} $tipe
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
            $nilai = $def->tanpaStripslashes === ['*'] || in_array($kolom, $def->tanpaStripslashes, true) ? (string) $nilai : Transformasi::stripslashes((string) $nilai);

            if (in_array($kolom, $def->kolomNama, true)) {
                $nilai = Transformasi::nama($nilai);
            } elseif (in_array($kolom, $def->kolomKode, true)) {
                $kode = (string) Transformasi::kode($nilai);

                if ($kode !== trim($nilai)) {
                    $this->laporan->koreksi($def->kunci(), "{$kolom} karakter tak terlihat dibuang");
                }

                $nilai = $kode;
            }

            if ($tipe['panjang'] !== null && mb_strlen($nilai) > $tipe['panjang']) {
                throw new ImporGagal("Panjang {$def->tabel}.{$kolom} melebihi {$tipe['panjang']} karakter.");
            }
        }

        return $nilai;
    }

    /**
     * @return array<string, array{tipe: string, nullable: bool, adaDefault: bool, auto: bool, onUpdate: bool, panjang: int|null}>
     */
    private function kolom(BaseConnection $db, string $tabel): array
    {
        $rows = $this->kueri(
            $db,
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
                'onUpdate'   => str_contains(strtolower((string) $r['e']), 'on update'),
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
        $rows = $this->kueri(
            $this->tujuan,
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
     * Indeks UNIQUE tujuan selain PRIMARY: nama indeks → kolom (urut).
     *
     * @return array<string, list<string>>
     */
    private function indeksUnik(string $tabel): array
    {
        $rows = $this->kueri(
            $this->tujuan,
            'SELECT INDEX_NAME AS i, COLUMN_NAME AS n FROM information_schema.STATISTICS'
            . " WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY' ORDER BY INDEX_NAME, SEQ_IN_INDEX",
            [$this->tujuan->getDatabase(), $tabel],
        )->getResultArray();

        $out = [];

        foreach ($rows as $r) {
            $out[(string) $r['i']][] = (string) $r['n'];
        }

        return $out;
    }

    /**
     * Pra-cek UNIQUE di PHP (juga saat dry-run). Pembanding meniru `utf8mb4_unicode_ci` (PAD SPACE): beda huruf besar/kecil
     * dan spasi akhir diabaikan; NULL tidak pernah bentrok. Pesan hanya menyebut tabel + indeks, tanpa nilai baris.
     *
     * @param array<string, list<string>>        $unik
     * @param array<string, mixed>               $baris
     * @param array<string, array<string, true>> $dilihat
     */
    private function catatUnik(DefinisiTabel $def, array $unik, array $baris, array &$dilihat): void
    {
        foreach ($unik as $indeks => $kolom) {
            $bagian = [];

            foreach ($kolom as $k) {
                if (! array_key_exists($k, $baris) || $baris[$k] === null) {
                    continue 2;
                }

                $bagian[] = mb_strtolower(rtrim((string) $baris[$k], ' '));
            }

            $kunci = implode("\x1F", $bagian);

            if (isset($dilihat[$indeks][$kunci])) {
                throw new ImporGagal("Bentrok UNIQUE {$def->tabel}.{$indeks}: dua baris identik sesudah normalisasi; tier di-rollback.");
            }

            $dilihat[$indeks][$kunci] = true;
        }
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
                $this->kueri(
                    $this->tujuan,
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
                    $this->laporan->koreksi($def->kunci(), "{$kolom} yatim→NULL");
                }
            }
        }

        return $tulis;
    }

    /**
     * Kueri dengan pemeriksaan hasil: `false` (DBDebug = false) → {@see ImporGagal}.
     *
     * @param array<int|string, mixed> $binds
     */
    private function kueri(BaseConnection $db, string $sql, array $binds = []): ResultInterface
    {
        $hasil = $db->query($sql, $binds === [] ? null : $binds);

        if (! $hasil instanceof ResultInterface) {
            throw new ImporGagal($this->pesanGagal($db, 'kueri'));
        }

        return $hasil;
    }

    /**
     * Perintah tanpa hasil baris (SET, ALTER) dengan pemeriksaan hasil.
     */
    private function perintah(BaseConnection $db, string $sql): void
    {
        if ($db->query($sql) === false) {
            throw new ImporGagal($this->pesanGagal($db, 'perintah sesi/DDL'));
        }
    }

    /**
     * Pesan galat DB tanpa isi baris: hanya langkah + kode galat (pesan MySQL bisa memuat nilai, mis. 1062).
     */
    private function pesanGagal(BaseConnection $db, string $langkah): string
    {
        $galat = $db->error();

        return sprintf('Galat DB saat %s (kode %s); tier di-rollback.', $langkah, (string) ($galat['code'] ?? '?'));
    }
}
