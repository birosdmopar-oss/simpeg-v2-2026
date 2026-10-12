<?php

declare(strict_types=1);

namespace App\Controllers\Api\Kepegawaian;

use App\Controllers\Api\ApiController;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * `GET api/v1/pegawai/{nip}/riwayat` → `{ tabs: descriptor[] }` (WS-1 M3 MAKE-006, kontrak aditif README Kepegawaian).
 *
 * Descriptor tab riwayat pegawai tanpa detail biodata, untuk halaman riwayat sementara frontend selama `GET pegawai/{nip}`
 * (MAKE-010, WS-2) belum ada. Isinya = RiwayatRegistryInterface::descriptorUntuk() — fungsi yang sama yang dipakai
 * `data.tabs` detail pegawai, jadi tidak ada dua sumber logika.
 *
 * Urutan pemeriksaan kontrak: 401 (filter `jwt`) → 403 lingkup (`bolehLihat`) → 404 NIP. Bagi role ber-lingkup NIP yang
 * tidak ada juga 403 (keberadaan NIP tidak bocor).
 */
final class RiwayatTabController extends ApiController
{
    protected bool $castNumericParams = false;

    public function index(string $nip): ResponseInterface
    {
        /** @var AuthContext $auth */
        $auth = service('authContext');

        if (! service('pegawaiScope')->bolehLihat($auth, $nip)) {
            throw new ForbiddenException('Anda tidak berhak mengakses data pegawai ini.');
        }

        if (db_connect()->table('pegawai')->where('nip', $nip)->countAllResults() === 0) {
            throw new NotFoundException('Pegawai tidak ditemukan.');
        }

        return $this->respondSuccess(['tabs' => service('riwayatRegistry')->descriptorUntuk($auth, $nip)]);
    }
}
