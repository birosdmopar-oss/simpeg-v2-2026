<?php

declare(strict_types=1);

namespace App\Libraries\Notifikasi\Pusat;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Interfaces\Kepegawaian\RiwayatRegistryInterface;
use App\Interfaces\Notifikasi\RingkasanTiketInterface;
use App\Interfaces\Notifikasi\SumberNotifikasiInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\ListQuery;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Config\Database;

/**
 * F-08 Pusat Notifikasi & Inbox (MAKE-085, PRD Fase 7 v2.0 §5.9) — agregator BACA-SAJA + penanda baca/hapus.
 *
 * Sumber: S1 {@see SumberInboxLayanan}, S2 {@see SumberShowNotif}, sumber eksternal lewat {@see SumberNotifikasiInterface}
 * (berita, F-07), S3 {@see ButuhApproval}, S4 {@see HistoriApproval}. Tiket Halo Simpeg hanya angka lewat
 * {@see RingkasanTiketInterface} (BP8-1). Role ikut legacy (Q7-29): daftar 2,3,6,7; hapus 1,2,6,7; butuh approval &
 * histori 1,2,3; ringkasan semua role login (penghitung tiket & berita legacy dihitung untuk semua role, `MY_Controller:27-28,117-123`;
 * angka notifikasi hanya 2,3,6,7, angka butuh approval hanya 1,2,3 — `MY_Controller:127-171`).
 */
final class PusatNotifikasiService
{
    public const ROLE_DAFTAR = [Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK];

    public const ROLE_HAPUS = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::PTT, Role::PPPK];

    public const ROLE_APPROVAL = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER];

    /**
     * Daftar gabungan lintas sumber memuat paling banyak N notifikasi terbaru (paginasi gabungan, B-F8-5) [V2].
     */
    public const BATAS_GABUNGAN = 500;

    public const MAKS_ID_BACA = 100;

    private const POLA_ID = '/^([a-z][a-z0-9-]*):([1-9][0-9]{0,18})$/';

    private readonly BaseConnection $db;

    private readonly SumberNotifikasiInterface $berita;

    private readonly RingkasanTiketInterface $tiket;

    /**
     * @var list<SumberDaftar>
     */
    private readonly array $sumber;

    private readonly ButuhApproval $approval;

    private readonly HistoriApproval $histori;

    public function __construct(
        ?BaseConnection $db = null,
        ?RiwayatRegistryInterface $registry = null,
        ?PegawaiScopeInterface $scope = null,
        ?SumberNotifikasiInterface $berita = null,
        ?RingkasanTiketInterface $tiket = null,
        ?ButuhApproval $approval = null,
    ) {
        $this->db = $db ?? Database::connect();
        $registry ??= service('riwayatRegistry');
        $scope ??= service('pegawaiScope');
        $this->berita = $berita ?? service('sumberNotifikasiBerita');
        $this->tiket  = $tiket ?? service('ringkasanTiket');

        $this->sumber = [
            new SumberInboxLayanan($this->db),
            new SumberShowNotif($this->db, $registry),
            new AdapterSumberEksternal($this->berita),
        ];
        $this->approval = $approval ?? new ButuhApproval($this->db, $registry, $scope, $this->berita);
        $this->histori  = new HistoriApproval($this->db, $registry, $scope, $this->berita);
    }

    /**
     * Kontrak lonceng (PRD Fase 7 v2.0 §7 C → D).
     *
     * @return array{notifikasi_belum_dibaca: int, butuh_approval: int, berita_belum_dibaca: int|null, tiket_belum_dibaca: int|null, diperbarui_at: string}
     */
    public function ringkasan(AuthContext $auth): array
    {
        $role = (int) $auth->role();

        if (! Role::isValid($role)) {
            throw new ForbiddenException();
        }

        $belum = 0;

        if (in_array($role, self::ROLE_DAFTAR, true)) {
            foreach ($this->sumber as $s) {
                $belum += $s->kunci($auth) === [] ? 0 : $s->jumlah($auth, true);
            }
        }

        return [
            'notifikasi_belum_dibaca' => $belum,
            'butuh_approval'          => in_array($role, self::ROLE_APPROVAL, true) ? $this->approval->total($auth) : 0,
            'berita_belum_dibaca'     => $this->berita->tersedia() ? $this->berita->jumlahLonceng($auth) : null,
            'tiket_belum_dibaca'      => $this->tiket->jumlahBelumDibaca($auth),
            'diperbarui_at'           => (string) self::iso(Time::now('UTC')->toDateTimeString()),
        ];
    }

    /**
     * @param array<string, mixed> $query status=belum|semua, page, per_page
     *
     * @return array{items: list<array<string, mixed>>, total: int, belum_dibaca: int, page: int, per_page: int}
     */
    public function daftar(AuthContext $auth, array $query): array
    {
        $this->wajibRole($auth, self::ROLE_DAFTAR);

        $q      = ListQuery::from($query, ['status', 'page', 'per_page']);
        $status = $q->filled('status') ? $q->string('status') : 'semua';

        if (! in_array($status, ['belum', 'semua'], true)) {
            throw ValidationException::forField('status', 'Status harus belum atau semua.');
        }

        $page     = $q->page();
        $perPage  = $q->perPage(20, 50);
        $batas    = min(self::BATAS_GABUNGAN, $page * $perPage);
        $belum    = $status === 'belum';
        $items    = [];
        $total    = 0;
        $jmlBelum = 0;

        foreach ($this->sumber as $s) {
            if ($s->kunci($auth) === []) {
                continue;
            }

            $total += $s->jumlah($auth, $belum);
            $jmlBelum += $belum ? 0 : $s->jumlah($auth, true);
            array_push($items, ...$s->daftar($auth, $belum, $batas));
        }

        return [
            'items'        => array_map(self::keluaran(...), array_slice(self::urut($items), ($page - 1) * $perPage, $perPage)),
            'total'        => min($total, self::BATAS_GABUNGAN),
            'belum_dibaca' => $belum ? $total : $jmlBelum,
            'page'         => $page,
            'per_page'     => $perPage,
        ];
    }

    /**
     * Tandai dibaca (Q7-31): `{ ids: ["<sumber>:<id>", ...] }` atau `{ semua: true }`. Id milik orang lain/tidak dikenal
     * dilewati tanpa galat (hanya dihitung yang berubah).
     *
     * @param array<string, mixed> $payload
     *
     * @return array{diubah: int}
     */
    public function baca(AuthContext $auth, array $payload): array
    {
        $this->wajibRole($auth, self::ROLE_DAFTAR);

        if (($payload['semua'] ?? null) === true || ($payload['semua'] ?? null) === 1 || ($payload['semua'] ?? null) === '1') {
            $ids = [];

            foreach ($this->sumber as $s) {
                if ($s->kunci($auth) !== []) {
                    array_push($ids, ...array_column($s->daftar($auth, true, self::BATAS_GABUNGAN), 'id'));
                }
            }
        } else {
            $ids = $payload['ids'] ?? null;

            if (! is_array($ids) || $ids === [] || count($ids) > self::MAKS_ID_BACA || ! array_is_list($ids)) {
                throw ValidationException::forField('ids', 'Isi 1–' . self::MAKS_ID_BACA . ' id notifikasi, atau semua = true.');
            }
        }

        $perKunci = [];

        foreach ($ids as $id) {
            if (! is_string($id) || preg_match(self::POLA_ID, $id, $m) !== 1) {
                throw ValidationException::forField('ids', 'Format id notifikasi tidak dikenal.');
            }

            $perKunci[$m[1]][] = (int) $m[2];
        }

        $diubah = 0;

        foreach ($perKunci as $kunci => $daftarId) {
            foreach ($this->sumber as $s) {
                if (in_array($kunci, $s->kunci($auth), true)) {
                    $diubah += $s->tandaiDibaca($auth, (string) $kunci, array_values(array_unique($daftarId)));
                }
            }
        }

        return ['diubah' => $diubah];
    }

    /**
     * Hapus dari daftar notifikasi (`show_notif` 2 [K]); notifikasi layanan tidak bisa dihapus (Q7-33) → 404.
     *
     * @return array{dihapus: true}
     */
    public function hapus(AuthContext $auth, string $sumber, string $id): array
    {
        $this->wajibRole($auth, self::ROLE_HAPUS);

        if (preg_match(self::POLA_ID, "{$sumber}:{$id}") !== 1) {
            throw new NotFoundException('Notifikasi tidak ditemukan.');
        }

        foreach ($this->sumber as $s) {
            if (in_array($sumber, $s->kunci($auth), true) && $s->hapus($auth, $sumber, (int) $id)) {
                return ['dihapus' => true];
            }
        }

        throw new NotFoundException('Notifikasi tidak ditemukan.');
    }

    /**
     * @param array<string, mixed> $query grup=riwayat, page, per_page
     *
     * @return array{total: int, grup: list<array{kode: string, label: string, jumlah: int, url_fe: ?string}>, riwayat: array<string, mixed>|null}
     */
    public function butuhApproval(AuthContext $auth, array $query): array
    {
        $this->wajibRole($auth, self::ROLE_APPROVAL);

        $q       = ListQuery::from($query, ['grup', 'page', 'per_page']);
        $grup    = $this->approval->grup($auth);
        $riwayat = null;

        if (in_array((int) $auth->role(), [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true)) {
            $hasil          = $this->approval->daftarRiwayat($auth, $q->page(), $q->perPage(20, 50));
            $hasil['items'] = array_map(self::keluaran(...), $hasil['items']);
            $riwayat        = $hasil;
        }

        return ['total' => array_sum(array_column($grup, 'jumlah')), 'grup' => $grup, 'riwayat' => $riwayat];
    }

    /**
     * @param array<string, mixed> $query bulan=YYYY-MM (12 bulan terakhir [K], bawaan bulan ini), page, per_page
     *
     * @return array{bulan: string, pilihan_bulan: list<string>, items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function historiApproval(AuthContext $auth, array $query): array
    {
        $this->wajibRole($auth, self::ROLE_APPROVAL);

        $q       = ListQuery::from($query, ['bulan', 'page', 'per_page']);
        $pilihan = self::duaBelasBulan();
        $bulan   = $q->filled('bulan') ? $q->string('bulan') : $pilihan[0];

        if (! in_array($bulan, $pilihan, true)) {
            throw ValidationException::forField('bulan', 'Bulan harus salah satu dari 12 bulan terakhir (YYYY-MM).');
        }

        $page    = $q->page();
        $perPage = $q->perPage(20, 50);
        $hasil   = $this->histori->daftar($auth, $bulan, min(self::BATAS_GABUNGAN, $page * $perPage));

        return [
            'bulan'         => $bulan,
            'pilihan_bulan' => $pilihan,
            'items'         => array_map(self::keluaran(...), array_slice($hasil['items'], ($page - 1) * $perPage, $perPage)),
            'total'         => $hasil['total'],
            'page'          => $page,
            'per_page'      => $perPage,
        ];
    }

    /**
     * Bulan ini lalu 11 bulan sebelumnya, `YYYY-MM` (legacy `NC:72-95`: 12 bulan terakhir).
     *
     * @return list<string>
     */
    public static function duaBelasBulan(): array
    {
        $awal  = Time::now('Asia/Jakarta')->setDay(1);
        $hasil = [];

        for ($i = 0; $i < 12; $i++) {
            $hasil[] = $awal->subMonths($i)->format('Y-m');
        }

        return $hasil;
    }

    /**
     * @param list<int> $role
     */
    private function wajibRole(AuthContext $auth, array $role): void
    {
        if (! in_array((int) $auth->role(), $role, true)) {
            throw new ForbiddenException();
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private static function urut(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => [(string) $b['waktu'], (string) $b['id']] <=> [(string) $a['waktu'], (string) $a['id']]);

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private static function keluaran(array $item): array
    {
        $item['waktu'] = self::iso((string) ($item['waktu'] ?? ''));

        return $item;
    }

    private static function iso(string $waktu): ?string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $waktu, $m) !== 1 || str_starts_with($waktu, '0000')) {
            return null;
        }

        return "{$m[1]}T{$m[2]}Z";
    }
}
