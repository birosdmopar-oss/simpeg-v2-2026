<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Exceptions\ApiException;
use App\Exceptions\BadRequestException;
use App\Exceptions\ValidationException;
use App\Filters\JwtAuthFilter;
use App\Libraries\ApiExceptionHandler;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base controller seluruh endpoint API.
 * Envelope response mengikuti ADR-001:
 *   sukses : {status: 'success', data}
 *   gagal  : {status: 'error', message, errors?}
 *
 * _remap() menangkap App\Exceptions\ApiException dari method mana pun dan mengubahnya ke envelope,
 * sehingga method controller tetap tipis (ADR-002) dan perilaku sama di feature test maupun runtime.
 *
 * CR-007 (prasyarat strictOn): input yang bukan UTF-8 valid (query string, body, parameter route) ditolak 422
 * sebelum menyentuh DB, dan error data MySQL (ApiExceptionHandler::DATA_ERROR_CODES) diterjemahkan ke 422 generik
 * dengan detail hanya di log. Segmen URL non-UTF-8 biasanya sudah ditolak Router CI4 lebih dulu (400 lewat
 * ApiExceptionHandler); cek parameter route di sini lapis cadangan.
 *
 * CR-185 (jaring terpusat sesi NIP basi): cek `masihMemegangNip` di JwtAuthFilter berupa SELECT biasa, jadi request yang
 * datang selama transaksi koreksi NIP / hapus pegawai belum commit tetap lolos, lalu INSERT ber-NIP lama kena FK 1452
 * sesudah commit. Galat 1452 yang sesinya TERBUKTI basi ({@see JwtAuthFilter::sesiNipTerbuktiBasi()}) dijawab 401 dengan
 * body yang sama persis dengan filter; 1452 dengan sesi masih sah / token tanpa NIP tetap 500 (bug FK sungguhan tetap
 * terlihat). Jaring khusus FaqService (403) dan TiketService (422) tidak berubah karena dilempar sebagai ApiException.
 */
abstract class ApiController extends BaseController
{
    public const INVALID_ENCODING_MESSAGE = 'Input tidak valid (encoding).';

    public const INVALID_ENCODING_FIELD_MESSAGE = 'Isian mengandung karakter yang tidak valid (bukan UTF-8).';

    /**
     * Pesan 422 untuk isian yang bukan teks/angka bulat (array/objek/boolean) — sama dengan UserService::textInput().
     */
    public const NON_TEXT_FIELD_MESSAGE = 'Isian harus berupa teks.';

    /**
     * Cast parameter route numerik ke int. Matikan untuk controller yang parameternya kode string
     * (mis. master data: kode '01' tidak boleh berubah jadi 1).
     */
    protected bool $castNumericParams = true;

    /** MySQL ER_NO_REFERENCED_ROW_2: INSERT/UPDATE anak tanpa induk FK (CR-185). */
    private const ER_FK_TANPA_INDUK = 1452;

    /**
     * @param mixed ...$params parameter route (string) yang diteruskan ke method
     */
    public function _remap(string $method, ...$params): mixed
    {
        if (! method_exists($this, $method) || str_starts_with($method, '_')) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Parameter route selalu string; dengan strict_types, cast angka ke int agar cocok dengan signature (int $id).
        if ($this->castNumericParams) {
            $params = array_map(static fn ($p) => is_string($p) && ctype_digit($p) ? (int) $p : $p, $params);
        }

        try {
            $this->assertValidUtf8Input($params);

            return $this->{$method}(...$params);
        } catch (ApiException $e) {
            [$status, $body] = ApiExceptionHandler::toEnvelope($e);

            return $this->response->setStatusCode($status)->setJSON($body);
        } catch (DatabaseException $e) {
            if ($e->getCode() === self::ER_FK_TANPA_INDUK && JwtAuthFilter::sesiNipTerbuktiBasi(service('authContext'))) {
                // Galat asli hanya di log, tanpa NIP / pesan MySQL (nama constraint cukup dari kode + kelas).
                log_message('warning', 'FK 1452 pada sesi NIP basi diterjemahkan ke 401: [{code}] {class}.', ['code' => $e->getCode(), 'class' => $e::class]);
                service('authContext')->clear();

                return $this->response->setStatusCode(401)->setJSON(JwtAuthFilter::BODY_UNAUTHORIZED);
            }

            // Hanya error data (nilai tidak muat/tidak sesuai kolom di koneksi strict) yang menjadi 422; error DB lain
            // (1062 yang tidak ditangani service, lock wait, deadlock, koneksi) tetap dilempar ke handler global (500).
            if (! ApiExceptionHandler::isDataError($e)) {
                throw $e;
            }

            // Detail (kolom, nilai) hanya di log — exception ini ditelan di sini sehingga tidak dicatat handler global.
            log_message('error', 'Error data database diterjemahkan ke 422: [{code}] {message} in {exFile} on line {exLine}.', [
                'code'    => $e->getCode(),
                'message' => $e->getMessage(),
                'exFile'  => clean_path($e->getFile()),
                'exLine'  => $e->getLine(),
            ]);

            [$status, $body] = ApiExceptionHandler::toEnvelope($e);

            return $this->response->setStatusCode($status)->setJSON($body);
        }
    }

    /**
     * `Cache-Control` akhir persis `private, no-store` (CR-147). Nilai bawaan Response CI4 (`noCache()`) berupa daftar, sehingga
     * `setHeader` saja menambah nilai, bukan mengganti; header dibuang dulu lalu dipasang ulang.
     */
    protected function tanpaCache(ResponseInterface $r): ResponseInterface
    {
        return $r->removeHeader('Cache-Control')->setHeader('Cache-Control', 'private, no-store');
    }

    protected function respondSuccess(mixed $data = null, int $status = 200): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setJSON(['status' => 'success', 'data' => $data]);
    }

    /**
     * @param array<string, mixed>|null $errors
     */
    protected function respondError(string $message, int $status = 400, ?array $errors = null): ResponseInterface
    {
        $body = ['status' => 'error', 'message' => $message];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return $this->response->setStatusCode($status)->setJSON($body);
    }

    /**
     * Body JSON request sebagai array (fallback ke form-data).
     * Body yang bukan JSON valid → BadRequestException (400), kecuali request memang dikirim sebagai form.
     * Nilai/key yang bukan UTF-8 valid (hanya bisa lewat form-urlencoded/multipart; JSON seperti itu sudah ditolak
     * json_decode) → ValidationException (422) per field.
     *
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        try {
            $json = $this->request->getJSON(true);
        } catch (HTTPException) {
            // Body form-urlencoded juga gagal di-parse sebagai JSON; itu bukan error, ambil dari getPost().
            if (! $this->isFormRequest()) {
                throw new BadRequestException('Body JSON tidak valid.');
            }

            $json = null;
        }

        /** @var array<string, mixed> $data */
        $data = is_array($json) ? $json : $this->request->getPost();

        self::assertUtf8Fields($data);

        return $data;
    }

    /**
     * Penjaga UTF-8 untuk input yang dibaca di luar payload(): parameter route dan query string (getGet()).
     * Byte non-UTF-8 di koneksi strict gagal ditulis (1366 → sebelumnya 500), dan di kolom utf8mb4_unicode_ci bisa
     * cocok dengan nilai lain saat dibandingkan (mis. username 'admin\xC3(' = 'admin') — keduanya dicegah di sini.
     *
     * @param array<array-key, mixed> $params
     *
     * @throws ValidationException
     */
    private function assertValidUtf8Input(array $params): void
    {
        // Segmen route non-UTF-8 sudah ditolak Router CI4 (permittedURIChars dengan modifier /u); ini lapis kedua.
        // Segmen tidak punya nama field, jadi tanpa errors per field.
        if (self::invalidUtf8Fields($params) !== []) {
            throw new ValidationException(self::INVALID_ENCODING_MESSAGE);
        }

        $query = $this->request->getGet();

        if (is_array($query)) {
            self::assertUtf8Fields($query);
        }
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws ValidationException 422 dengan errors per field (notasi titik untuk array bersarang)
     */
    private static function assertUtf8Fields(array $data): void
    {
        $fields = self::invalidUtf8Fields($data);

        if ($fields !== []) {
            throw new ValidationException(
                self::INVALID_ENCODING_MESSAGE,
                array_fill_keys($fields, [self::INVALID_ENCODING_FIELD_MESSAGE]),
            );
        }
    }

    /**
     * Nama field (notasi titik) yang key atau nilai string-nya bukan UTF-8 valid. Nama field ikut di-scrub agar
     * respons JSON tetap bisa di-encode.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private static function invalidUtf8Fields(array $data, string $prefix = ''): array
    {
        $fields = [];

        foreach ($data as $key => $value) {
            $name = $prefix . $key;

            if (! mb_check_encoding((string) $key, 'UTF-8') || (is_string($value) && ! mb_check_encoding($value, 'UTF-8'))) {
                $fields[] = mb_scrub($name, 'UTF-8');
            } elseif (is_array($value)) {
                array_push($fields, ...self::invalidUtf8Fields($value, $name . '.'));
            }
        }

        return $fields;
    }

    private function isFormRequest(): bool
    {
        $contentType = strtolower($this->request->getHeaderLine('Content-Type'));

        return str_starts_with($contentType, 'application/x-www-form-urlencoded')
            || str_starts_with($contentType, 'multipart/form-data');
    }

    /**
     * Validasi payload dengan rules CI4; gagal → ValidationException (422, errors per-field).
     *
     * @param array<string, mixed>                     $data
     * @param array<string, array<string, mixed>|string> $rules
     *
     * @return array<string, mixed> data yang lolos validasi (hanya key di rules)
     */
    protected function validateOrFail(array $data, array $rules): array
    {
        $validation = service('validation', null, false);
        $validation->setRules($rules);

        if (! $validation->run($data)) {
            $errors = [];

            foreach ($validation->getErrors() as $field => $message) {
                $errors[$field] = [$message];
            }

            throw new ValidationException('Validasi gagal.', $errors);
        }

        return array_intersect_key($data, $rules);
    }

    /**
     * validateOrFail() untuk payload yang setiap field-nya bernilai tunggal: null, teks, atau angka bulat (pola
     * UserService::textInput). Array/objek JSON — juga boolean/pecahan — ditolak lebih dulu dengan 422 per field
     * NON_TEXT_FIELD_MESSAGE, semua field salah dalam satu respons (ISSUE-023). Tanpa penjaga ini `permit_empty` CI4
     * menganggap `[]`/`{}` (dan `false`) kosong sehingga nilai itu lolos ke controller/service yang meng-cast-nya →
     * "Array to string conversion" → 500. Array berisi memang sudah ditolak StrictRules (`string`, `in_list`,
     * `integer`), tetapi dengan pesan bawaan CI4 yang berbeda per rule.
     *
     * @param array<string, mixed>                       $data
     * @param array<string, array<string, mixed>|string> $rules
     *
     * @return array<string, mixed> data yang lolos validasi (hanya key di rules)
     */
    protected function validateTextOrFail(array $data, array $rules): array
    {
        $errors = [];

        foreach (array_keys($rules) as $field) {
            $value = $data[$field] ?? null;

            if ($value !== null && ! is_string($value) && ! is_int($value)) {
                $errors[$field] = [self::NON_TEXT_FIELD_MESSAGE];
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        return $this->validateOrFail($data, $rules);
    }
}
