<?php

declare(strict_types=1);

namespace App\Controllers\Api\Kepegawaian;

use App\Constants\Role;
use App\Controllers\Api\ApiController;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Kariskarsu;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Halaman usulan mandiri Karpeg/Karis (WS-1 M4 MAKE-007, prefix `usulan/{jenis}` yang dicadangkan README kontrak).
 * Tulis/proses tetap lewat endpoint engine `pegawai/{nip}/riwayat/{jenis}[/{id}[/process]]`.
 *
 * - `GET usulan/{jenis}/saya` — usulan milik pemanggil (role ber-izin `tambah`, mis. 2/7): identitas ringkas (untuk label
 *   Karis/Karsu menurut jenis kelamin), hak aksi, syarat, dan daftar baris (engine `daftar`).
 * - `GET usulan/{jenis}` — antrean (legacy `list_ad`): role ber-izin `lihat` yang BUKAN UL_PEGAWAI (1, 4, 5), semua baris
 *   status selain 10 tanpa lingkup (keputusan user 12-10-2026), plus nama/unit/satker pegawai; `?status=` opsional;
 *   `can_process` dari izin `proses`.
 */
final class UsulanController extends ApiController
{
    protected bool $castNumericParams = false;

    public function saya(string $jenis): ResponseInterface
    {
        $auth = $this->auth();
        $d    = $this->definisi($jenis);
        $nip  = (string) ($auth->nip() ?? '');

        if ($nip === '' || ! $d->boleh(AksiRiwayat::Tambah, $auth->role())) {
            throw new ForbiddenException('Halaman ini untuk pegawai yang mengajukan usulan.');
        }

        /** @var array<string, mixed>|null $pegawai */
        $pegawai = db_connect()->table('pegawai')->select('nip, nama, glr_awal, glr_akhir, jenis_kelamin')->where('nip', $nip)->get()->getRowArray();

        if ($pegawai === null) {
            throw new NotFoundException('Pegawai tidak ditemukan.');
        }

        return $this->respondSuccess([
            'pegawai'          => $pegawai,
            'can_create'       => $d->boleh(AksiRiwayat::Tambah, $auth->role()),
            'can_edit'         => $d->boleh(AksiRiwayat::Ubah, $auth->role()),
            'can_delete'       => $d->boleh(AksiRiwayat::Hapus, $auth->role()),
            'syarat_terpenuhi' => ! $d instanceof Kariskarsu || Kariskarsu::punyaKeluarga($nip),
            'items'            => service('riwayatService')->daftar($auth, $nip, $jenis),
        ]);
    }

    public function antrean(string $jenis): ResponseInterface
    {
        $auth = $this->auth();
        $d    = $this->definisi($jenis);
        $role = $auth->role();

        if (! $d->boleh(AksiRiwayat::Lihat, $role) || in_array($role, Role::UL_PEGAWAI, true)) {
            throw new ForbiddenException('Anda tidak berhak melihat antrean usulan ini.');
        }

        $db      = db_connect();
        $tabel   = $d->tabel();
        $builder = $db->table($tabel . ' r')
            ->select('r.*, p.nama, p.glr_awal, p.glr_akhir, u.unit, s.satker')
            ->join('pegawai p', 'p.nip = r.' . $d->kolomNip())
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = r.' . $d->kolomNip(), 'left')
            ->join('unit u', 'u.id_unit = pmj.id_unit', 'left')
            ->join('satker s', 's.id_satker = pmj.id_satker', 'left')
            ->where('r.' . $d->kolomStatus() . ' !=', StatusRiwayat::Dihapus->value)
            ->orderBy('r.updated_at', 'DESC')
            ->orderBy('r.' . $d->primaryKey(), 'DESC');

        $status = $this->request->getGet('status');

        if (is_string($status) && $status !== '') {
            if (! ctype_digit($status) || ! isset($d->pemetaanStatus()[(int) $status]) || (int) $status === StatusRiwayat::Dihapus->value) {
                throw ValidationException::forField('status', 'Status tidak valid.');
            }

            $builder->where('r.' . $d->kolomStatus(), (int) $status);
        }

        return $this->respondSuccess([
            'can_process' => $d->boleh(AksiRiwayat::Proses, $role),
            'items'       => $builder->get()->getResultArray(),
        ]);
    }

    private function definisi(string $jenis): RiwayatDefinisi
    {
        $d = service('riwayatRegistry')->definisi($jenis);

        if ($d === null || $d->alur() !== AlurRiwayat::Usulan) {
            throw new NotFoundException('Jenis usulan tidak ditemukan.');
        }

        return $d;
    }

    private function auth(): AuthContext
    {
        return service('authContext');
    }
}
