<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg;

use App\Interfaces\Notifikasi\RingkasanTiketInterface;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Penghitung tiket Halo Simpeg (PRD Fase 8 v1.3 §6.2, F8-11, F8-19, D8 (a)); satu kelas untuk `GET halo-simpeg/tiket/ringkasan`,
 * `POST …/baca`, dan lonceng F-08 (`jumlahBelumDibaca()`, AC-E06.9). BP8-1: hanya membaca `disiplin_ticket` dan tabel petugas;
 * tidak pernah `inbox`/`inbox_msg`.
 *
 * - `belum_dibaca_saya` = tiket milik sendiri `is_read = 2` (termasuk kategori NULL).
 * - `belum_dibaca_petugas` = hanya petugas aktif: kategori yang dipegang, `is_read_off = 2`, **bukan** milik sendiri.
 * - `perlu_tindak` = sisi petugas aktif, bukan milik sendiri: Open AND (`is_read_off = 2` OR pengingat 24 jam jatuh tempo).
 * - `badge` = tiket distinct (belum dibaca di sisi mana pun ∪ `perlu_tindak`); `total` = `belum_dibaca_saya + belum_dibaca_petugas`.
 *
 * Kueri sisi petugas menulis `status IN (0, 1)` eksplisit agar indeks `idx_dticket_kategori_status_baca` (kategori, status,
 * is_read_off) terpakai penuh — syarat DB Validator DBV-028 Q6, diuji EXPLAIN (`TiketEksplainTest`).
 */
final class RingkasanTiket implements RingkasanTiketInterface
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** Lonceng: role di luar 1–7 (role 8) = 0, bukan null (§6.2). */
    public function jumlahBelumDibaca(AuthContext $auth): int
    {
        $id = $auth->idPengguna();

        if ($id === null || ! in_array($auth->role(), PetugasService::ROLE_PETUGAS, true)) {
            return 0;
        }

        return $this->hitung($id)['total'];
    }

    /**
     * @return array{belum_dibaca_saya: int, belum_dibaca_petugas: int, perlu_tindak: int, badge: int, total: int}
     */
    public function hitung(int $idPengguna, ?array $kategori = null): array
    {
        $kategori ??= AksesTiket::kategoriAktif($this->db, $idPengguna);
        $saya     = $this->db->table('disiplin_ticket')->where('sender', $idPengguna)->where('is_read', 2)->countAllResults();
        $petugas  = 0;
        $tindak   = 0;
        $gabungan = 0;

        if ($kategori !== []) {
            $petugas  = $this->kueriBelumDibacaPetugas($idPengguna, $kategori)->countAllResults();
            $tindak   = $this->kueriPetugas($idPengguna, $kategori)->where('status', 0)->where(self::sqlPerluTindak($this->db, ''), null, false)->countAllResults();
            $gabungan = $this->kueriPetugas($idPengguna, $kategori)
                ->groupStart()->where('is_read_off', 2)->orGroupStart()->where('status', 0)->where(self::sqlPengingatJatuhTempo($this->db, ''), null, false)->groupEnd()->groupEnd()
                ->countAllResults();
        }

        return [
            'belum_dibaca_saya'    => $saya,
            'belum_dibaca_petugas' => $petugas,
            'perlu_tindak'         => $tindak,
            'badge'                => $saya + $gabungan,
            'total'                => $saya + $petugas,
        ];
    }

    /**
     * Kueri `belum_dibaca_petugas` (bentuk B + `status IN (0, 1)` eksplisit, DBV-028 Q6). Publik untuk test EXPLAIN.
     *
     * @param list<int> $kategori
     */
    public function kueriBelumDibacaPetugas(int $idPengguna, array $kategori): BaseBuilder
    {
        return $this->kueriPetugas($idPengguna, $kategori)->where('is_read_off', 2);
    }

    /**
     * `perlu_tindak` tanpa syarat Open (dipakai bersama `status = 0`): `is_read_off = 2` OR pengingat jatuh tempo [K `MC:292`].
     * `$alias` = alias tabel tiket ('' = tanpa alias).
     */
    public static function sqlPerluTindak(BaseConnection $db, string $alias): string
    {
        $a = $alias === '' ? '' : $alias . '.';

        return "({$a}is_read_off = 2 OR " . self::sqlPengingatJatuhTempo($db, $alias) . ')';
    }

    public static function sqlPengingatJatuhTempo(BaseConnection $db, string $alias): string
    {
        $a = $alias === '' ? '' : $alias . '.';

        return "({$a}notify_pic = 1 AND {$a}notify_at < " . $db->escape(gmdate('Y-m-d H:i:s')) . ')';
    }

    /**
     * Tiket sisi petugas: kategori dipegang, Open/Closed eksplisit, bukan milik sendiri.
     *
     * @param list<int> $kategori
     */
    private function kueriPetugas(int $idPengguna, array $kategori): BaseBuilder
    {
        return $this->db->table('disiplin_ticket')
            ->whereIn('id_question_category', $kategori)->whereIn('status', [0, 1])->where('sender !=', $idPengguna);
    }

}
