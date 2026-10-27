<?php

declare(strict_types=1);

namespace Tests\Support\Dashboard;

use App\Libraries\CacheService;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * Fixture statistik F-03 (MAKE-036) di atas PegawaiFixtureTrait: dua unit aktif (A: satker A1, A2; B: satker B1), satu
 * unit nonaktif (C: satker C1), master pangkat/gol PPPK/jenjang/sub group struktural, plus pegawai sintetis dengan
 * snapshot `pegawai_kp` / `pegawai_pendidikan`. Cache diganti MockCache per test agar deterministik dan tidak bocor
 * antar test (tearDown: Services::reset()).
 *
 * Hanya INSERT lewat query builder di bingkai transaksi uji MAKE-001 (tanpa DDL/commit).
 */
trait StatistikUjiTrait
{
    /** @var array<string, int> */
    protected array $unit = [];

    /** @var array<string, int> */
    protected array $satker = [];

    /** @var array<string, int> */
    protected array $pangkat = [];

    /** @var array<string, int> */
    protected array $golPppk = [];

    /** @var array<string, int> */
    protected array $jenjang = [];

    protected int $subGroupEselon2 = 0;

    protected int $subGroupEselon3 = 0;

    protected CacheService $cacheUji;

    protected function pasangCacheUji(): void
    {
        $this->cacheUji = new CacheService(new MockCache(), '');
        Services::injectMock('cacheService', $this->cacheUji);
    }

    /**
     * Master unit/satker/pangkat/gol PPPK/jenjang/sub group untuk seluruh skenario statistik.
     */
    protected function siapkanMasterStatistik(): void
    {
        // jenis_pegawai (FK pegawai.id_jenis_pegawai): 1 PNS, 2 PTT, 6 PPPK [K]; tabel kosong sesudah migrate.
        foreach ([1 => 'Pegawai Negeri Sipil', 2 => 'Pegawai Tidak Tetap', 6 => 'PPPK'] as $id => $nama) {
            if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', $id)->countAllResults() === 0) {
                $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => $id, 'jenis_pegawai' => $nama, 'order' => $id, 'status' => 1]);
            }
        }

        $this->unit['A'] = $this->buatUnit(['unit' => 'Unit A', 'order' => 1]);
        $this->unit['B'] = $this->buatUnit(['unit' => 'Unit B', 'order' => 2]);
        $this->unit['C'] = $this->buatUnit(['unit' => 'Unit C Nonaktif', 'order' => 3, 'status' => 0]);

        $this->satker['A1'] = $this->buatSatker($this->unit['A'], ['satker' => 'Satker A1', 'order' => 1]);
        $this->satker['A2'] = $this->buatSatker($this->unit['A'], ['satker' => 'Satker A2', 'order' => 2]);
        $this->satker['B1'] = $this->buatSatker($this->unit['B'], ['satker' => 'Satker B1', 'order' => 1]);
        $this->satker['C1'] = $this->buatSatker($this->unit['C'], ['satker' => 'Satker C1', 'order' => 1]);

        foreach ([['III/a', 'III', 'a', 9], ['CPNS III/a', 'III', 'a', 29], ['IV/a', 'IV', 'a', 13], ['II/a', 'II', 'a', 5]] as [$golRuang, $gol, $ruang, $order]) {
            $this->db->table('pangkat')->insert(['pangkat' => 'Pangkat ' . $golRuang, 'gol' => $gol, 'ruang' => $ruang, 'gol_ruang' => $golRuang, 'order' => $order, 'status' => 1]);
            $this->pangkat[$golRuang] = (int) $this->db->insertID();
        }

        foreach ([['V', 5], ['VII', 7]] as [$nama, $order]) {
            $this->db->table('gol_pppk')->insert(['gol_pppk' => $nama, 'order' => $order, 'status' => 1]);
            $this->golPppk[$nama] = (int) $this->db->insertID();
        }

        foreach ([['S1', 6], ['S2', 7]] as [$singkat, $order]) {
            $this->db->table('jenjang_pendidikan')->insert(['jenjang_pendidikan_singkat' => $singkat, 'jenjang_pendidikan' => 'Jenjang ' . $singkat, 'order' => $order, 'status' => 1]);
            $this->jenjang[$singkat] = (int) $this->db->insertID();
        }

        // Group struktural = id 1 [K]; sub group = eselon (label dari master, F7-8).
        if ($this->db->table('group_jabatan')->where('id_group_jabatan', 1)->countAllResults() === 0) {
            $this->db->table('group_jabatan')->insert(['id_group_jabatan' => 1, 'group_jabatan' => 'Struktural', 'status' => 1]);
        }

        $this->db->table('sub_group_jabatan')->insert(['id_group_jabatan' => 1, 'sub_group_jabatan' => 'Eselon II', 'status' => 1]);
        $this->subGroupEselon2 = (int) $this->db->insertID();
        $this->db->table('sub_group_jabatan')->insert(['id_group_jabatan' => 1, 'sub_group_jabatan' => 'Eselon III', 'status' => 1]);
        $this->subGroupEselon3 = (int) $this->db->insertID();
    }

    /**
     * Pegawai di satker $kodeSatker dengan atribut statistik. $opsi: jenis (1 PNS/2 PTT/6 PPPK), jk (1/2), lahir
     * ('Y-m-d'), status (1/2), pangkat (kunci $this->pangkat), gol_pppk (kunci $this->golPppk), jenjang (kunci
     * $this->jenjang), eselon (1 = subGroupEselon2, 2 = subGroupEselon3), group (id_group_jabatan pmj).
     *
     * @param array<string, mixed> $opsi
     */
    protected function pegawaiStatistik(string $kodeSatker, array $opsi = []): string
    {
        $override = [
            'id_jenis_pegawai' => $opsi['jenis'] ?? 1,
            'jenis_kelamin'    => $opsi['jk'] ?? 1,
            'tgl_lahir'        => $opsi['lahir'] ?? '1985-06-15',
            'status'           => $opsi['status'] ?? 1,
        ];
        $pmj = ['id_satker' => $this->satker[$kodeSatker]];

        if (isset($opsi['eselon'])) {
            $pmj['id_group_jabatan']     = 1;
            $pmj['id_sub_group_jabatan'] = $opsi['eselon'] === 1 ? $this->subGroupEselon2 : $this->subGroupEselon3;
        } elseif (isset($opsi['group'])) {
            $pmj['id_group_jabatan'] = $opsi['group'];
        }

        if (isset($opsi['gol_pppk'])) {
            $pmj['id_gol_pppk'] = $this->golPppk[$opsi['gol_pppk']];
        }

        $nip = $this->buatPegawaiDiSatker($this->satker[$kodeSatker], $override);

        if ($pmj !== ['id_satker' => $this->satker[$kodeSatker]]) {
            unset($pmj['id_satker']);
            $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->update($pmj);
        }

        if (isset($opsi['pangkat'])) {
            [$gol, $ruang] = explode('/', str_replace('CPNS ', '', $opsi['pangkat'])) + [1 => ''];
            $this->db->table('pegawai_kp')->insert([
                'nip'      => $nip, 'id_pangkat' => $this->pangkat[$opsi['pangkat']], 'tmtsk' => '2020-04-01', 'tgl_sk' => '2020-03-15',
                'jenis_kp' => 'Reguler', 'gol' => $gol, 'ruang' => $ruang, 'gol_ruang' => $opsi['pangkat'], 'pangkat' => 'Pangkat ' . $opsi['pangkat'],
            ]);
        }

        if (isset($opsi['jenjang'])) {
            $this->db->table('pegawai_pendidikan')->insert(['nip' => $nip, 'id_jenjang_pendidikan' => $this->jenjang[$opsi['jenjang']], 'jenjang_pendidikan_singkat' => $opsi['jenjang'], 'tgl_lulus' => '2008-08-20']);
        }

        return $nip;
    }

    /**
     * Bearer akun fixture (bukan seed) untuk request HTTP.
     */
    protected function bearerAkun(int $idPengguna): string
    {
        /** @var array<string, mixed> $akun */
        $akun = $this->db->table('pengguna')->where('id_pengguna', $idPengguna)->get()->getRowArray();

        return 'Bearer ' . $this->issueTokensForUser($akun)['access_token'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function dataStatistik(TestResponse $result): array
    {
        $result->assertStatus(200);

        return $this->json($result)['data'];
    }

    /**
     * Nilai baris berlabel $label sebagai peta key seri → jumlah.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, int>
     */
    protected function nilaiBaris(array $data, string $label): array
    {
        foreach ($data['baris'] as $b) {
            if ($b['label'] === $label) {
                return array_combine(array_column($data['seri'], 'key'), $b['nilai']);
            }
        }

        $this->fail("Baris {$label} tidak ada: " . json_encode(array_column($data['baris'], 'label')));
    }
}
