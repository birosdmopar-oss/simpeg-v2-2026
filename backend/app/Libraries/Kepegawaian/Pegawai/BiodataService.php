<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Pegawai;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\BiodataServiceInterface;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\KolomBerkas;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Models\AuditLogModel;
use App\Models\Kepegawaian\PegawaiFotoModel;
use App\Models\Kepegawaian\PegawaiHistModel;
use App\Models\Kepegawaian\PegawaiModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\Files\UploadedFile;
use Throwable;

/**
 * B-03 Biodata dua jalur (MAKE-010) — implementasi nyata BiodataServiceInterface (service `biodataService`), ikut legacy
 * `libraries/hr/L_employee.php:333-548` (`update`) dan `controllers/hr/Employee.php:115-208` (`edit`).
 *
 * - Role (Matriks `hr/employee/edit/{nip?}` = 1, 2, 3, 6, 7) → 403; lingkup `PegawaiScope::bolehUbah` (UL_PEGAWAI hanya
 *   NIP sendiri, role 3 `validateAccess_AP`) → 403; NIP → 404; pegawai berstatus 10 Dihapus → 403 [V2].
 * - Role 1 = jalur LANGSUNG: kolom `pegawai` diperbarui, `flag_update` = 0, email akun `pengguna` disinkron bila berubah
 *   (:420-440). Pengajuan aktif (flag 1) ditandai 2 Ditolak TANPA alasan (:410-418) dengan `show_notif` 1 + `notif_date`
 *   seperti trigger `pegawai_hist_beUpd` [K] (keputusan user 13-10-2026: murni legacy).
 * - Role 2/3/6/7 = jalur PENGAJUAN: `pegawai.flag_update` = 1, `pegawai` lain tidak berubah; satu `pegawai_hist` per NIP —
 *   pengajuan aktif (flag 1) diperbarui, selain itu baris baru = salinan `pegawai` + perubahan + email, `approved_by`/
 *   `reason_note` NULL, `show_*` 0 (:444-466).
 * - Foto profil (`foto`, jpg/jpeg/png, 5 MB [K] :358-371) wajib bila pegawai belum punya foto (:916-923). Disimpan lewat
 *   KolomBerkas (penyimpanan v2, bukan arsip remote legacy). Jalur langsung: `pegawai.foto` + baris `pegawai_foto` bila
 *   pasangan (nip, foto) belum ada (pengganti trigger `pegawai_afUpd`, dok DBV-012 5.1); jalur pengajuan:
 *   `pegawai_hist.foto` (masuk `pegawai` saat disetujui B-04).
 * - Semua tulisan dalam SATU transaksi dengan baris `pegawai` terkunci (`FOR UPDATE`); berkas foto dikompensasi bila
 *   rollback (KolomBerkas::selesaikan). Audit otomatis lewat model (pelaku = $auth), email akun dicatat manual.
 */
final class BiodataService implements BiodataServiceInterface
{
    /**
     * @var list<int>
     */
    public const ROLE_UBAH = PegawaiService::ROLE_UBAH_BIODATA;

    /**
     * Aturan foto profil [K] (jpg|jpeg|png, 5 MB). Kode 0 = bukan lampiran `jenis_rwy`.
     */
    public static function aturanFoto(): AturanLampiran
    {
        return new AturanLampiran(0, false, 5, ['jpg', 'jpeg', 'png']);
    }

    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly ?PegawaiScopeInterface $scope = null,
        private readonly ?KolomBerkas $berkas = null,
    ) {
    }

    public function ubah(AuthContext $auth, string $nip, array $data, ?UploadedFile $foto = null): array
    {
        $role = $auth->role();

        if (! $auth->isAuthenticated() || ! in_array($role, self::ROLE_UBAH, true)) {
            throw new ForbiddenException('Anda tidak berhak mengubah biodata.');
        }

        if (! $this->scope()->bolehUbah($auth, $nip)) {
            throw new ForbiddenException('Anda tidak berhak mengubah data pegawai ini.');
        }

        $db = $this->koneksi();

        if ($db->table('pegawai')->where('nip', $nip)->countAllResults() === 0) {
            throw new NotFoundException('Pegawai tidak ditemukan.');
        }

        $kolom = $this->berkas ?? new KolomBerkas(null, $db);
        $db->transBegin();

        try {
            $hasil = $this->dalamTransaksi($db, $kolom, $auth, (int) $role, $nip, $data, $foto);

            if ($db->transStatus() === false) {
                throw new \RuntimeException('Transaksi biodata gagal.');
            }

            $db->transCommit();

            return $hasil;
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        } finally {
            $db->resetTransStatus();
            $kolom->selesaikan();
        }
    }

    public function foto(AuthContext $auth, string $nip): array
    {
        if (! $this->scope()->bolehLihat($auth, $nip)) {
            throw new ForbiddenException('Anda tidak berhak mengakses data pegawai ini.');
        }

        $row   = $this->koneksi()->table('pegawai')->select('foto')->where('nip', $nip)->get()->getRowArray();
        $path  = is_array($row) ? (string) ($row['foto'] ?? '') : '';
        $kolom = $this->berkas ?? new KolomBerkas();

        try {
            $isi = $path !== '' && $kolom->ada($path) ? $kolom->baca($path) : null;
        } catch (Throwable) {
            // Path arsip remote legacy (cutover) atau path tidak valid bagi adapter → 404, bukan 500.
            $isi = null;
        }

        if ($isi === null) {
            throw new NotFoundException('Foto pegawai tidak tersedia.');
        }

        return ['isi' => $isi, 'mime' => self::mime($isi), 'nama' => 'foto_' . $nip . '.' . pathinfo($path, PATHINFO_EXTENSION)];
    }

    public function antreanPengajuan(AuthContext $auth, array $filter = []): array
    {
        return $this->approval()->antrean($auth, $filter);
    }

    public function pengajuan(AuthContext $auth, int $idHist): array
    {
        return $this->approval()->detail($auth, $idHist);
    }

    public function fotoPengajuan(AuthContext $auth, int $idHist): array
    {
        $path = $this->approval()->pathFoto($auth, $idHist);
        $isi  = ApprovalBiodata::bacaFoto($this->berkas ?? new KolomBerkas(), $path);

        if ($isi === null) {
            throw new NotFoundException('Foto pengajuan tidak tersedia.');
        }

        return ['isi' => $isi, 'mime' => self::mime($isi), 'nama' => 'foto_pengajuan_' . $idHist . '.' . pathinfo((string) $path, PATHINFO_EXTENSION)];
    }

    public function prosesPengajuan(AuthContext $auth, int $idHist, string $aksi, ?string $reasonNote): array
    {
        return $this->approval()->proses($auth, $idHist, $aksi, $reasonNote);
    }

    private function approval(): ApprovalBiodata
    {
        return new ApprovalBiodata($this->koneksi(), $this->scope());
    }

    private static function mime(string $isi): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo === false ? false : finfo_buffer($finfo, $isi);

        if ($finfo !== false) {
            finfo_close($finfo);
        }

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{jalur: 'langsung'|'pengajuan', pegawai: array<string, mixed>, pengajuan: array<string, mixed>|null}
     */
    private function dalamTransaksi(BaseConnection $db, KolomBerkas $kolom, AuthContext $auth, int $role, string $nip, array $data, ?UploadedFile $foto): array
    {
        $tabelPegawai = $db->escapeIdentifiers($db->prefixTable('pegawai'));
        $pegawai      = $db->query("SELECT * FROM {$tabelPegawai} WHERE nip = ? FOR UPDATE", [$nip])->getRowArray();

        if (! is_array($pegawai)) {
            throw new NotFoundException('Pegawai tidak ditemukan.');
        }

        if ((int) $pegawai['status'] === 10) {
            throw new ForbiddenException('Data pegawai yang sudah dihapus tidak bisa diubah.');
        }

        $akun    = $db->table('pengguna')->select('id_pengguna, email')->where('nip', $nip)->get()->getRowArray();
        $hasil   = (new ValidasiBiodata($db))->periksa($role, $data, $pegawai, is_array($akun) ? $akun['email'] : null);
        $param   = $hasil['param'];
        $email   = $hasil['email'];
        $adaFoto = $foto instanceof UploadedFile && $foto->getError() !== UPLOAD_ERR_NO_FILE;

        if (! $adaFoto && (string) ($pegawai['foto'] ?? '') === '') {
            throw ValidationException::forField('foto', 'Foto pegawai wajib diunggah.');
        }

        $sekarang     = gmdate('Y-m-d H:i:s');
        $pegawaiModel = new PegawaiModel($db);
        $histModel    = new PegawaiHistModel($db);
        $aktif        = $db->table('pegawai_hist')->where('nip', $nip)->where('flag_update', PegawaiHistModel::FLAG_DIAJUKAN)
            ->orderBy('id_pegawai_hist', 'DESC')->get()->getRowArray();

        if ($role === Role::SUPER_ADMIN) {
            if ($adaFoto) {
                $param['foto'] = $kolom->simpan($foto, self::aturanFoto(), 'pegawai', 'foto', 'foto');
            }

            $param['flag_update'] = PegawaiHistModel::FLAG_TANPA;
            $param['updated_at']  = $sekarang;
            $param['updated_by']  = $auth->idPengguna();
            $pegawaiModel->withActor($auth, static fn () => $pegawaiModel->update($nip, $param));

            // B-05 [K] trigger pegawai_afUpd (D1:7975): status 1 → 2 menonaktifkan akun UserLevel 2 (PNS) + sesi dicabut.
            if ((int) $pegawai['status'] === 1 && (int) ($param['status'] ?? 1) === 2) {
                AkunPegawai::nonaktifkan($db, $auth, $nip, [Role::PEGAWAI], 'status pegawai 1 -> 2');
            }

            if (isset($param['foto']) && $db->table('pegawai_foto')->where('nip', $nip)->where('foto', $param['foto'])->countAllResults() === 0) {
                $fotoModel = new PegawaiFotoModel($db);
                $fotoModel->withActor($auth, static fn () => $fotoModel->insert(['nip' => $nip, 'foto' => $param['foto'], 'created_at' => $sekarang]));
            }

            if (is_array($aktif)) {
                // [K] :410-418 + trigger pegawai_hist_beUpd: tanpa alasan (keputusan user 13-10-2026).
                $histModel->withActor($auth, static fn () => $histModel->update((int) $aktif['id_pegawai_hist'], [
                    'flag_update' => PegawaiHistModel::FLAG_DITOLAK,
                    'show_notif'  => 1,
                    'notif_date'  => $sekarang,
                    'updated_at'  => $sekarang,
                    'updated_by'  => $auth->idPengguna(),
                ]));
            }

            $this->sinkronEmail($db, $auth, is_array($akun) ? $akun : null, $email);

            return ['jalur' => 'langsung', 'pegawai' => $this->baris($db, $nip), 'pengajuan' => null];
        }

        if ($adaFoto) {
            $param['foto'] = $kolom->simpan($foto, self::aturanFoto(), 'pegawai_hist', 'foto', 'foto');

            // Foto pengajuan aktif sebelumnya diganti: berkasnya dibuang setelah transaksi, kecuali masih foto pegawai
            // atau ada di galeri `pegawai_foto` (PenjagaBerkas hanya memeriksa pegawai_hist.foto).
            $fotoLama = is_array($aktif) ? (string) ($aktif['foto'] ?? '') : '';

            if ($fotoLama !== '' && $fotoLama !== (string) ($pegawai['foto'] ?? '')
                && $db->table('pegawai_foto')->where('foto', $fotoLama)->countAllResults() === 0) {
                $kolom->hapus($fotoLama, 'pegawai_hist', 'foto');
            }
        }

        $pegawaiModel->withActor($auth, static fn () => $pegawaiModel->update($nip, [
            'flag_update' => PegawaiHistModel::FLAG_DIAJUKAN,
            'updated_at'  => $sekarang,
            'updated_by'  => $auth->idPengguna(),
        ]));

        $kolomHist = array_flip($db->getFieldNames('pegawai_hist') ?: []);
        $pengajuan = array_intersect_key(array_merge($pegawai, $param), $kolomHist);
        unset($pengajuan['id_pegawai_hist'], $pengajuan['created_at']);
        $pengajuan = array_merge($pengajuan, [
            'nip'            => $nip,
            'email'          => $email,
            'flag_update'    => PegawaiHistModel::FLAG_DIAJUKAN,
            'approved_by'    => null,
            'reason_note'    => null,
            'show_notif'     => 0,
            'notif_date'     => null,
            'show_ua_upt'    => 0,
            'show_ua_deputi' => 0,
            'show_ua_biro'   => 0,
            'updated_by'     => $auth->idPengguna(),
            'updated_at'     => $sekarang,
        ]);

        if (is_array($aktif)) {
            $id = (int) $aktif['id_pegawai_hist'];
            $histModel->withActor($auth, static fn () => $histModel->update($id, $pengajuan));
        } else {
            $pengajuan['created_at'] = $sekarang;
            $id                      = (int) $histModel->withActor($auth, static fn () => $histModel->insert($pengajuan));
        }

        $hist = $db->table('pegawai_hist')
            ->select('id_pegawai_hist, flag_update, reason_note, created_at, updated_at, notif_date')
            ->where('id_pegawai_hist', $id)->get()->getRowArray();

        return ['jalur' => 'pengajuan', 'pegawai' => $this->baris($db, $nip), 'pengajuan' => is_array($hist) ? $hist : null];
    }

    /**
     * Email akun pegawai (role 1, jalur langsung) [K] :430-439; pegawai tanpa akun → tidak ada yang disinkron.
     *
     * @param array<string, mixed>|null $akun
     */
    private function sinkronEmail(BaseConnection $db, AuthContext $auth, ?array $akun, string $email): void
    {
        if ($akun === null || (string) ($akun['email'] ?? '') === $email) {
            return;
        }

        if ($db->table('pengguna')->where('id_pengguna', (int) $akun['id_pengguna'])->update(['email' => $email, 'updated_at' => gmdate('Y-m-d H:i:s')]) === false) {
            $error = $db->error();

            throw new DatabaseException('Sinkron email akun gagal: ' . $error['message'], (int) $error['code']);
        }

        (new AuditLogModel($db))->record(
            'pengguna',
            (int) $akun['id_pengguna'],
            AuditLogModel::EVENT_UPDATE,
            ['email' => $akun['email']],
            ['email' => $email],
            $auth->idPengguna(),
            $auth->nip(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function baris(BaseConnection $db, string $nip): array
    {
        $row             = (array) $db->table('pegawai')->where('nip', $nip)->get()->getRowArray();
        $row['ada_foto'] = (string) ($row['foto'] ?? '') !== '';

        return array_diff_key($row, array_flip(['foto', 'siasn_flag', 'siasn_lu', 'deleted_at']));
    }

    private function scope(): PegawaiScopeInterface
    {
        return $this->scope ?? service('pegawaiScope');
    }

    private function koneksi(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
