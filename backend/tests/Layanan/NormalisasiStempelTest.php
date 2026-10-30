<?php

declare(strict_types=1);

namespace Tests\Layanan;

use App\Constants\Role;
use App\Libraries\Waktu\NormalisasiStempel;
use InvalidArgumentException;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * CR-086: audit dan normalisasi baris yang ditulis zona sesi DB (dry-run bawaan, jendela wajib, `updated_at` ikut hanya bila
 * sama dengan `created_at`).
 *
 * @internal
 */
final class NormalisasiStempelTest extends DatabaseTestCase
{
    use LayananStudiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();
    }

    protected function tearDown(): void
    {
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testDryRunTidakMenulisDanEksekusiMenggeserHanyaDalamJendela(): void
    {
        $id = $this->sisipTigaBaris();
        $s  = new NormalisasiStempel($this->db);

        $dry = $s->normalisasi('petugas_layanan', '2026-10-10 00:00:00', '2026-10-20 23:59:59', 7, true);
        $this->assertSame([2, 1, 1, true], [$dry['terkena'], $dry['updated_at_ikut'], $dry['updated_at_tak_pasti'], $dry['dry_run']]);
        $this->assertSame('2026-10-12 08:00:00', $this->baris($id[0])['created_at'], 'dry-run tidak menulis');

        $s->normalisasi('petugas_layanan', '2026-10-10 00:00:00', '2026-10-20 23:59:59', 7, false);

        $a = $this->baris($id[0]);
        $b = $this->baris($id[1]);
        $c = $this->baris($id[2]);
        $this->assertSame(['2026-10-12 01:00:00', '2026-10-12 01:00:00'], [$a['created_at'], $a['updated_at']], 'updated_at = created_at ikut digeser');
        $this->assertSame(['2026-10-13 01:00:00', '2026-10-15 09:00:00'], [$b['created_at'], $b['updated_at']], 'updated_at beda tidak disentuh');
        $this->assertSame(['2026-09-01 08:00:00', '2026-09-01 08:00:00'], [$c['created_at'], $c['updated_at']], 'di luar jendela tidak disentuh');
    }

    public function testAuditMelaporkanBarisBertanggalMasaDepan(): void
    {
        $this->sisipTigaBaris();

        $r = (new NormalisasiStempel($this->db))->audit('2026-10-12 12:00:00')['petugas_layanan'];

        $this->assertSame([3, '2026-09-01 08:00:00', '2026-10-13 08:00:00', 1], [$r['total'], $r['terkecil'], $r['terbesar'], $r['masa_depan']]);
    }

    public function testMasukanTidakSahDitolak(): void
    {
        $s = new NormalisasiStempel($this->db);

        foreach ([
            ['tidak_ada', '2026-10-10 00:00:00', '2026-10-20 00:00:00', 7],
            ['petugas_layanan', '2026-10-10', '2026-10-20 00:00:00', 7],
            ['petugas_layanan', '2026-10-21 00:00:00', '2026-10-20 00:00:00', 7],
            ['petugas_layanan', '2026-10-10 00:00:00', '2026-10-20 00:00:00', 0],
        ] as [$tabel, $dari, $sampai, $offset]) {
            try {
                $s->normalisasi($tabel, $dari, $sampai, $offset, true);
                $this->fail('harus ditolak');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @return list<int>
     */
    private function sisipTigaBaris(): array
    {
        $id = [];

        foreach ([
            ['2026-10-12 08:00:00', '2026-10-12 08:00:00'], // dalam jendela, belum diubah
            ['2026-10-13 08:00:00', '2026-10-15 09:00:00'], // dalam jendela, pernah diubah
            ['2026-09-01 08:00:00', '2026-09-01 08:00:00'], // di luar jendela
        ] as $i => [$dibuat, $diubah]) {
            [$nip] = $this->aktorStudi(Role::PEGAWAI);
            $this->db->table('petugas_layanan')->insert(['id_jenis_layanan' => 1 + $i, 'nip' => $nip, 'status' => 1, 'created_at' => $dibuat, 'updated_at' => $diubah]);
            $id[] = (int) $this->db->insertID();
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function baris(int $id): array
    {
        return $this->db->table('petugas_layanan')->where('id_petugas_layanan', $id)->get()->getRowArray();
    }
}
