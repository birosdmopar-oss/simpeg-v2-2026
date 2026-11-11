<?php

declare(strict_types=1);

namespace Tests\Impor;

use App\Commands\ImporBerkasKonten;
use App\Libraries\Impor\AturanFormDigital;
use App\Libraries\Impor\AturanKonten;
use App\Libraries\Impor\BerkasKonten;
use App\Libraries\Impor\Keputusan;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Konten;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\Impor\ImporLegacyTestCase;

/**
 * MAKE-089 M3 (Q-089-1 b) — `impor:berkas-konten`: salin berkas legacy ke kunci tier 14, baca-saja sumber, idempoten, isi
 * beda tidak ditimpa, traversal ditolak, laporan jumlah saja. Skema legacy minimal tiruan + folder & data SINTETIS.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class BerkasKontenTest extends ImporLegacyTestCase
{
    use StreamFilterTrait;

    private const NIP = '000000000000000001';

    private string $asal;
    private string $tujuan;

    protected function setUp(): void
    {
        parent::setUp();

        $l = "`{$this->lgc}`";

        foreach (['popup', 'banner', 'news', 'dibo', 'form_digital', 'filled_form_digital'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$l}.`{$t}`");
        }

        $c = 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->db->query("CREATE TABLE {$l}.`popup` (`id_popup` int NOT NULL, `file` varchar(255) NOT NULL, `type` tinyint NOT NULL DEFAULT 1, PRIMARY KEY (`id_popup`)) {$c}");
        $this->db->query("CREATE TABLE {$l}.`banner` (`id_banner` int NOT NULL, `file` varchar(255) NOT NULL, PRIMARY KEY (`id_banner`)) {$c}");
        $this->db->query("CREATE TABLE {$l}.`news` (`id_news` int NOT NULL, `lampiran` varchar(250) DEFAULT NULL, PRIMARY KEY (`id_news`)) {$c}");
        $this->db->query("CREATE TABLE {$l}.`dibo` (`id_dibo` int NOT NULL, `file_1` varchar(255) NOT NULL, `file_2` varchar(255) DEFAULT NULL, `file_3` varchar(255) DEFAULT NULL, PRIMARY KEY (`id_dibo`)) {$c}");
        $this->db->query("CREATE TABLE {$l}.`form_digital` (`id_form_digital` int NOT NULL, `form_digital` longtext NOT NULL, PRIMARY KEY (`id_form_digital`)) {$c}");
        $this->db->query("CREATE TABLE {$l}.`filled_form_digital` (`nip` varchar(30) NOT NULL, `id_form_digital` int NOT NULL, `response` longtext NOT NULL, PRIMARY KEY (`nip`,`id_form_digital`)) {$c}");

        $this->src->table('popup')->insertBatch([
            ['id_popup' => 1, 'file' => 'assets/upload/popup/p1.png', 'type' => 1],
            ['id_popup' => 2, 'file' => 'assets/upload/popup/video.mp4', 'type' => 2],
            ['id_popup' => 3, 'file' => '../luar.png', 'type' => 1],
            ['id_popup' => 4, 'file' => 'assets/upload/popup/dibuang.png', 'type' => 1],
        ]);
        $this->src->table('banner')->insertBatch([
            ['id_banner' => 1, 'file' => 'assets/upload/banner/b1.jpg'],
            ['id_banner' => 2, 'file' => 'assets/upload/banner/hilang.jpg'],
        ]);
        $this->src->table('news')->insertBatch([
            ['id_news' => 1, 'lampiran' => 'assets/upload/news/n1.pdf'],
            ['id_news' => 2, 'lampiran' => null],
        ]);
        $this->src->table('dibo')->insert(['id_dibo' => 1, 'file_1' => 'assets/upload/dibo/d1.pdf', 'file_2' => '', 'file_3' => null]);
        $this->src->table('form_digital')->insert(['id_form_digital' => 1, 'form_digital' => addslashes((string) json_encode([
            ['type' => 'text', 'name' => 'text-1', 'label' => 'Nama'],
            ['type' => 'file', 'name' => 'file-1', 'label' => 'Lampiran'],
        ]))]);
        $this->src->table('filled_form_digital')->insert(['nip' => self::NIP, 'id_form_digital' => 1, 'response' => (string) json_encode([
            'text-1' => 'Isi', 'file' => ['file-1' => 'assets/upload/fd/' . self::NIP . '/1_1_20260101.pdf'],
        ])]);

        $this->asal   = sys_get_temp_dir() . '/make089-asal-' . bin2hex(random_bytes(4));
        $this->tujuan = sys_get_temp_dir() . '/make089-tujuan-' . bin2hex(random_bytes(4));

        foreach (['popup/p1.png', 'popup/video.mp4', 'popup/dibuang.png', 'banner/b1.jpg', 'news/n1.pdf', 'dibo/d1.pdf', 'fd/' . self::NIP . '/1_1_20260101.pdf'] as $p) {
            $lengkap = $this->asal . '/assets/upload/' . $p;
            @mkdir(dirname($lengkap), 0777, true);
            file_put_contents($lengkap, "isi sintetis {$p}");
        }

        file_put_contents(dirname($this->asal) . '/luar.png', 'di luar akar');
    }

    protected function tearDown(): void
    {
        foreach ([$this->asal, $this->tujuan] as $folder) {
            if (is_dir($folder)) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
                }

                rmdir($folder);
            }
        }

        @unlink(dirname($this->asal) . '/luar.png');

        parent::tearDown();
    }

    private function jalankan(bool $dryRun = false): array
    {
        $k = Keputusan::dariArray(['baris' => ['popup.f7' => ['4' => AturanKonten::BUANG]]]);

        return (new BerkasKonten($this->src, new LocalStorageAdapter($this->asal), new LocalStorageAdapter($this->tujuan), $k))->jalankan($dryRun);
    }

    public function testSalinIdempotenDanKunciSamaDenganTier14(): void
    {
        $dry = $this->jalankan(dryRun: true);
        $this->assertSame(1, $dry['popup']['disalin']);
        $this->assertDirectoryDoesNotExist($this->tujuan, 'dry-run tidak menulis');

        $l = $this->jalankan();
        $this->assertSame(['disalin' => 1, 'sudah_ada' => 0, 'beda' => 0, 'hilang' => 0, 'tidak_sah' => 1, 'dilewati' => 2], $l['popup'], 'video + __buang dilewati; traversal tidak_sah');
        $this->assertSame(['disalin' => 1, 'hilang' => 1], array_intersect_key($l['banner'], ['disalin' => 0, 'hilang' => 0]));
        $this->assertSame(1, $l['berita']['disalin']);
        $this->assertSame(1, $l['dibo']['disalin'], 'kolom kosong/NULL tidak dihitung');
        $this->assertSame(1, $l['form-digital']['disalin']);

        $kunciPopup = AturanKonten::kunciBerkas('popup', 'popup', 'file', '1', 'assets/upload/popup/p1.png', AturanKonten::EKSTENSI_GAMBAR);
        $this->assertNotNull($kunciPopup);
        $this->assertFileExists($this->tujuan . '/' . $kunciPopup);
        $this->assertSame('isi sintetis popup/p1.png', file_get_contents($this->tujuan . '/' . $kunciPopup));

        $konversi = AturanFormDigital::konversiSkema(json_decode(stripslashes((string) $this->src->table('form_digital')->get()->getRow()->form_digital), true));
        $jawaban  = AturanFormDigital::konversiJawaban((string) $this->src->table('filled_form_digital')->get()->getRow()->response, $konversi, 1, self::NIP);
        $this->assertFileExists($this->tujuan . '/' . $jawaban['jawaban']['file_1']['berkas'], 'kunci jawaban = kunci tier 14');

        // Ulang: semua sudah_ada, tidak ada yang disalin lagi.
        $ulang = $this->jalankan();
        $this->assertSame(0, array_sum(array_column($ulang, 'disalin')));
        $this->assertSame(5, array_sum(array_column($ulang, 'sudah_ada')));

        $teks = (string) json_encode($ulang);
        $this->assertStringNotContainsString(self::NIP, $teks, 'laporan tanpa nama berkas/NIP');
        $this->assertStringNotContainsString('assets', $teks);
    }

    public function testIsiBedaTidakDitimpa(): void
    {
        $this->jalankan();

        $kunci = AturanKonten::kunciBerkas('banner', 'banner', 'file', '1', 'assets/upload/banner/b1.jpg', AturanKonten::EKSTENSI_GAMBAR);
        file_put_contents($this->tujuan . '/' . $kunci, 'isi v2 lain');

        $l = $this->jalankan();
        $this->assertSame(1, $l['banner']['beda']);
        $this->assertSame('isi v2 lain', file_get_contents($this->tujuan . '/' . $kunci));
    }

    /**
     * @param array<int|string, string|null> $params
     *
     * @return array{0: int, 1: string}
     */
    private function command(array $params): array
    {
        $this->resetStreamFilterBuffer();
        $kode = (new ImporBerkasKonten(Services::logger(), Services::commands()))->run($params);

        return [$kode, (string) preg_replace('/\e\[[0-9;]*m/', '', $this->getStreamFilterBuffer())];
    }

    public function testCommandValidasiDanDryRunHanyaJumlah(): void
    {
        foreach ([['dasar' => $this->asal], ['sumber' => $this->lgc], ['sumber' => $this->lgc, 'dasar' => $this->asal . '/tidak-ada'], ['sumber' => $this->lgc, 'dasar' => $this->asal . '/assets/upload/popup/p1.png']] as $i => $params) {
            [$kode, $layar] = $this->command($params);
            $this->assertSame(EXIT_USER_INPUT, $kode, "kasus {$i}");
            $this->assertStringContainsString('Pemakaian: impor:berkas-konten', $layar, "kasus {$i}: pesan jelas");
        }

        $konten                  = config(Konten::class);
        $akar                    = $konten->akarPenyimpanan;
        $konten->akarPenyimpanan = $this->tujuan;

        try {
            [$kode, $layar] = $this->command(['sumber' => $this->lgc, 'dasar' => $this->asal, 'dry-run' => null]);
        } finally {
            $konten->akarPenyimpanan = $akar;
        }

        $this->assertSame(EXIT_SUCCESS, $kode, $layar);
        $this->assertStringContainsString('popup: disalin 2, sudah_ada 0, beda 0, hilang 0, tidak_sah 1, dilewati 1', $layar, 'tanpa --keputusan: hanya video dilewati');
        $this->assertStringContainsString('form-digital: disalin 1', $layar);
        $this->assertStringContainsString('Dry-run selesai', $layar);

        foreach ([self::NIP, 'assets', 'p1.png', 'luar.png', $this->asal] as $bocor) {
            $this->assertStringNotContainsString($bocor, $layar, 'keluaran hanya jumlah');
        }

        $this->assertDirectoryDoesNotExist($this->tujuan, 'dry-run tidak menulis');
    }

    public function testSumberHanyaDibaca(): void
    {
        $sebelum = $this->src->query('CHECKSUM TABLE `popup`, `banner`, `news`, `dibo`, `form_digital`, `filled_form_digital`')->getResultArray();
        $this->jalankan();
        $this->assertSame($sebelum, $this->src->query('CHECKSUM TABLE `popup`, `banner`, `news`, `dibo`, `form_digital`, `filled_form_digital`')->getResultArray());
    }
}
