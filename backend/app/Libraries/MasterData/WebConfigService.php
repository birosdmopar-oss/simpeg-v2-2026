<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\CacheService;
use App\Models\MasterData\WebConfigModel;
use CodeIgniter\Database\BaseConnection;

/**
 * G-09 — Web Config (key-value) dengan TIPE DATA PER KEY (DoD: "bukan free-text semua").
 *
 * Nilai divalidasi sesuai `tipe_data` sebelum disimpan (MTC-012: isi teks di field integer → ditolak), dan dibaca
 * kembali sudah ter-cast lewat value()/values() sehingga konsumen (kalkulasi tukin/uang makan Fase 5) tidak perlu
 * menebak bentuk datanya.
 *
 * Perubahan nilai langsung ter-reflect: cache `web_config_*` di-invalidate setiap penulisan (regression test
 * lengkap bersama kalkulasi ditunda ke Fase 5 sesuai DoD).
 */
class WebConfigService
{
    private const CACHE_KEY = 'web_config_all';
    private const CACHE_TTL = 3600;

    /** Nama key: huruf/angka/garis bawah/titik, tanpa spasi (dipakai sebagai identitas di kode). */
    private const KEY_PATTERN = '/^[A-Za-z0-9._]+$/';

    private BaseConnection $db;

    public function __construct(
        private WebConfigModel $model,
        private CacheService $cache,
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * @param array<string, mixed> $filters search, tipe_data
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $builder = $this->db->table('web_config');

        if (! empty($filters['tipe_data'])) {
            $builder->where('tipe_data', (string) $filters['tipe_data']);
        }

        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $search = trim((string) $filters['search']);
            $builder->groupStart()->like('config_name', $search)->orLike('keterangan', $search)->groupEnd();
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $builder->orderBy('config_name', 'ASC')->get()->getResultArray();

        return array_map(fn (array $row): array => $this->present($row), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $name): array
    {
        return $this->present($this->findOrFail($name));
    }

    /**
     * Nilai satu key, sudah di-cast sesuai tipe. $default dipakai kalau key belum ada.
     */
    public function value(string $name, int|float|string|bool|null $default = null): int|float|string|bool|null
    {
        $all = $this->values();

        return array_key_exists($name, $all) ? $all[$name] : $default;
    }

    /**
     * Seluruh config sebagai map key => nilai ter-cast (di-cache).
     *
     * @return array<string, int|float|string|bool|null>
     */
    public function values(): array
    {
        /** @var array<string, int|float|string|bool|null> $values */
        $values = $this->cache->remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->db->table('web_config')->get()->getResultArray();
            $map  = [];

            foreach ($rows as $row) {
                $map[(string) $row['config_name']] = $this->cast((string) $row['tipe_data'], $row['config_value']);
            }

            return $map;
        });

        return $values;
    }

    /**
     * @param array<string, mixed> $data config_name, config_value, tipe_data?, keterangan?
     *
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $name = trim((string) ($data['config_name'] ?? ''));

        if (! preg_match(self::KEY_PATTERN, $name)) {
            throw ValidationException::forField('config_name', 'Nama key hanya boleh huruf, angka, titik, dan garis bawah (tanpa spasi).');
        }

        if ($this->db->table('web_config')->where('config_name', $name)->countAllResults() > 0) {
            throw ValidationException::forField('config_name', "Key {$name} sudah ada.");
        }

        $type = $this->normalizeType($data['tipe_data'] ?? WebConfigModel::TYPE_STRING);

        $this->model->insert([
            'config_name'  => $name,
            'config_value' => $this->validateValue($type, $data['config_value'] ?? null),
            'tipe_data'    => $type,
            'keterangan'   => ($data['keterangan'] ?? '') === '' ? null : trim((string) $data['keterangan']),
        ]);

        $this->invalidate();

        return $this->get($name);
    }

    /**
     * Key tidak bisa diubah (dipakai sebagai identitas di kode). Mengubah `tipe_data` memvalidasi ulang nilai.
     *
     * @param array<string, mixed> $data config_value?, tipe_data?, keterangan?
     *
     * @return array<string, mixed>
     */
    public function update(string $name, array $data): array
    {
        $current = $this->findOrFail($name);
        $type    = array_key_exists('tipe_data', $data) ? $this->normalizeType($data['tipe_data']) : (string) $current['tipe_data'];
        $changes = [];

        if ($type !== (string) $current['tipe_data']) {
            $changes['tipe_data'] = $type;
        }

        if (array_key_exists('config_value', $data) || isset($changes['tipe_data'])) {
            $value                   = array_key_exists('config_value', $data) ? $data['config_value'] : $current['config_value'];
            $changes['config_value'] = $this->validateValue($type, $value);
        }

        if (array_key_exists('keterangan', $data)) {
            $changes['keterangan'] = ($data['keterangan'] ?? '') === '' ? null : trim((string) $data['keterangan']);
        }

        if ($changes !== []) {
            $this->model->update((int) $current['id_web_config'], $changes);
            $this->invalidate();
        }

        return $this->get($name);
    }

    public function delete(string $name): void
    {
        $current = $this->findOrFail($name);
        $this->model->deleteConfig((int) $current['id_web_config']);
        $this->invalidate();
    }

    // ------------------------------------------------------------------
    // Tipe data
    // ------------------------------------------------------------------

    private function normalizeType(mixed $type): string
    {
        $type = strtolower(trim((string) $type));

        if (! in_array($type, WebConfigModel::TYPES, true)) {
            throw ValidationException::forField('tipe_data', 'Tipe data harus salah satu dari: ' . implode(', ', WebConfigModel::TYPES) . '.');
        }

        return $type;
    }

    /**
     * Validasi nilai terhadap tipe; mengembalikan bentuk string yang disimpan di kolom TEXT.
     */
    private function validateValue(string $type, mixed $value): string
    {
        $raw = is_bool($value) ? ($value ? '1' : '0') : trim((string) ($value ?? ''));

        if ($raw === '') {
            throw ValidationException::forField('config_value', 'Nilai wajib diisi.');
        }

        return match ($type) {
            WebConfigModel::TYPE_INTEGER => $this->requireMatch(
                $raw,
                '/^-?\d+$/',
                'Nilai harus bilangan bulat (contoh: 30000).',
            ),
            WebConfigModel::TYPE_DECIMAL => $this->requireMatch(
                $raw,
                '/^-?\d+(\.\d+)?$/',
                'Nilai harus angka desimal dengan titik (contoh: 0.5 atau 12500.75).',
            ),
            WebConfigModel::TYPE_TIME => $this->requireMatch(
                $raw,
                '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/',
                'Nilai harus jam HH:MM atau HH:MM:SS (contoh: 07:30).',
            ),
            WebConfigModel::TYPE_BOOLEAN => in_array(strtolower($raw), ['0', '1', 'true', 'false'], true)
                ? (in_array(strtolower($raw), ['1', 'true'], true) ? '1' : '0')
                : throw ValidationException::forField('config_value', 'Nilai harus boolean (0/1).'),
            default => $raw,
        };
    }

    private function requireMatch(string $value, string $pattern, string $message): string
    {
        if (preg_match($pattern, $value) !== 1) {
            throw ValidationException::forField('config_value', $message);
        }

        return $value;
    }

    private function cast(string $type, mixed $value): int|float|string|bool|null
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            WebConfigModel::TYPE_INTEGER => (int) $value,
            WebConfigModel::TYPE_DECIMAL => (float) $value,
            WebConfigModel::TYPE_BOOLEAN => (string) $value === '1',
            default                      => (string) $value,
        };
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $row['value_casted'] = $this->cast((string) $row['tipe_data'], $row['config_value']);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function findOrFail(string $name): array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->db->table('web_config')->where('config_name', $name)->get()->getRowArray();

        if ($row === null) {
            throw new NotFoundException("Config {$name} tidak ditemukan.");
        }

        return $row;
    }

    private function invalidate(): void
    {
        $this->cache->invalidate(self::CACHE_KEY);
    }
}
