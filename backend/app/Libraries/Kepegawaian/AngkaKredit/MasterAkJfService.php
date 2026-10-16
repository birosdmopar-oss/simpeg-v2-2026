<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\AngkaKredit;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Master Angka Kredit JF `dm_ak_jf` (MAKE-021, keputusan user 16-10-2026 Q1: CRUD ikut legacy) — role 1 saja.
 *
 * Sumber [K]: `controllers/hr/master/C_jabatan.php:1380-1530` (userAuth ['1'], menu Data Master > JABATAN "Angka Kredit
 * JF", `controllers/hr/Employee.php:852`), `libraries/hr/master/Lm_jabatan.php:2277-2512`:
 * - daftar: semua baris + jabatan, group/sub group, jenjang JF, pangkat; urut sub group, jenjang, `pangkat.order`;
 * - tambah: pilih jabatan (group 2/3 → sub group → jenjang JF → jabatan aktif) + pangkat aktif non-CPNS (`cpns = 2`) + AK;
 *   pasangan jabatan + pangkat unik (PK) → 422 dengan pesan legacy;
 * - ubah: hanya AK (jabatan & pangkat terkunci, form legacy :128-135);
 * - `updated_by` = id pengguna (sp_akjf :2501-2512; `jenjang` tidak ditulis form);
 * - TANPA hapus [K]: hapus legacy (`delete_akjf()` :2421-2452) berisi salinan kode hapus unit kerja yang tidak berfungsi.
 * Validasi [V2] di server (legacy hanya di form): jabatan ada, aktif, group 2/3; pangkat ada, aktif, `cpns = 2`; AK angka
 * ≥ 0, maks 3 desimal (inputmask form :166-172), maks 9.999.999,999. Audit `audit_logs` entity `dm_ak_jf` (legacy mylog).
 *
 * Engine MasterData tidak dipakai: PK komposit (`id_jabatan`, `id_pangkat`), tanpa `status`/`order`.
 */
final class MasterAkJfService
{
    /**
     * Kelompok jabatan yang boleh punya AK JF (form legacy :2300).
     *
     * @var list<int>
     */
    public const GROUP = [2, 3];

    private const AK_MAKS = 9999999.999;

    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function daftar(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->dasar()
            ->orderBy('sgj.id_sub_group_jabatan', 'ASC')->orderBy('jjf.id_jenjang_jf', 'ASC')->orderBy('pang.order', 'ASC')
            ->orderBy('jab.jabatan', 'ASC')
            ->get()->getResultArray();

        return array_map($this->bentuk(...), $rows);
    }

    /**
     * Pilihan form tambah (form_akjf :2299-2302): group jabatan 2/3 aktif, sub group aktif, jenjang JF, pangkat aktif
     * non-CPNS.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function opsi(): array
    {
        $db = $this->koneksi();

        return [
            'group_jabatan' => $db->table('group_jabatan')->select('id_group_jabatan, group_jabatan')->where('status', 1)
                ->whereIn('id_group_jabatan', self::GROUP)->orderBy('order', 'ASC')->get()->getResultArray(),
            'sub_group_jabatan' => $db->table('sub_group_jabatan')->select('id_sub_group_jabatan, id_group_jabatan, sub_group_jabatan')
                ->where('status', 1)->whereIn('id_group_jabatan', self::GROUP)->orderBy('id_sub_group_jabatan', 'ASC')->get()->getResultArray(),
            'jenjang_jf' => $db->table('jenjang_jf')->select('id_jenjang_jf, kategori_jf, jenjang_jf')->orderBy('order', 'ASC')->get()->getResultArray(),
            'pangkat'    => $db->table('pangkat')->select('id_pangkat, pangkat, gol_ruang')->where('status', 1)->where('cpns', 2)
                ->orderBy('order', 'ASC')->get()->getResultArray(),
        ];
    }

    /**
     * Jabatan aktif untuk pilihan form (legacy form :2318: group + sub group + jenjang JF).
     *
     * @param array<string, mixed> $filter
     *
     * @return list<array<string, mixed>>
     */
    public function jabatan(array $filter): array
    {
        $id = [];

        foreach (['id_group_jabatan', 'id_sub_group_jabatan', 'id_jenjang_jf'] as $kunci) {
            $nilai = $filter[$kunci] ?? '';

            if (! is_string($nilai) || preg_match('/^[1-9][0-9]{0,9}$/', $nilai) !== 1) {
                throw ValidationException::forField($kunci, 'Pilihan tidak valid.');
            }

            $id[$kunci] = (int) $nilai;
        }

        if (! in_array($id['id_group_jabatan'], self::GROUP, true)) {
            throw ValidationException::forField('id_group_jabatan', 'Group jabatan harus Jabatan Fungsional.');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->koneksi()->table('jabatan')->select('id_jabatan, jabatan')->where('status', 1)
            ->where('id_group_jabatan', $id['id_group_jabatan'])->where('id_sub_group_jabatan', $id['id_sub_group_jabatan'])
            ->where('id_jenjang_jf', $id['id_jenjang_jf'])->orderBy('jabatan', 'ASC')->get()->getResultArray();

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function tambah(AuthContext $auth, array $data): array
    {
        $errors    = [];
        $idJabatan = $this->idWajib($data, 'id_jabatan', 'Silahkan pilih jabatan.', $errors);
        $idPangkat = $this->idWajib($data, 'id_pangkat', 'Silahkan pilih gol. ruang.', $errors);
        $ak        = $this->ak($data, $errors);

        if ($idJabatan !== null && ! $this->jabatanSah($idJabatan)) {
            $errors['id_jabatan'] = ['Jabatan tidak ditemukan atau tidak aktif (hanya Jabatan Fungsional).'];
        }

        if ($idPangkat !== null && ! $this->pangkatSah($idPangkat)) {
            $errors['id_pangkat'] = ['Gol. ruang tidak ditemukan atau tidak aktif.'];
        }

        if ($errors === [] && $idJabatan !== null && $idPangkat !== null && ($ada = $this->baris($idJabatan, $idPangkat)) !== null) {
            $errors['id_pangkat'] = [$this->pesanKembar($ada)];
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        $baris = ['id_jabatan' => $idJabatan, 'id_pangkat' => $idPangkat, 'ak' => $ak, 'updated_by' => $auth->idPengguna()];
        $db    = $this->koneksi();
        $db->transException(true)->transStart();

        try {
            $db->table('dm_ak_jf')->insert($baris);
            (new AuditLogModel($db))->record('dm_ak_jf', "{$idJabatan}-{$idPangkat}", AuditLogModel::EVENT_CREATE, null, $baris, $auth->idPengguna(), $auth->nip());
            $db->transComplete();
        } catch (DatabaseException $e) {
            $db->transRollback();

            // Tambah paralel pasangan yang sama (PK) → 422, bukan 500.
            if (($ada = $this->baris((int) $idJabatan, (int) $idPangkat)) !== null) {
                throw ValidationException::forField('id_pangkat', $this->pesanKembar($ada));
            }

            throw $e;
        }

        return $this->detail((int) $idJabatan, (int) $idPangkat);
    }

    /**
     * Ubah AK satu pasangan (form ubah legacy hanya mengirim AK; jabatan & pangkat terkunci).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function ubah(AuthContext $auth, int $idJabatan, int $idPangkat, array $data): array
    {
        $lama = $this->baris($idJabatan, $idPangkat) ?? throw new NotFoundException('Data angka kredit JF tidak ditemukan.');

        $errors = [];
        $ak     = $this->ak($data, $errors);

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        $baru = ['ak' => $ak, 'updated_by' => $auth->idPengguna()];
        $db   = $this->koneksi();
        $db->transException(true)->transStart();
        $db->table('dm_ak_jf')->where('id_jabatan', $idJabatan)->where('id_pangkat', $idPangkat)->update($baru);
        (new AuditLogModel($db))->record('dm_ak_jf', "{$idJabatan}-{$idPangkat}", AuditLogModel::EVENT_UPDATE, ['ak' => (float) $lama['ak']], $baru, $auth->idPengguna(), $auth->nip());
        $db->transComplete();

        return $this->detail($idJabatan, $idPangkat);
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(int $idJabatan, int $idPangkat): array
    {
        $row = $this->dasar()->where('akjf.id_jabatan', $idJabatan)->where('akjf.id_pangkat', $idPangkat)->get()->getRowArray();

        if ($row === null) {
            throw new NotFoundException('Data angka kredit JF tidak ditemukan.');
        }

        return $this->bentuk($row);
    }

    private function dasar(): \CodeIgniter\Database\BaseBuilder
    {
        return $this->koneksi()->table('dm_ak_jf akjf')
            ->select('akjf.id_jabatan, akjf.id_pangkat, akjf.ak, akjf.updated_at, jab.jabatan, gj.id_group_jabatan, gj.group_jabatan, '
                . 'sgj.id_sub_group_jabatan, sgj.sub_group_jabatan, jjf.id_jenjang_jf, jjf.kategori_jf, jjf.jenjang_jf, '
                . 'pang.gol_ruang, pang.pangkat')
            ->join('pangkat pang', 'pang.id_pangkat = akjf.id_pangkat')
            ->join('jabatan jab', 'jab.id_jabatan = akjf.id_jabatan')
            ->join('jenjang_jf jjf', 'jjf.id_jenjang_jf = jab.id_jenjang_jf', 'left')
            ->join('group_jabatan gj', 'gj.id_group_jabatan = jab.id_group_jabatan', 'left')
            ->join('sub_group_jabatan sgj', 'sgj.id_sub_group_jabatan = jab.id_sub_group_jabatan', 'left');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function bentuk(array $row): array
    {
        foreach (['id_jabatan', 'id_pangkat', 'id_group_jabatan', 'id_sub_group_jabatan', 'id_jenjang_jf'] as $k) {
            $row[$k] = $row[$k] === null ? null : (int) $row[$k];
        }

        $row['ak'] = (float) $row['ak'];

        return $row;
    }

    /**
     * @param array<string, mixed>        $data
     * @param array<string, list<string>> $errors
     */
    private function idWajib(array $data, string $kunci, string $pesanKosong, array &$errors): ?int
    {
        $nilai = $data[$kunci] ?? '';

        if (is_int($nilai) && $nilai > 0) {
            return $nilai;
        }

        if (! is_string($nilai) || trim($nilai) === '') {
            $errors[$kunci] = [is_string($nilai) ? $pesanKosong : 'Pilihan tidak valid.'];

            return null;
        }

        if (preg_match('/^[1-9][0-9]{0,9}$/', trim($nilai)) !== 1) {
            $errors[$kunci] = ['Pilihan tidak valid.'];

            return null;
        }

        return (int) trim($nilai);
    }

    /**
     * AK angka ≥ 0, maks 3 desimal (inputmask form legacy), titik desimal.
     *
     * @param array<string, mixed>        $data
     * @param array<string, list<string>> $errors
     */
    private function ak(array $data, array &$errors): ?float
    {
        $nilai = $data['ak'] ?? '';
        $teks  = is_int($nilai) || is_float($nilai) ? (string) $nilai : (is_string($nilai) ? trim($nilai) : null);

        if ($teks === null || $teks === '') {
            $errors['ak'] = [$teks === null ? 'Angka kredit harus berupa angka.' : 'Angka kredit tidak boleh kosong.'];

            return null;
        }

        if (preg_match('/^[0-9]{1,7}(\.[0-9]{1,3})?$/', $teks) !== 1 || (float) $teks > self::AK_MAKS) {
            $errors['ak'] = ['Angka kredit harus berupa angka ≥ 0 dengan paling banyak 3 angka desimal.'];

            return null;
        }

        return (float) $teks;
    }

    private function jabatanSah(int $id): bool
    {
        return $this->koneksi()->table('jabatan')->where('id_jabatan', $id)->where('status', 1)
            ->whereIn('id_group_jabatan', self::GROUP)->countAllResults() > 0;
    }

    private function pangkatSah(int $id): bool
    {
        return $this->koneksi()->table('pangkat')->where('id_pangkat', $id)->where('status', 1)->where('cpns', 2)->countAllResults() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function baris(int $idJabatan, int $idPangkat): ?array
    {
        return $this->koneksi()->table('dm_ak_jf akjf')->select('akjf.ak, jab.jabatan, pang.gol_ruang')
            ->join('jabatan jab', 'jab.id_jabatan = akjf.id_jabatan', 'left')
            ->join('pangkat pang', 'pang.id_pangkat = akjf.id_pangkat', 'left')
            ->where('akjf.id_jabatan', $idJabatan)->where('akjf.id_pangkat', $idPangkat)->get()->getRowArray();
    }

    /**
     * Pesan pasangan kembar legacy (vp_akjf :2486).
     *
     * @param array<string, mixed> $ada
     */
    private function pesanKembar(array $ada): string
    {
        return 'Data angka kredit JF untuk jabatan ' . $ada['jabatan'] . ' dan gol. ' . $ada['gol_ruang']
            . ' sudah terdaftar di sistem. Silahkan gunakan jabatan dan gol. ruang yang berbeda.';
    }

    private function koneksi(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
