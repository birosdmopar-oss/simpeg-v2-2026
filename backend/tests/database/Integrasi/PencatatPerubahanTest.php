<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Integrasi\Simapi\AturanTrigger as T;
use App\Libraries\Integrasi\Simapi\PencatatPerubahan as P;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-060 A5b — penulis `simapi_ch`: tulis langsung di luar transaksi; di dalam transaksi diantrekan dan ditulis hanya sesudah
 * commit TERLUAR; rollback membuang antrean; galat tulis tidak dilempar; nip kosong tidak ditulis (DBV-031 N2); lookup NIP
 * keluarga untuk `detail_anak`. Seed `simapi_jd` dari DBV-031.
 *
 * @internal
 */
final class PencatatPerubahanTest extends DatabaseTestCase
{
    private const NIP = '000000000000000001';

    /**
     * @return array<string, mixed>
     */
    private function kgb(array $lebih = []): array
    {
        return $lebih + ['id_riwayat_kgb' => '7', 'nip' => self::NIP, 'status' => '1', 'tmtsk' => '2020-01-01', 'jym' => 'Kepala'];
    }

    private function jumlah(): int
    {
        return $this->db->table('simapi_ch')->countAllResults();
    }

    protected function tearDown(): void
    {
        P::batal($this->db);
        parent::tearDown();
    }

    public function testLuarTransaksiLangsungDitulis(): void
    {
        P::catat($this->db, 'riwayat_kgb', T::INSERT, null, $this->kgb());

        $r = $this->db->table('simapi_ch')->get()->getRowArray();
        $this->assertSame(['11', self::NIP, '7', '1'], [(string) $r['id_simapi_jd'], $r['nip'], (string) $r['id_rwy'], (string) $r['action']]);
        $this->assertSame('id_rwy|nip|tmtsk|tgl_sk|no_sk|mker_gol_th|gaji_pokok|jabatan_yang_menandatangani', $r['field_name']);
        $this->assertLessThanOrEqual(5, abs(strtotime($r['action_at'] . ' UTC') - time()), 'action_at UTC');
    }

    public function testRollbackTidakMenulis(): void
    {
        $this->db->transBegin();
        P::catat($this->db, 'riwayat_kgb', T::INSERT, null, $this->kgb());
        $this->assertSame(1, P::tertunda($this->db));
        $this->db->transRollback();
        P::batal($this->db);
        P::setelahCommit($this->db);

        $this->assertSame(0, $this->jumlah(), '(a) rollback → 0 baris');
    }

    public function testBersarangDitulisSekaliSesudahCommitTerluar(): void
    {
        $this->db->transBegin();
        $this->db->transBegin();
        P::catat($this->db, 'riwayat_kgb', T::INSERT, null, $this->kgb());
        $this->db->transCommit();
        P::setelahCommit($this->db); // penulis dalam: transaksi luar masih terbuka
        $this->assertSame(0, $this->jumlah(), 'belum ditulis selama transaksi luar terbuka');

        P::catat($this->db, 'riwayat_kgb', T::UPDATE, $this->kgb(), $this->kgb(['status' => '2']));
        $this->db->transCommit();
        P::setelahCommit($this->db);
        P::setelahCommit($this->db); // pemanggilan ganda tidak menulis ulang

        $this->assertSame(2, $this->jumlah(), '(b) ditulis sekali sesudah commit terluar');
        $this->assertSame(0, P::tertunda($this->db));
    }

    public function testGalatTulisTidakDilemparDanNipKosongDilewati(): void
    {
        P::catat($this->db, 'riwayat_kgb', T::INSERT, null, $this->kgb(['nip' => '']));
        $this->assertSame(0, $this->jumlah(), 'nip kosong tidak ditulis (N2)');

        $this->db->table('simapi_jd')->where('id_simapi_jd', 11)->delete(); // FK menolak tulisan
        P::catat($this->db, 'riwayat_kgb', T::INSERT, null, $this->kgb());
        $this->assertSame(0, $this->jumlah(), '(c) galat FK hanya dilog, operasi tidak gagal');
    }

    public function testDetailAnakMemakaiNipKeluarga(): void
    {
        $this->db->table('pegawai')->insert(['nip' => self::NIP, 'nama' => 'Uji', 'tgl_lahir' => '1990-01-01', 'jenis_kelamin' => 1, 'status' => 1]);
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->table('riwayat_keluarga')->insert(['id_riwayat_keluarga' => 55, 'nip' => self::NIP, 'status' => 1, 'tgl_perkawinan' => '2015-01-01', 'nama_pasangan' => 'Pasangan']);
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        P::catat($this->db, 'detail_anak', T::INSERT, null, ['id_detail_anak' => '9', 'id_riwayat_keluarga' => '55', 'nama' => 'Anak']);
        P::catat($this->db, 'detail_anak', T::DELETE, ['id_detail_anak' => '9', 'id_riwayat_keluarga' => '55'], null);
        P::catat($this->db, 'detail_anak', T::INSERT, null, ['id_detail_anak' => '10', 'id_riwayat_keluarga' => '999']);

        $rows = $this->db->table('simapi_ch')->orderBy('id_simapi_ch')->get()->getResultArray();
        $this->assertSame([[self::NIP, '9', '1'], [self::NIP, '9', '2']], array_map(static fn (array $r): array => [$r['nip'], (string) $r['id_rwy'], (string) $r['action']], $rows), 'keluarga tidak ada → tidak ditulis');
    }
}
