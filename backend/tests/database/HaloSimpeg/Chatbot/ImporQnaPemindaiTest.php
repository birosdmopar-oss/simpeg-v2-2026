<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg\Chatbot;

use App\Exceptions\ValidationException;
use App\Libraries\HaloSimpeg\Chatbot\ImporQna;
use CodeIgniter\HTTP\Files\UploadedFile;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\Chatbot\ChatbotFixtureTrait;
use ZipArchive;

/**
 * Impor QnA: pemindaian struktur `.xlsx` sebelum `load()` (CR-182). Berkas dirakit dari template resmi dengan XML sheet
 * yang diubah; tanpa fixture biner.
 *
 * Sebelum CR-182 (9554054a): `<col min="5" max="1">` membuat `Reader\Xlsx\ColumnAndRowAttributes::readColumnAttributes`
 * (PhpSpreadsheet 5.10) berputar tanpa akhir sampai fatal memori (500 di luar envelope 422), dan `<col>` selebar lembar
 * dengan baris ber-`r` acak lolos pra-cek `listWorksheetInfo` (totalRows = `r` baris TERAKHIR) lalu dimuat lambat.
 *
 * @internal
 */
final class ImporQnaPemindaiTest extends DatabaseTestCase
{
    use ChatbotFixtureTrait;

    private const RUSAK = 'Berkas Excel rusak atau tidak dapat dibaca.';

    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }

        parent::tearDown();
    }

    public function testColMaxKurangDariMinDitolak422TanpaFatal(): void
    {
        $berkas = $this->rakit('<cols><col min="5" max="1" width="10" customWidth="1"/></cols>');
        $this->assertLessThan(10_000, (int) filesize($berkas), 'berkas kecil');

        // Di proses anak berbatas memori: sebelum CR-182 proses ini fatal (bukan 422), jadi PHPUnit sendiri tidak ikut mati.
        $hasil = $this->jalankanWorker($berkas, '128M');
        $this->assertSame(422, $hasil['status'] ?? null, 'harus 422, bukan fatal: ' . json_encode($hasil));
        $this->assertSame(['berkas' => [self::RUSAK]], $hasil['errors']);
        $this->assertLessThan(64 * 1024 * 1024, $hasil['puncak'], 'puncak memori PHP proses anak');

        if ($hasil['rss'] !== null) {
            $this->assertLessThan(160 * 1024 * 1024, $hasil['rss'], 'puncak RSS proses anak');
        }
    }

    public function testColSelebarLembarDenganBarisAcakDitolakCepat(): void
    {
        // Baris 3..1.502 diacak lalu baris 3 terakhir: listWorksheetInfo melihat totalRows 3, padahal ada 1.500 baris.
        $nomor = range(4, 1502);
        mt_srand(182);
        shuffle($nomor);
        $nomor[] = 3;
        $baris   = '';

        foreach ($nomor as $r) {
            $baris .= "<row r=\"{$r}\"><c r=\"A{$r}\" t=\"inlineStr\"><is><t>P{$r}?</t></is></c><c r=\"B{$r}\" t=\"inlineStr\"><is><t>J</t></is></c>"
                . "<c r=\"C{$r}\" t=\"inlineStr\"><is><t>Cuti</t></is></c></row>";
        }

        $berkas = $this->rakit('<cols><col min="1" max="16383" width="10" customWidth="1"/></cols>', $baris);

        $mulai = hrtime(true);
        $this->assertGalatBerkas(self::RUSAK, $berkas);
        $this->assertLessThan(1.5, (hrtime(true) - $mulai) / 1e9, 'ditolak sebelum dimuat');
        $this->assertSame(0, $this->db->table('qna')->countAllResults());
    }

    public function testBerkasGayaExcelDanLibreOfficeTetapDiterima(): void
    {
        // Bentuk <col> yang lazim ditulis Excel 365 (sisa lembar sampai 16.384) dan LibreOffice lama (sampai 1.025), VML
        // komentar dengan <br> tanpa penutup, serta relasi hyperlink: harus tetap diterima (pelajaran regresi CR-177).
        $vml    = '<xml xmlns:v="urn:schemas-microsoft-com:vml"><v:shape><v:textbox><div>baris1<br>baris2</div></v:textbox></v:shape></xml>';
        $berkas = $this->rakit(
            '<cols><col min="1" max="2" width="40" customWidth="1"/><col min="3" max="4" width="20" customWidth="1"/>'
            . '<col min="5" max="1025" width="11.52" style="0"/><col min="1026" max="16384" width="9"/></cols>',
            '',
            ['xl/drawings/vmlDrawing1.vml' => $vml],
        );

        $hasil = (new ImporQna($this->db))->impor($this->unggah($berkas));
        $this->assertSame([1, []], [$hasil['ditambah'], $hasil['galat']], 'baris contoh template diimpor');
    }

    public function testTemplateResmiTetapDiterima(): void
    {
        $path = $this->simpan(ImporQna::template());

        $hasil = (new ImporQna($this->db))->impor($this->unggah($path));
        $this->assertSame([1, []], [$hasil['ditambah'], $hasil['galat']]);
    }

    /**
     * Template resmi dengan `<cols>` diganti `$cols`, baris tambahan sebelum `</sheetData>`, dan entri ZIP tambahan.
     *
     * @param array<string, string> $entri
     */
    private function rakit(string $cols, string $baris = '', array $entri = []): string
    {
        $path = $this->simpan(ImporQna::template());
        $zip  = new ZipArchive();
        $this->assertTrue($zip->open($path));
        $nama  = 'xl/worksheets/sheet1.xml';
        $sheet = (string) $zip->getFromName($nama);
        $sheet = (string) preg_replace('~<cols>.*?</cols>~s', '', $sheet);
        $sheet = str_replace('<sheetData>', $cols . '<sheetData>', $sheet);
        $sheet = str_replace('</sheetData>', $baris . '</sheetData>', $sheet);
        $zip->addFromString($nama, $sheet);

        foreach ($entri as $n => $isi) {
            $zip->addFromString($n, $isi);
        }

        $zip->close();

        return $path;
    }

    private function simpan(string $isi): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cbp');
        file_put_contents($path, $isi);
        $this->tmp[] = $path;

        return $path;
    }

    private function unggah(string $path): UploadedFile
    {
        return new UploadedFile($path, 'qna.xlsx', 'application/octet-stream', (int) filesize($path), UPLOAD_ERR_OK);
    }

    private function assertGalatBerkas(string $pesan, string $path): void
    {
        try {
            (new ImporQna($this->db))->impor($this->unggah($path));
            $this->fail("seharusnya 422: {$pesan}");
        } catch (ValidationException $e) {
            $this->assertSame(['berkas' => [$pesan]], $e->getErrors());
        }
    }

    /**
     * @return array{status?: int, errors?: mixed, puncak: int, rss: int|null}
     */
    private function jalankanWorker(string $berkas, string $memori): array
    {
        $env = array_merge(getenv(), [
            'CI_ENVIRONMENT'          => 'testing',
            'database.tests.database' => $this->db->getDatabase(),
        ]);
        $proses = proc_open(
            [PHP_BINARY, '-d', "memory_limit={$memori}", '-d', 'max_execution_time=60', SUPPORTPATH . 'Scripts' . DIRECTORY_SEPARATOR . 'impor_qna_worker.php', $berkas],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            ROOTPATH,
            $env,
        );
        $this->assertIsResource($proses, 'Worker PHP gagal dijalankan');
        $keluar = (string) stream_get_contents($pipes[1]);
        $galat  = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $kode = proc_close($proses);

        $baris = trim((string) strrchr(rtrim($keluar), "\n") ?: $keluar);
        $hasil = json_decode($baris, true);

        if (! is_array($hasil)) {
            return ['puncak' => 0, 'rss' => null, 'exit' => $kode, 'keluaran' => substr($keluar . $galat, -600)];
        }

        return $hasil;
    }
}
