<?php

declare(strict_types=1);

namespace App\Controllers\Simapi;

use App\Controllers\BaseController;
use App\Libraries\Integrasi\Simapi\PenyajiPegawai;
use App\Libraries\Integrasi\Simapi\ResponsSimapi;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * MAKE-059 (H-06) — `simapi/v1/a_employee/{method}/{nip}` [K `A_employee.php`], filter `simapiToken`. Semua 25 method satu pola
 * legacy: NIP kosong → "Invalid paramater"; NIP tidak ada di `pegawai` (status apa pun, termasuk 10) → "NIP not valid";
 * data tidak ada → "Data … tidak tersedia"; selain itu "01" "Success". Tidak ada batas type (hanya `change_history`, MAKE-060).
 */
class AEmployee extends BaseController
{
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
