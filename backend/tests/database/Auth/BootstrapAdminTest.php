<?php

declare(strict_types=1);

namespace Tests\Database\Auth;

use App\Libraries\Auth\BootstrapAdmin;
use App\Libraries\Auth\PasswordPolicy;
use CodeIgniter\Test\StreamFilterTrait;
use RuntimeException;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-069 (K-M2) — akun admin awal: password acak sekali tampil, Argon2id, wajib ganti, audit tanpa hash, penjaga
 * admin aktif / konfirmasi / terminal non-interaktif.
 *
 * @internal
 */
final class BootstrapAdminTest extends DatabaseTestCase
{
    use StreamFilterTrait;

    public function testMembuatAdminDenganPasswordAcakDanWajibGanti(): void
    {
        $hasil = (new BootstrapAdmin($this->db))->buat(' admin.cutover ', 'Admin Cutover', null, false);
        $akun  = $this->db->table('pengguna')->where('id_pengguna', $hasil['id'])->get()->getRowArray();

        $this->assertSame('admin.cutover', $akun['username']);
        $this->assertNull($akun['nip']);
        $this->assertSame('1', $akun['user_level']);
        $this->assertSame('1', $akun['must_change_password']);
        $this->assertNull($akun['password_legacy']);
        $this->assertTrue(password_verify($hasil['password'], (string) $akun['password']));
        $this->assertStringStartsWith('$argon2id$', (string) $akun['password']);
        $this->assertSame([], PasswordPolicy::fromConfig()->errors($hasil['password']));

        $audit = $this->db->table('audit_logs')->where('entity', 'pengguna')->where('entity_id', (string) $hasil['id'])->get()->getRowArray();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($hasil['password'], json_encode($audit) ?: '');
        $this->assertStringNotContainsString('argon2id', json_encode($audit) ?: '');
    }

    public function testDitolakBilaSudahAdaAdminAktifKecualiTambahan(): void
    {
        (new BootstrapAdmin($this->db))->buat('admin.satu', 'Admin Satu', null, false);

        try {
            (new BootstrapAdmin($this->db))->buat('admin.dua', 'Admin Dua', null, false);
            $this->fail('Admin aktif sudah ada harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('akun admin role 1 aktif; pakai --tambahan', $e->getMessage());
        }

        $this->assertGreaterThan(0, (new BootstrapAdmin($this->db))->buat('admin.dua', 'Admin Dua', 'admin@contoh.test', true)['id']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Username sudah dipakai.');
        (new BootstrapAdmin($this->db))->buat('ADMIN.DUA', 'Lain', null, true);
    }

    public function testPasswordAcakBerbedaTiapPanggilan(): void
    {
        $this->assertNotSame(BootstrapAdmin::passwordAcak(), BootstrapAdmin::passwordAcak());
        $this->assertSame(24, strlen(BootstrapAdmin::passwordAcak()));
    }

    public function testCommandMenjagaKonfirmasiDanTerminal(): void
    {
        $db = $this->db->getDatabase();

        command('akun:bootstrap-admin --username=admin.cli --nama=Admin --konfirmasi=salah');
        $this->assertStringContainsString("Tujuan: {$this->db->hostname}:{$this->db->port} / {$db}", $this->getStreamFilterBuffer(), 'tujuan dicetak dulu');
        $this->assertStringContainsString('--konfirmasi harus sama persis', $this->getStreamFilterBuffer());

        command("akun:bootstrap-admin --username=admin.cli --nama=Admin --konfirmasi={$db}");
        $this->assertStringContainsString('Bukan terminal interaktif', $this->getStreamFilterBuffer());
        $this->assertSame(0, $this->db->table('pengguna')->where('username', 'admin.cli')->countAllResults());

        command("akun:bootstrap-admin --username=admin.cli --nama=Admin --konfirmasi={$db} --paksa-non-interaktif");
        $this->assertMatchesRegularExpression('/Password awal \(tampil sekali, wajib diganti saat login pertama\):\S*\R[A-Za-z0-9]{24}\R/', $this->getStreamFilterBuffer());
        $this->assertSame(1, $this->db->table('pengguna')->where('username', 'admin.cli')->countAllResults());
    }
}
