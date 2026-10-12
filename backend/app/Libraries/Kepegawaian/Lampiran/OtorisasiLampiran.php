<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lampiran;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Interfaces\Kepegawaian\RiwayatRegistryInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use CodeIgniter\Database\ConnectionInterface;

/**
 * Otorisasi endpoint `pegawai/{nip}/lampiran*` (B-18, MAKE-009).
 *
 * Daftar & unggah (target `id_riwayat` + `id_entri` dari request), urutan kontrak AttachmentServiceInterface / README API:
 *  1. `id_riwayat` → Definisi pemilik kode (tidak terdaftar → 404);
 *  2. izin Definisi × role: lihat = `lihat`; unggah = `tambah` atau `ubah` (403);
 *  3. PegawaiScope `bolehLihat` (baca) / `bolehUbah` (tulis) atas {nip} (403);
 *  4. `id_entri` = baris milik {nip} di `tabel()` Definisi (`<pk> = id_entri AND <kolomNip> = nip`), bukan berstatus
 *     Dihapus (404);
 *  5. tulis: baris Disetujui terkunci untuk UL_PEGAWAI bila `kunciBarisDisetujui()` (403).
 *
 * Unduh & hapus (per `{id}` lampiran):
 *  a. PegawaiScope `bolehLihat` atas {nip} (403) — SEBELUM baris lampiran dibaca, jadi pemanggil di luar lingkup
 *     mendapat 403 sama untuk id yang ada maupun tidak (keberadaan lampiran tidak bocor);
 *  b. baris `document_attachment` {id} milik {nip} (404);
 *  c. izin Definisi pemilik `id_riwayat` baris itu: unduh = `lihat`; hapus = `hapus` atau `ubah`, plus `bolehUbah` (403);
 *  d. hapus: baris riwayat `id_entri` seperti langkah 4–5 (404/403), lalu lampiran terakhir kode ber-`wajib` tidak boleh
 *     dihapus (422 `errors.berkas`; "ganti" = unggah baru dulu, lalu hapus yang lama).
 *
 * Dipakai juga oleh RiwayatEngine WS-1 bila perlu.
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
        $this->bukanBarisTerkunci($definisi, $auth, $this->entriMilik($definisi, $nip, $idEntri));

        return $this->aturan($idRiwayat);
    }

    /**
     * Unduh lampiran $idAttachment milik {nip}; mengembalikan barisnya (tanpa kolom internal).
     *
     * @return array<string, mixed>
     */
    public function bolehUnduh(AuthContext $auth, string $nip, int $idAttachment): array
    {
        $this->lingkup($auth, $nip, false);
        $row = $this->barisMilik($nip, $idAttachment);
        $this->izin($this->definisi((int) $row['id_riwayat']), $auth, [AksiRiwayat::Lihat]);

        return AttachmentService::untukKlien($row);
    }

    /**
     * Hapus lampiran $idAttachment milik {nip}; mengembalikan barisnya (tanpa kolom internal).
     *
     * @return array<string, mixed>
     */
    public function bolehHapus(AuthContext $auth, string $nip, int $idAttachment): array
    {
        $this->lingkup($auth, $nip, false);
        $row       = $this->barisMilik($nip, $idAttachment);
        $idRiwayat = (int) $row['id_riwayat'];
        $definisi  = $this->definisi($idRiwayat);
        $this->izin($definisi, $auth, [AksiRiwayat::Hapus, AksiRiwayat::Ubah]);
        $this->lingkup($auth, $nip, true);
        $this->bukanBarisTerkunci($definisi, $auth, $this->entriMilik($definisi, $nip, (string) $row['id_entri']));

        if ($this->aturan($idRiwayat)->wajib && $this->jumlahLampiran($nip, $idRiwayat, (string) $row['id_entri']) <= 1) {
            throw ValidationException::forField('berkas', 'Lampiran wajib tidak boleh dihapus semua. Unggah berkas pengganti dulu, lalu hapus yang lama.');
        }

        return AttachmentService::untukKlien($row);
    }

    /**
     * Baris `document_attachment` $idAttachment milik {nip}; 404 bila tidak ada atau milik NIP lain.
     *
     * @return array<string, mixed>
     */
    private function barisMilik(string $nip, int $idAttachment): array
    {
        $row = $this->koneksi()->table('document_attachment')
            ->where('id_attachment', $idAttachment)
            ->where('NIP', $nip)
            ->get()
            ->getRowArray();

        if (! is_array($row)) {
            throw new NotFoundException('Lampiran tidak ditemukan.');
        }

        return $row;
    }

    private function definisi(int $idRiwayat): RiwayatDefinisi
    {
        return $this->registry->definisiUntukLampiran($idRiwayat) ?? throw new NotFoundException('Jenis lampiran tidak ditemukan.');
    }

    private function aturan(int $idRiwayat): AturanLampiran
    {
        return $this->registry->aturanLampiran($idRiwayat) ?? throw new NotFoundException('Jenis lampiran tidak ditemukan.');
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

    /**
     * Status baris riwayat $idEntri milik {nip}; 404 bila tidak ada, milik NIP lain, atau berstatus Dihapus.
     */
    private function entriMilik(RiwayatDefinisi $definisi, string $nip, string $idEntri): ?StatusRiwayat
    {
        $row = $this->koneksi()->table($definisi->tabel())
            ->select($definisi->kolomStatus() . ' AS status_baris')
            ->where($definisi->primaryKey(), $idEntri)
            ->where($definisi->kolomNip(), $nip)
            ->get()
            ->getRowArray();

        $status = is_array($row) && $row['status_baris'] !== null ? ($definisi->pemetaanStatus()[(int) $row['status_baris']] ?? null) : null;

        if (! is_array($row) || $status === StatusRiwayat::Dihapus) {
            throw new NotFoundException('Data riwayat tidak ditemukan.');
        }

        return $status;
    }

    /**
     * Baris Disetujui terkunci untuk UL_PEGAWAI bila Definisi menguncinya (`kunciBarisDisetujui()`) → 403.
     */
    private function bukanBarisTerkunci(RiwayatDefinisi $definisi, AuthContext $auth, ?StatusRiwayat $status): void
    {
        if ($status === StatusRiwayat::Disetujui
            && $definisi->kunciBarisDisetujui()
            && in_array($auth->role(), Role::UL_PEGAWAI, true)) {
            throw new ForbiddenException('Data yang sudah disetujui tidak dapat diubah.');
        }
    }

    private function jumlahLampiran(string $nip, int $idRiwayat, string $idEntri): int
    {
        return $this->koneksi()->table('document_attachment')
            ->where('NIP', $nip)
            ->where('id_riwayat', $idRiwayat)
            ->where('id_entri', $idEntri)
            ->countAllResults();
    }

    private function koneksi(): ConnectionInterface
    {
        return $this->db ?? db_connect();
    }
}
