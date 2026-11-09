<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Integrasi\Simapi\AturanTrigger as T;
use App\Libraries\Integrasi\Simapi\PencatatPerubahan as P;
use Config\Database;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-060 A5b (opsi B) — penulis `simapi_ch` setara trigger: di dalam transaksi pemilik ditulis di transaksi yang sama lewat
 * SAVEPOINT; (a) rollback → 0 baris; (b) bersarang → terlihat koneksi lain hanya sesudah commit terluar; (c) insert log gagal →
 * transaksi bisnis tetap commit; (d) kegagalan bisnis sebelumnya tidak ditutupi; nip kosong dilewati (N2); lookup NIP keluarga.
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

    private function bisnis(string $nama): void
    {
        $this->db->table('agama')->insert(['agama' => $nama]);
    }

    public function testLuarTransaksiLangsungDitulis(): void
    {
        P::catat($this->db, 'riwayat_kgb', T::INSERT, null, $this->kgb());

        $r = $this->db->table('simapi_ch')->get()->getRowArray();
        $this->assertSame(['11', self::NIP, '7', '1'], [(string) $r['id_simapi_jd'], $r['nip'], (string) $r['id_rwy'], (string) $r['action']]);
        $this->assertLessThanOrEqual(5, abs(strtotime($r['action_at'] . ' UTC') - time()), 'action_at UTC');
    }

    public function testRollbackLewatExceptionMenghapusBaris(): void
    {
        try {
            $this->db->transBegin();
            $this->bisnis('Uji A');
            P::catat($this->db, 'riwayat_kgb', T::INSERT, null, $this->kgb());

            throw new RuntimeException('galat bisnis');
        } catch (RuntimeException) {
            $this->db->transRollback();
        }

        $this->assertSame(0, $this->jumlah(), '(a) rollback (exception, tanpa penanganan khusus) → 0 baris');
        $this->db->transBegin();
        P::catat($this->db, 'riwayat_kgb', T::UPDATE, $this->kgb(), $this->kgb(['status' => '2']));
        $this->db->transCommit();
        $this->assertSame(1, $this->jumlah(), 'commit berikutnya hanya membawa barisnya sendiri');
    }

    public function testInsertLogGagalTransaksiBisnisTetapCommit(): void
    {
        $this->db->table('simapi_jd')->where('id_simapi_jd', 11)->delete(); // FK simapi_ch menolak

        $this->db->transBegin();
        $this->bisnis('Uji C');
        P::catat($this->db, 'riwayat_kgb', T::INSERT, null, $this->kgb());
        $this->assertTrue($this->db->transStatus(), 'status bisnis dipulihkan');
        $this->db->transCommit();

        $this->assertSame(0, $this->jumlah());
        $this->assertSame(1, $this->db->table('agama')->where('agama', 'Uji C')->countAllResults(), '(c) data bisnis tersimpan');
    }

    public function testKegagalanBisnisSebelumnyaTidakDitutupi(): void
    {
        $this->db->transBegin();
        $this->db->query('SELECT * FROM tabel_tidak_ada_xyz'); // kegagalan bisnis (DBDebug di transaksi: status false)
        $this->assertFalse($this->db->transStatus());
        P::catat($this->db, 'riwayat_kgb', T::INSERT, null, $this->kgb());

        $this->assertFalse($this->db->transStatus(), '(d) status gagal tidak di-reset');
        $this->db->transRollback();
        $this->db->resetTransStatus();
        $this->assertSame(0, $this->jumlah());
    }

    public function testNipKosongDanDetailAnak(): void
    {
        P::catat($this->db, 'riwayat_kgb', T::INSERT, null, $this->kgb(['nip' => '']));
        $this->assertSame(0, $this->jumlah(), 'nip kosong tidak ditulis (N2)');

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

    public function testBacaGagalDalamTransaksiTidakMenggagalkanBisnis(): void
    {
        $this->db->transBegin();
        $this->bisnis('Uji P3a');
        $this->assertNull(P::baca($this->db, 'pegawai', 'kolom_tidak_ada', 1), 'SELECT gagal → null');
        $this->assertTrue($this->db->transStatus(), 'status bisnis dipulihkan (P3)');
        $this->db->transCommit();

        $this->assertSame(1, $this->db->table('agama')->where('agama', 'Uji P3a')->countAllResults());
    }

    /**
     * Review P3: lookup di dalam catat() (NIP keluarga) gagal → savepoint dipulihkan, bisnis tetap commit, 0 baris log.
     */
    #[Group('db-isolasi-penuh')]
    public function testLookupGagalDalamTransaksiBisnisTetapCommit(): void
    {
        $asli = $this->db->prefixTable('riwayat_keluarga');
        $this->db->query("RENAME TABLE {$asli} TO {$asli}_p3");

        try {
            $this->db->transBegin();
            $this->bisnis('Uji P3b');
            P::catat($this->db, 'detail_anak', T::INSERT, null, ['id_detail_anak' => '9', 'id_riwayat_keluarga' => '55', 'nama' => 'Anak']);
            $this->assertTrue($this->db->transStatus(), 'status bisnis dipulihkan');
            $this->db->transCommit();
        } finally {
            $this->db->query("RENAME TABLE {$asli}_p3 TO {$asli}");
        }

        $this->assertSame(1, $this->db->table('agama')->where('agama', 'Uji P3b')->countAllResults(), 'bisnis tersimpan');
        $this->assertSame(0, $this->jumlah(), '0 baris log');
        $this->db->table('agama')->where('agama', 'Uji P3b')->delete();
    }

    #[Group('db-isolasi-penuh')]
    public function testBersarangTerlihatKoneksiLainSesudahCommitTerluar(): void
    {
        $lain   = Database::connect(config(Database::class)->tests, false);
        $hitung = fn (): int => (int) $lain->query('SELECT COUNT(*) AS n FROM ' . $lain->prefixTable('simapi_ch'))->getRowArray()['n'];

        try {
            $this->db->transBegin();
            $this->db->transBegin();
            P::catat($this->db, 'riwayat_kgb', T::INSERT, null, $this->kgb());
            $this->db->transCommit();
            $this->assertSame(0, $hitung(), 'transaksi luar masih terbuka');
            $this->db->transCommit();

            $this->assertSame(1, $hitung(), '(b) terlihat sesudah commit terluar');
        } finally {
            $this->db->table('simapi_ch')->where('nip', self::NIP)->delete();
            $lain->close();
        }
    }
}
