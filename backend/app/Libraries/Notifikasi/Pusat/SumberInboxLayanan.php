<?php

declare(strict_types=1);

namespace App\Libraries\Notifikasi\Pusat;

use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;

/**
 * S1 — notifikasi layanan cuti/TB/IB/PMK dari `inbox`/`inbox_msg` v2 (Fase 4, ditulis `NotifikasiService`).
 *
 * Penerima = `inbox.nip_penerima` = NIP pemanggil. Waktu = pesan terakhir; keterangan/alasan dari pesan terakhir.
 * Tandai dibaca = `is_read` 1 + `read_at` (UTC). Tanpa hapus (Q7-33: `inbox` v2 tidak punya penanda hapus).
 * Tembusan Ketua Tim cuti ikut di sini (`inbox_msg.tipe` 4), bukan `riwayat_cuti_notif_kt` (Q7-32).
 */
final class SumberInboxLayanan implements SumberDaftar
{
    public const KUNCI = 'layanan';

    private const TIPE = [1 => 'Diajukan', 2 => 'Keputusan', 3 => 'Perubahan', 4 => 'Tembusan'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function kunci(AuthContext $auth): array
    {
        return $this->nip($auth) === null ? [] : [self::KUNCI];
    }

    public function jumlah(AuthContext $auth, bool $hanyaBelumDibaca): int
    {
        $nip = $this->nip($auth);

        if ($nip === null) {
            return 0;
        }

        $b = $this->db->table('inbox')->where('nip_penerima', $nip);
        $hanyaBelumDibaca && $b->where('is_read', 0);

        return $b->countAllResults();
    }

    public function daftar(AuthContext $auth, bool $hanyaBelumDibaca, int $batas): array
    {
        $nip = $this->nip($auth);

        if ($nip === null || $batas < 1) {
            return [];
        }

        $msg = $this->db->prefixTable('inbox_msg');
        $b   = $this->db->table('inbox i')
            ->select('i.id_inbox, i.judul, i.url, i.is_read, i.created_at')
            ->select("(SELECT MAX(m.id_inbox_msg) FROM {$msg} m WHERE m.id_inbox = i.id_inbox) AS id_pesan", false)
            ->where('i.nip_penerima', $nip);
        $hanyaBelumDibaca && $b->where('i.is_read', 0);

        $rows = $b->orderBy('id_pesan', 'DESC', false)->orderBy('i.id_inbox', 'DESC')->limit($batas)->get()->getResultArray();
        $ids  = array_values(array_filter(array_map(static fn (array $r): int => (int) ($r['id_pesan'] ?? 0), $rows)));

        $pesan = [];

        if ($ids !== []) {
            foreach ($this->db->table('inbox_msg')->select('id_inbox_msg, tipe, isi, reason_note, created_at')->whereIn('id_inbox_msg', $ids)->get()->getResultArray() as $p) {
                $pesan[(int) $p['id_inbox_msg']] = $p;
            }
        }

        $hasil = [];

        foreach ($rows as $r) {
            $p       = $pesan[(int) ($r['id_pesan'] ?? 0)] ?? null;
            $hasil[] = [
                'id'            => self::KUNCI . ':' . (int) $r['id_inbox'],
                'sumber'        => self::KUNCI,
                'judul'         => (string) $r['judul'],
                'keterangan'    => $p === null ? '' : (string) $p['isi'],
                'status_label'  => $p === null ? null : (self::TIPE[(int) $p['tipe']] ?? null),
                'reason_note'   => $p === null || $p['reason_note'] === null ? null : (string) $p['reason_note'],
                'url_fe'        => (string) $r['url'],
                'dibaca'        => (int) $r['is_read'] === 1,
                'waktu'         => (string) ($p['created_at'] ?? $r['created_at']),
                'dapat_dihapus' => false,
            ];
        }

        return $hasil;
    }

    public function tandaiDibaca(AuthContext $auth, string $kunci, array $ids): int
    {
        $nip = $this->nip($auth);

        if ($nip === null || $kunci !== self::KUNCI || $ids === []) {
            return 0;
        }

        $this->db->table('inbox')->where('nip_penerima', $nip)->where('is_read', 0)->whereIn('id_inbox', $ids)
            ->update(['is_read' => 1, 'read_at' => Time::now('UTC')->toDateTimeString()]);

        return $this->db->affectedRows();
    }

    public function hapus(AuthContext $auth, string $kunci, int $id): bool
    {
        return false;
    }

    private function nip(AuthContext $auth): ?string
    {
        $nip = $auth->nip();

        return $nip === null || $nip === '' ? null : $nip;
    }
}
