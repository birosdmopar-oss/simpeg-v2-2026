<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Integrasi\Simapi\PencatatPerubahan;
use CodeIgniter\Events\Events;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-060 — kerangka penulis `simapi_ch`. Spesifikasi produksi KOSONG (⚑ D1 ISSUE-056 P14): event apa pun diabaikan. Mekanisme
 * penulisan diuji dengan spesifikasi SINTETIS di test (bukan tebakan trigger legacy).
 *
 * @internal
 */
final class PencatatPerubahanTest extends DatabaseTestCase
{
    private const SPEK_UJI = ['tabel_uji' => ['jenis_data' => 'jenis_uji', 'id' => 'id_x', 'kolom' => ['a', 'b']]];

    public function testSpesifikasiProduksiKosongSehinggaEventNoOp(): void
    {
        $this->assertSame([], PencatatPerubahan::SPESIFIKASI, '⚑ diisi dari D1 (A5b), jangan ditebak');

        Events::trigger(PencatatPerubahan::EVENT, ['tabel' => 'riwayat_kp', 'aksi' => 1, 'baris' => ['nip' => '1']]);

        $this->assertSame(0, $this->db->table('simapi_ch')->countAllResults());
    }

    public function testMenulisBarisPipeDenganSpesifikasiSintetis(): void
    {
        $this->db->table('simapi_jd')->insert(['jenis_data' => 'jenis_uji']);
        $p = new PencatatPerubahan($this->db, self::SPEK_UJI);

        $this->assertTrue($p->catat('tabel_uji', PencatatPerubahan::AKSI_HAPUS, ['id_x' => 9, 'nip' => '123', 'a' => 'x', 'b' => null]));
        $this->assertFalse($p->catat('tabel_lain', PencatatPerubahan::AKSI_SIMPAN, ['id_x' => 1]), 'tabel tanpa spesifikasi diabaikan');
        $this->assertFalse($p->catat('tabel_uji', 7, ['id_x' => 1]), 'aksi tidak sah diabaikan');

        $r = $this->db->table('simapi_ch')->get()->getRowArray();
        $this->assertSame(['9', '123', '2', 'a|b', 'x|'], [$r['id_rwy'], $r['nip'], (string) $r['action'], $r['field_name'], $r['field_value']]);
        $this->assertLessThanOrEqual(5, abs(strtotime($r['action_at'] . ' UTC') - time()), 'action_at UTC');
    }

    public function testTanpaSeedJenisDataDanAkunTanpaNipTidakDicatat(): void
    {
        $p = new PencatatPerubahan($this->db, self::SPEK_UJI + ['pengguna' => ['jenis_data' => 'jenis_uji', 'id' => 'id_pengguna', 'kolom' => ['username']]]);

        $this->assertFalse($p->catat('tabel_uji', 1, ['id_x' => 1]), 'simapi_jd belum di-seed (A7)');
        $this->db->table('simapi_jd')->insert(['jenis_data' => 'jenis_uji']);
        $this->assertFalse($p->catat('pengguna', 1, ['id_pengguna' => 5, 'nip' => null, 'username' => 'sistem.integrasi']), 'akun sistem (CR-093)');
        $this->assertSame(0, $this->db->table('simapi_ch')->countAllResults());
    }
}
