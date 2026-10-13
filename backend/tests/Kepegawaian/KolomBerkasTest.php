<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Lampiran\KolomBerkas;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\LampiranUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-009 — helper kolom berkas (mis. `riwayat_karpeg.file_1..5`): validasi sama dengan lampiran, dan berkas hanya
 * bertahan bila dirujuk kolomnya setelah transaksi pemanggil selesai.
 *
 * @internal
 */
final class KolomBerkasTest extends DatabaseTestCase
{
    use LampiranUjiTrait;
    use PegawaiFixtureTrait;

    private KolomBerkas $kolom;

    private AturanLampiran $aturan;

    private string $nip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kolom  = new KolomBerkas(new LocalStorageAdapter($this->akarLampiranUji()), $this->db);
        $this->aturan = new AturanLampiran(0, false, 2, ['pdf']);
        $this->nip    = $this->buatPegawai();
    }

    protected function tearDown(): void
    {
        $this->bersihkanLampiranUji();

        parent::tearDown();
    }

    public function testCommitMempertahankanBerkasYangDirujukDanMenghapusYangDiganti(): void
    {
        $this->db->transBegin();
        $lama = $this->kolom->simpan($this->berkasPdf('sk-cpns.pdf'), $this->aturan, 'riwayat_karpeg', 'file_1');
        $id   = $this->insertKarpeg($lama);
        $this->db->transCommit();
        $this->assertTrue($this->kolom->selesaikan());
        $this->assertSame([$lama], $this->berkasDiAkarUji());
        $this->assertStringStartsWith('kolom/', $lama);

        // Ganti berkas: yang baru dirujuk, yang lama dilepas.
        $this->db->transBegin();
        $baru = $this->kolom->simpan($this->berkasPdf('sk-cpns-revisi.pdf'), $this->aturan, 'riwayat_karpeg', 'file_1');
        $this->kolom->hapus($lama, 'riwayat_karpeg', 'file_1');
        $this->db->table('riwayat_karpeg')->where('id_riwayat_karpeg', $id)->update(['file_1' => $baru]);
        $this->assertFalse($this->kolom->selesaikan(), 'menunggu transaksi selesai');
        $this->db->transCommit();
        $this->kolom->selesaikan();

        $this->assertSame([$baru], $this->berkasDiAkarUji());
        $this->assertStringStartsWith('%PDF', $this->kolom->baca($baru));
    }

    public function testRollbackMenghapusBerkasBaruDanMempertahankanYangLama(): void
    {
        $lama = $this->kolom->simpan($this->berkasPdf(), $this->aturan, 'riwayat_karpeg', 'file_1');
        $id   = $this->insertKarpeg($lama);
        $this->kolom->selesaikan();

        $this->db->transBegin();
        $baru = $this->kolom->simpan($this->berkasPdf(), $this->aturan, 'riwayat_karpeg', 'file_1');
        $this->kolom->hapus($lama, 'riwayat_karpeg', 'file_1');
        $this->db->table('riwayat_karpeg')->where('id_riwayat_karpeg', $id)->update(['file_1' => $baru]);
        $this->db->transRollback();
        $this->kolom->selesaikan();

        $this->assertSame([$lama], $this->berkasDiAkarUji());
        $this->assertTrue($this->kolom->ada($lama));
        $this->assertFalse($this->kolom->ada($baru));
    }

    public function testValidasiMemakaiKunciField(): void
    {
        try {
            $this->kolom->simpan($this->berkasPng('pas-foto.pdf'), $this->aturan, 'riwayat_karpeg', 'file_5', 'file_5');
            $this->fail('seharusnya 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file_5', $e->getErrors() ?? []);
        }

        $this->assertSame([], $this->berkasDiAkarUji());
    }

    private function insertKarpeg(string $file1): int
    {
        $this->db->table('riwayat_karpeg')->insert([
            'nip'    => $this->nip,
            'file_1' => $file1,
            'file_2' => '',
            'file_3' => '',
            'file_4' => '',
            'file_5' => '',
        ]);

        return (int) $this->db->insertID();
    }
}
