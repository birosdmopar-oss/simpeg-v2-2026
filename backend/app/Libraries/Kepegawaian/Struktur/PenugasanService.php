<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Struktur;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\KolomBerkas;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Models\Kepegawaian\PegawaiPlhModel;
use App\Models\Kepegawaian\PegawaiPltModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use RuntimeException;
use Throwable;

/**
 * Penugasan Plt/Plh pada jabatan (B-07 bagian Plt/Plh, WS-2 M4 MAKE-012) — tabel `pegawai_plt` / `pegawai_plh`,
 * tanpa approval (WS2 §3.4.2 #8). Dibaca B-19 (pejabat simpul yang lowong).
 *
 * Sumber [K]: `controllers/hr/rwy/Jabatan.php` list/add/edit/delete_off_plt|plh (:655-1013, userAuth ['1']),
 * `libraries/hr/rwy/L_jabatan.php` list_off_plt (:4419, lingkup role 3: satker akun, atau unit bila tanpa satker),
 * insert/update/delete_off_plt (:4568-4810), validate_param_off_plt (:4811) / vp_off_plh (:5310), set_param (:4890).
 *
 * [V2] hapus = status 10 (legacy hapus keras; CHECK `IN (1, 2, 10)`), tgl_akhir >= tgl_mulai, rujukan master &
 * pegawai diperiksa (FK DBV-019 menolak id yatim), jabatan koordinasi harus milik jenis jabatan koordinasi.
 * Hak akses: lihat ROLE_LIHAT, tulis ROLE_TULIS (keputusan reviewer Q2 M4).
 */
final class PenugasanService
{
    public const JENIS = ['plt', 'plh'];

    /**
     * @var list<int>
     */
    public const ROLE_LIHAT = [Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI];

    /**
     * @var list<int>
     */
    public const ROLE_TULIS = [Role::SUPER_ADMIN];

    /**
     * @var list<string>
     */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'xls', 'xlsx', 'doc', 'docx'];

    public function __construct(private readonly ?BaseConnection $db = null, private readonly ?KolomBerkas $berkas = null)
    {
    }

    public static function aturanLampiran(): AturanLampiran
    {
        return new AturanLampiran(0, false, 5, self::EKSTENSI);
    }

    /**
     * @param array<string, mixed> $filter `search` (nama/NIP), `status`, `page`, `per_page`
     *
     * @return array{items: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int}, can_create: bool}
     */
    public function daftar(AuthContext $auth, string $jenis, array $filter = []): array
    {
        $this->periksaJenis($jenis);
        $this->wajibRole($auth, self::ROLE_LIHAT);

        $page    = max(1, (int) ($filter['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filter['per_page'] ?? 20)));
        $builder = $this->query($auth, $jenis);
        $search  = trim((string) ($filter['search'] ?? ''));

        if ($search !== '') {
            $builder->groupStart()->like('p.nama', $search)->orLike("t.nip_{$jenis}", $search)->groupEnd();
        }

        if (in_array((string) ($filter['status'] ?? ''), ['1', '2'], true)) {
            $builder->where('t.status', (int) $filter['status']);
        }

        $total = $builder->countAllResults(false);
        /** @var list<array<string, mixed>> $rows */
        $rows = $builder->orderBy("t.id_pegawai_{$jenis}", 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'items'      => array_map(fn (array $r): array => $this->bentuk($r, $jenis), $rows),
            'meta'       => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
            'can_create' => in_array($auth->role(), self::ROLE_TULIS, true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(AuthContext $auth, string $jenis, int $id): array
    {
        $this->periksaJenis($jenis);
        $this->wajibRole($auth, self::ROLE_LIHAT);

        return $this->bentuk($this->baris($auth, $jenis, $id), $jenis);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function tambah(AuthContext $auth, string $jenis, array $data, ?UploadedFile $lampiran): array
    {
        $this->periksaJenis($jenis);
        $this->wajibRole($auth, self::ROLE_TULIS);
        $param = $this->validasi($jenis, $data);

        $id = $this->tulis($auth, $jenis, null, $param, $lampiran, null);

        return $this->detail($auth, $jenis, $id);
    }

    /**
     * Ubah = kirim ulang seluruh isian (legacy form penuh).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function ubah(AuthContext $auth, string $jenis, int $id, array $data, ?UploadedFile $lampiran): array
    {
        $this->periksaJenis($jenis);
        $this->wajibRole($auth, self::ROLE_TULIS);
        $lama  = $this->baris($auth, $jenis, $id);
        $param = $this->validasi($jenis, $data);

        $this->tulis($auth, $jenis, $id, $param, $lampiran, $lama);

        return $this->detail($auth, $jenis, $id);
    }

    /**
     * Hapus lunak (status 10); lampiran dipertahankan (pola retensi lampiran riwayat status 10).
     *
     * @return array{deleted: true, id: int}
     */
    public function hapus(AuthContext $auth, string $jenis, int $id): array
    {
        $this->periksaJenis($jenis);
        $this->wajibRole($auth, self::ROLE_TULIS);
        $this->baris($auth, $jenis, $id);

        $model = $this->model($jenis);
        $model->withActor($auth, static fn () => $model->update($id, ['status' => 10, 'updated_by' => $auth->idPengguna()]));

        return ['deleted' => true, 'id' => $id];
    }

    /**
     * @return array{isi: string, nama: string}
     */
    public function unduhLampiran(AuthContext $auth, string $jenis, int $id): array
    {
        $this->periksaJenis($jenis);
        $this->wajibRole($auth, self::ROLE_LIHAT);
        $path  = (string) ($this->baris($auth, $jenis, $id)['lampiran'] ?? '');
        $kolom = $this->berkas ?? new KolomBerkas(null, $this->db());

        // Path impor legacy (arsip remote, diawali "arsip/") belum ada di storage v2 → 404.
        if ($path === '' || ! $kolom->ada($path)) {
            throw new NotFoundException('Lampiran tidak ditemukan.');
        }

        return ['isi' => $kolom->baca($path), 'nama' => "lampiran_{$jenis}_{$id}." . pathinfo($path, PATHINFO_EXTENSION)];
    }

    /**
     * Tambah ($id null) atau ubah dalam satu transaksi; lampiran lewat KolomBerkas (berkas lama dihapus sesudah commit,
     * berkas baru dibuang bila rollback).
     *
     * @param array<string, mixed>      $param
     * @param array<string, mixed>|null $lama
     */
    private function tulis(AuthContext $auth, string $jenis, ?int $id, array $param, ?UploadedFile $lampiran, ?array $lama): int
    {
        $db    = $this->db();
        $kolom = $this->berkas ?? new KolomBerkas(null, $db);
        $model = $this->model($jenis);
        $db->transBegin();

        try {
            if ($lampiran !== null) {
                $param['lampiran'] = $kolom->simpan($lampiran, self::aturanLampiran(), "pegawai_{$jenis}", 'lampiran', 'lampiran');
                $kolom->hapus($lama['lampiran'] ?? null, "pegawai_{$jenis}", 'lampiran');
            }

            $param['updated_by'] = $auth->idPengguna();
            $id                  = $model->withActor($auth, static function () use ($model, $id, $param): int {
                if ($id === null) {
                    return (int) $model->insert($param);
                }

                $model->update($id, $param);

                return $id;
            });

            if ($db->transStatus() === false) {
                throw new RuntimeException('Transaksi penugasan gagal.');
            }

            $db->transCommit();

            return $id;
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        } finally {
            $db->resetTransStatus();
            $kolom->selesaikan();
        }
    }

    /**
     * Legacy validate_param_off_plt + set_param_off_plt.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function validasi(string $jenis, array $data): array
    {
        $v      = static fn (string $k): string => trim(is_scalar($data[$k] ?? null) ? (string) $data[$k] : '');
        $errors = [];
        $wajib  = static function (string $kolom, string $pesan) use (&$errors, $v): void {
            if ($v($kolom) === '') {
                $errors[$kolom] = [$pesan];
            }
        };

        if (! in_array($v('status'), ['1', '2'], true)) {
            $errors['status'] = ['Silakan pilih status.'];
        }

        $jenisJabatan = $v('jenis_jabatan');

        if (! in_array($jenisJabatan, ['1', '2'], true)) {
            $errors['jenis_jabatan'] = ['Silakan pilih jenis jabatan.'];
        } elseif ($jenisJabatan === '1') {
            $wajib('id_group_jabatan', 'Silakan pilih group jabatan.');
            $wajib('id_sub_group_jabatan', 'Silakan pilih sub group jabatan.');
            $wajib('id_jabatan', 'Silakan pilih jabatan.');
        } else {
            if (! in_array($v('jenis_jabatan_koord'), ['1', '2'], true)) {
                $errors['jenis_jabatan_koord'] = ['Silakan pilih jenis jabatan koordinasi.'];
            }

            $wajib('id_jabatan_koord', 'Silakan pilih jabatan koordinasi.');
        }

        foreach (['tgl_mulai' => 'Tgl. mulai', 'tgl_akhir' => 'Tgl. akhir'] as $kolom => $label) {
            if ($v($kolom) === '') {
                $errors[$kolom] = ["{$label} tidak boleh kosong."];
            } elseif (! self::tanggalValid($v($kolom))) {
                $errors[$kolom] = ["{$label} tidak valid."];
            }
        }

        if (! isset($errors['tgl_mulai']) && ! isset($errors['tgl_akhir']) && $v('tgl_akhir') < $v('tgl_mulai')) {
            $errors['tgl_akhir'] = ['Tgl. akhir tidak boleh sebelum tgl. mulai.'];
        }

        $label = strtoupper($jenis);
        $wajib('id_unit', 'Silakan pilih unit kerja.');
        $wajib('id_satker', 'Silakan pilih satuan kerja.');
        $wajib("id_group_jabatan_{$jenis}", "Silakan pilih group jabatan {$label}.");
        $wajib("id_sub_group_jabatan_{$jenis}", "Silakan pilih sub group jabatan {$label}.");
        $wajib("id_unit_{$jenis}", "Silakan pilih unit kerja {$label}.");
        $wajib("id_satker_{$jenis}", "Silakan pilih satuan kerja {$label}.");
        $wajib("nip_{$jenis}", "Silakan pilih pegawai {$label}.");

        if (mb_strlen($v('keterangan')) > 255) {
            $errors['keterangan'] = ['Keterangan maksimal 255 karakter.'];
        }

        $koord = $jenisJabatan === '2';
        $rujuk = [
            'id_unit'                       => ['unit', 'id_unit'],
            'id_satker'                     => ['satker', 'id_satker'],
            "id_group_jabatan_{$jenis}"     => ['group_jabatan', 'id_group_jabatan'],
            "id_sub_group_jabatan_{$jenis}" => ['sub_group_jabatan', 'id_sub_group_jabatan'],
            "id_unit_{$jenis}"              => ['unit', 'id_unit'],
            "id_satker_{$jenis}"            => ['satker', 'id_satker'],
        ] + ($koord
            ? ['id_jabatan_koord' => ['jabatan_koordinasi', 'id_jabatan_koordinasi']]
            : ['id_group_jabatan' => ['group_jabatan', 'id_group_jabatan'], 'id_sub_group_jabatan' => ['sub_group_jabatan', 'id_sub_group_jabatan'], 'id_jabatan' => ['jabatan', 'id_jabatan']]);

        foreach ($rujuk as $kolom => [$tabel, $pk]) {
            if (isset($errors[$kolom]) || $v($kolom) === '') {
                continue;
            }

            if (preg_match('/^[1-9][0-9]{0,9}$/', $v($kolom)) !== 1 || $this->db()->table($tabel)->where($pk, $v($kolom))->countAllResults() === 0) {
                $errors[$kolom] = ['Pilihan tidak ditemukan.'];
            }
        }

        if (! isset($errors["nip_{$jenis}"]) && $this->db()->table('pegawai')->where('nip', $v("nip_{$jenis}"))->countAllResults() === 0) {
            $errors["nip_{$jenis}"] = ['Pegawai tidak ditemukan.'];
        }

        if ($koord && ! isset($errors['id_jabatan_koord']) && ! isset($errors['jenis_jabatan_koord'])) {
            $jk = $this->db()->table('jabatan_koordinasi')->select('jenis')->where('id_jabatan_koordinasi', $v('id_jabatan_koord'))->get()->getRowArray();

            if ((string) ($jk['jenis'] ?? '') !== $v('jenis_jabatan_koord')) {
                $errors['id_jabatan_koord'] = ['Jabatan koordinasi tidak sesuai jenis koordinasi yang dipilih.'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        $int = static fn (string $k): ?int => $v($k) === '' ? null : (int) $v($k);

        return [
            'status'                        => (int) $v('status'),
            'jenis_jabatan'                 => (int) $jenisJabatan,
            'jenis_jabatan_koord'           => $koord ? (int) $v('jenis_jabatan_koord') : null,
            'tgl_mulai'                     => $v('tgl_mulai'),
            'tgl_akhir'                     => $v('tgl_akhir'),
            'id_group_jabatan'              => $koord ? null : $int('id_group_jabatan'),
            'id_sub_group_jabatan'          => $koord ? null : $int('id_sub_group_jabatan'),
            'id_jabatan'                    => $koord ? null : $int('id_jabatan'),
            'id_jabatan_koord'              => $koord ? $int('id_jabatan_koord') : null,
            'id_unit'                       => $int('id_unit'),
            'id_satker'                     => $int('id_satker'),
            "id_group_jabatan_{$jenis}"     => $int("id_group_jabatan_{$jenis}"),
            "id_sub_group_jabatan_{$jenis}" => $int("id_sub_group_jabatan_{$jenis}"),
            "id_unit_{$jenis}"              => $int("id_unit_{$jenis}"),
            "id_satker_{$jenis}"            => $int("id_satker_{$jenis}"),
            "nip_{$jenis}"                  => $v("nip_{$jenis}"),
            'keterangan'                    => $v('keterangan') === '' ? null : $v('keterangan'),
        ];
    }

    /**
     * Daftar + nama rujukan, status ≠ 10, lingkup role 3 (legacy list_off_plt: satker akun, atau unit bila tanpa satker).
     */
    private function query(AuthContext $auth, string $jenis): BaseBuilder
    {
        $builder = $this->db()->table("pegawai_{$jenis} t")
            ->select('t.*, p.glr_awal, p.nama, p.glr_akhir, jab.jabatan, jk.jabatan AS jabatan_koord, u.unit, s.satker')
            ->join('pegawai p', "p.nip = t.nip_{$jenis}", 'left')
            ->join('jabatan jab', 'jab.id_jabatan = t.id_jabatan', 'left')
            ->join('jabatan_koordinasi jk', 'jk.id_jabatan_koordinasi = t.id_jabatan_koord', 'left')
            ->join('unit u', 'u.id_unit = t.id_unit', 'left')
            ->join('satker s', 's.id_satker = t.id_satker', 'left')
            ->where('t.status !=', 10);

        if ($auth->role() === Role::ADMIN_SATKER) {
            $satker = (string) ($auth->idSatker() ?? '');
            $unit   = (string) ($auth->idUnit() ?? '');

            if ($satker !== '') {
                $builder->where('t.id_satker', (int) $satker);
            } elseif ($unit !== '') {
                $builder->where('t.id_unit', (int) $unit);
            } else {
                $builder->where('1 = 0', null, false);
            }
        }

        return $builder;
    }

    /**
     * @return array<string, mixed>
     */
    private function baris(AuthContext $auth, string $jenis, int $id): array
    {
        $row = $this->query($auth, $jenis)->where("t.id_pegawai_{$jenis}", $id)->get()->getRowArray();

        if ($row === null) {
            throw new NotFoundException('Data penugasan tidak ditemukan.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function bentuk(array $r, string $jenis): array
    {
        $r['id']           = (int) $r["id_pegawai_{$jenis}"];
        $r['nip']          = $r["nip_{$jenis}"];
        $r['nama_lengkap'] = $r['nama'] === null ? null : StrukturService::namaGelar($r['glr_awal'], (string) $r['nama'], $r['glr_akhir']);
        $r['ada_lampiran'] = (string) ($r['lampiran'] ?? '') !== '';
        unset($r['lampiran']);

        return $r;
    }

    private function model(string $jenis): PegawaiPltModel|PegawaiPlhModel
    {
        return $jenis === 'plt' ? new PegawaiPltModel($this->db()) : new PegawaiPlhModel($this->db());
    }

    private function periksaJenis(string $jenis): void
    {
        if (! in_array($jenis, self::JENIS, true)) {
            throw new NotFoundException('Jenis penugasan tidak dikenal.');
        }
    }

    /**
     * @param list<int> $role
     */
    private function wajibRole(AuthContext $auth, array $role): void
    {
        if (! in_array($auth->role(), $role, true)) {
            throw new ForbiddenException('Anda tidak berhak mengakses data penugasan ini.');
        }
    }

    private static function tanggalValid(string $nilai): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $nilai);

        return $d !== false && $d->format('Y-m-d') === $nilai;
    }

    private function db(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
