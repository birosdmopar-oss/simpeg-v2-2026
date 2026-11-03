<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Integrasi\Log\CatatanIntegrasi;
use App\Libraries\Integrasi\Log\IntegrasiLog;
use App\Libraries\Integrasi\Log\PembersihLogIntegrasi;
use App\Libraries\Integrasi\Log\StatusIntegrasi;
use CodeIgniter\Test\StreamFilterTrait;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-063 — penulis `integrasi_log` (F6-24), ringkasan `integrasi:status`, dan retensi `integrasi:bersihkan-log`
 * (Q6-16). Tabel dari DBV-027.
 *
 * @internal
 */
final class IntegrasiLogTest extends DatabaseTestCase
{
    use StreamFilterTrait;

    public function testCatatMenyimpanBarisTerpotongDanUtc(): void
    {
        (new IntegrasiLog($this->db))->catat(new CatatanIntegrasi(
            arah: CatatanIntegrasi::ARAH_KELUAR,
            layanan: 'bkn',
            operasi: str_repeat('o', 150),
            status: CatatanIntegrasi::GAGAL,
            httpStatus: 503,
            kode: 'gateway',
            pesan: str_repeat('p', 1500),
            durasiMs: 120,
            percobaan: 2,
            refId: 'periode-uji',
        ));

        $b = $this->db->table('integrasi_log')->get()->getRowArray();
        $this->assertSame(['bkn', 100, 1000, '503', '2', 'periode-uji'], [$b['layanan'], mb_strlen($b['operasi']), mb_strlen($b['pesan']), (string) $b['http_status'], (string) $b['percobaan'], $b['ref_id']]);
        $this->assertLessThanOrEqual(5, abs(strtotime($b['created_at'] . ' UTC') - time()), 'created_at UTC');
    }

    #[Group('db-isolasi-penuh')]
    public function testGagalMenulisLogTidakMelemparKeOperasi(): void
    {
        $this->db->query('ALTER TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable('integrasi_log')) . ' RENAME TO ' . $this->db->escapeIdentifiers($this->db->prefixTable('integrasi_log_x')));

        try {
            (new IntegrasiLog($this->db))->catat(new CatatanIntegrasi(1, 'bkn', 'periode', 1));
            $this->addToAssertionCount(1);
        } finally {
            $this->db->query('ALTER TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable('integrasi_log_x')) . ' RENAME TO ' . $this->db->escapeIdentifiers($this->db->prefixTable('integrasi_log')));
        }
    }

    public function testRingkasanStatusPerLayananDalamJendela(): void
    {
        $this->isi('bkn', 1, 1, '-1 hour');
        $this->isi('bkn', 1, 2, '-30 minutes', 'timeout');
        $this->isi('bkn', 1, 2, '-2 days', 'lama');
        $this->isi('simapi', 2, 1, '-10 minutes');

        $r = (new StatusIntegrasi($this->db))->ringkasan(24);

        $this->assertSame([
            ['layanan' => 'bkn', 'arah' => 1, 'sukses' => 1, 'gagal' => 1, 'kode_terakhir' => 'timeout'],
            ['layanan' => 'simapi', 'arah' => 2, 'sukses' => 1, 'gagal' => 0, 'kode_terakhir' => null],
        ], array_map(static fn (array $b): array => array_diff_key($b, ['gagal_terakhir_at' => 0]), $r));
    }

    public function testBersihkanLogMenghapusYangMelewatiRetensiPerBatch(): void
    {
        foreach (range(1, 7) as $_) {
            $this->isi('bkn', 1, 1, '-200 days');
        }

        $this->isi('bkn', 1, 1, '-10 days');

        $pembersih = new PembersihLogIntegrasi($this->db, 3);
        $this->assertSame(7, $pembersih->bersihkan(180, true)['jumlah']);
        $this->assertSame(8, $this->db->table('integrasi_log')->countAllResults(), 'dry-run tidak menghapus');
        $this->assertSame(7, $pembersih->bersihkan(180)['jumlah']);
        $this->assertSame(1, $this->db->table('integrasi_log')->countAllResults());
        $this->assertSame(0, $pembersih->bersihkan(180)['jumlah'], 'idempoten');
    }

    public function testCommandStatusDanBersihkan(): void
    {
        $this->isi('fcm', 1, 2, '-1 hour', 'UNREGISTERED');
        $this->isi('fcm', 1, 1, '-300 days');

        command('integrasi:status --jam 24');
        $keluaran = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('fcm', $keluaran);
        $this->assertStringContainsString('UNREGISTERED', $keluaran);

        $this->resetStreamFilterBuffer();
        command('integrasi:bersihkan-log --dry-run');
        $this->assertStringContainsString('[dry-run] Akan dihapus: 1 baris', $this->getStreamFilterBuffer());
        $this->assertSame(2, $this->db->table('integrasi_log')->countAllResults());

        $this->resetStreamFilterBuffer();
        command('integrasi:bersihkan-log --hari 180');
        $this->assertStringContainsString('Dihapus: 1 baris', $this->getStreamFilterBuffer());
        $this->assertSame(1, $this->db->table('integrasi_log')->countAllResults());

        $this->resetStreamFilterBuffer();
        command('integrasi:bersihkan-log --hari abc');
        $this->assertStringContainsString('--hari harus angka', $this->getStreamFilterBuffer());

        $this->resetStreamFilterBuffer();
        command('integrasi:bersihkan-log --hari=200 --dry-run');
        $this->assertStringContainsString('retensi 200 hari', $this->getStreamFilterBuffer());

        $this->resetStreamFilterBuffer();
        command('integrasi:status --tidak-dikenal');
        $this->assertStringContainsString('Pemakaian: integrasi:status', $this->getStreamFilterBuffer());
    }

    private function isi(string $layanan, int $arah, int $status, string $relatif, ?string $kode = null): void
    {
        $this->db->table('integrasi_log')->insert([
            'arah'       => $arah, 'layanan' => $layanan, 'operasi' => 'uji', 'status' => $status, 'kode' => $kode,
            'created_at' => gmdate('Y-m-d H:i:s', (int) strtotime($relatif)),
        ]);
    }
}
