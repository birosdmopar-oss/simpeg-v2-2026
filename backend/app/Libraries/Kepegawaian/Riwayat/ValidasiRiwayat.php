<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat;

use App\Libraries\MasterData\MasterDefinition;
use App\Libraries\MasterData\MasterField;
use App\Libraries\MasterData\MasterRegistry;
use App\Models\MasterData\MasterModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Validasi + normalisasi payload riwayat dari RiwayatDefinisi::fields() (WS-1 M1 MAKE-004), memakai aturan MasterField
 * yang sama dengan master data (Modul G).
 *
 * - Hanya field Definisi yang diambil; key lain (kolom sistem `status`, `approved_by`, PK, `nip`, …) diabaikan.
 * - Nilai wajib tunggal (teks/angka/null). Array/objek → 422 per field. JSON boolean dibaca 1/0.
 * - String kosong (setelah trim) = NULL untuk field opsional; field wajib kosong → 422 (`MasterField::normalize`). Aturan
 *   ini juga berlaku untuk multipart, jadi klien boleh mengirim '' untuk NULL.
 * - Tambah: semua field diperiksa (required). Ubah: hanya field yang dikirim (parsial, `if_exist`).
 * - Field ref: kode master rujukan harus ada, bukan baris sistem (kecuali allowSystem), dan aktif — status aktif hanya
 *   diperiksa bila nilainya berubah (data lama yang merujuk master nonaktif tetap bisa disimpan); rantai `dependsOn`
 *   diperiksa seperti MasterService.
 */
final class ValidasiRiwayat
{
    /**
     * Sama dengan ApiController::NON_TEXT_FIELD_MESSAGE.
     */
    public const PESAN_BUKAN_TEKS = 'Isian harus berupa teks.';

    public function __construct(private readonly MasterRegistry $master, private readonly BaseConnection $db)
    {
    }

    /**
     * @param array<string, mixed>      $data mentah dari request
     * @param array<string, mixed>|null $lama baris sebelum diubah (null = tambah)
     *
     * @return array{0: array<string, int|float|string|null>, 1: array<string, list<string>>} [data ternormalisasi, errors]
     */
    public function periksa(RiwayatDefinisi $definisi, array $data, ?array $lama): array
    {
        $tambah = $lama === null;
        $errors = [];
        $input  = [];
        $rules  = [];

        foreach ($definisi->fields() as $field) {
            if (! array_key_exists($field->name, $data)) {
                if ($tambah) {
                    $input[$field->name] = null;
                    $rules[$field->name] = ['rules' => $field->validationRules(true, $this->refMax($field)), 'errors' => $field->validationMessages()];
                }

                continue;
            }

            $nilai = $data[$field->name];

            if (is_bool($nilai)) {
                $nilai = $nilai ? '1' : '0';
            } elseif (is_int($nilai) || is_float($nilai)) {
                $nilai = (string) $nilai;
            } elseif ($nilai !== null && ! is_string($nilai)) {
                $errors[$field->name] = [self::PESAN_BUKAN_TEKS];

                continue;
            }

            $input[$field->name] = $nilai;
            $rules[$field->name] = ['rules' => $field->validationRules($tambah, $this->refMax($field)), 'errors' => $field->validationMessages()];
        }

        $validation = service('validation', null, false);
        $validation->setRules($rules);

        if (! $validation->run($input)) {
            foreach ($validation->getErrors() as $field => $pesan) {
                $errors[$field] ??= [$pesan];
            }
        }

        $hasil = [];

        foreach ($definisi->fields() as $field) {
            if (array_key_exists($field->name, $input) && ! isset($errors[$field->name])) {
                $hasil[$field->name] = $field->normalize($input[$field->name]);
            }
        }

        if ($errors === []) {
            $errors = $this->periksaRef($definisi, $hasil, $lama);
        }

        return [$hasil, $errors];
    }

    /**
     * @param array<string, int|float|string|null> $hasil
     * @param array<string, mixed>|null            $lama
     *
     * @return array<string, list<string>>
     */
    private function periksaRef(RiwayatDefinisi $definisi, array $hasil, ?array $lama): array
    {
        $errors = [];
        $fields = [];

        foreach ($definisi->fields() as $field) {
            $fields[$field->name] = $field;
        }

        foreach ($fields as $field) {
            if ($field->type !== MasterField::TYPE_REF || ! array_key_exists($field->name, $hasil)) {
                continue;
            }

            $nilai = $hasil[$field->name];

            if ($nilai === null || $nilai === '') {
                continue;
            }

            $nilai   = (string) $nilai;
            $berubah = $lama === null || (string) ($lama[$field->name] ?? '') !== $nilai;
            $refDef  = $this->master->get((string) $field->entity);

            if (! $refDef->isCanonicalId($nilai) || ($refDef->isSystemId($nilai) && ! $field->allowSystem)) {
                $errors[$field->name] = ["{$field->label} tidak ditemukan."];

                continue;
            }

            /** @var array<string, mixed>|null $ref */
            $ref = $this->db->table($refDef->table)->where($refDef->primaryKey, $nilai)->get()->getRowArray();

            if ($ref === null) {
                $errors[$field->name] = ["{$field->label} tidak ditemukan."];

                continue;
            }

            if ($berubah && $refDef->hasStatus && (string) $ref[MasterDefinition::STATUS_FIELD] !== MasterModel::STATUS_ACTIVE) {
                $errors[$field->name] = ["{$field->label} {$ref[$refDef->nameField]} sedang non-aktif."];

                continue;
            }

            if ($field->dependsOn === null || ! $field->checkDependsOn || $refDef->parentField === null) {
                continue;
            }

            $induk      = $hasil[$field->dependsOn] ?? $lama[$field->dependsOn] ?? null;
            $labelInduk = $fields[$field->dependsOn]->label ?? $field->dependsOn;

            if ($induk === null || $induk === '') {
                $errors[$field->name] = ["Pilih {$labelInduk} terlebih dahulu."];
            } elseif ((string) $ref[$refDef->parentField] !== (string) $induk) {
                $errors[$field->name] = ["{$field->label} {$ref[$refDef->nameField]} tidak berada di bawah {$labelInduk} yang dipilih."];
            }
        }

        return $errors;
    }

    private function refMax(MasterField $field): ?int
    {
        return $field->type === MasterField::TYPE_REF ? $this->master->get((string) $field->entity)->idMaxLength : null;
    }
}
