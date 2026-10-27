<?php

declare(strict_types=1);

namespace Tests\Layanan\Approval;

use App\Exceptions\ApiException;
use App\Libraries\Layanan\Cuti\StatusCuti;
use App\Libraries\Layanan\Cuti\TransisiCuti;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\CutiUjiTrait;

/**
 * MAKE-022 M3 (PRD Fase 4 §6.4.2, AC-04.5/04.9) — single end node: enumerasi (DFS dengan replay pada baris baru) semua urutan
 * aksi sah dari status 0 lewat service sungguhan; setiap jalur berakhir di {1, 2, 4} dalam <= 2 keputusan, dan dari
 * setiap status non-final ada >= 1 aksi sah oleh pelaku yang ada. Diulang untuk (a) dua langkah, (b) langkah tunggal,
 * (c) approver tahap aktif tak terjangkau (role 1 atas nama), (d) pemohon eselon I.
 *
 * @internal
 */
final class CutiSingleEndNodeTest extends DatabaseTestCase
{
    use CutiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    /**
     * @return iterable<string, array{string}>
     */
    public static function skenario(): iterable
    {
        yield 'dua langkah' => ['dua_langkah'];
        yield 'langkah tunggal' => ['tunggal'];
        yield 'approver tak terjangkau (atas nama)' => ['atas_nama'];
        yield 'eselon I (atas nama)' => ['eselon_i'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('skenario')]
    public function testSemuaJalurBerakhirDiStatusFinal(string $skenario): void
    {
        $paths = $this->telusuri($skenario, []);

        $this->assertNotEmpty($paths);

        foreach ($paths as $jalur) {
            $this->assertContains($jalur['status'], StatusCuti::FINAL, $skenario . ' ' . implode('>', $jalur['aksi']));
            $this->assertLessThanOrEqual(2, count($jalur['aksi']), 'maksimal 2 keputusan');
        }

        // Dua langkah/atas nama dua tahap: setujui>setujui, setujui>tolak, setujui>tangguhkan, tolak, tangguhkan = 5 jalur;
        // langkah tunggal dan eselon I: 3 jalur.
        $this->assertCount(in_array($skenario, ['dua_langkah', 'atas_nama'], true) ? 5 : 3, $paths);
    }

    /**
     * @param list<string> $prefix
     *
     * @return list<array{aksi: list<string>, status: int}>
     */
    private function telusuri(string $skenario, array $prefix): array
    {
        [$id, $aktor, $admin] = $this->siapkanBaris($skenario);
        $status               = 0;

        foreach ($prefix as $aksi) {
            $status = (int) $this->jalankan($skenario, $id, $aktor, $admin, $aksi)['status'];
        }

        if (StatusCuti::final($status)) {
            return [['aksi' => $prefix, 'status' => $status]];
        }

        $hasil = [];
        $sah   = 0;

        foreach (TransisiCuti::AKSI as $aksi) {
            [$id2, $aktor2, $admin2] = $this->siapkanBaris($skenario);

            try {
                foreach ($prefix as $a) {
                    $this->jalankan($skenario, $id2, $aktor2, $admin2, $a);
                }

                $this->jalankan($skenario, $id2, $aktor2, $admin2, $aksi);
            } catch (ApiException) {
                continue;
            }

            $sah++;
            $hasil = array_merge($hasil, $this->telusuri($skenario, [...$prefix, $aksi]));
        }

        $this->assertGreaterThanOrEqual(1, $sah, "status {$status} tanpa aksi sah (jalan buntu): " . implode('>', $prefix));

        return $hasil;
    }

    /**
     * @return array{int, array<string, mixed>, array<string, mixed>}
     */
    private function siapkanBaris(string $skenario): array
    {
        $roleAl  = $skenario === 'atas_nama' ? 4 : 2;
        $rolePyb = $skenario === 'atas_nama' ? 5 : 2;
        $al      = $this->buatPejabat($roleAl);
        $pyb     = $this->buatPejabat($rolePyb);
        $p       = $this->buatPemohon(2, [], [], ['id_atasan_es_4' => $al['id_jabatan']]);
        $this->buatSaldo($p['nip'], 2026);

        $nipAl  = $skenario === 'tunggal' ? $pyb['nip'] : $al['nip'];
        $nipPyb = $pyb['nip'];
        $status = 0;

        if ($skenario === 'eselon_i') {
            $nipAl = $nipPyb = null;
        }

        $id = $this->buatBarisCuti($p['nip'], [
            'nip_atasan_langsung'  => $nipAl, 'nip_yang_menyetujui' => $nipPyb, 'status' => $status,
            'nama_atasan_langsung' => 'AL', 'nama_yang_menyetujui' => 'PyB', 'created_at' => '2026-11-02 02:00:00',
        ]);

        $admin = $this->buatPemohon(1);

        return [$id, ['al' => $al, 'pyb' => $pyb], $admin];
    }

    /**
     * @param array<string, mixed> $aktor
     * @param array<string, mixed> $admin
     *
     * @return array<string, mixed>
     */
    private function jalankan(string $skenario, int $id, array $aktor, array $admin, string $aksi): array
    {
        $svc    = $this->approvalService();
        $cuti   = $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->get()->getRowArray();
        $status = (int) $cuti['status'];
        $input  = ['aksi' => $aksi, 'reason_note' => 'Alasan uji'];

        if ($skenario === 'atas_nama' || $skenario === 'eselon_i') {
            return $svc->process($admin['auth'], $id, $input + ['atas_nama' => true]);
        }

        $pelaku = $skenario === 'tunggal' || $status === StatusCuti::DISETUJUI_AL ? $aktor['pyb'] : $aktor['al'];

        return $svc->process($pelaku['auth'], $id, $input);
    }
}
