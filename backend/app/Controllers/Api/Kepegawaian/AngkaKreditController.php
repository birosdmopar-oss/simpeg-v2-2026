<?php

declare(strict_types=1);

namespace App\Controllers\Api\Kepegawaian;

use App\Controllers\Api\ApiController;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\AngkaKredit\AkPermenpanrb;
use App\Libraries\Kepegawaian\AngkaKredit\MasterAkJfService;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Angka kredit JF (MAKE-021, keputusan user 16-10-2026):
 *   GET  pegawai/{nip}/ak/target-kp          target AK kenaikan pangkat PermenPAN-RB 1/2023 (AkPermenpanrb, port getAKAccu
 *                                            legacy) — izin = melihat riwayat AK (Definisi `ak`: izin lihat, lingkup
 *                                            `bolehLihat`, NIP ada, `bolehAksiUntuk(Lihat)`: role 2/7 hanya JF tertentu)
 *   GET  ak-jf                               daftar master `dm_ak_jf` (role 1, filter rute)
 *   GET  ak-jf/opsi                          pilihan form tambah
 *   GET  ak-jf/jabatan?id_group_jabatan=&id_sub_group_jabatan=&id_jenjang_jf=   jabatan untuk pilihan form
 *   POST ak-jf                               tambah { id_jabatan, id_pangkat, ak } → 201
 *   PUT  ak-jf/{id_jabatan}/{id_pangkat}     ubah AK { ak }
 * Tanpa hapus [K] (hapus legacy tidak berfungsi).
 */
final class AngkaKreditController extends ApiController
{
    protected bool $castNumericParams = false;

    public function targetKp(string $nip): ResponseInterface
    {
        $auth = $this->auth();
        $d    = service('riwayatRegistry')->definisi('ak');

        if ($d === null || ! $d->boleh(AksiRiwayat::Lihat, $auth->role())) {
            throw new ForbiddenException('Anda tidak berhak melihat angka kredit.');
        }

        if (! service('pegawaiScope')->bolehLihat($auth, $nip)) {
            throw new ForbiddenException('Anda tidak berhak mengakses data pegawai ini.');
        }

        if (db_connect()->table('pegawai')->where('nip', $nip)->countAllResults() === 0) {
            throw new NotFoundException('Pegawai tidak ditemukan.');
        }

        if (! $d->bolehAksiUntuk($auth, AksiRiwayat::Lihat, $nip)) {
            throw new ForbiddenException('Anda tidak berhak melihat angka kredit pegawai ini.');
        }

        return $this->respondSuccess((new AkPermenpanrb())->hitung($nip));
    }

    public function index(): ResponseInterface
    {
        return $this->respondSuccess($this->master()->daftar());
    }

    public function opsi(): ResponseInterface
    {
        return $this->respondSuccess($this->master()->opsi());
    }

    public function jabatan(): ResponseInterface
    {
        /** @var array<string, mixed> $query */
        $query = $this->request->getGet() ?? [];

        return $this->respondSuccess($this->master()->jabatan($query));
    }

    public function create(): ResponseInterface
    {
        return $this->respondSuccess($this->master()->tambah($this->auth(), $this->payload()), 201);
    }

    public function update(string $idJabatan, string $idPangkat): ResponseInterface
    {
        return $this->respondSuccess($this->master()->ubah($this->auth(), (int) $idJabatan, (int) $idPangkat, $this->payload()));
    }

    private function master(): MasterAkJfService
    {
        return new MasterAkJfService();
    }

    private function auth(): AuthContext
    {
        return service('authContext');
    }
}
