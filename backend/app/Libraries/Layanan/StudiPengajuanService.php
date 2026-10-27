<?php

declare(strict_types=1);

namespace App\Libraries\Layanan;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\ValidasiBerkas;
use App\Libraries\Layanan\Studi\BerkasStudi;
use App\Libraries\Layanan\Studi\StatusStudi;
use App\Libraries\Layanan\Studi\ValidasiStudi;
use App\Libraries\ListQuery;
use App\Models\Kepegawaian\DocumentAttachmentModel;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * CRUD pengajuan TB/IB (PRD Fase 4 §5.2–§5.3, §6.7.2, §6.8.1): daftar `mode=saya|pic|admin`, opsi form, detail, tambah,
 * ubah, hapus fisik + audit [K], unduh lampiran. Proses PIC ada di `*ApprovalService`.
 *
 * Isian: field DDL snake_case (§7.10); nilai turunan (`lama_tahun`, `jenjang_pendidikan_singkat`, snapshot pemohon)
 * dihitung server dan isian klien untuk kolom itu diabaikan (Q-03). Berkas per slot (`BerkasStudi::SLOT`) dikirim dengan
 * nama field = nama slot (`file_sp`, `file_1`, …).
 */
abstract class StudiPengajuanService extends LayananStudi
{
    public const PER_PAGE_DEFAULT = 20;

    public const PER_PAGE_MAX = 100;

    public const MODE = ['saya', 'pic', 'admin'];

    /** Kolom isian klien yang disimpan apa adanya (teks dipangkas; kosong → NULL untuk kolom opsional). */
    private const ISIAN_WAJIB = ['institusi_pendidikan', 'jurusan_pendidikan', 'lokasi_pendidikan', 'lembaga_yang_mendanai', 'up'];

    private const ISIAN_OPSIONAL = ['tembusan', 'keterangan'];

    /**
     * Status awal dari isian pemanggil: role 1 boleh mengirim `status` (TB 0|1; IB selalu 0 → 422 bila lain); selain
     * role 1 dipaksa 0 (isian diabaikan).
     */
    abstract protected function statusAwal(AuthContext $auth, array $data): ?int;

    /** Role 1 dibebaskan dari berkas wajib (TB [K `L_tb.php:907, 942`]); IB wajib semua role [K `L_ib.php:705`]. */
    abstract protected function role1BebasBerkas(): bool;

    /**
     * Kolom SK yang disimpan saat input/ubah role 1 (TB-09) — kosong untuk IB.
     *
     * @return list<string>
     */
    protected function kolomSkAdmin(): array
    {
        return [];
    }

    /**
     * Efek final setuju (TB: `id_jenis_status`; IB: surat) — dipanggil di dalam transaksi tepat setelah baris → 1.
     *
     * @param array<string, mixed> $row baris sesudah update
     */
    abstract protected function efekFinalSetuju(AuthContext $auth, array $row): void;

    /**
     * Pembersihan sebelum hapus fisik (anak, berkas, efek balik) — di dalam transaksi.
     *
     * @param array<string, mixed> $row
     */
    protected function sebelumHapus(AuthContext $auth, array $row): void
    {
        $this->hapusLampiran((string) $row['nip'], BerkasStudi::kodeLampiran($this->jenis()), (int) $row[$this->pk()]);
        $this->hapusLampiran((string) $row['nip'], BerkasStudi::kodeSurat($this->jenis()), (int) $row[$this->pk()]);
    }

    /**
     * Tambahan detail per jenis (TB: perpanjangan, `status_penuh`).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    protected function ekstraDetail(AuthContext $auth, array $row): array
    {
        return [];
    }

    // ------------------------------------------------------------------------------------------------------------

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

        if (! in_array($mode, self::MODE, true)) {
            throw ValidationException::forField('mode', 'Mode daftar tidak valid.');
        }

        $db      = $this->koneksi();
        $t       = $this->tabel();
        $builder = $db->table($t);

        match ($mode) {
            'saya'  => $builder->where("{$t}.nip", (string) $auth->nip() !== '' ? (string) $auth->nip() : '__tanpa_nip__'),
            'pic'   => $isPic ? $builder->where("{$t}.status", StatusStudi::PENGAJUAN) : throw new ForbiddenException("Anda bukan PIC {$this->namaKecil()} aktif."),
            default => $admin ? ($auth->role() === Role::ADMIN_SATKER ? $this->scope()->terapkanKeQuery($builder, $auth, "{$t}.nip") : null) : throw new ForbiddenException('Mode admin hanya untuk Super Admin dan Admin Satker.'),
        };

        if ($query->filled('status')) {
            $status = $query->string('status');

            if (! ctype_digit($status) || ! StatusStudi::valid((int) $status)) {
                throw ValidationException::forField('status', 'Status tidak valid.');
            }

            $builder->where("{$t}.status", (int) $status);
        }

        if ($query->filled('search')) {
            $s = ListQuery::likeLiteral($db, $query->string('search'));
            $builder->groupStart()->like("{$t}.nama", $s)->orLike("{$t}.nip", $s)->orLike("{$t}.institusi_pendidikan", $s)->groupEnd();
        }

        $total = (clone $builder)->countAllResults();
        $rows  = $builder->select("{$t}.*")->orderBy("{$t}.created_at", 'DESC')->orderBy("{$t}." . $this->pk(), 'DESC')
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
     * Opsi form: jenjang (`status = 1`, id > batas), `up` bawaan, snapshot pemohon (`nip` hanya role 1/3), `can_create`.
     *
     * @return array<string, mixed>
     */
    public function opsi(AuthContext $auth, ?string $nip): array
    {
        $aturan = $this->aturan();
        $target = $this->nipTarget($auth, $nip === null || trim($nip) === '' ? null : trim($nip), false);

        $jenjang = $this->koneksi()->table('jenjang_pendidikan')
            ->select('id_jenjang_pendidikan, jenjang_pendidikan, jenjang_pendidikan_singkat')
            ->where('status', 1)->where('id_jenjang_pendidikan >', $aturan->jenjangMinEksklusif)
            ->orderBy('order')->orderBy('id_jenjang_pendidikan')->get()->getResultArray();

        return [
            'jenjang_pendidikan' => $jenjang,
            'up'                 => $aturan->upBawaan,
            'pegawai'            => $target !== null ? ['nip' => $target] + $this->snapshotPegawai($target) : null,
            'slot_berkas'        => BerkasStudi::slot($this->jenis()),
            'berkas_wajib'       => ! ($this->role1BebasBerkas() && $auth->role() === Role::SUPER_ADMIN),
            'can_create'         => $this->bolehMengajukan($auth),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(AuthContext $auth, int $id): array
    {
        $row = $this->barisTerlihat($auth, $id);

        return $this->tampil($auth, $row) + [
            'lampiran' => array_values($this->lampiranSlot((string) $row['nip'], $id)),
            'surat'    => $this->lampiran()->daftar((string) $row['nip'], BerkasStudi::kodeSurat($this->jenis()), $id),
        ] + $this->ekstraDetail($auth, $row);
    }

    /**
     * @param array<string, mixed>        $data
     * @param array<string, UploadedFile> $berkas slot => berkas
     *
     * @return array<string, mixed> detail baris baru
     */
    public function tambah(AuthContext $auth, array $data, array $berkas): array
    {
        $nip = $this->nipTarget($auth, isset($data['nip']) && is_scalar($data['nip']) ? trim((string) $data['nip']) : null, true);

        if ($nip === null) {
            throw $this->terlarangPengaju();
        }

        $kirim = $this->statusAwal($auth, $data);
        $param = $this->validasi($auth, $data, $kirim, $kirim ?? StatusStudi::PENGAJUAN, $berkas, [], []);

        $id = $this->transaksi(function () use ($auth, $nip, $param, $berkas): int {
            $this->kunciPegawai($nip);
            $this->periksaBentrok($nip, (string) $param['tgl_mulai'], (string) $param['tgl_akhir'], null);

            $param += ['nip' => $nip, 'updated_by' => $auth->idPengguna()] + $this->snapshotPegawai($nip);

            if ((int) $param['status'] === StatusStudi::DISETUJUI) {
                $param += ['approved_by' => $auth->idPengguna(), 'show_pl' => 1, 'reason_note' => null];
            }

            $model = $this->model();
            $id    = (int) $model->withActor($auth, static fn () => $model->insert($param, true));
            $this->simpanSlot($nip, $id, $berkas);

            $row = $this->kunciBaris($nip, $id);

            if ((int) $row['status'] === StatusStudi::DISETUJUI) {
                $this->efekFinalSetuju($auth, $row);
                $this->notifikasiKeputusan($auth, $row);
            } else {
                $this->notifikasiPic($auth, $row, true);
            }

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
        $awal   = $this->barisTerlihat($auth, $id);
        $pelaku = $this->pelakuTulis($auth, $awal) ?? $this->terlarang();
        $this->tolakBila($this->galatUbah($pelaku, $awal));

        $nip = (string) $awal['nip'];

        $this->transaksi(function () use ($auth, $id, $nip, $data, $berkas): void {
            $row    = $this->kunciBaris($nip, $id);
            $pelaku = $this->pelakuTulis($auth, $row) ?? $this->terlarang();
            $this->tolakBila($this->galatUbah($pelaku, $row));

            $statusLama = (int) $row['status'];
            $status     = $this->statusUbah($auth, $data, $statusLama);
            $kirim      = $statusLama === StatusStudi::PENGAJUAN ? $this->statusAwal($auth, $data) : null;
            $param      = $this->validasi($auth, $data, $kirim, $status, $berkas, array_keys($this->lampiranSlot($nip, $id)), $row);
            $this->periksaBentrok($nip, (string) $param['tgl_mulai'], (string) $param['tgl_akhir'], $id);

            $param['updated_by'] = $auth->idPengguna();
            $param['updated_at'] = $this->sekarang();
            $keFinal             = $statusLama === StatusStudi::PENGAJUAN && (int) $param['status'] === StatusStudi::DISETUJUI;

            if ($keFinal) {
                // Pengganti trigger `riwayat_*_beUpd` (V3): status lama 0/3 → 1/2/3.
                $param += ['approved_by' => $auth->idPengguna(), 'show_pl' => 1, 'reason_note' => null, 'show_notif' => 1, 'notif_date' => $this->sekarang()];
            }

            $model = $this->model();
            $model->withActor($auth, static fn () => $model->update($id, $param));
            $this->simpanSlot($nip, $id, $berkas);

            $baru = $this->kunciBaris($nip, $id);

            if ($keFinal) {
                $this->efekFinalSetuju($auth, $baru);
                $this->notifikasiKeputusan($auth, $baru);
            } elseif ((int) $baru['status'] === StatusStudi::PENGAJUAN) {
                $this->notifikasiPic($auth, $baru, false);
            }
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

            $this->sebelumHapus($auth, $row);

            $model = $this->model();
            $model->withActor($auth, static fn () => $model->delete($id));
        });
    }

    /**
     * Unduh lampiran milik baris (kode lampiran/surat jenis ini, `id_entri` = id) — selain itu 404 (§7.4).
     *
     * @return array{lampiran: array<string, mixed>, isi: string}
     */
    public function unduhLampiran(AuthContext $auth, int $id, int $idAttachment): array
    {
        $row = $this->barisTerlihat($auth, $id);
        $da  = model(DocumentAttachmentModel::class)->find($idAttachment);

        if (! is_array($da) || ! $this->lampiranMilikBaris($da, $row)) {
            throw new NotFoundException('Lampiran tidak ditemukan.');
        }

        return $this->lampiran()->ambil((string) $row['nip'], $idAttachment);
    }

    /**
     * @param array<string, mixed> $da  baris document_attachment
     * @param array<string, mixed> $row baris layanan
     */
    protected function lampiranMilikBaris(array $da, array $row): bool
    {
        return (string) $da['id_entri'] === (string) $row[$this->pk()]
            && in_array((int) $da['id_riwayat'], [BerkasStudi::kodeLampiran($this->jenis()), BerkasStudi::kodeSurat($this->jenis())], true);
    }

    // ------------------------------------------------------------------------------------------------------------

    public function bolehMengajukan(AuthContext $auth): bool
    {
        return in_array($auth->role(), [Role::SUPER_ADMIN, Role::ADMIN_SATKER, ...$this->rolePengaju()], true);
    }

    /**
     * NIP sasaran pengajuan/opsi: role 1 & 3 memakai `nip` isian (3: dalam lingkup ubah, `$wajib` → 422 bila kosong);
     * role pengaju = NIP sendiri (isian `nip` diabaikan). Role lain → null.
     */
    protected function nipTarget(AuthContext $auth, ?string $nip, bool $wajib): ?string
    {
        $role = $auth->role();

        if ($role === Role::SUPER_ADMIN || $role === Role::ADMIN_SATKER) {
            if ($nip === null || $nip === '') {
                if ($wajib) {
                    throw ValidationException::forField('nip', 'Pegawai wajib dipilih.');
                }

                return null;
            }

            $pegawai = $this->pegawai($nip);

            if ($role === Role::ADMIN_SATKER && ($pegawai === null || ! $this->scope()->bolehUbah($auth, $nip))) {
                throw new ForbiddenException('Pegawai di luar lingkup Anda.');
            }

            if ($pegawai === null) {
                throw ValidationException::forField('nip', 'Pegawai tidak ditemukan.');
            }

            $this->periksaPegawaiAdmin($auth, $pegawai);

            return $nip;
        }

        if (in_array($role, $this->rolePengaju(), true)) {
            $sendiri = (string) $auth->nip();

            return $sendiri !== '' ? $sendiri : null;
        }

        return null;
    }

    /**
     * Syarat pegawai untuk input admin (TB role 1: PNS, §5.2). Bawaan tanpa syarat.
     *
     * @param array<string, mixed> $pegawai
     */
    protected function periksaPegawaiAdmin(AuthContext $auth, array $pegawai): void
    {
    }

    /**
     * Status saat ubah: role 1 (TB) boleh 0 → 1 pada baris status 0; baris final tetap; selain role 1 tetap 0.
     *
     * @param array<string, mixed> $data
     */
    protected function statusUbah(AuthContext $auth, array $data, int $statusLama): int
    {
        if ($statusLama !== StatusStudi::PENGAJUAN) {
            $kirim = $this->statusAwal($auth, $data);

            if ($kirim !== null && $kirim !== $statusLama) {
                throw ValidationException::forField('status', "Status {$this->namaKecil()} yang sudah final tidak dapat diubah.");
            }

            return $statusLama;
        }

        return $this->statusAwal($auth, $data) ?? StatusStudi::PENGAJUAN;
    }

    /**
     * Validasi isian + master jenjang + berkas (semua galat dikumpulkan, TB-03) → parameter kolom.
     *
     * @param array<string, mixed>        $data
     * @param int|null                    $kirim        status yang dikirim role 1 (null = tidak dikirim / bukan role 1);
     *                                                  ValidasiStudi memeriksa domain dan data SK bila 1
     * @param int                         $status       status yang disimpan
     * @param array<string, UploadedFile> $berkas
     * @param list<string>                $tagTersimpan
     * @param array<string, mixed>        $baris        baris lama (ubah) atau []
     *
     * @return array<string, mixed>
     */
    protected function validasi(AuthContext $auth, array $data, ?int $kirim, int $status, array $berkas, array $tagTersimpan, array $baris): array
    {
        $aturan = $this->aturan();
        $hasil  = ValidasiStudi::periksa($this->jenis(), $data, $kirim, $aturan);
        $errors = $hasil['errors'];

        $jenjang = null;

        if (! isset($errors['id_jenjang_pendidikan'])) {
            $jenjang = $this->koneksi()->table('jenjang_pendidikan')->select('id_jenjang_pendidikan, jenjang_pendidikan_singkat')
                ->where('id_jenjang_pendidikan', (int) $data['id_jenjang_pendidikan'])->where('status', 1)->get()->getRowArray();

            if (! is_array($jenjang)) {
                $errors['id_jenjang_pendidikan'] = "Jenjang pendidikan {$this->namaKecil()} tidak valid.";
            }
        }

        $slot = BerkasStudi::slot($this->jenis());

        foreach ($berkas as $nama => $file) {
            if (! isset($slot[$nama])) {
                continue;
            }

            try {
                ValidasiBerkas::periksa($file, $this->aturanBerkas(BerkasStudi::kodeLampiran($this->jenis())), $nama);
            } catch (ValidationException $e) {
                $errors += array_map(static fn (array|string $m): string => is_array($m) ? (string) reset($m) : $m, $e->getErrors() ?? []);
            }
        }

        if (! ($this->role1BebasBerkas() && $auth->role() === Role::SUPER_ADMIN)) {
            $errors += BerkasStudi::slotKosong($this->jenis(), array_keys(array_intersect_key($berkas, $slot)), $tagTersimpan, $baris);
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', array_map(static fn (string $m): array => [$m], $errors));
        }

        $param = [
            'id_jenjang_pendidikan'      => (int) $data['id_jenjang_pendidikan'],
            'jenjang_pendidikan_singkat' => (string) ($jenjang['jenjang_pendidikan_singkat'] ?? ''),
            'tgl_mulai'                  => trim((string) $data['tgl_mulai']),
            'tgl_akhir'                  => trim((string) $data['tgl_akhir']),
            'lama_tahun'                 => (string) $hasil['lama_tahun'],
            // `dalam_negeri` tidak divalidasi [K]; kolom NOT NULL → 1 (Dalam Negeri) bila kosong/di luar 1|2.
            'dalam_negeri' => in_array((string) ($data['dalam_negeri'] ?? ''), ['1', '2'], true) ? (int) $data['dalam_negeri'] : 1,
            'status'       => $status,
        ];

        foreach (self::ISIAN_WAJIB as $kolom) {
            $param[$kolom] = trim((string) $data[$kolom]);
        }

        foreach (self::ISIAN_OPSIONAL as $kolom) {
            $nilai         = isset($data[$kolom]) && is_scalar($data[$kolom]) ? trim((string) $data[$kolom]) : '';
            $param[$kolom] = $nilai === '' ? null : $nilai;
        }

        if ($auth->role() === Role::SUPER_ADMIN) {
            foreach ($this->kolomSkAdmin() as $kolom) {
                if (array_key_exists($kolom, $data) && (is_scalar($data[$kolom]) || $data[$kolom] === null)) {
                    $nilai         = trim((string) $data[$kolom]);
                    $param[$kolom] = $nilai === '' ? null : $nilai;
                }
            }
        }

        return $param;
    }

    protected function periksaBentrok(string $nip, string $mulai, string $akhir, ?int $abaikan): void
    {
        $lain = $this->koneksi()->table($this->tabel())
            ->select($this->pk() . ' AS id, tgl_mulai, tgl_akhir, status')
            ->where('nip', $nip)->whereIn('status', StatusStudi::TUMPANG_TINDIH)->get()->getResultArray();

        if (ValidasiStudi::bentrok($mulai, $akhir, $lain, $abaikan) !== null) {
            throw ValidationException::forField('tgl_mulai', ValidasiStudi::pesanBentrok($this->jenis()));
        }
    }

    private function terlarangPengaju(): ForbiddenException
    {
        return new ForbiddenException("Anda tidak dapat mengajukan {$this->namaKecil()}.");
    }
}
