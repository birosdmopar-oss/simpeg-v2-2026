<?php

declare(strict_types=1);

namespace Tests\Layanan\Approval;

use Config\Services;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\CutiUjiTrait;
use Tests\Support\Ukur\PenghitungKueri;

/**
 * CR-167 — sesudah commit, `CutiApprovalService::process` membaca baris `riwayat_cuti` SEKALI untuk push Android dan
 * detail (dulu dua kali). Isi push dan detail dikunci test pagar `CutiApproveFinalPushDetailTest`. Data sintetis.
 *
 * @internal
 */
final class CutiProsesSatuBacaSesudahCommitTest extends DatabaseTestCase
{
    use CutiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    /** @var array<string, mixed> */
    private array $pushAsli;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pushAsli                           = config(\Config\Integrations::class)->push;
        config(\Config\Integrations::class)->push = ['driver' => 'fcm', 'projectId' => '', 'credentialsPath' => ''];
    }

    protected function tearDown(): void
    {
        config(\Config\Integrations::class)->push = $this->pushAsli;
        Services::reset(true);

        parent::tearDown();
    }

    public function testSatuBacaBarisCutiSesudahTulisTerakhir(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $id = (int) $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null)['id_riwayat_cuti'];

        $hitung = new PenghitungKueri();
        $hitung->mulai();

        try {
            $detail = $this->approvalService()->process($a['al']['auth'], $id, ['aksi' => 'setujui']);
        } finally {
            $hitung->selesai();
        }

        $kueri    = $hitung->kueri();
        $tabel    = $this->db->prefixTable('riwayat_cuti');
        $terakhir = 0;

        foreach ($kueri as $i => $sql) {
            if (preg_match('/^\s*UPDATE\s+`?' . preg_quote($tabel, '/') . '`?/i', $sql) === 1) {
                $terakhir = $i;
            }
        }

        // Baca baris cuti utuh per id (bentuk `CutiService::ambil`) sesudah tulis terakhir ke riwayat_cuti.
        $baca = array_values(array_filter(
            array_slice($kueri, $terakhir + 1),
            static fn (string $sql): bool => preg_match('/^\s*SELECT\s+\*\s+FROM\s+`?' . preg_quote($tabel, '/') . '`?\s+WHERE\s+`?id_riwayat_cuti`?\s*=/i', $sql) === 1,
        ));

        $this->assertSame('3', $detail['status'], 'AL menyetujui: menunggu PyB');
        $this->assertCount(1, $baca, 'satu baca untuk push dan detail (dulu dua):
' . implode('
---
', $baca));
    }
}
