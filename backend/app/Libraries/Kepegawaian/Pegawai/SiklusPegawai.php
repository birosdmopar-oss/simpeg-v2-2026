<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Pegawai;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AccountProvisioner;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\KolomBerkas;
use App\Models\AuditLogModel;
use App\Models\Kepegawaian\PegawaiFotoModel;
use App\Models\Kepegawaian\PegawaiModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\Files\UploadedFile;
use Throwable;

/**
 * B-05 Tambah & Hapus pegawai (MAKE-011, role 1; Matriks `hr/employee/add`, `hr/employee/delete/{nip}`).
 *
 * Tambah [K] `libraries/hr/L_employee.php:94-211` (`insert`): biodata Super Admin + NIP baru + foto wajib, satu
 * transaksi: `pegawai` (status 1) → `pegawai_foto` → akun `pengguna` SESUDAH baris pegawai tersimpan (A-09 + MAKE-015;
 * username = NIP, role dari jenis pegawai :158-161). Password awal = NIP [K] (:162-164, keputusan user 13-10-2026) lewat
 * jalur aditif `AccountProvisioner::provisionDenganPasswordNip()` (hash Argon2id, bukan md5); pemilik akun wajib segera
 * menggantinya (penanda menunggu DBV-020). Email yang sudah dipakai akun lain tidak disimpan di akun (`email_skipped`,
 * A-09 D-6). `pegawai_sisa_cuti` PPPK (legacy :170-184) tidak ditulis: tabelnya milik C-01 (Fase 4) — dicatat untuk C-01.
 *
 * Hapus [K] ikut legacy (`controllers/hr/Employee.php:361-386`, `L_employee.php:663-705`, keputusan user 13-10-2026;
 * DoD B-05 menyebut soft delete, user memilih legacy): DELETE fisik baris `pegawai` dalam transaksi, audit `delete`
 * (before = baris pegawai: NIP + nama) sebelum DELETE, berkas foto dibuang SESUDAH commit. Legacy hanya menghapus
 * `pegawai`; di v2 FK RESTRICT ke `pegawai.nip` membuat pegawai yang masih punya data turunan (riwayat, snapshot
 * jabatan, pengajuan, …) GAGAL dihapus → 422 (bukan 500), tanpa cascade manual. Akun `pengguna` ber-NIP itu [K] D1:3756
 * (FK `pengguna.id_pegawai` ON DELETE SET NULL, keputusan user 13-10-2026): di transaksi yang sama DILEPAS dari NIP
 * (`nip` NULL, termasuk akun soft-deleted), dinonaktifkan (`status` '0'), sesinya dicabut, diaudit — tidak menghalangi
 * hapus (akun role 2/6/7 tanpa NIP memang tidak lazim, tetapi itulah hasil legacy). Galeri `pegawai_foto` ikut dihapus
 * [K] D1:2932 (`fk_nip_pegfoto_to_peg` ON DELETE CASCADE), diaudit per baris, berkasnya dibuang sesudah commit.
 */
final class SiklusPegawai
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly KolomBerkas $berkas,
        private readonly AccountProvisioner $provisioner,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{pegawai: array<string, mixed>, akun: array<string, mixed>}
     */
    public function tambah(AuthContext $auth, array $data, ?UploadedFile $foto): array
    {
        $this->wajibSuperAdmin($auth);

        $hasil = (new ValidasiBiodata($this->db))->periksaTambah($data);

        // NIP sudah tertaut ke akun mana pun (termasuk soft-deleted, mis. bekas pegawai yang akunnya dilepas) → tolak
        // sebelum provisioning; akun lama tidak boleh tertaut diam-diam ke pegawai baru.
        if ($this->db->table('pengguna')->where('nip', $hasil['nip'])->countAllResults() > 0) {
            throw ValidationException::forField('nip', 'NIP ini sudah tertaut ke akun lain; selesaikan akun tersebut di Manajemen Akun terlebih dahulu.');
        }

        if (! $foto instanceof UploadedFile || $foto->getError() === UPLOAD_ERR_NO_FILE) {
            throw ValidationException::forField('foto', 'Foto pegawai wajib diunggah.');
        }

        $nip = $hasil['nip'];
        $this->db->transBegin();

        try {
            $akun = $this->tambahTerkunci($auth, $nip, $hasil['param'], $hasil['email'], $foto);

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Transaksi tambah pegawai gagal.');
            }

            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();

            // Balapan: NIP yang sama didaftarkan proses lain di antara cek dan INSERT.
            if ($e instanceof DatabaseException && (int) $e->getCode() === 1062) {
                throw ValidationException::forField('nip', 'NIP sudah terdaftar di dalam sistem; gunakan NIP yang berbeda.');
            }

            throw $e;
        } finally {
            $this->db->resetTransStatus();
            $this->berkas->selesaikan();
        }

        return ['pegawai' => $this->baris($nip), 'akun' => $akun];
    }

    /**
     * @return array<string, mixed> baris pegawai sebelum dihapus (tanpa path foto)
     */
    public function hapus(AuthContext $auth, string $nip): array
    {
        $this->wajibSuperAdmin($auth);

        if ($this->db->table('pegawai')->where('nip', $nip)->countAllResults() === 0) {
            throw new NotFoundException('Pegawai tidak ditemukan.');
        }

        $sebelum = $this->baris($nip);
        $this->db->transBegin();

        try {
            $t       = $this->db->escapeIdentifiers($this->db->prefixTable('pegawai'));
            $pegawai = $this->db->query("SELECT * FROM {$t} WHERE nip = ? FOR UPDATE", [$nip])->getRowArray();

            if (! is_array($pegawai)) {
                throw new NotFoundException('Pegawai tidak ditemukan.');
            }

            $this->lepasAkun($auth, $nip);

            $galeri    = $this->db->table('pegawai_foto')->select('id_pegawai_foto, foto')->where('nip', $nip)->get()->getResultArray();
            $fotoModel = new PegawaiFotoModel($this->db);

            foreach ($galeri as $baris) {
                $id = (int) $baris['id_pegawai_foto'];

                if ($fotoModel->withActor($auth, static fn () => $fotoModel->delete($id)) === false) {
                    throw self::gagalTulis($this->db);
                }
            }

            // Audit otomatis model: event `delete`, before = baris pegawai (NIP, nama bergelar) — padanan mylog legacy.
            $model = new PegawaiModel($this->db);
            if ($model->withActor($auth, static fn () => $model->delete($nip)) === false) {
                // DBDebug mati: query builder mengembalikan false, bukan exception.
                throw (int) $this->db->error()['code'] === 1451 ? self::masihTerkait() : self::gagalTulis($this->db);
            }

            // Penjaga berkas: path dibuang sesudah commit bila tidak lagi dirujuk tabel.kolom asalnya.
            foreach ($galeri as $baris) {
                $this->berkas->hapus((string) $baris['foto'], 'pegawai_foto', 'foto');
            }

            $this->berkas->hapus((string) ($pegawai['foto'] ?? ''), 'pegawai', 'foto');

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Transaksi hapus pegawai gagal.');
            }

            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();

            if ($e instanceof DatabaseException && (int) $e->getCode() === 1451) {
                throw self::masihTerkait();
            }

            throw $e;
        } finally {
            $this->db->resetTransStatus();
            // Berkas foto dibuang SESUDAH commit (rollback: tetap dirujuk pegawai.foto → dipertahankan).
            $this->berkas->selesaikan();
        }

        return $sebelum;
    }

    /**
     * [K] D1:3756 ON DELETE SET NULL: akun ber-NIP (termasuk soft-deleted) dilepas dari NIP, dinonaktifkan, sesi dicabut,
     * diaudit. Akun yang sudah nonaktif tetap dilepas.
     */
    private function lepasAkun(AuthContext $auth, string $nip): void
    {
        foreach ($this->db->table('pengguna')->select('id_pengguna, nip, status')->where('nip', $nip)->get()->getResultArray() as $akun) {
            $id = (int) $akun['id_pengguna'];

            if ($this->db->table('pengguna')->where('id_pengguna', $id)->update(['nip' => null, 'status' => '0', 'updated_at' => gmdate('Y-m-d H:i:s')]) === false) {
                throw self::gagalTulis($this->db);
            }

            service('jwt')->revokeAllForUser($id);
            (new AuditLogModel($this->db))->record(
                'pengguna',
                $id,
                AuditLogModel::EVENT_UPDATE,
                ['nip' => $akun['nip'], 'status' => (string) $akun['status']],
                ['nip' => null, 'status' => '0', 'alasan' => 'pegawai dihapus (legacy ON DELETE SET NULL)'],
                $auth->idPengguna(),
                $auth->nip(),
            );
        }
    }

    private static function masihTerkait(): ValidationException
    {
        return ValidationException::forField('nip', 'Pegawai tidak dapat dihapus karena masih memiliki data terkait (riwayat, jabatan, pengajuan, dll.).');
    }

    private static function gagalTulis(BaseConnection $db): DatabaseException
    {
        $error = $db->error();

        return new DatabaseException('Hapus pegawai gagal: ' . $error['message'], (int) $error['code']);
    }

    /**
     * @param array<string, mixed> $param
     *
     * @return array<string, mixed>
     */
    private function tambahTerkunci(AuthContext $auth, string $nip, array $param, string $email, UploadedFile $foto): array
    {
        $sekarang = gmdate('Y-m-d H:i:s');
        $path     = $this->berkas->simpan($foto, BiodataService::aturanFoto(), 'pegawai', 'foto', 'foto');

        $model = new PegawaiModel($this->db);
        $model->withActor($auth, static fn () => $model->insert(array_merge($param, [
            'nip'         => $nip,
            'foto'        => $path,
            'status'      => 1,
            'flag_update' => 0,
            'created_at'  => $sekarang,
            'updated_by'  => $auth->idPengguna(),
        ]), false));

        $fotoModel = new PegawaiFotoModel($this->db);
        $fotoModel->withActor($auth, static fn () => $fotoModel->insert(['nip' => $nip, 'foto' => $path, 'created_at' => $sekarang]));

        $nama = trim(implode(' ', array_filter([(string) ($param['glr_awal'] ?? ''), (string) $param['nama']], static fn (string $s): bool => $s !== '')))
            . ((string) ($param['glr_akhir'] ?? '') !== '' ? ', ' . $param['glr_akhir'] : '');
        $role = AkunPegawai::roleUntukJenisPegawai(isset($param['id_jenis_pegawai']) ? (int) $param['id_jenis_pegawai'] : null);

        $akun = $this->provisioner->provisionDenganPasswordNip(['nip' => $nip, 'name' => mb_substr($nama, 0, 150), 'email' => $email], $role);

        if (! $akun['created']) {
            // Balapan: akun untuk NIP ini dibuat proses lain di antara cek dan provisioning.
            throw ValidationException::forField('nip', 'NIP ini sudah tertaut ke akun lain; selesaikan akun tersebut di Manajemen Akun terlebih dahulu.');
        }

        return [
            'id_pengguna'      => $akun['pengguna']['id_pengguna'] ?? null,
            'username'         => $akun['pengguna']['username'] ?? $nip,
            'user_level'       => $akun['pengguna']['user_level'] ?? $role,
            'created'          => $akun['created'],
            'initial_password' => $akun['initial_password'],
            'email_skipped'    => $akun['email_skipped'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baris(string $nip): array
    {
        $row             = (array) $this->db->table('pegawai')->where('nip', $nip)->get()->getRowArray();
        $row['ada_foto'] = (string) ($row['foto'] ?? '') !== '';

        return array_diff_key($row, array_flip(['foto', 'siasn_flag', 'siasn_lu', 'deleted_at']));
    }

    private function wajibSuperAdmin(AuthContext $auth): void
    {
        if (! $auth->isAuthenticated() || $auth->role() !== Role::SUPER_ADMIN) {
            throw new ForbiddenException('Hanya Super Admin yang dapat menambah atau menghapus pegawai.');
        }
    }
}
