<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Pmk;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\ValidasiBerkas;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Layanan\Studi\RentangTanggal;
use App\Libraries\ListQuery;
use CodeIgniter\HTTP\Files\UploadedFile;
use InvalidArgumentException;

/**
 * Pengajuan PMK C-09 (PRD Fase 4 §5.4, §6.9.1): daftar `mode=saya|pic|admin`, opsi (`tmt_cpns`, `tgl_awal_maks`),
 * kalkulator `hitung-mker`, detail, tambah (role 2 PNS diri sendiri; role 1 `nip` PNS; role 3 → 403), ubah, hapus fisik +
 * audit + berkas. Isian `jenis_pmk`, `instansi`, `tgl_awal`, `tgl_akhir`, `mker_th`/`mker_bl` **dari klien** [K D19/P-4];
 * empat berkas kolom [K] (`file_kontrak`, `file_paklaring`, `file_surat_phk`, `file_slip_gaji`) wajib saat tambah, saat ubah
 * wajib bila kolom kosong; 2 MB, `jpg jpeg png gif pdf` [K P-6]; disimpan lewat KolomBerkas (pola transBegin §7.4).
 * Batas TMT CPNS dari `pegawai_cpns.tmtsk` [K `Local.php:4537-4603`]; tanpa baris CPNS → cek dilewati.
 */
class PmkPengajuanService extends DasarPmkService
{
    public const PER_PAGE_DEFAULT = 20;

    public const PER_PAGE_MAX = 100;

    /** Kolom berkas => label [K form legacy]. */
    public const BERKAS = [
        'file_kontrak'   => 'Surat kontrak kerja',
        'file_paklaring' => 'Surat paklaring',
        'file_surat_phk' => 'Surat PHK',
        'file_slip_gaji' => 'Slip gaji',
    ];

    public const BATAS_MB = 2;

    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

    /**
     * @param array<string, mixed> $filter
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, mode: string, can_create: bool, can_process: bool}
     */
    public function daftar(AuthContext $auth, array $filter): array
    {
        $query   = ListQuery::from($filter, ['mode', 'status', 'search', 'page', 'per_page']);
        $page    = $query->page();
        $perPage = $query->perPage(self::PER_PAGE_DEFAULT, self::PER_PAGE_MAX);
        $admin   = in_array($auth->role(), [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true);
        $mode    = $query->filled('mode') ? $query->string('mode') : ($admin ? 'admin' : 'saya');
        $isPic   = $this->adalahPic($auth);

        if (! in_array($mode, ['saya', 'pic', 'admin'], true)) {
            throw ValidationException::forField('mode', 'Mode daftar tidak valid.');
        }

        $db      = $this->koneksi();
        $t       = $this->tabel();
        $builder = $db->table($t)->join('pegawai', "pegawai.nip = {$t}.nip", 'left');

        match ($mode) {
            'saya' => $builder->where("{$t}.nip", (string) $auth->nip() !== '' ? (string) $auth->nip() : '__tanpa_nip__'),
            // Antrean PIC = status 0 dan 3, tanpa batas unit [K P-19].
            'pic'   => $isPic ? $builder->whereIn("{$t}.status", [StatusPmk::PENGAJUAN, StatusPmk::PROSES]) : throw new ForbiddenException('Anda bukan PIC peninjauan masa kerja aktif.'),
            default => $admin ? ($auth->role() === Role::ADMIN_SATKER ? $this->scope()->terapkanKeQuery($builder, $auth, "{$t}.nip") : null) : throw new ForbiddenException('Mode admin hanya untuk Super Admin dan Admin Satker.'),
        };

        if ($query->filled('status')) {
            $status = $query->string('status');

            if (! ctype_digit($status) || ! StatusPmk::valid((int) $status)) {
                throw ValidationException::forField('status', 'Status tidak valid.');
            }

            $builder->where("{$t}.status", (int) $status);
        }

        if ($query->filled('search')) {
            $s = ListQuery::likeLiteral($db, $query->string('search'));
            $builder->groupStart()->like('pegawai.nama', $s)->orLike("{$t}.nip", $s)->orLike("{$t}.instansi", $s)->groupEnd();
        }

        $total = (clone $builder)->countAllResults();
        $rows  = $builder->select("{$t}.*, pegawai.nama")->orderBy("{$t}.created_at", 'DESC')->orderBy("{$t}.id_riwayat_pmk_req", 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'items'       => array_map(fn (array $r): array => $this->tampil($auth, $r), $rows),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'mode'        => $mode,
            'can_create'  => $this->bolehMengajukan($auth),
            'can_process' => $isPic,
        ];
    }

    /**
     * @return array{jenis_pmk: list<string>, pegawai: array<string, mixed>|null, tmt_cpns: ?string, tgl_awal_maks: ?string, berkas: array<string, string>, can_create: bool, adalah_pic: bool}
     */
    public function opsi(AuthContext $auth, ?string $nip): array
    {
        $target  = $this->nipPengaju($auth, $nip === null || trim($nip) === '' ? null : trim($nip), false);
        $tmt     = $target !== null ? $this->tmtCpns($target) : null;
        $pegawai = null;

        if ($target !== null) {
            $p       = $this->koneksi()->table('pegawai')->select('nip, glr_awal, nama, glr_akhir')->where('nip', $target)->get()->getRowArray();
            $pegawai = is_array($p) ? ['nip' => $target, 'nama' => \App\Libraries\Kepegawaian\Struktur\StrukturService::namaGelar($p['glr_awal'], (string) $p['nama'], $p['glr_akhir'])] : null;
        }

        return [
            'jenis_pmk'     => $this->aturanPmk()->jenisPmk,
            'pegawai'       => $pegawai,
            'tmt_cpns'      => $tmt,
            'tgl_awal_maks' => $tmt !== null ? RentangTanggal::tambahHari($tmt, -1) : null,
            'berkas'        => self::BERKAS,
            'can_create'    => $this->bolehMengajukan($auth),
            // Penanda tampilan (FE menyembunyikan tab Perlu Persetujuan); hak proses tetap ditegakkan service.
            'adalah_pic' => $this->adalahPic($auth),
        ];
    }

    /**
     * Kalkulator klien (`POST pmk/hitung-mker`) [K `Local.php:4574-4601`]: `mker_th` = diff.y, `mker_bl` = diff.m.
     *
     * @param array<string, mixed> $data
     *
     * @return array{mker_th: int, mker_bl: int, peringatan: ?string}
     */
    public function hitungMker(array $data): array
    {
        $awal   = isset($data['tgl_awal']) && is_scalar($data['tgl_awal']) ? trim((string) $data['tgl_awal']) : '';
        $akhir  = isset($data['tgl_akhir']) && is_scalar($data['tgl_akhir']) ? trim((string) $data['tgl_akhir']) : '';
        $errors = [];

        foreach (['tgl_awal' => $awal, 'tgl_akhir' => $akhir] as $field => $nilai) {
            if (! \App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis::valid($nilai)) {
                $errors[$field] = ['Tanggal tidak valid.'];
            }
        }

        if ($errors === [] && $akhir < $awal) {
            $errors['tgl_akhir'] = [ValidasiPmk::PESAN_URUTAN];
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        $mker = MasaKerjaPmk::hitung($awal, $akhir);

        return $mker + ['peringatan' => MasaKerjaPmk::kurangSebulan($mker['mker_th'], $mker['mker_bl']) ? MasaKerjaPmk::PESAN_KURANG_SEBULAN : null];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(AuthContext $auth, int $id): array
    {
        $row = $this->barisTerlihat($auth, $id);

        return $this->tampil($auth, $row + ['nama' => $this->namaPemohon($row)]);
    }

    /**
     * @param array<string, mixed>        $data
     * @param array<string, UploadedFile> $berkas kolom => berkas
     *
     * @return array<string, mixed>
     */
    public function tambah(AuthContext $auth, array $data, array $berkas): array
    {
        $nip = $this->nipPengaju($auth, isset($data['nip']) && is_scalar($data['nip']) ? trim((string) $data['nip']) : null, true);

        if ($nip === null) {
            throw new ForbiddenException('Anda tidak dapat mengajukan peninjauan masa kerja.');
        }

        $param = $this->validasi($nip, $data, $berkas, []);

        $id = $this->transaksi(function () use ($auth, $nip, $param, $berkas): int {
            $this->kunciPegawai($nip);
            $this->periksaBentrok($nip, (string) $param['tgl_awal'], (string) $param['tgl_akhir'], null);

            $param += ['nip' => $nip, 'status' => StatusPmk::PENGAJUAN, 'created_by' => $auth->idPengguna(), 'updated_by' => $auth->idPengguna()];

            foreach ($berkas as $kolom => $file) {
                $param[$kolom] = $this->kolomBerkas()->simpan($file, $this->aturanBerkas(), $this->tabel(), $kolom, $kolom);
            }

            $model = $this->model();
            $id    = (int) $model->withActor($auth, static fn () => $model->insert($param, true));
            $row   = $this->kunciBaris($nip, $id);

            $this->notifikasi($auth, $id, 1, $this->pic()->nipPicAktif($this->idJenisLayanan()), 'Permohonan Peninjauan Masa Kerja Baru', $this->ringkasPmk($row) . ' oleh ' . $this->namaPemohon($row) . ' / ' . $nip, null, StatusPmk::PENGAJUAN);

            return $id;
        });

        return $this->detail($auth, $id);
    }

    /**
     * @param array<string, mixed>        $data
     * @param array<string, UploadedFile> $berkas
     *
     * @return array<string, mixed>
     */
    public function ubah(AuthContext $auth, int $id, array $data, array $berkas): array
    {
        $awal = $this->barisTerlihat($auth, $id);
        $this->pelakuTulis($auth, $awal) ?? $this->terlarang();

        $nip = (string) $awal['nip'];

        $this->transaksi(function () use ($auth, $id, $nip, $data, $berkas): void {
            $row = $this->kunciBaris($nip, $id);
            $this->pelakuTulis($auth, $row) ?? $this->terlarang();

            $param = $this->validasi($nip, $data, $berkas, $row);
            $this->periksaBentrok($nip, (string) $param['tgl_awal'], (string) $param['tgl_akhir'], $id);

            foreach ($berkas as $kolom => $file) {
                $param[$kolom] = $this->kolomBerkas()->simpan($file, $this->aturanBerkas(), $this->tabel(), $kolom, $kolom);
                $this->kolomBerkas()->hapus($row[$kolom] ?? null, $this->tabel(), $kolom);
            }

            // Status tidak berubah [K]: edit_req/edit_req_ad mengirim ulang status lama.
            $param += ['updated_by' => $auth->idPengguna(), 'updated_at' => $this->sekarang()];
            $model = $this->model();
            $model->withActor($auth, static fn () => $model->update($id, $param));
        });

        return $this->detail($auth, $id);
    }

    public function hapus(AuthContext $auth, int $id): void
    {
        $awal   = $this->barisTerlihat($auth, $id);
        $pelaku = $this->pelakuTulis($auth, $awal) ?? $this->terlarang();
        $this->tolakBila($this->galatHapus($pelaku, $awal));

        $nip = (string) $awal['nip'];

        $this->transaksi(function () use ($auth, $id, $nip): void {
            $row    = $this->kunciBaris($nip, $id);
            $pelaku = $this->pelakuTulis($auth, $row) ?? $this->terlarang();
            $this->tolakBila($this->galatHapus($pelaku, $row));

            // Berkas ikut dibuang (perbaikan P-9).
            foreach (array_keys(self::BERKAS) as $kolom) {
                $this->kolomBerkas()->hapus($row[$kolom] ?? null, $this->tabel(), $kolom);
            }

            $model = $this->model();
            $model->withActor($auth, static fn () => $model->delete($id));
        });
    }

    /**
     * Unduh satu berkas kolom pengajuan (`GET pmk/pengajuan/{id}/berkas/{kolom}`). [V2] gap PRD §5.4 (disetujui Horii
     * 27-10-2026): legacy membuka berkas lewat tautan langsung (`views/hr/employee/rwy/pmk/req/admin/form.php:142-214`,
     * `pathToSrc()` `helpers/function_helper.php:314`). Hak = hak lihat detail (404 bila tak terlihat); `$kolom` hanya
     * empat kolom berkas D1 ({@see self::BERKAS}), selain itu 404; kolom kosong, path impor legacy yang belum ada di
     * storage v2, atau path yang ditolak storage (traversal / karakter di luar `[A-Za-z0-9._-]`) → 404. Path internal
     * tidak pernah dikirim; nama unduhan dibangkitkan server.
     *
     * @return array{isi: string, nama: string}
     */
    public function berkas(AuthContext $auth, int $id, string $kolom): array
    {
        $row = $this->barisTerlihat($auth, $id);

        if (! array_key_exists($kolom, self::BERKAS)) {
            throw new NotFoundException('Berkas tidak ditemukan.');
        }

        $path = trim((string) ($row[$kolom] ?? ''));

        try {
            if ($path === '' || ! $this->kolomBerkas()->ada($path)) {
                throw new NotFoundException('Berkas tidak ditemukan.');
            }

            $isi = $this->kolomBerkas()->baca($path);
        } catch (InvalidArgumentException) {
            throw new NotFoundException('Berkas tidak ditemukan.');
        }

        $ekstensi = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return ['isi' => $isi, 'nama' => "pmk_{$id}_{$kolom}" . ($ekstensi !== '' ? ".{$ekstensi}" : '')];
    }

    public function bolehMengajukan(AuthContext $auth): bool
    {
        return in_array($auth->role(), [Role::SUPER_ADMIN, ...self::ROLE_PENGAJU], true);
    }

    protected function aturanBerkas(int $kode = 0, array $ekstensi = self::EKSTENSI): AturanLampiran
    {
        return new AturanLampiran($kode, true, self::BATAS_MB, $ekstensi);
    }

    /**
     * NIP pengaju: role 1 `nip` isian (PNS); role 2 diri sendiri (harus PNS); role 3 → 403 (lihat saja).
     */
    private function nipPengaju(AuthContext $auth, ?string $nip, bool $wajib): ?string
    {
        if ($auth->role() === Role::ADMIN_SATKER) {
            if ($wajib) {
                throw new ForbiddenException('Admin Satker hanya dapat melihat pengajuan PMK.');
            }

            return $nip !== null && $this->scope()->bolehLihat($auth, $nip) ? $nip : null;
        }

        if ($auth->role() === Role::SUPER_ADMIN) {
            if ($nip === null || $nip === '') {
                if ($wajib) {
                    throw ValidationException::forField('nip', 'Pegawai wajib dipilih.');
                }

                return null;
            }
        } elseif (in_array($auth->role(), self::ROLE_PENGAJU, true)) {
            $nip = (string) $auth->nip() !== '' ? (string) $auth->nip() : null;
        } else {
            return null;
        }

        if ($nip === null) {
            return null;
        }

        $pegawai = $this->pegawai($nip);

        if ($pegawai === null) {
            throw ValidationException::forField('nip', 'Pegawai tidak ditemukan.');
        }

        if ($wajib && (int) ($pegawai['id_jenis_pegawai'] ?? 0) !== self::JENIS_PEGAWAI_PNS) {
            throw ValidationException::forField('nip', 'Peninjauan masa kerja hanya untuk Pegawai Negeri Sipil.');
        }

        return $nip;
    }

    private function tmtCpns(string $nip): ?string
    {
        $row = $this->koneksi()->table('pegawai_cpns')->select('tmtsk')->where('nip', $nip)->get()->getRowArray();
        $tmt = is_array($row) ? (string) ($row['tmtsk'] ?? '') : '';

        return $tmt !== '' ? substr($tmt, 0, 10) : null;
    }

    /**
     * @param array<string, mixed>        $data
     * @param array<string, UploadedFile> $berkas
     * @param array<string, mixed>        $baris baris lama (ubah) atau []
     *
     * @return array<string, mixed>
     */
    private function validasi(string $nip, array $data, array $berkas, array $baris): array
    {
        $errors = ValidasiPmk::periksa($data, $this->tmtCpns($nip), $this->aturanPmk());

        foreach (self::BERKAS as $kolom => $label) {
            if (isset($berkas[$kolom])) {
                try {
                    ValidasiBerkas::periksa($berkas[$kolom], $this->aturanBerkas(), $kolom);
                } catch (ValidationException $e) {
                    $errors += array_map(static fn (array|string $m): string => is_array($m) ? (string) reset($m) : $m, $e->getErrors() ?? []);
                }
            } elseif (trim((string) ($baris[$kolom] ?? '')) === '') {
                $errors[$kolom] = "{$label} wajib diunggah.";
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', array_map(static fn (string $m): array => [$m], $errors));
        }

        return [
            'jenis_pmk' => trim((string) $data['jenis_pmk']),
            'instansi'  => trim((string) $data['instansi']),
            'tgl_awal'  => trim((string) $data['tgl_awal']),
            'tgl_akhir' => trim((string) $data['tgl_akhir']),
            'mker_th'   => (int) $data['mker_th'],
            'mker_bl'   => (int) $data['mker_bl'],
        ];
    }

    private function periksaBentrok(string $nip, string $awal, string $akhir, ?int $abaikan): void
    {
        $lain = $this->koneksi()->table($this->tabel())->select('id_riwayat_pmk_req AS id, tgl_awal, tgl_akhir, status')
            ->where('nip', $nip)->get()->getResultArray();

        if (ValidasiPmk::bentrok($awal, $akhir, $lain, $abaikan) !== null) {
            throw ValidationException::forField('tgl_awal', ValidasiPmk::PESAN_BENTROK);
        }
    }
}
