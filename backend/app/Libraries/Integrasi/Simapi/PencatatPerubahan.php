<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Simapi;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * MAKE-060 A5b (F6-06) + MAKE-076 (Q6-41) — penulis log `simapi_ch` dan `simapi_update` pengganti trigger DB legacy (aturan per
 * tabel: {@see AturanTrigger}, {@see AturanTriggerUpdate}). Penulis data
 * (layanan pemilik tabel sumber) memanggil {@see catat()} tepat sesudah tulisan bisnisnya, dengan baris lama/baru, pada koneksi
 * yang sama.
 *
 * [V2, keputusan reviewer 09-11-2026 opsi B] Setara trigger: di dalam transaksi pemilik (`transDepth > 0`) baris log ditulis
 * DI DALAM transaksi itu, dibungkus SAVEPOINT unik — commit pemilik → baris ada; rollback lewat jalur apa pun → baris hilang
 * bersama data bisnis. Insert log gagal → `ROLLBACK TO SAVEPOINT`, galat hanya dilog (kelas + kode, tanpa NIP), dan status
 * transaksi CI4 dipulihkan HANYA bila sebelum savepoint masih baik (kegagalan bisnis sebelumnya tidak pernah ditutupi).
 * Di luar transaksi: ditulis langsung sesudah tulisan bisnis, galat hanya dilog.
 *
 * Baris tanpa NIP tidak ditulis (DBV-031 N2/§3.3; akun tanpa NIP CR-093). Impor cutover (`Libraries/Impor`) sengaja TIDAK
 * memanggil kelas ini (antrean mulai kosong, keputusan Horii 09-11 opsi a).
 */
final class PencatatPerubahan
{
    private static int $urutan = 0;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>|null $lama baris SEBELUM perubahan (UPDATE/DELETE)
     * @param array<string, mixed>|null $baru baris SESUDAH perubahan (INSERT/UPDATE)
     */
    public static function catat(BaseConnection $db, string $tabel, string $peristiwa, ?array $lama, ?array $baru): void
    {
        if ($db->transDepth === 0) {
            try {
                self::tulisLangsung($db, self::susun($db, $tabel, $peristiwa, $lama, $baru));
            } catch (Throwable $e) {
                self::log($e);
            }

            return;
        }

        // Review P3: lookup (NIP keluarga, foto) DAN insert di dalam satu savepoint — query gagal apa pun di sini (yang di CI4
        // menurunkan status transaksi tanpa exception) dipulihkan, sehingga transaksi bisnis tidak ikut gagal.
        self::dalamSavepoint($db, static function () use ($db, $tabel, $peristiwa, $lama, $baru): void {
            foreach (self::susun($db, $tabel, $peristiwa, $lama, $baru) as [$log, $b]) {
                if (self::insert($db, $log, $b) === false) {
                    throw new \RuntimeException('Insert log SIMAPI gagal.', (int) ($db->error()['code'] ?? 0));
                }
            }
        });
    }

    /**
     * @param array<string, mixed>|null $lama
     * @param array<string, mixed>|null $baru
     *
     * @return list<array{0: string, 1: array<string, mixed>}> [tabel log, baris]
     */
    private static function susun(BaseConnection $db, string $tabel, string $peristiwa, ?array $lama, ?array $baru): array
    {
        $urlFoto = static fn (string $nip, string $path): string => (new PenyajiPegawai($db))->urlFoto($nip, $path);
        $hasil   = [];

        foreach (AturanTrigger::baris($tabel, $peristiwa, $lama, $baru, ['nip_keluarga' => static fn (mixed $id): ?string => self::nipKeluarga($db, $id), 'url_foto' => $urlFoto]) as $b) {
            $hasil[] = ['simapi_ch', $b];
        }

        foreach (AturanTriggerUpdate::baris($tabel, $peristiwa, $lama, $baru, ['url_foto' => $urlFoto, 'hash_akun' => static fn (string $nip): ?string => self::hashAkun($db, $nip)]) as $b) {
            $hasil[] = ['simapi_update', $b];
        }

        return array_values(array_filter($hasil, static fn (array $x): bool => Nilai::teks($x[1]['nip']) !== ''));
    }

    /**
     * Baris sumber saat ini (untuk `lama` sebelum tulis, atau `baru` sesudahnya) pada koneksi/transaksi yang sama; null = tidak ada
     * atau galat (dilog). Prefix-safe lewat query builder.
     *
     * @return array<string, mixed>|null
     */
    public static function baca(BaseConnection $db, string $tabel, string $kolom, mixed $kunci): ?array
    {
        // Review P3: SELECT gagal di dalam transaksi menurunkan status CI4 → dipulihkan bila sebelumnya baik.
        $statusSebelum = $db->transStatus();

        try {
            $hasil = $db->table($tabel)->where($kolom, $kunci)->limit(1)->get();

            if ($hasil === false) {
                throw new \RuntimeException('Baca sumber log SIMAPI gagal.', (int) ($db->error()['code'] ?? 0));
            }

            $r = $hasil->getRowArray();

            return is_array($r) ? $r : null;
        } catch (Throwable $e) {
            if ($db->transDepth > 0 && $statusSebelum) {
                $db->resetTransStatus();
            }

            self::log($e);

            return null;
        }
    }

    /**
     * Pintasan penulis UPDATE: baca baris SESUDAH tulisan bisnis lalu {@see catat()} dengan `$lama` yang dibaca sebelumnya.
     *
     * @param array<string, mixed>|null $lama
     */
    public static function sesudahUbah(BaseConnection $db, string $tabel, string $kolom, mixed $kunci, ?array $lama): void
    {
        if ($lama === null) {
            return;
        }

        self::catat($db, $tabel, AturanTrigger::UPDATE, $lama, self::baca($db, $tabel, $kolom, $kunci));
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $baris
     */
    private static function tulisLangsung(BaseConnection $db, array $baris): void
    {
        try {
            foreach ($baris as [$log, $b]) {
                if (self::insert($db, $log, $b) === false) {
                    self::log(null, (int) ($db->error()['code'] ?? 0));
                }
            }
        } catch (Throwable $e) {
            self::log($e);
        }
    }

    /**
     * Jalankan `$kerja` di dalam SAVEPOINT unik; galat (exception atau status CI4 turun) → ROLLBACK TO SAVEPOINT + log, status
     * transaksi dipulihkan hanya bila sebelum savepoint masih baik.
     *
     * @param \Closure(): void $kerja
     */
    private static function dalamSavepoint(BaseConnection $db, \Closure $kerja): void
    {
        $statusSebelum = $db->transStatus();
        $titik         = 'sp_simapi_' . (++self::$urutan);

        try {
            if ($db->simpleQuery('SAVEPOINT ' . $titik) === false) {
                self::log(null, (int) ($db->error()['code'] ?? 0));

                return;
            }
        } catch (Throwable $e) {
            self::log($e);

            return;
        }

        try {
            $kerja();

            if ($statusSebelum && ! $db->transStatus()) {
                throw new \RuntimeException('Query log SIMAPI gagal (status transaksi turun).');
            }

            $db->simpleQuery('RELEASE SAVEPOINT ' . $titik);
        } catch (Throwable $e) {
            try {
                $db->simpleQuery('ROLLBACK TO SAVEPOINT ' . $titik);
            } catch (Throwable) {
                // Galat asli lebih penting.
            }

            if ($statusSebelum) {
                $db->resetTransStatus(); // hanya memulihkan kegagalan log; kegagalan bisnis sebelumnya tetap
            }

            self::log($e);
        }
    }

    /**
     * @param array<string, mixed> $b
     */
    private static function insert(BaseConnection $db, string $log, array $b): bool
    {
        $kini = gmdate('Y-m-d H:i:s');

        return (bool) $db->table($log)->insert($log === 'simapi_update' ? $b + ['last_update' => $kini, 'created_at' => $kini] : $b + ['action_at' => $kini]);
    }

    /**
     * Hash password berlaku akun ber-NIP itu (`pegawai` INSERT, [V2] pengganti `MD5(nip)` legacy); null bila tidak ada akun.
     */
    private static function hashAkun(BaseConnection $db, #[\SensitiveParameter] string $nip): ?string
    {
        $r = $db->table('pengguna')->select('password, password_legacy')->where('nip', $nip)->limit(1)->get()->getRowArray();

        return $r === null ? null : AturanTriggerUpdate::hashBerlaku($r);
    }

    private static function nipKeluarga(BaseConnection $db, mixed $idKeluarga): ?string
    {
        if ($idKeluarga === null || (string) $idKeluarga === '') {
            return null;
        }

        $r = $db->table('riwayat_keluarga')->select('nip')->where('id_riwayat_keluarga', $idKeluarga)->limit(1)->get()->getRowArray();

        return $r === null ? null : Nilai::mentah($r['nip']);
    }

    private static function log(?Throwable $e, int $kode = 0): void
    {
        log_message('error', 'SIMAPI pencatat perubahan gagal: ' . ($e === null ? 'DB' : (new \ReflectionClass($e))->getShortName()) . ' kode ' . ($e?->getCode() ?? $kode) . '.');
    }
}
