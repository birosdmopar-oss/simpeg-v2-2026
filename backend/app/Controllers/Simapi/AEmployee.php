<?php

declare(strict_types=1);

namespace App\Controllers\Simapi;

use App\Controllers\BaseController;
use App\Exceptions\NotFoundException;
use App\Libraries\Integrasi\Klien\HakKlien;
use App\Libraries\Integrasi\Simapi\PenyajiChangeHistory;
use App\Libraries\Integrasi\Simapi\PenyajiPegawai;
use App\Libraries\Integrasi\Simapi\ResponsSimapi;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

/**
 * MAKE-059 (H-06) — `simapi/v1/a_employee/{method}/{nip}` [K `A_employee.php`], filter `simapiToken`. Semua 25 method satu pola
 * legacy: NIP kosong → "Invalid paramater"; NIP tidak ada di `pegawai` (status apa pun, termasuk 10) → "NIP not valid";
 * data tidak ada → "Data … tidak tersedia"; selain itu "01" "Success". Tidak ada batas type kecuali `change_history` (MAKE-060, type 2).
 */
class AEmployee extends BaseController
{
    /**
     * [V2, keputusan Horii = A] foto pegawai untuk klien SIMAPI (token SIMAPI, filter `simapiToken`). Sumber sama dengan
     * `api/v1/pegawai/{nip}/foto` (BiodataService::fotoSistem). NIP asing / tanpa foto / berkas tidak ada / bukan gambar →
     * 404 seragam tanpa alasan; log hanya kode hasil (tanpa NIP).
     */
    public function foto(string $nip = ''): ResponseInterface
    {
        try {
            $foto = $nip !== '' && (new PenyajiPegawai(db_connect()))->nipAda($nip) ? service('biodataService')->fotoSistem($nip) : null;
        } catch (NotFoundException) {
            $foto = null;
        }

        if ($foto === null) {
            log_message('info', 'SIMAPI a_employee/foto: 404');

            return $this->response->setStatusCode(404)->setHeader('Cache-Control', 'no-store')->setHeader('X-Content-Type-Options', 'nosniff')->setBody('');
        }

        return $this->response
            ->setStatusCode(200)
            ->setContentType($foto['mime'])
            ->setHeader('Content-Disposition', 'inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $foto['nama']) . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($foto['isi']);
    }

    /**
     * MAKE-060 (F6-06) — `a_employee/change_history` [K `AE:20-118`]: hanya klien type 2 (selain itu `"01"` data kosong, `AE:48-50`);
     * baris baru sejak ack terakhir, ditandai terkirim dalam transaksi yang sama ({@see PenyajiChangeHistory}).
     */
    public function changeHistory(): ResponseInterface
    {
        $klien = service('klienSaatIni');

        if ($klien->jenis() !== HakKlien::JENIS_SIMAPI_BARU) {
            return ResponsSimapi::kosong();
        }

        try {
            $data = (new PenyajiChangeHistory(db_connect()))->ambilDanTandai((int) $klien->id());
        } catch (RuntimeException $e) {
            log_message('error', 'SIMAPI change_history gagal: ' . (new \ReflectionClass($e))->getShortName() . ' kode ' . $e->getCode() . '.');

            return ResponsSimapi::gagal(ResponsSimapi::PESAN_SERVER);
        }

        return ResponsSimapi::sukses($data);
    }

    public function sajikan(string $method, string $nip = ''): ResponseInterface
    {
        if ($nip === '') {
            return ResponsSimapi::gagal(ResponsSimapi::PESAN_PARAMETER);
        }

        $penyaji = new PenyajiPegawai(db_connect());

        if (! $penyaji->nipAda($nip)) {
            return ResponsSimapi::gagal(ResponsSimapi::PESAN_NIP);
        }

        $data = $penyaji->sajikan($method, $nip);

        return $data === null ? ResponsSimapi::gagal(PenyajiPegawai::pesanKosong($method)) : ResponsSimapi::sukses($data);
    }
}
