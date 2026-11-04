<?php

declare(strict_types=1);

namespace App\Libraries\Push;

use App\Constants\AkunSistem;
use App\Constants\Role;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Penentu token FCM penerima push (PRD Fase 6 §7.4, F6-13; MAKE-066) — padanan helper legacy `FH:389-586`
 * (`getTokenAdmin`, `getTokenPeg`, `getTokenPL`) di atas tabel `firebase_token` v2 (DBV-027):
 *
 * - token **web** (`platform` 1) milik akun (`id_pengguna`) yang aktif (`status` '1') dan belum dihapus — padanan `fb_token`;
 * - token **Android** (`platform` 2) milik `nip` — padanan `firebase_token` legacy (tanpa syarat akun, [K `L_cuti.php:1068`]).
 *
 * Hanya membaca. Kembalian satu baris per token: `{id, token, platform}`; token kosong dibuang, token ganda per platform
 * digabung (DISTINCT legacy).
 */
final class PenerimaTokenFcm
{
    /** Unit "Lain-lain" pegawai_mutasi_jabatan — notifikasi ke admin kepegawaian [K `FH:487-490`]. */
    public const UNIT_LAIN = 7;

    /** Unit UPT — admin satker yang sama [K `FH:492-495`]. */
    public const UNIT_UPT = 6;

    public function __construct(private readonly BaseConnection $db, private readonly ?int $unitKepegawaian = null)
    {
    }

    /**
     * Token Android milik NIP-NIP ini.
     *
     * @param list<string> $nips
     *
     * @return list<array{id: int, token: string, platform: int}>
     */
    public function android(array $nips): array
    {
        $nips = $this->bersih($nips);

        if ($nips === []) {
            return [];
        }

        return $this->baris($this->db->table('firebase_token')->select('id_firebase_token, fcm_token, platform')
            ->where('platform', TokenFcmService::PLATFORM_ANDROID)->whereIn('nip', $nips));
    }

    /**
     * Token web akun pegawai (role 2/6/7) ber-NIP ini — `getTokenPeg` [K `FH:556-586`, `UserLevel IN (2, 6)`; v2 menambah
     * PPPK 7 yang ada di `Role::UL_PEGAWAI`].
     *
     * @param list<string> $nips
     *
     * @return list<array{id: int, token: string, platform: int}>
     */
    public function webPegawai(array $nips): array
    {
        $nips = $this->bersih($nips);

        if ($nips === []) {
            return [];
        }

        return $this->baris($this->web()->whereIn('p.user_level', Role::UL_PEGAWAI)->whereIn('p.nip', $nips));
    }

    /**
     * Token web akun ber-NIP ini tanpa syarat role — penerima `inbox` layanan Fase 4 (penerima sudah ditentukan
     * `NotifikasiService`, termasuk admin/pejabat ber-NIP).
     *
     * @param list<string> $nips
     *
     * @return list<array{id: int, token: string, platform: int}>
     */
    public function webAkunNip(array $nips): array
    {
        $nips = $this->bersih($nips);

        if ($nips === []) {
            return [];
        }

        return $this->baris($this->web()->whereIn('p.nip', $nips));
    }

    /**
     * Token web admin yang membawahi pegawai — `getTokenAdmin` [K `FH:474-554`], unit/satker dari `pegawai_mutasi_jabatan`:
     * tanpa baris pmj, unit Lain-lain (7), atau unit kepegawaian (`web_config.id_unit_kepegawaian`) → semua Super Admin (1);
     * baris pmj dengan unit kosong → tanpa penerima (legacy `id_unit=''`, review MAKE-066 S5);
     * UPT (6) → Admin Satker (3) unit 6 satker sama; unit lain → Admin Satker (3) unit itu tanpa satker.
     *
     * @return list<array{id: int, token: string, platform: int}>
     */
    public function webAdmin(string $nip): array
    {
        $nip = trim($nip);

        if ($nip === '') {
            return [];
        }

        $pmj = $this->db->table('pegawai_mutasi_jabatan')->select('id_unit, id_satker')->where('nip', $nip)->get(1)->getRowArray();

        // Baris pmj ada tetapi unit kosong → legacy mencari admin `id_unit=''` = tidak ada penerima (review MAKE-066 S5).
        if ($pmj !== null && trim((string) $pmj['id_unit']) === '') {
            return [];
        }

        $unit = $pmj === null ? null : (int) $pmj['id_unit'];
        $q    = $this->web();

        if ($unit === null || $unit === self::UNIT_LAIN || ($this->unitKepegawaian !== null && $unit === $this->unitKepegawaian)) {
            return $this->baris($q->where('p.user_level', Role::SUPER_ADMIN));
        }

        $q->where('p.user_level', Role::ADMIN_SATKER)->where('p.id_unit', (string) $unit);

        if ($unit === self::UNIT_UPT) {
            $satker = trim((string) ($pmj['id_satker'] ?? ''));

            // Legacy: UPT tanpa satker jatuh ke cabang "unit lain" (satker NULL).
            return $this->baris($satker === '' ? $q->where('p.id_satker', null) : $q->where('p.id_satker', $satker));
        }

        return $this->baris($q->where('p.id_satker', null));
    }

    /**
     * Token web petugas layanan aktif jenis ini — `getTokenPL` [K `FH:421-472`]: 1 Karpeg, 2 Kariskarsu.
     *
     * @return list<array{id: int, token: string, platform: int}>
     */
    public function webPetugasLayanan(int $idJenisLayanan): array
    {
        $nips = array_map('strval', array_column(
            $this->db->table('petugas_layanan')->select('nip')->where(['id_jenis_layanan' => $idJenisLayanan, 'status' => 1])->get()->getResultArray(),
            'nip',
        ));

        return $this->webPegawai($nips);
    }

    private function web(): BaseBuilder
    {
        return $this->db->table('firebase_token ft')->select('ft.id_firebase_token, ft.fcm_token, ft.platform')
            ->join('pengguna p', 'p.id_pengguna = ft.id_pengguna')
            ->where('ft.platform', TokenFcmService::PLATFORM_WEB)
            ->where('p.status', '1')->where('p.deleted_at', null)->where('p.username !=', AkunSistem::USERNAME);
    }

    /**
     * @return list<array{id: int, token: string, platform: int}>
     */
    private function baris(BaseBuilder $q): array
    {
        $hasil = [];

        foreach ($q->orderBy('id_firebase_token', 'ASC')->get()->getResultArray() as $r) {
            $token = trim((string) $r['fcm_token']);
            $kunci = $r['platform'] . '|' . $token;

            if ($token !== '' && ! isset($hasil[$kunci])) {
                $hasil[$kunci] = ['id' => (int) $r['id_firebase_token'], 'token' => $token, 'platform' => (int) $r['platform']];
            }
        }

        return array_values($hasil);
    }

    /**
     * @param list<string> $nips
     *
     * @return list<string>
     */
    private function bersih(array $nips): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (mixed $n): string => trim((string) $n), $nips), static fn (string $n): bool => $n !== '')));
    }
}
