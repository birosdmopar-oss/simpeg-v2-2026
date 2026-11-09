<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Simapi;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * MAKE-060 A5b (F6-06) — penulis `simapi_ch` pengganti trigger DB legacy (aturan per tabel: {@see AturanTrigger}). Penulis data
 * (layanan pemilik tabel sumber) memanggil:
 *  1. {@see catat()} SEBELUM commit, dengan baris lama/baru yang sudah dibacanya, pada koneksi & transaksi yang sama — aturan
 *     dievaluasi saat itu (lookup NIP keluarga ikut membaca di transaksi yang sama) lalu hasilnya DIANTREKAN per koneksi;
 *  2. {@see setelahCommit()} sesudah commit miliknya — antrean ditulis hanya bila transaksi TERLUAR sudah selesai
 *     (`transDepth === 0`); di dalam transaksi pemanggil lain, penulisan menunggu `setelahCommit()` pemilik transaksi terluar;
 *  3. {@see batal()} pada rollback — antrean dibuang (tidak ada baris log untuk perubahan yang batal).
 * Dipanggil di luar transaksi (`transDepth === 0`), {@see catat()} langsung menulis.
 *
 * Aditif murni: galat apa pun di sini hanya dicatat ke log (kelas + kode, tanpa NIP), tidak pernah dilempar ke operasi bisnis.
 * Baris tanpa NIP tidak ditulis (DBV-031 N2/§3.3; akun tanpa NIP CR-093). `simapi_jd` belum di-seed → FK menolak → dilog.
 * Impor cutover (`Libraries/Impor`) sengaja TIDAK memanggil kelas ini (antrean mulai kosong, keputusan Horii 09-11 opsi a).
 */
final class PencatatPerubahan
{
    /** @var array<int, list<array<string, mixed>>> antrean per koneksi (spl_object_id) */
    private static array $antrean = [];

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

            $baris = array_values(array_filter($baris, static fn (array $b): bool => Nilai::teks($b['nip']) !== ''));

            if ($baris === []) {
                return;
            }

            if ($db->transDepth === 0) {
                self::tulis($db, $baris);

                return;
            }

            self::$antrean[spl_object_id($db)] = array_merge(self::$antrean[spl_object_id($db)] ?? [], $baris);
        } catch (Throwable $e) {
            self::log($e);
        }
    }

    /**
     * Panggil sesudah commit milik penulis. Menulis antrean bila tidak ada lagi transaksi terbuka di koneksi itu.
     */
    public static function setelahCommit(BaseConnection $db): void
    {
        if ($db->transDepth !== 0) {
            return;
        }

        $baris = self::$antrean[spl_object_id($db)] ?? [];
        unset(self::$antrean[spl_object_id($db)]);

        if ($baris !== []) {
            self::tulis($db, $baris);
        }
    }

    /**
     * Panggil pada rollback: perubahan batal → antrean koneksi dibuang.
     */
    public static function batal(BaseConnection $db): void
    {
        unset(self::$antrean[spl_object_id($db)]);
    }

    /**
     * Jumlah baris yang masih menunggu (untuk test).
     */
    public static function tertunda(BaseConnection $db): int
    {
        return count(self::$antrean[spl_object_id($db)] ?? []);
    }

    /**
     * @param list<array<string, mixed>> $baris
     */
    private static function tulis(BaseConnection $db, array $baris): void
    {
        try {
            $sekarang = gmdate('Y-m-d H:i:s');

            foreach ($baris as $b) {
                if ($db->table('simapi_ch')->insert($b + ['action_at' => $sekarang]) === false) {
                    self::log(null, (int) ($db->error()['code'] ?? 0));
                }
            }
        } catch (Throwable $e) {
            self::log($e);
        }
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
