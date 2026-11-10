<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Integrasi\Simapi\AturanTrigger as T;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * MAKE-060 A5b — aturan trigger legacy penulis `simapi_ch` (murni). Kontrak jd/field_name/peristiwa per tabel dari fixture
 * `tests/_support/Integrasi/Simapi/simapi_ch_trigger.json` (diturunkan dari D1), ditambah semantik MySQL (CONCAT NULL, COALESCE,
 * perbandingan NULL), cabang status, ganti NIP, dan pemetaan teks.
 *
 * @internal
 */
final class AturanTriggerTest extends CIUnitTestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(SUPPORTPATH . 'Integrasi/Simapi/simapi_ch_trigger.json'), true);
    }

    /**
     * Baris sumber sintetis aktif (status 1) dengan semua kolom terisi string.
     *
     * @return array<string, mixed>
     */
    private static function sumber(string $tabel, array $lebih = []): array
    {
        $pk = T::RIWAYAT[$tabel][1] ?? null;

        return $lebih + array_filter([
            $pk          => '7', 'nip' => '000000000000000001', 'status' => '1', 'id_riwayat_keluarga' => '3', 'id_detail_anak' => '9', 'nama' => 'Nama', 'tgl_lahir' => '1990-01-01',
            'user_level' => '6', 'email' => 'a@contoh.test', 'id_jenis_kp' => '1', 'tmtsk' => '2020-01-01', 'tgl_sk' => '2020-01-02', 'jenis_kp' => 'Reguler', 'gol' => 'III',
            'ruang'      => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda', 'tgl_lulus' => '2010-01-01',
        ], static fn ($v, $k) => $k !== '', ARRAY_FILTER_USE_BOTH);
    }

    public function testKontrakJdFieldDanPeristiwaSesuaiFixtureD1(): void
    {
        $fx = self::fixture();
        $this->assertSame(T::tabel(), array_values(array_intersect(T::tabel(), array_keys($fx))), '15 tabel sumber');
        $this->assertCount(15, array_filter(array_keys($fx), static fn (string $k): bool => $k[0] !== '_'));

        foreach (T::tabel() as $tabel) {
            $f   = $fx[$tabel];
            $ins = T::baris($tabel, T::INSERT, null, self::sumber($tabel), ['nip_keluarga' => static fn (): string => '000000000000000001']);
            $this->assertCount(1, $ins, $tabel);
            $this->assertSame([$f['id_simapi_jd'], 1, $f['field_name']], [$ins[0]['id_simapi_jd'], $ins[0]['action'], $ins[0]['field_name']], "{$tabel} INSERT");
            $this->assertNotNull($ins[0]['field_value'], $tabel);
            $this->assertCount(substr_count($f['field_name'], '|') + 1, explode('|', (string) $ins[0]['field_value']), "{$tabel} jumlah nilai");

            $hapus = T::baris($tabel, T::DELETE, self::sumber($tabel), null, ['nip_keluarga' => static fn (): string => '000000000000000001']);
            $this->assertSame(in_array('DELETE', $f['peristiwa'], true) ? 1 : 0, count($hapus), "{$tabel} DELETE ada/tidak");
        }
    }

    public function testRiwayatCabangStatusDanGantiNip(): void
    {
        $lama = self::sumber('riwayat_kgb');

        $this->assertSame([], T::baris('riwayat_kgb', T::INSERT, null, self::sumber('riwayat_kgb', ['status' => '0'])), 'INSERT menunggu tidak ditulis');
        $this->assertSame(1, T::baris('riwayat_kgb', T::UPDATE, $lama, $lama)[0]['action']);
        $tolak = T::baris('riwayat_kgb', T::UPDATE, $lama, ['status' => '2'] + $lama);
        $this->assertSame([2, null, null, 7], [$tolak[0]['action'], $tolak[0]['field_name'], $tolak[0]['field_value'], $tolak[0]['id_rwy']]);
        $this->assertSame([], T::baris('riwayat_kgb', T::UPDATE, $lama, ['status' => '10'] + $lama), 'hapus lunak (status 10) tidak menulis [K]');

        $ganti = T::baris('riwayat_kgb', T::UPDATE, $lama, ['nip' => '000000000000000002'] + $lama);
        $this->assertSame('000000000000000001', $ganti[0]['nip'], 'cabang OLD.nip != NEW.nip memakai OLD.nip');

        $del = T::baris('riwayat_kgb', T::DELETE, $lama, null);
        $this->assertSame([2, 7, '000000000000000001'], [$del[0]['action'], $del[0]['id_rwy'], $del[0]['nip']], 'DELETE: OLD.id, OLD.nip');

        $this->assertSame([], T::baris('riwayat_kp', T::INSERT, null, self::sumber('riwayat_kp', ['id_jenis_kp' => '6'])), 'KP jenis 6 dikecualikan');
        $this->assertSame([], T::baris('riwayat_kp', T::INSERT, null, self::sumber('riwayat_kp', ['id_jenis_kp' => null])), "NULL != '6' bernilai NULL [K]");
    }

    public function testConcatNullDanCoalesce(): void
    {
        $kp = T::baris('riwayat_kp', T::INSERT, null, self::sumber('riwayat_kp', ['gol' => null]));
        $this->assertNull($kp[0]['field_value'], 'CONCAT dengan bagian NULL (tanpa COALESCE) = NULL [K]');

        $kgb = T::baris('riwayat_kgb', T::INSERT, null, self::sumber('riwayat_kgb', ['no_sk' => null, 'jym' => 'Kepala']));
        $this->assertSame('7|000000000000000001|2020-01-01|2020-01-02||||Kepala', $kgb[0]['field_value']);

        $kel = T::baris('riwayat_keluarga', T::INSERT, null, self::sumber('riwayat_keluarga', ['id_riwayat_keluarga' => '7']));
        $this->assertStringEndsWith('|0', (string) $kel[0]['field_value'], 'COALESCE(jumlah_anak, 0)');
    }

    public function testPemetaanTeks(): void
    {
        $jab = T::baris('riwayat_mutasi_jabatan', T::INSERT, null, self::sumber('riwayat_mutasi_jabatan', ['id_riwayat_mutasi_jabatan' => '7', 'jenis_jabatan' => '1', 'jenis_jabatan_koord' => '2', 'status_jft' => '2']));
        $v   = explode('|', (string) $jab[0]['field_value']);
        $this->assertSame(['Nomenlaktur', 'Subkoordinator', 'Pembebasan Sementara'], [$v[5], $v[6], $v[21]]);

        $dik = T::baris('riwayat_diklat', T::INSERT, null, self::sumber('riwayat_diklat', ['id_riwayat_diklat' => '7', 'jenis_diklat' => '4', 'deskripsi' => 'Latsar', 'nama_diklat' => 'CPNS']));
        $this->assertSame(['Prajabatan', 'Latsar (CPNS)'], array_slice(explode('|', (string) $dik[0]['field_value']), 2, 2));

        $al = T::baris('riwayat_alamat', T::INSERT, null, self::sumber('riwayat_alamat', ['id_riwayat_alamat' => '7', 'jenis_alamat' => '2', 'id_provinsi' => '99', 'provinsi_lain' => 'Lain', 'provinsi' => 'P']));
        $this->assertSame(['Alamat Domisili', '', 'Lain'], array_slice(explode('|', (string) $al[0]['field_value']), 2, 3));

        $pd = T::baris('riwayat_pendidikan', T::INSERT, null, self::sumber('riwayat_pendidikan', ['id_riwayat_pendidikan' => '7', 'id_jenjang_pendidikan' => '2', 'nem' => '40.00', 'ipk' => '3.00']));
        $this->assertStringEndsWith('|40.00', (string) $pd[0]['field_value']);
    }

    public function testPegawaiDanPengguna(): void
    {
        $peg  = self::sumber('pegawai', ['jenis_kelamin' => '2', 'foto' => 'foto/a.jpg', 'agama' => 'Islam', 'jenis_pegawai' => 'PNS']);
        $foto = static fn (string $nip, string $p): string => "URL:{$nip}";
        $ins  = T::baris('pegawai', T::INSERT, null, $peg, ['url_foto' => $foto]);
        $v    = explode('|', (string) $ins[0]['field_value']);
        $this->assertSame(['Perempuan', '', 'PNS', 'Aktif', 'URL:000000000000000001'], array_slice($v, 8, 5), 'email kosong literal, foto lewat urlFoto [V2]');
        $this->assertNull($ins[0]['id_rwy']);

        $non = T::baris('pegawai', T::UPDATE, $peg, ['status' => '2'] + $peg, ['url_foto' => $foto]);
        $this->assertSame('Tidak Aktif', explode('|', (string) $non[0]['field_value'])[11], 'aktif → nonaktif tetap ditulis');
        $this->assertSame([], T::baris('pegawai', T::UPDATE, ['status' => '2'] + $peg, ['status' => '2'] + $peg), 'nonaktif → nonaktif tidak ditulis');
        $this->assertSame(2, T::baris('pegawai', T::DELETE, $peg, null)[0]['action']);

        $akun = ['nip' => '000000000000000001', 'email' => 'a@contoh.test', 'status' => '1', 'user_level' => '6'];
        $this->assertSame('000000000000000001|a@contoh.test', T::baris('pengguna', T::INSERT, null, $akun)[0]['field_value']);
        $this->assertSame([], T::baris('pengguna', T::UPDATE, $akun, $akun), 'email tidak berubah');
        $this->assertSame([], T::baris('pengguna', T::UPDATE, ['email' => null] + $akun, $akun), 'NULL != x bernilai NULL [K]');
        $this->assertCount(1, T::baris('pengguna', T::UPDATE, $akun, ['email' => 'b@contoh.test'] + $akun));
        $this->assertSame([], T::baris('pengguna', T::INSERT, null, ['user_level' => '1'] + $akun), 'level selain 2/6/7');
        $this->assertSame([], T::baris('pengguna', T::DELETE, $akun, null), 'pengguna tanpa trigger DELETE');
    }
}
