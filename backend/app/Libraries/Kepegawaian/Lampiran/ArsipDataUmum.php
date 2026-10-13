<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lampiran;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;

/**
 * Arsip Kepegawaian = arsip Data Umum (B-20 penutup × B-18, MAKE-014; keputusan user 13-10-2026 Q-M6-6), lewat endpoint
 * lampiran `pegawai/{nip}/lampiran*` dengan `id_entri` = NIP (data umum tidak punya baris riwayat; baris legacy
 * ber-`id_entri` NULL ikut terbaca). Kode `jenis_rwy` legacy `ARSIP_RWY`:
 *  - 38 `arsip_du` (legacy `Employee::add_adu`/`delete_adu` userAuth ['1','2','3','6','7']): unggah & hapus role 1 (semua),
 *    role 3 (PegawaiScope ubah), role 2/6/7 hanya NIP sendiri; `tag` = jenis arsip (KTP, Paspor, …, atau "Lain-lain" +
 *    nama); jpg/jpeg/png/pdf 5 MB (legacy L_employee :224-262);
 *  - 37 `status_du` ("Arsip Status", legacy diunggah role 1 saat mengubah status; `delete_da` userAuth ['1']): unggah &
 *    hapus role 1; `tag` = nama arsip (bawaan "Arsip Status"); jpg/jpeg/png/gif/pdf/doc/docx/xls/xlsx 5 MB.
 * Lihat & unduh: siapa pun yang boleh melihat detail pegawai (PegawaiScope lihat). Tidak ada lampiran wajib.
 */
final class ArsipDataUmum
{
    public const KODE_ARSIP  = 38;
    public const KODE_STATUS = 37;

    /** Jenis arsip legacy (form.php :519-527). */
    public const JENIS = ['KTP', 'Paspor', 'BPJS', 'Taspen', 'KARIS', 'KARSU', 'KARPEG', 'NPWP'];

    public const LAIN = 'Lain-lain';

    /** @var list<int> */
    public const ROLE_TULIS_ARSIP = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK];

    public static function kode(int $idRiwayat): bool
    {
        return in_array($idRiwayat, [self::KODE_ARSIP, self::KODE_STATUS], true);
    }

    public static function aturan(int $idRiwayat): AturanLampiran
    {
        return $idRiwayat === self::KODE_STATUS
            ? new AturanLampiran(self::KODE_STATUS, false, 5, ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'])
            : new AturanLampiran(self::KODE_ARSIP, false, 5, ['jpg', 'jpeg', 'png', 'pdf']);
    }

    /**
     * Role boleh menulis (unggah/hapus) kode ini untuk {nip} — tanpa lingkup (PegawaiScope ubah diperiksa pemanggil).
     */
    public static function bolehTulis(AuthContext $auth, int $idRiwayat, string $nip): bool
    {
        if ($idRiwayat === self::KODE_STATUS) {
            return $auth->role() === Role::SUPER_ADMIN;
        }

        if (! in_array($auth->role(), self::ROLE_TULIS_ARSIP, true)) {
            return false;
        }

        // Pegawai (2/6/7) hanya arsip miliknya (legacy add_adu: NIP dipaksa milik sendiri).
        return ! in_array($auth->role(), Role::UL_PEGAWAI, true) || (string) $auth->nip() === $nip;
    }

    /**
     * `tag` dari input unggah: 38 = jenis (`jenis_arsip`, "Lain-lain" → `nama_arsip_lain`, wajib); 37 = `nama_arsip`
     * opsional (bawaan "Arsip Status"). 422 per kolom.
     *
     * @param array<string, mixed> $input
     */
    public static function tag(int $idRiwayat, array $input): string
    {
        $teks = static fn (string $k): string => is_scalar($input[$k] ?? null) ? trim((string) $input[$k]) : '';

        if ($idRiwayat === self::KODE_STATUS) {
            $nama = $teks('nama_arsip');

            if (mb_strlen($nama) > 100) {
                throw ValidationException::forField('nama_arsip', 'Nama arsip maksimal 100 karakter.');
            }

            return $nama === '' ? 'Arsip Status' : $nama;
        }

        $jenis = $teks('jenis_arsip');

        if ($jenis === self::LAIN) {
            $nama = $teks('nama_arsip_lain');

            if ($nama === '' || mb_strlen($nama) > 100) {
                throw ValidationException::forField('nama_arsip_lain', 'Nama arsip lain wajib diisi (maksimal 100 karakter).');
            }

            return $nama;
        }

        if (! in_array($jenis, self::JENIS, true)) {
            throw ValidationException::forField('jenis_arsip', 'Silakan pilih jenis arsip data umum.');
        }

        return $jenis;
    }
}
