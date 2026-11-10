<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Auth\AccountProvisioner;
use App\Libraries\Auth\PasswordService;
use App\Libraries\Auth\PasswordVerifier;
use App\Libraries\Integrasi\Simapi\AturanTrigger;
use App\Libraries\Integrasi\Simapi\PencatatPerubahan;
use App\Models\Auth\PenggunaModel;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-076 — pemancar `simapi_update` lewat penulis nyata: akun pegawai baru (AccountProvisioner) → 'UPDATE'
 * username|password|email dengan hash berlaku; ganti password (PasswordService) dan rehash MD5 → Argon2id saat login
 * (PasswordVerifier, keputusan reviewer Q1) → field `password`; pegawai INSERT/UPDATE/DELETE; pmj selalu 'UPDATE'; insert log
 * gagal di dalam transaksi tidak menggagalkan bisnis (savepoint, pola A5b); rollback pemilik → log hilang.
 *
 * @internal
 */
final class SimapiPemancarUpdateTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    /**
     * @return list<array<string, mixed>>
     */
    private function su(): array
    {
        return $this->db->table('simapi_update')->orderBy('id_simapi_update')->get()->getResultArray();
    }

    private function verifier(): PasswordVerifier
    {
        return new PasswordVerifier(new PenggunaModel());
    }

    private function akun(string $nip): int
    {
        $pengguna = new PenggunaModel();
        (new AccountProvisioner($pengguna, new PasswordVerifier($pengguna)))->provisionForPegawai(['nip' => $nip, 'nama' => 'Uji', 'email' => 'uji@contoh.test']);

        return (int) $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray()['id_pengguna'];
    }

    public function testAkunBaruDicatatDenganHashBerlaku(): void
    {
        $nip = $this->buatPegawai();
        $id  = $this->akun($nip);
        $row = $this->db->table('pengguna')->where('id_pengguna', $id)->get()->getRowArray();

        $su = $this->su();
        $this->assertCount(1, $su);
        $this->assertSame([$nip, 'UPDATE', 'username|password|email', '||'], [$su[0]['nip'], $su[0]['last_action'], $su[0]['nama_field'], $su[0]['nilai_lama']]);
        $this->assertSame($row['username'] . '|' . ($row['password'] ?? $row['password_legacy'] ?? '') . '|uji@contoh.test', $su[0]['nilai_baru']);
        $this->assertNotNull($su[0]['last_update']);
    }

    public function testGantiPasswordDanRehashLoginDicatat(): void
    {
        $nip = $this->buatPegawai();
        $id  = $this->akun($nip);
        $this->db->table('pengguna')->where('id_pengguna', $id)->update(['password' => null, 'password_legacy' => md5('Rahasia#Lama1')]);
        $this->db->table('simapi_update')->emptyTable();

        $user = (new PenggunaModel())->find($id);
        $this->assertTrue($this->verifier()->verify($user, 'Rahasia#Lama1')['ok']);

        $su = $this->su();
        $this->assertCount(1, $su, 'rehash MD5 → Argon2id tercatat (Q1)');
        $this->assertSame(['password', md5('Rahasia#Lama1')], [$su[0]['nama_field'], $su[0]['nilai_lama']]);
        $baru = (string) $this->db->table('pengguna')->where('id_pengguna', $id)->get()->getRowArray()['password'];
        $this->assertSame($baru, $su[0]['nilai_baru']);

        $pengguna = new PenggunaModel();
        (new PasswordService($pengguna, new PasswordVerifier($pengguna), service('jwt')))->change($id, 'Rahasia#Lama1', 'Rahasia#Baru22', 'Rahasia#Baru22');

        $su = $this->su();
        $this->assertCount(2, $su);
        $this->assertSame(['password', $baru], [$su[1]['nama_field'], $su[1]['nilai_lama']]);
        $this->assertNotSame($baru, $su[1]['nilai_baru']);
    }

    public function testPegawaiInsertUpdateDeleteDanPmj(): void
    {
        $nip = $this->buatPegawai(['jenis_pegawai' => 'PNS']);
        $peg = PencatatPerubahan::baca($this->db, 'pegawai', 'nip', $nip);
        PencatatPerubahan::catat($this->db, 'pegawai', AturanTrigger::INSERT, null, $peg);

        $lama = PencatatPerubahan::baca($this->db, 'pegawai_mutasi_jabatan', 'nip', $nip);
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->update(['jabatan' => 'Analis Baru']);
        PencatatPerubahan::sesudahUbah($this->db, 'pegawai_mutasi_jabatan', 'nip', $nip, $lama);

        PencatatPerubahan::catat($this->db, 'pegawai', AturanTrigger::DELETE, $peg, null);

        $su = $this->su();
        $this->assertSame(['INSERT', 'UPDATE', 'DELETE'], array_column($su, 'last_action'));
        $this->assertStringEndsWith('|PNS|Aktif', (string) $su[0]['nilai_baru']);
        $this->assertSame(['jabatan', 'Analis Baru'], [$su[1]['nama_field'], $su[1]['nilai_baru']]);
        $this->assertNull($su[2]['nama_field']);
        $this->assertSame([$nip, $nip, $nip], array_column($su, 'nip'));
    }

    public function testRollbackPemilikMenghapusLogDanInsertGagalTidakMenggagalkanBisnis(): void
    {
        $nip = $this->buatPegawai();

        $this->db->transBegin();
        PencatatPerubahan::catat($this->db, 'pegawai', AturanTrigger::DELETE, ['nip' => $nip], null);
        $this->db->transRollback();
        $this->assertSame([], $this->su(), 'rollback pemilik → log hilang');

        $this->db->transBegin();
        $this->db->table('pegawai')->where('nip', $nip)->update(['nama' => 'Sah']);
        PencatatPerubahan::catat($this->db, 'pegawai', AturanTrigger::DELETE, ['nip' => str_repeat('9', 40)], null); // > VARCHAR(30) → insert gagal
        $this->assertTrue($this->db->transStatus(), 'status transaksi dipulihkan');
        $this->db->transCommit();

        $this->assertSame('Sah', $this->db->table('pegawai')->where('nip', $nip)->get()->getRowArray()['nama']);
    }
}
