<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lampiran;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Interfaces\Kepegawaian\RiwayatRegistryInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use CodeIgniter\Database\ConnectionInterface;

/**
 * Otorisasi endpoint `pegawai/{nip}/lampiran*` (B-18, MAKE-009), urutan sesuai kontrak AttachmentServiceInterface /
 * README API:
 *  1. `id_riwayat` → Definisi pemilik kode (tidak terdaftar → 404);
 *  2. izin Definisi × role: lihat = `lihat`; unggah = `tambah` atau `ubah`; hapus = `hapus` atau `ubah` (403);
 *  3. PegawaiScope `bolehLihat` (baca) / `bolehUbah` (tulis) atas {nip} (403);
 *  4. `id_entri` = baris milik {nip} di `tabel()` Definisi (`<pk> = id_entri AND <kolomNip> = nip`) (404).
 *
 * Untuk operasi per lampiran (unduh/hapus) keterikatan ke {nip} sudah dijamin baris `document_attachment.NIP`, jadi
 * langkah 4 tidak diulang. Dipakai juga oleh RiwayatEngine WS-1 bila perlu.
 */
final class OtorisasiLampiran
{
    public function __construct(
        private readonly RiwayatRegistryInterface $registry,
        private readonly PegawaiScopeInterface $scope,
        private readonly ?ConnectionInterface $db = null,
    ) {
    }

    /**
     * Daftar lampiran satu baris riwayat.
     */
    public function bolehDaftar(AuthContext $auth, string $nip, int $idRiwayat, string $idEntri): void
    {
        $definisi = $this->definisi($idRiwayat);
        $this->izin($definisi, $auth, [AksiRiwayat::Lihat]);
        $this->lingkup($auth, $nip, false);
        $this->entriMilik($definisi, $nip, $idEntri);
    }

    /**
     * Unggah lampiran ke baris riwayat yang sudah ada; mengembalikan aturan lampiran kode itu.
     */
    public function bolehUnggah(AuthContext $auth, string $nip, int $idRiwayat, string $idEntri): AturanLampiran
    {
        $definisi = $this->definisi($idRiwayat);
        $this->izin($definisi, $auth, [AksiRiwayat::Tambah, AksiRiwayat::Ubah]);
        $this->lingkup($auth, $nip, true);
        $this->entriMilik($definisi, $nip, $idEntri);

        return $this->registry->aturanLampiran($idRiwayat) ?? throw new NotFoundException('Jenis lampiran tidak ditemukan.');
    }

    /**
     * Unduh lampiran $lampiran (baris `document_attachment` milik {nip}).
     *
     * @param array<string, mixed> $lampiran
     */
    public function bolehUnduh(AuthContext $auth, string $nip, array $lampiran): void
    {
        $definisi = $this->definisi((int) ($lampiran['id_riwayat'] ?? 0));
        $this->izin($definisi, $auth, [AksiRiwayat::Lihat]);
        $this->lingkup($auth, $nip, false);
    }

    /**
     * Hapus lampiran $lampiran (baris `document_attachment` milik {nip}).
     *
     * @param array<string, mixed> $lampiran
     */
    public function bolehHapus(AuthContext $auth, string $nip, array $lampiran): void
    {
        $definisi = $this->definisi((int) ($lampiran['id_riwayat'] ?? 0));
        $this->izin($definisi, $auth, [AksiRiwayat::Hapus, AksiRiwayat::Ubah]);
        $this->lingkup($auth, $nip, true);
    }

    /**
     * Baris `document_attachment` $idAttachment milik {nip} tanpa kolom internal; 404 bila tidak ada atau milik NIP
     * lain (dibaca sebelum otorisasi karena izin bergantung pada `id_riwayat` baris itu).
     *
     * @return array<string, mixed>
     */
    public function barisMilik(string $nip, int $idAttachment): array
    {
        $row = $this->koneksi()->table('document_attachment')
            ->where('id_attachment', $idAttachment)
            ->where('NIP', $nip)
            ->get()
            ->getRowArray();

        if (! is_array($row)) {
            throw new NotFoundException('Lampiran tidak ditemukan.');
        }

        return AttachmentService::untukKlien($row);
    }

    private function definisi(int $idRiwayat): RiwayatDefinisi
    {
        return $this->registry->definisiUntukLampiran($idRiwayat) ?? throw new NotFoundException('Jenis lampiran tidak ditemukan.');
    }

    /**
     * @param list<AksiRiwayat> $salahSatu
     */
    private function izin(RiwayatDefinisi $definisi, AuthContext $auth, array $salahSatu): void
    {
        foreach ($salahSatu as $aksi) {
            if ($definisi->boleh($aksi, $auth->role())) {
                return;
            }
        }

        throw new ForbiddenException('Anda tidak berhak mengakses lampiran ini.');
    }

    private function lingkup(AuthContext $auth, string $nip, bool $tulis): void
    {
        $boleh = $tulis ? $this->scope->bolehUbah($auth, $nip) : $this->scope->bolehLihat($auth, $nip);

        if (! $boleh) {
            throw new ForbiddenException('Anda tidak dapat mengakses data pegawai ini.');
        }
    }

    private function entriMilik(RiwayatDefinisi $definisi, string $nip, string $idEntri): void
    {
        $ada = $this->koneksi()->table($definisi->tabel())
            ->where($definisi->primaryKey(), $idEntri)
            ->where($definisi->kolomNip(), $nip)
            ->countAllResults() > 0;

        if (! $ada) {
            throw new NotFoundException('Data riwayat tidak ditemukan.');
        }
    }

    private function koneksi(): ConnectionInterface
    {
        return $this->db ?? db_connect();
    }
}
