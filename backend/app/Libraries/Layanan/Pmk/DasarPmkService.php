<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Pmk;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Layanan\LayananStudi;
use App\Libraries\Layanan\PetugasLayananService;
use App\Models\Layanan\LayananModel;
use App\Models\Layanan\RiwayatPmkReqModel;
use Config\Layanan;

/**
 * Dasar pengajuan PMK (PRD Fase 4 §5.4, §6.9) di atas `LayananStudi` (kunci, transaksi, notifikasi, PIC): tabel
 * `riwayat_pmk_req`, PIC jenis 5, aturan hak PMK [K]:
 *
 * - Lihat: pemilik, PIC PMK aktif, role 1, role 3 lingkup (`bolehLihat`); lainnya 404. Status 0 boleh dibuka (Q-16).
 * - Tulis: pemilik role 2 dan role 1; role 3 lihat saja (403). Ubah **tanpa syarat status** dan status tidak berubah
 *   [K `Pmk.php:289-346, 625-682`, D19/ISSUE-048]; hapus ditolak bila status 1 [K `Pmk.php:357, 693`] → 422 `errors.status`.
 * - Proses: PIC PMK aktif, status 0 atau 3 (`TransisiPmk`).
 */
abstract class DasarPmkService extends LayananStudi
{
    /** Role route PMK (PRD §5.4; role 4..8 → 403, DoD 072-4) — dipakai RoutesLayananStudi.php & KontrakMatriksRoleTest. */
    public const ROLE_ROUTE = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER];

    /** PMK hanya PNS (P-17 [K]): pengajuan pegawai role 2. */
    public const ROLE_PENGAJU = [Role::PEGAWAI];

    protected function jenis(): string
    {
        return 'pmk';
    }

    protected function tabel(): string
    {
        return 'riwayat_pmk_req';
    }

    protected function pk(): string
    {
        return 'id_riwayat_pmk_req';
    }

    protected function nama(): string
    {
        return 'peninjauan masa kerja';
    }

    protected function rolePengaju(): array
    {
        return self::ROLE_PENGAJU;
    }

    protected function model(): LayananModel
    {
        return new RiwayatPmkReqModel($this->koneksi());
    }

    protected function idJenisLayanan(): int
    {
        return PetugasLayananService::JENIS_PMK;
    }

    protected function aturanPmk(): AturanPmk
    {
        return config(Layanan::class)->aturanPmk();
    }

    protected function pelakuTulis(AuthContext $auth, array $row): ?string
    {
        return match (true) {
            $auth->role() === Role::SUPER_ADMIN                                              => 'admin1',
            in_array($auth->role(), self::ROLE_PENGAJU, true) && $this->pemilik($auth, $row) => 'pemilik',
            default                                                                          => null,
        };
    }

    protected function galatUbah(string $pelaku, array $row): ?string
    {
        return null;
    }

    protected function galatHapus(string $pelaku, array $row): ?string
    {
        return StatusPmk::bolehHapus((int) $row['status']) ? null : 'Pengajuan PMK yang sudah selesai tidak dapat dihapus.';
    }

    protected function bolehProses(AuthContext $auth, array $row): bool
    {
        return TransisiPmk::aksiTersedia((int) $row['status']) !== [] && $this->adalahPic($auth);
    }

    protected function tampil(AuthContext $auth, array $row): array
    {
        $status = (int) $row['status'];
        $pelaku = $this->pelakuTulis($auth, $row);
        $aksi   = [
            'ubah'   => $pelaku !== null,
            'hapus'  => $pelaku !== null && $this->galatHapus($pelaku, $row) === null,
            'proses' => $this->bolehProses($auth, $row),
        ];

        foreach (['file_kontrak', 'file_paklaring', 'file_surat_phk', 'file_slip_gaji'] as $kolom) {
            // Path penyimpanan internal tidak dikirim ke klien; cukup penanda ada/tidak.
            $row[$kolom] = $row[$kolom] !== null && $row[$kolom] !== '';
        }

        return $row + [
            'label_status'  => StatusPmk::labelUi($status),
            'nada_status'   => TampilanPmk::nadaStatus($status),
            'tahap'         => TampilanPmk::tahap($status, $row['created_at'] ?? null, $row['approved_at'] ?? null),
            'aksi'          => $aksi,
            'aksi_tersedia' => $aksi['proses'] ? TransisiPmk::aksiTersedia($status) : [],
        ];
    }

    /**
     * NIP + nama pemohon untuk isi notifikasi.
     *
     * @param array<string, mixed> $row
     */
    protected function namaPemohon(array $row): string
    {
        $p = $this->koneksi()->table('pegawai')->select('glr_awal, nama, glr_akhir')->where('nip', (string) $row['nip'])->get()->getRowArray();

        return is_array($p) ? \App\Libraries\Kepegawaian\Struktur\StrukturService::namaGelar($p['glr_awal'], (string) $p['nama'], $p['glr_akhir']) : '';
    }

    /**
     * Isi PMK §6.6.2 [K `L_notification.php:3241-3245`].
     *
     * @param array<string, mixed> $row
     */
    protected function ringkasPmk(array $row): string
    {
        return sprintf(
            'Pengajuan di %s (%s) mulai tanggal %s s/d %s dengan masa kerja %d tahun %d bulan',
            (string) $row['instansi'],
            (string) $row['jenis_pmk'],
            TanggalBisnis::formatIndonesia((string) $row['tgl_awal']),
            TanggalBisnis::formatIndonesia((string) $row['tgl_akhir']),
            (int) $row['mker_th'],
            (int) $row['mker_bl'],
        );
    }
}
