<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten\Berita;

use App\Constants\Role;
use App\Interfaces\Notifikasi\SumberNotifikasiInterface;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Sumber notifikasi berita untuk Pusat Notifikasi F-08 (kontrak A ↔ C, PRD Fase 7 v2.0 §7; legacy `L_notification.php:2893-3100`).
 * Pusat notifikasi TIDAK membaca tabel `news` langsung — hanya lewat kelas ini.
 *
 * - Notifikasi pembuat (role 3): hasil persetujuan berita miliknya lewat `news.show_notif` — `1` baru (diisi saat diproses),
 *   `3` dibaca, `2` dihapus pembuat (tidak tampil lagi). Belum dibaca = 1. Nilai awal kolom ⚑ menunggu D1.
 * - Antrean "Butuh Approval" (role 1): berita `status = 0`. Histori (role 1): berita yang diproses pemanggil pada bulan itu.
 * - Penghitung lonceng (semua role): berita terbit yang belum dibuka pembaca (`news_flag`), {@see BeritaService::jumlahBelumDibuka()}.
 * Role lain tidak punya notifikasi/antrean berita (daftar kosong, hitungan 0).
 */
final class SumberBerita implements SumberNotifikasiInterface
{
    private const URL_KELOLA = '/konten/berita';

    private readonly BeritaService $berita;

    public function __construct(private readonly BaseConnection $db, ?BeritaService $berita = null)
    {
        $this->berita = $berita ?? new BeritaService($db);
    }

    public function kode(): string
    {
        return 'berita';
    }

    public function tersedia(): bool
    {
        return true;
    }

    public function jumlah(AuthContext $auth, bool $hanyaBelumDibaca): int
    {
        $b = $this->milikPembuat($auth);

        if ($b === null) {
            return 0;
        }

        $hanyaBelumDibaca ? $b->where('news.show_notif', 1) : $b->whereIn('news.show_notif', [1, 3]);

        return (int) $b->countAllResults();
    }

    public function daftar(AuthContext $auth, bool $hanyaBelumDibaca, int $batas): array
    {
        $b = $this->milikPembuat($auth);

        if ($b === null || $batas < 1) {
            return [];
        }

        $hanyaBelumDibaca ? $b->where('news.show_notif', 1) : $b->whereIn('news.show_notif', [1, 3]);
        $rows = $b->select('news.id_news, news.title, news.status, news.reason_note, news.show_notif, news.approved_at, news.updated_at, news.created_at')
            ->orderBy('news.approved_at', 'DESC')->orderBy('news.id_news', 'DESC')->limit($batas)->get()->getResultArray();

        return array_map(fn (array $r): array => $this->item($r) + ['dibaca' => (int) $r['show_notif'] === 3, 'dapat_dihapus' => true], $rows);
    }

    public function tandaiDibaca(AuthContext $auth, array $ids): int
    {
        $b   = $this->milikPembuat($auth);
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));

        if ($b === null || $ids === []) {
            return 0;
        }

        $target = array_map('intval', array_column($b->select('news.id_news')->whereIn('news.id_news', $ids)->where('news.show_notif', 1)->get()->getResultArray(), 'id_news'));

        if ($target === []) {
            return 0;
        }

        $this->db->table('news')->whereIn('id_news', $target)->update(['show_notif' => 3]);

        return count($target);
    }

    public function hapus(AuthContext $auth, int $id): bool
    {
        $b = $this->milikPembuat($auth);

        if ($b === null) {
            return false;
        }

        $ada = $b->select('news.id_news')->where('news.id_news', $id)->whereIn('news.show_notif', [1, 3])->countAllResults();

        if ($ada === 0) {
            return false;
        }

        $this->db->table('news')->where('id_news', $id)->update(['show_notif' => 2]);

        return true;
    }

    public function jumlahMenunggu(AuthContext $auth): int
    {
        if ((int) $auth->role() !== Role::SUPER_ADMIN) {
            return 0;
        }

        return (int) $this->db->table('news')->where('status', BeritaService::MENUNGGU)->countAllResults();
    }

    public function daftarMenunggu(AuthContext $auth, int $page, int $perPage): array
    {
        if ((int) $auth->role() !== Role::SUPER_ADMIN) {
            return ['items' => [], 'total' => 0, 'url_fe' => null];
        }

        $saring = static function (BaseBuilder $b): void {
            $b->where('news.status', BeritaService::MENUNGGU);
        };

        return ['items' => $this->halaman($saring, 'news.created_at', $page, $perPage), 'total' => $this->total($saring), 'url_fe' => self::URL_KELOLA];
    }

    public function histori(AuthContext $auth, string $bulan, int $page, int $perPage): array
    {
        $id = $auth->idPengguna();

        if ((int) $auth->role() !== Role::SUPER_ADMIN || $id === null || preg_match('/^\d{4}-(0[1-9]|1[0-2])\z/', $bulan) !== 1) {
            return ['items' => [], 'total' => 0];
        }

        $awal   = new DateTimeImmutable($bulan . '-01 00:00:00', new DateTimeZone('UTC'));
        $akhir  = $awal->modify('+1 month');
        $saring = static function (BaseBuilder $b) use ($id, $awal, $akhir): void {
            $b->where('news.approved_by', $id)
                ->where('news.approved_at >=', $awal->format('Y-m-d H:i:s'))
                ->where('news.approved_at <', $akhir->format('Y-m-d H:i:s'));
        };

        return ['items' => $this->halaman($saring, 'news.approved_at', $page, $perPage), 'total' => $this->total($saring)];
    }

    public function jumlahLonceng(AuthContext $auth): int
    {
        return $this->berita->jumlahBelumDibuka($auth);
    }

    // ------------------------------------------------------------------

    /**
     * Berita milik pembuat (role 3: satker pembuat sama, atau unit bila tanpa satker) yang sudah diproses dan belum dihapus
     * pembuat. null = pemanggil bukan role 3.
     */
    private function milikPembuat(AuthContext $auth): ?BaseBuilder
    {
        if ((int) $auth->role() !== Role::ADMIN_SATKER) {
            return null;
        }

        $b      = $this->db->table('news')->where('news.ul_creator', Role::ADMIN_SATKER);
        $satker = $auth->idSatker();

        if ($satker !== null && ctype_digit($satker)) {
            return $b->where('news.id_satker_creator', (int) $satker);
        }

        $unit = $auth->idUnit();

        return $b->where('news.id_unit_creator', $unit !== null && ctype_digit($unit) ? (int) $unit : 0);
    }

    /**
     * Total tanpa join: countAllResults() pada builder beralias mengosongkan pelacakan alias koneksi CI4 sehingga query
     * berikutnya salah memberi prefix DBPrefix pada alias (lihat BeritaService::portal()).
     *
     * @param callable(BaseBuilder): void $saring
     */
    private function total(callable $saring): int
    {
        $b = $this->db->table('news');
        $saring($b);

        return (int) $b->countAllResults();
    }

    private function antreanDasar(): BaseBuilder
    {
        // join dulu, baru select (alias join harus dikenal sebelum select; lihat BeritaService::dasar).
        return $this->db->table('news')
            ->join('pengguna p', 'p.id_pengguna = news.created_by', 'left')
            ->join('unit u', 'u.id_unit = news.id_unit_creator', 'left')
            ->select('news.id_news, news.title, news.status, news.reason_note, news.approved_at, news.created_at, p.name AS pembuat, p.nip AS nip_pembuat, u.unit AS nama_unit');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function halaman(callable $saring, string $urut, int $page, int $perPage): array
    {
        $perPage = max(1, $perPage);
        $b       = $this->antreanDasar();
        $saring($b);
        $rows = $b->orderBy($urut, 'DESC')->orderBy('news.id_news', 'DESC')->limit($perPage, (max(1, $page) - 1) * $perPage)->get()->getResultArray();

        return array_map(fn (array $r): array => $this->item($r) + [
            'dibaca'        => true,
            'dapat_dihapus' => false,
            'nip'           => $r['nip_pembuat'] !== null ? (string) $r['nip_pembuat'] : null,
            'nama'          => $r['pembuat'] !== null ? (string) $r['pembuat'] : null,
            'unit'          => $r['nama_unit'] !== null ? (string) $r['nama_unit'] : null,
        ], $rows);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function item(array $r): array
    {
        $status = (int) $r['status'];
        $label  = match ($status) {
            BeritaService::TERBIT  => 'Disetujui',
            BeritaService::DITOLAK => 'Ditolak',
            default                => 'Menunggu persetujuan',
        };

        return [
            'id'           => (int) $r['id_news'],
            'judul'        => (string) $r['title'],
            'keterangan'   => 'Berita "' . $r['title'] . '" ' . strtolower($label) . '.',
            'status_label' => $label,
            'reason_note'  => $status === BeritaService::DITOLAK && $r['reason_note'] !== null ? (string) $r['reason_note'] : null,
            'url_fe'       => $status === BeritaService::TERBIT ? '/berita/' . $r['id_news'] : self::URL_KELOLA,
            'waktu'        => (string) ($r['approved_at'] ?? $r['created_at'] ?? gmdate('Y-m-d H:i:s')),
        ];
    }
}
