<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Integrasi\Simapi\AturanTrigger as T;
use App\Libraries\Integrasi\Simapi\AturanTriggerUpdate as U;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * MAKE-076 — aturan trigger legacy penulis `simapi_update` (murni). Kontrak per tabel/peristiwa dari fixture
 * `tests/_support/Integrasi/Simapi/simapi_update_trigger.json` (diturunkan dari D1), ditambah semantik MySQL, cabang ganti NIP,
 * hash berlaku [V2] (Argon2id, selain itu MD5 legacy) dan foto [V2].
 *
 * @internal
 */
final class AturanTriggerUpdateTest extends CIUnitTestCase
{
    private const NIP = '000000000000000001';

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(SUPPORTPATH . 'Integrasi/Simapi/simapi_update_trigger.json'), true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function pegawai(array $lebih = []): array
    {
        return $lebih + ['nip' => self::NIP, 'nama' => 'Budi', 'glr_awal' => 'Dr.', 'glr_akhir' => 'M.Si', 'status' => '1', 'jenis_pegawai' => 'PNS', 'foto' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private static function akun(array $lebih = []): array
    {
        return $lebih + ['nip' => self::NIP, 'username' => self::NIP, 'password' => null, 'password_legacy' => 'md5lama', 'email' => 'a@contoh.test', 'user_level' => '2', 'status' => '1'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function bantu(): array
    {
        return ['url_foto' => static fn (string $nip, string $p): string => "foto:{$nip}:{$p}", 'hash_akun' => static fn (): string => 'hashAkun'];
    }

    public function testKontrakLastActionDanFieldSesuaiFixtureD1(): void
    {
        $fx = self::fixture();
        $this->assertSame(U::TABEL, array_values(array_filter(array_keys($fx), static fn (string $k): bool => $k[0] !== '_')));

        $ins = U::baris('pegawai', T::INSERT, null, self::pegawai(), self::bantu());
        $this->assertSame(['INSERT', $fx['pegawai']['INSERT']['nama_field'], null], [$ins[0]['last_action'], $ins[0]['nama_field'], $ins[0]['nilai_lama']]);
        $this->assertSame(self::NIP . '|Dr. Budi, M.Si||' . self::NIP . '|hashAkun|||||PNS|Aktif', $ins[0]['nilai_baru']);

        $del = U::baris('pegawai', T::DELETE, self::pegawai(), null);
        $this->assertSame([['nip' => self::NIP, 'last_action' => 'DELETE', 'nama_field' => null, 'nilai_lama' => null, 'nilai_baru' => null]], $del);

        $pmj = ['nip' => self::NIP, 'jabatan' => 'Analis', 'unit' => 'Unit', 'satker' => null];
        $this->assertSame([['nip' => self::NIP, 'last_action' => 'UPDATE', 'nama_field' => $fx['pegawai_mutasi_jabatan']['INSERT']['nama_field'], 'nilai_lama' => '||', 'nilai_baru' => 'Analis|Unit|']], U::baris('pegawai_mutasi_jabatan', T::INSERT, null, $pmj));
        $this->assertSame([['nip' => self::NIP, 'last_action' => 'UPDATE', 'nama_field' => 'jabatan|unit_kerja|satuan_kerja', 'nilai_lama' => 'Analis|Unit|', 'nilai_baru' => '||']], U::baris('pegawai_mutasi_jabatan', T::DELETE, $pmj, null));

        $ak = U::baris('pengguna', T::INSERT, null, self::akun());
        $this->assertSame(['UPDATE', $fx['pengguna']['INSERT']['nama_field'], '||', self::NIP . '|md5lama|a@contoh.test'], [$ak[0]['last_action'], $ak[0]['nama_field'], $ak[0]['nilai_lama'], $ak[0]['nilai_baru']]);
        $this->assertSame([], U::baris('pengguna', T::DELETE, self::akun(), null));
    }

    public function testPegawaiInsertTanpaAkunPasswordKosongDanNonaktifTidakMenulis(): void
    {
        $r = U::baris('pegawai', T::INSERT, null, self::pegawai(['glr_akhir' => '', 'glr_awal' => null, 'foto' => 'a.jpg']), ['url_foto' => self::bantu()['url_foto'], 'hash_akun' => static fn (): ?string => null]);
        $this->assertSame(self::NIP . '|Budi|foto:' . self::NIP . ':a.jpg|' . self::NIP . '||||||PNS|Aktif', $r[0]['nilai_baru']);
        $this->assertSame([], U::baris('pegawai', T::INSERT, null, self::pegawai(['status' => '2'])));
        $this->assertNull(U::baris('pegawai', T::INSERT, null, self::pegawai(['nama' => null]))[0]['nilai_baru'], 'CONCAT NULL');
    }

    public function testPegawaiUpdateKolomTerpantauUrutanTetap(): void
    {
        $lama = self::pegawai(['status' => '2', 'jenis_pegawai' => null, 'foto' => 'a.jpg']);
        $baru = self::pegawai(['nama' => 'Budi S', 'foto' => '']);
        $r    = U::baris('pegawai', T::UPDATE, $lama, $baru, self::bantu());

        $this->assertSame('nama_lengkap|status|jenis_pegawai|foto', $r[0]['nama_field']);
        $this->assertSame('Dr. Budi, M.Si|Tidak Aktif||foto:' . self::NIP . ':a.jpg', $r[0]['nilai_lama']);
        $this->assertSame('Dr. Budi S, M.Si|Aktif|PNS|', $r[0]['nilai_baru']);
        $this->assertSame('UPDATE', $r[0]['last_action']);

        $this->assertSame([], U::baris('pegawai', T::UPDATE, self::pegawai(), self::pegawai(['agama' => 'x'])), 'kolom tak terpantau');
        $this->assertSame([], U::baris('pegawai', T::UPDATE, self::pegawai(), self::pegawai(['status' => '2', 'nama' => 'X'])), 'NEW.status != 1');
    }

    public function testPegawaiGantiNipMemakaiOldNip(): void
    {
        $r = U::baris('pegawai', T::UPDATE, self::pegawai(), self::pegawai(['nip' => '000000000000000002']));
        $this->assertSame(['nip' => self::NIP, 'last_action' => 'UPDATE', 'nama_field' => 'nip', 'nilai_lama' => self::NIP, 'nilai_baru' => '000000000000000002'], $r[0]);
    }

    public function testPmjUpdateNullTidakDianggapBerubah(): void
    {
        $lama = ['nip' => self::NIP, 'jabatan' => 'A', 'unit' => null, 'satker' => 'S'];
        $this->assertSame([], U::baris('pegawai_mutasi_jabatan', T::UPDATE, $lama, ['unit' => 'U'] + $lama), '[K] OLD.unit NULL != NEW → salah');
        $r = U::baris('pegawai_mutasi_jabatan', T::UPDATE, $lama, ['jabatan' => 'B', 'satker' => 'T'] + $lama);
        $this->assertSame(['jabatan|satuan_kerja', 'A|S', 'B|T'], [$r[0]['nama_field'], $r[0]['nilai_lama'], $r[0]['nilai_baru']]);
    }

    public function testPenggunaHashBerlakuDanRehashTercatat(): void
    {
        $this->assertSame('md5lama', U::hashBerlaku(self::akun()));
        $this->assertSame('argon', U::hashBerlaku(self::akun(['password' => 'argon'])));
        $this->assertNull(U::hashBerlaku(self::akun(['password' => '', 'password_legacy' => ''])));

        $r = U::baris('pengguna', T::UPDATE, self::akun(), self::akun(['password' => 'argon', 'password_legacy' => null]));
        $this->assertSame(['password', 'md5lama', 'argon'], [$r[0]['nama_field'], $r[0]['nilai_lama'], $r[0]['nilai_baru']], 'rehash MD5 → Argon2id (Q1)');

        $r = U::baris('pengguna', T::UPDATE, self::akun(), self::akun(['username' => 'baru', 'email' => null]));
        $this->assertSame(['username|email', self::NIP . '|a@contoh.test', 'baru|'], [$r[0]['nama_field'], $r[0]['nilai_lama'], $r[0]['nilai_baru']]);

        $this->assertSame([], U::baris('pengguna', T::UPDATE, self::akun(), self::akun(['name' => 'x'])), 'tanpa perubahan terpantau');
    }

    public function testPenggunaHanyaLevelPegawaiAktif(): void
    {
        foreach (['1', '3', '4', '5', '8', null] as $level) {
            $this->assertSame([], U::baris('pengguna', T::INSERT, null, self::akun(['user_level' => $level])), (string) $level);
        }

        $this->assertSame([], U::baris('pengguna', T::INSERT, null, self::akun(['status' => '0'])));
        $this->assertSame([], U::baris('pengguna', T::UPDATE, self::akun(), self::akun(['status' => '0', 'email' => 'b@contoh.test'])));
        $this->assertCount(1, U::baris('pengguna', T::INSERT, null, self::akun(['user_level' => '7'])));
    }

    public function testTabelLainTidakMenulis(): void
    {
        $this->assertSame([], U::baris('riwayat_kp', T::INSERT, null, ['nip' => self::NIP]));
    }
}
