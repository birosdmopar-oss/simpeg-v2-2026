<?php

declare(strict_types=1);

namespace Tests\Database\Sintetis;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Sintetis\GeneratorRiwayat;
use Tests\Support\Sintetis\ProfilSintetis;

/**
 * Generator data sintetis riwayat dalam (CR-168, izin #18.3): jumlah baris = laporan generator, semua NIP berawalan
 * `9999`, deterministik per benih, memuat kasus tmtsk kembar / pegawai keluar / riwayat dalam, LKH hanya hari kerja
 * tanpa (nip, tanggal) ganda. Profil `kecil` bawaan; `SINTETIS_PROFIL=riset` menyemai profil riset (±1,6 juta LKH) dan
 * mencetak durasi. Di-skip kecuali env `RUN_VOLUME_TEST=1`. Isi semai ikut di-ROLLBACK bingkai `DatabaseTestCase`; DB scratch
 * untuk EXPLAIN #18.3 disemai oleh pemanggil yang commit (lihat docblock `GeneratorRiwayat`).
 *
 * @internal
 */
#[Group('volume')]
final class GeneratorRiwayatSintetisTest extends DatabaseTestCase
{
    private const TABEL = ['pegawai', 'riwayat_mutasi_jabatan', 'riwayat_kp', 'riwayat_lckh'];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_VOLUME_TEST') !== '1') {
            $this->markTestSkipped('Generator data sintetis hanya diuji dengan env RUN_VOLUME_TEST=1.');
        }
    }

    public function testJumlahNipDanKasusRiwayat(): void
    {
        $profil = getenv('SINTETIS_PROFIL') === 'riset' ? ProfilSintetis::riset() : ProfilSintetis::kecil();
        $mulai  = hrtime(true);
        $jumlah = (new GeneratorRiwayat($this->db))->semai($profil);
        fwrite(STDERR, "\n[sintetis] " . json_encode($jumlah + ['detik' => round((hrtime(true) - $mulai) / 1e9, 1)]) . "\n");

        foreach (self::TABEL as $t) {
            $this->assertSame($jumlah[$t], $this->db->table($t)->countAllResults(), "jumlah {$t}");
            $this->assertSame(0, $this->db->table($t)->notLike('nip', GeneratorRiwayat::AWALAN_NIP, 'after')->countAllResults(), "NIP sintetis {$t}");
        }

        $this->assertSame($profil->jumlahPegawai, $jumlah['pegawai']);
        $this->assertSame(0, $this->db->table('pegawai')->where('CHAR_LENGTH(nip) !=', 18, false)->countAllResults());
        $this->assertGreaterThan(0, $this->db->table('pegawai')->where('status', 2)->where('tmt_status IS NOT NULL')->countAllResults(), 'pegawai keluar');

        foreach (['riwayat_mutasi_jabatan', 'riwayat_kp'] as $t) {
            $this->assertGreaterThan(0, $this->kembar($t), "tmtsk kembar {$t}");
            $this->assertSame(0, $this->tanpaAktif($t), "tiap NIP punya baris aktif {$t}");
        }

        // R1: mayoritas NIP punya jabatan nomenklatur aktif (jenis 1), dan kasus kembar terlihat oleh kueri jenis 1.
        $rmj    = $this->db->prefixTable('riwayat_mutasi_jabatan');
        $jenis1 = (int) $this->db->query("SELECT COUNT(DISTINCT nip) AS n FROM {$rmj} WHERE status = 1 AND jenis_jabatan = 1")->getRow()->n;
        $this->assertGreaterThanOrEqual((int) ceil($profil->jumlahPegawai * 0.7), $jenis1, 'NIP dengan baris jenis_jabatan 1');
        $this->assertGreaterThan(0, $this->kembar('riwayat_mutasi_jabatan', 'jenis_jabatan = 1'), 'tmtsk kembar jenis 1');

        $per = $this->perNip('riwayat_mutasi_jabatan');
        $this->assertGreaterThanOrEqual($profil->mutasi[0] * $profil->faktorDalam, $per[GeneratorRiwayat::nip(1)], 'riwayat dalam');

        $lkh = $this->db->prefixTable('riwayat_lckh');
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) - COUNT(DISTINCT nip, tgl_laporan) AS n FROM {$lkh}")->getRow()->n, 'LKH (nip, tanggal) unik');
        $this->assertSame(0, $this->db->table('riwayat_lckh')->whereIn('DAYOFWEEK(tgl_laporan)', [1, 7], false)->countAllResults(), 'LKH hari kerja');
        $this->assertSame($profil->lkh * $profil->faktorDalam, $this->perNip('riwayat_lckh')[GeneratorRiwayat::nip(1)]);
        $this->assertGreaterThan(0, $this->db->table('riwayat_lckh')->where('status', 0)->where('nip_atasan IS NOT NULL')->countAllResults(), 'LKH menunggu atasan');
    }

    public function testDeterministikPerBenih(): void
    {
        $gen = new GeneratorRiwayat($this->db, 7);
        $gen->semai(ProfilSintetis::kecil(11));
        $a = $this->sidik();

        foreach (array_reverse(self::TABEL) as $t) {
            $this->db->table($t)->like('nip', GeneratorRiwayat::AWALAN_NIP, 'after')->delete();
        }

        $gen->semai(ProfilSintetis::kecil(11));
        $this->assertSame($a, $this->sidik(), 'benih sama → isi sama (ukuran batch tidak berpengaruh)');

        foreach (array_reverse(self::TABEL) as $t) {
            $this->db->table($t)->like('nip', GeneratorRiwayat::AWALAN_NIP, 'after')->delete();
        }

        (new GeneratorRiwayat($this->db))->semai(ProfilSintetis::kecil(12));
        $this->assertNotSame($a, $this->sidik(), 'benih lain → isi lain');
    }

    /** Jumlah NIP yang punya ≥ 2 baris aktif dengan tmtsk terbaru yang sama. */
    private function kembar(string $tabel, string $saring = '1 = 1'): int
    {
        $t = $this->db->prefixTable($tabel);

        return (int) $this->db->query("SELECT COUNT(*) AS n FROM (SELECT r.nip FROM {$t} r JOIN (SELECT nip, MAX(tmtsk) m FROM {$t} WHERE status = 1 AND {$saring} GROUP BY nip) x ON x.nip = r.nip AND x.m = r.tmtsk WHERE r.status = 1 AND {$saring} GROUP BY r.nip HAVING COUNT(*) > 1) k")->getRow()->n;
    }

    private function tanpaAktif(string $tabel): int
    {
        $t = $this->db->prefixTable($tabel);
        $p = $this->db->prefixTable('pegawai');

        return (int) $this->db->query("SELECT COUNT(*) AS n FROM {$p} p WHERE NOT EXISTS (SELECT 1 FROM {$t} r WHERE r.nip = p.nip AND r.status = 1)")->getRow()->n;
    }

    /**
     * @return array<string, int>
     */
    private function perNip(string $tabel): array
    {
        $baris = $this->db->table($tabel)->select('nip, COUNT(*) AS n')->groupBy('nip')->get()->getResultArray();

        return array_combine(array_column($baris, 'nip'), array_map('intval', array_column($baris, 'n')));
    }

    /** Sidik isi tanpa PK auto-increment (PK bisa bergeser sesudah DELETE). */
    private function sidik(): string
    {
        $isi = [
            $this->db->table('pegawai')->select('nip, status, tmt_status, tgl_lahir')->orderBy('nip')->get()->getResultArray(),
            $this->db->table('riwayat_mutasi_jabatan')->select('nip, tmtsk, status, jenis_jabatan, jabatan, kelas_jabatan')->orderBy('nip, tmtsk, jabatan, status')->get()->getResultArray(),
            $this->db->table('riwayat_kp')->select('nip, tmtsk, gol_ruang, status')->orderBy('nip, tmtsk, gol_ruang, status')->get()->getResultArray(),
            $this->db->table('riwayat_lckh')->select('nip, tgl_laporan, status, nip_atasan, show_notif')->orderBy('nip, tgl_laporan')->get()->getResultArray(),
        ];

        return hash('sha256', (string) json_encode($isi));
    }
}
