<?php

declare(strict_types=1);

namespace App\Controllers\Api\Kepegawaian;

use App\Controllers\Api\ApiController;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\RiwayatServiceInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Mimes;

/**
 * Controller generik riwayat pegawai `api/v1/pegawai/{nip}/riwayat/{jenis}` (WS-1 M1 MAKE-004; kontrak README
 * Kepegawaian). Tipis (ADR-002): hanya membaca request lalu meneruskan ke service `riwayatService` (RiwayatEngine),
 * yang memeriksa jenis, izin Definisi × role, PegawaiScope, keberadaan data, dan validasi.
 *
 * Parameter route: NIP dan slug jenis selalu string (NIP berawalan nol tidak boleh di-cast), `{id}` angka (`(:num)`).
 * Berkas lampiran dikirim sebagai `berkas[<id_riwayat>]` (multipart), satu berkas per kode; berkas kolom (aditif
 * MAKE-007, `RiwayatDefinisi::kolomBerkas()`) sebagai `berkas[<kolom>]`, diunduh lewat `.../{id}/berkas/{kolom}`.
 */
abstract class BaseRiwayatController extends ApiController
{
    protected bool $castNumericParams = false;

    public function index(string $nip, string $jenis): ResponseInterface
    {
        return $this->respondSuccess($this->service()->daftar($this->auth(), $nip, $jenis));
    }

    public function show(string $nip, string $jenis, string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->detail($this->auth(), $nip, $jenis, (int) $id));
    }

    public function create(string $nip, string $jenis): ResponseInterface
    {
        $row = $this->service()->tambah($this->auth(), $nip, $jenis, $this->payload(), $this->berkas());

        return $this->respondSuccess($row, 201);
    }

    public function update(string $nip, string $jenis, string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->ubah($this->auth(), $nip, $jenis, (int) $id, $this->payload(), $this->berkas()));
    }

    public function delete(string $nip, string $jenis, string $id): ResponseInterface
    {
        $this->service()->hapus($this->auth(), $nip, $jenis, (int) $id);

        return $this->respondSuccess(['deleted' => true, 'soft_delete' => true]);
    }

    public function process(string $nip, string $jenis, string $id): ResponseInterface
    {
        $data   = $this->payload();
        $errors = [];

        foreach (['aksi', 'reason_note'] as $field) {
            if (isset($data[$field]) && ! is_string($data[$field])) {
                $errors[$field] = [self::NON_TEXT_FIELD_MESSAGE];
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        $aksi = isset($data['aksi']) ? (string) $data['aksi'] : '';
        $note = isset($data['reason_note']) ? (string) $data['reason_note'] : null;

        return $this->respondSuccess($this->service()->proses($this->auth(), $nip, $jenis, (int) $id, $aksi, $note));
    }

    /**
     * `GET pegawai/{nip}/riwayat/{jenis}/pratinjau` — pratinjau isian turunan form tambah (aditif MAKE-017).
     */
    public function pratinjau(string $nip, string $jenis): ResponseInterface
    {
        $service = $this->service();

        if (! $service instanceof RiwayatEngine) {
            throw new NotFoundException('Pratinjau tidak tersedia.');
        }

        /** @var array<string, mixed> $query */
        $query = $this->request->getGet() ?? [];

        return $this->respondSuccess($service->pratinjauTambah($this->auth(), $nip, $jenis, $query));
    }

    /**
     * `GET pegawai/{nip}/riwayat/{jenis}/{id}/berkas/{kolom}` — unduh berkas kolom (aditif MAKE-007).
     */
    public function unduhBerkas(string $nip, string $jenis, string $id, string $kolom): ResponseInterface
    {
        $service = $this->service();

        if (! $service instanceof RiwayatEngine) {
            throw new NotFoundException('Berkas tidak ditemukan.');
        }

        $hasil = $service->unduhKolomBerkas($this->auth(), $nip, $jenis, (int) $id, $kolom);
        $mime  = (string) (Mimes::guessTypeFromExtension(pathinfo($hasil['nama'], PATHINFO_EXTENSION)) ?? 'application/octet-stream');

        return $this->response
            ->setStatusCode(200)
            ->setContentType($mime)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $hasil['nama'] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($hasil['isi']);
    }

    protected function service(): RiwayatServiceInterface
    {
        return service('riwayatService');
    }

    protected function auth(): AuthContext
    {
        return service('authContext');
    }

    /**
     * Berkas `berkas[<id_riwayat>]` dari multipart: kode angka, satu berkas per kode, unggahan valid. Kode yang tidak
     * diunggah (UPLOAD_ERR_NO_FILE) dilewati.
     *
     * @return array<int|string, UploadedFile>
     */
    protected function berkas(): array
    {
        $files = $this->request->getFiles();

        if (! isset($files['berkas'])) {
            return [];
        }

        if (! is_array($files['berkas'])) {
            throw ValidationException::forField('berkas', 'Berkas dikirim sebagai berkas[<id_riwayat>].');
        }

        $hasil  = [];
        $errors = [];

        foreach ($files['berkas'] as $kode => $file) {
            $kode  = (string) $kode;
            $kolom = preg_match('/^[a-z][a-z0-9_]{0,63}$/', $kode) === 1;

            // Kunci angka = kode jenis_rwy lampiran; kunci nama kolom = kolom berkas Definisi (aditif MAKE-007, divalidasi
            // engine terhadap kolomBerkas()).
            if (! ctype_digit($kode) && ! $kolom) {
                $errors["berkas.{$kode}"] = ['Kode lampiran tidak valid.'];

                continue;
            }

            if (! $file instanceof UploadedFile) {
                $errors["berkas.{$kode}"] = ['Kirim satu berkas per jenis lampiran.'];

                continue;
            }

            if ($file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if (! $file->isValid()) {
                $errors["berkas.{$kode}"] = ['Berkas gagal diunggah: ' . $file->getErrorString()];

                continue;
            }

            $hasil[ctype_digit($kode) ? (int) $kode : $kode] = $file;
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        return $hasil;
    }
}
