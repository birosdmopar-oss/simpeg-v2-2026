<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Database\Migrations\CreateKontenInteraksiFase7;
use App\Libraries\Dashboard\Konten\Penargetan\PemekarTarget;
use App\Libraries\Dashboard\Konten\Penargetan\SinkronPenerima;
use App\Libraries\Dashboard\Konten\Penargetan\SpesifikasiTarget;
use App\Libraries\Dashboard\Konten\Penargetan\TabelPenerima;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

require_once APPPATH . 'Database/MigrationsDitahan/CreateKontenInteraksiFase7.php';

/**
 * Penargetan F-04/F-05 (PRD Fase 7 v2.0 Q7-15): pemekaran target → NIP unik (status 1, kecuali menang) dan sinkron selisih
 * snapshot penerima. Masih memuat draf migration di `MigrationsDitahan/` (DBV-032); `require_once` dihapus saat dipindah.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class KontenPenargetanTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private CreateKontenInteraksiFase7 $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new CreateKontenInteraksiFase7();
        $this->m->up();
    }

    protected function tearDown(): void
    {
        $this->m->down();
        parent::tearDown();
    }

    public function testUnitSatkerPegawaiDanKecuali(): void
    {
        $unitA   = $this->buatUnit();
        $satkerA = $this->buatSatker($unitA);
        $satkerB = $this->buatSatker($this->buatUnit());

        $a1       = $this->buatPegawaiDiSatker($satkerA);
        $a2       = $this->buatPegawaiDiSatker($satkerA);
        $b1       = $this->buatPegawaiDiSatker($satkerB);
        $nonaktif = $this->buatPegawai(['status' => 2], ['id_satker' => $satkerA]);

        $pemekar = new PemekarTarget($this->db);

        // unit → pegawai satker di bawah unit itu (id_unit snapshot), hanya status 1
        $hasil = $pemekar->pekarkan(SpesifikasiTarget::dariArray(['unit_satker' => [(string) $unitA]]));
        $this->assertContains($a1, $hasil);
        $this->assertContains($a2, $hasil);
        $this->assertNotContains($b1, $hasil);
        $this->assertNotContains($nonaktif, $hasil);

        // satker + pegawai eksplisit − kecuali (kecuali menang)
        $hasil = $pemekar->pekarkan(SpesifikasiTarget::dariArray([
            'unit_satker' => ["sat_{$satkerA}"],
            'pegawai'     => [$b1],
            'kecuali'     => [$a2],
        ]));
        $this->assertSame([$a1, $b1], $hasil === [] ? [] : array_values(array_intersect($hasil, [$a1, $a2, $b1])));
        $this->assertNotContains($a2, $hasil);

        // pegawai nonaktif yang dipilih eksplisit TETAP ikut [K] (legacy L_announcement.php:561-586); NIP tak dikenal diabaikan
        $this->assertSame([$nonaktif], $pemekar->pekarkan(SpesifikasiTarget::dariArray(['pegawai' => [$nonaktif, '000000000000000099']])));
    }

    public function testSeluruhKementerianSemuaPegawaiAktifTanpaDuplikat(): void
    {
        $satker   = $this->buatSatker($this->buatUnit());
        $p1       = $this->buatPegawaiDiSatker($satker);
        $nonaktif = $this->buatPegawai(['status' => 2], ['id_satker' => $satker]);

        // pilihan pegawai diabaikan bila seluruh kementerian [K] (!$allKem): nonaktif eksplisit pun tidak ikut
        $hasil = (new PemekarTarget($this->db))->pekarkan(SpesifikasiTarget::dariArray(['unit_satker' => ['0'], 'pegawai' => [$p1, $nonaktif]]));

        $this->assertContains($p1, $hasil);
        $this->assertNotContains($nonaktif, $hasil);
        $this->assertSame($hasil, array_values(array_unique($hasil)));
        $sorted = $hasil;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $hasil);
    }

    public function testSinkronHanyaMenulisSelisih(): void
    {
        $satker         = $this->buatSatker($this->buatUnit());
        [$p1, $p2, $p3] = [$this->buatPegawaiDiSatker($satker), $this->buatPegawaiDiSatker($satker), $this->buatPegawaiDiSatker($satker)];
        $idPopup        = $this->buatPopup();

        $sinkron = new SinkronPenerima($this->db);

        $this->assertSame(['ditambah' => 2, 'dihapus' => 0, 'dipertahankan' => 0], $sinkron->sinkron(TabelPenerima::UserPopup, $idPopup, [$p1, $p2]));
        $idAwal = (int) $this->db->table('user_popup')->where(['id_popup' => $idPopup, 'nip' => $p1])->get()->getRow()->id_user_popup;

        $this->assertSame(['ditambah' => 1, 'dihapus' => 1, 'dipertahankan' => 1], $sinkron->sinkron(TabelPenerima::UserPopup, $idPopup, [$p1, $p3]));
        $this->assertSame(
            [$p1, $p3],
            array_column($this->db->table('user_popup')->where('id_popup', $idPopup)->orderBy('nip')->get()->getResultArray(), 'nip'),
        );
        // baris yang bertahan tidak dibuat ulang (PK sama)
        $this->assertSame($idAwal, (int) $this->db->table('user_popup')->where(['id_popup' => $idPopup, 'nip' => $p1])->get()->getRow()->id_user_popup);

        // idempoten
        $this->assertSame(['ditambah' => 0, 'dihapus' => 0, 'dipertahankan' => 2], $sinkron->sinkron(TabelPenerima::UserPopup, $idPopup, [$p3, $p1]));
    }

    public function testBolehHapusMenahanPenghapusan(): void
    {
        $satker    = $this->buatSatker($this->buatUnit());
        [$p1, $p2] = [$this->buatPegawaiDiSatker($satker), $this->buatPegawaiDiSatker($satker)];
        $idPopup   = $this->buatPopup();
        $sinkron   = new SinkronPenerima($this->db);
        $sinkron->sinkron(TabelPenerima::UserPopup, $idPopup, [$p1, $p2]);

        $hasil = $sinkron->sinkron(TabelPenerima::UserPopup, $idPopup, [], static fn (string $nip): bool => $nip !== $p2);

        $this->assertSame(['ditambah' => 0, 'dihapus' => 1, 'dipertahankan' => 1], $hasil);
        $this->assertSame(1, $this->db->table('user_popup')->where('id_popup', $idPopup)->countAllResults());
    }

    private function buatPopup(): int
    {
        $this->db->table('popup')->insert(['tgl_mulai' => '2026-11-01 00:00:00', 'tgl_akhir' => '2026-11-30 23:59:00', 'file' => 'uji.png']);

        return (int) $this->db->insertID();
    }
}
