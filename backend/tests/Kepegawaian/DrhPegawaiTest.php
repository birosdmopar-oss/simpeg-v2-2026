<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Interfaces\PdfRendererInterface;
use App\Libraries\Kepegawaian\Pegawai\DrhPegawai;
use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\Pdf;
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

        $renderer = new class () implements PdfRendererInterface {
            public string $html = '';

            public function render(DokumenPdf $dokumen): string
            {
                $this->html = $dokumen->kopHtml . $dokumen->html;

                return '%PDF-uji';
            }
        };
        Pdf::pakai($renderer);

        try {
            $hasil = (new DrhPegawai())->pdf($auth, $nip);
        } finally {
            Pdf::reset();
        }

        $html = $renderer->html;
        $this->assertSame('%PDF-uji', $hasil['isi']);
        $this->assertStringContainsString('Budi DRH, S.Kom.', $html);
        $this->assertStringContainsString('Kota Uji', $html);
        $this->assertStringContainsString('Seminar &lt;b&gt;Arsip&lt;/b&gt;', $html, 'data di-escape');
        $this->assertStringNotContainsString('Seminar Ditolak', $html, 'hanya status 1');
        foreach (['A. DATA PRIBADI', 'B. RIWAYAT KEPANGKATAN', 'E.3. PELATIHAN FUNGSIONAL', 'J. KENAIKAN GAJI BERKALA TERAKHIR', 'L. RIWAYAT HUKUMAN DISIPLIN'] as $judul) {
            $this->assertStringContainsString($judul, $html);
        }

        // CR-065: label A ada di depan B (urutan legacy drh_pdf.php), dan setiap judul bagian satu blok nobr dengan tabelnya.
        $this->assertLessThan(strpos($html, 'B. RIWAYAT KEPANGKATAN'), strpos($html, 'A. DATA PRIBADI'));
        $this->assertSame(range('A', 'L'), self::hurufBagian($html), 'urutan huruf bagian A–L');
        $this->assertJudulSeblokDenganTabel($html);
    }

    /**
     * CR-065 (ISSUE-051 T14): judul bagian tidak boleh tertinggal di dasar halaman sementara tabelnya di halaman berikut.
     * View DRH dirender TCPDF sungguhan (tanpa kompresi, font inti) dengan pengisi baris yang berbeda-beda agar setiap
     * judul pernah jatuh dekat dasar halaman; judul dan header tabelnya harus selalu di halaman yang sama.
     */
    public function testJudulBagianSelaluSehalamanDenganTabelnya(): void
    {
        $sisi      = ['F' => 'RIWAYAT KURSUS / SEMINAR', 'G' => 'RIWAYAT PENGHARGAAN', 'H' => 'SASARAN KERJA PEGAWAI', 'K' => 'RIWAYAT ORGANISASI', 'L' => 'RIWAYAT HUKUMAN DISIPLIN'];
        $diperiksa = 0;

        for ($pengisi = 0; $pengisi <= 45; $pengisi += 3) {
            $bagian = [['kode' => 'B', 'judul' => 'RIWAYAT KEPANGKATAN', 'kolom' => [['NOB', 5], ['TMT SK', 18], ['PANGKAT', 77]],
                'baris'        => array_map(static fn (int $i): array => [(string) $i, '01 Jan 2000', 'Penata Muda Tingkat I'], range(1, max(1, $pengisi)))]];

            foreach ($sisi as $kode => $judul) {
                $bagian[] = ['kode' => $kode, 'judul' => $judul, 'kolom' => [['NO' . $kode, 10], ['KETERANGAN', 90]], 'baris' => [['1', 'Isi ' . $kode], ['2', 'Isi ' . $kode]]];
            }

            $halaman = self::halamanPdf(view('pdf/drh', [
                'nama'  => 'Budi DRH', 'du' => ['nip' => '199001012015011001', 'agama' => 'Islam', 'tgl_lahir' => '1990-01-01', 'jenis_kelamin' => 1],
                'lahir' => 'Kota Uji', 'alamat' => 'Jl. Uji', 'bagian' => $bagian, 'kgb' => null, 'fotoTag' => '',
            ]));

            foreach (array_keys($sisi) as $kode) {
                $judulDi = self::halamanBerisi($halaman, $kode . '. ' . $sisi[$kode]);
                $tabelDi = self::halamanBerisi($halaman, 'NO' . $kode);
                $this->assertNotNull($judulDi, "judul {$kode} (pengisi {$pengisi})");
                $this->assertSame($judulDi, $tabelDi, "judul {$kode} dan tabelnya beda halaman (pengisi {$pengisi})");
                $diperiksa++;
            }
        }

        $this->assertGreaterThan(1, count(self::halamanPdf(view('pdf/drh', [
            'nama'   => 'X', 'du' => ['nip' => '1', 'tgl_lahir' => null], 'lahir' => '', 'alamat' => '', 'kgb' => null, 'fotoTag' => '',
            'bagian' => [['kode' => 'B', 'judul' => 'RIWAYAT KEPANGKATAN', 'kolom' => [['NO', 100]], 'baris' => array_fill(0, 80, ['x'])]],
        ]))), 'bagian lebih panjang dari satu halaman tetap terpecah (tidak hilang)');
        $this->assertSame(80, $diperiksa);
    }

    /**
     * Setiap h4/h5 judul bagian berada di dalam `<div nobr="true">` yang juga memuat tabel bagian itu.
     */
    private function assertJudulSeblokDenganTabel(string $html): void
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        $judul = 0;

        foreach (['h4', 'h5'] as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $el) {
                $blok = $el->parentNode;
                $this->assertInstanceOf(\DOMElement::class, $blok);
                $this->assertSame('div', $blok->nodeName, trim($el->textContent));
                $this->assertSame('true', $blok->getAttribute('nobr'), trim($el->textContent));
                $this->assertGreaterThan(0, $blok->getElementsByTagName('table')->length, trim($el->textContent) . ' tanpa tabel di bloknya');
                $judul++;
            }
        }

        $this->assertSame(15, $judul, '12 judul bagian A–L + 3 subjudul E.1–E.3');
    }

    /**
     * @return list<string> huruf bagian berurutan dari judul `<h4>X. …</h4>`
     */
    private static function hurufBagian(string $html): array
    {
        preg_match_all('/<h4>([A-Z])\. /', $html, $m);

        return $m[1];
    }

    /**
     * Isi stream konten per halaman dari PDF TCPDF tanpa kompresi, berurutan.
     *
     * @return list<string>
     */
    private static function halamanPdf(string $html): array
    {
        $pdf = new \TCPDF('P', 'mm', 'A4', false, 'ISO-8859-1');
        $pdf->setCompression(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setMargins(15, 42, 15);
        $pdf->setAutoPageBreak(true, 15);
        $pdf->setFont('helvetica', '', 9);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf->Output('', 'S'), $m);

        return array_values(array_filter($m[1], static fn (string $isi): bool => str_contains($isi, ' Tj') || str_contains($isi, ' TJ')));
    }

    /**
     * @param list<string> $halaman
     */
    private static function halamanBerisi(array $halaman, string $teks): ?int
    {
        // String literal PDF: backslash dan kurung di-escape.
        $cari = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $teks);

        foreach ($halaman as $i => $isi) {
            if (str_contains($isi, '(' . $cari . ')')) {
                return $i;
            }
        }

        return null;
    }
}
