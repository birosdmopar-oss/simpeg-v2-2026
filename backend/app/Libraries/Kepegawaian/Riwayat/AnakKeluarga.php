<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Keluarga;
use App\Libraries\MasterData\MasterField;
use App\Models\Kepegawaian\RiwayatModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\Files\UploadedFile;
use Throwable;

/**
 * Sub-resource anak riwayat keluarga `pegawai/{nip}/riwayat/keluarga/{id}/anak[/{idAnak}]` (WS-1 M4 MAKE-007, aditif;
 * keputusan reviewer CR 12-10-2026). Tabel `detail_anak` (tanpa status) milik baris `riwayat_keluarga`.
 *
 * Pemeriksaan sama dengan RiwayatEngine: Definisi `keluarga` terdaftar (404) → izin role (`lihat` untuk baca, `ubah`
 * untuk tulis — legacy add/edit/delete anak lewat form edit keluarga, userAuth 1, 2, 3, 7) (403) → PegawaiScope
 * `bolehLihat`/`bolehUbah` (403) → NIP ada (404) → `bolehAksiUntuk` (403) → baris keluarga milik NIP, bukan status 10
 * (404) → baris anak milik keluarga itu (404). Tulis: keluarga Disetujui terkunci untuk UL_PEGAWAI (403); hapus anak
 * oleh `roleKunciHapusDisetujui()` (role 3) pada keluarga Disetujui → 403 (legacy `Keluarga.php:504`).
 *
 * Satu transaksi (kunci pegawai + baris keluarga `FOR UPDATE`): tulis anak + akte (kode 3, `id_entri` = id anak) +
 * `jumlah_anak` dihitung ulang + UL_PEGAWAI mengembalikan keluarga ke status 0 (legacy: anak berubah lewat edit
 * keluarga → status 0) + snapshot `pegawai_keluarga` disinkron bila keluarga Disetujui. Akte wajib saat tambah dan saat
 * ubah bila anak belum punya akte (legacy `vp_anak()`). Hapus anak = hapus keras baris + aktenya (legacy; tabel tanpa
 * status). Audit lewat RiwayatModel (entity `detail_anak`).
 */
final class AnakKeluarga
{
    public const TABEL = 'detail_anak';
    public const PK    = 'id_detail_anak';

    /**
     * Keluarga yang diajukan ulang UL_PEGAWAI di transaksi berjalan: notifikasi "diubah" dipicu sesudah commit.
     *
     * @var array{0: RiwayatDefinisi, 1: string, 2: int}|null
     */
    private ?array $notifTertunda = null;

    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function daftar(AuthContext $auth, string $nip, int $idKeluarga): array
    {
        $this->siapkan($auth, $nip, $idKeluarga, AksiRiwayat::Lihat);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->koneksi()->table(self::TABEL)->where('id_riwayat_keluarga', $idKeluarga)->orderBy('urutan', 'ASC')->orderBy(self::PK, 'ASC')->get()->getResultArray();

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function tambah(AuthContext $auth, string $nip, int $idKeluarga, array $data, ?UploadedFile $akte): array
    {
        [$d, $induk] = $this->siapkan($auth, $nip, $idKeluarga, AksiRiwayat::Ubah);
        $this->jagaTulis($d, $auth, $induk, false);
        $bersih = $this->validasi($data, null);

        if ($akte === null) {
            throw ValidationException::forField('berkas', 'Akte lahir wajib diunggah.');
        }

        return $this->transaksi(function () use ($d, $auth, $nip, $idKeluarga, $bersih, $akte): array {
            $this->jagaTulis($d, $auth, $this->kunciInduk($d, $nip, $idKeluarga), false);

            $model = $this->model();
            $id    = (int) $model->withActor($auth, static fn () => $model->insert($bersih + ['id_riwayat_keluarga' => $idKeluarga]));
            $this->simpanAkte($d, $nip, $id, $akte);
            $this->perbaruiInduk($d, $auth, $nip, $idKeluarga);

            return $this->anak($idKeluarga, $id);
        });
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function ubah(AuthContext $auth, string $nip, int $idKeluarga, int $idAnak, array $data, ?UploadedFile $akte): array
    {
        [$d, $induk] = $this->siapkan($auth, $nip, $idKeluarga, AksiRiwayat::Ubah);
        $lama        = $this->anak($idKeluarga, $idAnak);
        $this->jagaTulis($d, $auth, $induk, false);
        $bersih = $this->validasi($data, $lama);

        if ($akte === null && $this->lampiran()->daftar($nip, Keluarga::LAMPIRAN_AKTE_ANAK, $idAnak) === []) {
            throw ValidationException::forField('berkas', 'Akte lahir wajib diunggah.');
        }

        return $this->transaksi(function () use ($d, $auth, $nip, $idKeluarga, $idAnak, $bersih, $akte): array {
            $this->jagaTulis($d, $auth, $this->kunciInduk($d, $nip, $idKeluarga), false);
            $this->anak($idKeluarga, $idAnak);

            if ($bersih !== []) {
                $model = $this->model();
                $model->withActor($auth, static fn () => $model->update($idAnak, $bersih));
            }

            if ($akte !== null) {
                $this->simpanAkte($d, $nip, $idAnak, $akte);
            }

            $this->perbaruiInduk($d, $auth, $nip, $idKeluarga);

            return $this->anak($idKeluarga, $idAnak);
        });
    }

    public function hapus(AuthContext $auth, string $nip, int $idKeluarga, int $idAnak): void
    {
        [$d, $induk] = $this->siapkan($auth, $nip, $idKeluarga, AksiRiwayat::Ubah);
        $this->anak($idKeluarga, $idAnak);
        $this->jagaTulis($d, $auth, $induk, true);

        $this->transaksi(function () use ($d, $auth, $nip, $idKeluarga, $idAnak): void {
            $this->jagaTulis($d, $auth, $this->kunciInduk($d, $nip, $idKeluarga), true);
            $this->anak($idKeluarga, $idAnak);

            foreach ($this->lampiran()->daftar($nip, Keluarga::LAMPIRAN_AKTE_ANAK, $idAnak) as $akte) {
                $this->lampiran()->hapus($nip, (int) $akte['id_attachment']);
            }

            $model = $this->model();
            $model->withActor($auth, static fn () => $model->delete($idAnak));
            $this->perbaruiInduk($d, $auth, $nip, $idKeluarga);
        });
    }

    /**
     * Definisi pseudo isian anak (kolom DDL `detail_anak`) untuk ValidasiRiwayat & RiwayatModel; bukan jenis registry.
     */
    public static function definisiAnak(): RiwayatDefinisi
    {
        return new class () extends RiwayatDefinisi {
            public function jenis(): string
            {
                return 'keluarga';
            }

            public function label(): string
            {
                return 'Anak';
            }

            public function tabel(): string
            {
                return AnakKeluarga::TABEL;
            }

            public function primaryKey(): string
            {
                return AnakKeluarga::PK;
            }

            public function fields(): array
            {
                return [
                    new MasterField('urutan', 'Anak Ke-', MasterField::TYPE_INT, required: true, columnType: 'tinyint', min: 1),
                    new MasterField('nama', 'Nama Anak', required: true, maxBytes: 255),
                    new MasterField('tempat_lahir', 'Tempat Lahir', required: true, maxBytes: 255),
                    new MasterField('tgl_lahir', 'Tanggal Lahir', MasterField::TYPE_DATE, required: true),
                    new MasterField('keterangan', 'Keterangan', MasterField::TYPE_TEXTAREA, maxBytes: 255),
                ];
            }

            public function izin(): array
            {
                return [];
            }

            public function alur(): AlurRiwayat
            {
                return AlurRiwayat::SelfService;
            }
        };
    }

    /**
     * @return array{0: RiwayatDefinisi, 1: array<string, mixed>} [Definisi keluarga, baris keluarga]
     */
    private function siapkan(AuthContext $auth, string $nip, int $idKeluarga, AksiRiwayat $aksi): array
    {
        $d = service('riwayatRegistry')->definisi('keluarga') ?? throw new NotFoundException('Jenis riwayat tidak ditemukan.');

        if (! $d->boleh($aksi, $auth->role())) {
            throw new ForbiddenException('Anda tidak berhak atas aksi ini pada ' . $d->label() . '.');
        }

        $scope = service('pegawaiScope');

        if (! ($aksi->mengubah() ? $scope->bolehUbah($auth, $nip) : $scope->bolehLihat($auth, $nip))) {
            throw new ForbiddenException('Anda tidak berhak mengakses data pegawai ini.');
        }

        if ($this->koneksi()->table('pegawai')->where('nip', $nip)->countAllResults() === 0) {
            throw new NotFoundException('Pegawai tidak ditemukan.');
        }

        if (! $d->bolehAksiUntuk($auth, $aksi, $nip)) {
            throw new ForbiddenException('Anda tidak berhak atas aksi ini untuk pegawai ini.');
        }

        return [$d, $this->induk($d, $nip, $idKeluarga, false)];
    }

    /**
     * @return array<string, mixed>
     */
    private function induk(RiwayatDefinisi $d, string $nip, int $idKeluarga, bool $kunci): array
    {
        $db = $this->koneksi();

        /** @var array<string, mixed>|null $row */
        $row = $db->query(
            'SELECT * FROM ' . $db->protectIdentifiers($d->tabel(), true)
            . ' WHERE ' . $db->protectIdentifiers($d->primaryKey()) . ' = ? AND ' . $db->protectIdentifiers($d->kolomNip()) . ' = ?'
            . ($kunci ? ' FOR UPDATE' : ''),
            [$idKeluarga, $nip],
        )->getRowArray();

        if ($row === null || $this->status($d, $row) === StatusRiwayat::Dihapus) {
            throw new NotFoundException($d->label() . ' tidak ditemukan.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function kunciInduk(RiwayatDefinisi $d, string $nip, int $idKeluarga): array
    {
        $db = $this->koneksi();
        $db->query('SELECT ' . $db->protectIdentifiers('nip') . ' FROM ' . $db->protectIdentifiers('pegawai', true) . ' WHERE ' . $db->protectIdentifiers('nip') . ' = ? FOR UPDATE', [$nip]);

        return $this->induk($d, $nip, $idKeluarga, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function anak(int $idKeluarga, int $idAnak): array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->koneksi()->table(self::TABEL)->where(self::PK, $idAnak)->where('id_riwayat_keluarga', $idKeluarga)->get()->getRowArray();

        return $row ?? throw new NotFoundException('Data anak tidak ditemukan.');
    }

    /**
     * Keluarga Disetujui terkunci untuk UL_PEGAWAI (kunciBarisDisetujui) dan, untuk hapus anak, role
     * roleKunciHapusDisetujui() → 403.
     *
     * @param array<string, mixed> $induk
     */
    private function jagaTulis(RiwayatDefinisi $d, AuthContext $auth, array $induk, bool $hapus): void
    {
        if ($this->status($d, $induk) !== StatusRiwayat::Disetujui) {
            return;
        }

        $role = (int) $auth->role();

        if (($d->kunciBarisDisetujui() && in_array($role, Role::UL_PEGAWAI, true)) || ($hapus && in_array($role, $d->roleKunciHapusDisetujui(), true))) {
            throw new ForbiddenException('Data keluarga yang sudah disetujui tidak dapat diubah.');
        }
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $lama
     *
     * @return array<string, mixed>
     */
    private function validasi(array $data, ?array $lama): array
    {
        [$bersih, $errors] = (new ValidasiRiwayat(service('masterRegistry'), $this->koneksi()))->periksa(self::definisiAnak(), $data, $lama);

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        return $bersih;
    }

    private function simpanAkte(RiwayatDefinisi $d, string $nip, int $idAnak, UploadedFile $akte): void
    {
        $aturan = null;

        foreach ($d->lampiran() as $a) {
            $aturan = $a->idRiwayat === Keluarga::LAMPIRAN_AKTE_ANAK ? $a : $aturan;
        }

        $this->lampiran()->simpan($nip, Keluarga::LAMPIRAN_AKTE_ANAK, $idAnak, $akte, $aturan ?? throw new NotFoundException('Jenis lampiran tidak ditemukan.'));
    }

    /**
     * `jumlah_anak` dihitung ulang; UL_PEGAWAI → keluarga kembali status 0; snapshot bila keluarga Disetujui.
     */
    private function perbaruiInduk(RiwayatDefinisi $d, AuthContext $auth, string $nip, int $idKeluarga): void
    {
        $induk = $this->induk($d, $nip, $idKeluarga, true);
        $data  = ['jumlah_anak' => Keluarga::hitungAnak($idKeluarga), 'updated_by' => $auth->idPengguna()];

        if (in_array((int) $auth->role(), Role::UL_PEGAWAI, true)) {
            if ($this->status($d, $induk) !== StatusRiwayat::Menunggu) {
                $data[$d->kolomStatus()] = StatusRiwayat::Menunggu->value;
            }

            // Sama dengan RiwayatEngine::ubah(): status baru Menunggu → notifikasi NOTIF_DIUBAH sesudah commit.
            $this->notifTertunda = [$d, $nip, $idKeluarga];
        }

        $model = new RiwayatModel($d, $this->koneksi()->getFieldNames($d->tabel()), $this->koneksi());
        $model->withActor($auth, static fn () => $model->update($idKeluarga, $data));

        if ($this->status($d, $induk) === StatusRiwayat::Disetujui) {
            service('snapshotSync')->sinkronkan($d, $nip, $auth);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function status(RiwayatDefinisi $d, array $row): ?StatusRiwayat
    {
        $nilai = $row[$d->kolomStatus()] ?? null;

        return $nilai === null ? null : ($d->pemetaanStatus()[(int) $nilai] ?? null);
    }

    private function model(): RiwayatModel
    {
        return new RiwayatModel(self::definisiAnak(), $this->koneksi()->getFieldNames(self::TABEL), $this->koneksi());
    }

    private function lampiran(): AttachmentServiceInterface
    {
        return service('attachmentService');
    }

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function transaksi(Closure $work): mixed
    {
        $db                  = $this->koneksi();
        $this->notifTertunda = null;
        $db->transBegin();

        try {
            $hasil = $work();
        } catch (Throwable $e) {
            $db->transRollback();
            $db->resetTransStatus();
            $this->selesaikanLampiran();
            $this->notifTertunda = null;

            throw $e;
        }

        if ($db->transStatus() === false) {
            $error = $db->error();
            $db->transRollback();
            $db->resetTransStatus();
            $this->selesaikanLampiran();
            $this->notifTertunda = null;

            throw new DatabaseException('Penulisan data anak gagal: ' . $error['message'], (int) $error['code']);
        }

        $db->transCommit();
        $db->resetTransStatus();
        $this->selesaikanLampiran();
        $this->kirimNotifikasi();

        return $hasil;
    }

    /**
     * Event `riwayatNotifikasi` tipe NOTIF_DIUBAH untuk keluarga yang diajukan ulang (payload sama dengan
     * RiwayatEngine::notifikasi()); kegagalan pendengar tidak menggagalkan respons (data sudah tersimpan).
     */
    private function kirimNotifikasi(): void
    {
        if ($this->notifTertunda === null) {
            return;
        }

        [$d, $nip, $idKeluarga] = $this->notifTertunda;
        $this->notifTertunda    = null;

        try {
            $row = $this->induk($d, $nip, $idKeluarga, false);
            Events::trigger(RiwayatEngine::EVENT_NOTIFIKASI, [
                'tipe'        => RiwayatEngine::NOTIF_DIUBAH,
                'jenis'       => $d->jenis(),
                'label'       => $d->label(),
                'nip'         => $nip,
                'id'          => $row[$d->primaryKey()],
                'status'      => $this->status($d, $row)?->value,
                'aksi'        => null,
                'reason_note' => $row['reason_note'] ?? null,
            ]);
        } catch (Throwable $e) {
            log_message('error', '[riwayat] notifikasi anak keluarga #{id} gagal: {msg}', ['id' => (string) $idKeluarga, 'msg' => $e->getMessage()]);
        }
    }

    private function selesaikanLampiran(): void
    {
        $lampiran = $this->lampiran();

        if (method_exists($lampiran, 'selesaikan')) {
            $lampiran->selesaikan();
        }
    }

    private function koneksi(): BaseConnection
    {
        /** @var BaseConnection $db */
        $db = $this->db ?? db_connect();

        return $db;
    }
}
