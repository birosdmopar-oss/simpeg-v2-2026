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
 * → snapshot `pegawai_mutasi_jabatan` ber-unit/satker/jabatan → akun `pengguna` tertaut NIP. Setiap kolom FK diisi ID
 * master yang dibuat fixture ini (patuh FK DBV-019 `pegawai_mutasi_jabatan` → G-02 dan `pengguna.nip` → `pegawai`,
 * terpasang atau belum); kolom FK lain dibiarkan NULL.
 *
 * Berjalan di bingkai transaksi uji MAKE-001 tanpa tanda `db-isolasi-penuh`: HANYA INSERT lewat query builder —
 * tanpa DDL, tanpa migrate, tanpa commit eksplisit, tanpa TRUNCATE/DELETE. NIP sintetis 18 digit (bukan data asli),
 * unik terhadap `pegawai` dan `pengguna` yang sudah ada.
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
     * Pegawai baru + snapshot jabatan aktif (unit/satker/jabatan baru kecuali $pmj menyebut id_satker).
     *
     * @param array<string, mixed> $override kolom tabel `pegawai` (mis. nip, nama, status)
     * @param array<string, mixed> $pmj      kolom tabel `pegawai_mutasi_jabatan`; id_satker tanpa id_jabatan → jabatan
     *                                       baru di satker itu
     *
     * @return string NIP
     */
    protected function buatPegawai(array $override = [], array $pmj = []): string
    {
        $nip = isset($override['nip']) ? (string) $override['nip'] : $this->nipSintetis();

        $this->db->table('pegawai')->insert(array_merge([
            'nip'           => $nip,
            'nama'          => 'Pegawai Fixture ' . substr($nip, -3),
            'tgl_lahir'     => substr($nip, 0, 4) . '-' . substr($nip, 4, 2) . '-' . substr($nip, 6, 2),
            'jenis_kelamin' => 1,
            'status'        => 1,
        ], $override, ['nip' => $nip]));

        $jabatan = isset($pmj['id_jabatan'])
            ? []
            : $this->buatJabatan(isset($pmj['id_satker']) ? (int) $pmj['id_satker'] : null);

        $this->db->table('pegawai_mutasi_jabatan')->insert(array_merge(
            ['nip' => $nip, 'jenis_jabatan' => 1, 'jenis_mutasi' => 1, 'tmtsk' => '2015-01-01'],
            $jabatan,
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
     * Akun `pengguna` aktif tertaut NIP $nip (username = NIP, password AuthSeeder::PASSWORD). id_unit/id_satker akun
     * diambil dari snapshot jabatan pegawai itu.
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
