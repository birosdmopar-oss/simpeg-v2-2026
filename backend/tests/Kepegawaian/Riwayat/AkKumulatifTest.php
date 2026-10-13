<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Libraries\Kepegawaian\Riwayat\Definisi\Ak;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * AK kumulatif B-15 (WS-1 M5 MAKE-008) dari master `dm_ak_jf` — legacy `L_ak.php` form() :357-405. Tabel `dm_ak_jf`
 * belum ada di skema v2 (kebutuhan DBV), jadi test ini membuatnya sementara dengan DDL legacy (tanpa FK) dan
 * menghapusnya lagi; tanpa tabel itu AK kumulatif = 0.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class AkKumulatifTest extends DatabaseTestCase
{
    use RiwayatUjiTrait;

    /**
     * @var array<string, int>
     */
    private array $pangkat = [];

    private int $jenjangMuda = 0;

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->escapeIdentifiers($this->db->prefixTable('dm_ak_jf')));

        parent::tearDown();
    }

    public function testAkKumulatifKpDanJenjangBerikutDikurangiAkTerakhir(): void
    {
        $nip = $this->siapkanPegawaiJf();

        $this->assertSame(['ak_kum_kp' => 0.0, 'ak_kum_next_jen' => 0.0], array_intersect_key(Ak::turunan($nip), array_flip(['ak_kum_kp', 'ak_kum_next_jen'])), 'tanpa dm_ak_jf');

        $this->db->query('CREATE TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable('dm_ak_jf')) . ' (
            `id_jabatan` int NOT NULL, `id_pangkat` tinyint NOT NULL, `jenjang` varchar(50) DEFAULT NULL, `ak` double NOT NULL,
            PRIMARY KEY (`id_jabatan`, `id_pangkat`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pmj = $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->get()->getRowArray();
        $this->db->table('dm_ak_jf')->insertBatch([
            ['id_jabatan' => $pmj['id_jabatan'], 'id_pangkat' => $this->pangkat['III/a'], 'ak' => 100],
            ['id_jabatan' => $pmj['id_jabatan'], 'id_pangkat' => $this->pangkat['III/b'], 'ak' => 50],
        ]);

        // KP: pangkat ≤ pangkat saat ini (III/a) = 100; jenjang berikut ada → semua pangkat jenjang ini = 150; dikurangi
        // AK terakhir (pegawai_ak jabatan + pangkat sama) 30.
        $turunan = Ak::turunan($nip);
        $this->assertSame([70.0, 120.0], [$turunan['ak_kum_kp'], $turunan['ak_kum_next_jen']]);

        // Tanpa jenjang yang lebih tinggi di sub kelompok yang sama → AK jenjang berikut 0.
        $this->db->table('jabatan')->where('id_jenjang_jf', $this->jenjangMuda)->update(['id_jenjang_jf' => null]);
        $this->assertSame(0.0, Ak::turunan($nip)['ak_kum_next_jen']);
    }

    /**
     * Pegawai berjabatan JF jenjang Pertama (sub kelompok berisi juga jenjang Muda), pangkat III/a, AK terakhir 30.
     */
    private function siapkanPegawaiJf(): string
    {
        foreach (['III/a' => 9, 'III/b' => 10] as $golRuang => $order) {
            $this->db->table('pangkat')->insert(['pangkat' => 'Pangkat ' . $golRuang, 'gol' => 'III', 'ruang' => substr($golRuang, -1), 'gol_ruang' => $golRuang, 'order' => $order, 'status' => 1]);
            $this->pangkat[$golRuang] = (int) $this->db->insertID();
        }

        $this->db->table('jenjang_jf')->insert(['kategori_jf' => 'Ahli', 'jenjang_jf' => 'Pertama Uji', 'extra_nama_jab' => '', 'order' => 1, 'status' => 1]);
        $pertama = (int) $this->db->insertID();
        $this->db->table('jenjang_jf')->insert(['kategori_jf' => 'Ahli', 'jenjang_jf' => 'Muda Uji', 'extra_nama_jab' => '', 'order' => 2, 'status' => 1]);
        $this->jenjangMuda = (int) $this->db->insertID();

        $rantai = $this->buatJabatan();
        $this->db->table('jabatan')->where('id_jabatan', $rantai['id_jabatan'])->update(['id_jenjang_jf' => $pertama]);
        $this->db->table('jabatan')->insert([
            'id_group_jabatan' => $rantai['id_group_jabatan'], 'id_sub_group_jabatan' => $rantai['id_sub_group_jabatan'],
            'id_satker'        => $rantai['id_satker'], 'jabatan' => 'Jabatan Muda Uji', 'id_jenjang_jf' => $this->jenjangMuda, 'status' => 1,
        ]);

        $nip = $this->buatPegawai([], ['id_jabatan' => $rantai['id_jabatan']]);
        $this->db->table('pegawai_kp')->insert([
            'nip'      => $nip, 'id_pangkat' => $this->pangkat['III/a'], 'tmtsk' => '2020-01-01', 'tgl_sk' => '2019-12-01',
            'jenis_kp' => 'Reguler', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Pangkat III/a',
        ]);

        $this->db->table('riwayat_ak')->insert(['nip' => $nip, 'id_jabatan' => $rantai['id_jabatan'], 'id_pangkat' => $this->pangkat['III/a'], 'nilai_ak' => 30, 'status' => 1]);
        $this->db->table('pegawai_ak')->insert([
            'nip'        => $nip, 'id_riwayat_ak' => (int) $this->db->insertID(), 'id_jabatan' => $rantai['id_jabatan'],
            'id_pangkat' => $this->pangkat['III/a'], 'nilai_ak' => 30,
        ]);

        return $nip;
    }
}
