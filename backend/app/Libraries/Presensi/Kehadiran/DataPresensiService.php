<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Kehadiran;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use App\Libraries\ListQuery;
use App\Libraries\Presensi\SlipGaji\PencocokanNama;
use CodeIgniter\Database\BaseConnection;

/**
 * Lihat data presensi oleh admin (PRD 5A §6.4, §7.6.4, F5-15): role 1, 3, 4, 5 dengan lingkup `PegawaiScope` (role 3
 * satker sendiri). `aksi {ubah, hapus}` dan `can_create`/`can_kelola_manual` true **hanya** untuk akun khusus presensi
 * manual [K `Presensi.php:1274-1276`]. Koordinat tidak pernah dikirim di daftar (§8.6) — hanya `sumber` dan id/nama lokasi.
 */
final class DataPresensiService
{
    /** Role route data presensi admin (legacy `list_ad` 1/4, `list/{nip}` 1/4/5 + role 3 lingkup satker, Q5A-09). */
    public const ROLE = [Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI];

    /** Bawaan baris terbaru detail pegawai [K `L_presensi.php:3281-3290`]. */
    public const BAWAAN_PER_PAGE = 100;

    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly ?PresensiManualService $manual = null,
        private readonly ?PegawaiScope $scope = null,
    ) {
    }

    /**
     * `GET presensi/pegawai?search=&id_unit=&id_satker=&page=&per_page=`.
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function daftarPegawai(AuthContext $auth, array $query): array
    {
        $this->wajibRole($auth);
        $q       = ListQuery::from($query, ['search', 'id_unit', 'id_satker', 'page', 'per_page']);
        $page    = $q->page();
        $perPage = $q->perPage(20, 100);
        $db      = $this->koneksi();

        $builder = function () use ($db, $auth, $q) {
            $b = $db->table('pegawai p')
                ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = p.nip', 'left')
                ->join('unit u', 'u.id_unit = pmj.id_unit', 'left')
                ->join('satker s', 's.id_satker = pmj.id_satker', 'left');
            $this->scope()->terapkanKeQuery($b, $auth, 'p.nip');

            foreach (['id_unit', 'id_satker'] as $f) {
                if ($q->filled($f)) {
                    if (! ctype_digit($q->string($f))) {
                        throw ValidationException::forField($f, 'Filter tidak valid.');
                    }

                    $b->where("pmj.{$f}", (int) $q->string($f));
                }
            }

            if ($q->filled('search')) {
                $cari = ListQuery::likeLiteral($db, $q->string('search'));
                $b->groupStart()->like('p.nip', $cari, 'both', null, true)->orLike('p.nama', $cari, 'both', null, true)->groupEnd();
            }

            return $b;
        };

        $total = $builder()->countAllResults();
        $rows  = $builder()->select('p.nip, p.nama, p.glr_awal, p.glr_akhir, u.unit, s.satker')
            ->orderBy('p.nama')->orderBy('p.nip')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'items' => array_map(static fn (array $r): array => [
                'nip'    => $r['nip'],
                'nama'   => PencocokanNama::denganGelar($r['glr_awal'] ?? null, (string) $r['nama'], $r['glr_akhir'] ?? null),
                'unit'   => $r['unit'],
                'satker' => $r['satker'],
            ], $rows),
            'total'             => $total,
            'page'              => $page,
            'per_page'          => $perPage,
            'can_kelola_manual' => $this->manual()->akunKhusus($auth),
        ];
    }

    /**
     * `GET presensi/pegawai/{nip}?tgl_mulai=&tgl_akhir=&page=&per_page=` — bawaan 100 baris terbaru.
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function detailPegawai(AuthContext $auth, string $nip, array $query): array
    {
        $this->wajibRole($auth);
        $db = $this->koneksi();

        $pegawai = $db->table('pegawai')->select('nip, nama, glr_awal, glr_akhir')->where('nip', $nip)->get()->getRowArray();

        if ($pegawai === null) {
            throw new NotFoundException('Pegawai tidak ditemukan.');
        }

        if (! $this->scope()->bolehLihat($auth, $nip)) {
            throw new ForbiddenException('Pegawai di luar lingkup Anda.');
        }

        $q       = ListQuery::from($query, ['tgl_mulai', 'tgl_akhir', 'page', 'per_page']);
        $page    = $q->page();
        $perPage = $q->perPage(self::BAWAAN_PER_PAGE, self::BAWAAN_PER_PAGE);
        $khusus  = $this->manual()->akunKhusus($auth);
        $builder = function () use ($db, $nip, $q) {
            $b = $db->table('dh_online d')->join('lokasi_presensi lm', 'lm.id_lokasi_presensi = d.id_lokasi_masuk', 'left')
                ->join('lokasi_presensi lp', 'lp.id_lokasi_presensi = d.id_lokasi_pulang', 'left')
                ->where('d.nip', $nip);

            foreach (['tgl_mulai' => '>=', 'tgl_akhir' => '<='] as $f => $op) {
                if ($q->filled($f)) {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $q->string($f)) !== 1) {
                        throw ValidationException::forField($f, 'Format tanggal harus YYYY-MM-DD.');
                    }

                    $b->where("d.tgl {$op}", $q->string($f));
                }
            }

            return $b;
        };

        $total = $builder()->countAllResults();
        $rows  = $builder()->select('d.nip, d.tgl, d.jam_masuk, d.jam_pulang, d.sumber, d.id_lokasi_masuk, lm.nama_lokasi AS lokasi_masuk, d.id_lokasi_pulang, lp.nama_lokasi AS lokasi_pulang')
            ->orderBy('d.tgl', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'pegawai'    => ['nip' => $pegawai['nip'], 'nama' => PencocokanNama::denganGelar($pegawai['glr_awal'] ?? null, (string) $pegawai['nama'], $pegawai['glr_akhir'] ?? null)],
            'items'      => array_map(static fn (array $r): array => [...$r, 'sumber' => $r['sumber'] === null ? null : (int) $r['sumber'], 'aksi' => ['ubah' => $khusus, 'hapus' => $khusus]], $rows),
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'can_create' => $khusus,
        ];
    }

    private function wajibRole(AuthContext $auth): void
    {
        if (! in_array($auth->role(), self::ROLE, true)) {
            throw new ForbiddenException('Anda tidak berhak melihat data presensi.');
        }
    }

    private function manual(): PresensiManualService
    {
        return $this->manual ?? new PresensiManualService($this->db);
    }

    private function scope(): PegawaiScope
    {
        return $this->scope ?? new PegawaiScope($this->db);
    }

    private function koneksi(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
