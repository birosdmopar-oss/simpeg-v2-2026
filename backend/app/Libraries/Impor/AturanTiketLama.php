<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;

/**
 * F-02 Sortir inbox legacy (Fase 7 Jalur C, MAKE-086; PRD Fase 7 v2.0 §5.2, Q7-28) untuk tier "tiket lama".
 *
 * Kriteria diturunkan dari KODE legacy, bukan dari data: satu-satunya penulis `inbox`/`inbox_msg` legacy adalah Halo
 * Simpeg (`libraries/hr/L_support.php:997,1006,1169,1341`; tidak ada penulis lain di controllers/models/libraries/core),
 * jadi SEMUA baris = tiket → `disiplin_ticket(_msg)`; `inbox` v2 (notifikasi) tidak ditulis (DBV-022 §3.4). Bentuk tiket
 * yang sah mengikuti pola penulisan `insert_inbox` (`LS:937-1095`, `set_param_inbox` `LS:1528-1550`, validasi
 * `LS:1505-1527`) dan `set_param_conversation` (`LS:1567-1597`). Baris yang tidak berbentuk tiket = AMBIGU: tidak ditebak,
 * dieskalasi per id ke Horii/DBV lewat berkas keputusan (bagian `baris`):
 *
 *   "baris": {"disiplin_ticket.f02": {"<id_inbox>": "impor" | "__buang"},
 *             "disiplin_ticket_msg.f02": {"<id_inbox_msg>": "impor" | "__buang"}}
 *
 * Ambigu tanpa keputusan → {@see ImporGagal} dengan jumlah per kode (juga saat dry-run): tidak ada yang ditulis.
 * Daftar id untuk eskalasi dibuat {@see self::manifest()} (command `impor:sortir-inbox`), tanpa isi pesan/NIP/nama.
 * Normalisasi nilai di luar domain CHECK (kelas T-NORM/M-NORM) memakai bagian `status` berkas keputusan seperti tier lain.
 */
final class AturanTiketLama
{
    public const KUNCI_TIKET = 'disiplin_ticket.f02';

    public const KUNCI_PESAN = 'disiplin_ticket_msg.f02';

    public const BUANG = '__buang';

    public const IMPOR = 'impor';

    /** Kode kelas ambigu tiket (A-ESK). */
    public const A_PENGIRIM = 'F-02 A-ESK pengirim tidak sah';

    public const A_SUBJEK = 'F-02 A-ESK subjek kosong';

    public const A_NIP = 'F-02 A-ESK nip kosong';

    public const A_TANPA_PESAN = 'F-02 A-ESK tanpa pesan';

    /** Kode kelas ambigu pesan (M-ESK). */
    public const M_TANPA_INDUK = 'F-02 M-ESK tanpa induk';

    public const M_PENGIRIM = 'F-02 M-ESK pengirim tidak sah';

    public const M_KOSONG = 'F-02 M-ESK pesan kosong';

    /** Baris dibuang karena keputusan / induknya dibuang. */
    public const DIBUANG_KEPUTUSAN = 'F-02 dibuang (keputusan eskalasi)';

    public const DIBUANG_IKUT_INDUK = 'F-02 M-IKUT induk dibuang';

    private const BATCH = 2000;

    private function __construct()
    {
    }

    /**
     * praPindai `disiplin_ticket` ← `inbox`: id sumber → kode alasan tidak diimpor.
     *
     * @return array<int|string, string>
     */
    public static function tiket(BaseConnection $db, string $tabel, Keputusan $keputusan): array
    {
        return self::putus(self::ambiguTiket($db, $tabel, self::tabelPesan($db, $tabel)), self::KUNCI_TIKET, $keputusan, 'inbox');
    }

    /**
     * praPindai `disiplin_ticket_msg` ← `inbox_msg`: pesan ambigu (diputus per id) + pesan yang induknya tidak diimpor.
     *
     * @return array<int|string, string>
     */
    public static function pesan(BaseConnection $db, string $tabel, Keputusan $keputusan): array
    {
        $tanda  = self::pesanTanpaGagal($db, $tabel, (string) preg_replace('/_msg$/i', '', $tabel), $keputusan);
        $ikut   = array_filter($tanda, static fn (string $k): bool => $k === self::DIBUANG_IKUT_INDUK);
        $ambigu = array_diff_key($tanda, $ikut);

        // Pesan tanpa induk tidak mungkin ditulis (disiplin_ticket_msg.id_inbox NOT NULL + FK): hanya boleh "__buang".
        foreach ($ambigu as $id => $kode) {
            if ($kode === self::M_TANPA_INDUK && $keputusan->baris('disiplin_ticket_msg', 'f02', (string) $id)['nilai'] === self::IMPOR) {
                throw new ImporGagal('inbox_msg: pesan tanpa induk tidak bisa diimpor; keputusannya hanya boleh "__buang" (F-02).');
            }
        }

        return self::putus($ambigu, self::KUNCI_PESAN, $keputusan, 'inbox_msg') + $ikut;
    }

    /**
     * INFO sesudah impor (MAKE-069 T13): akun/NIP yang tidak ada di tujuan disalin apa adanya (tanpa FK, DQ-4/DQ-6).
     *
     * @return array<string, int>
     */
    public static function infoTiket(BaseConnection $tujuan): array
    {
        $t = $tujuan->prefixTable('disiplin_ticket');
        $p = $tujuan->prefixTable('pengguna');
        $g = $tujuan->prefixTable('pegawai');

        return array_filter([
            'F-02 INFO pengirim tanpa akun v2' => self::hitung($tujuan, "SELECT COUNT(*) AS n FROM {$t} t WHERE t.sender > 0 AND NOT EXISTS (SELECT 1 FROM {$p} p WHERE p.id_pengguna = t.sender)"),
            'F-02 INFO nip tanpa pegawai'      => self::hitung($tujuan, "SELECT COUNT(*) AS n FROM {$t} t WHERE NOT EXISTS (SELECT 1 FROM {$g} g WHERE g.nip = t.nip)"),
        ]);
    }

    /**
     * Manifest sortir untuk eskalasi (dry-run cutover): jumlah per kelas + daftar id ambigu dengan kodenya.
     * Isi = id dan kode saja (tanpa subjek/pesan/NIP/nama).
     *
     * @return array{tiket: array{total: int, kelas: array<string, int>}, pesan: array{total: int, kelas: array<string, int>}, eskalasi: array{tiket: array<int|string, string>, pesan: array<int|string, string>}}
     */
    public static function manifest(BaseConnection $db, string $tabelTiket, string $tabelPesan, Keputusan $keputusan): array
    {
        $ambiguTiket = self::ambiguTiket($db, $tabelTiket, $tabelPesan);
        $totalTiket  = self::hitung($db, 'SELECT COUNT(*) AS n FROM ' . $db->escapeIdentifiers($tabelTiket));
        $totalPesan  = self::hitung($db, 'SELECT COUNT(*) AS n FROM ' . $db->escapeIdentifiers($tabelPesan));

        $kelasTiket = ['T-OK' => $totalTiket - count($ambiguTiket)];
        $eskTiket   = [];

        foreach ($ambiguTiket as $id => $kode) {
            $putusan                                     = $keputusan->baris('disiplin_ticket', 'f02', (string) $id)['nilai'];
            $label                                       = $putusan === self::BUANG ? 'A-ESK dibuang' : ($putusan === self::IMPOR ? 'A-ESK diimpor' : 'A-ESK belum diputuskan');
            $kelasTiket[$label]                          = ($kelasTiket[$label] ?? 0) + 1;
            $putusan === null && $eskTiket[(string) $id] = $kode;
        }

        $kelasPesan = [];
        $eskPesan   = [];
        $ditandai   = 0;

        foreach (self::pesanTanpaGagal($db, $tabelPesan, $tabelTiket, $keputusan) as $id => $kode) {
            $ditandai++;
            $putusan            = $kode === self::DIBUANG_IKUT_INDUK ? self::BUANG : $keputusan->baris('disiplin_ticket_msg', 'f02', (string) $id)['nilai'];
            $label              = $kode === self::DIBUANG_IKUT_INDUK ? 'M-IKUT dibuang' : ($putusan === self::BUANG ? 'M-ESK dibuang' : ($putusan === self::IMPOR ? 'M-ESK diimpor' : 'M-ESK belum diputuskan'));
            $kelasPesan[$label] = ($kelasPesan[$label] ?? 0) + 1;

            if ($putusan === null && $kode !== self::DIBUANG_IKUT_INDUK) {
                $eskPesan[(string) $id] = $kode;
            }
        }

        $kelasPesan = ['M-OK' => $totalPesan - $ditandai] + $kelasPesan;

        return [
            'tiket'    => ['total' => $totalTiket, 'kelas' => $kelasTiket],
            'pesan'    => ['total' => $totalPesan, 'kelas' => $kelasPesan],
            'eskalasi' => ['tiket' => $eskTiket, 'pesan' => $eskPesan],
        ];
    }

    /**
     * Tiket berbentuk tidak sah: id → kode A-ESK (urutan pemeriksaan = urutan validasi legacy).
     *
     * @return array<string, string>
     */
    private static function ambiguTiket(BaseConnection $db, string $tabelTiket, string $tabelPesan): array
    {
        $berpesan = [];

        foreach (self::kueri($db, 'SELECT DISTINCT ' . $db->escapeIdentifiers('id_inbox') . ' AS i FROM ' . $db->escapeIdentifiers($tabelPesan) . ' WHERE ' . $db->escapeIdentifiers('id_inbox') . ' IS NOT NULL') as $r) {
            $berpesan[(string) $r['i']] = true;
        }

        $ambigu = [];

        foreach (self::baca($db, $tabelTiket, 'id_inbox', ['sender', 'subject', 'nip']) as $r) {
            $id = (string) $r['id_inbox'];

            if ((int) $r['sender'] <= 0) {
                $ambigu[$id] = self::A_PENGIRIM;
            } elseif (trim(stripslashes((string) $r['subject'])) === '') {
                $ambigu[$id] = self::A_SUBJEK;
            } elseif ((string) Transformasi::kode($r['nip'] === null ? null : (string) $r['nip']) === '') {
                $ambigu[$id] = self::A_NIP;
            } elseif (! isset($berpesan[$id])) {
                $ambigu[$id] = self::A_TANPA_PESAN;
            }
        }

        return $ambigu;
    }

    /**
     * Terapkan keputusan per id: "__buang" → dibuang; "impor" → diimpor; tanpa keputusan → gagal dengan jumlah per kode.
     *
     * @param array<string, string> $ambigu
     *
     * @return array<string, string>
     */
    private static function putus(array $ambigu, string $kunci, Keputusan $keputusan, string $label): array
    {
        [$tabel, $kolom] = explode('.', $kunci);
        $buang           = [];
        $belum           = [];

        foreach ($ambigu as $id => $kode) {
            $putusan = $keputusan->baris($tabel, $kolom, (string) $id);

            if (! $putusan['ada']) {
                $belum[$kode] = ($belum[$kode] ?? 0) + 1;
            } elseif ($putusan['nilai'] === self::BUANG) {
                $buang[(string) $id] = self::DIBUANG_KEPUTUSAN;
            } elseif ($putusan['nilai'] !== self::IMPOR) {
                throw new ImporGagal("{$label}: keputusan {$kunci} hanya boleh \"" . self::IMPOR . '" atau "' . self::BUANG . '" (F-02).');
            }
        }

        if ($belum !== []) {
            ksort($belum);
            $rincian = implode(', ', array_map(static fn (string $k, int $n): string => "{$k}: {$n}", array_keys($belum), $belum));

            throw new ImporGagal("{$label}: " . array_sum($belum) . " baris ambigu belum diputuskan ({$rincian}); buat daftar eskalasi dengan `php spark impor:sortir-inbox`, putuskan di berkas keputusan bagian baris {$kunci} (F-02, jangan ditebak).");
        }

        return $buang;
    }

    /**
     * Penanda pesan tanpa menerapkan "gagal bila belum diputuskan" (untuk manifest).
     *
     * @return array<string, string>
     */
    private static function pesanTanpaGagal(BaseConnection $db, string $tabelPesan, string $tabelTiket, Keputusan $keputusan): array
    {
        $indukBuang = [];

        foreach (array_keys(self::ambiguTiket($db, $tabelTiket, $tabelPesan)) as $id) {
            if ($keputusan->baris('disiplin_ticket', 'f02', (string) $id)['nilai'] === self::BUANG) {
                $indukBuang[(string) $id] = true;
            }
        }

        $indukAda = [];

        foreach (self::baca($db, $tabelTiket, 'id_inbox', []) as $r) {
            $indukAda[(string) $r['id_inbox']] = true;
        }

        $tanda = [];

        foreach (self::baca($db, $tabelPesan, 'id_inbox_msg', ['id_inbox', 'msg_sender', 'msg']) as $r) {
            $id    = (string) $r['id_inbox_msg'];
            $induk = $r['id_inbox'] === null ? null : (string) $r['id_inbox'];

            $kode = match (true) {
                $induk === null || ! isset($indukAda[$induk]) => self::M_TANPA_INDUK,
                isset($indukBuang[$induk])                    => self::DIBUANG_IKUT_INDUK,
                (int) $r['msg_sender'] <= 0                   => self::M_PENGIRIM,
                trim(stripslashes((string) $r['msg'])) === '' => self::M_KOSONG,
                default                                       => null,
            };

            if ($kode !== null) {
                $tanda[$id] = $kode;
            }
        }

        return $tanda;
    }

    private static function tabelPesan(BaseConnection $db, string $tabelTiket): string
    {
        $calon = $tabelTiket . '_msg';
        $rows  = self::kueri($db, 'SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND LOWER(TABLE_NAME) = LOWER(?)', [$db->getDatabase(), $calon]);

        if ($rows === []) {
            throw new ImporGagal('Tabel sumber inbox_msg tidak ada (F-02 butuh pesan untuk memeriksa tiket tanpa pesan).');
        }

        return (string) $rows[0]['n'];
    }

    private static function hitung(BaseConnection $db, string $sql): int
    {
        return (int) (self::kueri($db, $sql)[0]['n'] ?? 0);
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
            throw new ImporGagal('Galat DB saat sortir inbox (kode ' . (string) ($db->error()['code'] ?? '?') . ').');
        }

        /** @var list<array<string, mixed>> */
        return $hasil->getResultArray();
    }

    /**
     * Baca berurut PK per batch (keyset), hanya kolom aturan.
     *
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
