<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Tb;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\Studi\BerkasStudi;
use App\Libraries\Layanan\Studi\StatusStudi;
use App\Libraries\Layanan\StudiPengajuanService;
use App\Models\Layanan\LayananModel;
use App\Models\Layanan\RiwayatTbModel;
use App\Models\Layanan\RiwayatTbPerpanModel;

/**
 * Tugas Belajar C-07 — CRUD pengajuan (PRD Fase 4 §5.2, §6.7.2, §6.7.3 baris "input role 1 status 1", §6.7.5
 * `status_penuh`). Role 2/6 diri sendiri status 0; role 3 `nip` dalam lingkup status 0; role 1 `nip` PNS status 0|1
 * (1 = efek final yang sama dengan approval, TB-07). Role 7 ditolak route (DoD 070-4). Berkas 4 slot; role 1
 * dibebaskan [K]. Data SK role 1 ikut tersimpan pada tambah/ubah (TB-09).
 */
class TbService extends StudiPengajuanService
{
    use EfekTbTrait;

    /** Role route TB (PRD §5.2; role 7 → 403, DoD 070-4) — dipakai RoutesLayananStudi.php & KontrakMatriksRoleTest. */
    public const ROLE_ROUTE = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT];

    /** Role yang mengajukan untuk diri sendiri (status 0). */
    public const ROLE_PENGAJU = [Role::PEGAWAI, Role::PTT];

    /** Kolom SK yang boleh diisi role 1 saat tambah/ubah. */
    private const KOLOM_SK = ['no_sk', 'tgl_sk', 'ds_tb', 'no_ds_tb', 'tgl_ds_tb', 'perihal_ds_tb'];

    protected function jenis(): string
    {
        return AturanStudi::JENIS_TB;
    }

    protected function rolePengaju(): array
    {
        return self::ROLE_PENGAJU;
    }

    protected function model(): LayananModel
    {
        return new RiwayatTbModel($this->koneksi());
    }

    protected function idJenisLayanan(): int
    {
        return PetugasLayananService::JENIS_TB;
    }

    protected function statusAwal(AuthContext $auth, array $data): ?int
    {
        if ($auth->role() !== Role::SUPER_ADMIN || ! array_key_exists('status', $data) || ! is_scalar($data['status']) || trim((string) $data['status']) === '') {
            return null;
        }

        $nilai = trim((string) $data['status']);

        // Nilai bukan angka → -1 agar ValidasiStudi menolak dengan pesan domain status.
        return ctype_digit($nilai) ? (int) $nilai : -1;
    }

    protected function role1BebasBerkas(): bool
    {
        return true;
    }

    protected function kolomSkAdmin(): array
    {
        return self::KOLOM_SK;
    }

    protected function periksaPegawaiAdmin(AuthContext $auth, array $pegawai): void
    {
        if ($auth->role() === Role::SUPER_ADMIN && (int) ($pegawai['id_jenis_pegawai'] ?? 0) !== self::JENIS_PEGAWAI_PNS) {
            throw ValidationException::forField('nip', 'Tugas belajar hanya untuk Pegawai Negeri Sipil.');
        }
    }

    protected function aksi(AuthContext $auth, array $row, ?string $pelaku): array
    {
        return parent::aksi($auth, $row, $pelaku) + [
            'perpanjangan' => $auth->role() === Role::SUPER_ADMIN && (int) $row['status'] === StatusStudi::DISETUJUI,
        ];
    }

    /**
     * Detail TB: `status_penuh` (bebas presensi hari ini, D15) + `perpanjangan[]` beserta berkasnya.
     */
    protected function ekstraDetail(AuthContext $auth, array $row): array
    {
        $nip    = (string) $row['nip'];
        $perpan = $this->koneksi()->table('riwayat_tb_perpan')->where('id_riwayat_tb', (int) $row['id_riwayat_tb'])
            ->orderBy('tgl_mulai_perpan')->orderBy('id_riwayat_tb_perpan')->get()->getResultArray();

        return [
            'status_penuh' => (new StatusTugasBelajar($this->koneksi()))->bebasPresensiPenuh($nip, $this->hariIni()) ? 1 : 0,
            'perpanjangan' => array_map(fn (array $p): array => $p + [
                'lampiran' => $this->lampiran()->daftar($nip, BerkasStudi::KODE_TB_PERPAN, (int) $p['id_riwayat_tb_perpan']),
            ], $perpan),
        ];
    }

    /**
     * Hapus TB: perpanjangan + berkasnya dulu (FK RESTRICT, TB-14), berkas TB, lalu kembalikan `id_jenis_status`.
     *
     * @param array<string, mixed> $row
     */
    protected function sebelumHapus(AuthContext $auth, array $row): void
    {
        $nip   = (string) $row['nip'];
        $id    = (int) $row['id_riwayat_tb'];
        $model = new RiwayatTbPerpanModel($this->koneksi());

        foreach ($this->koneksi()->table('riwayat_tb_perpan')->select('id_riwayat_tb_perpan')->where('id_riwayat_tb', $id)->get()->getResultArray() as $perpan) {
            $this->hapusLampiran($nip, BerkasStudi::KODE_TB_PERPAN, (int) $perpan['id_riwayat_tb_perpan']);
            $model->withActor($auth, static fn () => $model->delete((int) $perpan['id_riwayat_tb_perpan']));
        }

        parent::sebelumHapus($auth, $row);

        if ((int) $row['status'] === StatusStudi::DISETUJUI && $this->hariIniDalamTb($row)) {
            $pegawai = $this->pegawai($nip);

            if ($pegawai !== null && (int) $pegawai['status'] === 1 && (int) $pegawai['id_jenis_status'] === self::JENIS_STATUS_TB) {
                $this->ubahJenisStatus($auth, $nip, self::JENIS_STATUS_AKTIF);
            }
        }
    }

    protected function lampiranMilikBaris(array $da, array $row): bool
    {
        if (parent::lampiranMilikBaris($da, $row)) {
            return true;
        }

        if ((int) $da['id_riwayat'] !== BerkasStudi::KODE_TB_PERPAN) {
            return false;
        }

        return (new RiwayatTbPerpanModel($this->koneksi()))->where('id_riwayat_tb', (int) $row['id_riwayat_tb'])
            ->where('id_riwayat_tb_perpan', (int) $da['id_entri'])->countAllResults() > 0;
    }
}
