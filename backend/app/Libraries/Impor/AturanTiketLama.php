<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;

/**
 * F-02 Sortir inbox legacy (Fase 7 Jalur C, MAKE-086; PRD Fase 7 v2.0 §5.2, Q7-28) untuk tier 13 "tiket lama".
 *
 * Kriteria diturunkan dari KODE legacy, bukan dari data: satu-satunya penulis `inbox`/`inbox_msg` legacy adalah Halo
 * Simpeg (`libraries/hr/L_support.php:997,1006,1169,1341`; tidak ada penulis lain di controllers/models/libraries/core,
 * dicek ulang WS-2), jadi SEMUA baris = tiket → `disiplin_ticket(_msg)`; `inbox` v2 (notifikasi) tidak ditulis
 * (DBV-022 §3.4). Bentuk tiket sah mengikuti `insert_inbox` (`LS:937-1095`, `set_param_inbox` `LS:1528-1550`, validasi
 * `LS:1505-1527`) dan `set_param_conversation` (`LS:1567-1597`). Baris yang tidak berbentuk tiket = AMBIGU: tidak
 * ditebak, dieskalasi per id ke Horii/DBV lewat berkas keputusan bagian `baris`:
 *
 *   "baris": {"disiplin_ticket.f02": {"<id_inbox>": "impor" | "__buang"},
 *             "disiplin_ticket_msg.f02": {"<id_inbox_msg>": "impor" | "__buang"}}
 *
 * Ambigu tanpa keputusan → {@see ImporGagal} dengan jumlah per kode (juga saat dry-run): tidak ada yang ditulis.
 * Tiket yang SEMUA pesannya diputus `__buang` ikut dinilai "tanpa pesan" (review WS-2 N2). Satu instance dipakai oleh
 * praPindai dan pascaImpor satu tier, sehingga jumlah keputusan tak terpakai ikut laporan impor (N1).
 * Daftar id eskalasi + jumlah per kelas/nilai di luar domain dibuat {@see self::manifest()} (`impor:sortir-inbox`).
 */
final class AturanTiketLama
{
    public const KUNCI_TIKET = 'disiplin_ticket.f02';

    public const KUNCI_PESAN = 'disiplin_ticket_msg.f02';

    public const BUANG = '__buang';

    public const IMPOR = 'impor';

    /**
     * Domain CHECK v2 (DBV-022 §2.10/§2.11); nilai lain = kelas T-NORM/M-NORM → bagian `status` berkas keputusan.
     */
    public const DOMAIN_TIKET = ['allow_reply' => [0, 1], 'is_read' => [1, 2], 'is_read_off' => [1, 2], 'notify_pic' => [0, 1, 2]];

    public const STATUS_TIKET = [0, 1];

    public const DOMAIN_PESAN = ['show_notif' => [0, 1], 'is_read' => [0, 1]];

    public const A_PENGIRIM = 'F-02 A-ESK pengirim tidak sah';

    public const A_SUBJEK = 'F-02 A-ESK subjek kosong';

    public const A_NIP = 'F-02 A-ESK nip kosong';

    public const A_TANPA_PESAN = 'F-02 A-ESK tanpa pesan';

    public const M_TANPA_INDUK = 'F-02 M-ESK tanpa induk';

    public const M_PENGIRIM = 'F-02 M-ESK pengirim tidak sah';

    public const M_KOSONG = 'F-02 M-ESK pesan kosong';

    public const DIBUANG_KEPUTUSAN = 'F-02 dibuang (keputusan eskalasi)';

    public const DIBUANG_IKUT_INDUK = 'F-02 M-IKUT induk dibuang';

    public const INFO_TAK_TERPAKAI_TIKET = 'F-02 INFO keputusan tiket tak terpakai';

    public const INFO_TAK_TERPAKAI_PESAN = 'F-02 INFO keputusan pesan tak terpakai';

    public const INFO_PENGIRIM = 'F-02 INFO pengirim tanpa akun v2';

    public const INFO_NIP = 'F-02 INFO nip tanpa pegawai';

    public const INFO_KATEGORI_NULL = 'F-02 INFO kategori NULL';

    private const BATCH = 2000;

    /**
     * Keputusan tak terpakai hasil praPindai terakhir (dibaca pascaImpor tier yang sama).
     *
     * @var array{tiket: int, pesan: int}
     */
    private array $takTerpakai = ['tiket' => 0, 'pesan' => 0];

    /**
     * praPindai `disiplin_ticket` ← `inbox`: id sumber → kode alasan tidak diimpor.
     *
     * @return array<int|string, string>
     */
    public function tiket(BaseConnection $db, string $tabel, Keputusan $keputusan): array
    {
        $k                          = $this->klasifikasi($db, $tabel, self::tabelPesan($db, $tabel), $keputusan);
        $this->takTerpakai['tiket'] = count($k['takTerpakaiTiket']);

        return self::putus($k['ambiguTiket'], self::KUNCI_TIKET, $keputusan, 'inbox');
    }

    /**
     * praPindai `disiplin_ticket_msg` ← `inbox_msg`: pesan ambigu (diputus per id) + pesan yang induknya dibuang.
     *
     * @return array<int|string, string>
     */
    public function pesan(BaseConnection $db, string $tabel, Keputusan $keputusan): array
    {
        $k                          = $this->klasifikasi($db, (string) preg_replace('/_msg$/i', '', $tabel), $tabel, $keputusan);
        $this->takTerpakai['pesan'] = count($k['takTerpakaiPesan']);

        // Pesan tanpa induk tidak mungkin ditulis (disiplin_ticket_msg.id_inbox NOT NULL + FK): hanya boleh "__buang".
        foreach ($k['ambiguPesan'] as $id => $kode) {
            if ($kode === self::M_TANPA_INDUK && $keputusan->baris('disiplin_ticket_msg', 'f02', (string) $id)['nilai'] === self::IMPOR) {
                throw new ImporGagal('inbox_msg: pesan tanpa induk tidak bisa diimpor; keputusannya hanya boleh "__buang" (F-02).');
            }
        }

        return self::putus($k['ambiguPesan'], self::KUNCI_PESAN, $keputusan, 'inbox_msg') + $k['ikut'];
    }

    /**
     * pascaImpor `disiplin_ticket`: INFO akun/NIP yang tidak ada di tujuan (disalin apa adanya, tanpa FK, DQ-4/DQ-6),
     * tiket berkategori NULL (N6), dan keputusan tiket tak terpakai (N1).
     *
     * @return array<string, int>
     */
    public function infoTiket(BaseConnection $tujuan): array
    {
        $t = $tujuan->prefixTable('disiplin_ticket');
        $p = $tujuan->prefixTable('pengguna');
        $g = $tujuan->prefixTable('pegawai');

        return array_filter([
            self::INFO_PENGIRIM           => self::hitung($tujuan, "SELECT COUNT(*) AS n FROM {$t} t WHERE t.sender > 0 AND NOT EXISTS (SELECT 1 FROM {$p} p WHERE p.id_pengguna = t.sender)"),
            self::INFO_NIP                => self::hitung($tujuan, "SELECT COUNT(*) AS n FROM {$t} t WHERE NOT EXISTS (SELECT 1 FROM {$g} g WHERE g.nip = t.nip)"),
            self::INFO_KATEGORI_NULL      => self::hitung($tujuan, "SELECT COUNT(*) AS n FROM {$t} t WHERE t.id_question_category IS NULL"),
            self::INFO_TAK_TERPAKAI_TIKET => $this->takTerpakai['tiket'],
        ]);
    }

    /**
     * pascaImpor `disiplin_ticket_msg`: keputusan pesan tak terpakai (N1).
     *
     * @return array<string, int>
     */
    public function infoPesan(BaseConnection $tujuan): array
    {
        return array_filter([self::INFO_TAK_TERPAKAI_PESAN => $this->takTerpakai['pesan']]);
    }

    /**
     * Manifest sortir untuk eskalasi (dry-run cutover): jumlah per kelas, nilai di luar domain per kolom (N3), keputusan
     * tak terpakai (N1), dan daftar id ambigu yang belum diputuskan. Isi = id, kode, dan nilai flag saja.
     *
     * @return array{tiket: array{total: int, kelas: array<string, int>}, pesan: array{total: int, kelas: array<string, int>}, di_luar_domain: array<string, array<string, int>>, keputusan_tak_terpakai: array{tiket: int, pesan: int}, eskalasi: array{tiket: array<int|string, string>, pesan: array<int|string, string>}}
     */
    public function manifest(BaseConnection $db, string $tabelTiket, string $tabelPesan, Keputusan $keputusan): array
    {
        $k = $this->klasifikasi($db, $tabelTiket, $tabelPesan, $keputusan);

        $kelasTiket = ['T-OK' => $k['totalTiket'] - count($k['ambiguTiket']) - $k['normTiket'], 'T-NORM' => $k['normTiket']];
        $eskTiket   = [];

        foreach ($k['ambiguTiket'] as $id => $kode) {
            $putusan = $keputusan->baris('disiplin_ticket', 'f02', (string) $id)['nilai'];
            $label   = match ($putusan) {
                self::BUANG => 'A-ESK dibuang',
                self::IMPOR => 'A-ESK diimpor',
                default     => 'A-ESK belum diputuskan',
            };
            $kelasTiket[$label] = ($kelasTiket[$label] ?? 0) + 1;

            if ($putusan === null) {
                $eskTiket[(string) $id] = $kode;
            }
        }

        $kelasPesan = ['M-OK' => $k['totalPesan'] - count($k['ambiguPesan']) - count($k['ikut']) - $k['normPesan'], 'M-NORM' => $k['normPesan']];
        $kelasPesan += $k['ikut'] === [] ? [] : ['M-IKUT dibuang' => count($k['ikut'])];
        $eskPesan = [];

        foreach ($k['ambiguPesan'] as $id => $kode) {
            $putusan = $keputusan->baris('disiplin_ticket_msg', 'f02', (string) $id)['nilai'];
            $label   = match ($putusan) {
                self::BUANG => 'M-ESK dibuang',
                self::IMPOR => 'M-ESK diimpor',
                default     => 'M-ESK belum diputuskan',
            };
            $kelasPesan[$label] = ($kelasPesan[$label] ?? 0) + 1;

            if ($putusan === null) {
                $eskPesan[(string) $id] = $kode;
            }
        }

        return [
            'tiket'                  => ['total' => $k['totalTiket'], 'kelas' => array_filter($kelasTiket)],
            'pesan'                  => ['total' => $k['totalPesan'], 'kelas' => array_filter($kelasPesan)],
            'di_luar_domain'         => $k['diLuarDomain'],
            'keputusan_tak_terpakai' => ['tiket' => count($k['takTerpakaiTiket']), 'pesan' => count($k['takTerpakaiPesan'])],
            'eskalasi'               => ['tiket' => $eskTiket, 'pesan' => $eskPesan],
        ];
    }

    /**
     * Klasifikasi seluruh baris tiket + pesan sumber (satu lintasan per tabel, berurut PK).
     *
     * @return array{ambiguTiket: array<string, string>, ambiguPesan: array<string, string>, ikut: array<string, string>, normTiket: int, normPesan: int, diLuarDomain: array<string, array<string, int>>, totalTiket: int, totalPesan: int, takTerpakaiTiket: list<string>, takTerpakaiPesan: list<string>}
     */
    private function klasifikasi(BaseConnection $db, string $tabelTiket, string $tabelPesan, Keputusan $keputusan): array
    {
        $diLuar = [];
        $normId = ['tiket' => [], 'pesan' => []];

        // 1. Tiket: bentuk dasar + nilai di luar domain.
        $tiket  = [];
        $ambigu = [];

        foreach (self::baca($db, $tabelTiket, 'id_inbox', ['sender', 'subject', 'nip', 'status', ...array_keys(self::DOMAIN_TIKET)]) as $r) {
            $id         = (string) $r['id_inbox'];
            $tiket[$id] = true;

            if ((int) $r['sender'] <= 0) {
                $ambigu[$id] = self::A_PENGIRIM;
            } elseif (trim(stripslashes((string) $r['subject'])) === '') {
                $ambigu[$id] = self::A_SUBJEK;
            } elseif ((string) Transformasi::kode($r['nip'] === null ? null : (string) $r['nip']) === '') {
                $ambigu[$id] = self::A_NIP;
            }

            foreach (['status' => self::STATUS_TIKET] + self::DOMAIN_TIKET as $kolom => $domain) {
                if (self::diLuarDomain($r[$kolom], $domain)) {
                    $kunci                                      = Transformasi::kunciStatus($r[$kolom]);
                    $diLuar["disiplin_ticket.{$kolom}"][$kunci] = ($diLuar["disiplin_ticket.{$kolom}"][$kunci] ?? 0) + 1;
                    $normId['tiket'][$id]                       = true;
                }
            }
        }

        // 2. Pesan: induk, pengirim, isi, nilai di luar domain. Pesan yang diputus "__buang" tidak menghitung induknya
        //    sebagai "berpesan" (N2).
        $pesan    = [];
        $berpesan = [];

        foreach (self::baca($db, $tabelPesan, 'id_inbox_msg', ['id_inbox', 'msg_sender', 'msg', ...array_keys(self::DOMAIN_PESAN)]) as $r) {
            $id    = (string) $r['id_inbox_msg'];
            $induk = $r['id_inbox'] === null ? null : (string) $r['id_inbox'];
            $kode  = match (true) {
                $induk === null || ! isset($tiket[$induk])    => self::M_TANPA_INDUK,
                (int) $r['msg_sender'] <= 0                   => self::M_PENGIRIM,
                trim(stripslashes((string) $r['msg'])) === '' => self::M_KOSONG,
                default                                       => null,
            };
            $pesan[$id] = ['induk' => $induk, 'kode' => $kode];

            if ($kode !== self::M_TANPA_INDUK && $keputusan->baris('disiplin_ticket_msg', 'f02', $id)['nilai'] !== self::BUANG) {
                $berpesan[(string) $induk] = true;
            }

            foreach (self::DOMAIN_PESAN as $kolom => $domain) {
                if (self::diLuarDomain($r[$kolom], $domain)) {
                    $kunci                                          = Transformasi::kunciStatus($r[$kolom]);
                    $diLuar["disiplin_ticket_msg.{$kolom}"][$kunci] = ($diLuar["disiplin_ticket_msg.{$kolom}"][$kunci] ?? 0) + 1;
                    $normId['pesan'][$id]                           = true;
                }
            }
        }

        // 3. Tiket tanpa pesan yang akan diimpor (termasuk bila semua pesannya dibuang) = ambigu.
        foreach (array_keys($tiket) as $id) {
            if (! isset($ambigu[(string) $id]) && ! isset($berpesan[(string) $id])) {
                $ambigu[(string) $id] = self::A_TANPA_PESAN;
            }
        }

        // 4. Pesan yang induknya diputus "__buang" ikut dibuang; sisanya yang berkode = ambigu pesan.
        $ikut        = [];
        $ambiguPesan = [];

        foreach ($pesan as $id => $p) {
            $indukBuang = $p['induk'] !== null && isset($ambigu[$p['induk']]) && $keputusan->baris('disiplin_ticket', 'f02', $p['induk'])['nilai'] === self::BUANG;

            if ($p['kode'] !== self::M_TANPA_INDUK && $indukBuang) {
                $ikut[(string) $id] = self::DIBUANG_IKUT_INDUK;
            } elseif ($p['kode'] !== null) {
                $ambiguPesan[(string) $id] = $p['kode'];
            }
        }

        ksort($diLuar);
        $diLuar = array_map(static function (array $nilai): array {
            ksort($nilai);

            return $nilai;
        }, $diLuar);

        return [
            'ambiguTiket'      => $ambigu,
            'ambiguPesan'      => $ambiguPesan,
            'ikut'             => $ikut,
            'normTiket'        => count(array_diff_key($normId['tiket'], $ambigu)),
            'normPesan'        => count(array_diff_key($normId['pesan'], $ambiguPesan, $ikut)),
            'diLuarDomain'     => $diLuar,
            'totalTiket'       => count($tiket),
            'totalPesan'       => count($pesan),
            'takTerpakaiTiket' => array_values(array_filter($keputusan->barisDiputus('disiplin_ticket', 'f02'), static fn (string $id): bool => ! isset($ambigu[$id]))),
            'takTerpakaiPesan' => array_values(array_filter($keputusan->barisDiputus('disiplin_ticket_msg', 'f02'), static fn (string $id): bool => ! isset($ambiguPesan[$id]))),
        ];
    }

    /**
     * @param list<int> $domain
     */
    private static function diLuarDomain(mixed $nilai, array $domain): bool
    {
        return ! in_array(Transformasi::kunciStatus($nilai), array_map('strval', $domain), true);
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

    private static function tabelPesan(BaseConnection $db, string $tabelTiket): string
    {
        $rows = self::kueri($db, 'SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND LOWER(TABLE_NAME) = LOWER(?)', [$db->getDatabase(), $tabelTiket . '_msg']);

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
