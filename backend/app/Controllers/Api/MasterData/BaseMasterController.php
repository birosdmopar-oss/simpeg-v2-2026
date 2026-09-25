<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

use App\Controllers\Api\ApiController;
use App\Exceptions\NotFoundException;
use App\Libraries\MasterData\MasterDefinition;
use App\Libraries\MasterData\MasterField;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Controller generik master data (Modul G). Controller grup (UmumController, JabatanController, dst. — sesuai
 * "Files touched" di 02-MasterData.md) cukup mendaftarkan key master miliknya di $entities.
 * Logika bisnis seluruhnya di MasterService (ADR-002: controller tipis). Route dibangkitkan dari Config\MasterData.
 *
 * Role (Matriks Role x Endpoint Modul G): CRUD = role 1; options (dropdown) = UL_ALL, kecuali master ber-publicOptions
 * false (FAQ) = role 1 (lihat Routes).
 * Parameter pertama setiap method = key master (diinjeksi routing), lalu kode entri.
 */
abstract class BaseMasterController extends ApiController
{
    /**
     * Kode master adalah string (mis. '01', '3171'): jangan di-cast ke int.
     */
    protected bool $castNumericParams = false;

    /**
     * Key master (Config\MasterData) yang dilayani controller ini.
     *
     * @var list<string>
     */
    protected array $entities = [];

    public function index(string $entity): ResponseInterface
    {
        /** @var array<string, mixed> $filters */
        $filters = $this->request->getGet();

        return $this->respondSuccess(service('masterService')->list($this->definition($entity), $filters));
    }

    public function show(string $entity, string $id): ResponseInterface
    {
        return $this->respondSuccess(service('masterService')->get($this->definition($entity), $id));
    }

    /**
     * Dropdown: `?parent=` (induk) + filter kolom allowlist opsi `filters` master (mis. `?cpns=1`, CR-009).
     */
    public function options(string $entity): ResponseInterface
    {
        $parent = $this->request->getGet('parent');
        /** @var array<string, mixed> $query */
        $query = $this->request->getGet() ?? [];

        return $this->respondSuccess(service('masterService')->options(
            $this->definition($entity),
            is_string($parent) ? $parent : null,
            $query,
        ));
    }

    public function create(string $entity): ResponseInterface
    {
        $def  = $this->definition($entity);
        $data = $this->validateOrFail($this->input($def), $this->rules($def, true));

        return $this->respondSuccess(service('masterService')->create($def, $data), 201);
    }

    public function update(string $entity, string $id): ResponseInterface
    {
        $def  = $this->definition($entity);
        $data = $this->validateOrFail($this->input($def), $this->rules($def, false));

        return $this->respondSuccess(service('masterService')->update($def, $id, $data));
    }

    public function setStatus(string $entity, string $id): ResponseInterface
    {
        $data = $this->validateOrFail($this->payload(), [
            'status' => [
                'rules'  => 'required|in_list[1,2]',
                'errors' => ['required' => 'Status wajib diisi.', 'in_list' => "Status hanya boleh '1' (aktif) atau '2' (tidak aktif)."],
            ],
        ]);

        return $this->respondSuccess(service('masterService')->setStatus($this->definition($entity), $id, (string) $data['status']));
    }

    public function reorder(string $entity, string $id): ResponseInterface
    {
        $def  = $this->definition($entity);
        $rule = $this->orderRule($def);
        $data = $this->validateOrFail($this->payload(), [
            'order' => [
                'rules'  => 'required|' . $rule['rules'],
                'errors' => ['required' => 'Urutan wajib diisi.', ...$rule['errors']],
            ],
        ]);

        return $this->respondSuccess(service('masterService')->reorder($def, $id, (int) $data['order']));
    }

    /**
     * Soft delete (status 10 'Dihapus'); master tidak pernah di-hard-delete. Pulihkan lewat PATCH status.
     */
    public function delete(string $entity, string $id): ResponseInterface
    {
        $row = service('masterService')->delete($this->definition($entity), $id);

        return $this->respondSuccess(['deleted' => true, 'soft_delete' => true, 'item' => $row]);
    }

    protected function definition(string $entity): MasterDefinition
    {
        if (! in_array($entity, $this->entities, true)) {
            throw new NotFoundException('Master data tidak ditemukan.');
        }

        return service('masterRegistry')->get($entity);
    }

    /**
     * Rules validasi bentuk (CI4) + pesan berbahasa Indonesia. Aturan bisnis (keunikan, induk aktif,
     * overlap tanggal) ada di service masing-masing.
     *
     * @return array<string, array<string, mixed>|string>
     */
    protected function rules(MasterDefinition $def, bool $creating): array
    {
        // Update bersifat parsial: field yang dikirim tetap wajib terisi (nama/induk tidak boleh dikosongkan).
        $required = $creating ? 'required' : 'if_exist|required';
        $rules    = [];

        // Kode diinput admin hanya untuk master ber-PK string; PK AUTO_INCREMENT diberikan DB.
        if ($creating && ! $def->autoIncrement) {
            $rules[$def->primaryKey] = $def->idDigits !== null
                // Kode wilayah legacy: tepat N digit angka tanpa titik (CHAR(N) di DB, ISSUE-008).
                ? [
                    // \z, bukan $: '$' PCRE juga cocok sebelum newline di akhir ("31\n" akan lolos).
                    'rules'  => "required|regex_match[/^[0-9]{{$def->idDigits}}\\z/]",
                    'errors' => [
                        'required'    => 'Kode wajib diisi.',
                        'regex_match' => "Kode harus tepat {$def->idDigits} digit angka (tanpa titik atau spasi).",
                    ],
                ]
                // Kode umum: huruf/angka/titik/strip/garis bawah, tanpa spasi.
                : [
                    'rules'  => "required|max_length[{$def->idMaxLength}]|regex_match[/^[A-Za-z0-9._-]+\\z/]",
                    'errors' => [
                        'required'    => 'Kode wajib diisi.',
                        'max_length'  => "Kode maksimal {$def->idMaxLength} karakter.",
                        'regex_match' => 'Kode hanya boleh huruf, angka, titik, strip, atau garis bawah (tanpa spasi).',
                    ],
                ];
        }

        if ($def->parentField !== null && $def->parentEntity !== null) {
            $parentLength = service('masterRegistry')->get($def->parentEntity)->idMaxLength;

            $rules[$def->parentField] = [
                'rules'  => "{$required}|max_length[{$parentLength}]",
                'errors' => ['required' => 'Induk wajib dipilih.', 'max_length' => 'Induk tidak valid.'],
            ];
        }

        $rules[$def->nameField] = [
            'rules'  => "{$required}|string|max_length[{$def->nameMaxLength}]",
            'errors' => [
                'required'   => "{$def->nameLabel} wajib diisi.",
                'string'     => "{$def->nameLabel} harus teks.",
                'max_length' => "{$def->nameLabel} maksimal {$def->nameMaxLength} karakter.",
            ],
        ];

        foreach ($def->fields as $field) {
            // Field ref: panjang maksimal mengikuti kode master rujukannya (bentuk kanonik & keberadaan dicek service).
            $refIdMaxLength = $field->type === MasterField::TYPE_REF
                ? service('masterRegistry')->get((string) $field->entity)->idMaxLength
                : null;

            $rules[$field->name] = [
                'rules'  => $field->validationRules($creating, $refIdMaxLength),
                'errors' => $field->validationMessages(),
            ];
        }

        if ($def->hasOrder) {
            $rule = $this->orderRule($def);

            $rules[MasterDefinition::ORDER_FIELD] = [
                'rules'  => 'permit_empty|' . $rule['rules'],
                'errors' => $rule['errors'],
            ];
        }

        if ($def->hasStatus) {
            $rules[MasterDefinition::STATUS_FIELD] = [
                'rules'  => $creating ? 'permit_empty|in_list[1,2]' : 'if_exist|in_list[1,2]',
                'errors' => ['in_list' => "Status hanya boleh '1' (aktif) atau '2' (tidak aktif)."],
            ];
        }

        return $rules;
    }

    /**
     * Rule `order` (tanpa required/permit_empty): bilangan bulat >= 1. Mode urutan manual (CR-009) menyimpan nilainya
     * apa adanya, jadi juga dibatasi tipe kolom `order` (mis. TINYINT 127) → 422, bukan 500/terpotong di DB. Mode shift
     * tidak perlu batas atas: posisi dijepit ke jumlah entri.
     *
     * @return array{rules: string, errors: array<string, string>}
     */
    protected function orderRule(MasterDefinition $def): array
    {
        $rules  = 'is_natural_no_zero';
        $errors = ['is_natural_no_zero' => 'Urutan harus bilangan bulat minimal 1.'];

        if ($def->isManualOrder()) {
            $rules .= '|less_than_equal_to[' . $def->orderMax() . ']';
            $errors['less_than_equal_to'] = 'Urutan maksimal ' . number_format($def->orderMax(), 0, ',', '.') . '.';
        }

        return ['rules' => $rules, 'errors' => $errors];
    }

    /**
     * Payload tambah/ubah. Field boolean (CR-009) menerima JSON true/false selain '1'/'0': dikonversi dulu karena rule
     * in_list membaca false sebagai string kosong.
     *
     * @return array<string, mixed>
     */
    protected function input(MasterDefinition $def): array
    {
        $payload = $this->payload();

        foreach ($def->fields as $field) {
            if ($field->type === MasterField::TYPE_BOOLEAN && is_bool($payload[$field->name] ?? null)) {
                $payload[$field->name] = $payload[$field->name] ? '1' : '0';
            }
        }

        return $payload;
    }
}
