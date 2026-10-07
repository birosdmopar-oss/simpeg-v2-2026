<?php

declare(strict_types=1);

namespace Tests\Support\Kepegawaian;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Auth\AuthService;
use RuntimeException;
use Tests\Support\Database\Seeds\AuthSeeder;

/**
 * Fixture pegawai bersama Fase 3 (S0-A MAKE-002), dipakai WS-1 dan WS-2 di test turunan Tests\Support\DatabaseTestCase
 * (base case MAKE-001; `$this->db`, koneksi group tests).
 *
 * Membuat, berurutan: baris master G-02 yang dirujuk (unit → satker → group/sub group jabatan → jabatan) → `pegawai`
 * → snapshot `pegawai_mutasi_jabatan` ber-unit/satker/jabatan (ID + kolom teks snapshot) → akun `pengguna` tertaut
 * NIP. Setiap kolom FK diisi ID master yang dibuat fixture ini (patuh FK DBV-019 snapshot/riwayat → G-02 dan
 * `pengguna.nip` → `pegawai`, terpasang atau belum); kolom FK lain dibiarkan NULL kecuali diberikan pemanggil.
 *
 * Berjalan di bingkai transaksi uji MAKE-001 tanpa tanda `db-isolasi-penuh`: HANYA INSERT/SELECT lewat query builder —
 * tanpa DDL, tanpa migrate, tanpa commit eksplisit, tanpa TRUNCATE/DELETE. NIP sintetis 18 digit (bukan data asli),
 * unik terhadap `pegawai` dan `pengguna` yang sudah ada.
 *
 * Kepemilikan setelah S0: berkas bersama — WS mana pun boleh MENAMBAH helper (aditif, satu helper per commit, dicatat
 * di commit milestone-nya); mengubah perilaku helper yang ada butuh persetujuan reviewer CR.
 */
trait PegawaiFixtureTrait
{
    private static int $fixtureUrutan = 0;

    private static ?string $fixtureHashPassword = null;

    /**
     * NIP sintetis 18 digit: lahir 1990-01-01, TMT CPNS 2015-01, jenis kelamin 1, nomor urut 3 digit.
     */
    protected function nipSintetis(): string
    {
        for ($i = 0; $i < 1000; $i++) {
            $nip = '19900101' . '201501' . '1' . sprintf('%03d', self::$fixtureUrutan++ % 1000);

            $dipakai = $this->db->table('pegawai')->where('nip', $nip)->countAllResults() > 0
                || $this->db->table('pengguna')->groupStart()->where('nip', $nip)->orWhere('username', $nip)->groupEnd()->countAllResults() > 0;

            if (! $dipakai) {
                return $nip;
            }
        }

        throw new RuntimeException('NIP sintetis fixture habis (1000 nomor urut terpakai).');
    }

    /**
     * Unit kerja G-02 baru; mengembalikan id_unit.
     *
     * @param array<string, mixed> $override kolom tabel `unit`
     */
    protected function buatUnit(array $override = []): int
    {
        return $this->fixtureInsert('unit', array_merge([
            'unit'   => 'Unit Fixture ' . $this->fixtureNomor(),
            'status' => 1,
        ], $override));
    }

    /**
     * Satker G-02 baru di bawah $idUnit (null → unit baru).
     *
     * @param array<string, mixed> $override kolom tabel `satker`
     */
    protected function buatSatker(?int $idUnit = null, array $override = []): int
    {
        return $this->fixtureInsert('satker', array_merge([
            'id_unit' => $idUnit ?? $this->buatUnit(),
            'satker'  => 'Satker Fixture ' . $this->fixtureNomor(),
            'status'  => 1,
        ], $override));
    }

    /**
     * Rantai jabatan G-02 di satker $idSatker (null → unit + satker baru): group_jabatan → sub_group_jabatan →
     * jabatan.
     *
     * @return array{id_unit: int, id_satker: int, id_group_jabatan: int, id_sub_group_jabatan: int, id_jabatan: int}
     */
    protected function buatJabatan(?int $idSatker = null): array
    {
        $idSatker ??= $this->buatSatker();
        $idUnit = $this->idUnitSatker($idSatker);
        $nomor  = $this->fixtureNomor();

        $idGroup = $this->fixtureInsert('group_jabatan', ['group_jabatan' => 'Group Fixture ' . $nomor, 'status' => 1]);
        $idSub   = $this->fixtureInsert('sub_group_jabatan', [
            'id_group_jabatan'  => $idGroup,
            'sub_group_jabatan' => 'Sub Group Fixture ' . $nomor,
            'status'            => 1,
        ]);
        $idJabatan = $this->fixtureInsert('jabatan', [
            'id_group_jabatan'     => $idGroup,
            'id_sub_group_jabatan' => $idSub,
            'id_satker'            => $idSatker,
            'jabatan'              => 'Jabatan Fixture ' . $nomor,
            'status'               => 1,
        ]);

        return [
            'id_unit'              => $idUnit,
            'id_satker'            => $idSatker,
            'id_group_jabatan'     => $idGroup,
            'id_sub_group_jabatan' => $idSub,
            'id_jabatan'           => $idJabatan,
        ];
    }

    /**
     * Master `jabatan_koordinasi` (FK DBV-019 `id_jabatan_koord`, `id_atasan_es_3_koord`, `id_atasan_es_4_koord`) di
     * satker $idSatker (null → unit + satker baru).
     *
     * @param array<string, mixed> $override kolom tabel `jabatan_koordinasi`
     */
    protected function buatJabatanKoordinasi(?int $idSatker = null, array $override = []): int
    {
        $idSatker ??= $this->buatSatker();

        return $this->fixtureInsert('jabatan_koordinasi', array_merge([
            'id_unit'   => $this->idUnitSatker($idSatker),
            'id_satker' => $idSatker,
            'jenis'     => 1,
            'jabatan'   => 'Jabatan Koordinasi Fixture ' . $this->fixtureNomor(),
            'status'    => 1,
        ], $override));
    }

    /**
     * Master `rumpun_jabatan` (FK DBV-019 `id_rumpun_jabatan`).
     *
     * @param array<string, mixed> $override kolom tabel `rumpun_jabatan`
     */
    protected function buatRumpunJabatan(array $override = []): int
    {
        return $this->fixtureInsert('rumpun_jabatan', array_merge([
            'rumpun_jabatan' => 'Rumpun Fixture ' . $this->fixtureNomor(),
            'status'         => 1,
        ], $override));
    }

    /**
     * Pegawai baru + snapshot jabatan aktif.
     *
     * Rantai jabatan snapshot: `$pmj['id_jabatan']` diberikan → id_satker/id_unit/id_group_jabatan/id_sub_group_jabatan
     * diturunkan dari baris `jabatan` itu; hanya `$pmj['id_satker']` → jabatan baru di satker itu; tidak keduanya →
     * unit + satker + jabatan baru. Kolom teks snapshot (`unit`, `satker`, `jabatan`, `group_jabatan`,
     * `sub_group_jabatan`) diisi nama masternya. Nilai di $pmj selalu menang atas turunan.
     *
     * tgl_lahir default diturunkan dari 8 digit pertama NIP bila NIP 18 digit dan tanggalnya valid; selain itu
     * 1990-01-01 (mis. NIP pendek/NIK untuk test koreksi NIP).
     *
     * @param array<string, mixed> $override kolom tabel `pegawai` (mis. nip, nama, status)
     * @param array<string, mixed> $pmj      kolom tabel `pegawai_mutasi_jabatan`
     *
     * @return string NIP
     */
    protected function buatPegawai(array $override = [], array $pmj = []): string
    {
        $nip = isset($override['nip']) ? (string) $override['nip'] : $this->nipSintetis();

        $this->db->table('pegawai')->insert(array_merge([
            'nip'           => $nip,
            'nama'          => 'Pegawai Fixture ' . substr($nip, -3),
            'tgl_lahir'     => $this->tglLahirDariNip($nip),
            'jenis_kelamin' => 1,
            'status'        => 1,
        ], $override, ['nip' => $nip]));

        $idJabatan = isset($pmj['id_jabatan'])
            ? (int) $pmj['id_jabatan']
            : $this->buatJabatan(isset($pmj['id_satker']) ? (int) $pmj['id_satker'] : null)['id_jabatan'];

        $this->db->table('pegawai_mutasi_jabatan')->insert(array_merge(
            ['nip' => $nip, 'jenis_jabatan' => 1, 'jenis_mutasi' => 1, 'tmtsk' => '2015-01-01'],
            $this->rantaiJabatan($idJabatan),
            $pmj,
            ['nip' => $nip],
        ));

        return $nip;
    }

    /**
     * Pegawai baru di satker $idSatker (jabatan baru di satker itu) — untuk test lingkup role 3 (Admin Satker).
     *
     * @param array<string, mixed> $override kolom tabel `pegawai`
     */
    protected function buatPegawaiDiSatker(int $idSatker, array $override = []): string
    {
        return $this->buatPegawai($override, ['id_satker' => $idSatker]);
    }

    /**
     * Baris `riwayat_mutasi_jabatan` untuk pegawai $nip (wajib sudah ada). Default: rantai jabatan snapshot pegawai itu
     * (atau jabatan baru bila belum ada snapshot), status 1 Disetujui, TMT 2015-01-01. Snapshot TIDAK disinkronkan.
     *
     * @param array<string, mixed> $override kolom tabel `riwayat_mutasi_jabatan`; id_jabatan → rantai diturunkan dari
     *                                       jabatan itu
     *
     * @return int id_riwayat_mutasi_jabatan
     */
    protected function buatRiwayatMutasiJabatan(string $nip, array $override = []): int
    {
        $this->pastikanPegawaiAda($nip);

        if (isset($override['id_jabatan'])) {
            $idJabatan = (int) $override['id_jabatan'];
        } else {
            $pmj       = $this->db->table('pegawai_mutasi_jabatan')->select('id_jabatan')->where('nip', $nip)->get()->getRowArray();
            $idJabatan = isset($pmj['id_jabatan']) ? (int) $pmj['id_jabatan'] : $this->buatJabatan()['id_jabatan'];
        }

        $rantai = array_intersect_key($this->rantaiJabatan($idJabatan), array_flip([
            'id_group_jabatan', 'id_sub_group_jabatan', 'id_unit', 'id_satker', 'id_jabatan', 'unit', 'satker', 'jabatan',
        ]));

        return $this->fixtureInsert('riwayat_mutasi_jabatan', array_merge(
            ['nip' => $nip, 'jenis_jabatan' => 1, 'jenis_mutasi' => 1, 'tmtsk' => '2015-01-01', 'status' => 1],
            $rantai,
            $override,
            ['nip' => $nip],
        ));
    }

    /**
     * Akun `pengguna` aktif tertaut NIP $nip (username = NIP, password AuthSeeder::PASSWORD). Pegawai $nip wajib sudah
     * ada (prasyarat FK `pengguna.nip` → `pegawai` DBV-019). id_unit/id_satker akun diambil dari snapshot jabatan
     * pegawai itu.
     *
     * @param array<string, mixed> $override kolom tabel `pengguna`
     *
     * @return int id_pengguna
     */
    protected function buatAkunUntuk(string $nip, int $role, array $override = []): int
    {
        if (! Role::isValid($role)) {
            throw new RuntimeException("Role {$role} tidak dikenal.");
        }

        $this->pastikanPegawaiAda($nip);

        $pmj = $this->db->table('pegawai_mutasi_jabatan')->select('id_unit, id_satker')->where('nip', $nip)->get()->getRowArray();
        $now = date('Y-m-d H:i:s');

        self::$fixtureHashPassword ??= password_hash(AuthSeeder::PASSWORD, PASSWORD_ARGON2ID);

        return $this->fixtureInsert('pengguna', array_merge([
            'nip'        => $nip,
            'username'   => $nip,
            'name'       => 'Akun ' . $nip,
            'password'   => self::$fixtureHashPassword,
            'user_level' => $role,
            'id_unit'    => isset($pmj['id_unit']) ? (string) $pmj['id_unit'] : null,
            'id_satker'  => isset($pmj['id_satker']) ? (string) $pmj['id_satker'] : null,
            'status'     => '1',
            'created_at' => $now,
            'updated_at' => $now,
        ], $override));
    }

    /**
     * AuthContext berisi claims akun $idPengguna (format AuthService::claimsFor), untuk memanggil service langsung
     * tanpa HTTP.
     */
    protected function authUntukAkun(int $idPengguna): AuthContext
    {
        $akun = $this->db->table('pengguna')->where('id_pengguna', $idPengguna)->get()->getRowArray();

        if (! is_array($akun)) {
            throw new RuntimeException("Akun {$idPengguna} tidak ada.");
        }

        $auth = new AuthContext();
        $auth->setClaims(AuthService::claimsFor($akun));

        return $auth;
    }

    /**
     * ID + kolom teks snapshot untuk jabatan $idJabatan (satker/unit/group/sub group diturunkan dari baris jabatan).
     *
     * @return array<string, int|string|null>
     */
    private function rantaiJabatan(int $idJabatan): array
    {
        $jabatan = $this->db->table('jabatan')->where('id_jabatan', $idJabatan)->get()->getRowArray();

        if (! is_array($jabatan)) {
            throw new RuntimeException("Jabatan {$idJabatan} tidak ada.");
        }

        $idSatker = $jabatan['id_satker'] === null ? null : (int) $jabatan['id_satker'];
        $idUnit   = $idSatker === null ? null : $this->idUnitSatker($idSatker);
        $idGroup  = $jabatan['id_group_jabatan'] === null ? null : (int) $jabatan['id_group_jabatan'];
        $idSub    = $jabatan['id_sub_group_jabatan'] === null ? null : (int) $jabatan['id_sub_group_jabatan'];

        return [
            'id_unit'              => $idUnit,
            'id_satker'            => $idSatker,
            'id_group_jabatan'     => $idGroup,
            'id_sub_group_jabatan' => $idSub,
            'id_jabatan'           => $idJabatan,
            'unit'                 => $this->namaMaster('unit', 'id_unit', 'unit', $idUnit),
            'satker'               => $this->namaMaster('satker', 'id_satker', 'satker', $idSatker),
            'group_jabatan'        => $this->namaMaster('group_jabatan', 'id_group_jabatan', 'group_jabatan', $idGroup),
            'sub_group_jabatan'    => $this->namaMaster('sub_group_jabatan', 'id_sub_group_jabatan', 'sub_group_jabatan', $idSub),
            'jabatan'              => (string) $jabatan['jabatan'],
        ];
    }

    private function namaMaster(string $table, string $pk, string $kolom, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $row = $this->db->table($table)->select($kolom)->where($pk, $id)->get()->getRowArray();

        return is_array($row) ? (string) $row[$kolom] : null;
    }

    private function tglLahirDariNip(string $nip): string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})\d{10}$/', $nip, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return '1990-01-01';
    }

    private function pastikanPegawaiAda(string $nip): void
    {
        if ($this->db->table('pegawai')->where('nip', $nip)->countAllResults() === 0) {
            throw new RuntimeException("Pegawai {$nip} tidak ada — buat dulu dengan buatPegawai().");
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fixtureInsert(string $table, array $data): int
    {
        if (! $this->db->table($table)->insert($data)) {
            throw new RuntimeException("Fixture gagal menyisipkan baris {$table}.");
        }

        return (int) $this->db->insertID();
    }

    private function idUnitSatker(int $idSatker): int
    {
        $row = $this->db->table('satker')->select('id_unit')->where('id_satker', $idSatker)->get()->getRowArray();

        if (! is_array($row) || $row['id_unit'] === null) {
            throw new RuntimeException("Satker {$idSatker} tidak ada atau tanpa unit.");
        }

        return (int) $row['id_unit'];
    }

    private function fixtureNomor(): string
    {
        return bin2hex(random_bytes(4));
    }
}
