<?php

declare(strict_types=1);

namespace Tests\Layanan\Esign;

use App\Exceptions\NotFoundException;
use App\Libraries\Layanan\Cuti\CutiService;
use App\Libraries\Layanan\Cuti\SuratCutiService;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\EsignUjiTrait;

/**
 * MAKE-023 M4 (PRD Fase 4 §6.5.2) — surat cuti: render PDF dengan kop kementerian, versi belum TTE disimpan kode
 * `ID_SURAT_BELUM_TTE` (idempoten), unduhan memilih ber-TTE bila ada, isi templat di-escape.
 *
 * @internal
 */
final class SuratCutiTest extends DatabaseTestCase
{
    use EsignUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private function surat(): SuratCutiService
    {
        $this->cutiService();

        return new SuratCutiService($this->db, $this->lampiranUji);
    }

    /**
     * @return array<string, mixed>
     */
    private function baris(int $id): array
    {
        return $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->get()->getRowArray();
    }

    public function testRenderMenghasilkanPdf(): void
    {
        ['id' => $id] = $this->cutiFinal();

        $this->assertStringStartsWith('%PDF', $this->surat()->render($this->baris($id)));
    }

    public function testTerbitkanBelumTteMenyimpanSatuBerkasDanIdempoten(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $surat                   = $this->surat();

        $surat->terbitkanBelumTte($this->baris($id));
        $surat->terbitkanBelumTte($this->baris($id));

        $this->assertCount(1, $this->lampiranUji->daftar($a['pemohon']['nip'], CutiService::ID_SURAT_BELUM_TTE, $id), 'dipanggil dua kali tetap satu berkas');
        $this->assertNotNull($surat->belumTte($a['pemohon']['nip'], $id));
        $this->assertFalse($surat->adaTte($a['pemohon']['nip'], $id));
    }

    public function testTemplatMengEscapeIsianPengguna(): void
    {
        ['id' => $id] = $this->cutiFinal();
        $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->update(['keterangan' => '<script>alert(1)</script>']);

        $html = view('pdf/surat_cuti', ['dataset' => $this->baris($id), 'tglSurat' => '2026-11-02', 'qr' => '']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('##', $html, 'tag koordinat tanda tangan');
    }

    public function testUnduhSuratMemilihVersiTteBilaAdaKalauTidakBelumTte(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $svc                     = $this->cutiService();
        $surat                   = new SuratCutiService($this->db, $this->lampiranUji);
        $pemohon                 = $a['pemohon']['auth'];

        try {
            $svc->unduh($pemohon, $id, 'surat');
            $this->fail('Tanpa surat harus 404.');
        } catch (NotFoundException) {
            $this->addToAssertionCount(1);
        }

        $surat->terbitkanBelumTte($this->baris($id));
        $this->assertStringContainsString("surat_cuti_{$a['pemohon']['nip']}", $svc->unduh($pemohon, $id, 'surat')['nama']);

        $surat->simpanTte($a['pemohon']['nip'], $id, $surat->render($this->baris($id)));
        $this->assertStringContainsString('surat_cuti_ttd_', $svc->unduh($pemohon, $id, 'surat')['nama']);
    }
}
