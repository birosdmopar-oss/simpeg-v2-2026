<?php

declare(strict_types=1);

namespace App\Libraries\Layanan;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Interfaces\Layanan\NotifikasiLayananInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Lampiran\KolomBerkas;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\Studi\BerkasStudi;
use App\Libraries\Layanan\Studi\StatusStudi;
use App\Libraries\Layanan\Studi\TampilanStudi;
use App\Models\Kepegawaian\DocumentAttachmentModel;
use App\Models\Kepegawaian\PegawaiModel;
use App\Models\Layanan\LayananModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Layanan;
use Throwable;

/**
 * Dasar ber-DB layanan studi TB/IB (PRD Fase 4 §6.7–§6.8, MAKE-024 M5): konfigurasi per jenis, kunci baris, hak lihat &
 * aksi, tampilan (`label_status`, `nada_status`, `tahap[]`, `aksi {}`), transaksi, lampiran ber-slot, notifikasi.
 * Aturan bisnis (validasi, transisi, lama tahun, tumpang tindih, slot berkas) = kelas murni `Studi/*`; kelas ini hanya
 * membungkusnya dengan DB.
 *
 * Hak lihat detail (§5.2/§5.3): pemilik, PIC aktif jenis itu, role 1, role 3 dalam lingkup (`bolehLihat`); selain itu 404.
 * Hak tulis (§6.7.2, §6.8.1): role 1 semua status kecuali 2 (hapus: semua); role 3 status 0 + lingkup (`bolehUbah`);
 * pemilik ber-role pengaju: ubah status 0 dan `tgl_mulai > hari ini`, hapus status 0. Proses: PIC aktif, status 0.
 * Urutan §7.10: 404 (tak terlihat) → 403 (bukan pelaku) → 422 (`errors.status` untuk status yang salah).
 *
 * Transaksi §7.8: `transBegin` (tanpa `transException(true)`), pegawai pemilik dikunci `FOR UPDATE` lalu baris layanan
 * dibaca ulang; notifikasi ditulis di dalam transaksi, push di-enqueue sesudah commit; berkas direkonsiliasi
 * (`AttachmentService::selesaikan`) sesudah commit/rollback.
 */
abstract class LayananStudi
{
    /** Kode `pegawai.id_jenis_pegawai` PNS [K]. */
    public const JENIS_PEGAWAI_PNS = 1;

    /** @var list<int> id `inbox_msg` yang menunggu enqueue sesudah commit */
    private array $antreanPush = [];

    /** Berkas di kolom (PMK req) transaksi berjalan; direkonsiliasi sesudah commit/rollback. */
    private ?KolomBerkas $kolomBerkas = null;

    public function __construct(
        protected readonly ?BaseConnection $db = null,
        private readonly ?PegawaiScopeInterface $scope = null,
        private readonly ?AttachmentServiceInterface $lampiran = null,
        private readonly ?PetugasLayananService $pic = null,
        private readonly ?NotifikasiLayananInterface $notifikasi = null,
    ) {
    }

    /** `tb` | `ib` (AturanStudi::JENIS_*). */
    abstract protected function jenis(): string;

    /**
     * Role yang mengajukan untuk diri sendiri (TB 2, 6; IB 2, 7).
     *
     * @return list<int>
     */
    abstract protected function rolePengaju(): array;

    abstract protected function model(): LayananModel;

    /** `jenis_layanan` PIC (TB 3, IB 4). */
    abstract protected function idJenisLayanan(): int;

    protected function tabel(): string
    {
        return 'riwayat_' . $this->jenis();
    }

    protected function pk(): string
    {
        return 'id_riwayat_' . $this->jenis();
    }

    protected function nama(): string
    {
        return AturanStudi::namaLayanan($this->jenis());
    }

    protected function aturan(): AturanStudi
    {
        return config(Layanan::class)->aturanStudi();
    }

    // ------------------------------------------------------------------------------------------------------------
    // Baris & hak

    /**
     * Baris yang terlihat pemanggil; tidak ada / tidak terlihat → 404.
     *
     * @return array<string, mixed>
     */
    public function barisTerlihat(AuthContext $auth, int $id): array
    {
        $row = $this->baris($id);

        if ($row === null || ! $this->bolehLihat($auth, $row)) {
            throw new NotFoundException();
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function baris(int $id, bool $kunci = false): ?array
    {
        $db  = $this->koneksi();
        $sql = $db->table($this->tabel())->where($this->pk(), $id)->getCompiledSelect();

        $row = $db->query($sql . ($kunci ? ' FOR UPDATE' : ''))->getRowArray();

        return is_array($row) ? $row : null;
    }

    /**
     * Kunci pegawai pemilik lalu baca ulang baris layanan terkunci (TOCTOU, §7.8). Baris hilang → 404.
     *
     * @return array<string, mixed>
     */
    protected function kunciBaris(string $nip, int $id): array
    {
        $this->kunciPegawai($nip);
        $row = $this->baris($id, true);

        if ($row === null || (string) $row['nip'] !== $nip) {
            throw new NotFoundException();
        }

        return $row;
    }

    protected function kunciPegawai(string $nip): void
    {
        $db = $this->koneksi();
        $db->query('SELECT ' . $db->protectIdentifiers('nip') . ' FROM ' . $db->protectIdentifiers('pegawai', true) . ' WHERE ' . $db->protectIdentifiers('nip') . ' = ? FOR UPDATE', [$nip]);
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function pemilik(AuthContext $auth, array $row): bool
    {
        $nip = (string) $auth->nip();

        return $nip !== '' && $nip === (string) $row['nip'];
    }

    public function adalahPic(AuthContext $auth): bool
    {
        return $this->pic()->adalahPic($auth->nip(), $this->idJenisLayanan());
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function bolehLihat(AuthContext $auth, array $row): bool
    {
        return match (true) {
            $auth->role() === Role::SUPER_ADMIN                                                             => true,
            $this->pemilik($auth, $row)                                                                     => true,
            $this->adalahPic($auth)                                                                         => true,
            $auth->role() === Role::ADMIN_SATKER && $this->scope()->bolehLihat($auth, (string) $row['nip']) => true,
            default                                                                                         => false,
        };
    }

    /**
     * Pelaku ubah: `admin1` | `admin3` | `pemilik` | null (bukan pelaku → 403).
     *
     * @param array<string, mixed> $row
     */
    protected function pelakuTulis(AuthContext $auth, array $row): ?string
    {
        return match (true) {
            $auth->role() === Role::SUPER_ADMIN                                                            => 'admin1',
            $auth->role() === Role::ADMIN_SATKER && $this->scope()->bolehUbah($auth, (string) $row['nip']) => 'admin3',
            in_array($auth->role(), $this->rolePengaju(), true) && $this->pemilik($auth, $row)             => 'pemilik',
            default                                                                                        => null,
        };
    }

    /**
     * Galat status ubah untuk pelaku (null = boleh) — aturan §6.7.2 "Ubah [K]".
     *
     * @param array<string, mixed> $row
     */
    protected function galatUbah(string $pelaku, array $row): ?string
    {
        $status = (int) $row['status'];

        return match ($pelaku) {
            'admin1' => $status === StatusStudi::DITOLAK ? "{$this->judulNama()} yang ditolak tidak dapat diubah." : null,
            'admin3' => $status === StatusStudi::PENGAJUAN ? null : "Hanya pengajuan {$this->namaKecil()} berstatus Pengajuan yang dapat diubah.",
            default  => $status !== StatusStudi::PENGAJUAN
                ? "Hanya pengajuan {$this->namaKecil()} berstatus Pengajuan yang dapat diubah."
                : ((string) $row['tgl_mulai'] > $this->hariIni() ? null : "Pengajuan {$this->namaKecil()} hanya dapat diubah sebelum tanggal mulai."),
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function galatHapus(string $pelaku, array $row): ?string
    {
        return $pelaku === 'admin1' || (int) $row['status'] === StatusStudi::PENGAJUAN
            ? null
            : "Hanya pengajuan {$this->namaKecil()} berstatus Pengajuan yang dapat dihapus.";
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function bolehProses(AuthContext $auth, array $row): bool
    {
        return (int) $row['status'] === StatusStudi::PENGAJUAN && $this->adalahPic($auth);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Tampilan

    /**
     * Item daftar/detail: kolom DDL + turunan server (§6.11.4) + hak per item (§7.10).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    protected function tampil(AuthContext $auth, array $row): array
    {
        $status = (int) $row['status'];
        $pelaku = $this->pelakuTulis($auth, $row);

        return $row + [
            'label_status' => TampilanStudi::labelStatus($status),
            'nada_status'  => TampilanStudi::nadaStatus($status),
            'tahap'        => TampilanStudi::tahap(
                $status,
                $row['created_at'] ?? null,
                StatusStudi::final($status) ? ($row['notif_date'] ?? $row['updated_at'] ?? null) : null,
                null,
            ),
            'aksi' => $this->aksi($auth, $row, $pelaku),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, bool>
     */
    protected function aksi(AuthContext $auth, array $row, ?string $pelaku): array
    {
        return [
            'ubah'        => $pelaku !== null && $this->galatUbah($pelaku, $row) === null,
            'hapus'       => $pelaku !== null && $this->galatHapus($pelaku, $row) === null,
            'proses'      => $this->bolehProses($auth, $row),
            'unduh_surat' => (int) $row['status'] === StatusStudi::DISETUJUI,
        ];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Pegawai

    /**
     * Snapshot pemohon diambil server saat tambah (§6.7.2, perbaikan TB-06) [K `L_ib.php:799-841`].
     *
     * @return array<string, string|null>
     */
    protected function snapshotPegawai(string $nip): array
    {
        $p = $this->koneksi()->table('pegawai p')
            ->select('p.glr_awal, p.nama, p.glr_akhir, pkp.gol_ruang, pkp.pangkat, pmj.jabatan, pmj.unit, pmj.satker, ppend.jenjang_pendidikan_singkat')
            ->join('pegawai_kp pkp', 'pkp.nip = p.nip', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = p.nip', 'left')
            ->join('pegawai_pendidikan ppend', 'ppend.nip = p.nip', 'left')
            ->where('p.nip', $nip)->get()->getRowArray();

        if (! is_array($p)) {
            throw new NotFoundException('Pegawai tidak ditemukan.');
        }

        return [
            'nama'                     => StrukturService::namaGelar($p['glr_awal'], (string) $p['nama'], $p['glr_akhir']),
            'gol_ruang'                => $p['gol_ruang'],
            'pangkat'                  => $p['pangkat'],
            'jabatan'                  => $p['jabatan'],
            'unit'                     => $p['unit'],
            'satker'                   => $p['satker'],
            'pendidikan_dasar_singkat' => $p['jenjang_pendidikan_singkat'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function pegawai(string $nip): ?array
    {
        $row = $this->koneksi()->table('pegawai')->select('nip, id_jenis_pegawai, id_jenis_status, status')->where('nip', $nip)->get()->getRowArray();

        return is_array($row) ? $row : null;
    }

    /**
     * Ubah `pegawai.id_jenis_status` beraudit (efek final TB, §6.7.3).
     */
    protected function ubahJenisStatus(AuthContext $auth, string $nip, int $idJenisStatus): void
    {
        $model = new PegawaiModel($this->koneksi());
        $param = ['id_jenis_status' => $idJenisStatus, 'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => $auth->idPengguna()];
        $model->withActor($auth, static fn () => $model->update($nip, $param));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Lampiran

    protected function aturanBerkas(int $kode, array $ekstensi = BerkasStudi::EKSTENSI): AturanLampiran
    {
        return new AturanLampiran($kode, true, BerkasStudi::BATAS_MB, $ekstensi);
    }

    /**
     * Lampiran ber-slot milik baris (kode 46/49), satu per `tag`.
     *
     * @return array<string, array<string, mixed>> tag => baris document_attachment
     */
    protected function lampiranSlot(string $nip, int $id): array
    {
        $out = [];

        foreach ($this->lampiran()->daftar($nip, BerkasStudi::kodeLampiran($this->jenis()), $id) as $row) {
            $tag = (string) ($row['tag'] ?? '');

            if ($tag !== '') {
                $out[$tag] = $row;
            }
        }

        return $out;
    }

    /**
     * Simpan berkas per slot di dalam transaksi pemanggil; slot yang sudah berisi lampiran v2 diganti (baris lama
     * dihapus fisik + audit) — keputusan reviewer CR 27-10 butir 3.
     *
     * @param array<string, \CodeIgniter\HTTP\Files\UploadedFile> $berkas slot => berkas
     */
    protected function simpanSlot(string $nip, int $id, array $berkas): void
    {
        if ($berkas === []) {
            return;
        }

        $kode   = BerkasStudi::kodeLampiran($this->jenis());
        $lama   = $this->lampiranSlot($nip, $id);
        $aturan = $this->aturanBerkas($kode);

        foreach ($berkas as $slot => $file) {
            if (isset($lama[$slot])) {
                $this->lampiran()->hapus($nip, (int) $lama[$slot]['id_attachment']);
            }

            $row = $this->lampiran()->simpan($nip, $kode, $id, $file, $aturan);
            model(DocumentAttachmentModel::class)->update((int) $row['id_attachment'], ['tag' => $slot]);
        }
    }

    /**
     * Hapus semua lampiran kode itu untuk entri (hapus induk, §6.7.4 TB-14).
     */
    protected function hapusLampiran(string $nip, int $kode, int|string $idEntri): void
    {
        foreach ($this->lampiran()->daftar($nip, $kode, $idEntri) as $row) {
            $this->lampiran()->hapus($nip, (int) $row['id_attachment']);
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // Notifikasi

    /**
     * Tulis notifikasi (di dalam transaksi); id-nya di-enqueue sesudah commit oleh transaksi().
     *
     * @param list<string> $penerima
     */
    protected function notifikasi(AuthContext $auth, int $id, int $tipe, array $penerima, string $judul, string $isi, ?string $reasonNote, int $status): void
    {
        $penerima = array_values(array_diff(array_unique($penerima), [(string) $auth->nip()]));

        if ($penerima === []) {
            return;
        }

        $ids = $this->notif()->kirim($this->jenis(), $id, $tipe, $penerima, $judul, $isi, $reasonNote, $status, "/layanan/{$this->jenis()}/{$id}");

        array_push($this->antreanPush, ...$ids);
    }

    /**
     * Isi notifikasi TB/IB §6.6.2: "{jenjang} - {institusi} ({jurusan}), {tgl_mulai} s/d {tgl_akhir}".
     *
     * @param array<string, mixed> $row
     */
    protected function ringkasNotifikasi(array $row): string
    {
        return sprintf(
            '%s - %s (%s), %s s/d %s',
            (string) $row['jenjang_pendidikan_singkat'],
            (string) $row['institusi_pendidikan'],
            (string) $row['jurusan_pendidikan'],
            TanggalBisnis::formatIndonesia((string) $row['tgl_mulai']),
            TanggalBisnis::formatIndonesia((string) $row['tgl_akhir']),
        );
    }

    /**
     * Notifikasi pengajuan baru / perubahan ke semua PIC aktif (§6.6.2, D14 tanpa syarat unit).
     *
     * @param array<string, mixed> $row
     */
    protected function notifikasiPic(AuthContext $auth, array $row, bool $baru): void
    {
        $judul = $baru ? "Permohonan {$this->judulNama()} Baru" : "Perubahan {$this->judulNama()}";
        $isi   = 'Data: ' . $this->ringkasNotifikasi($row) . ' oleh ' . (string) $row['nama'] . ' / ' . (string) $row['nip'];

        $this->notifikasi($auth, (int) $row[$this->pk()], $baru ? 1 : 3, $this->pic()->nipPicAktif($this->idJenisLayanan()), $judul, $isi, null, (int) $row['status']);
    }

    /**
     * Notifikasi keputusan ke pemohon (§6.6.2; tolak + alasan, perbaikan N6).
     *
     * @param array<string, mixed> $row
     */
    protected function notifikasiKeputusan(AuthContext $auth, array $row): void
    {
        $status = (int) $row['status'];
        $tolak  = $status === StatusStudi::DITOLAK;
        $isi    = $this->ringkasNotifikasi($row) . ($tolak ? '. Ket: ' . (string) $row['reason_note'] : '');

        $this->notifikasi($auth, (int) $row[$this->pk()], 2, [(string) $row['nip']], "{$this->judulNama()} " . ($tolak ? 'Ditolak' : 'Disetujui'), $isi, $tolak ? (string) $row['reason_note'] : null, $status);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Infrastruktur

    /**
     * Transaksi pola RiwayatEngine (§7.8): rollback pada galat atau transStatus false, push sesudah commit, berkas
     * direkonsiliasi sesudah commit/rollback.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    protected function transaksi(Closure $work): mixed
    {
        $db                = $this->koneksi();
        $this->antreanPush = [];
        $db->transBegin();

        try {
            $hasil = $work();
        } catch (Throwable $e) {
            $db->transRollback();
            $db->resetTransStatus();
            $this->selesaikanBerkas();

            throw $e;
        }

        if ($db->transStatus() === false) {
            $error = $db->error();
            $db->transRollback();
            $db->resetTransStatus();
            $this->selesaikanBerkas();

            throw new DatabaseException("Penulisan {$this->namaKecil()} gagal: " . $error['message'], (int) $error['code']);
        }

        $db->transCommit();
        $db->resetTransStatus();
        $this->selesaikanBerkas();

        if ($this->antreanPush !== []) {
            $this->notif()->enqueuePush($this->antreanPush);
            $this->antreanPush = [];
        }

        return $hasil;
    }

    protected function hariIni(): string
    {
        return TanggalBisnis::hariIni();
    }

    protected function sekarang(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    protected function namaKecil(): string
    {
        return strtolower($this->nama());
    }

    /** "Tugas Belajar" / "Izin Belajar" untuk judul notifikasi (§6.6.2) dan awal kalimat. */
    protected function judulNama(): string
    {
        return ucwords($this->nama());
    }

    protected function koneksi(): BaseConnection
    {
        return $this->db ?? db_connect();
    }

    protected function scope(): PegawaiScopeInterface
    {
        return $this->scope ?? service('pegawaiScope');
    }

    protected function lampiran(): AttachmentServiceInterface
    {
        return $this->lampiran ?? service('attachmentService');
    }

    protected function pic(): PetugasLayananService
    {
        return $this->pic ?? new PetugasLayananService($this->koneksi());
    }

    protected function notif(): NotifikasiLayananInterface
    {
        return $this->notifikasi ?? service('notifikasiLayanan');
    }

    /**
     * 403 bila bukan pelaku, 422 `errors.status` bila status/tanggal tidak mengizinkan.
     */
    protected function tolakBila(?string $galat): void
    {
        if ($galat !== null) {
            throw ValidationException::forField('status', $galat);
        }
    }

    protected function terlarang(): never
    {
        throw new ForbiddenException("Anda tidak berhak melakukan aksi ini pada {$this->namaKecil()} ini.");
    }

    /**
     * Penulis berkas kolom (`KolomBerkas`, pola transaksi `transBegin` §7.4) untuk transaksi berjalan.
     */
    protected function kolomBerkas(): KolomBerkas
    {
        return $this->kolomBerkas ??= new KolomBerkas(null, $this->koneksi());
    }

    private function selesaikanBerkas(): void
    {
        $lampiran = $this->lampiran();

        if (method_exists($lampiran, 'selesaikan')) {
            $lampiran->selesaikan();
        }

        $this->kolomBerkas?->selesaikan();
        $this->kolomBerkas = null;
    }
}
