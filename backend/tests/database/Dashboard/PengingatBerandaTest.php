<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use App\Libraries\Dashboard\Beranda\ProyeksiTandaJasa;
use App\Libraries\Integrasi\Stat\KalenderStat;
use App\Libraries\Integrasi\Stat\RekomendasiKgb;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Dashboard;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-087 (F-13 M2) — 4 kartu pengingat beranda admin (`pengingat` di `GET dashboard/ringkasan`), ikut `L_user::dashboard_*`
 * + `L_employee::get_*` legacy: Pensiun tahun berjalan; Tanda Jasa kementerian (≤ tahun acuan, acuan tahun depan bila bulan ≥ 9)
 * vs satker (tepat 1 Agustus tahun berjalan/depan, proyeksi tahun berjalan); KGB kementerian PNS + PPPK vs satker PNS;
 * label & urutan kartu legacy. NIP sintetis.
 *
 * @internal
 */
final class PengingatBerandaTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    private const URL = 'api/v1/dashboard/ringkasan';

    private int $unit;

    private int $satker;

    private int $idPangkat;

    protected function setUp(): void
    {
        parent::setUp();
        config(Dashboard::class)->ttlRingkasanDetik = 0;

        foreach ([1 => 'PNS', 6 => 'PPPK'] as $id => $nama) {
            if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', $id)->countAllResults() === 0) {
                $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => $id, 'jenis_pegawai' => $nama, 'status' => 1]);
            }
        }

        foreach ([ProyeksiTandaJasa::ID_XXX => 'SL XXX', ProyeksiTandaJasa::ID_XX => 'SL XX', ProyeksiTandaJasa::ID_X => 'SL X'] as $id => $nama) {
            if ($this->db->table('tanda_jasa')->where('id_tanda_jasa', $id)->countAllResults() === 0) {
                $this->db->table('tanda_jasa')->insert(['id_tanda_jasa' => $id, 'tanda_jasa' => $nama, 'status' => 1]);
            }
        }

        if ($this->db->table('jenis_kp')->where('id_jenis_kp', 3)->countAllResults() === 0) {
            $this->db->table('jenis_kp')->insert(['id_jenis_kp' => 3, 'jenis_kp' => 'Reguler', 'status' => 1]);
        }

        $this->db->table('pangkat')->insert(['pangkat' => 'Penata Muda', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'order' => 16, 'status' => 1]);
        $this->idPangkat = (int) $this->db->insertID();

        $this->unit   = $this->buatUnit();
        $this->satker = $this->buatSatker($this->unit);
    }

    protected function tearDown(): void
    {
        config(Dashboard::class)->ttlRingkasanDetik = 900;
        Time::setTestNow();
        Services::reset(true);
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testUrutanLabelKartuLegacy(): void
    {
        Time::setTestNow('2026-05-10 09:00:00', 'Asia/Jakarta');
        $data = $this->ringkasan($this->kementerian());

        $this->assertSame(['pensiun', 'kp', 'tj', 'kgb'], array_column($data['pengingat'], 'kode'));
        $this->assertSame(['Pensiun', 'Naik Pangkat', 'Tanda Jasa', 'KGB'], array_column($data['pengingat'], 'label'));
        $this->assertSame([0, 0, 0, 0], array_column($data['pengingat'], 'jumlah'));
    }

    public function testPensiunTahunBerjalanPnsBerjabatan(): void
    {
        Time::setTestNow('2026-05-10 09:00:00', 'Asia/Jakarta');
        $this->pns(['tgl_lahir' => '1968-03-15']);                   // BUP 58 → 1 April 2026
        $this->pns(['tgl_lahir' => '1969-03-15']);                   // 2027
        $this->bup58($this->buatPegawaiDiSatker($this->satker, ['id_jenis_pegawai' => 6, 'tgl_lahir' => '1968-03-15'])); // PPPK: bukan BUP

        $this->assertSame(1, $this->kartu($this->ringkasan($this->kementerian()), 'pensiun'));
        $this->assertSame(1, $this->kartu($this->ringkasan($this->adminSatker()), 'pensiun'));
    }

    public function testTandaJasaKementerianVsSatkerSebelumSeptember(): void
    {
        Time::setTestNow('2026-05-10 09:00:00', 'Asia/Jakarta');
        $this->pnsCpns('2016-03-01');                                // X → 2026-08-01
        $this->pnsCpns('2005-03-01');                                // XX → 2025-08-01 (terlewat, belum diberikan)
        $punya = $this->pnsCpns('2016-03-01');                       // sudah punya X → tidak ada
        $this->tandaJasa($punya, ProyeksiTandaJasa::ID_X);
        $this->pnsCpns('2016-09-01');                                // 9 tahun → tidak ada

        $this->assertSame(2, $this->kartu($this->ringkasan($this->kementerian()), 'tj'), 'kementerian: ≤ tahun acuan');
        $this->assertSame(1, $this->kartu($this->ringkasan($this->adminSatker()), 'tj'), 'satker: tepat 1 Agustus tahun berjalan');
    }

    public function testTandaJasaMulaiSeptemberAcuanTahunDepan(): void
    {
        Time::setTestNow('2026-10-10 09:00:00', 'Asia/Jakarta');
        $this->pnsCpns('2016-03-01');                                // kementerian acuan 2027 → X 2026-08-01 ≤ 2027
        $this->pnsCpns('2016-09-01');                                // kementerian: 10 th per 2027-08-01 → X 2027-08-01

        $this->assertSame(2, $this->kartu($this->ringkasan($this->kementerian()), 'tj'));
        // [K] satker: proyeksi tetap tahun berjalan (2016-09-01 baru 9 th), sasaran 2027-08-01 → tidak ada yang cocok.
        $this->assertSame(0, $this->kartu($this->ringkasan($this->adminSatker()), 'tj'));
    }

    public function testKgbKementerianTermasukPppkSatkerHanyaPns(): void
    {
        Time::setTestNow('2026-05-10 09:00:00', 'Asia/Jakarta');
        $pns  = $this->pnsCpns('2024-03-01');
        $pppk = $this->buatPegawaiDiSatker($this->satker, ['id_jenis_pegawai' => 6]);
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $pppk)->update(['tmtsk' => '2024-03-01']);

        $oracle = new RekomendasiKgb('2026-05-10');
        $jatuh  = static fn (array $r): int => ($k = $oracle->hitung($r)['tmtsk_kgb']) !== '' && KalenderStat::tahunLegacy($k) <= 2026 ? 1 : 0;
        $hPns   = $jatuh(['nip' => $pns, 'id_jenis_pegawai' => 1, 'tmtsk_kgb' => null, 'tmtsk_cpns' => '2024-03-01', 'gol_ruang' => 'III/a', 'tmtsk_pmj' => '2015-01-01', 'gol_pppk' => null]);
        $hPppk  = $jatuh(['nip' => $pppk, 'id_jenis_pegawai' => 6, 'tmtsk_kgb' => null, 'tmtsk_cpns' => null, 'gol_ruang' => null, 'tmtsk_pmj' => '2024-03-01', 'gol_pppk' => null]);
        $this->assertSame([1, 1], [$hPns, $hPppk], 'prasyarat fixture: keduanya jatuh tempo menurut kalkulasi legacy');

        $this->assertSame(2, $this->kartu($this->ringkasan($this->kementerian()), 'kgb'), 'kementerian: PNS + PPPK');
        $this->assertSame(1, $this->kartu($this->ringkasan($this->adminSatker()), 'kgb'), 'satker: PNS saja');
    }

    public function testLingkupSatkerMenyaringPegawaiSatkerLain(): void
    {
        Time::setTestNow('2026-05-10 09:00:00', 'Asia/Jakarta');
        $this->pns(['tgl_lahir' => '1968-03-15']);
        $this->bup58($this->buatPegawaiDiSatker($this->buatSatker($this->unit), ['id_jenis_pegawai' => 1, 'tgl_lahir' => '1968-03-15']));

        $this->assertSame(2, $this->kartu($this->ringkasan($this->kementerian()), 'pensiun'));
        $this->assertSame(1, $this->kartu($this->ringkasan($this->adminSatker()), 'pensiun'));
        $this->assertSame(2, $this->kartu($this->ringkasan($this->adminUnit()), 'pensiun'), 'unit mencakup semua satker di unit itu');
    }

    /**
     * @param array<string, mixed> $override
     */
    private function pns(array $override = []): string
    {
        return $this->bup58($this->buatPegawaiDiSatker($this->satker, array_merge(['id_jenis_pegawai' => 1], $override)));
    }

    /**
     * BUP tetap 58 lewat `jabatan.umur_pensiun` (id grup/subgrup jabatan fixture bisa jatuh ke "struktural Eselon I/II").
     */
    private function bup58(string $nip): string
    {
        $pmj = $this->db->table('pegawai_mutasi_jabatan')->select('id_jabatan')->where('nip', $nip)->get()->getRowArray();
        $this->db->table('jabatan')->where('id_jabatan', $pmj['id_jabatan'])->update(['umur_pensiun' => 58]);

        return $nip;
    }

    private function pnsCpns(string $tmtCpns): string
    {
        $nip = $this->pns();
        $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'tmtsk' => $tmtCpns, 'id_jenis_kp' => 3, 'id_pangkat' => $this->idPangkat, 'status' => 1]);
        $this->db->table('pegawai_cpns')->insert([
            'nip'      => $nip, 'id_riwayat_kp' => (int) $this->db->insertID(), 'tmtsk' => $tmtCpns, 'tgl_sk' => $tmtCpns,
            'jenis_kp' => 'CPNS', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda',
        ]);

        return $nip;
    }

    private function tandaJasa(string $nip, int $id): void
    {
        $this->db->table('riwayat_tanda_jasa')->insert(['nip' => $nip, 'id_tanda_jasa' => $id, 'tgl_sertifikat' => '2020-08-17', 'status' => 1]);
    }

    /**
     * @return array<string, mixed>
     */
    private function kementerian(): array
    {
        return $this->buatAkunTanpaNip('admin.pengingat.' . bin2hex(random_bytes(3)), Role::SUPER_ADMIN);
    }

    /**
     * @return array<string, mixed>
     */
    private function adminSatker(): array
    {
        return $this->buatAkunTanpaNip('satker.pengingat.' . bin2hex(random_bytes(3)), Role::ADMIN_SATKER, ['id_unit' => (string) $this->unit, 'id_satker' => (string) $this->satker]);
    }

    /**
     * @return array<string, mixed>
     */
    private function adminUnit(): array
    {
        return $this->buatAkunTanpaNip('unit.pengingat.' . bin2hex(random_bytes(3)), Role::ADMIN_SATKER, ['id_unit' => (string) $this->unit, 'id_satker' => null]);
    }

    /**
     * @param array<string, mixed> $akun
     *
     * @return array<string, mixed>
     */
    private function ringkasan(array $akun): array
    {
        $res = $this->asUser($akun)->get(self::URL);
        $res->assertStatus(200);

        return json_decode($res->getJSON(), true)['data'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function kartu(array $data, string $kode): int
    {
        return array_column($data['pengingat'], 'jumlah', 'kode')[$kode];
    }
}
