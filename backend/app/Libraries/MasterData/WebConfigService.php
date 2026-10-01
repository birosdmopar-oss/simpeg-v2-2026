<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Constants\Role;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\CacheService;
use App\Libraries\Html\HtmlSanitizer;
use App\Models\MasterData\WebConfigModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\WebConfig;
use InvalidArgumentException;
use Throwable;

/**
 * G-09 — Web Config key-value bertipe (DBV-006/CR-030). Legacy: hr/master/c_web (C_web.php, Lm_web.php).
 *
 *  - Akses: CRUD role 1 saja (Matriks Role x Endpoint Modul G `hr/master/web_config/*`). Tidak ada endpoint baca publik
 *    (legacy `services/s_dm/p_web_config` membocorkan semua nilai tanpa auth — tidak dipindahkan). Modul lain membaca
 *    lewat value()/values() di server.
 *  - Key: hanya key katalog Config\WebConfig yang bisa diisi; tipe & validasi dari WebConfigTypes. Key yang belum punya
 *    baris memakai nilai bawaan katalog. Baris key tak dikenal (hasil impor yang diputuskan TL) tampil read-only dan
 *    hanya bisa dihapus.
 *  - Simpan (PUT) = upsert per `config_name`: nilai wajib, dinormalisasi per tipe. Hapus (DELETE) = hard delete baris
 *    sehingga nilai kembali ke bawaan (audit_logs menyimpan before lengkap).
 *  - Cache: peta config_name → nilai mentah disimpan CacheService (ADR-014), diinvalidasi setiap tulis yang berhasil
 *    (setelah commit), sehingga perubahan langsung terbaca konsumen (DoD G-09).
 */
class WebConfigService
{
    public const WRITE_ROLES = [Role::SUPER_ADMIN];

    public const CACHE_KEY = 'web_config_values';

    public const CACHE_TTL = CacheService::DEFAULT_TTL;

    public const REMARK_MAX_LENGTH = 255;

    private const ERR_DUPLICATE = 1062;

    /**
     * Kolom baris yang dikirim ke admin.
     */
    private const COLUMNS = ['id_web_config', 'config_name', 'config_value', 'remark', 'updated_at', 'updated_by'];

    private BaseConnection $db;

    private ?WebConfigModel $model = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $catalog;

    public function __construct(
        private CacheService $cache,
        ?WebConfig $config = null,
        ?BaseConnection $db = null,
        private ?HtmlSanitizer $sanitizer = null,
    ) {
        $this->db      = $db ?? db_connect();
        $this->catalog = ($config ?? config(WebConfig::class))->keys;

        WebConfigTypes::assertCatalog($this->catalog);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function catalog(): array
    {
        return $this->catalog;
    }

    // ------------------------------------------------------------------
    // Konsumen (server-side, Fase 3-5)
    // ------------------------------------------------------------------

    /**
     * Nilai bertipe satu key katalog (nilai tersimpan, atau bawaan bila baris tidak ada / nilai tersimpan tidak sah).
     *
     * @return float|int|list<string>|string|null
     *
     * @throws InvalidArgumentException key tidak ada di katalog
     */
    public function value(string $name): mixed
    {
        $def = $this->catalog[$name] ?? throw new InvalidArgumentException("Key web_config tidak dikenal: {$name}.");

        return WebConfigTypes::cast($def, $this->effectiveRaw($def, $this->cachedValues()[$name] ?? null, false));
    }

    /**
     * Seluruh key katalog → nilai bertipe.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $stored = $this->cachedValues();
        $values = [];

        foreach ($this->catalog as $name => $def) {
            $values[$name] = WebConfigTypes::cast($def, $this->effectiveRaw($def, $stored[$name] ?? null, false));
        }

        return $values;
    }

    // ------------------------------------------------------------------
    // Admin (role 1)
    // ------------------------------------------------------------------

    /**
     * Seluruh key katalog (urut katalog) lalu baris key tak dikenal (urut nama).
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function list(): array
    {
        $rows  = $this->rows();
        $items = [];

        foreach ($this->catalog as $name => $def) {
            $items[] = $this->item($name, $rows[$name] ?? null);
            unset($rows[$name]);
        }

        ksort($rows, SORT_STRING);

        foreach ($rows as $name => $row) {
            $items[] = $this->item((string) $name, $row);
        }

        return ['items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $name): array
    {
        $row = $this->findRow($name);

        if (! isset($this->catalog[$name]) && $row === null) {
            throw new NotFoundException('Web config tidak ditemukan.');
        }

        return $this->item($name, $row);
    }

    /**
     * Simpan nilai satu key katalog (upsert). `remark` opsional: bila tidak dikirim, baris baru memakai label katalog dan
     * baris lama tidak berubah.
     *
     * @param array<string, mixed> $data config_value, remark?
     *
     * @return array{item: array<string, mixed>, created: bool}
     */
    public function set(string $name, array $data): array
    {
        $def = $this->catalog[$name] ?? null;

        if ($def === null) {
            throw $this->findRow($name) === null
                ? new NotFoundException('Web config tidak ditemukan.')
                : ValidationException::forField('config_name', 'Key ini tidak ada di katalog v2 sehingga nilainya tidak bisa diubah; hapus bila tidak dipakai.');
        }

        if (! array_key_exists('config_value', $data)) {
            throw ValidationException::forField('config_value', 'Nilai wajib diisi. Untuk kembali ke nilai bawaan, hapus nilainya.');
        }

        try {
            $value = WebConfigTypes::normalize($def, $data['config_value'], $this->sanitizer());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::forField('config_value', $e->getMessage());
        }

        $remark = array_key_exists('remark', $data) ? $this->remark($data['remark']) : false;

        $created = $this->write(function () use ($name, $def, $value, $remark): bool {
            $row = $this->findRow($name);

            if ($row === null) {
                $this->model()->insert([
                    'config_name'  => $name,
                    'config_value' => $value,
                    'remark'       => $remark === false || $remark === null ? $def['label'] : $remark,
                ]);

                return true;
            }

            $this->updateRow($row, $value, $remark);

            return false;
        }, $name, $value, $remark);

        return ['item' => $this->get($name), 'created' => $created];
    }

    /**
     * Hapus baris (hard delete) → nilai kembali ke bawaan katalog. Key tanpa baris → 404.
     *
     * @return array<string, mixed> item setelah dihapus (key katalog: kembali bawaan) atau baris yang dihapus (key tak dikenal,
     *                              ditandai `deleted`)
     */
    public function delete(string $name): array
    {
        $row = $this->findRow($name) ?? throw new NotFoundException(
            isset($this->catalog[$name]) ? 'Web config ini belum punya nilai tersimpan (sudah memakai nilai bawaan).' : 'Web config tidak ditemukan.',
        );

        $this->write(function () use ($row): void {
            $this->model()->delete((int) $row['id_web_config']);
        });

        return isset($this->catalog[$name]) ? $this->get($name) : ['deleted' => true] + $this->item($name, $row);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $row baris tersimpan (null = belum ada)
     *
     * @return array<string, mixed>
     */
    private function item(string $name, ?array $row): array
    {
        $def = $this->catalog[$name] ?? null;
        $raw = $row === null ? null : ($row['config_value'] === null ? null : (string) $row['config_value']);

        $base = [
            'config_name'   => $name,
            'id_web_config' => $row === null ? null : (int) $row['id_web_config'],
            'config_value'  => $raw,
            'remark'        => $row['remark'] ?? null,
            'updated_at'    => $row['updated_at'] ?? null,
            'updated_by'    => $row === null || $row['updated_by'] === null ? null : (int) $row['updated_by'],
            'is_default'    => $row === null,
        ];

        if ($def === null) {
            return $base + [
                'known'   => false, 'label' => $name, 'group' => 'Key Tidak Dikenal', 'type' => null, 'description' => 'Key hasil impor yang tidak ada di katalog v2 dan tidak dibaca aplikasi.',
                'default' => null, 'effective' => $raw, 'valid' => false, 'personal' => false, 'constraints' => [],
            ];
        }

        $valid = $row === null || WebConfigTypes::isValidStored($def, $raw, $this->sanitizer());

        return $base + [
            'known'       => true,
            'label'       => $def['label'],
            'group'       => $def['group'],
            'type'        => $def['type'],
            'description' => $def['description'],
            'default'     => $def['default'],
            'effective'   => $this->effectiveRaw($def, $raw, $valid),
            'valid'       => $valid,
            'personal'    => (bool) ($def['personal'] ?? false),
            'constraints' => array_filter([
                'max_length' => $def['type'] === WebConfigTypes::TEXT ? (int) ($def['maxLength'] ?? 255) : null,
                'min'        => $def['min'] ?? null,
                'max'        => $def['max'] ?? null,
                'scale'      => $def['scale'] ?? null,
                'ref'        => $def['ref'] ?? null,
            ], static fn ($v): bool => $v !== null),
        ];
    }

    /**
     * Nilai mentah yang berlaku: nilai tersimpan bila ada dan sah, selain itu bawaan katalog. Nilai tersimpan tidak sah
     * (mis. impor yang lolos tanpa normalisasi) tidak pernah sampai ke konsumen.
     *
     * @param array<string, mixed> $def
     */
    private function effectiveRaw(array $def, ?string $raw, ?bool $valid): ?string
    {
        if ($raw === null) {
            return $def['default'];
        }

        $valid ??= false;

        if ($valid === false && ! WebConfigTypes::isValidStored($def, $raw, $this->sanitizer())) {
            return $def['default'];
        }

        return $raw;
    }

    /**
     * Peta config_name → nilai mentah dari cache (diisi ulang dari DB bila kosong).
     *
     * @return array<string, string|null>
     */
    private function cachedValues(): array
    {
        /** @var array<string, string|null> $values */
        $values = $this->cache->remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            $map = [];

            foreach ($this->rows() as $name => $row) {
                $map[$name] = $row['config_value'] === null ? null : (string) $row['config_value'];
            }

            return $map;
        });

        return $values;
    }

    /**
     * Seluruh baris tersimpan, di-key config_name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->table('web_config')->select(self::COLUMNS)->orderBy('config_name', 'ASC')->get()->getResultArray();

        return array_column($rows, null, 'config_name');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRow(string $name): ?array
    {
        if (preg_match(WebConfigTypes::NAME_PATTERN, $name) !== 1 || strlen($name) > 255) {
            return null;
        }

        /** @var array<string, mixed>|null $row */
        $row = $this->db->table('web_config')->select(self::COLUMNS)->where('config_name', $name)->get()->getRowArray();

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function updateRow(array $row, string $value, string|false|null $remark): void
    {
        $changes = [];

        if ((string) ($row['config_value'] ?? '') !== $value || $row['config_value'] === null) {
            $changes['config_value'] = $value;
        }

        if ($remark !== false && $remark !== ($row['remark'] ?? null)) {
            $changes['remark'] = $remark;
        }

        if ($changes !== []) {
            $this->model()->update((int) $row['id_web_config'], $changes);
        }
    }

    /**
     * Jalankan tulisan dalam transaksi, invalidasi cache setelah commit. Balapan dua tambah key yang sama (1062 UNIQUE
     * `config_name`) diulang sekali sebagai ubah.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function write(Closure $work, ?string $name = null, ?string $value = null, string|false|null $remark = false): mixed
    {
        try {
            $result = $this->transaction($work);
        } catch (DatabaseException $e) {
            if ($e->getCode() !== self::ERR_DUPLICATE || $name === null || $value === null) {
                throw $e;
            }

            $result = $this->transaction(function () use ($name, $value, $remark): bool {
                $row = $this->findRow($name) ?? throw new NotFoundException('Web config tidak ditemukan.');
                $this->updateRow($row, $value, $remark);

                return false;
            });
        }

        $this->cache->invalidate(self::CACHE_KEY);

        return $result;
    }

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function transaction(Closure $work): mixed
    {
        $this->db->transBegin();

        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        }

        $this->db->transCommit();
        $this->db->resetTransStatus();

        return $result;
    }

    private function remark(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::forField('remark', 'Keterangan harus teks.');
        }

        $remark = trim((string) preg_replace('/\s+/u', ' ', $value));

        if (mb_strlen($remark) > self::REMARK_MAX_LENGTH) {
            throw ValidationException::forField('remark', 'Keterangan maksimal 255 karakter.');
        }

        return $remark === '' ? null : $remark;
    }

    private function sanitizer(): HtmlSanitizer
    {
        return $this->sanitizer ??= service('htmlSanitizer');
    }

    private function model(): WebConfigModel
    {
        return $this->model ??= new WebConfigModel($this->db);
    }
}
