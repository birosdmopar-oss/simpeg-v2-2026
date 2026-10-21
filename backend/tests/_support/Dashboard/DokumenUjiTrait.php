<?php

declare(strict_types=1);

namespace Tests\Support\Dashboard;

use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\TestResponse;
use CodeIgniter\Throttle\Throttler;
use Config\Services;

/**
 * Fixture dokumen F-09 (MAKE-037) di atas StatistikUjiTrait: pegawai dengan snapshot tambahan (CPNS/PNS, diklat, KGB,
 * tanda jasa), kelas jabatan & umur pensiun pada master jabatan, throttler MockCache (tiap test mulai dari nol).
 * Hanya INSERT/UPDATE lewat query builder di bingkai transaksi uji.
 */
trait DokumenUjiTrait
{
    use StatistikUjiTrait;

    /** @var array<string, int> */
    protected array $kelasJabatan = [];

    protected function pasangThrottlerUji(): void
    {
        Services::injectMock('throttler', new Throttler(new MockCache()));
    }

    /**
     * Master statistik + penyesuaian dokumen: order CPNS III/a di bawah III/a (realistis), kelas jabatan 9 & 12.
     */
    protected function siapkanMasterDokumen(): void
    {
        // Eselon memakai id sub group 1–4 pada group struktural 1 [K `getEselon`; Pensiun::ID_SUB_GROUP_ESELON_I_II].
        if ($this->db->table('group_jabatan')->where('id_group_jabatan', 1)->countAllResults() === 0) {
            $this->db->table('group_jabatan')->insert(['id_group_jabatan' => 1, 'group_jabatan' => 'Struktural', 'status' => 1]);
        }

        foreach ([1 => 'E.I (uji)', 2 => 'E.II (uji)', 3 => 'E.III (uji)', 4 => 'E.IV (uji)'] as $id => $nama) {
            if ($this->db->table('sub_group_jabatan')->where('id_sub_group_jabatan', $id)->countAllResults() === 0) {
                $this->db->table('sub_group_jabatan')->insert(['id_sub_group_jabatan' => $id, 'id_group_jabatan' => 1, 'sub_group_jabatan' => $nama, 'status' => 1]);
            }
        }

        $this->siapkanMasterStatistik();
        $this->subGroupEselon2 = 2;
        $this->subGroupEselon3 = 3;
        $this->db->table('pangkat')->where('id_pangkat', $this->pangkat['CPNS III/a'])->update(['order' => 8]);

        foreach ([9, 12] as $kelas) {
            if ($this->db->table('kelas_jabatan')->where('kelas_jabatan', $kelas)->countAllResults() === 0) {
                $this->db->table('kelas_jabatan')->insert(['kelas_jabatan' => $kelas, 'tukin' => $kelas * 1000000, 'status' => 1]);
            }

            $this->kelasJabatan[(string) $kelas] = $kelas;
        }
    }

    /**
     * Pegawai dokumen: opsi StatistikUjiTrait::pegawaiStatistik + `nama`, `tmt_pangkat`, `cpns` (TMT), `pns` (TMT),
     * `diklat`, `kgb` (TMT), `tanda_jasa`, `kelas` (kelas jabatan master), `umur_pensiun` (master jabatan).
     *
     * @param array<string, mixed> $opsi
     */
    protected function pegawaiDokumen(string $kodeSatker, array $opsi = []): string
    {
        $nip = $this->pegawaiStatistik($kodeSatker, $opsi);

        if (isset($opsi['nama'])) {
            $this->db->table('pegawai')->where('nip', $nip)->update(['nama' => $opsi['nama']]);
        }

        if (isset($opsi['tmt_pangkat'])) {
            $this->db->table('pegawai_kp')->where('nip', $nip)->update(['tmtsk' => $opsi['tmt_pangkat'], 'tgl_sk' => $opsi['tmt_pangkat']]);
        }

        foreach (['cpns' => 'pegawai_cpns', 'pns' => 'pegawai_pns'] as $k => $tabel) {
            if (isset($opsi[$k])) {
                // Snapshot CPNS/PNS ber-FK ke riwayat_kp (DBV-013): sisipkan riwayat sumbernya dulu.
                $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'tmtsk' => $opsi[$k], 'tgl_sk' => $opsi[$k], 'status' => 1]);
                $idRiwayat = (int) $this->db->insertID();
                $this->db->table($tabel)->insert(['nip' => $nip, 'id_riwayat_kp' => $idRiwayat, 'tmtsk' => $opsi[$k], 'tgl_sk' => $opsi[$k], 'jenis_kp' => strtoupper($k), 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda']);
            }
        }

        if (isset($opsi['diklat'])) {
            $this->db->table('pegawai_diklat')->insert(['nip' => $nip, 'nama_diklat' => $opsi['diklat'], 'tgl_sertifikat' => '2021-06-01']);
        }

        if (isset($opsi['kgb'])) {
            $this->db->table('pegawai_kgb')->insert(['nip' => $nip, 'tmtsk' => $opsi['kgb'], 'tgl_sk' => $opsi['kgb']]);
        }

        if (isset($opsi['tanda_jasa'])) {
            $this->db->table('pegawai_tanda_jasa')->insert(['nip' => $nip, 'tanda_jasa' => $opsi['tanda_jasa'], 'tgl_sertifikat' => '2025-08-17']);
        }

        if (isset($opsi['kelas']) || isset($opsi['umur_pensiun'])) {
            $idJabatan = (int) $this->db->table('pegawai_mutasi_jabatan')->select('id_jabatan')->where('nip', $nip)->get()->getRow('id_jabatan');
            $update    = [];

            if (isset($opsi['kelas'])) {
                $update['kelas_jabatan'] = $this->kelasJabatan[(string) $opsi['kelas']];
            }

            if (isset($opsi['umur_pensiun'])) {
                $update['umur_pensiun'] = $opsi['umur_pensiun'];
            }

            $this->db->table('jabatan')->where('id_jabatan', $idJabatan)->update($update);
        }

        return $nip;
    }

    /**
     * @return array<string, mixed>
     */
    protected function dataDokumen(TestResponse $result): array
    {
        $result->assertStatus(200);

        return $this->json($result)['data'];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    protected function namaItems(array $data): array
    {
        return array_map(static fn (array $i): string => (string) $i['nama'], $data['items']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function auditUnduhan(string $jenis): array
    {
        return $this->db->table('audit_logs')->where('entity', 'dokumen_unduhan')->where('entity_id', $jenis)->orderBy('id_log', 'ASC')->get()->getResultArray();
    }
}
