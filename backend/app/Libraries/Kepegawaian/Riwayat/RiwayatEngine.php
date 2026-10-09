<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Interfaces\Kepegawaian\RiwayatRegistryInterface;
use App\Interfaces\Kepegawaian\RiwayatServiceInterface;
use App\Interfaces\Kepegawaian\SnapshotSyncInterface;
use App\Libraries\Auth\AuthContext;
use App\Models\Kepegawaian\RiwayatModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\I18n\Time;
use Config\Kepegawaian;
use LogicException;
use Throwable;

/**
 * RiwayatEngine — mesin riwayat generik di balik `api/v1/pegawai/{nip}/riwayat/{jenis}` (WS-1 M1 MAKE-004; kontrak
 * RiwayatServiceInterface, README `Controllers/Api/Kepegawaian`). Tidak ada kode khusus per jenis: semua perilaku
 * dibaca dari RiwayatDefinisi (field, izin, alur, status, snapshot, lampiran, hook).
 *
 * Urutan pemeriksaan: jenis terdaftar (404) → izin Definisi × role (403) → PegawaiScope (403) → NIP ada (404) → baris
 * milik NIP (404) → validasi (422). Tulis riwayat + snapshot + lampiran dalam SATU transaksi; baris `pegawai` dikunci
 * (`FOR UPDATE`) di awal transaksi tulis sehingga tulisan paralel untuk pegawai yang sama berurutan dan pemilihan
 * snapshot tidak balapan. Sesudah kunci itu baris riwayat DIBACA ULANG (`FOR UPDATE`) dan guard kunci/status serta
 * status lama/baru dihitung dari hasil baca ulang; pemeriksaan sebelum transaksi hanya jalan cepat (tanpa TOCTOU).
 *
 * Status (ikut legacy, data di Definisi): tambah → RiwayatDefinisi::statusAwal(); ubah →
 * RiwayatDefinisi::statusSetelahUbah(); hapus → 10; proses → 1/2 hanya dari 0 (atau 3 bila flag Diproses aktif) —
 * penjagaan proses ini [V2], legacy tidak menjaganya.
 * Baris Disetujui terkunci untuk UL_PEGAWAI (ubah/hapus) bila kunciBarisDisetujui(), dan untuk hapus oleh
 * roleKunciHapusDisetujui() (default role 3).
 *
 * Snapshot: SnapshotSync dipanggil bila status lama ATAU baru = Disetujui (transisi masuk/keluar status 1, termasuk
 * ubah data baris yang sudah disetujui — setara trigger `afUpd` legacy). Pengajuan (status 0) dan penolakan baris yang
 * belum disetujui tidak menyentuh snapshot.
 *
 * Kolom notifikasi (setara trigger `<tabel>_beUpd` legacy, dok DBV-012 §5.2): transisi 0/3 → 1/2 mengisi
 * `show_notif = 1`, `notif_date` (UTC). Proses mengisi `approved_by`, `reason_note`, dan `show_ua_*` per role pemroses
 * (pola `L_kp.php:649-668`: role 1 → biro; role 3 ber-satker → upt, tanpa satker → deputi). Antrean notifikasi:
 * event `riwayatNotifikasi` (EVENT_NOTIFIKASI) dipicu SESUDAH commit — tipe 1 diajukan, 2 diproses, 3 diubah, sama
 * dengan `fcmQueue_rwy` legacy (`helpers/function_helper.php:588`); pengirim push (FCM, H-01) berlangganan event ini.
 */
final class RiwayatEngine implements RiwayatServiceInterface
{
    public const EVENT_NOTIFIKASI = 'riwayatNotifikasi';

    /**
     * Tipe notifikasi = `$type` fcmQueue_rwy legacy.
     */
    public const NOTIF_DIAJUKAN = 1;

    public const NOTIF_DIPROSES = 2;
    public const NOTIF_DIUBAH   = 3;

    public const AKSI_SETUJUI = 'setujui';
    public const AKSI_TOLAK   = 'tolak';

    /**
     * Batas `reason_note` (TINYTEXT, byte).
     */
    public const REASON_NOTE_MAX_BYTES = 255;

    /**
     * @var array<string, list<string>> tabel → kolom DDL (cache per proses)
     */
    private array $kolom = [];

    public function __construct(
        private readonly ?RiwayatRegistryInterface $registry = null,
        private readonly ?PegawaiScopeInterface $scope = null,
        private readonly ?SnapshotSyncInterface $snapshot = null,
        private readonly ?AttachmentServiceInterface $lampiran = null,
        private readonly ?BaseConnection $db = null,
    ) {
    }

    public function daftar(AuthContext $auth, string $nip, string $jenis): array
    {
        $d       = $this->siapkan($auth, $nip, $jenis, AksiRiwayat::Lihat);
        $builder = $this->db()->table($d->tabel())
            ->where($d->kolomNip(), $nip)
            ->groupStart()
            ->where($d->kolomStatus() . ' !=', $this->nilaiStatus($d, StatusRiwayat::Dihapus))
            ->orWhere($d->kolomStatus(), null)
            ->groupEnd();

        foreach ($d->urutanDaftar() as $kolom => $arah) {
            $builder->orderBy($kolom, $arah);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $builder->get()->getResultArray();

        return $rows;
    }

    public function detail(AuthContext $auth, string $nip, string $jenis, int $id): array
    {
        $d = $this->siapkan($auth, $nip, $jenis, AksiRiwayat::Lihat);

        return $this->baris($d, $nip, $id);
    }

    public function tambah(AuthContext $auth, string $nip, string $jenis, array $data, array $berkas = []): array
    {
        $d    = $this->siapkan($auth, $nip, $jenis, AksiRiwayat::Tambah);
        $role = (int) $auth->role();

        $data = $this->validasi($d, $auth, $data, null);
        $this->periksaBerkas($d, $nip, null, $berkas);
        $status = $d->statusAwal($role);

        $row = $this->transaksi(function () use ($d, $auth, $nip, $data, $berkas, $status): array {
            $this->kunciPegawai($nip);

            $data = $d->beforeSave($data, null, $auth);
            unset($data[$d->primaryKey()]);
            $data[$d->kolomNip()]    = $nip;
            $data[$d->kolomStatus()] = $this->nilaiStatus($d, $status);
            $data                    = $this->stempelPelaku($d, $auth, $data);

            $model = $this->model($d);
            $id    = (int) $model->withActor($auth, static fn () => $model->insert($data));

            $this->simpanBerkas($d, $nip, $id, $berkas);

            if ($status === StatusRiwayat::Disetujui) {
                $this->snapshot()->sinkronkan($d, $nip, $auth);
                $d->afterApprove($this->baris($d, $nip, $id), $auth);
            }

            return $this->baris($d, $nip, $id);
        });

        if ($status === StatusRiwayat::Menunggu) {
            $this->notifikasi(self::NOTIF_DIAJUKAN, $d, $row);
        }

        return $row;
    }

    public function ubah(AuthContext $auth, string $nip, string $jenis, int $id, array $data, array $berkas = []): array
    {
        $d    = $this->siapkan($auth, $nip, $jenis, AksiRiwayat::Ubah);
        $role = (int) $auth->role();
        $lama = $this->baris($d, $nip, $id);

        // Jalan cepat sebelum validasi; diulang di dalam transaksi atas baris yang dibaca ulang (terkunci).
        $this->jagaUbah($d, $role, $this->statusDari($d, $lama));

        $data = $this->validasi($d, $auth, $data, $lama);
        $this->periksaBerkas($d, $nip, $id, $berkas);

        [$row, $statusBaru] = $this->transaksi(function () use ($d, $auth, $role, $nip, $id, $data, $berkas): array {
            $this->kunciPegawai($nip);

            // Baca ulang SESUDAH kunci: guard dan status baru dihitung dari keadaan terkini, bukan hasil baca sebelum
            // transaksi (ubah/proses/hapus paralel tidak bisa menyelinap di antaranya).
            $lama       = $this->barisTerkunci($d, $nip, $id);
            $statusLama = $this->statusDari($d, $lama);
            $this->jagaUbah($d, $role, $statusLama);
            $statusBaru = $d->statusSetelahUbah($role, $statusLama);

            $data = $d->beforeSave($data, $lama, $auth);
            unset($data[$d->primaryKey()], $data[$d->kolomNip()], $data[$d->kolomStatus()]);

            if ($statusBaru !== null && $statusBaru !== $statusLama) {
                $data[$d->kolomStatus()] = $this->nilaiStatus($d, $statusBaru);
                $data                    = $this->tandaiNotifikasi($d, $statusLama, $statusBaru, $data);
            }

            $data  = $this->stempelPelaku($d, $auth, $data);
            $model = $this->model($d);

            if ($data !== []) {
                $model->withActor($auth, static fn () => $model->update($id, $data));
            }

            $this->simpanBerkas($d, $nip, $id, $berkas);

            if ($statusLama === StatusRiwayat::Disetujui || $statusBaru === StatusRiwayat::Disetujui) {
                $this->snapshot()->sinkronkan($d, $nip, $auth);
            }

            if ($statusBaru === StatusRiwayat::Disetujui && $statusLama !== StatusRiwayat::Disetujui) {
                $d->afterApprove($this->baris($d, $nip, $id), $auth);
            }

            return [$this->baris($d, $nip, $id), $statusBaru];
        });

        if ($statusBaru === StatusRiwayat::Menunggu) {
            $this->notifikasi(self::NOTIF_DIUBAH, $d, $row);
        }

        return $row;
    }

    public function hapus(AuthContext $auth, string $nip, string $jenis, int $id): void
    {
        $d    = $this->siapkan($auth, $nip, $jenis, AksiRiwayat::Hapus);
        $role = (int) $auth->role();

        $this->jagaHapus($d, $role, $this->statusDari($d, $this->baris($d, $nip, $id)));

        $this->transaksi(function () use ($d, $auth, $role, $nip, $id): void {
            $this->kunciPegawai($nip);

            $statusLama = $this->statusDari($d, $this->barisTerkunci($d, $nip, $id));
            $this->jagaHapus($d, $role, $statusLama);

            $data  = $this->stempelPelaku($d, $auth, [$d->kolomStatus() => $this->nilaiStatus($d, StatusRiwayat::Dihapus)]);
            $model = $this->model($d);
            $model->withActor($auth, static fn () => $model->hapusLunak($id, $data));

            if ($statusLama === StatusRiwayat::Disetujui) {
                $this->snapshot()->sinkronkan($d, $nip, $auth);
            }
        });
    }

    public function proses(AuthContext $auth, string $nip, string $jenis, int $id, string $aksi, ?string $reasonNote): array
    {
        $d = $this->siapkan($auth, $nip, $jenis, AksiRiwayat::Proses);

        // Urutan kontrak: baris milik NIP (404) lebih dulu, baru validasi isi request (422).
        $statusLama = $this->statusDari($d, $this->baris($d, $nip, $id));

        if (! in_array($aksi, [self::AKSI_SETUJUI, self::AKSI_TOLAK], true)) {
            throw ValidationException::forField('aksi', 'Aksi harus setujui atau tolak.');
        }

        $this->jagaProses($statusLama);

        $catatan = trim((string) $reasonNote);

        if ($aksi === self::AKSI_TOLAK && $catatan === '') {
            throw ValidationException::forField('reason_note', 'Alasan penolakan wajib diisi.');
        }

        if (strlen($catatan) > self::REASON_NOTE_MAX_BYTES) {
            throw ValidationException::forField('reason_note', 'Alasan maksimal ' . self::REASON_NOTE_MAX_BYTES . ' byte.');
        }

        $statusBaru = $aksi === self::AKSI_SETUJUI ? StatusRiwayat::Disetujui : StatusRiwayat::Ditolak;

        $row = $this->transaksi(function () use ($d, $auth, $nip, $id, $catatan, $statusBaru): array {
            $this->kunciPegawai($nip);

            $statusLama = $this->statusDari($d, $this->barisTerkunci($d, $nip, $id));
            $this->jagaProses($statusLama);

            $data = $this->tandaiNotifikasi($d, $statusLama, $statusBaru, [
                $d->kolomStatus() => $this->nilaiStatus($d, $statusBaru),
                'reason_note'     => $catatan === '' ? null : $catatan,
                'approved_by'     => $auth->idPengguna(),
            ]);

            foreach ($this->kolomUa($auth) as $kolom) {
                $data[$kolom] = 1;
            }

            $data  = $this->stempelPelaku($d, $auth, $this->saringKolom($d, $data));
            $model = $this->model($d);
            $model->withActor($auth, static fn () => $model->update($id, $data));

            if ($statusBaru === StatusRiwayat::Disetujui) {
                $this->snapshot()->sinkronkan($d, $nip, $auth);
                $d->afterApprove($this->baris($d, $nip, $id), $auth);
            }

            return $this->baris($d, $nip, $id);
        });

        $this->notifikasi(self::NOTIF_DIPROSES, $d, $row);

        return $row;
    }

    // ------------------------------------------------------------------
    // Pemeriksaan
    // ------------------------------------------------------------------

    private function siapkan(AuthContext $auth, string $nip, string $jenis, AksiRiwayat $aksi): RiwayatDefinisi
    {
        $d = $this->registry()->definisi($jenis) ?? throw new NotFoundException('Jenis riwayat tidak ditemukan.');

        if (! $d->boleh($aksi, $auth->role())) {
            throw new ForbiddenException('Anda tidak berhak atas aksi ini pada ' . $d->label() . '.');
        }

        $scope = $this->scope();
        $boleh = $aksi->mengubah() ? $scope->bolehUbah($auth, $nip) : $scope->bolehLihat($auth, $nip);

        if (! $boleh) {
            throw new ForbiddenException('Anda tidak berhak mengakses data pegawai ini.');
        }

        if ($this->db()->table('pegawai')->where('nip', $nip)->countAllResults() === 0) {
            throw new NotFoundException('Pegawai tidak ditemukan.');
        }

        return $d;
    }

    /**
     * Baris ber-PK $id milik $nip yang belum dihapus (status 10 = 404).
     *
     * @return array<string, mixed>
     */
    private function baris(RiwayatDefinisi $d, string $nip, int $id): array
    {
        $row = $this->model($d)->milik($nip, $id);

        if ($row === null || $this->statusDari($d, $row) === StatusRiwayat::Dihapus) {
            throw new NotFoundException($d->label() . ' tidak ditemukan.');
        }

        return $row;
    }

    /**
     * Baris $id milik $nip dibaca `FOR UPDATE` di dalam transaksi tulis (sesudah kunciPegawai()), sehingga guard dan
     * status lama memakai keadaan terkini. Status 10 = 404.
     *
     * @return array<string, mixed>
     */
    private function barisTerkunci(RiwayatDefinisi $d, string $nip, int $id): array
    {
        $db = $this->db();

        /** @var array<string, mixed>|null $row */
        $row = $db->query(
            'SELECT * FROM ' . $db->protectIdentifiers($d->tabel(), true)
            . ' WHERE ' . $db->protectIdentifiers($d->primaryKey()) . ' = ? AND ' . $db->protectIdentifiers($d->kolomNip()) . ' = ? FOR UPDATE',
            [$id, $nip],
        )->getRowArray();

        if ($row === null || $this->statusDari($d, $row) === StatusRiwayat::Dihapus) {
            throw new NotFoundException($d->label() . ' tidak ditemukan.');
        }

        return $row;
    }

    /**
     * Baris Disetujui terkunci untuk UL_PEGAWAI (bila kunciBarisDisetujui()) → 403.
     */
    private function jagaUbah(RiwayatDefinisi $d, int $role, ?StatusRiwayat $status): void
    {
        if ($status === StatusRiwayat::Disetujui && $d->kunciBarisDisetujui() && in_array($role, Role::UL_PEGAWAI, true)) {
            throw new ForbiddenException('Data yang sudah disetujui tidak dapat diubah.');
        }
    }

    /**
     * Baris Disetujui tidak boleh dihapus UL_PEGAWAI (bila kunciBarisDisetujui()) dan roleKunciHapusDisetujui() → 403.
     */
    private function jagaHapus(RiwayatDefinisi $d, int $role, ?StatusRiwayat $status): void
    {
        if ($status === StatusRiwayat::Disetujui
            && (($d->kunciBarisDisetujui() && in_array($role, Role::UL_PEGAWAI, true)) || in_array($role, $d->roleKunciHapusDisetujui(), true))) {
            throw new ForbiddenException('Data yang sudah disetujui tidak dapat dihapus.');
        }
    }

    /**
     * [V2] Hanya baris 0 Menunggu (atau 3 bila flag Diproses aktif) yang dapat diproses → 422 `errors.status`; legacy
     * tidak menjaga ini.
     */
    private function jagaProses(?StatusRiwayat $status): void
    {
        if (! in_array($status, [StatusRiwayat::Menunggu, StatusRiwayat::Diproses], true)) {
            throw ValidationException::forField('status', 'Hanya data berstatus Menunggu yang dapat diproses.');
        }
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $lama
     *
     * @return array<string, mixed>
     */
    private function validasi(RiwayatDefinisi $d, AuthContext $auth, array $data, ?array $lama): array
    {
        [$bersih, $errors] = (new ValidasiRiwayat(service('masterRegistry'), $this->db()))->periksa($d, $data, $lama);

        if ($errors === []) {
            $errors = $d->validate($bersih, $lama, $auth);
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        return $bersih;
    }

    /**
     * Kode `berkas[<id_riwayat>]` harus milik Definisi; setiap AturanLampiran ber-`wajib` harus punya berkas di request
     * ini atau (saat ubah) lampiran yang sudah ada.
     *
     * @param array<int, UploadedFile> $berkas
     */
    private function periksaBerkas(RiwayatDefinisi $d, string $nip, ?int $id, array $berkas): void
    {
        $aturan = [];

        foreach ($d->lampiran() as $a) {
            $aturan[$a->idRiwayat] = $a;
        }

        $errors = [];

        foreach (array_keys($berkas) as $kode) {
            if (! isset($aturan[$kode])) {
                $errors["berkas.{$kode}"] = ['Jenis lampiran tidak dikenal untuk ' . $d->label() . '.'];
            }
        }

        foreach ($aturan as $kode => $a) {
            if ($a->wajib && ! isset($berkas[$kode]) && ($id === null || $this->lampiran()->daftar($nip, $kode, $id) === [])) {
                $errors["berkas.{$kode}"] = ['Lampiran wajib diunggah.'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }
    }

    // ------------------------------------------------------------------
    // Tulis
    // ------------------------------------------------------------------

    /**
     * @param array<int, UploadedFile> $berkas
     */
    private function simpanBerkas(RiwayatDefinisi $d, string $nip, int $id, array $berkas): void
    {
        $aturan = [];

        foreach ($d->lampiran() as $a) {
            $aturan[$a->idRiwayat] = $a;
        }

        foreach ($berkas as $kode => $file) {
            try {
                $this->lampiran()->simpan($nip, $kode, $id, $file, $aturan[$kode]);
            } catch (ValidationException $e) {
                // Kontrak endpoint riwayat: error berkas berkunci `berkas.<id_riwayat>`.
                $errors = $e->getErrors() ?? [];
                $pesan  = $errors['berkas'] ?? [$e->getMessage()];

                throw new ValidationException($e->getMessage(), ["berkas.{$kode}" => is_array($pesan) ? array_values($pesan) : [$pesan]]);
            }
        }
    }

    private function kunciPegawai(string $nip): void
    {
        $db = $this->db();
        $db->query('SELECT ' . $db->protectIdentifiers('nip') . ' FROM ' . $db->protectIdentifiers('pegawai', true) . ' WHERE ' . $db->protectIdentifiers('nip') . ' = ? FOR UPDATE', [$nip]);
    }

    /**
     * Kolom pelaku/waktu yang ada di tabel: `updated_by` = id_pengguna pemanggil.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function stempelPelaku(RiwayatDefinisi $d, AuthContext $auth, array $data): array
    {
        if ($this->punyaKolom($d, 'updated_by')) {
            $data['updated_by'] = $auth->idPengguna();
        }

        return $data;
    }

    /**
     * Setara trigger `<tabel>_beUpd` legacy: status 0 (atau 3) → 1/2 mengisi `show_notif = 1`, `notif_date` (UTC).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function tandaiNotifikasi(RiwayatDefinisi $d, ?StatusRiwayat $lama, StatusRiwayat $baru, array $data): array
    {
        if (in_array($lama, [StatusRiwayat::Menunggu, StatusRiwayat::Diproses], true)
            && in_array($baru, [StatusRiwayat::Disetujui, StatusRiwayat::Ditolak], true)) {
            $data['show_notif'] = 1;
            $data['notif_date'] = Time::now()->toDateTimeString();
        }

        return $this->saringKolom($d, $data);
    }

    /**
     * Kolom `show_ua_*` pemroses (legacy `L_kp.php:656-666`).
     *
     * @return list<string>
     */
    private function kolomUa(AuthContext $auth): array
    {
        return match ($auth->role()) {
            Role::SUPER_ADMIN  => ['show_ua_biro'],
            Role::ADMIN_SATKER => [($auth->idSatker() ?? '') !== '' ? 'show_ua_upt' : 'show_ua_deputi'],
            default            => [],
        };
    }

    /**
     * Buang kolom sistem yang tidak ada di tabel jenis ini.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function saringKolom(RiwayatDefinisi $d, array $data): array
    {
        foreach (array_keys($data) as $kolom) {
            if (! $this->punyaKolom($d, $kolom)) {
                unset($data[$kolom]);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function notifikasi(int $tipe, RiwayatDefinisi $d, array $row): void
    {
        $status = $this->statusDari($d, $row);

        try {
            Events::trigger(self::EVENT_NOTIFIKASI, [
                'tipe'        => $tipe,
                'jenis'       => $d->jenis(),
                'label'       => $d->label(),
                'nip'         => (string) $row[$d->kolomNip()],
                'id'          => $row[$d->primaryKey()],
                'status'      => $status?->value,
                'aksi'        => $tipe === self::NOTIF_DIPROSES ? $status?->label() : null,
                'reason_note' => $row['reason_note'] ?? null,
            ]);
        } catch (Throwable $e) {
            // Notifikasi sesudah commit tidak boleh menggagalkan respons (data sudah tersimpan).
            log_message('error', '[riwayat] notifikasi {jenis}#{id} gagal: {msg}', [
                'jenis' => $d->jenis(),
                'id'    => (string) $row[$d->primaryKey()],
                'msg'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Jalankan $work dalam satu transaksi; sesudah commit/rollback berkas lampiran direkonsiliasi (MAKE-009
     * AttachmentService::selesaikan(), bila implementasinya punya).
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function transaksi(Closure $work): mixed
    {
        $db = $this->db();
        $db->transBegin();

        try {
            $hasil = $work();
        } catch (Throwable $e) {
            $db->transRollback();
            $db->resetTransStatus();
            $this->selesaikanLampiran();

            throw $e;
        }

        if ($db->transStatus() === false) {
            $error = $db->error();
            $db->transRollback();
            $db->resetTransStatus();
            $this->selesaikanLampiran();

            throw new DatabaseException('Penulisan riwayat gagal: ' . $error['message'], (int) $error['code']);
        }

        $db->transCommit();
        $db->resetTransStatus();
        $this->selesaikanLampiran();

        return $hasil;
    }

    private function selesaikanLampiran(): void
    {
        $lampiran = $this->lampiran();

        if (method_exists($lampiran, 'selesaikan')) {
            $lampiran->selesaikan();
        }
    }

    // ------------------------------------------------------------------
    // Status & skema
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     */
    private function statusDari(RiwayatDefinisi $d, array $row): ?StatusRiwayat
    {
        $nilai = $row[$d->kolomStatus()] ?? null;

        if ($nilai === null || (is_string($nilai) && ! ctype_digit($nilai))) {
            return null;
        }

        return $d->pemetaanStatus()[(int) $nilai] ?? null;
    }

    private function nilaiStatus(RiwayatDefinisi $d, StatusRiwayat $status): int
    {
        $nilai = array_search($status, $d->pemetaanStatus(), true);

        if ($nilai === false) {
            throw new LogicException("Definisi {$d->jenis()}: status {$status->label()} tidak ada di pemetaanStatus().");
        }

        if ($status === StatusRiwayat::Diproses && ! config(Kepegawaian::class)->statusDiprosesAktif) {
            throw new LogicException('Status Diproses nonaktif (Config\Kepegawaian::$statusDiprosesAktif).');
        }

        return $nilai;
    }

    private function punyaKolom(RiwayatDefinisi $d, string $kolom): bool
    {
        return in_array($kolom, $this->kolomTabel($d->tabel()), true);
    }

    /**
     * @return list<string>
     */
    private function kolomTabel(string $tabel): array
    {
        return $this->kolom[$tabel] ??= array_values($this->db()->getFieldNames($tabel));
    }

    private function model(RiwayatDefinisi $d): RiwayatModel
    {
        return new RiwayatModel($d, $this->kolomTabel($d->tabel()), $this->db());
    }

    // ------------------------------------------------------------------
    // Dependensi (di-resolve saat dipakai agar Services::injectMock berlaku untuk engine shared)
    // ------------------------------------------------------------------

    private function registry(): RiwayatRegistryInterface
    {
        return $this->registry ?? service('riwayatRegistry');
    }

    private function scope(): PegawaiScopeInterface
    {
        return $this->scope ?? service('pegawaiScope');
    }

    private function snapshot(): SnapshotSyncInterface
    {
        return $this->snapshot ?? service('snapshotSync');
    }

    private function lampiran(): AttachmentServiceInterface
    {
        return $this->lampiran ?? service('attachmentService');
    }

    private function db(): BaseConnection
    {
        /** @var BaseConnection $db */
        $db = $this->db ?? db_connect();

        return $db;
    }
}
