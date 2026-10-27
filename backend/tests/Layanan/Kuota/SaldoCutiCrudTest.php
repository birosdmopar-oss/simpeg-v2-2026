<?php

declare(strict_types=1);

namespace Tests\Layanan\Kuota;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use App\Libraries\Layanan\Cuti\SaldoCutiService;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\CutiUjiTrait;

/**
 * MAKE-022 M3 (PRD Fase 4 §6.3.6, QASMTASK-066) — kelola saldo cuti: role 1/3 (lingkup), batas D8 per kolom, tolak
 * duplikat `(nip, tahun)`, tahun > tahun ini → 422, target semua/pilih, ubah, hapus fisik, audit.
 *
 * @internal
 */
final class SaldoCutiCrudTest extends DatabaseTestCase
{
    use CutiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private function service(): SaldoCutiService
    {
        return new SaldoCutiService(
            $this->db,
            config(\Config\Cuti::class)->aturan(),
            new PegawaiScope($this->db),
            fn (): \DateTimeImmutable => new \DateTimeImmutable($this->sekarangUji, new \DateTimeZone('Asia/Jakarta')),
        );
    }

    /**
     * @param array<string, mixed> $lebih
     *
     * @return array<string, mixed>
     */
    private function isian(string $nip, array $lebih = []): array
    {
        return $lebih + ['target' => 2, 'nip' => $nip, 'tahun' => 2026, 'sisa_cuti' => 12, 'sisa_cuti_n1' => 6, 'sisa_cuti_n2' => 6, 'utang_cuti' => 0];
    }

    public function testTambahPilihPegawaiMenulisBarisDanAudit(): void
    {
        $p     = $this->buatPemohon(2);
        $admin = $this->buatPemohon(1);

        $hasil = $this->service()->tambah($admin['auth'], $this->isian($p['nip'], ['sisa_cuti_n1' => 4]));

        $this->assertSame(1, $hasil['dibuat']);
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => $p['nip'], 'tahun' => 2026, 'sisa_cuti' => 12, 'sisa_cuti_n1' => 4, 'sisa_cuti_n2' => 6]);
        $this->seeInDatabase('audit_logs', ['entity' => 'pegawai_sisa_cuti', 'event' => 'create']);
    }

    public function testDuplikatNipTahunDitolakDenganErrorsNip(): void
    {
        $p     = $this->buatPemohon(2);
        $admin = $this->buatPemohon(1);
        $this->service()->tambah($admin['auth'], $this->isian($p['nip']));

        try {
            $this->service()->tambah($admin['auth'], $this->isian($p['nip']));
            $this->fail('Duplikat harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('nip', $e->getErrors() ?? []);
        }

        $this->assertSame(1, $this->db->table('pegawai_sisa_cuti')->where('nip', $p['nip'])->countAllResults());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function batasD8(): iterable
    {
        yield 'N > 12' => [['sisa_cuti' => 13], 'sisa_cuti'];
        yield 'N-1 > 6' => [['sisa_cuti_n1' => 7], 'sisa_cuti_n1'];
        yield 'N-2 > 6' => [['sisa_cuti_n2' => 7], 'sisa_cuti_n2'];
        yield 'utang > 12' => [['utang_cuti' => 13], 'utang_cuti'];
        yield 'negatif' => [['sisa_cuti' => -1], 'sisa_cuti'];
        yield 'bukan angka' => [['sisa_cuti_n1' => 'abc'], 'sisa_cuti_n1'];
        yield 'kosong wajib' => [['utang_cuti' => ''], 'utang_cuti'];
    }

    /**
     * @param array<string, mixed> $ubah
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('batasD8')]
    public function testBatasD8DitolakPerKolom(array $ubah, string $kolom): void
    {
        $p     = $this->buatPemohon(2);
        $admin = $this->buatPemohon(1);

        try {
            $this->service()->tambah($admin['auth'], $this->isian($p['nip'], $ubah));
            $this->fail('Harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($kolom, $e->getErrors() ?? []);
        }

        $this->assertSame(0, $this->db->table('pegawai_sisa_cuti')->countAllResults());
    }

    public function testBatasTepatDiterimaDanDitangguhkanOpsional(): void
    {
        $p     = $this->buatPemohon(2);
        $admin = $this->buatPemohon(1);

        $this->service()->tambah($admin['auth'], $this->isian($p['nip'], ['utang_cuti' => 12]));

        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => $p['nip'], 'utang_cuti' => 12, 'sisa_cuti_ditangguhkan' => 0]);
    }

    public function testTahunLebihDariTahunIniDitolakDiTambahDanDaftar(): void
    {
        $p     = $this->buatPemohon(2);
        $admin = $this->buatPemohon(1);

        try {
            $this->service()->tambah($admin['auth'], $this->isian($p['nip'], ['tahun' => 2027]));
            $this->fail('Tahun depan harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tahun', $e->getErrors() ?? []);
        }

        try {
            $this->service()->daftar($admin['auth'], ['tahun' => '2027']);
            $this->fail('Daftar tahun depan harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tahun', $e->getErrors() ?? []);
        }
    }

    public function testTargetSemuaHanyaMenambahYangBelumPunyaBarisDanMengabaikanJenisLain(): void
    {
        $a     = $this->buatPemohon(2);
        $b     = $this->buatPemohon(2);
        $lain  = $this->buatPemohon(2, ['id_jenis_pegawai' => 2]);
        $admin = $this->buatPemohon(1);
        $this->buatSaldo($a['nip'], 2026, [1, 1, 1, 1, 0]);

        $hasil = $this->service()->tambah($admin['auth'], $this->isian('', ['target' => 1]));

        $this->assertGreaterThanOrEqual(2, $hasil['dibuat']);
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => $b['nip'], 'tahun' => 2026, 'sisa_cuti' => 12]);
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => $a['nip'], 'tahun' => 2026, 'sisa_cuti' => 1]);
        $this->dontSeeInDatabase('pegawai_sisa_cuti', ['nip' => $lain['nip']]);
    }

    public function testUbahDanHapusFisikDenganAudit(): void
    {
        $p     = $this->buatPemohon(2);
        $admin = $this->buatPemohon(1);
        $this->buatSaldo($p['nip'], 2026);
        $id = (int) $this->db->table('pegawai_sisa_cuti')->where('nip', $p['nip'])->get()->getRowArray()['id_pegawai_sisa_cuti'];

        $ubah = $this->service()->ubah($admin['auth'], $id, $this->isian($p['nip'], ['sisa_cuti' => 5]));
        $this->assertSame(5, (int) $ubah['sisa_cuti']);
        $this->seeInDatabase('audit_logs', ['entity' => 'pegawai_sisa_cuti', 'event' => 'update']);

        $this->assertSame(['deleted' => true, 'id' => $id], $this->service()->hapus($admin['auth'], $id));
        $this->dontSeeInDatabase('pegawai_sisa_cuti', ['id_pegawai_sisa_cuti' => $id]);
        $this->seeInDatabase('audit_logs', ['entity' => 'pegawai_sisa_cuti', 'event' => 'delete']);
    }

    public function testRole3HanyaLingkupSendiriDanRoleLainDitolak(): void
    {
        $admin3 = $this->buatPejabat(3);
        $satker = (int) $this->db->table('pegawai_mutasi_jabatan')->where('nip', $admin3['nip'])->get()->getRowArray()['id_satker'];
        $dalam  = $this->buatPegawaiDiSatker($satker, ['id_jenis_pegawai' => 1]);
        $luar   = $this->buatPemohon(2);

        $this->service()->tambah($admin3['auth'], $this->isian($dalam));
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => $dalam]);

        try {
            $this->service()->tambah($admin3['auth'], $this->isian($luar['nip']));
            $this->fail('Di luar lingkup harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('nip', $e->getErrors() ?? []);
        }

        $this->buatSaldo($luar['nip'], 2026);
        $idLuar = (int) $this->db->table('pegawai_sisa_cuti')->where('nip', $luar['nip'])->get()->getRowArray()['id_pegawai_sisa_cuti'];

        try {
            $this->service()->hapus($admin3['auth'], $idLuar);
            $this->fail('Di luar lingkup harus 404');
        } catch (NotFoundException) {
            $this->addToAssertionCount(1);
        }

        $nip3 = array_column($this->service()->daftar($admin3['auth'], [])['items'], 'nip');
        $this->assertSame([$dalam], $nip3);

        foreach ([$luar['auth']] as $auth) {
            try {
                $this->service()->daftar($auth, []);
                $this->fail('Role 2 harus 403');
            } catch (ForbiddenException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
