<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Pegawai\DrhPegawai;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * Cetak DRH PDF (B-20 penutup, MAKE-014; legacy print_drh UL_ALL, keputusan user 13-10-2026 Q-M6-5): hak 2/6/7 sendiri,
 * 1/4/5/8 semua, 3 lingkup; isi bagian A–L dari riwayat status 1. PegawaiScope NYATA.
 *
 * @internal
 */
final class DrhPegawaiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatUjiTrait;

    public function testEndpointPdfDanHakAkses(): void
    {
        $satker          = $this->buatSatker();
        [$nip, , $milik] = $this->aktor(Role::PEGAWAI, [], $satker);
        $lain            = $this->buatPegawaiDiSatker($this->buatSatker());
        [, , $super]     = $this->aktor(Role::SUPER_ADMIN);
        [, , $admin3]    = $this->aktor(Role::ADMIN_SATKER, [], $satker);
        [, , $pimpinan]  = $this->aktor(Role::PIMPINAN);
        [, , $ptt]       = $this->aktor(Role::PTT);

        $res = $this->asUser($milik)->get("api/v1/pegawai/{$nip}/drh");
        $res->assertStatus(200);
        $this->assertSame('application/pdf', explode(';', $res->response()->getHeaderLine('Content-Type'))[0]);
        $this->assertStringStartsWith('%PDF-', (string) $res->response()->getBody());
        $this->assertStringContainsString('drh_' . $nip . '_', $res->response()->getHeaderLine('Content-Disposition'));

        $this->asUser($milik)->get("api/v1/pegawai/{$lain}/drh")->assertStatus(403);
        $this->asUser($ptt)->get("api/v1/pegawai/{$nip}/drh")->assertStatus(403);
        $this->asUser($super)->get("api/v1/pegawai/{$lain}/drh")->assertStatus(200);
        $this->asUser($pimpinan)->get("api/v1/pegawai/{$lain}/drh")->assertStatus(200);
        $this->asUser($admin3)->get("api/v1/pegawai/{$nip}/drh")->assertStatus(200);
        $this->asUser($admin3)->get("api/v1/pegawai/{$lain}/drh")->assertStatus(403);
        $this->asUser($super)->get('api/v1/pegawai/999999999999999999/drh')->assertStatus(404);
    }

    public function testIsiBagianDariRiwayatStatus1(): void
    {
        $nip  = $this->buatPegawai(['nama' => 'Budi DRH', 'glr_akhir' => 'S.Kom.', 'kabupaten_kota_lahir' => 'LAIN-LAIN', 'kabupaten_kota_lahir_lain' => 'Kota Uji']);
        $auth = $this->aktor(Role::SUPER_ADMIN)[1];
        $this->db->table('riwayat_seminar')->insert(['nip' => $nip, 'nama_seminar' => 'Seminar <b>Arsip</b>', 'jenis_seminar' => 1, 'status' => 1, 'tgl_sertifikat' => '2020-05-02']);
        $this->db->table('riwayat_seminar')->insert(['nip' => $nip, 'nama_seminar' => 'Seminar Ditolak', 'jenis_seminar' => 1, 'status' => 2]);

        $html = '';
        \App\Libraries\Pdf\Pdf::pakai(new class ($html) implements \App\Interfaces\PdfRendererInterface {
            public function __construct(private string &$html)
            {
            }

            public function render(\App\Libraries\Pdf\DokumenPdf $dokumen): string
            {
                $this->html = $dokumen->kopHtml . $dokumen->html;

                return '%PDF-uji';
            }
        });

        try {
            $hasil = (new DrhPegawai())->pdf($auth, $nip);
        } finally {
            \App\Libraries\Pdf\Pdf::reset();
        }

        $this->assertSame('%PDF-uji', $hasil['isi']);
        $this->assertStringContainsString('Budi DRH, S.Kom.', $html);
        $this->assertStringContainsString('Kota Uji', $html);
        $this->assertStringContainsString('Seminar &lt;b&gt;Arsip&lt;/b&gt;', $html, 'data di-escape');
        $this->assertStringNotContainsString('Seminar Ditolak', $html, 'hanya status 1');
        foreach (['B. RIWAYAT KEPANGKATAN', 'E.3. PELATIHAN FUNGSIONAL', 'J. KENAIKAN GAJI BERKALA TERAKHIR', 'L. RIWAYAT HUKUMAN DISIPLIN'] as $judul) {
            $this->assertStringContainsString($judul, $html);
        }
    }
}
