<?php

declare(strict_types=1);

namespace App\Libraries\Bkn\Ekinerja;

use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Throwable;

/**
 * Penulis hasil pemetaan e-Kinerja ke DB (PRD Fase 6 §7.8, F6-15, AC-10.1/AC-10.2) — dipanggil SETELAH panggilan API
 * selesai (BP-4: tidak ada panggilan eksternal di dalam transaksi).
 *
 * - Periode [K `EB:9-55`]: hanya INSERT id yang belum ada (periode lama tidak diperbarui).
 * - SKP periodik [K `EB:239-352`]: kunci `id_pns + periode_id + skp_id` (dinormalisasi trim + huruf kecil, setara
 *   collation `utf8mb4_unicode_ci` UNIQUE DBV-027); kunci baru → INSERT; kunci ada dan `hasil_akhir` baru terisi &
 *   berbeda → UPDATE seluruh kolom (+ `status = 1`); selain itu tidak berubah. Idempoten: jalankan ulang dengan respons
 *   sama = tanpa perubahan.
 * - Transaksi pola proyek (`transBegin` → cek hasil query & `transStatus()` → commit/rollback → `resetTransStatus()`, tanpa
 *   `transException`): query gagal (mis. 1062 karena tarik bersamaan, nilai ditolak mode strict) TIDAK lagi di-rollback
 *   diam-diam — seluruh tulisan dibatalkan, status transaksi koneksi dipulihkan, lalu {@see DatabaseException} dilempar
 *   agar pemanggil mencatat `gagal` dan job di-retry (temuan review B3 #1).
 * - AC-10.2: baris yang NIP-nya ada di `pegawai` tetapi `id_pns_siasn` kosong dihitung "tidak terpasang"; NIP yang tidak
 *   ada di `pegawai` dihitung "tidak dikenal". Keduanya tetap disimpan seperti legacy (tabel tanpa FK `nip`) — hanya
 *   jumlah yang dilaporkan, tanpa NIP.
 */
final class SinkronEkinerja
{
    /**
     * @param (Closure(): mixed)|null $kaitSebelumTulis seam test tarik bersamaan: dipanggil setelah baca, tepat sebelum
     *                                                transaksi tulis
     */
    public function __construct(private readonly BaseConnection $db, private readonly ?Closure $kaitSebelumTulis = null)
    {
    }

    /**
     * @param list<array<string, int|string|null>> $baris hasil {@see PemetaEkinerja::periode()}
     *
     * @return array{baru: int, sudah_ada: int}
     *
     * @throws DatabaseException
     */
    public function simpanPeriode(array $baris): array
    {
        if ($baris === []) {
            return ['baru' => 0, 'sudah_ada' => 0];
        }

        $baru = $this->periodeBaru($baris);

        if ($baru !== []) {
            $sekarang = gmdate('Y-m-d H:i:s');
            $this->dalamTransaksi('periode e-Kinerja', fn (): bool => $this->db->table('bkn_periode_ekinper')
                ->insertBatch(array_map(static fn (array $b): array => $b + ['created_at' => $sekarang], $baru)) !== false);
        }

        return ['baru' => count($baru), 'sudah_ada' => count($baris) - count($baru)];
    }

    /**
     * Perkiraan dry-run tanpa menulis.
     *
     * @param list<array<string, int|string|null>> $baris
     *
     * @return array{baru: int, sudah_ada: int}
     */
    public function perkiraanPeriode(array $baris): array
    {
        $baru = $baris === [] ? 0 : count($this->periodeBaru($baris));

        return ['baru' => $baru, 'sudah_ada' => count($baris) - $baru];
    }

    /**
     * @param list<array<string, string|null>> $baris hasil {@see PemetaEkinerja::skpPeriodik()}
     *
     * @return array{baru: int, diperbarui: int, tetap: int, tidak_terpasang: int, tidak_dikenal: int}
     *
     * @throws DatabaseException
     */
    public function simpanSkpPeriodik(array $baris): array
    {
        $rencana = $this->rencanaSkp($baris);

        if ($rencana['sisip'] !== [] || $rencana['ubah'] !== []) {
            $sekarang = gmdate('Y-m-d H:i:s');

            $this->dalamTransaksi('SKP periodik e-Kinerja', function () use ($rencana, $sekarang): bool {
                foreach ($rencana['ubah'] as $id => $b) {
                    if ($this->db->table('riwayat_skp_periodik')->where('id_riwayat_skp_periodik', $id)->update($b) === false) {
                        return false;
                    }
                }

                return $rencana['sisip'] === []
                    || $this->db->table('riwayat_skp_periodik')->insertBatch(array_map(static fn (array $b): array => $b + ['created_at' => $sekarang], $rencana['sisip'])) !== false;
            });
        }

        return [...$rencana['hasil'], ...$this->pemasangan(array_values(array_unique(array_column($baris, 'nip'))))];
    }

    /**
     * Perkiraan dry-run tanpa menulis (baru / diperbarui / tetap dari data yang ada sekarang).
     *
     * @param list<array<string, string|null>> $baris
     *
     * @return array{baru: int, diperbarui: int, tetap: int, tidak_terpasang: int, tidak_dikenal: int}
     */
    public function perkiraanSkpPeriodik(array $baris): array
    {
        return [...$this->rencanaSkp($baris)['hasil'], ...$this->pemasangan(array_values(array_unique(array_column($baris, 'nip'))))];
    }

    /**
     * @param list<array<string, string|null>> $baris
     *
     * @return array{sisip: list<array<string, string|null>>, ubah: array<int|string, array<string, string|null>>, hasil: array{baru: int, diperbarui: int, tetap: int, tidak_terpasang: int, tidak_dikenal: int}}
     */
    private function rencanaSkp(array $baris): array
    {
        $hasil = ['baru' => 0, 'diperbarui' => 0, 'tetap' => 0, 'tidak_terpasang' => 0, 'tidak_dikenal' => 0];
        $sisip = [];
        $ubah  = [];

        if ($baris === []) {
            return ['sisip' => $sisip, 'ubah' => $ubah, 'hasil' => $hasil];
        }

        $ada = [];

        foreach ($this->db->table('riwayat_skp_periodik')
            ->select('id_riwayat_skp_periodik, id_pns, periode_id, skp_id, hasil_akhir')
            ->whereIn('periode_id', array_values(array_unique(array_column($baris, 'periode_id'))))
            ->get()->getResultArray() as $r) {
            $ada[PemetaEkinerja::kunci($r)] = $r;
        }

        foreach ($baris as $b) {
            $kunci = PemetaEkinerja::kunci($b);

            if (! isset($ada[$kunci])) {
                $sisip[]     = $b;
                $ada[$kunci] = ['id_riwayat_skp_periodik' => null, 'hasil_akhir' => $b['hasil_akhir']];
                $hasil['baru']++;

                continue;
            }

            $lama = $ada[$kunci];

            if ($b['hasil_akhir'] !== null && (string) $lama['hasil_akhir'] !== $b['hasil_akhir'] && $lama['id_riwayat_skp_periodik'] !== null) {
                $ubah[$lama['id_riwayat_skp_periodik']] = $b;
                $ada[$kunci]['hasil_akhir']             = $b['hasil_akhir'];
                $hasil['diperbarui']++;

                continue;
            }

            $hasil['tetap']++;
        }

        return ['sisip' => $sisip, 'ubah' => $ubah, 'hasil' => $hasil];
    }

    /**
     * @param list<array<string, int|string|null>> $baris
     *
     * @return list<array<string, int|string|null>>
     */
    private function periodeBaru(array $baris): array
    {
        $ada = array_fill_keys(array_map(
            static fn (array $r): string => mb_strtolower(trim((string) $r['id'])),
            $this->db->table('bkn_periode_ekinper')->select('id')->whereIn('id', array_column($baris, 'id'))->get()->getResultArray(),
        ), true);

        $baru = [];

        foreach ($baris as $b) {
            $kunci = mb_strtolower(trim((string) $b['id']));

            if (! isset($ada[$kunci])) {
                $baru[]      = $b;
                $ada[$kunci] = true;
            }
        }

        return $baru;
    }

    /**
     * Pola transaksi bersama proyek (lihat `MasterAkJfService`): tanpa `transException(true)` yang menempel di koneksi
     * bersama; hasil query & `transStatus()` diperiksa, rollback + lempar bila gagal, status selalu di-reset.
     *
     * @param callable(): bool $tulis false = ada query yang gagal
     *
     * @throws DatabaseException
     */
    private function dalamTransaksi(string $label, callable $tulis): void
    {
        if ($this->kaitSebelumTulis !== null) {
            ($this->kaitSebelumTulis)();
        }

        $this->db->transBegin();

        try {
            if (! $tulis() || $this->db->transStatus() === false) {
                throw new DatabaseException("Simpan {$label} gagal (" . $this->db->error()['code'] . ').');
            }

            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e instanceof DatabaseException ? $e : new DatabaseException("Simpan {$label} gagal.", 0, $e);
        } finally {
            $this->db->resetTransStatus();
        }
    }

    /**
     * @param list<string|null> $nip
     *
     * @return array{tidak_terpasang: int, tidak_dikenal: int}
     */
    private function pemasangan(array $nip): array
    {
        $nip = array_values(array_filter($nip, static fn (?string $n): bool => $n !== null));

        if ($nip === []) {
            return ['tidak_terpasang' => 0, 'tidak_dikenal' => 0];
        }

        $pegawai = $this->db->table('pegawai')->select('nip, id_pns_siasn')->whereIn('nip', $nip)->get()->getResultArray();
        $tanpaId = count(array_filter($pegawai, static fn (array $p): bool => trim((string) ($p['id_pns_siasn'] ?? '')) === ''));

        return ['tidak_terpasang' => $tanpaId, 'tidak_dikenal' => count($nip) - count($pegawai)];
    }
}
