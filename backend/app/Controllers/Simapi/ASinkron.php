<?php

declare(strict_types=1);

namespace App\Controllers\Simapi;

use App\Controllers\BaseController;
use App\Libraries\Integrasi\Simapi\PenyajiSinkron;
use App\Libraries\Integrasi\Simapi\ResponsSimapi;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * MAKE-059 (F6-07) — `simapi/v1/a_sinkron/ambil_riwayat_mutasi` [K `A_sinkron.php:59-114`], filter `simapiToken`. Pesan
 * token legacy endpoint ini ("Invalid Token or IP Address"/"Token Expired") diganti pesan seragam filter "Token is not valid"
 * [V2]. `ambil_seluruh_pengguna` tidak dibuat (route tidak ada → 404, Q6-03 = A).
 */
class ASinkron extends BaseController
{
    public function ambilRiwayatMutasi(): ResponseInterface
    {
        $data = (new PenyajiSinkron(db_connect()))->riwayatMutasi();

        if ($data === []) {
            return ResponsSimapi::gagal('Tabel riwayat mutasi jabatan kosong'); // [K :106-108]
        }

        return ResponsSimapi::sukses($data, 'Berhasil mengambil ' . count($data) . ' data riwayat mutasi jabatan terupdate'); // [K :103-105]
    }
}
