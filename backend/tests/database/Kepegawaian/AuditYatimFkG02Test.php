<?php

declare(strict_types=1);

namespace Tests\Database\Kepegawaian;

use App\Database\Migrations\AddFkG02Pegawai;
use App\Database\Migrations\AddFkG02Riwayat;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\SkemaD1;
use Tests\Support\Kepegawaian\SkemaD1TestTrait;

/**
 * DBV-021 (ISSUE-045): berkas audit `DBV-021-audit-yatim-fk-g02-per-tabel.sql` mencakup tepat ke-55 FK G-02 migration
 * DBV-019 (nama FK, kolom anak, induk), berjalan di skema v2, bernilai 0 di DB bersih, dan menghitung nilai yatim
 * (`0` dan id asing; NULL bukan yatim). Contoh nilai hanya id master. Data sintetis.
 *
 * @internal
 */
final class AuditYatimFkG02Test extends DatabaseTestCase
{
    use SkemaD1TestTrait;

    private const BERKAS = 'docs/db-review/sql/DBV-021-audit-yatim-fk-g02-per-tabel.sql';

    private const NIP = '999900000000000021';

    /** `konv_ak` ber-PK `nip`: baris kedua butuh pegawai kedua. */
    private const NIP_2 = '999900000000000022';

    private const ID_ASING = 424242;

    public function testBerkasMencakupTepat55FkMigration(): void
    {
        [$ringkasan, $contoh] = $this->bagian();
        $this->assertCount(10, $ringkasan, 'satu kueri ringkasan per tabel anak');
        $this->assertCount(10, $contoh);

        $diharapkan = [...AddFkG02Pegawai::FOREIGN_KEYS, ...AddFkG02Riwayat::FOREIGN_KEYS];
        $this->assertCount(55, $diharapkan);

        $ditemukan = [];

        foreach ($ringkasan as $q) {
            $this->assertSame(1, preg_match('/FROM\s+(\w+)\s+c\b/', $q, $m), $q);
            $anak = $m[1];

            preg_match_all('/SUM\(c\.(\w+)\s+IS NOT NULL AND (p\d+)\.(\w+)\s+IS NULL\), 0\) AS `(\w+)`/', $q, $kolom, PREG_SET_ORDER);

            foreach ($kolom as [, $kol, $alias, $pk, $fk]) {
                $this->assertSame(1, preg_match('/LEFT JOIN (\w+)\s+' . $alias . ' ON ' . $alias . '\.' . $pk . ' = c\.' . $kol . '\b/', $q), "{$fk}: join induk");
                preg_match('/LEFT JOIN (\w+)\s+' . $alias . ' /', $q, $j);
                $ditemukan[$fk] = [$anak, $kol, $j[1], $pk];
            }
        }

        ksort($diharapkan);
        ksort($ditemukan);
        $this->assertSame($diharapkan, $ditemukan, 'Bagian A = konstanta FOREIGN_KEYS DBV-019');

        $fkContoh = [];

        foreach ($contoh as $q) {
            preg_match_all("/SELECT '(\\w+)' AS fk/", $q, $m);
            $fkContoh = [...$fkContoh, ...$m[1]];
        }

        sort($fkContoh);
        $this->assertSame(array_keys($ditemukan), $fkContoh, 'Bagian B memuat semua FK');
    }

    public function testDbBersihNolDanYatimTerhitung(): void
    {
        foreach ($this->jalankan(0) as $baris) {
            foreach ($this->kolomFk($baris) as $fk => $n) {
                $this->assertSame(0, $n, "DB bersih: {$fk}");
            }
        }

        // Nilai yatim hanya bisa ditanam dengan FK nonaktif (sama dengan impor ber-FOREIGN_KEY_CHECKS = 0).
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $this->db->table('pegawai')->insert(['nip' => self::NIP, 'nama' => 'Pegawai Uji DBV-021', 'tgl_lahir' => '1990-01-01']);
            $this->db->table('pegawai')->insert(['nip' => self::NIP_2, 'nama' => 'Pegawai Uji DBV-021 B', 'tgl_lahir' => '1990-01-01']);
            $this->db->table('pegawai_mutasi_jabatan')->insert($this->barisMinimal(SkemaD1::B01['pegawai_mutasi_jabatan'], [
                'nip' => self::NIP, 'id_jabatan' => self::ID_ASING, 'id_atasan_es_1' => 0, 'id_unit' => null,
            ]));
            $this->db->table('konv_ak')->insert($this->barisMinimal(SkemaD1::B02['konv_ak'], ['nip' => self::NIP, 'id_unit' => self::ID_ASING]));
            $this->db->table('konv_ak')->insert($this->barisMinimal(SkemaD1::B02['konv_ak'], ['nip' => self::NIP_2, 'id_unit' => self::ID_ASING]));
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        $hasil = [];

        foreach ($this->jalankan(0) as $baris) {
            $hasil[$baris['tabel_anak']] = $this->kolomFk($baris);
        }

        $this->assertSame(1, $hasil['pegawai_mutasi_jabatan']['fk_id_jabatan_pmj_to_jabatan']);
        $this->assertSame(1, $hasil['pegawai_mutasi_jabatan']['fk_id_atasan_es_1_pmj_to_jabatan'], 'nilai 0 = yatim');
        $this->assertSame(0, $hasil['pegawai_mutasi_jabatan']['fk_id_unit_pmj_to_unit'], 'NULL bukan yatim');
        $this->assertSame(2, $hasil['konv_ak']['fk_id_unit_konvak_to_unit'], 'dihitung per baris');
        $this->assertSame(0, $hasil['konv_ak']['fk_id_jabatan_konvak_to_jabatan']);
        $this->assertSame(4, array_sum(array_map('array_sum', $hasil)), 'tidak ada FK lain yang ikut terhitung');

        $contoh = [];

        foreach ($this->jalankan(1) as $baris) {
            $contoh[$baris['fk']] = [(int) $baris['nilai_yatim_berbeda'], $baris['contoh_nilai']];
        }

        $this->assertSame([1, (string) self::ID_ASING], $contoh['fk_id_unit_konvak_to_unit'], 'nilai berbeda dihitung sekali; hanya id master');
        $this->assertSame([1, '0'], $contoh['fk_id_atasan_es_1_pmj_to_jabatan']);
        $this->assertSame([0, null], $contoh['fk_id_satker_konvak_to_satker']);
        $this->assertStringNotContainsString(self::NIP, (string) json_encode($contoh), 'contoh tanpa NIP');
        $this->assertStringNotContainsString(self::NIP_2, (string) json_encode($contoh));
    }

    /**
     * @return array{0: list<string>, 1: list<string>} [Bagian A, Bagian B]
     */
    private function bagian(): array
    {
        $isi = (string) file_get_contents(ROOTPATH . self::BERKAS);
        $a   = strpos($isi, '-- Bagian A');
        $b   = strpos($isi, '-- Bagian B');
        $this->assertNotFalse($a);
        $this->assertNotFalse($b);

        return [$this->kueri(substr($isi, $a, $b - $a)), $this->kueri(substr($isi, $b))];
    }

    /**
     * @return list<string>
     */
    private function kueri(string $isi): array
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $isi);

        return array_values(array_filter(array_map('trim', explode(';', $sql))));
    }

    /**
     * Jalankan semua kueri satu bagian (0 = A, 1 = B) dengan nama tabel diberi prefix test.
     *
     * @return list<array<string, mixed>>
     */
    private function jalankan(int $bagian): array
    {
        $tabel = [];

        foreach ([...AddFkG02Pegawai::FOREIGN_KEYS, ...AddFkG02Riwayat::FOREIGN_KEYS] as [$anak, , $induk]) {
            $tabel[$anak]  = true;
            $tabel[$induk] = true;
        }

        $hasil = [];

        foreach ($this->bagian()[$bagian] as $q) {
            foreach (array_keys($tabel) as $t) {
                $q = (string) preg_replace('/\b(FROM|JOIN)\s+' . $t . '\b/', '$1 ' . $this->db->prefixTable($t), $q);
            }

            $hasil = [...$hasil, ...$this->db->query($q)->getResultArray()];
        }

        return $hasil;
    }

    /**
     * @param array<string, mixed> $baris
     *
     * @return array<string, int>
     */
    private function kolomFk(array $baris): array
    {
        unset($baris['tabel_anak'], $baris['baris']);

        return array_map('intval', $baris);
    }
}
