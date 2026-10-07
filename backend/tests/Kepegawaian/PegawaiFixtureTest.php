<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * S0-A (MAKE-002) — PegawaiFixtureTrait: pegawai + snapshot jabatan + akun yang lolos FK (termasuk kolom FK DBV-019
 * `pegawai_mutasi_jabatan` → G-02 dan `pengguna.nip` → `pegawai`, terpasang atau belum), dan cocok dengan base case
 * MAKE-001 (hanya INSERT; data hilang saat transaksi test di-rollback).
 *
 * @internal
 */
final class PegawaiFixtureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use PegawaiFixtureTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    /**
     * Kolom FK anak → [tabel induk, kolom induk] yang diisi fixture: FK G-02 DBV-019 (`pegawai_mutasi_jabatan`),
     * FK `pegawai_mutasi_jabatan.nip` (DBV-012), dan `pengguna.nip` → `pegawai` (DBV-019).
     */
    private const RUJUKAN = [
        ['pegawai_mutasi_jabatan', 'nip', 'pegawai', 'nip'],
        ['pegawai_mutasi_jabatan', 'id_group_jabatan', 'group_jabatan', 'id_group_jabatan'],
        ['pegawai_mutasi_jabatan', 'id_sub_group_jabatan', 'sub_group_jabatan', 'id_sub_group_jabatan'],
        ['pegawai_mutasi_jabatan', 'id_unit', 'unit', 'id_unit'],
        ['pegawai_mutasi_jabatan', 'id_satker', 'satker', 'id_satker'],
        ['pegawai_mutasi_jabatan', 'id_jabatan', 'jabatan', 'id_jabatan'],
        ['pegawai_mutasi_jabatan', 'id_atasan_es_1', 'jabatan', 'id_jabatan'],
        ['pegawai_mutasi_jabatan', 'id_atasan_es_2', 'jabatan', 'id_jabatan'],
        ['pegawai_mutasi_jabatan', 'id_atasan_es_3', 'jabatan', 'id_jabatan'],
        ['pegawai_mutasi_jabatan', 'id_atasan_es_4', 'jabatan', 'id_jabatan'],
        ['pegawai_mutasi_jabatan', 'id_jabatan_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi'],
        ['pegawai_mutasi_jabatan', 'id_atasan_es_3_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi'],
        ['pegawai_mutasi_jabatan', 'id_atasan_es_4_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi'],
        ['pegawai_mutasi_jabatan', 'id_rumpun_jabatan', 'rumpun_jabatan', 'id_rumpun_jabatan'],
        ['pegawai_mutasi_jabatan', 'id_riwayat_mutasi_jabatan', 'riwayat_mutasi_jabatan', 'id_riwayat_mutasi_jabatan'],
        ['satker', 'id_unit', 'unit', 'id_unit'],
        ['jabatan', 'id_satker', 'satker', 'id_satker'],
        ['jabatan', 'id_group_jabatan', 'group_jabatan', 'id_group_jabatan'],
        ['jabatan', 'id_sub_group_jabatan', 'sub_group_jabatan', 'id_sub_group_jabatan'],
        ['sub_group_jabatan', 'id_group_jabatan', 'group_jabatan', 'id_group_jabatan'],
        ['pengguna', 'nip', 'pegawai', 'nip'],
    ];

    public function testPegawaiPmjAkunLolosFk(): void
    {
        $nip     = $this->buatPegawai(['nama' => 'Budi Fixture']);
        $idAkun  = $this->buatAkunUntuk($nip, Role::PEGAWAI);
        $idAdmin = $this->buatAkunUntuk($this->buatPegawai(), Role::SUPER_ADMIN);

        $this->assertMatchesRegularExpression('/^\d{18}$/', $nip);

        $pegawai = $this->db->table('pegawai')->where('nip', $nip)->get()->getRowArray();
        $this->assertIsArray($pegawai);
        $this->assertSame('Budi Fixture', $pegawai['nama']);
        $this->assertSame('1990-01-01', $pegawai['tgl_lahir']);

        $pmj = $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->get()->getRowArray();
        $this->assertIsArray($pmj);

        foreach (['id_unit', 'id_satker', 'id_group_jabatan', 'id_sub_group_jabatan', 'id_jabatan'] as $kolom) {
            $this->assertNotNull($pmj[$kolom], "pmj.{$kolom} terisi");
        }

        $satker = $this->db->table('satker')->where('id_satker', $pmj['id_satker'])->get()->getRowArray();
        $this->assertSame((int) $pmj['id_unit'], (int) $satker['id_unit'], 'satker berada di unit pmj');

        $akun = $this->db->table('pengguna')->where('id_pengguna', $idAkun)->get()->getRowArray();
        $this->assertSame($nip, $akun['nip']);
        $this->assertSame($nip, $akun['username']);
        $this->assertSame(Role::PEGAWAI, (int) $akun['user_level']);
        $this->assertSame((string) $pmj['id_unit'], $akun['id_unit']);
        $this->assertSame((string) $pmj['id_satker'], $akun['id_satker']);

        $auth = $this->authUntukAkun($idAdmin);
        $this->assertSame(Role::SUPER_ADMIN, $auth->role());
        $this->assertSame($idAdmin, $auth->idPengguna());

        $this->assertSame([], $this->yatim());
    }

    public function testPegawaiDiSatkerYangSama(): void
    {
        $idSatker = $this->buatSatker();
        $nipA     = $this->buatPegawaiDiSatker($idSatker);
        $nipB     = $this->buatPegawaiDiSatker($idSatker);
        $nipLain  = $this->buatPegawai();

        $this->assertNotSame($nipA, $nipB);

        $satkerPmj = $this->db->table('pegawai_mutasi_jabatan')->select('nip, id_satker')
            ->whereIn('nip', [$nipA, $nipB, $nipLain])->get()->getResultArray();
        $peta = array_column($satkerPmj, 'id_satker', 'nip');

        $this->assertSame($idSatker, (int) $peta[$nipA]);
        $this->assertSame($idSatker, (int) $peta[$nipB]);
        $this->assertNotSame($idSatker, (int) $peta[$nipLain]);

        $idAdminSatker = $this->buatAkunUntuk($nipA, Role::ADMIN_SATKER);
        $this->assertSame((string) $idSatker, $this->authUntukAkun($idAdminSatker)->idSatker());

        $this->assertSame([], $this->yatim());
    }

    public function testRollbackTransaksiMenghapusDataFixture(): void
    {
        // Simulasi base case MAKE-001 (transaksi per test): semua tulisan fixture ikut rollback.
        $hitung = fn (): array => array_map(
            fn (string $tabel): int => $this->db->table($tabel)->countAllResults(),
            ['pegawai', 'pegawai_mutasi_jabatan', 'pengguna', 'unit', 'satker', 'jabatan'],
        );
        $awal = $hitung();

        $this->db->transBegin();
        $nip = $this->buatPegawai();
        $this->buatAkunUntuk($nip, Role::PPPK);
        $this->assertNotSame($awal, $hitung());
        $this->db->transRollback();

        $this->assertSame($awal, $hitung());
    }

    public function testFixtureHanyaInsert(): void
    {
        $sumber = (string) file_get_contents(SUPPORTPATH . 'Kepegawaian/PegawaiFixtureTrait.php');
        // Abaikan komentar: docblock menyebut kata terlarang sebagai larangan.
        $kode = implode('', array_map(
            static fn ($token): string => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token),
            token_get_all($sumber),
        ));

        foreach (['->delete(', '->update(', '->replace(', '->emptyTable(', '->truncate(', '->query(', 'transCommit', 'transBegin', 'forge', 'migrate', 'TRUNCATE', 'DELETE ', 'DROP '] as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $kode, "fixture tidak boleh memakai {$terlarang}");
        }
    }

    /**
     * Nilai FK yang tidak NULL dan tidak ada di induknya.
     *
     * @return list<string>
     */
    private function yatim(): array
    {
        $masalah = [];

        foreach (self::RUJUKAN as [$tabel, $kolom, $induk, $kolomInduk]) {
            $anak = $this->db->prefixTable($tabel);
            $ref  = $this->db->prefixTable($induk);
            $n    = (int) ($this->db->query(
                "SELECT COUNT(*) AS n FROM `{$anak}` c WHERE c.`{$kolom}` IS NOT NULL
                 AND NOT EXISTS (SELECT 1 FROM `{$ref}` p WHERE p.`{$kolomInduk}` = c.`{$kolom}`)",
            )->getRowArray()['n'] ?? 0);

            if ($n > 0) {
                $masalah[] = "{$tabel}.{$kolom} → {$induk}: {$n}";
            }
        }

        return $masalah;
    }
}
