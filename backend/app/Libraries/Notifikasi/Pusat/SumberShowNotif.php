<?php

declare(strict_types=1);

namespace App\Libraries\Notifikasi\Pusat;

use App\Constants\Role;
use App\Interfaces\Kepegawaian\RiwayatRegistryInterface;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseConnection;

/**
 * S2 — notifikasi hasil proses riwayat/biodata/LKH/Konket lewat kolom `show_notif` tabel sumbernya [K].
 *
 * Legacy (`L_notification.php`): daftar UL 2/7 `LN:2464-2482` (+ halaman `LN:3056-3077`), UL 6 `LN:2765-2884`;
 * kondisi daftar `show_notif IN (1, 3, 4)`, belum dibaca = 1 (`LN:2486`, `LN:3104`); hapus = 2 (`NC:194-206`);
 * dibaca = 3 (`LN:3260-3265`, `L_<jenis>.php` detail). Penulis `show_notif` = 1 di v2: `RiwayatEngine::tandaiNotifikasi`,
 * `ApprovalBiodata`, `LkhService`, `KonketService`.
 *
 * Deviasi [V2]: cuti/TB/IB/PMK tidak dibaca dari sini (S1 `inbox` v2); baris status 10 (hapus lunak v2) dilewati;
 * biodata (`pegawai_hist`) ikut (PRD Fase 7 v2.0 §5.9); dibaca hanya saat item dibuka/"Tandai dibaca" (Q7-31);
 * UL 7 memakai daftar UL 2 untuk daftar DAN hapus (legacy `NC:153-160` memakai whitelist PTT untuk hapus, B-F8-3).
 */
final class SumberShowNotif implements SumberDaftar
{
    /**
     * Jenis riwayat (slug registry) yang ada di daftar notifikasi legacy UL 2/7, tanpa cuti/tb/ib (S1).
     */
    private const JENIS_PNS = ['kp', 'jabatan', 'kgb', 'pendidikan', 'diklat', 'seminar', 'organisasi', 'tanda-jasa', 'skp', 'keluarga', 'alamat', 'kariskarsu', 'karpeg', 'ak'];

    /**
     * Jenis riwayat di daftar legacy UL 6 (PTT, `LN:2765-2884`).
     */
    private const JENIS_PTT = ['jabatan', 'pendidikan', 'alamat'];

    private const KHUSUS = ['konket', 'lkh', 'biodata'];

    /**
     * @var array<string, array{tabel: string, pk: string, status: string, waktu: string, alasan: ?string, hapus: bool, label: string, url: string}>|null
     */
    private ?array $definisi = null;

    public function __construct(private readonly BaseConnection $db, private readonly RiwayatRegistryInterface $registry)
    {
    }

    public function kunci(AuthContext $auth): array
    {
        if ($this->nip($auth) === null) {
            return [];
        }

        $jenis = match ((int) $auth->role()) {
            Role::PEGAWAI, Role::PPPK => self::JENIS_PNS,
            Role::PTT                 => self::JENIS_PTT,
            default                   => null,
        };

        if ($jenis === null) {
            return [];
        }

        return array_values(array_filter([...$jenis, ...self::KHUSUS], fn (string $k): bool => isset($this->definisi()[$k])));
    }

    public function jumlah(AuthContext $auth, bool $hanyaBelumDibaca): int
    {
        $nip   = (string) $this->nip($auth);
        $total = 0;

        foreach ($this->kunci($auth) as $k) {
            $total += $this->builder($k, $nip, $hanyaBelumDibaca)->countAllResults();
        }

        return $total;
    }

    public function daftar(AuthContext $auth, bool $hanyaBelumDibaca, int $batas): array
    {
        $nip   = (string) $this->nip($auth);
        $hasil = [];

        foreach ($this->kunci($auth) as $k) {
            $d    = $this->definisi()[$k];
            $rows = $this->builder($k, $nip, $hanyaBelumDibaca)
                ->select("{$d['pk']} AS id, nip, {$d['status']} AS status_baris, show_notif, {$d['waktu']} AS waktu" . ($d['alasan'] === null ? '' : ", {$d['alasan']} AS alasan"))
                ->orderBy($d['waktu'], 'DESC')->orderBy($d['pk'], 'DESC')->limit($batas)->get()->getResultArray();

            foreach ($rows as $r) {
                $status  = $this->labelStatus($k, (string) $r['status_baris']);
                $hasil[] = [
                    'id'            => $k . ':' . (int) $r['id'],
                    'sumber'        => $k,
                    'judul'         => $d['label'],
                    'keterangan'    => $status === null ? $d['label'] : "{$d['label']} {$this->kecil($status)}.",
                    'status_label'  => $status,
                    'reason_note'   => isset($r['alasan']) && trim((string) $r['alasan']) !== '' ? (string) $r['alasan'] : null,
                    'url_fe'        => str_replace('{nip}', rawurlencode((string) $r['nip']), $d['url']),
                    'dibaca'        => (int) $r['show_notif'] !== 1,
                    'waktu'         => (string) ($r['waktu'] ?? ''),
                    'dapat_dihapus' => true,
                ];
            }
        }

        return $hasil;
    }

    public function tandaiDibaca(AuthContext $auth, string $kunci, array $ids): int
    {
        return $this->ubahPenanda($auth, $kunci, $ids, 1, 3);
    }

    public function hapus(AuthContext $auth, string $kunci, int $id): bool
    {
        return $this->ubahPenanda($auth, $kunci, [$id], null, 2) > 0;
    }

    /**
     * @param list<int> $ids
     */
    private function ubahPenanda(AuthContext $auth, string $kunci, array $ids, ?int $dari, int $ke): int
    {
        $nip = $this->nip($auth);

        if ($nip === null || $ids === [] || ! in_array($kunci, $this->kunci($auth), true)) {
            return 0;
        }

        $d = $this->definisi()[$kunci];
        $b = $this->builder($kunci, $nip, false)->whereIn($d['pk'], $ids);
        $dari !== null && $b->where('show_notif', $dari);
        $b->update(['show_notif' => $ke]);

        return $this->db->affectedRows();
    }

    private function builder(string $kunci, string $nip, bool $hanyaBelumDibaca): \CodeIgniter\Database\BaseBuilder
    {
        $d = $this->definisi()[$kunci];
        $b = $this->db->table($d['tabel'])->where('nip', $nip);
        $d['hapus'] && $b->where('status !=', '10');
        $hanyaBelumDibaca ? $b->where('show_notif', 1) : $b->whereIn('show_notif', [1, 3, 4]);

        return $b;
    }

    private function labelStatus(string $kunci, string $status): ?string
    {
        return match ($kunci) {
            'konket'  => ['V' => 'Disetujui', 'X' => 'Ditolak', 'W' => 'Menunggu'][$status] ?? null,
            'biodata' => ['3' => 'Disetujui', '2' => 'Ditolak', '1' => 'Menunggu'][$status] ?? null,
            'lkh'     => ['1' => 'Disetujui', '2' => 'Ditolak', '3' => 'Revisi', '0' => 'Menunggu'][$status] ?? null,
            default   => ['1' => 'Disetujui', '2' => 'Ditolak', '0' => 'Menunggu', '3' => 'Diproses'][$status] ?? null,
        };
    }

    private function kecil(string $teks): string
    {
        return mb_strtolower($teks);
    }

    /**
     * Definisi tabel per kunci; tabel tanpa kolom `show_notif` dilewati. Kolom waktu = `notif_date` bila ada, selain itu
     * `updated_at` (LKH legacy: `updated_at`, `LN:3990`).
     *
     * @return array<string, array{tabel: string, pk: string, status: string, waktu: string, alasan: ?string, hapus: bool, label: string, url: string}>
     */
    private function definisi(): array
    {
        if ($this->definisi !== null) {
            return $this->definisi;
        }

        $calon = [];

        foreach (array_unique([...self::JENIS_PNS, ...self::JENIS_PTT]) as $jenis) {
            $d = $this->registry->definisi($jenis);

            if ($d === null) {
                continue;
            }

            $url           = in_array($jenis, ['karpeg', 'kariskarsu'], true) ? "/karpeg-karis?jenis={$jenis}" : "/pegawai/{nip}?tab={$jenis}";
            $calon[$jenis] = ['tabel' => $d->tabel(), 'pk' => $d->primaryKey(), 'status' => $d->kolomStatus(), 'label' => $d->label(), 'url' => $url];
        }

        $calon['konket']  = ['tabel' => 'absen_ijin', 'pk' => 'id', 'status' => 'status', 'label' => 'Usulan Konket', 'url' => '/konket'];
        $calon['lkh']     = ['tabel' => 'riwayat_lckh', 'pk' => 'id_riwayat_lckh', 'status' => 'status', 'label' => 'Laporan Kinerja Harian', 'url' => '/lkh/verifikasi'];
        $calon['biodata'] = ['tabel' => 'pegawai_hist', 'pk' => 'id_pegawai_hist', 'status' => 'flag_update', 'label' => 'Pengajuan Perubahan Biodata', 'url' => '/pegawai/{nip}'];

        $hasil = [];

        foreach ($calon as $kunci => $c) {
            if (! $this->db->tableExists($this->db->prefixTable($c['tabel']), false)) {
                continue;
            }

            $kolom = $this->db->getFieldNames($c['tabel']);

            if (! in_array('show_notif', $kolom, true) || ! in_array($c['status'], $kolom, true) || ! in_array('nip', $kolom, true)) {
                continue;
            }

            $hasil[$kunci] = $c + [
                'waktu'  => in_array('notif_date', $kolom, true) ? 'notif_date' : 'updated_at',
                'alasan' => in_array('reason_note', $kolom, true) ? 'reason_note' : null,
                'hapus'  => in_array('status', $kolom, true),
            ];
        }

        return $this->definisi = $hasil;
    }

    private function nip(AuthContext $auth): ?string
    {
        $nip = $auth->nip();

        return $nip === null || $nip === '' ? null : $nip;
    }
}
