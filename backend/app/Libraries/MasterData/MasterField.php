<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

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
     * Batas byte bawaan tipe html bila maxBytes tidak diisi (jauh di bawah LONGTEXT, cukup untuk artikel panjang).
     */
    public const HTML_MAX_BYTES = 1000000;

    /**
     * @param array<string|int, string> $options  nilai => label (tipe select)
     * @param int|null                  $maxBytes batas panjang dalam BYTE (strlen), mis. 255 untuk TINYTEXT — kolom
     *                                            TINYTEXT dihitung byte, dan koneksi strictOn=false memotong diam-diam
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
    ) {
    }

    /**
     * @param array{
     *     label: string, type?: string, required?: bool, rules?: string,
     *     options?: array<string|int, string>, hint?: string, maxBytes?: int
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
        );
    }

    /**
     * Batas byte yang berlaku: maxBytes eksplisit, atau bawaan tipe html. null = tanpa batas byte.
     */
    public function byteLimit(): ?int
    {
        return $this->maxBytes ?? ($this->type === self::TYPE_HTML ? self::HTML_MAX_BYTES : null);
    }

    /**
     * Rules CI4 untuk field ini. `$creating` menentukan required vs if_exist (update parsial).
     */
    public function validationRules(bool $creating): string
    {
        $parts = [];

        if ($this->required) {
            $parts[] = $creating ? 'required' : 'if_exist|required';
        } else {
            $parts[] = $creating ? 'permit_empty' : 'if_exist|permit_empty';
        }

        $parts[] = match ($this->type) {
            self::TYPE_INT     => 'is_natural',
            self::TYPE_DECIMAL => 'decimal',
            self::TYPE_DATE    => 'valid_date[Y-m-d]',
            self::TYPE_SELECT  => 'in_list[' . implode(',', array_map('strval', array_keys($this->options))) . ']',
            default            => 'string',
        };

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
            'decimal'     => "{$this->label} harus angka (pakai titik untuk desimal).",
            'valid_date'  => "{$this->label} harus tanggal dengan format YYYY-MM-DD.",
            'in_list'     => "{$this->label} tidak valid.",
            'string'      => "{$this->label} harus teks.",
            'regex_match' => "{$this->label} tidak sesuai format." . ($this->hint !== null ? " {$this->hint}" : ''),
        ];

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
     * Normalisasi nilai sebelum disimpan (string kosong → NULL untuk field opsional).
     */
    public function normalize(mixed $value): int|float|string|null
    {
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
     * @return array<string, mixed>
     */
    public function toMeta(): array
    {
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
        ];
    }
}
