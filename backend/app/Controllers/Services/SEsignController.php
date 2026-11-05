<?php

declare(strict_types=1);

namespace App\Controllers\Services;

use App\Controllers\BaseController;
use App\Libraries\Esign\LayananEsignMudig;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\EsignMudig;
use Throwable;

/**
 * `services/s_esign/{check_user,sign_pdf}` untuk MuDig (H-02, F6-16, MAKE-065 B4b) — filter `klienLayanan:esign` (MAKE-058,
 * ADR-036). Amplop [K] `{"status":"1"|"2","msg","data"}` (status STRING ikut `S_esign.php`) HTTP 200; auth gagal 401 oleh
 * filter. Logika: {@see LayananEsignMudig}.
 *
 * Unggahan [V2]: `file` (PDF, `%PDF` di 1024 byte pertama) dan `imageTTD` (JPEG/PNG), batas di {@see EsignMudig}; berkas
 * tidak pernah ditulis ke disk aplikasi.
 *
 * @property IncomingRequest $request
 */
final class SEsignController extends BaseController
{
    public function checkUser(): ResponseInterface
    {
        return $this->jalankan(fn (): array => $this->layanan()->cekPengguna(is_string($n = $this->request->getGet('nik')) ? $n : ''));
    }

    public function signPdf(): ResponseInterface
    {
        return $this->jalankan(function (): array {
            $post = $this->request->getPost();
            $cfg  = config(EsignMudig::class);
            $pdf  = $this->berkas('file', $cfg->pdfMaks, static fn (string $isi): bool => str_contains(substr($isi, 0, 1024), '%PDF'));
            $ttd  = $this->berkas('imageTTD', $cfg->gambarMaks, static fn (string $isi): bool => ($info = @getimagesizefromstring($isi)) !== false && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true));

            return $this->layanan()->tandaTangani(is_array($post) ? $post : [], $pdf['isi'] ?? null, $pdf['nama'] ?? null, $ttd['isi'] ?? null);
        });
    }

    private function layanan(): LayananEsignMudig
    {
        return new LayananEsignMudig(service('esignGatewayNikLangsung'), db_connect());
    }

    /**
     * @param callable(): array{status: string, msg: string|null, data: array<string, string>} $kerja
     */
    private function jalankan(callable $kerja): ResponseInterface
    {
        try {
            $hasil = $kerja();
        } catch (Throwable $e) {
            log_message('error', '[s_esign] galat {kelas} di {berkas}:{baris}.', ['kelas' => $e::class, 'berkas' => clean_path($e->getFile()), 'baris' => $e->getLine()]);
            $hasil = ['status' => '2', 'msg' => LayananEsignMudig::PESAN_GALAT, 'data' => []];
        }

        return $this->response->setStatusCode(200)->setHeader('Cache-Control', 'no-store')->setJSON($hasil);
    }

    /**
     * Isi unggahan bila sah (terunggah, ukuran 1..maks, lolos cek isi); selain itu null → "Invalid parameter.".
     *
     * @param callable(string): bool $cek
     *
     * @return array{isi: string, nama: string}|null
     */
    private function berkas(string $field, int $maks, callable $cek): ?array
    {
        $f = $this->request->getFile($field);

        // Tanpa isValid()/is_uploaded_file agar bisa diuji (pola LampiranController); path tmp berasal dari PHP, bukan klien.
        if (! $f instanceof UploadedFile || $f->getError() !== UPLOAD_ERR_OK || ! is_file($f->getTempName())) {
            return null;
        }

        $ukuran = (int) filesize($f->getTempName());

        if ($ukuran < 1 || $ukuran > $maks) {
            return null;
        }

        $isi = file_get_contents($f->getTempName());

        if (! is_string($isi) || $isi === '' || ! $cek($isi)) {
            return null;
        }

        return ['isi' => $isi, 'nama' => mb_substr(basename(str_replace('\\', '/', $f->getClientName())), 0, 256)];
    }
}
