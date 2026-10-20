<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use JsonException;
use RuntimeException;

/**
 * Penyimpan pratinjau impor Slip Gaji (PRD 5A §7.2.2 butir 5–6, §7.2.4 butir 2–3 dan 7, §7.2.5; F5-3 — tanpa tabel).
 *
 * - Berkas disimpan di direktori di bawah `writable/` (tidak dapat diakses publik) dengan nama `sha256(token) + ekstensi`,
 *   bukan nama klien; metadata di sidecar JSON `sha256(token).json`: `id_pengguna`, `role`, `id_satker`, `filter`,
 *   `ekstensi`, `nama_berkas` (metadata saja), `hash_isi` (SHA-256 isi berkas), `dibuat_at`/`kedaluwarsa_at` (UTC).
 * - Token pratinjau = 32 byte acak → 64 heks; hanya pemegang token yang dapat memuat, dan hanya pengguna pemiliknya.
 * - Satu pratinjau aktif per pengguna [V2]: simpan baru menghapus pratinjau lama pengguna yang sama; setiap simpan juga
 *   menyapu pratinjau kedaluwarsa (pemanggil commit juga memanggil {@see self::sapuKedaluwarsa()}).
 * - {@see self::muat()} = null (→ 404 `pratinjau_tidak_ditemukan` seragam) bila token tidak sah/tidak ada, bukan milik
 *   pemanggil, kedaluwarsa (berkas langsung dihapus), atau isi berkas berubah (hash berbeda).
 * - Waktu disuntik pemanggil (detik Unix), tidak membaca jam sistem sendiri.
 */
final class PenyimpanPratinjau
{
    private const POLA_TOKEN = '/^[0-9a-f]{64}\z/';

    public function __construct(private readonly string $direktori, private readonly int $ttlDetik)
    {
    }

    /**
     * @param array{id_pengguna: int, role: int, id_satker: ?string, filter: array<string, mixed>, nama_berkas: string} $meta
     *
     * @return array{token: string, kedaluwarsa_at: string}
     */
    public function simpan(string $isi, string $ekstensi, array $meta, int $sekarang): array
    {
        $this->siapkanDirektori();
        $this->sapuKedaluwarsa($sekarang);
        $this->hapusMilik((int) $meta['id_pengguna']);

        $token       = bin2hex(random_bytes(32));
        $nama        = hash('sha256', $token);
        $kedaluwarsa = $sekarang + $this->ttlDetik;
        $ekstensi    = strtolower($ekstensi);

        $data = $meta + [
            'ekstensi'       => $ekstensi,
            'hash_isi'       => hash('sha256', $isi),
            'dibuat_at'      => gmdate('Y-m-d H:i:s', $sekarang),
            'kedaluwarsa_at' => gmdate('Y-m-d H:i:s', $kedaluwarsa),
            'kedaluwarsa_ts' => $kedaluwarsa,
        ];

        if (@file_put_contents($this->path($nama, $ekstensi), $isi, LOCK_EX) === false
            || @file_put_contents($this->path($nama, 'json'), json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            $this->hapusNama($nama);

            throw new RuntimeException('Pratinjau impor gagal disimpan.');
        }

        return ['token' => $token, 'kedaluwarsa_at' => (string) $data['kedaluwarsa_at']];
    }

    /**
     * @return array{path: string, meta: array<string, mixed>}|null
     */
    public function muat(string $token, int $idPengguna, int $sekarang): ?array
    {
        if (preg_match(self::POLA_TOKEN, $token) !== 1) {
            return null;
        }

        $nama = hash('sha256', $token);
        $meta = $this->bacaMeta($nama);

        if ($meta === null) {
            return null;
        }

        if ((int) ($meta['kedaluwarsa_ts'] ?? 0) <= $sekarang) {
            $this->hapusNama($nama);

            return null;
        }

        $path = $this->path($nama, (string) ($meta['ekstensi'] ?? ''));

        if ((int) ($meta['id_pengguna'] ?? 0) !== $idPengguna || ! is_file($path) || hash_file('sha256', $path) !== ($meta['hash_isi'] ?? null)) {
            return null;
        }

        return ['path' => $path, 'meta' => $meta];
    }

    public function hapus(string $token): void
    {
        if (preg_match(self::POLA_TOKEN, $token) === 1) {
            $this->hapusNama(hash('sha256', $token));
        }
    }

    public function sapuKedaluwarsa(int $sekarang): void
    {
        foreach ($this->semuaMeta() as $nama => $meta) {
            if ($meta === null || (int) ($meta['kedaluwarsa_ts'] ?? 0) <= $sekarang) {
                $this->hapusNama($nama);
            }
        }
    }

    public function hapusMilik(int $idPengguna): void
    {
        foreach ($this->semuaMeta() as $nama => $meta) {
            if ($meta !== null && (int) ($meta['id_pengguna'] ?? 0) === $idPengguna) {
                $this->hapusNama($nama);
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>|null> nama (sha256 token) => metadata (null = tidak terbaca)
     */
    private function semuaMeta(): array
    {
        $hasil = [];

        foreach (glob(rtrim($this->direktori, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [] as $berkas) {
            $nama = basename($berkas, '.json');

            if (preg_match(self::POLA_TOKEN, $nama) === 1) {
                $hasil[$nama] = $this->bacaMeta($nama);
            }
        }

        return $hasil;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bacaMeta(string $nama): ?array
    {
        $isi = @file_get_contents($this->path($nama, 'json'));

        if ($isi === false) {
            return null;
        }

        try {
            $meta = json_decode($isi, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($meta) ? $meta : null;
    }

    private function hapusNama(string $nama): void
    {
        foreach (glob(rtrim($this->direktori, '/\\') . DIRECTORY_SEPARATOR . $nama . '.*') ?: [] as $berkas) {
            @unlink($berkas);
        }
    }

    private function path(string $nama, string $ekstensi): string
    {
        return rtrim($this->direktori, '/\\') . DIRECTORY_SEPARATOR . $nama . '.' . $ekstensi;
    }

    private function siapkanDirektori(): void
    {
        if (! is_dir($this->direktori) && ! @mkdir($this->direktori, 0700, true) && ! is_dir($this->direktori)) {
            throw new RuntimeException('Direktori pratinjau impor tidak dapat dibuat.');
        }
    }
}
