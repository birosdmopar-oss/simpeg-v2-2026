<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseConnection;

/**
 * Q6-40 (ISSUE-055 opsi d, PRD Fase 6 v1.0m; MAKE-075) — kapan Super Admin boleh menandatangani surat cuti "atas nama"
 * penanda tangan (PyB; langkah tunggal tanpa PyB → AL) dengan passphrase pejabat itu, ikut legacy `L_cuti.php:3668-3673`,
 * `Cuti.php:796` (siapa pun yang memproses persetujuan boleh mencentang `allow_esign` dan mengetik passphrase).
 *
 * Tafsir [V2] "berhak atas nama" (putusan reviewer 06-11-2026): role 1 (Super Admin mana pun, bukan hanya yang memproses)
 * DAN keputusan final cuti itu (status 1/4) tercatat sebagai keputusan atas nama — entri audit `cuti.process_atas_nama`
 * TERAKHIR yang berstatus final untuk cuti itu, dengan status sama dengan status cuti sekarang. Keputusan final oleh pejabat
 * sendiri tetap hanya ditandatangani pejabat itu (F6-18). Atas nama di tahap AL yang belum final tidak membuka TTE (Q2).
 */
final class TteAtasNama
{
    public const PESAN_DITOLAK = 'Tanda tangan atas nama hanya untuk Super Admin pada cuti yang keputusan finalnya diproses atas nama.';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $cuti baris `riwayat_cuti`
     */
    public static function boleh(BaseConnection $db, AuthContext $auth, array $cuti): bool
    {
        return (int) $auth->role() === Role::SUPER_ADMIN && self::finalAtasNama($db, $cuti);
    }

    /**
     * Keputusan final cuti ini diproses atas nama (dibaca dari koneksi `$db` yang sama dengan pemanggil).
     *
     * @param array<string, mixed> $cuti
     */
    public static function finalAtasNama(BaseConnection $db, array $cuti): bool
    {
        $status = (int) ($cuti['status'] ?? 0);

        if (! in_array($status, StatusCuti::EFEK_FINAL, true) || EsignCutiService::penandaTangan($cuti) === null) {
            return false;
        }

        $baris = $db->table('audit_logs')->select('after_json')
            ->where(['entity' => 'riwayat_cuti', 'entity_id' => (string) $cuti['id_riwayat_cuti']])
            ->like('after_json', CutiApprovalService::JENIS_AUDIT_ATAS_NAMA)
            ->orderBy('id_log', 'DESC')->get()->getResultArray();

        foreach ($baris as $b) {
            $after = json_decode((string) $b['after_json'], true);

            if (! is_array($after) || ($after['jenis_audit'] ?? null) !== CutiApprovalService::JENIS_AUDIT_ATAS_NAMA) {
                continue;
            }

            $statusAudit = (int) ($after['status'] ?? 0);

            if (in_array($statusAudit, StatusCuti::EFEK_FINAL, true)) {
                // Entri final atas nama TERAKHIR: berlaku hanya bila status cuti masih hasil keputusan itu. Entri ini
                // otomatis juga keputusan final TERAKHIR secara umum (saran review S1): status final 1/4 bersifat terminal —
                // {@see TransisiCuti::tahapAktif()} hanya untuk status 0/3, sehingga cuti final tidak bisa diproses lagi
                // (oleh pejabat maupun atas nama) dan tidak ada keputusan pejabat sesudahnya (dikunci test
                // EsignAtasNamaTest::testTanpaPassphraseSuratTanpaTteDanBisaDiulang).
                return $statusAudit === $status;
            }
        }

        return false;
    }
}
