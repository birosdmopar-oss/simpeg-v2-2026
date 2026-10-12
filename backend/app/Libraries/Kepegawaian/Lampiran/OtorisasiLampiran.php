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
use CodeIgniter\Database\BaseConnection;
use LogicException;

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
 *  b. role tanpa izin aksi itu (unduh = `lihat`; hapus = `hapus`/`ubah`) di Definisi berlampiran MANA PUN → 403, juga
 *     sebelum baris lampiran dibaca (tidak ada oracle keberadaan id bagi role yang tidak pernah boleh);
 *  c. baris `document_attachment` {id} milik {nip} (404);
 *  d. izin Definisi pemilik `id_riwayat` baris itu: unduh = `lihat`; hapus = `hapus` atau `ubah`, plus `bolehUbah` (403);
 *  e. baris riwayat `id_entri` milik {nip} dan bukan status 10 Dihapus (404) — unduh hanya membaca (tanpa kunci baris);
 *     hapus juga menegakkan kunci baris Disetujui (403), lalu lampiran terakhir kode ber-`wajib` tidak boleh dihapus
 *     (422 `errors.berkas`; "ganti" = unggah baru dulu, lalu hapus yang lama).
 *
 * Tulis (unggah & hapus) = dua tahap: bolehUnggah()/bolehHapus() = jalan cepat di luar transaksi (menentukan urutan
 * 403/404/422), lalu kunciUnggah()/kunciHapus() WAJIB dipanggil DI DALAM transaksi tulis: baris `pegawai` dikunci
 * `FOR UPDATE` (pola RiwayatEngine::kunciPegawai), lampiran & baris riwayat dibaca ulang terkunci, kunci baris dan
 * hitungan lampiran wajib diulang — dua hapus paralel tidak bisa menghabiskan lampiran wajib (TOCTOU).
 *
 * Dipakai juga oleh RiwayatEngine WS-1 bila perlu.
 */
final class OtorisasiLampiran
{
    public function __construct(
        private readonly RiwayatRegistryInterface $registry,
        private readonly PegawaiScopeInterface $scope,
        private readonly ?BaseConnection $db = null,
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
        $this->izinDiDefinisiManaPun($auth, [AksiRiwayat::Lihat]);
        $row      = $this->barisMilik($nip, $idAttachment);
        $definisi = $this->definisi((int) $row['id_riwayat']);
        $this->izin($definisi, $auth, [AksiRiwayat::Lihat]);
        // Baca saja (tanpa kunci baris Disetujui): baris riwayat yang sudah dihapus (status 10) → 404.
        $this->entriMilik($definisi, $nip, (string) $row['id_entri']);

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
        $this->izinDiDefinisiManaPun($auth, [AksiRiwayat::Hapus, AksiRiwayat::Ubah]);
        $row       = $this->barisMilik($nip, $idAttachment);
        $idRiwayat = (int) $row['id_riwayat'];
        $definisi  = $this->definisi($idRiwayat);
        $this->izin($definisi, $auth, [AksiRiwayat::Hapus, AksiRiwayat::Ubah]);
        $this->lingkup($auth, $nip, true);
        $this->bukanBarisTerkunci($definisi, $auth, $this->entriMilik($definisi, $nip, (string) $row['id_entri']));
        $this->bukanLampiranWajibTerakhir($nip, $idRiwayat, (string) $row['id_entri'], false);

        return AttachmentService::untukKlien($row);
    }

    /**
     * Tahap kedua unggah, DI DALAM transaksi tulis (sesudah bolehUnggah()): kunci baris `pegawai`, baca ulang baris
     * riwayat terkunci (hilang/status 10 → 404), ulangi kunci baris Disetujui (403).
     */
    public function kunciUnggah(AuthContext $auth, string $nip, int $idRiwayat, string $idEntri): void
    {
        $this->wajibDalamTransaksi();
        $definisi = $this->definisi($idRiwayat);
        $this->kunciPegawai($nip);
        $this->bukanBarisTerkunci($definisi, $auth, $this->entriMilik($definisi, $nip, $idEntri, true));
    }

    /**
     * Tahap kedua hapus, DI DALAM transaksi tulis (sesudah bolehHapus()): kunci baris `pegawai`, baca ulang lampiran
     * terkunci (hilang → 404), ulangi keterikatan & kunci baris riwayat (404/403), lalu hitung ulang lampiran kode itu
     * dengan kunci (lampiran wajib terakhir → 422 `errors.berkas`).
     */
    public function kunciHapus(AuthContext $auth, string $nip, int $idAttachment): void
    {
        $this->wajibDalamTransaksi();
        $this->kunciPegawai($nip);
        $row       = $this->barisMilik($nip, $idAttachment, true);
        $idRiwayat = (int) $row['id_riwayat'];
        $idEntri   = (string) $row['id_entri'];
        $definisi  = $this->definisi($idRiwayat);
        $this->bukanBarisTerkunci($definisi, $auth, $this->entriMilik($definisi, $nip, $idEntri, true));
        $this->bukanLampiranWajibTerakhir($nip, $idRiwayat, $idEntri, true);
    }

    /**
     * Baris `document_attachment` $idAttachment milik {nip}; 404 bila tidak ada atau milik NIP lain.
     *
     * @return array<string, mixed>
     */
    private function barisMilik(string $nip, int $idAttachment, bool $kunci = false): array
    {
        $db = $this->koneksi();

        /** @var array<string, mixed>|null $row */
        $row = $db->query(
            'SELECT * FROM ' . $db->protectIdentifiers('document_attachment', true)
            . ' WHERE ' . $db->protectIdentifiers('id_attachment') . ' = ? AND ' . $db->protectIdentifiers('NIP') . ' = ?'
            . ($kunci ? ' FOR UPDATE' : ''),
            [$idAttachment, $nip],
        )->getRowArray();

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

    /**
     * Role tanpa salah satu aksi $salahSatu di Definisi berlampiran mana pun → 403 (dipanggil sebelum baris lampiran
     * dibaca, supaya role itu tidak bisa membedakan id yang ada dari yang tidak).
     *
     * @param list<AksiRiwayat> $salahSatu
     */
    private function izinDiDefinisiManaPun(AuthContext $auth, array $salahSatu): void
    {
        foreach ($this->registry->semua() as $definisi) {
            if ($definisi->lampiran() === []) {
                continue;
            }

            foreach ($salahSatu as $aksi) {
                if ($definisi->boleh($aksi, $auth->role())) {
                    return;
                }
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
    private function entriMilik(RiwayatDefinisi $definisi, string $nip, string $idEntri, bool $kunci = false): ?StatusRiwayat
    {
        $db = $this->koneksi();

        /** @var array<string, mixed>|null $row */
        $row = $db->query(
            'SELECT ' . $db->protectIdentifiers($definisi->kolomStatus()) . ' AS status_baris FROM ' . $db->protectIdentifiers($definisi->tabel(), true)
            . ' WHERE ' . $db->protectIdentifiers($definisi->primaryKey()) . ' = ? AND ' . $db->protectIdentifiers($definisi->kolomNip()) . ' = ?'
            . ($kunci ? ' FOR UPDATE' : ''),
            [$idEntri, $nip],
        )->getRowArray();

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

    /**
     * Lampiran terakhir kode ber-`wajib` pada satu baris tidak boleh dihapus → 422 `errors.berkas`. $kunci = hitung
     * dengan `FOR UPDATE` (di dalam transaksi tulis).
     */
    private function bukanLampiranWajibTerakhir(string $nip, int $idRiwayat, string $idEntri, bool $kunci): void
    {
        if ($this->aturan($idRiwayat)->wajib && $this->jumlahLampiran($nip, $idRiwayat, $idEntri, $kunci) <= 1) {
            throw ValidationException::forField('berkas', 'Lampiran wajib tidak boleh dihapus semua. Unggah berkas pengganti dulu, lalu hapus yang lama.');
        }
    }

    private function jumlahLampiran(string $nip, int $idRiwayat, string $idEntri, bool $kunci): int
    {
        $db = $this->koneksi();

        return count($db->query(
            'SELECT ' . $db->protectIdentifiers('id_attachment') . ' FROM ' . $db->protectIdentifiers('document_attachment', true)
            . ' WHERE ' . $db->protectIdentifiers('NIP') . ' = ? AND ' . $db->protectIdentifiers('id_riwayat') . ' = ? AND '
            . $db->protectIdentifiers('id_entri') . ' = ?' . ($kunci ? ' FOR UPDATE' : ''),
            [$nip, $idRiwayat, $idEntri],
        )->getResultArray());
    }

    /**
     * Kunci baris `pegawai` {nip} (`FOR UPDATE`, sama dengan RiwayatEngine::kunciPegawai) — tulisan lampiran dan
     * riwayat paralel untuk pegawai yang sama berurutan.
     */
    private function kunciPegawai(string $nip): void
    {
        $db = $this->koneksi();
        $db->query('SELECT ' . $db->protectIdentifiers('nip') . ' FROM ' . $db->protectIdentifiers('pegawai', true) . ' WHERE ' . $db->protectIdentifiers('nip') . ' = ? FOR UPDATE', [$nip]);
    }

    private function wajibDalamTransaksi(): void
    {
        if ((int) $this->koneksi()->transDepth < 1) {
            throw new LogicException('Pemeriksaan terkunci lampiran harus dipanggil di dalam transaksi tulis.');
        }
    }

    private function koneksi(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
