<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * S0-A (MAKE-002) — PegawaiFixtureTrait: pegawai + snapshot jabatan + akun yang lolos FK (termasuk kolom FK DBV-019
 * snapshot/riwayat → G-02 dan `pengguna.nip` → `pegawai`, terpasang atau belum), dan berjalan di base case MAKE-001
 * DatabaseTestCase tanpa tanda isolasi penuh (hanya INSERT; data hilang saat bingkai transaksi uji di-rollback).
 *
 * @internal
 */
final class PegawaiFixtureTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    /**
     * Prefiks kelas migration DBV-019 (PR #24, `AddFkG02Pegawai`, `AddFkG02Riwayat`): bila sudah di main dan termuat oleh
     * migrate, daftar FOREIGN_KEYS-nya ikut dicek.
     */
    private const PREFIKS_MIGRATION_DBV019 = 'App\Database\Migrations\AddFkG02';

    /**
     * Kolom FK anak → [tabel induk, kolom induk] yang diisi fixture: FK G-02 DBV-019 (snapshot & riwayat jabatan),
     * FK snapshot yang sudah di main (DBV-012/013), master G-02, dan `pengguna.nip` → `pegawai` (DBV-019).
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
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
        ['pegawai_mutasi_jabatan', 'id_gol_pppk', 'gol_pppk', 'id_gol_pppk'],
        ['riwayat_mutasi_jabatan', 'nip', 'pegawai', 'nip'],
        ['riwayat_mutasi_jabatan', 'id_group_jabatan', 'group_jabatan', 'id_group_jabatan'],
        ['riwayat_mutasi_jabatan', 'id_sub_group_jabatan', 'sub_group_jabatan', 'id_sub_group_jabatan'],
        ['riwayat_mutasi_jabatan', 'id_unit', 'unit', 'id_unit'],
        ['riwayat_mutasi_jabatan', 'id_satker', 'satker', 'id_satker'],
        ['riwayat_mutasi_jabatan', 'id_jabatan', 'jabatan', 'id_jabatan'],
        ['riwayat_mutasi_jabatan', 'id_jabatan_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi'],
        ['riwayat_mutasi_jabatan', 'id_rumpun_jabatan', 'rumpun_jabatan', 'id_rumpun_jabatan'],
        ['riwayat_mutasi_jabatan', 'id_gol_pppk', 'gol_pppk', 'id_gol_pppk'],
        ['jabatan_koordinasi', 'id_unit', 'unit', 'id_unit'],
        ['jabatan_koordinasi', 'id_satker', 'satker', 'id_satker'],
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

        foreach (['id_unit', 'id_satker', 'id_group_jabatan', 'id_sub_group_jabatan', 'id_jabatan', 'unit', 'satker', 'jabatan', 'group_jabatan', 'sub_group_jabatan'] as $kolom) {
            $this->assertNotNull($pmj[$kolom], "pmj.{$kolom} terisi");
        }

        $satker = $this->db->table('satker')->where('id_satker', $pmj['id_satker'])->get()->getRowArray();
        $this->assertSame((int) $pmj['id_unit'], (int) $satker['id_unit'], 'satker berada di unit pmj');
        $this->assertSame($satker['satker'], $pmj['satker'], 'kolom teks snapshot = nama master');

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

    public function testIdJabatanDiberikanMenurunkanRantai(): void
    {
        $jabatan = $this->buatJabatan();
        $nip     = $this->buatPegawai([], ['id_jabatan' => $jabatan['id_jabatan']]);

        $pmj = $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->get()->getRowArray();

        foreach ($jabatan as $kolom => $id) {
            $this->assertSame($id, (int) $pmj[$kolom], "pmj.{$kolom} diturunkan dari jabatan");
        }

        $this->assertNotNull($pmj['unit']);
        $idAkun = $this->buatAkunUntuk($nip, Role::PEGAWAI);
        $this->assertSame((string) $jabatan['id_satker'], $this->authUntukAkun($idAkun)->idSatker());
    }

    public function testHelperMasterDbv019DanRiwayatMutasiJabatan(): void
    {
        $idSatker = $this->buatSatker();
        $idKoord  = $this->buatJabatanKoordinasi($idSatker);
        $idRumpun = $this->buatRumpunJabatan();
        $atasan   = $this->buatJabatan($idSatker)['id_jabatan'];
        $nip      = $this->buatPegawai([], [
            'id_satker'         => $idSatker,
            'id_jabatan_koord'  => $idKoord,
            'id_rumpun_jabatan' => $idRumpun,
            'id_atasan_es_2'    => $atasan,
        ]);

        $idRmj = $this->buatRiwayatMutasiJabatan($nip, ['id_jabatan_koord' => $idKoord]);
        $rmj   = $this->db->table('riwayat_mutasi_jabatan')->where('id_riwayat_mutasi_jabatan', $idRmj)->get()->getRowArray();
        $pmj   = $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->get()->getRowArray();

        $this->assertSame($nip, $rmj['nip']);
        $this->assertSame(1, (int) $rmj['status']);
        $this->assertSame((int) $pmj['id_jabatan'], (int) $rmj['id_jabatan'], 'riwayat memakai jabatan snapshot');
        $this->assertSame($idSatker, (int) $rmj['id_satker']);

        $this->assertSame([], $this->yatim());
    }

    public function testTglLahirNipPendekDanNik(): void
    {
        foreach (['12345', '060012345', '3201010101900001', '199013452015011001'] as $nip) {
            $this->buatPegawai(['nip' => $nip]);
            $tgl = $this->db->table('pegawai')->select('tgl_lahir')->where('nip', $nip)->get()->getRowArray()['tgl_lahir'];
            $this->assertSame('1990-01-01', $tgl, "NIP {$nip}");
        }

        $this->buatPegawai(['nip' => '198512312010011001']);
        $this->assertSame('1985-12-31', $this->db->table('pegawai')->select('tgl_lahir')->where('nip', '198512312010011001')->get()->getRowArray()['tgl_lahir']);
    }

    public function testAkunUntukNipTanpaPegawaiDitolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak ada');
        $this->buatAkunUntuk('999999999999999999', Role::PEGAWAI);
    }

    public function testRiwayatUntukNipTanpaPegawaiDitolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->buatRiwayatMutasiJabatan('999999999999999999');
    }

    public function testRollbackTransaksiMenghapusDataFixture(): void
    {
        // Transaksi aplikasi di dalam bingkai uji MAKE-001 (SAVEPOINT): semua tulisan fixture ikut rollback.
        $hitung = fn (): array => array_map(
            fn (string $tabel): int => $this->db->table($tabel)->countAllResults(),
            ['pegawai', 'pegawai_mutasi_jabatan', 'pengguna', 'unit', 'satker', 'jabatan', 'riwayat_mutasi_jabatan'],
        );
        $awal = $hitung();

        $this->db->transBegin();
        $nip = $this->buatPegawai();
        $this->buatAkunUntuk($nip, Role::PPPK);
        $this->buatRiwayatMutasiJabatan($nip);
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

        $terlarang = [
            '->delete(', '->deleteBatch(', '->update(', '->updateBatch(', '->replace(', '->upsert(', '->upsertBatch(',
            '->emptyTable(', '->truncate(', '->query(', '->simpleQuery(', 'transCommit', 'transBegin', 'transComplete',
            'forge', 'migrate', 'TRUNCATE', 'DELETE ', 'DROP ', 'ALTER ', 'CREATE ',
        ];

        foreach ($terlarang as $token) {
            $this->assertStringNotContainsString($token, $kode, "fixture tidak boleh memakai {$token}");
        }
    }

    /**
     * Nilai FK yang tidak NULL dan tidak ada di induknya (daftar RUJUKAN + FK migration DBV-019 bila sudah termuat).
     *
     * @return list<string>
     */
    private function yatim(): array
    {
        $rujukan = self::RUJUKAN;

        foreach (get_declared_classes() as $kelas) {
            if (str_starts_with($kelas, self::PREFIKS_MIGRATION_DBV019) && defined($kelas . '::FOREIGN_KEYS')) {
                /** @var array<string, array{0: string, 1: string, 2: string, 3: string}> $fks */
                $fks = constant($kelas . '::FOREIGN_KEYS');
                array_push($rujukan, ...array_values($fks));
            }
        }

        $masalah = [];

        foreach ($rujukan as [$tabel, $kolom, $induk, $kolomInduk]) {
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
