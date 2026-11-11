<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use App\Libraries\Dashboard\Konten\Berita\PenyimpananLampiran;
use App\Libraries\Dashboard\Konten\Berita\SanitasiBerita;
use App\Libraries\Dashboard\Konten\Dibo\PenyimpananDibo;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;

/**
 * MAKE-089 — aturan impor tier 14 Fase 7 (konten & interaksi; DBV-032 §10, keputusan reviewer Q-089-1..6 11-11-2026).
 *
 * - Berkas legacy (`assets/upload/<jenis>/…` di webroot) → kunci storage DETERMINISTIK `<jenis>/<hex32>.<ext>`,
 *   `hex32 = sha256("MAKE-089|<tabel>|<kolom>|<id>|<path legacy>")[0..32]`, sama dengan format buatan aplikasi Jalur A/B.
 *   Berkasnya disalin command `impor:berkas-konten` (menghitung kunci yang sama). Path tidak sah (traversal/absolut/karakter
 *   asing) atau ekstensi di luar daftar putih = AMBIGU.
 * - JSON target (`target_uns`/`target_peg`/`excl_peg`) legacy ber-`addslashes` → `stripslashes` lalu divalidasi (token unit
 *   Jalur A `"0"`/`"<id_unit>"`/`"sat_<id_satker>"`, NIP). Tidak valid = AMBIGU.
 * - AMBIGU memakai pola keputusan F-02: tanpa putusan → {@see ImporGagal} dengan jumlah per kode (juga dry-run); putusan per id
 *   di berkas keputusan bagian `baris` `<tabel>.f7` = `"__buang"` (satu-satunya nilai, Q-089-4).
 * - Popup `type ≠ 1` (video) dilewati beserta `user_popup`-nya (DBV-032-Q9), id dicatat di kode laporan.
 */
final class AturanKonten
{
    public const BUANG = '__buang';

    public const KOLOM_KEPUTUSAN = 'f7';

    public const VIDEO = 'MAKE-089 Q9 popup video dilewati';

    public const IKUT_INDUK = 'MAKE-089 ikut induk dibuang';

    public const DIBUANG_KEPUTUSAN = 'MAKE-089 dibuang (keputusan eskalasi)';

    public const A_BERKAS = 'MAKE-089 berkas tidak sah (path/ekstensi)';

    public const A_TARGET = 'MAKE-089 JSON target tidak valid';

    public const EKSTENSI_GAMBAR = ['jpg', 'jpeg', 'png', 'gif'];

    private const POLA_PATH = '/^[A-Za-z0-9][A-Za-z0-9._\-\/ ]*$/';

    private const POLA_UNIT = '/^(0|[1-9][0-9]{0,9}|sat_[1-9][0-9]{0,9})$/';

    private const POLA_NIP = '/^[0-9A-Za-z.\-]{1,30}$/';

    private const BATCH = 2000;

    private function __construct()
    {
    }

    // ------------------------------------------------------------------ kunci berkas

    /**
     * Kunci storage deterministik untuk satu berkas legacy; null bila path/ekstensi tidak sah.
     *
     * @param list<string> $ekstensi daftar putih
     */
    public static function kunciBerkas(string $jenis, string $tabel, string $kolom, int|string $id, string $path, array $ekstensi): ?string
    {
        if (! self::pathSah($path)) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($ext, $ekstensi, true)) {
            return null;
        }

        return $jenis . '/' . substr(hash('sha256', "MAKE-089|{$tabel}|{$kolom}|{$id}|{$path}"), 0, 32) . '.' . $ext;
    }

    /**
     * Path relatif legacy yang aman: tanpa segmen `..`, tidak absolut, tanpa backslash/skema/NUL.
     */
    public static function pathSah(string $path): bool
    {
        if ($path === '' || preg_match(self::POLA_PATH, $path) !== 1 || str_contains($path, '//')) {
            return false;
        }

        foreach (explode('/', $path) as $segmen) {
            if ($segmen === '..' || $segmen === '.') {
                return false;
            }
        }

        return true;
    }

    /**
     * JSON target legacy sah: NULL/'' atau list string dengan token sesuai jenis kolom.
     */
    public static function targetSah(?string $json, string $kolom): bool
    {
        if ($json === null || trim($json) === '') {
            return true;
        }

        $isi = json_decode($json, true);

        if (! is_array($isi) || ! array_is_list($isi)) {
            return false;
        }

        $pola = $kolom === 'target_uns' ? self::POLA_UNIT : self::POLA_NIP;

        foreach ($isi as $v) {
            if (! is_string($v) && ! is_int($v)) {
                return false;
            }

            if (preg_match($pola, trim((string) $v)) !== 1) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ praPindai

    /**
     * @return Closure(BaseConnection, string, Keputusan): array<int|string, string>
     */
    public static function praPindaiPengumuman(string $tabel, string $pk, string $jenis, bool $lewatiVideo): Closure
    {
        return static function (BaseConnection $db, string $namaSumber, Keputusan $k) use ($tabel, $pk, $jenis, $lewatiVideo): array {
            return self::klasifikasiPengumuman($db, $namaSumber, $tabel, $pk, $jenis, $lewatiVideo, $k);
        };
    }

    /**
     * Anak berpenerima (`user_popup`/`user_banner`): baris yang induknya dilewati/dibuang ikut dibuang. Kunci baris =
     * `<id induk>\x1F<nip>` (urutan `kunciUrut` [id induk, nip]).
     *
     * @return Closure(BaseConnection, string, Keputusan): array<int|string, string>
     */
    public static function praPindaiAnak(string $tabelInduk, string $pkInduk, string $jenis, bool $lewatiVideo): Closure
    {
        return static function (BaseConnection $db, string $namaSumber, Keputusan $k) use ($tabelInduk, $pkInduk, $jenis, $lewatiVideo): array {
            $sumberInduk = self::namaTabel($db, $tabelInduk);
            $indukBuang  = array_keys(self::klasifikasiPengumuman($db, $sumberInduk, $tabelInduk, $pkInduk, $jenis, $lewatiVideo, $k));

            if ($indukBuang === []) {
                return [];
            }

            $buang = [];

            foreach (array_chunk($indukBuang, 500) as $potong) {
                $sql = 'SELECT ' . $db->escapeIdentifiers($pkInduk) . ' AS i, ' . $db->escapeIdentifiers('nip') . ' AS n FROM ' . $db->escapeIdentifiers($namaSumber)
                    . ' WHERE ' . $db->escapeIdentifiers($pkInduk) . ' IN (' . implode(', ', array_map('intval', $potong)) . ')';

                foreach (self::kueri($db, $sql) as $r) {
                    $buang[$r['i'] . "\x1F" . $r['n']] = self::IKUT_INDUK;
                }
            }

            return $buang;
        };
    }

    /**
     * `news.lampiran` (nullable) dan `dibo.file_1..3`: path/ekstensi tidak sah = ambigu (pola F-02).
     *
     * @param array<string, list<string>> $kolomBerkas kolom → ekstensi daftar putih
     *
     * @return Closure(BaseConnection, string, Keputusan): array<int|string, string>
     */
    public static function praPindaiBerkas(string $tabel, string $pk, array $kolomBerkas): Closure
    {
        return static function (BaseConnection $db, string $namaSumber, Keputusan $k) use ($tabel, $pk, $kolomBerkas): array {
            $ambigu = [];

            foreach (self::baca($db, $namaSumber, $pk, array_keys($kolomBerkas)) as $r) {
                foreach ($kolomBerkas as $kolom => $ekstensi) {
                    $path = $r[$kolom] === null ? '' : Transformasi::stripslashes((string) $r[$kolom]);

                    if ($path !== '' && self::kunciBerkas('x', $tabel, $kolom, (string) $r[$pk], $path, $ekstensi) === null) {
                        $ambigu[(string) $r[$pk]] = self::A_BERKAS;
                    }
                }
            }

            return self::putus($ambigu, $tabel, $k);
        };
    }

    /**
     * `skl` UNIQUE (nip, jenis_rwy, id_rwy) — legacy KEY biasa: per kunci ganda dipertahankan `id_skl` terkecil, sisanya
     * dibuang + dicatat (setara INSERT IGNORE). Isi `kritik_saran` tidak dibaca.
     *
     * @return array<int|string, string>
     */
    public static function praPindaiSkl(BaseConnection $db, string $namaSumber, Keputusan $k): array
    {
        $lihat = [];
        $buang = [];

        foreach (self::baca($db, $namaSumber, 'id_skl', ['nip', 'jenis_rwy', 'id_rwy']) as $r) {
            $kunci = mb_strtolower((string) Transformasi::kode((string) $r['nip'])) . "\x1F" . $r['jenis_rwy'] . "\x1F" . $r['id_rwy'];

            if (isset($lihat[$kunci])) {
                $buang[(string) $r['id_skl']] = DefinisiTabel::KODE_GANDA;
            } else {
                $lihat[$kunci] = true;
            }
        }

        return $buang;
    }

    // ------------------------------------------------------------------ khusus (per baris)

    /**
     * Popup/banner: kunci berkas deterministik; target '' → NULL.
     *
     * @return Closure(array<string, mixed>, Keputusan): array<string, mixed>
     */
    public static function khususPengumuman(string $tabel, string $pk, string $jenis): Closure
    {
        return static function (array $baris, Keputusan $k) use ($tabel, $pk, $jenis): array {
            $baris['file'] = self::kunciBerkas($jenis, $tabel, 'file', (string) $baris[$pk], (string) $baris['file'], self::EKSTENSI_GAMBAR)
                ?? throw new ImporGagal("{$tabel}: berkas tidak sah lolos pra-pindai (bug alat impor).");

            foreach (['target_uns', 'target_peg', 'excl_peg'] as $kolom) {
                if (array_key_exists($kolom, $baris) && is_string($baris[$kolom]) && trim($baris[$kolom]) === '') {
                    $baris[$kolom] = null;
                }
            }

            return $baris;
        };
    }

    /**
     * `news`: isi disanitasi dengan whitelist berita Jalur A (Q7-10, Q-089-3); lampiran → kunci `berita/…`.
     *
     * @return Closure(array<string, mixed>, Keputusan): array<string, mixed>
     */
    public static function khususBerita(?SanitasiBerita $sanitasi = null): Closure
    {
        $sanitasi ??= new SanitasiBerita();

        return static function (array $baris, Keputusan $k) use ($sanitasi): array {
            $asli          = (string) $baris['news'];
            $baris['news'] = $sanitasi->bersihkan($asli);

            if ($baris['news'] !== $asli) {
                $baris['__koreksi'][] = 'MAKE-089 isi berita disanitasi (Q7-10)';
            }

            $lampiran = $baris['lampiran'] ?? null;

            if ($lampiran === null || trim((string) $lampiran) === '') {
                $baris['lampiran'] = null;
            } else {
                $baris['lampiran'] = self::kunciBerkas('berita', 'news', 'lampiran', (string) $baris['id_news'], (string) $lampiran, PenyimpananLampiran::EKSTENSI)
                    ?? throw new ImporGagal('news: lampiran tidak sah lolos pra-pindai (bug alat impor).');
            }

            return $baris;
        };
    }

    /**
     * `news_category.status` legacy 0 nonaktif / 1 aktif → v2 2 / 1 (DBV-032 §3.5).
     *
     * @return Closure(array<string, mixed>, Keputusan): array<string, mixed>
     */
    public static function khususKategoriBerita(): Closure
    {
        return static function (array $baris, Keputusan $k): array {
            if ((int) $baris['status'] === 0) {
                $baris['status']      = 2;
                $baris['__koreksi'][] = 'MAKE-089 status 0→2';
            }

            return $baris;
        };
    }

    /**
     * `dibo`: `tahun` 0000 → NULL + dicatat (N2); berkas → kunci `dibo/…`.
     *
     * @return Closure(array<string, mixed>, Keputusan): array<string, mixed>
     */
    public static function khususDibo(): Closure
    {
        return static function (array $baris, Keputusan $k): array {
            if (isset($baris['tahun']) && (int) $baris['tahun'] === 0) {
                $baris['tahun']       = null;
                $baris['__koreksi'][] = 'MAKE-089 N2 tahun 0000→NULL';
            }

            foreach (['file_1', 'file_2', 'file_3'] as $kolom) {
                $path = $baris[$kolom] ?? null;

                if ($path === null || trim((string) $path) === '') {
                    $baris[$kolom] = $kolom === 'file_1' ? $path : null;

                    continue;
                }

                $baris[$kolom] = self::kunciBerkas('dibo', 'dibo', $kolom, (string) $baris['id_dibo'], (string) $path, PenyimpananDibo::EKSTENSI)
                    ?? throw new ImporGagal('dibo: berkas tidak sah lolos pra-pindai (bug alat impor).');
            }

            return $baris;
        };
    }

    // ------------------------------------------------------------------ dasar

    /**
     * Klasifikasi popup/banner: video (popup) → dilewati; berkas/target tidak sah → ambigu, diputus per id.
     *
     * @return array<string, string>
     */
    private static function klasifikasiPengumuman(BaseConnection $db, string $namaSumber, string $tabel, string $pk, string $jenis, bool $lewatiVideo, Keputusan $k): array
    {
        $lewat  = [];
        $ambigu = [];
        // Kolom `type` hanya ada di legacy (v2 tidak membawanya); sumber tanpa kolom itu = tanpa popup video.
        $lewatiVideo = $lewatiVideo && self::kueri(
            $db,
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$db->getDatabase(), $namaSumber, 'type'],
        ) !== [];
        $kolom = ['file', 'target_uns', 'target_peg', 'excl_peg', ...($lewatiVideo ? ['type'] : [])];

        foreach (self::baca($db, $namaSumber, $pk, $kolom) as $r) {
            $id = (string) $r[$pk];

            if ($lewatiVideo && (int) $r['type'] !== 1) {
                $lewat[$id] = self::VIDEO . " (id {$id})";

                continue;
            }

            $path = Transformasi::stripslashes((string) $r['file']);

            if (self::kunciBerkas($jenis, $tabel, 'file', $id, $path, self::EKSTENSI_GAMBAR) === null) {
                $ambigu[$id] = self::A_BERKAS;

                continue;
            }

            foreach (['target_uns', 'target_peg', 'excl_peg'] as $t) {
                if (! self::targetSah($r[$t] === null ? null : Transformasi::stripslashes((string) $r[$t]), $t)) {
                    $ambigu[$id] = self::A_TARGET;

                    break;
                }
            }
        }

        return $lewat + self::putus($ambigu, $tabel, $k);
    }

    /**
     * Putusan per id (pola F-02): "__buang" → dibuang; tanpa putusan → gagal dengan jumlah per kode; nilai lain ditolak.
     *
     * @param array<string, string> $ambigu
     *
     * @return array<string, string>
     */
    private static function putus(array $ambigu, string $tabel, Keputusan $k): array
    {
        $buang = [];
        $belum = [];

        foreach ($ambigu as $id => $kode) {
            $p = $k->baris($tabel, self::KOLOM_KEPUTUSAN, (string) $id);

            if (! $p['ada']) {
                $belum[$kode] = ($belum[$kode] ?? 0) + 1;
            } elseif ($p['nilai'] === self::BUANG) {
                $buang[(string) $id] = self::DIBUANG_KEPUTUSAN;
            } else {
                throw new ImporGagal("{$tabel}: keputusan {$tabel}." . self::KOLOM_KEPUTUSAN . ' hanya boleh "' . self::BUANG . '" (Q-089-4).');
            }
        }

        if ($belum !== []) {
            ksort($belum);
            $rincian = implode(', ', array_map(static fn (string $kode, int $n): string => "{$kode}: {$n}", array_keys($belum), $belum));

            throw new ImporGagal("{$tabel}: " . array_sum($belum) . " baris ambigu belum diputuskan ({$rincian}); putuskan per id di berkas keputusan bagian baris {$tabel}." . self::KOLOM_KEPUTUSAN . ' (jangan ditebak).');
        }

        return $buang;
    }

    private static function namaTabel(BaseConnection $db, string $nama): string
    {
        $rows = self::kueri($db, 'SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND LOWER(TABLE_NAME) = LOWER(?)', [$db->getDatabase(), $nama]);

        if (count($rows) !== 1) {
            throw new ImporGagal("Tabel sumber {$nama} tidak ada.");
        }

        return (string) $rows[0]['n'];
    }

    /**
     * @param list<mixed>|null $bind
     *
     * @return list<array<string, mixed>>
     */
    private static function kueri(BaseConnection $db, string $sql, ?array $bind = null): array
    {
        $hasil = $db->query($sql, $bind);

        if (! $hasil instanceof ResultInterface) {
            throw new ImporGagal('Galat DB saat pra-pindai konten (kode ' . (string) ($db->error()['code'] ?? '?') . ').');
        }

        /** @var list<array<string, mixed>> */
        return $hasil->getResultArray();
    }

    /**
     * @param list<string> $kolom
     *
     * @return iterable<array<string, mixed>>
     */
    private static function baca(BaseConnection $db, string $tabel, string $pk, array $kolom): iterable
    {
        $pilih    = implode(', ', array_map(static fn (string $k): string => $db->escapeIdentifiers($k), [$pk, ...$kolom]));
        $terakhir = null;

        do {
            $sql = "SELECT {$pilih} FROM " . $db->escapeIdentifiers($tabel)
                . ($terakhir === null ? '' : ' WHERE ' . $db->escapeIdentifiers($pk) . ' > ?')
                . ' ORDER BY ' . $db->escapeIdentifiers($pk) . ' LIMIT ' . self::BATCH;
            $baris = self::kueri($db, $sql, $terakhir === null ? null : [$terakhir]);

            foreach ($baris as $r) {
                $terakhir = $r[$pk];

                yield $r;
            }
        } while (count($baris) === self::BATCH);
    }
}
