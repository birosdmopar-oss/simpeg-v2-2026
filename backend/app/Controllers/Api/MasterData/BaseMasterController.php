<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

use App\Controllers\Api\ApiController;
use App\Exceptions\NotFoundException;
use App\Libraries\MasterData\MasterDefinition;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Controller generik master data (Modul G). Controller grup (UmumController, JabatanController, dst. — sesuai
 * "Files touched" di 02-MasterData.md) cukup mendaftarkan key master miliknya di $entities.
 * Logika bisnis seluruhnya di MasterService (ADR-002: controller tipis). Route dibangkitkan dari Config\MasterData.
 *
 * Role (Matriks Role x Endpoint Modul G): CRUD = role 1; options (dropdown) = UL_ALL (lihat Routes).
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

    public function options(string $entity): ResponseInterface
    {
        $parent = $this->request->getGet('parent');

        return $this->respondSuccess(service('masterService')->options(
            $this->definition($entity),
            is_string($parent) ? $parent : null,
        ));
    }

    public function create(string $entity): ResponseInterface
    {
        $def  = $this->definition($entity);
        $data = $this->validateOrFail($this->payload(), $this->rules($def, true));

        return $this->respondSuccess(service('masterService')->create($def, $data), 201);
    }

    public function update(string $entity, string $id): ResponseInterface
    {
        $def  = $this->definition($entity);
        $data = $this->validateOrFail($this->payload(), $this->rules($def, false));

        return $this->respondSuccess(service('masterService')->update($def, $id, $data));
    }

    public function setStatus(string $entity, string $id): ResponseInterface
    {
        $data = $this->validateOrFail($this->payload(), ['status' => 'required|in_list[0,1]']);

        return $this->respondSuccess(service('masterService')->setStatus($this->definition($entity), $id, (string) $data['status']));
    }

    public function reorder(string $entity, string $id): ResponseInterface
    {
        $data = $this->validateOrFail($this->payload(), ['order' => 'required|is_natural_no_zero']);

        return $this->respondSuccess(service('masterService')->reorder($this->definition($entity), $id, (int) $data['order']));
    }

    /**
     * Soft delete (status '0'); master tidak pernah di-hard-delete.
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
            // Kode: huruf/angka/titik/strip/garis bawah, tanpa spasi.
            $rules[$def->primaryKey] = [
                'rules'  => "required|max_length[{$def->idMaxLength}]|regex_match[/^[A-Za-z0-9._-]+$/]",
                'errors' => [
                    'required'    => 'Kode wajib diisi.',
                    'max_length'  => "Kode maksimal {$def->idMaxLength} karakter.",
                    'regex_match' => 'Kode hanya boleh huruf, angka, titik, strip, atau garis bawah (tanpa spasi).',
                ],
            ];
        }

        if ($def->parentField !== null) {
            $rules[$def->parentField] = [
                'rules'  => "{$required}|max_length[{$def->idMaxLength}]",
                'errors' => ['required' => 'Induk wajib dipilih.'],
            ];
        }

        $rules[$def->nameField] = [
            'rules'  => "{$required}|string|max_length[{$def->nameMaxLength}]",
            'errors' => [
                'required'   => "{$def->nameLabel} wajib diisi.",
                'max_length' => "{$def->nameLabel} maksimal {$def->nameMaxLength} karakter.",
            ],
        ];

        foreach ($def->fields as $field) {
            $rules[$field->name] = [
                'rules'  => $field->validationRules($creating),
                'errors' => $field->validationMessages(),
            ];
        }

        if ($def->hasOrder) {
            $rules[MasterDefinition::ORDER_FIELD] = [
                'rules'  => 'permit_empty|is_natural_no_zero',
                'errors' => ['is_natural_no_zero' => 'Urutan harus bilangan bulat minimal 1.'],
            ];
        }

        if ($def->hasStatus) {
            $rules[MasterDefinition::STATUS_FIELD] = [
                'rules'  => 'permit_empty|in_list[0,1]',
                'errors' => ['in_list' => "Status hanya boleh '1' (aktif) atau '0' (non-aktif)."],
            ];
        }

        return $rules;
    }
}
