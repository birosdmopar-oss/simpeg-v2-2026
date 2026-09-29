<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\ListQuery;
use App\Models\MasterData\HariLiburModel;
use App\Models\MasterData\MasterModel;
use Closure;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * G-08 — Hari libur (DBV-003/CR-010, keputusan F1-F6). Legacy: hr/presensi/holiday (Presensi.php, L_presensi.php).
 *
 *  - Baca: role 1/4/5/8 (legacy Presensi.php:1015). Role 1 melihat semua status (default tanpa status 10, filter
 *    ?status=1|2|10); role 4/5/8 hanya status 1 — di legacy setiap baris dihitung sebagai libur, padanannya di v2
 *    status 1 (keputusan aplikasi CR-010).
 *  - Tulis: role 1. Status 1/2/10 (F2): hanya status 1 dihitung sebagai libur; hapus = status 10 (soft delete).
 *  - Validasi: tanggal `YYYY-MM-DD` valid (1900-2100), `tgl_akhir >= tgl_mulai` (CHECK chk_hari_libur_rentang),
 *    jenis libur wajib dan aktif (F5, hanya bila berubah), nama ≤ 100 karakter (tanpa UNIQUE nama, F4).
 *  - Overlap (Paket A): rentang inklusif tidak boleh beririsan dengan hari libur lain berstatus APA PUN (1/2/10), jadi
 *    libur yang tidak aktif/dihapus harus diaktifkan/dipulihkan atau diubah, bukan dibuat ganda. Dicek di dalam
 *    transaksi yang dijaga named lock (GET_LOCK, sesi) supaya dua penulisan paralel tidak sama-sama lolos cek.
 *    UNIQUE `tgl_mulai` (1062) dan CHECK (3819 MySQL / 4025 MariaDB) adalah lapis kedua di DB → diterjemahkan ke 422
 *    di sini (tidak lewat daftar global CR-007).
 *  - tanggalLibur(): SATU-SATUNYA sumber tanggal libur untuk presensi, tukin, uang makan, lama cuti, konket, dan LKH
 *    (Fase 5). Legacy membaca `hari_libur` tanpa filter di >20 lokasi; di v2 hanya status 1 yang dihitung.
 */
class HariLiburService
{
    /**
     * Role yang boleh membaca daftar/detail hari libur (legacy Presensi.php:1015).
     */
    public const READ_ROLES = [Role::SUPER_ADMIN, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN];

    /**
     * Role yang boleh menambah/mengubah/menghapus (legacy Presensi.php:1042, 1086, 1138).
     */
    public const WRITE_ROLES = [Role::SUPER_ADMIN];

    /**
     * Batas tunggu named lock (detik) sebelum menyerah dengan 409.
     */
    public const LOCK_TIMEOUT = 10;

    public const LOCK_BUSY_MESSAGE = 'Data hari libur sedang diubah pengguna lain. Coba lagi.';

    public const RANGE_MESSAGE = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';

    private const PER_PAGE_DEFAULT = 20;
    private const PER_PAGE_MAX     = 100;

    /**
     * Kolom audit internal (id_pengguna admin + waktu) yang tidak dikirim ke role baca saja (4/5/8).
     */
    private const AUDIT_COLUMNS = ['created_at', 'updated_at', 'updated_by'];

    private const ERR_DUPLICATE = 1062;

    /**
     * Pelanggaran CHECK: MySQL 8.0.16+ (3819), MariaDB 10.2.1+ (4025).
     */
    private const ERR_CHECK = [3819, 4025];

    /**
     * Kolom respons (urutan tetap), `jenis_libur` = nama jenis dari LEFT JOIN (null bila tanpa jenis).
     */
    private const COLUMNS = [
        'hari_libur.id_libur', 'hari_libur.id_jenis_libur', 'jenis_libur.jenis_libur', 'hari_libur.tgl_mulai',
        'hari_libur.tgl_akhir', 'hari_libur.nama_libur', 'hari_libur.keterangan', 'hari_libur.status',
        'hari_libur.created_at', 'hari_libur.updated_at', 'hari_libur.updated_by',
    ];

    private BaseConnection $db;

    private ?HariLiburModel $model = null;

    public function __construct(?BaseConnection $db = null, private int $lockTimeout = self::LOCK_TIMEOUT)
    {
        $this->db = $db ?? db_connect();
    }

    public static function canWrite(?int $role): bool
    {
        return $role !== null && in_array($role, self::WRITE_ROLES, true);
    }

    // ------------------------------------------------------------------
    // Baca
    // ------------------------------------------------------------------

    /**
     * Daftar urut `tgl_mulai DESC` (terbaru dulu, legacy :20346-20351) dengan LEFT JOIN jenis libur, sehingga baris
     * tanpa jenis (impor legacy) tetap tampil. Filter: `tahun` (rentang yang beririsan dengan tahun itu), `search`
     * (nama), `status` (role 1), `page` (dibatasi agar offset tidak meluap), `per_page` (≤ 100). Role baca saja tidak
     * menerima kolom audit (AUDIT_COLUMNS).
     *
     * @param array<string, mixed> $query
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $query, ?int $role): array
    {
        // Parameter daftar lewat ListQuery (ISSUE-019/CR-016), aturan sama dengan daftar master & akun: array/objek → 422,
        // page di luar 1..1.000.000 → 422, per_page dijepit 1..100.
        $params  = ListQuery::from($query, ['search', 'status', 'tahun', 'page', 'per_page']);
        $page    = $params->page();
        $perPage = $params->perPage(self::PER_PAGE_DEFAULT, self::PER_PAGE_MAX);
        $builder = $this->baseQuery();

        if (self::canWrite($role)) {
            $status = $params->string('status');

            if (in_array($status, [MasterModel::STATUS_ACTIVE, MasterModel::STATUS_INACTIVE, MasterModel::STATUS_DELETED], true)) {
                $builder->where('hari_libur.status', $status);
            } else {
                $builder->where('hari_libur.status !=', MasterModel::STATUS_DELETED);
            }
        } else {
            // Role baca saja: hanya hari libur yang berlaku (status 1); ?status diabaikan.
            $builder->where('hari_libur.status', MasterModel::STATUS_ACTIVE);
        }

        $tahun = $params->string('tahun');

        if ($tahun !== '') {
            if (preg_match('/^[0-9]{4}\z/', $tahun) !== 1 || (int) $tahun < HariLiburRules::YEAR_MIN || (int) $tahun > HariLiburRules::YEAR_MAX) {
                throw ValidationException::forField('tahun', 'Tahun harus 4 digit angka antara ' . HariLiburRules::YEAR_MIN . ' dan ' . HariLiburRules::YEAR_MAX . '.');
            }

            // Rentang lintas tahun (mis. 31 Des - 2 Jan) tampil di kedua tahun.
            $builder->where('hari_libur.tgl_mulai <=', "{$tahun}-12-31")->where('hari_libur.tgl_akhir >=', "{$tahun}-01-01");
        }

        $search = $params->string('search');

        if ($search !== '') {
            if (mb_strlen($search) > 100) {
                throw ValidationException::forField('search', 'Kata kunci pencarian maksimal 100 karakter.');
            }

            // `%`, `_`, dan karakter escape dicari sebagai karakter biasa (helper yang sama dengan master & akun).
            $builder->like('hari_libur.nama_libur', ListQuery::likeLiteral($this->db, $search));
        }

        $total = (clone $builder)->countAllResults();

        /** @var list<array<string, mixed>> $rows */
        $rows = $builder
            ->orderBy('hari_libur.tgl_mulai', 'DESC')
            ->orderBy('hari_libur.id_libur', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return ['items' => array_map(fn (array $row): array => $this->visibleTo($row, $role), $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Detail. Id non-kanonik ('06', '1e0') = tidak ada; role baca saja hanya melihat status 1 (selain itu 404).
     *
     * @return array<string, mixed>
     */
    public function get(string $id, ?int $role): array
    {
        $row = $this->find($id);

        if ($row === null || (! self::canWrite($role) && (string) $row['status'] !== MasterModel::STATUS_ACTIVE)) {
            throw new NotFoundException('Hari libur tidak ditemukan.');
        }

        return $this->visibleTo($row, $role);
    }

    /**
     * Role baca saja (4/5/8) tidak butuh identitas admin pengubah (`updated_by` = id_pengguna) maupun waktu audit —
     * pola bagian publik FaqService.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function visibleTo(array $row, ?int $role): array
    {
        return self::canWrite($role) ? $row : array_diff_key($row, array_flip(self::AUDIT_COLUMNS));
    }

    /**
     * Tanggal libur (`Y-m-d`, unik, terurut) di dalam [from, to] dari hari libur berstatus 1 — satu-satunya sumber
     * kalkulasi hari kerja Fase 5 (presensi, tukin, uang makan, cuti, konket, LKH). Rentang yang terpotong batas
     * [from, to] hanya dihitung bagian di dalamnya. Status jenis libur tidak berpengaruh.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException tanggal tidak valid atau from > to
     */
    public function tanggalLibur(DateTimeInterface|string $from, DateTimeInterface|string $to): array
    {
        $from = self::dateString($from, 'from');
        $to   = self::dateString($to, 'to');

        if ($from > $to) {
            throw new InvalidArgumentException("Rentang tanggal libur tidak valid: {$from} > {$to}.");
        }

        /** @var list<array{tgl_mulai: string, tgl_akhir: string}> $rows */
        $rows = $this->db->table('hari_libur')
            ->select(['tgl_mulai', 'tgl_akhir'])
            ->where('status', MasterModel::STATUS_ACTIVE)
            ->where('tgl_mulai <=', $to)
            ->where('tgl_akhir >=', $from)
            ->get()
            ->getResultArray();

        $dates = [];
        $utc   = new DateTimeZone('UTC');

        foreach ($rows as $row) {
            $day  = new DateTimeImmutable(max($from, (string) $row['tgl_mulai']), $utc);
            $last = min($to, (string) $row['tgl_akhir']);

            while (($date = $day->format('Y-m-d')) <= $last) {
                $dates[$date] = true;
                $day          = $day->modify('+1 day');
            }
        }

        $dates = array_map('strval', array_keys($dates));
        sort($dates);

        return $dates;
    }

    // ------------------------------------------------------------------
    // Tulis (role 1)
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data tgl_mulai, tgl_akhir, id_jenis_libur, nama_libur, keterangan?, status?
     *
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $row = [
            'id_jenis_libur' => $this->jenisId($data['id_jenis_libur'] ?? null),
            'tgl_mulai'      => $this->date($data['tgl_mulai'] ?? null, 'tgl_mulai'),
            'tgl_akhir'      => $this->date($data['tgl_akhir'] ?? null, 'tgl_akhir'),
            'nama_libur'     => $this->nama($data['nama_libur'] ?? null),
            'keterangan'     => $this->keterangan($data['keterangan'] ?? null),
            'status'         => $this->status($data['status'] ?? null) ?? MasterModel::STATUS_ACTIVE,
        ];

        $this->assertJenisUsable($row['id_jenis_libur']);
        $this->assertValidRange($row['tgl_mulai'], $row['tgl_akhir']);

        $id = $this->write(function () use ($row): string {
            $this->assertNoOverlap($row['tgl_mulai'], $row['tgl_akhir'], null);

            return (string) $this->model()->insert($row);
        }, $row['tgl_mulai'], $row['tgl_akhir'], null);

        return $this->get($id, Role::SUPER_ADMIN);
    }

    /**
     * Ubah parsial: hanya field yang dikirim. Overlap dicek hanya bila tanggal berubah; jenis libur hanya dicek bila
     * berubah (data lama yang jenisnya kini non-aktif tetap bisa diubah kolom lainnya, pola E6). `status` 1/2 juga
     * memulihkan entri berstatus 10.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        $current = $this->findOrFail($id);
        $changes = [];

        if (array_key_exists('id_jenis_libur', $data)) {
            $changes['id_jenis_libur'] = $this->jenisId($data['id_jenis_libur']);
        }

        foreach (['tgl_mulai', 'tgl_akhir'] as $column) {
            if (array_key_exists($column, $data)) {
                $changes[$column] = $this->date($data[$column], $column);
            }
        }

        if (array_key_exists('nama_libur', $data)) {
            $changes['nama_libur'] = $this->nama($data['nama_libur']);
        }

        if (array_key_exists('keterangan', $data)) {
            $changes['keterangan'] = $this->keterangan($data['keterangan']);
        }

        if (array_key_exists('status', $data)) {
            $changes['status'] = $this->status($data['status']) ?? throw ValidationException::forField('status', "Status hanya boleh '1' (aktif) atau '2' (tidak aktif).");
        }

        // Hanya yang benar-benar berubah (tidak menulis/mengaudit nilai yang sama).
        $changes = array_filter(
            $changes,
            static fn (mixed $value, string $column): bool => (string) $value !== (string) ($current[$column] ?? ''),
            ARRAY_FILTER_USE_BOTH,
        );

        if (array_key_exists('id_jenis_libur', $changes)) {
            $this->assertJenisUsable($changes['id_jenis_libur']);
        }

        $mulai        = (string) ($changes['tgl_mulai'] ?? $current['tgl_mulai']);
        $akhir        = (string) ($changes['tgl_akhir'] ?? $current['tgl_akhir']);
        $datesChanged = array_key_exists('tgl_mulai', $changes) || array_key_exists('tgl_akhir', $changes);

        if ($datesChanged) {
            $this->assertValidRange($mulai, $akhir);
        }

        if ($changes !== []) {
            $this->write(function () use ($id, $changes, $mulai, $akhir, $datesChanged): void {
                if ($datesChanged) {
                    $this->assertNoOverlap($mulai, $akhir, (int) $id);
                }

                $this->model()->update($id, $changes);
            }, $mulai, $akhir, (int) $id);
        }

        return $this->get($id, Role::SUPER_ADMIN);
    }

    /**
     * Aktif (1) / Tidak Aktif (2); juga memulihkan entri yang dihapus (10). Tanpa cek overlap: rentang entri ini
     * tidak pernah dipakai entri lain (overlap dicek terhadap semua status, Paket A).
     *
     * @return array<string, mixed>
     */
    public function setStatus(string $id, string $status): array
    {
        $current = $this->findOrFail($id);
        $status  = $this->status($status) ?? throw ValidationException::forField('status', "Status hanya boleh '1' (aktif) atau '2' (tidak aktif).");

        if ((string) $current['status'] !== $status) {
            $this->write(function () use ($id, $status): void {
                $this->model()->update($id, ['status' => $status]);
            }, (string) $current['tgl_mulai'], (string) $current['tgl_akhir'], (int) $id);
        }

        return $this->get($id, Role::SUPER_ADMIN);
    }

    /**
     * Soft delete (status 10, audit 'delete'); tidak pernah hard delete. Pulihkan lewat setStatus.
     *
     * @return array<string, mixed>
     */
    public function delete(string $id): array
    {
        $current = $this->findOrFail($id);

        if ((string) $current['status'] !== MasterModel::STATUS_DELETED) {
            $this->write(function () use ($id): void {
                $this->model()->softDelete($id);
            }, (string) $current['tgl_mulai'], (string) $current['tgl_akhir'], (int) $id);
        }

        return $this->get($id, Role::SUPER_ADMIN);
    }

    // ------------------------------------------------------------------
    // Aturan (protected: bisa diganti subclass test untuk mensimulasikan balapan / pelanggaran CHECK)
    // ------------------------------------------------------------------

    /**
     * Hari libur lain (status APA PUN, Paket A) yang rentangnya beririsan inklusif dengan [mulai, akhir], atau null.
     * Query menyaring kandidat dengan kondisi yang sama; keputusan akhir memakai HariLiburRules::overlaps().
     *
     * @return array<string, mixed>|null
     */
    protected function findOverlap(string $mulai, string $akhir, ?int $exceptId): ?array
    {
        $builder = $this->db->table('hari_libur')
            ->select(['id_libur', 'nama_libur', 'tgl_mulai', 'tgl_akhir', 'status'])
            ->where('tgl_mulai <=', $akhir)
            ->where('tgl_akhir >=', $mulai);

        if ($exceptId !== null) {
            $builder->where('id_libur !=', $exceptId);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $builder->orderBy('tgl_mulai', 'ASC')->get()->getResultArray();

        foreach ($rows as $row) {
            if (HariLiburRules::overlaps($mulai, $akhir, (string) $row['tgl_mulai'], (string) $row['tgl_akhir'])) {
                return $row;
            }
        }

        return null;
    }

    protected function assertValidRange(string $mulai, string $akhir): void
    {
        if (! HariLiburRules::validRange($mulai, $akhir)) {
            throw ValidationException::forField('tgl_akhir', self::RANGE_MESSAGE);
        }
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function assertNoOverlap(string $mulai, string $akhir, ?int $exceptId): void
    {
        $overlap = $this->findOverlap($mulai, $akhir, $exceptId);

        if ($overlap !== null) {
            throw $this->overlapError($overlap);
        }
    }

    /**
     * @param array<string, mixed> $overlap
     */
    private function overlapError(array $overlap): ValidationException
    {
        $hint = match ((string) $overlap['status']) {
            MasterModel::STATUS_INACTIVE => ' (tidak aktif — aktifkan atau ubah entri tersebut)',
            MasterModel::STATUS_DELETED  => ' (sudah dihapus — pulihkan atau ubah entri tersebut lewat filter status Dihapus)',
            default                      => '',
        };

        return ValidationException::forField(
            'tgl_mulai',
            "Rentang tanggal bentrok dengan hari libur \"{$overlap['nama_libur']}\" ({$overlap['tgl_mulai']} s.d. {$overlap['tgl_akhir']}){$hint}.",
        );
    }

    /**
     * Jalankan tulisan di dalam named lock + transaksi. Lock (GET_LOCK, milik sesi koneksi) menserialkan semua
     * penulisan hari libur, sehingga cek overlap dan INSERT/UPDATE tidak disela penulisan lain. Lock tidak didapat
     * dalam batas waktu → 409 tanpa tulis. Error DB → rollback, lalu 1062/CHECK diterjemahkan ke 422.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function write(Closure $work, string $mulai, string $akhir, ?int $exceptId): mixed
    {
        $lock = $this->lockName();

        /** @var array<string, mixed>|null $acquired */
        $acquired = $this->db->query('SELECT GET_LOCK(?, ?) AS acquired', [$lock, $this->lockTimeout])->getRowArray();

        if ((string) ($acquired['acquired'] ?? '') !== '1') {
            throw new ApiException(self::LOCK_BUSY_MESSAGE, 409);
        }

        try {
            $this->db->transBegin();

            try {
                $result = $work();
            } catch (Throwable $e) {
                $this->db->transRollback();
                $this->db->resetTransStatus();

                throw $this->translateDbError($e, $mulai, $akhir, $exceptId);
            }

            $this->db->transCommit();
            $this->db->resetTransStatus();

            return $result;
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /**
     * Lapis kedua DB → 422: UNIQUE `tgl_mulai` (1062, mis. penulisan di luar aplikasi yang lolos cek) dan CHECK rentang
     * (3819 MySQL / 4025 MariaDB). Error lain dilempar apa adanya (500).
     */
    private function translateDbError(Throwable $e, string $mulai, string $akhir, ?int $exceptId): Throwable
    {
        if (! $e instanceof DatabaseException) {
            return $e;
        }

        if ($e->getCode() === self::ERR_DUPLICATE) {
            $overlap = $this->findOverlap($mulai, $akhir, $exceptId);

            return $overlap !== null
                ? $this->overlapError($overlap)
                : ValidationException::forField('tgl_mulai', 'Tanggal mulai sudah dipakai hari libur lain.');
        }

        if (in_array($e->getCode(), self::ERR_CHECK, true)) {
            return ValidationException::forField('tgl_akhir', self::RANGE_MESSAGE);
        }

        return $e;
    }

    /**
     * Nama named lock (≤ 64 karakter) per database + prefix tabel, agar DB lain di server yang sama tidak saling kunci.
     */
    public function lockName(): string
    {
        return 'simpeg_hl_' . md5($this->db->getDatabase() . '|' . $this->db->getPrefix());
    }

    private function assertJenisUsable(string $id): void
    {
        $def = service('masterRegistry')->get('jenis-libur');

        if (! $def->isCanonicalId($id)) {
            throw ValidationException::forField('id_jenis_libur', 'Jenis Libur tidak ditemukan.');
        }

        /** @var array<string, mixed>|null $jenis */
        $jenis = $this->db->table($def->table)->where($def->primaryKey, $id)->get()->getRowArray();

        if ($jenis === null) {
            throw ValidationException::forField('id_jenis_libur', 'Jenis Libur tidak ditemukan.');
        }

        if ((string) $jenis['status'] !== MasterModel::STATUS_ACTIVE) {
            throw ValidationException::forField('id_jenis_libur', "Jenis Libur {$jenis[$def->nameField]} sedang non-aktif.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function findOrFail(string $id): array
    {
        return $this->find($id) ?? throw new NotFoundException('Hari libur tidak ditemukan.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $id): ?array
    {
        if (preg_match('/^[1-9][0-9]{0,9}\z/', $id) !== 1) {
            return null;
        }

        /** @var array<string, mixed>|null $row */
        $row = $this->baseQuery()->where('hari_libur.id_libur', (int) $id)->get()->getRowArray();

        return $row;
    }

    /**
     * hari_libur LEFT JOIN jenis_libur. Tanpa alias tabel: alias CI4 disimpan di koneksi dan dihapus reset builder lain
     * (mis. countAllResults() pada clone), sehingga prefix tabel (DBPrefix) ikut menempel ke alias.
     */
    private function baseQuery(): BaseBuilder
    {
        return $this->db->table('hari_libur')
            ->select(self::COLUMNS)
            ->join('jenis_libur', 'jenis_libur.id_jenis_libur = hari_libur.id_jenis_libur', 'left');
    }

    private function jenisId(mixed $value): string
    {
        return trim(is_scalar($value) ? (string) $value : '');
    }

    private function date(mixed $value, string $field): string
    {
        $date = is_string($value) ? trim($value) : '';

        if (! HariLiburRules::isValidDate($date)) {
            $label = $field === 'tgl_mulai' ? 'Tanggal mulai' : 'Tanggal selesai';

            throw ValidationException::forField($field, "{$label} harus tanggal yang valid dengan format YYYY-MM-DD (tahun " . HariLiburRules::YEAR_MIN . '-' . HariLiburRules::YEAR_MAX . ').');
        }

        return $date;
    }

    private function nama(mixed $value): string
    {
        $nama = trim((string) preg_replace('/\s+/u', ' ', is_string($value) ? $value : ''));

        if ($nama === '') {
            throw ValidationException::forField('nama_libur', 'Nama libur wajib diisi.');
        }

        return $nama;
    }

    private function keterangan(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }

    /**
     * Status yang boleh diset: 1/2 (10 hanya lewat delete). null = tidak dikirim/kosong.
     */
    private function status(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $status = is_scalar($value) ? (string) $value : '';

        return in_array($status, [MasterModel::STATUS_ACTIVE, MasterModel::STATUS_INACTIVE], true) ? $status : null;
    }

    private static function dateString(DateTimeInterface|string $value, string $name): string
    {
        $date = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : trim($value);

        if (! HariLiburRules::isValidDate($date)) {
            throw new InvalidArgumentException("Tanggal {$name} tidak valid: {$date}.");
        }

        return $date;
    }

    private function model(): HariLiburModel
    {
        return $this->model ??= new HariLiburModel($this->db);
    }
}
