<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Pmk;

use App\Constants\Role;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\ValidasiBerkas;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\ListQuery;
use App\Models\Kepegawaian\DocumentAttachmentModel;
use App\Models\Layanan\LayananModel;
use App\Models\Layanan\RiwayatPmkModel;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Riwayat PMK final (PRD Fase 4 §5.4, §6.9.1; `riwayat_pmk` hanya dari impor/SIASN [K D16, P-2]): `GET pmk?nip=` (role 2
 * diri sendiri, 3 lingkup, 1 semua), `PUT pmk/{id}` role 1 = tambah arsip SK (`document_attachment` kode 42, wajib,
 * 2 MB — perbaikan P-7), `DELETE pmk/{id}` role 1 fisik + audit + arsipnya, `DELETE pmk/{id}/arsip/{id_attachment}` role 1
 * dengan minimal tersisa 1 arsip [K `Pmk.php:178-183`].
 */
class RiwayatPmkService extends DasarPmkService
{
    /** Kode `jenis_rwy` arsip SK PMK (seed Fase 3, `pmk`). */
    public const KODE_ARSIP = 42;

    protected function tabel(): string
    {
        return 'riwayat_pmk';
    }

    protected function pk(): string
    {
        return 'id_riwayat_pmk';
    }

    protected function model(): LayananModel
    {
        return new RiwayatPmkModel($this->koneksi());
    }

    protected function bolehLihat(AuthContext $auth, array $row): bool
    {
        return match (true) {
            $auth->role() === Role::SUPER_ADMIN                                                             => true,
            $this->pemilik($auth, $row)                                                                     => true,
            $auth->role() === Role::ADMIN_SATKER && $this->scope()->bolehLihat($auth, (string) $row['nip']) => true,
            default                                                                                         => false,
        };
    }

    /**
     * @param array<string, mixed> $filter `nip`, `page`, `per_page`
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, can_edit: bool}
     */
    public function daftar(AuthContext $auth, array $filter): array
    {
        $query   = ListQuery::from($filter, ['nip', 'page', 'per_page']);
        $page    = $query->page();
        $perPage = $query->perPage(PmkPengajuanService::PER_PAGE_DEFAULT, PmkPengajuanService::PER_PAGE_MAX);
        $t       = $this->tabel();
        $builder = $this->koneksi()->table($t);

        if ($auth->role() === Role::PEGAWAI) {
            $builder->where("{$t}.nip", (string) $auth->nip() !== '' ? (string) $auth->nip() : '__tanpa_nip__');
        } else {
            if ($auth->role() === Role::ADMIN_SATKER) {
                $this->scope()->terapkanKeQuery($builder, $auth, "{$t}.nip");
            }

            if ($query->filled('nip')) {
                $builder->where("{$t}.nip", $query->string('nip'));
            }
        }

        $total = (clone $builder)->countAllResults();
        $rows  = $builder->select("{$t}.*")->orderBy("{$t}.tgl_sk", 'DESC')->orderBy("{$t}.id_riwayat_pmk", 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'items'    => array_map(fn (array $r): array => $r + ['arsip' => $this->lampiran()->daftar((string) $r['nip'], self::KODE_ARSIP, (int) $r['id_riwayat_pmk'])], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'can_edit' => $auth->role() === Role::SUPER_ADMIN,
        ];
    }

    /**
     * @return list<array<string, mixed>> arsip sesudah penambahan
     */
    public function tambahArsip(AuthContext $auth, int $id, ?UploadedFile $berkas): array
    {
        $row = $this->barisTerlihat($auth, $id);
        $this->hanyaRole1($auth);

        $aturan = $this->aturanArsip();

        if ($berkas === null) {
            throw ValidationException::forField('arsip', 'Arsip SK wajib diunggah.');
        }

        ValidasiBerkas::periksa($berkas, $aturan, 'arsip');
        $nip = (string) $row['nip'];

        $this->transaksi(function () use ($nip, $id, $berkas, $aturan): void {
            $this->kunciPegawai($nip);
            $this->lampiran()->simpan($nip, self::KODE_ARSIP, $id, $berkas, $aturan);
        });

        return $this->lampiran()->daftar($nip, self::KODE_ARSIP, $id);
    }

    /**
     * Unduh satu arsip SK riwayat PMK final (gap PRD §5.4, [V2]; legacy `views/hr/employee/rwy/pmk/list.php:70-77`
     * menautkan arsip ke semua yang boleh melihat riwayat). Hak = hak lihat riwayat (role 1, 2 pemilik, 3 lingkup);
     * baris tak terlihat atau arsip yang bukan milik baris itu (kode 42, id_entri) → 404. Path internal tidak keluar.
     *
     * @return array{lampiran: array<string, mixed>, isi: string}
     */
    public function unduhArsip(AuthContext $auth, int $id, int $idAttachment): array
    {
        $row = $this->barisTerlihat($auth, $id);
        $da  = model(DocumentAttachmentModel::class)->find($idAttachment);

        if (! is_array($da) || (int) $da['id_riwayat'] !== self::KODE_ARSIP || (string) $da['id_entri'] !== (string) $id) {
            throw new NotFoundException('Arsip tidak ditemukan.');
        }

        return $this->lampiran()->ambil((string) $row['nip'], $idAttachment);
    }

    public function hapus(AuthContext $auth, int $id): void
    {
        $row = $this->barisTerlihat($auth, $id);
        $this->hanyaRole1($auth);
        $nip = (string) $row['nip'];

        $this->transaksi(function () use ($auth, $nip, $id): void {
            $this->kunciPegawai($nip);
            $this->hapusLampiran($nip, self::KODE_ARSIP, $id);

            $model = $this->model();
            $model->withActor($auth, static fn () => $model->delete($id));
        });
    }

    public function hapusArsip(AuthContext $auth, int $id, int $idAttachment): void
    {
        $row = $this->barisTerlihat($auth, $id);
        $this->hanyaRole1($auth);
        $nip = (string) $row['nip'];

        $this->transaksi(function () use ($nip, $id, $idAttachment): void {
            $this->kunciPegawai($nip);
            $da = model(DocumentAttachmentModel::class)->find($idAttachment);

            if (! is_array($da) || (int) $da['id_riwayat'] !== self::KODE_ARSIP || (string) $da['id_entri'] !== (string) $id) {
                throw new NotFoundException('Arsip tidak ditemukan.');
            }

            if (count($this->lampiran()->daftar($nip, self::KODE_ARSIP, $id)) <= 1) {
                throw ValidationException::forField('arsip', 'Minimal satu arsip SK harus tersisa.');
            }

            $this->lampiran()->hapus($nip, $idAttachment);
        });
    }

    private function aturanArsip(): AturanLampiran
    {
        return new AturanLampiran(self::KODE_ARSIP, true, PmkPengajuanService::BATAS_MB, PmkPengajuanService::EKSTENSI);
    }

    private function hanyaRole1(AuthContext $auth): void
    {
        if ($auth->role() !== Role::SUPER_ADMIN) {
            $this->terlarang();
        }
    }
}
