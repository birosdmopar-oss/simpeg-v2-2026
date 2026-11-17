<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg;

use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use CodeIgniter\Database\BaseConnection;

/**
 * Kewenangan objek tiket Halo Simpeg untuk SATU pengguna (BP8-2; PRD Fase 8 v1.3 §6.1b, F8-06, F8-08, F8-09, H8-16 (a1)).
 * Satu sumber untuk penegakan (`wajib*`) dan flag respons (`bisa_*`), sehingga flag selalu sama dengan hasil aksi (AC-E04.14).
 *
 * - **Pemilik** = `sender` = pengguna.
 * - **Petugas tiket** = petugas **aktif** (status 1, akun aktif, role 1–7; F8-06, S12) yang memegang kategori tiket itu. Tiket
 *   berkategori NULL tidak punya petugas (H8-16 (a1)); role 1 tidak mendapat perluasan akses.
 * - Melihat (daftar/detail/baca/lampiran) = pemilik atau petugas tiket; selain itu 404 (tiket orang lain = tidak ditemukan).
 * - Balas: dapat melihat, kategori tidak NULL (422 `tiket_tanpa_kategori`), Open (422 `tiket_ditutup`), `allow_reply = 1`
 *   (422 `tiket_tanpa_balasan`) [K `LS:1116-1123`]. Pemilik selalu boleh membalas tiketnya, termasuk bila ia petugas kategori
 *   lain (B-19).
 * - Tutup: pemilik atau petugas tiket (F8-08); sudah Closed → 422 `tiket_sudah_ditutup`.
 * - Hapus: hanya petugas tiket yang **bukan pemilik** (F8-09 harfiah, B-11; keputusan Horii 17-11-2026, T2 (b)); pemilik → 403
 *   `bukan_pihak_berwenang`, termasuk bila ia petugas aktif kategori tiket itu.
 */
final class AksesTiket
{
    public const PESAN_404 = 'Data percakapan tidak ditemukan.';

    public const PESAN_DITUTUP = 'Percakapan ini telah di tutup oleh admin. Silahkan tambah pertanyaan baru untuk memulai percakapan baru.';

    public const PESAN_TANPA_BALASAN = 'Percakapan ini tidak memerlukan balasan. Silahkan tambah pertanyaan baru untuk memulai percakapan baru.';

    public const PESAN_SUDAH_DITUTUP = 'Percakapan ini telah ditutup sebelumnya.';

    public const PESAN_TANPA_KATEGORI = 'Pertanyaan ini tidak memiliki kategori. Silakan tambah pertanyaan baru.';

    public const PESAN_BUKAN_BERWENANG = 'Anda tidak berwenang melakukan tindakan ini pada percakapan ini.';

    /** @var list<int>|null */
    private ?array $kategori = null;

    public function __construct(private readonly BaseConnection $db, public readonly int $idPengguna, public readonly int $role)
    {
    }

    /**
     * Kategori yang dipegang pengguna sebagai petugas aktif; [] bila bukan petugas aktif.
     *
     * @return list<int>
     */
    public function kategoriPetugas(): array
    {
        return $this->kategori ??= self::kategoriAktif($this->db, $this->idPengguna);
    }

    /**
     * @return list<int>
     */
    public static function kategoriAktif(BaseConnection $db, int $idPengguna): array
    {
        $rows = $db->table('support_officer so')->select('oc.id_question_category')
            ->join('officer_category oc', 'oc.id_support_officer = so.id_support_officer', 'inner')
            ->join('pengguna u', 'u.id_pengguna = so.id_user', 'inner')
            ->where('so.id_user', $idPengguna)->where('so.status', MasterHaloSimpeg::STATUS_AKTIF)
            ->where('u.status', '1')->where('u.deleted_at', null)->whereIn('u.user_level', PetugasService::ROLE_PETUGAS)
            ->orderBy('oc.id_question_category', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $r): int => (int) $r['id_question_category'], $rows);
    }

    /**
     * @param array<string, mixed> $t baris `disiplin_ticket`
     */
    public function pemilik(array $t): bool
    {
        return (int) $t['sender'] === $this->idPengguna;
    }

    /**
     * @param array<string, mixed> $t
     */
    public function petugasTiket(array $t): bool
    {
        return $t['id_question_category'] !== null && in_array((int) $t['id_question_category'], $this->kategoriPetugas(), true);
    }

    /**
     * @param array<string, mixed> $t
     */
    public function bisaLihat(array $t): bool
    {
        return $this->pemilik($t) || $this->petugasTiket($t);
    }

    /**
     * @param array<string, mixed> $t
     */
    public function bisaBalas(array $t): bool
    {
        return $this->galatBalas($t) === null;
    }

    /**
     * @param array<string, mixed> $t
     */
    public function bisaTutup(array $t): bool
    {
        return $this->bisaLihat($t) && (int) $t['status'] === 0;
    }

    /**
     * @param array<string, mixed> $t
     */
    public function bisaHapus(array $t): bool
    {
        return $this->petugasTiket($t) && ! $this->pemilik($t);
    }

    /**
     * @param array<string, mixed> $t
     *
     * @return array{bisa_balas: bool, bisa_tutup: bool, bisa_hapus: bool}
     */
    public function flag(array $t): array
    {
        return ['bisa_balas' => $this->bisaBalas($t), 'bisa_tutup' => $this->bisaTutup($t), 'bisa_hapus' => $this->bisaHapus($t)];
    }

    /**
     * @param array<string, mixed>|null $t
     *
     * @return array<string, mixed>
     */
    public function wajibLihat(?array $t): array
    {
        if ($t === null || ! $this->bisaLihat($t)) {
            throw new NotFoundException(self::PESAN_404);
        }

        return $t;
    }

    /**
     * @param array<string, mixed> $t
     */
    public function wajibBalas(array $t): void
    {
        $galat = $this->galatBalas($t);

        if ($galat !== null) {
            throw $galat;
        }
    }

    /**
     * @param array<string, mixed> $t
     */
    public function wajibTutup(array $t): void
    {
        $this->wajibLihat($t);

        if ((int) $t['status'] !== 0) {
            throw new ApiException(self::PESAN_SUDAH_DITUTUP, 422, null, 'tiket_sudah_ditutup');
        }
    }

    /**
     * @param array<string, mixed> $t
     */
    public function wajibHapus(array $t): void
    {
        $this->wajibLihat($t);

        if (! $this->bisaHapus($t)) {
            throw new ApiException(self::PESAN_BUKAN_BERWENANG, 403, null, 'bukan_pihak_berwenang');
        }
    }

    /**
     * @param array<string, mixed> $t
     */
    private function galatBalas(array $t): ?ApiException
    {
        if (! $this->bisaLihat($t)) {
            return new NotFoundException(self::PESAN_404);
        }

        if ($t['id_question_category'] === null) {
            return new ApiException(self::PESAN_TANPA_KATEGORI, 422, null, 'tiket_tanpa_kategori');
        }

        if ((int) $t['status'] !== 0) {
            return new ApiException(self::PESAN_DITUTUP, 422, null, 'tiket_ditutup');
        }

        if ((int) $t['allow_reply'] !== 1) {
            return new ApiException(self::PESAN_TANPA_BALASAN, 422, null, 'tiket_tanpa_balasan');
        }

        return null;
    }
}
