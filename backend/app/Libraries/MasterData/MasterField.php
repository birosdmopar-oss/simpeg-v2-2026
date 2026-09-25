<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use LogicException;

/**
 * Field tambahan sebuah master di luar kolom standar (kode, nama, induk, order, status).
 * Contoh: `kd_area` kabupaten/kota, `kd_pos` kelurahan, `status_pegawai` jenis status (G-07, kolom legacy),
 * `remark` topik FAQ (TINYTEXT), `content` artikel FAQ (HTML, G-10).
 *
 * Tipe dipakai untuk: rules validasi backend, casting nilai, dan metadata form frontend (GET master/meta).
 */
final class MasterField
{
    public const TYPE_TEXT     = 'text';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_INT      = 'int';
    public const TYPE_DECIMAL  = 'decimal';
    public const TYPE_DATE     = 'date';
    public const TYPE_SELECT   = 'select';

    /**
     * Konten HTML (LONGTEXT), mis. isi artikel FAQ. Disanitasi server saat tulis lewat hook master (MasterHooks),
     * bukan di sini — field ini hanya memvalidasi bentuk (teks) dan batas ukuran.
     */
    public const TYPE_HTML = 'html';

    /**
     * Flag 1/0 (TINYINT NOT NULL DEFAULT 0), mis. D_I..S_3 jurusan pendidikan (CR-009). Input '1'/'0' (JSON true/false
     * dikonversi controller); kosong = 0. Form: checkbox.
     */
    public const TYPE_BOOLEAN = 'boolean';

    /**
     * Rujukan ke master lain (dropdown dari `{entity}/options`, CR-009), mis. kode wilayah `kantor`. Nilai = kode
     * entri master `entity`; server memeriksa bentuk kanonik, keberadaan, dan status aktif (hanya bila nilainya
     * berubah). `dependsOn` = field ref lain di form yang sama yang menjadi induk entri rujukan (dropdown berjenjang):
     * nilai field ini wajib berada di bawah nilai field `dependsOn` (kecuali `checkDependsOn` false — rantai diperiksa
     * hook, mis. sentinel LAIN-LAIN kantor).
     */
    public const TYPE_REF = 'ref';

    public const TYPES = [
        self::TYPE_TEXT, self::TYPE_TEXTAREA, self::TYPE_INT, self::TYPE_DECIMAL, self::TYPE_DATE, self::TYPE_SELECT,
        self::TYPE_HTML, self::TYPE_BOOLEAN, self::TYPE_REF,
    ];

    /**
     * Batas byte bawaan tipe html bila maxBytes tidak diisi (jauh di bawah LONGTEXT, cukup untuk artikel panjang).
     */
    public const HTML_MAX_BYTES = 1000000;

    /**
     * Rentang nilai kolom bilangan bulat MySQL/MariaDB (CR-009). Koneksi strict menolak nilai di luar rentang (1264 →
     * 500) dan koneksi non-strict memotongnya diam-diam (TINYINT 300 → 127), jadi batasnya divalidasi aplikasi (422).
     * PHP tidak bisa merepresentasikan batas atas BIGINT UNSIGNED, jadi dibatasi PHP_INT_MAX.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    public const INT_RANGES = [
        'tinyint'            => [-128, 127],
        'tinyint unsigned'   => [0, 255],
        'smallint'           => [-32768, 32767],
        'smallint unsigned'  => [0, 65535],
        'mediumint'          => [-8388608, 8388607],
        'mediumint unsigned' => [0, 16777215],
        'int'                => [-2147483648, 2147483647],
        'int unsigned'       => [0, 4294967295],
        'bigint'             => [PHP_INT_MIN, PHP_INT_MAX],
        'bigint unsigned'    => [0, PHP_INT_MAX],
    ];

    /**
     * Tipe kolom bawaan field int bila `columnType` tidak diisi (INT signed).
     */
    public const DEFAULT_INT_COLUMN = 'int';

    /**
     * @param array<string|int, string> $options        nilai => label (tipe select)
     * @param int|null                  $maxBytes       batas panjang dalam BYTE (strlen), mis. 255 untuk TINYTEXT — kolom
     *                                                  TINYTEXT dihitung byte, dan koneksi strictOn=false memotong diam-diam
     * @param string|null               $columnType     tipe kolom bilangan bulat (kunci INT_RANGES, mis. 'tinyint',
     *                                                  'int unsigned') — menentukan batas atas/bawah field int
     * @param int|float|null            $min            batas bawah eksplisit (int/decimal); field int tanpa min = 0
     * @param int|float|null            $max            batas atas eksplisit (int/decimal); field int dijepit ke rentang kolom
     * @param string|null               $entity         key master rujukan (tipe ref)
     * @param string|null               $dependsOn      field ref lain yang menjadi induk entri rujukan (tipe ref)
     * @param bool                      $checkDependsOn false = konsistensi dengan `dependsOn` diperiksa hook, bukan engine
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = self::TYPE_TEXT,
        public readonly bool $required = false,
        public readonly ?string $rules = null,
        public readonly array $options = [],
        public readonly ?string $hint = null,
        public readonly ?int $maxBytes = null,
        public readonly ?string $columnType = null,
        public readonly int|float|null $min = null,
        public readonly int|float|null $max = null,
        public readonly ?string $entity = null,
        public readonly ?string $dependsOn = null,
        public readonly bool $checkDependsOn = true,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new LogicException("Tipe field master {$name} tidak dikenal: {$type}.");
        }

        if ($columnType !== null && ! array_key_exists($columnType, self::INT_RANGES)) {
            throw new LogicException("columnType field master {$name} tidak dikenal: {$columnType}.");
        }

        if ($type === self::TYPE_REF && ($entity === null || $entity === '')) {
            throw new LogicException("Field ref {$name} wajib menyebut entity master rujukan.");
        }

        if ($type !== self::TYPE_REF && ($entity !== null || $dependsOn !== null)) {
            throw new LogicException("entity/dependsOn hanya untuk field bertipe ref ({$name}).");
        }
    }

    /**
     * @param array{
     *     label: string, type?: string, required?: bool, rules?: string,
     *     options?: array<string|int, string>, hint?: string, maxBytes?: int,
     *     columnType?: string, min?: int|float, max?: int|float,
     *     entity?: string, dependsOn?: string, checkDependsOn?: bool
     * } $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        return new self(
            name: $name,
            label: $config['label'],
            type: $config['type'] ?? self::TYPE_TEXT,
            required: $config['required'] ?? false,
            rules: $config['rules'] ?? null,
            options: $config['options'] ?? [],
            hint: $config['hint'] ?? null,
            maxBytes: $config['maxBytes'] ?? null,
            columnType: $config['columnType'] ?? null,
            min: $config['min'] ?? null,
            max: $config['max'] ?? null,
            entity: $config['entity'] ?? null,
            dependsOn: $config['dependsOn'] ?? null,
            checkDependsOn: $config['checkDependsOn'] ?? true,
        );
    }

    /**
     * Rentang [min, max] sebuah tipe kolom bilangan bulat (kunci INT_RANGES).
     *
     * @return array{0: int, 1: int}
     */
    public static function intRange(string $columnType): array
    {
        return self::INT_RANGES[$columnType] ?? throw new LogicException("Tipe kolom bilangan bulat tidak dikenal: {$columnType}.");
    }

    /**
     * Batas byte yang berlaku: maxBytes eksplisit, atau bawaan tipe html. null = tanpa batas byte.
     */
    public function byteLimit(): ?int
    {
        return $this->maxBytes ?? ($this->type === self::TYPE_HTML ? self::HTML_MAX_BYTES : null);
    }

    /**
     * Batas nilai yang berlaku [min, max] untuk field angka (null = tanpa batas di sisi itu; non-angka = [null, null]).
     * Field int: min eksplisit (bawaan 0 — bilangan bulat tidak negatif) dan max eksplisit, keduanya dijepit ke rentang
     * tipe kolom (bawaan INT signed). Field decimal: hanya batas eksplisit (DOUBLE tidak punya batas praktis).
     *
     * @return array{0: int|float|null, 1: int|float|null}
     */
    public function bounds(): array
    {
        if ($this->type === self::TYPE_INT) {
            [$typeMin, $typeMax] = self::intRange($this->columnType ?? self::DEFAULT_INT_COLUMN);

            $min = max($typeMin, (int) ($this->min ?? 0));
            $max = $this->max === null ? $typeMax : min($typeMax, (int) $this->max);

            return [$min, $max];
        }

        if ($this->type === self::TYPE_DECIMAL) {
            return [$this->min, $this->max];
        }

        return [null, null];
    }

    /**
     * Rules CI4 untuk field ini. `$creating` menentukan required vs if_exist (update parsial).
     *
     * @param int|null $refIdMaxLength panjang maksimal kode master rujukan (tipe ref), diisi controller dari registry
     */
    public function validationRules(bool $creating, ?int $refIdMaxLength = null): string
    {
        $parts = [];

        if ($this->required) {
            $parts[] = $creating ? 'required' : 'if_exist|required';
        } else {
            $parts[] = $creating ? 'permit_empty' : 'if_exist|permit_empty';
        }

        [$min, $max] = $this->bounds();

        $parts[] = match ($this->type) {
            // Bilangan bulat: tanpa tanda bila min >= 0 (is_natural), bertanda bila min negatif (integer).
            self::TYPE_INT     => $min !== null && $min < 0 ? 'integer' : 'is_natural',
            self::TYPE_DECIMAL => 'decimal',
            self::TYPE_DATE    => 'valid_date[Y-m-d]',
            self::TYPE_SELECT  => 'in_list[' . implode(',', array_map('strval', array_keys($this->options))) . ']',
            self::TYPE_BOOLEAN => 'in_list[0,1]',
            // Bentuk saja (kode rujukan); keberadaan, status aktif, dan rantai dependsOn diperiksa MasterService.
            self::TYPE_REF => 'max_length[' . ($refIdMaxLength ?? 255) . ']',
            default        => 'string',
        };

        if ($min !== null) {
            $parts[] = 'greater_than_equal_to[' . self::numberLiteral($min) . ']';
        }

        if ($max !== null) {
            $parts[] = 'less_than_equal_to[' . self::numberLiteral($max) . ']';
        }

        $byteLimit = $this->byteLimit();

        if ($byteLimit !== null) {
            $parts[] = "max_byte_length[{$byteLimit}]";
        }

        if ($this->rules !== null && $this->rules !== '') {
            $parts[] = $this->rules;
        }

        return implode('|', $parts);
    }

    /**
     * Pesan error berbahasa Indonesia per rule (CI4 memakai pesan bawaan berbahasa Inggris).
     *
     * @return array<string, string>
     */
    public function validationMessages(): array
    {
        $messages = [
            'required'    => "{$this->label} wajib diisi.",
            'is_natural'  => "{$this->label} harus bilangan bulat tidak negatif.",
            'integer'     => "{$this->label} harus bilangan bulat.",
            'decimal'     => "{$this->label} harus angka (pakai titik untuk desimal).",
            'valid_date'  => "{$this->label} harus tanggal dengan format YYYY-MM-DD.",
            'in_list'     => $this->type === self::TYPE_BOOLEAN ? "{$this->label} hanya boleh 1 (ya) atau 0 (tidak)." : "{$this->label} tidak valid.",
            'string'      => "{$this->label} harus teks.",
            'max_length'  => "{$this->label} tidak valid.",
            'regex_match' => "{$this->label} tidak sesuai format." . ($this->hint !== null ? " {$this->hint}" : ''),
        ];

        [$min, $max] = $this->bounds();

        if ($min !== null) {
            $messages['greater_than_equal_to'] = "{$this->label} minimal " . self::formatNumber($min) . '.';
        }

        if ($max !== null) {
            $messages['less_than_equal_to'] = "{$this->label} maksimal " . self::formatNumber($max) . '.';
        }

        $byteLimit = $this->byteLimit();

        if ($byteLimit !== null) {
            $messages['max_byte_length'] = "{$this->label} maksimal " . number_format($byteLimit, 0, ',', '.') . ' byte.';
        }

        foreach (explode('|', (string) $this->rules) as $rule) {
            if (preg_match('/^(greater_than_equal_to|less_than_equal_to|max_length|min_length)\[(.+)\]$/', $rule, $m) !== 1) {
                continue;
            }

            $messages[$m[1]] = match ($m[1]) {
                'greater_than_equal_to' => "{$this->label} minimal {$m[2]}.",
                'less_than_equal_to'    => "{$this->label} maksimal {$m[2]}.",
                'max_length'            => "{$this->label} maksimal {$m[2]} karakter.",
                default                 => "{$this->label} minimal {$m[2]} karakter.",
            };
        }

        return $messages;
    }

    /**
     * Normalisasi nilai sebelum disimpan (string kosong → NULL untuk field opsional; boolean kosong → 0).
     */
    public function normalize(mixed $value): int|float|string|null
    {
        if ($this->type === self::TYPE_BOOLEAN) {
            return in_array($value, [true, 1, '1'], true) ? 1 : 0;
        }

        if ($value === null || $value === '') {
            return $this->required ? '' : null;
        }

        return match ($this->type) {
            self::TYPE_INT     => (int) $value,
            self::TYPE_DECIMAL => (float) $value,
            default            => trim((string) $value),
        };
    }

    /**
     * Nilai filter query (`?kolom=nilai` di options/daftar, opsi definisi `filters`) yang sah untuk field ini: pilihan
     * select yang terdaftar, 0/1 untuk boolean, bilangan bulat untuk int, bentuk kode untuk ref, teks UTF-8 ≤ 255 byte
     * untuk tipe lain. Nilai lain → 422 (bukan diam-diam tidak difilter).
     */
    public function acceptsFilterValue(string $value): bool
    {
        return match ($this->type) {
            self::TYPE_SELECT  => in_array($value, array_map('strval', array_keys($this->options)), true),
            self::TYPE_BOOLEAN => in_array($value, ['0', '1'], true),
            self::TYPE_INT     => preg_match('/^-?[0-9]{1,20}\z/', $value) === 1,
            self::TYPE_REF     => preg_match('/^[A-Za-z0-9._-]{1,64}\z/', $value) === 1,
            default            => strlen($value) <= 255 && mb_check_encoding($value, 'UTF-8'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toMeta(): array
    {
        [$min, $max] = $this->bounds();

        return [
            'name'     => $this->name,
            'label'    => $this->label,
            'type'     => $this->type,
            'required' => $this->required,
            'options'  => $this->options === [] ? null : array_map(
                static fn (string|int $value, string $label): array => ['value' => (string) $value, 'label' => $label],
                array_keys($this->options),
                array_values($this->options),
            ),
            'hint' => $this->hint,
            // Batas byte UTF-8 (TINYTEXT = 255; html bawaan 1.000.000) agar form ikut memvalidasi; null = tanpa batas.
            'max_bytes' => $this->byteLimit(),
            // Batas nilai field angka (int: rentang tipe kolom, CR-009); null = tanpa batas.
            'min' => $min,
            'max' => $max,
            // Tipe ref: master rujukan (dropdown `{entity}/options`) dan field induknya di form (dropdown berjenjang).
            'entity'     => $this->entity,
            'depends_on' => $this->dependsOn,
        ];
    }

    private static function numberLiteral(int|float $value): string
    {
        return is_int($value) ? (string) $value : rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    }

    private static function formatNumber(int|float $value): string
    {
        if (is_int($value)) {
            return number_format($value, 0, ',', '.');
        }

        $literal  = self::numberLiteral($value);
        $decimals = str_contains($literal, '.') ? strlen(substr($literal, (int) strpos($literal, '.') + 1)) : 0;

        return number_format($value, $decimals, ',', '.');
    }
}
