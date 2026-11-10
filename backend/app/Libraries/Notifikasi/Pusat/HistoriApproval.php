<?php

declare(strict_types=1);

namespace App\Libraries\Notifikasi\Pusat;

use App\Constants\Role;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Interfaces\Kepegawaian\PegawaiScopeProsesInterface;
use App\Interfaces\Kepegawaian\RiwayatRegistryInterface;
use App\Interfaces\Notifikasi\SumberNotifikasiInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * S4 — histori approval per bulan (legacy `ah`, `L_notification.php:3346-4183`; Q7-35 kolom [K], presedensi diperbaiki).
 *
 * - Role 1: riwayat/usulan/Konket/biodata dengan `show_ua_biro` = 1 pada bulan `notif_date` (`LN:3379-3423`) + berita.
 * - Role 3: `show_ua_upt` (admin bersatker) / `show_ua_deputi` (tanpa satker) dalam lingkup proses (`LN:3554-3968`).
 *   Bug legacy `pegawai_hist` memakai `show_ua_deputi` untuk admin satker (`LN:3812`) tidak dibawa [V2].
 * - Role 2: LKH yang diverifikasi (`nip_atasan`, `status != 0`, `show_history_atasan` 1, bulan `updated_at`) dan cuti yang
 *   diputus sebagai AL (`status_al != 0`) atau PyB (`status_pyb != 0`) — OR legacy tanpa kurung (`LN:4002`) diperbaiki.
 *   Cuti memakai bulan `updated_at` karena alur cuti v2 tidak mengisi `notif_date` [V2]. TB/IB/PMK PIC belum: alur v2
 *   tidak mengisi `show_pl` / penanda pemroses (dicatat, tindak lanjut adapter).
 */
final class HistoriApproval
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly RiwayatRegistryInterface $registry,
        private readonly PegawaiScopeInterface $scope,
        private readonly SumberNotifikasiInterface $berita,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function daftar(AuthContext $auth, string $bulan, int $batas): array
    {
        [$tahun, $bln] = array_map('intval', explode('-', $bulan));
        $role          = (int) $auth->role();
        $sumber        = [];

        if (in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true)) {
            $kolomUa = $role === Role::SUPER_ADMIN ? 'show_ua_biro' : (((string) ($auth->idSatker() ?? '')) !== '' ? 'show_ua_upt' : 'show_ua_deputi');

            foreach ($this->registry->semua() as $d) {
                if (in_array($d->alur(), [AlurRiwayat::SelfService, AlurRiwayat::Usulan], true)) {
                    $sumber[] = $this->sumberRiwayat($auth, $d, $kolomUa, $tahun, $bln);
                }
            }

            $sumber[] = $this->sumberTabel($auth, 'konket', 'Usulan Konket', 'absen_ijin', 'id', 'status', $kolomUa, $tahun, $bln, '/konket', ['V' => 'Disetujui', 'X' => 'Ditolak']);
            $sumber[] = $this->sumberTabel($auth, 'biodata', 'Pengajuan Perubahan Biodata', 'pegawai_hist', 'id_pegawai_hist', 'flag_update', $kolomUa, $tahun, $bln, '/pegawai/{nip}', ['3' => 'Disetujui', '2' => 'Ditolak']);
        } elseif ($role === Role::PEGAWAI && ($nip = (string) ($auth->nip() ?? '')) !== '') {
            $sumber[] = $this->sumberLkh($nip, $tahun, $bln);
            $sumber[] = $this->sumberCuti($nip, $tahun, $bln);
        }

        $items = [];
        $total = 0;

        foreach (array_filter($sumber) as [$hitung, $ambil]) {
            $total += $hitung()->countAllResults();
            array_push($items, ...$ambil($batas));
        }

        if ($role === Role::SUPER_ADMIN && $this->berita->tersedia()) {
            $b = $this->berita->histori($auth, $bulan, 1, $batas);
            $total += $b['total'];

            foreach ($b['items'] as $i) {
                $items[] = ['id' => $this->berita->kode() . ':' . (int) $i['id'], 'sumber' => $this->berita->kode()] + $i;
            }
        }

        usort($items, static fn (array $a, array $b): int => [$b['waktu'], $b['id']] <=> [$a['waktu'], $a['id']]);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array{0: callable(): BaseBuilder, 1: callable(int): list<array<string, mixed>>}|null
     */
    private function sumberRiwayat(AuthContext $auth, RiwayatDefinisi $d, string $kolomUa, int $tahun, int $bln): ?array
    {
        $url = $d->alur() === AlurRiwayat::Usulan ? "/karpeg-karis?jenis={$d->jenis()}" : '/pegawai/{nip}?tab=' . $d->jenis();

        return $this->sumberTabel($auth, $d->jenis(), $d->label(), $d->tabel(), $d->primaryKey(), $d->kolomStatus(), $kolomUa, $tahun, $bln, $url, ['1' => 'Disetujui', '2' => 'Ditolak']);
    }

    /**
     * @param array<int|string, string> $label
     *
     * @return array{0: callable(): BaseBuilder, 1: callable(int): list<array<string, mixed>>}|null
     */
    private function sumberTabel(AuthContext $auth, string $kunci, string $judul, string $tabel, string $pk, string $kolomStatus, string $kolomUa, int $tahun, int $bln, string $url, array $label): ?array
    {
        if (! $this->db->tableExists($this->db->prefixTable($tabel), false)) {
            return null;
        }

        $kolom = $this->db->getFieldNames($tabel);

        if (! in_array($kolomUa, $kolom, true) || ! in_array('notif_date', $kolom, true) || ! in_array('nip', $kolom, true)) {
            return null;
        }

        $bangun = function () use ($auth, $tabel, $kolomUa, $tahun, $bln, $kolom): BaseBuilder {
            $b = $this->db->table("{$tabel} t")->where("t.{$kolomUa}", 1)
                ->where('YEAR(t.notif_date)', $tahun, false)->where('MONTH(t.notif_date)', $bln, false);
            in_array('status', $kolom, true) && $b->where('t.status !=', '10');

            if ((int) $auth->role() !== Role::SUPER_ADMIN) {
                $this->scope instanceof PegawaiScopeProsesInterface
                    ? $this->scope->terapkanKeQueryProses($b, $auth, 't.nip')
                    : $this->scope->terapkanKeQuery($b, $auth, 't.nip');
            }

            return $b;
        };

        $ambil = static function (int $batas) use ($bangun, $kunci, $judul, $pk, $kolomStatus, $url, $label): array {
            $rows = $bangun()->select("t.{$pk} AS id, t.nip, t.{$kolomStatus} AS status_baris, t.notif_date AS waktu, p.nama")
                ->join('pegawai p', 'p.nip = t.nip', 'left')
                ->orderBy('t.notif_date', 'DESC')->orderBy("t.{$pk}", 'DESC')->limit($batas)->get()->getResultArray();

            return array_map(static fn (array $r): array => [
                'id'           => $kunci . ':' . (int) $r['id'],
                'sumber'       => $kunci,
                'judul'        => $judul,
                'keterangan'   => $judul,
                'status_label' => $label[(string) $r['status_baris']] ?? null,
                'nip'          => (string) $r['nip'],
                'nama'         => $r['nama'] === null ? null : (string) $r['nama'],
                'url_fe'       => str_replace('{nip}', rawurlencode((string) $r['nip']), $url),
                'waktu'        => (string) ($r['waktu'] ?? ''),
            ], $rows);
        };

        return [$bangun, $ambil];
    }

    /**
     * @return array{0: callable(): BaseBuilder, 1: callable(int): list<array<string, mixed>>}
     */
    private function sumberLkh(string $nip, int $tahun, int $bln): array
    {
        $bangun = fn (): BaseBuilder => $this->db->table('riwayat_lckh t')->where('t.nip_atasan', $nip)
            ->where('t.status !=', 0)->where('t.status !=', 10)->where('t.show_history_atasan', 1)
            ->where('YEAR(t.updated_at)', $tahun, false)->where('MONTH(t.updated_at)', $bln, false);

        $ambil = static fn (int $batas): array => array_map(static fn (array $r): array => [
            'id'           => 'lkh:' . (int) $r['id_riwayat_lckh'],
            'sumber'       => 'lkh',
            'judul'        => 'Laporan Kinerja Harian',
            'keterangan'   => 'Verifikasi LKH',
            'status_label' => ['1' => 'Disetujui', '2' => 'Ditolak', '3' => 'Revisi'][(string) $r['status']] ?? null,
            'nip'          => (string) $r['nip'],
            'nama'         => $r['nama'] === null ? null : (string) $r['nama'],
            'url_fe'       => '/lkh/verifikasi?tab=verifikasi',
            'waktu'        => (string) ($r['updated_at'] ?? ''),
        ], $bangun()->select('t.id_riwayat_lckh, t.nip, t.nama, t.status, t.updated_at')->orderBy('t.updated_at', 'DESC')->limit($batas)->get()->getResultArray());

        return [$bangun, $ambil];
    }

    /**
     * @return array{0: callable(): BaseBuilder, 1: callable(int): list<array<string, mixed>>}
     */
    private function sumberCuti(string $nip, int $tahun, int $bln): array
    {
        $bangun = fn (): BaseBuilder => $this->db->table('riwayat_cuti t')
            ->groupStart()
            ->groupStart()->where('t.nip_atasan_langsung', $nip)->where('t.status_al !=', 0)->groupEnd()
            ->orGroupStart()->where('t.nip_yang_menyetujui', $nip)->where('t.status_pyb !=', 0)->groupEnd()
            ->groupEnd()
            ->where('YEAR(t.updated_at)', $tahun, false)->where('MONTH(t.updated_at)', $bln, false);

        $ambil = static fn (int $batas): array => array_map(static fn (array $r): array => [
            'id'           => 'cuti:' . (int) $r['id_riwayat_cuti'],
            'sumber'       => 'cuti',
            'judul'        => 'Cuti',
            'keterangan'   => 'Persetujuan cuti',
            'status_label' => null,
            'nip'          => (string) $r['nip'],
            'nama'         => $r['nama'] === null ? null : (string) $r['nama'],
            'url_fe'       => '/layanan/cuti/' . (int) $r['id_riwayat_cuti'],
            'waktu'        => (string) ($r['updated_at'] ?? ''),
        ], $bangun()->select('t.id_riwayat_cuti, t.nip, t.nama, t.updated_at')->orderBy('t.updated_at', 'DESC')->limit($batas)->get()->getResultArray());

        return [$bangun, $ambil];
    }
}
