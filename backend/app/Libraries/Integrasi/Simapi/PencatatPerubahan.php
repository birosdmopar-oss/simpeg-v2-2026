<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Simapi;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * MAKE-060 A5b (F6-06) — penulis `simapi_ch` pengganti trigger DB legacy (aturan per tabel: {@see AturanTrigger}). Penulis data
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
        try {
            $baris = AturanTrigger::baris($tabel, $peristiwa, $lama, $baru, [
                'nip_keluarga' => static fn (mixed $id): ?string => self::nipKeluarga($db, $id),
                'url_foto'     => static fn (string $nip, string $path): string => (new PenyajiPegawai($db))->urlFoto($nip, $path),
            ]);
        } catch (Throwable $e) {
            self::log($e);

            return;
        }

        $baris = array_values(array_filter($baris, static fn (array $b): bool => Nilai::teks($b['nip']) !== ''));

        if ($baris === []) {
            return;
        }

        if ($db->transDepth === 0) {
            self::tulisLangsung($db, $baris);

            return;
        }

        self::tulisDalamTransaksi($db, $baris);
    }

    /**
     * @param list<array<string, mixed>> $baris
     */
    private static function tulisLangsung(BaseConnection $db, array $baris): void
    {
        try {
            foreach ($baris as $b) {
                if (self::insert($db, $b) === false) {
                    self::log(null, (int) ($db->error()['code'] ?? 0));
                }
            }
        } catch (Throwable $e) {
            self::log($e);
        }
    }

    /**
     * @param list<array<string, mixed>> $baris
     */
    private static function tulisDalamTransaksi(BaseConnection $db, array $baris): void
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
            foreach ($baris as $b) {
                if (self::insert($db, $b) === false) {
                    throw new \RuntimeException('Insert simapi_ch gagal.', (int) ($db->error()['code'] ?? 0));
                }
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
    private static function insert(BaseConnection $db, array $b): bool
    {
        return (bool) $db->table('simapi_ch')->insert($b + ['action_at' => gmdate('Y-m-d H:i:s')]);
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
