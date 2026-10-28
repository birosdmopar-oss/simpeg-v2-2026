<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Libraries\ListQuery;
use App\Models\Layanan\PaLayananModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Petugas approval cuti (`pa_layanan`, PRD Fase 4 §6.4.8, legacy `C/Cuti.php:1009-1171`, `L:3958-4516`). Role route 1 dan 3:
 * role 1 semua baris; role 3 hanya `id_unit_pemohon` = unit pemanggil (daftar, ubah, hapus) dan wajib mengisi unit itu
 * saat tambah (422 `errors.id_unit_pemohon`; pembatasan dropdown legacy, bukan penjaga keamanan D19). Whitelist kolom
 * (bukan mass-assignment). Ubah hanya baris status 1 (status dipaksa 1). Hapus fisik + audit.
 */
class PetugasApprovalCutiService
{
    public const ID_JENIS_PA_CUTI = 1;

    private const GOL_GJ = [2, 3];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function daftar(AuthContext $auth, array $query): array
    {
        $this->wajibRole($auth);

        $q      = ListQuery::from($query, ['page', 'per_page', 'search']);
        $bangun = fn (): \CodeIgniter\Database\BaseBuilder => $this->db->table('pa_layanan pa')
            ->select('pa.*, jp.jenis_pa, jpg.jenis_pegawai, u.unit AS unit_pemohon, gj.group_jabatan AS group_jabatan_pemohon, sgj.sub_group_jabatan AS sub_group_jabatan_pemohon, '
                . 'up.unit AS unit_petugas, sp.satker AS satker_petugas, gjp.group_jabatan AS group_jabatan_petugas, sgjp.sub_group_jabatan AS sub_group_jabatan_petugas, '
                . 'jb.jabatan AS jabatan_struktural, jk.jabatan AS jabatan_koordinasi')
            ->join('jenis_pa jp', 'jp.id_jenis_pa = pa.id_jenis_pa', 'left')
            ->join('jenis_pegawai jpg', 'jpg.id_jenis_pegawai = pa.id_jenis_pegawai', 'left')
            ->join('unit u', 'u.id_unit = pa.id_unit_pemohon', 'left')
            ->join('group_jabatan gj', 'gj.id_group_jabatan = pa.id_group_jabatan_pemohon', 'left')
            ->join('sub_group_jabatan sgj', 'sgj.id_sub_group_jabatan = pa.id_sub_group_jabatan_pemohon', 'left')
            ->join('unit up', 'up.id_unit = pa.id_unit_petugas', 'left')
            ->join('satker sp', 'sp.id_satker = pa.id_satker_petugas', 'left')
            ->join('group_jabatan gjp', 'gjp.id_group_jabatan = pa.id_group_jabatan_petugas', 'left')
            ->join('sub_group_jabatan sgjp', 'sgjp.id_sub_group_jabatan = pa.id_sub_group_jabatan_petugas', 'left')
            ->join('jabatan jb', 'jb.id_jabatan = pa.id_jabatan', 'left')
            ->join('jabatan_koordinasi jk', 'jk.id_jabatan_koordinasi = pa.id_jabatan_koord', 'left')
            ->where('pa.status', 1);

        $unit = $this->unitPemanggil($auth);

        $hitung = $this->db->table('pa_layanan pa')->where('pa.status', 1);

        if ((int) $auth->role() === Role::ADMIN_SATKER) {
            $unit === null ? $hitung->where('1 = 0', null, false) : $hitung->where('pa.id_unit_pemohon', $unit);
        }

        $total   = $hitung->countAllResults();
        $page    = $q->page();
        $perPage = $q->perPage(25, 100);
        $b       = $bangun();

        if ((int) $auth->role() === Role::ADMIN_SATKER) {
            $unit === null ? $b->where('1 = 0', null, false) : $b->where('pa.id_unit_pemohon', $unit);
        }

        $baris = $b->orderBy('pa.id_pa_layanan', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'items'    => array_map(fn (array $r): array => $r + ['petugas' => $this->petugas($r)], $baris),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Opsi form petugas approval cuti yang TIDAK tersedia di master options: jabatan koordinasi/sub koordinasi (`koord_<id>`),
     * jenis pemohon yang sah (PNS/PPPK), group jabatan yang memakai golongan/ruang, jenis layanan tetap, dan unit terkunci
     * untuk Admin Satker. Daftar lain memakai `GET master/{entitas}/options`: `unit`, `satker`, `group-jabatan`,
     * `sub-group-jabatan` (parent group), `pangkat` (gol/ruang), `jabatan` (`struk_<id>`); pegawai lain (`other_<nip>`) lewat
     * `GET pegawai?search=`.
     *
     * @return array<string, mixed>
     */
    public function opsi(AuthContext $auth): array
    {
        $this->wajibRole($auth);

        $jenisPegawai = $this->db->table('jenis_pegawai')->select('id_jenis_pegawai, jenis_pegawai')
            ->whereIn('id_jenis_pegawai', [AturanJenisCuti::ID_PNS, AturanJenisCuti::ID_PPPK])->orderBy('id_jenis_pegawai')->get()->getResultArray();

        $koordinasi = $this->db->table('jabatan_koordinasi')->select('id_jabatan_koordinasi, jabatan, jenis')
            ->where('status', 1)->orderBy('jabatan')->get()->getResultArray();

        $unitTerkunci = null;
        $unit         = $this->unitPemanggil($auth);

        if ((int) $auth->role() === Role::ADMIN_SATKER && $unit !== null) {
            $baris        = $this->db->table('unit')->select('id_unit, unit')->where('id_unit', $unit)->get()->getRowArray();
            $unitTerkunci = is_array($baris) ? ['id_unit' => (int) $baris['id_unit'], 'unit' => (string) $baris['unit']] : null;
        }

        return [
            'id_jenis_pa'             => self::ID_JENIS_PA_CUTI,
            'jenis_pegawai'           => array_map(static fn (array $r): array => ['id_jenis_pegawai' => (int) $r['id_jenis_pegawai'], 'jenis_pegawai' => (string) $r['jenis_pegawai']], $jenisPegawai),
            'group_jabatan_pakai_gol' => self::GOL_GJ,
            'jabatan_koordinasi'      => array_map(static fn (array $r): array => ['id_jabatan_koordinasi' => (int) $r['id_jabatan_koordinasi'], 'jabatan' => (string) $r['jabatan'], 'jenis' => (int) $r['jenis']], $koordinasi),
            'unit_terkunci'           => $unitTerkunci,
        ];
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array<string, mixed>
     */
    public function tambah(AuthContext $auth, array $in): array
    {
        $this->wajibRole($auth);

        $data = $this->validasi($auth, $in, true);
        $id   = (new PaLayananModel())->insert($data + ['status' => 1, 'updated_by' => $auth->idPengguna()]);

        return $this->ambilItem((int) $id);
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array<string, mixed>
     */
    public function ubah(AuthContext $auth, int $id, array $in): array
    {
        $this->wajibRole($auth);

        $baris = $this->bolehKelola($auth, $id);

        if ((int) $baris['status'] !== 1) {
            throw ValidationException::forField('status', 'Hanya aturan petugas berstatus aktif yang dapat diubah.');
        }

        $data = $this->validasi($auth, $in, false);

        (new PaLayananModel())->update($id, $data + ['status' => 1, 'updated_by' => $auth->idPengguna()]);

        return $this->ambilItem($id);
    }

    /**
     * @return array{deleted: bool, id: int}
     */
    public function hapus(AuthContext $auth, int $id): array
    {
        $this->wajibRole($auth);
        $this->bolehKelola($auth, $id);

        (new PaLayananModel())->delete($id);

        return ['deleted' => true, 'id' => $id];
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $in
     *
     * @return array<string, mixed>
     */
    private function validasi(AuthContext $auth, array $in, bool $tambah): array
    {
        $errors = [];
        $int    = fn (string $k): ?int => (isset($in[$k]) && (is_int($in[$k]) || (is_string($in[$k]) && ctype_digit($in[$k])))) ? (int) $in[$k] : null;

        $jenisPa  = $int('id_jenis_pa');
        $jenisPeg = $int('id_jenis_pegawai');
        $unit     = $int('id_unit_pemohon');
        $gj       = $int('id_group_jabatan_pemohon');
        $sgj      = $int('id_sub_group_jabatan_pemohon');
        $gol      = isset($in['gol_ruang_pemohon']) && is_string($in['gol_ruang_pemohon']) ? trim($in['gol_ruang_pemohon']) : '';

        $this->wajibAda($errors, 'id_jenis_pa', $jenisPa, 'jenis_pa', 'id_jenis_pa', 'Silahkan pilih layanan.');
        $this->wajibAda($errors, 'id_unit_pemohon', $unit, 'unit', 'id_unit', 'Silahkan pilih unit pemohon.');
        $this->wajibAda($errors, 'id_group_jabatan_pemohon', $gj, 'group_jabatan', 'id_group_jabatan', 'Silahkan pilih group jabatan pemohon.');

        if ($jenisPeg === null || ! in_array($jenisPeg, [AturanJenisCuti::ID_PNS, AturanJenisCuti::ID_PPPK], true)) {
            $errors['id_jenis_pegawai'] = 'Silahkan pilih jenis pemohon (PNS atau PPPK).';
        }

        if ($gj !== null && in_array($gj, self::GOL_GJ, true)) {
            if ($gol === '') {
                $errors['gol_ruang_pemohon'] = 'Silahkan isi golongan/ruang pemohon.';
            }
        } else {
            $this->wajibAda($errors, 'id_sub_group_jabatan_pemohon', $sgj, 'sub_group_jabatan', 'id_sub_group_jabatan', 'Silahkan pilih sub group jabatan pemohon.');
        }

        $this->wajibAda($errors, 'id_unit_petugas', $int('id_unit_petugas'), 'unit', 'id_unit', 'Silahkan pilih unit petugas.');
        $this->wajibAda($errors, 'id_satker_petugas', $int('id_satker_petugas'), 'satker', 'id_satker', 'Silahkan pilih satker petugas.');
        $this->wajibAda($errors, 'id_group_jabatan_petugas', $int('id_group_jabatan_petugas'), 'group_jabatan', 'id_group_jabatan', 'Silahkan pilih group jabatan petugas.');
        $this->wajibAda($errors, 'id_sub_group_jabatan_petugas', $int('id_sub_group_jabatan_petugas'), 'sub_group_jabatan', 'id_sub_group_jabatan', 'Silahkan pilih sub group jabatan petugas.');

        $petugas = $this->urai(isset($in['petugas']) && is_string($in['petugas']) ? $in['petugas'] : '', $errors);

        if ((int) $auth->role() === Role::ADMIN_SATKER) {
            $unitSendiri = $this->unitPemanggil($auth);

            if ($unit === null || $unitSendiri === null || $unit !== $unitSendiri) {
                $errors['id_unit_pemohon'] = 'Unit pemohon harus unit Anda.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', array_map(static fn (string $p): array => [$p], $errors));
        }

        $golDipakai = $gj !== null && in_array($gj, self::GOL_GJ, true);

        return [
            'id_jenis_pa'                  => $jenisPa,
            'id_jenis_pegawai'             => $jenisPeg,
            'id_unit_pemohon'              => $unit,
            'id_group_jabatan_pemohon'     => $gj,
            'id_sub_group_jabatan_pemohon' => $golDipakai ? null : $sgj,
            'gol_ruang_pemohon'            => $golDipakai ? $gol : null,
            'id_unit_petugas'              => $int('id_unit_petugas'),
            'id_satker_petugas'            => $int('id_satker_petugas'),
            'id_group_jabatan_petugas'     => $int('id_group_jabatan_petugas'),
            'id_sub_group_jabatan_petugas' => $int('id_sub_group_jabatan_petugas'),
            'id_jabatan'                   => $petugas['id_jabatan'],
            'id_jabatan_koord'             => $petugas['id_jabatan_koord'],
            'nip'                          => $petugas['nip'],
        ];
    }

    /**
     * `struk_<id_jabatan>` | `koord_<id_jabatan_koord>` | `other_<nip>`.
     *
     * @param array<string, string> $errors
     *
     * @return array{id_jabatan: int|null, id_jabatan_koord: int|null, nip: string|null}
     */
    private function urai(string $nilai, array &$errors): array
    {
        $kosong = ['id_jabatan' => null, 'id_jabatan_koord' => null, 'nip' => null];

        if (preg_match('/^(struk|koord|other)_(.+)$/', trim($nilai), $m) !== 1) {
            $errors['petugas'] = 'Silahkan pilih petugas.';

            return $kosong;
        }

        if ($m[1] === 'other') {
            if ($this->db->table('pegawai')->where('nip', $m[2])->countAllResults() === 0) {
                $errors['petugas'] = 'Pegawai petugas tidak ditemukan.';

                return $kosong;
            }

            return ['nip' => $m[2]] + $kosong;
        }

        if (! ctype_digit($m[2])) {
            $errors['petugas'] = 'Petugas tidak valid.';

            return $kosong;
        }

        $tabel = $m[1] === 'struk' ? ['jabatan', 'id_jabatan'] : ['jabatan_koordinasi', 'id_jabatan_koordinasi'];

        if ($this->db->table($tabel[0])->where($tabel[1], (int) $m[2])->countAllResults() === 0) {
            $errors['petugas'] = 'Jabatan petugas tidak ditemukan.';

            return $kosong;
        }

        return $m[1] === 'struk' ? ['id_jabatan' => (int) $m[2]] + $kosong : ['id_jabatan_koord' => (int) $m[2]] + $kosong;
    }

    /**
     * @param array<string, string> $errors
     */
    private function wajibAda(array &$errors, string $field, ?int $id, string $tabel, string $pk, string $pesan): void
    {
        if ($id === null || $id === 0) {
            $errors[$field] = $pesan;
        } elseif ($this->db->table($tabel)->where($pk, $id)->countAllResults() === 0) {
            $errors[$field] = 'Data tidak ditemukan.';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function bolehKelola(AuthContext $auth, int $id): array
    {
        $baris = $this->db->table('pa_layanan')->where('id_pa_layanan', $id)->where('id_jenis_pa', self::ID_JENIS_PA_CUTI)->get()->getRowArray() ?? throw new NotFoundException();

        if ((int) $auth->role() === Role::ADMIN_SATKER) {
            $unit = $this->unitPemanggil($auth);

            if ($unit === null || (int) $baris['id_unit_pemohon'] !== $unit) {
                throw new ForbiddenException('Aturan petugas ini bukan milik unit Anda.');
            }
        }

        return $baris;
    }

    /**
     * @return array<string, mixed>
     */
    private function ambilItem(int $id): array
    {
        $baris = $this->db->table('pa_layanan')->where('id_pa_layanan', $id)->get()->getRowArray() ?? throw new NotFoundException();

        return $baris + ['petugas' => $this->petugas($baris)];
    }

    /**
     * Pemegang jabatan saat ini (Plt bila kosong) atau pegawai tertaut (`L:4000-4053`).
     *
     * @param array<string, mixed> $r
     *
     * @return array{nip: string|null, nama: string|null, jabatan: string|null}
     */
    private function petugas(array $r): array
    {
        $nip = null;

        if (($r['nip'] ?? null) !== null && $r['nip'] !== '') {
            $nip = (string) $r['nip'];
        } elseif (($r['id_jabatan_koord'] ?? null) !== null) {
            $nip = $this->pemegang('pmj.id_jabatan_koord', (int) $r['id_jabatan_koord'], 'plt.id_jabatan_koord');
        } elseif (($r['id_jabatan'] ?? null) !== null) {
            $nip = $this->pemegang('pmj.id_jabatan', (int) $r['id_jabatan'], 'plt.id_jabatan');
        }

        $jabatan = (string) ($r['jabatan_koordinasi'] ?? $r['jabatan_struktural'] ?? '');

        if ($nip === null) {
            return ['nip' => null, 'nama' => null, 'jabatan' => $jabatan === '' ? null : $jabatan];
        }

        $p = $this->db->table('pegawai')->select('glr_awal, nama, glr_akhir')->where('nip', $nip)->get()->getRowArray();

        return [
            'nip'     => $nip,
            'nama'    => $p === null ? null : StrukturService::namaGelar($p['glr_awal'], (string) $p['nama'], $p['glr_akhir']),
            'jabatan' => $jabatan === '' ? null : $jabatan,
        ];
    }

    private function pemegang(string $kolomPmj, int $id, string $kolomPlt): ?string
    {
        $p = $this->db->table('pegawai_mutasi_jabatan pmj')->select('p.nip')->join('pegawai p', 'p.nip = pmj.nip', 'inner')
            ->where($kolomPmj, $id)->where('p.status', 1)->orderBy('pmj.tmtsk', 'DESC')->limit(1)->get()->getRowArray();

        if ($p !== null) {
            return (string) $p['nip'];
        }

        $plt = $this->db->table('pegawai_plt plt')->select('plt.nip_plt')->where($kolomPlt, $id)->where('plt.status', 1)->orderBy('plt.id_pegawai_plt', 'ASC')->limit(1)->get()->getRowArray();

        return $plt === null ? null : (string) $plt['nip_plt'];
    }

    private function unitPemanggil(AuthContext $auth): ?int
    {
        $unit = $auth->idUnit();

        return $unit !== null && ctype_digit($unit) ? (int) $unit : null;
    }

    private function wajibRole(AuthContext $auth): void
    {
        if (! in_array((int) $auth->role(), [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true)) {
            throw new ForbiddenException();
        }
    }
}
