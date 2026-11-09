<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Kgb;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Kp;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * MAKE-060 A5b — pemancar log SIMAPI di RiwayatEngine (alur nyata): baris `simapi_ch` sama dengan trigger legacy — tambah
 * disetujui → action 1; ubah status 1 → action 1; ubah ke status 2 → action 2; hapus lunak (status 10) → tidak ada; transaksi gagal
 * → tidak ada baris.
 *
 * @internal
 */
final class SimapiPemancarRiwayatTest extends DatabaseTestCase
{
    use RiwayatUjiTrait;

    private string $nip;

    private AuthContext $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pasangEngineNyata([new Kgb(), new Kp()]);
        $this->siapkanMasterKp();
        $idSatker    = $this->buatSatker();
        $this->nip   = $this->buatPegawaiDiSatker($idSatker);
        $this->admin = $this->aktor(Role::SUPER_ADMIN)[1];
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();
        parent::tearDown();
    }

    private function engine(): RiwayatEngine
    {
        /** @var RiwayatEngine $e */
        $e = service('riwayatService');

        return $e;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(string $tmt): array
    {
        return ['tmtsk' => $tmt, 'no_sk' => 'SK/' . $tmt, 'tgl_sk' => $tmt, 'mker_gol_th' => 8, 'gaji_pokok' => 3100000, 'jym' => 'Kepala Biro SDM'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ch(): array
    {
        return $this->db->table('simapi_ch')->where('id_simapi_jd', 11)->orderBy('id_simapi_ch')->get()->getResultArray();
    }

    public function testTambahDisetujuiDanHapusLunak(): void
    {
        $row = $this->engine()->tambah($this->admin, $this->nip, 'kgb', $this->data('2022-04-01'), [Kgb::LAMPIRAN_SK_KGB => $this->berkasUji('sk.pdf')]);

        $ch = $this->ch();
        $this->assertCount(1, $ch);
        $this->assertSame([$this->nip, (string) $row['id_riwayat_kgb'], '1'], [$ch[0]['nip'], (string) $ch[0]['id_rwy'], (string) $ch[0]['action']]);
        $this->assertSame($row['id_riwayat_kgb'] . '|' . $this->nip . '|2022-04-01|2022-04-01|SK/2022-04-01|8|3100000|Kepala Biro SDM', $ch[0]['field_value']);

        $this->engine()->hapus($this->admin, $this->nip, 'kgb', (int) $row['id_riwayat_kgb']);
        $this->assertCount(1, $this->ch(), 'hapus lunak status 10 tidak menulis [K]');
    }

    public function testUbahDisetujuiLaluDitolak(): void
    {
        $row = $this->engine()->tambah($this->admin, $this->nip, 'kgb', $this->data('2022-04-01'), [Kgb::LAMPIRAN_SK_KGB => $this->berkasUji('sk.pdf')]);
        $id  = (int) $row['id_riwayat_kgb'];

        $this->engine()->ubah($this->admin, $this->nip, 'kgb', $id, ['jym' => 'Sekretaris'] + $this->data('2022-04-01'));
        $this->engine()->ubah($this->admin, $this->nip, 'kgb', $id, ['status' => 2, 'reason_note' => 'Berkas kurang'] + $this->data('2022-04-01'));

        $ch = $this->ch();
        $this->assertSame(['1', '1', '2'], array_map(static fn (array $r): string => (string) $r['action'], $ch), 'tambah, ubah status 1, ubah status 2');
        $this->assertStringEndsWith('|Sekretaris', (string) $ch[1]['field_value']);
        $this->assertNull($ch[2]['field_value'], 'status 2 = action 2 tanpa field');
    }

    public function testTransaksiGagalTidakMenulis(): void
    {
        try {
            $this->engine()->tambah($this->admin, $this->nip, 'kgb', ['tmtsk' => 'bukan-tanggal'] + $this->data('2022-04-01'), [Kgb::LAMPIRAN_SK_KGB => $this->berkasUji('sk.pdf')]);
        } catch (\Throwable) {
            // validasi / DB menolak
        }

        $this->assertSame([], $this->ch());
    }
}
