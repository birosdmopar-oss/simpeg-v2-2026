<?php

declare(strict_types=1);

namespace Tests\Dashboard;

use App\Constants\Role;
use App\Libraries\Dashboard\Dokumen\DokumenService;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestTrait;
use Tests\Support\Dashboard\DokumenUjiTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * F7-15 uji volume (MAKE-037, PRD §7.2.7): 5.000 PNS sintetis (insertBatch di bingkai transaksi uji, hilang saat
 * rollback). Hasil ukur M3: PDF DUK lingkup kementerian 5.000 baris kehabisan memori TCPDF (512 MB) → fallback LOCKED:
 * PDF hanya per unit/satker dan maksimal DokumenService::PDF_MAKS_BARIS baris; XLSX tetap seluruh lingkup.
 *
 * Yang diukur: PDF satker tepat di batas (≤ 30 detik, puncak ≤ 256 MB, valid & > 1 halaman); PDF kementerian dan PDF
 * unit di atas batas → 422 `errors.lingkup`; XLSX DUK 5.000 baris (≤ 10 detik, ≤ 128 MB). Memori puncak per permintaan
 * (`memory_reset_peak_usage()` sebelum tiap permintaan). Lama dan berat: di-skip kecuali env `RUN_VOLUME_TEST=1`
 * (phpunit.dist.xml berkas bersama tidak diubah); hasil lokal dicatat di backend/docs/progress/07-Dashboard.md & ADR-034.
 *
 * @internal
 */
#[Group('volume')]
final class DokumenVolumeTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use DokumenUjiTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    protected $seed = AuthSeeder::class;

    private const BASE = 'api/v1/dokumen';

    /**
     * Sebaran pegawai per satker: A1 tepat di batas PDF, unit A (A1 + A2) di atas batas, total 5.000.
     */
    private const SEBARAN = ['A1' => DokumenService::PDF_MAKS_BARIS, 'A2' => 1650, 'B1' => 1650];

    public const PDF_DETIK = 30;

    public const PDF_MB = 256;

    public const XLSX_DETIK = 10;

    public const XLSX_MB = 128;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_VOLUME_TEST') !== '1') {
            $this->markTestSkipped('Uji volume F7-15 hanya dijalankan dengan env RUN_VOLUME_TEST=1.');
        }

        $this->clearAuthState();
        $this->pasangCacheUji();
        $this->pasangThrottlerUji();
        $this->siapkanMasterDokumen();
        $this->seedVolume();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    public function testPdfPerSatkerDiBatasDanXlsxLimaRibuBaris(): void
    {
        $total = array_sum(self::SEBARAN);
        $this->assertSame($total, $this->dataDokumen($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk', ['per_page' => '1']))['total']);
        fwrite(STDERR, sprintf("\n[volume] PHP %s, memory_limit %s, %s\n", PHP_VERSION, ini_get('memory_limit'), php_uname('s') . ' ' . php_uname('m')));

        // Fallback: PDF kementerian dan PDF di atas batas baris ditolak sebelum render.
        $kementerian = $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk/pdf');
        $kementerian->assertStatus(422);
        $this->assertSame([DokumenService::PESAN_PDF_LINGKUP], $this->json($kementerian)['errors']['lingkup']);

        $unit = $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/duk/pdf', ['lingkup' => 'unit', 'id' => (string) $this->unit['A']]);
        $unit->assertStatus(422);
        $this->assertSame([DokumenService::PESAN_PDF_BATAS], $this->json($unit)['errors']['lingkup']);

        // XLSX seluruh kementerian (diukur lebih dulu: alokasi TCPDF tidak kembali ke OS dan akan mengotori angka puncak).
        [$result, $detik, $mb] = $this->ukur(self::BASE . '/duk/xlsx', []);
        $result->assertStatus(200);
        $this->assertStringStartsWith('PK', $result->response()->getBody());
        fwrite(STDERR, sprintf("[volume] XLSX DUK kementerian %d baris: %.1f s, puncak %.0f MB, %d KB\n", $total, $detik, $mb, (int) (strlen($result->response()->getBody()) / 1024)));
        $this->assertLessThanOrEqual(self::XLSX_DETIK, $detik);
        $this->assertLessThanOrEqual(self::XLSX_MB, $mb);

        // PDF satker tepat di batas.
        [$result, $detik, $mb] = $this->ukur(self::BASE . '/duk/pdf', ['lingkup' => 'satker', 'id' => (string) $this->satker['A1']]);
        $result->assertStatus(200);
        $isi = $result->response()->getBody();
        $this->assertStringStartsWith('%PDF', $isi);
        $halaman = preg_match_all('/\/Type\s*\/Page[^s]/', $isi);
        fwrite(STDERR, sprintf("[volume] PDF DUK satker %d baris: %.1f s, puncak %.0f MB, %d halaman, %d KB\n", self::SEBARAN['A1'], $detik, $mb, $halaman, (int) (strlen($isi) / 1024)));
        $this->assertGreaterThan(1, $halaman);
        $this->assertLessThanOrEqual(self::PDF_DETIK, $detik, 'PDF di batas baris melewati batas waktu (turunkan PDF_MAKS_BARIS)');
        $this->assertLessThanOrEqual(self::PDF_MB, $mb, 'PDF di batas baris melewati batas memori (turunkan PDF_MAKS_BARIS)');
    }

    /**
     * @param array<string, string> $query
     *
     * @return array{0: TestResponse, 1: float, 2: float} respons, detik, MB puncak permintaan ini
     */
    private function ukur(string $uri, array $query): array
    {
        gc_collect_cycles();
        fwrite(STDERR, sprintf("[volume] dasar proses uji sebelum permintaan: %.0f MB\n", memory_get_usage(true) / 1048576));
        memory_reset_peak_usage();
        $mulai  = hrtime(true);
        $result = $this->asRole(Role::SUPER_ADMIN)->get($uri, $query);

        return [$result, (hrtime(true) - $mulai) / 1e9, memory_get_peak_usage(true) / 1048576];
    }

    /**
     * PNS aktif per satker menurut SEBARAN (unit A: satker A1 + A2, unit B: B1) dengan jabatan, pangkat (III/a atau IV/a), TMT acak; NIP sintetis 18 digit
     * yang tidak tumpang tindih dengan fixture lain (awalan 1999…).
     */
    private function seedVolume(): void
    {
        $jabatan = [];

        foreach (array_keys(self::SEBARAN) as $kode) {
            $j              = $this->buatJabatan($this->satker[$kode]);
            $jabatan[$kode] = $j + ['unit' => 'Unit', 'satker' => 'Satker ' . $kode, 'jabatan' => 'Jabatan ' . $kode];
        }

        $pangkat = [$this->pangkat['III/a'] => ['III', 'a', 'III/a'], $this->pangkat['IV/a'] => ['IV', 'a', 'IV/a']];
        $peg     = [];
        $pmj     = [];
        $pkp     = [];

        $i = 0;

        foreach (self::SEBARAN as $kode => $jumlah) {
            $j = $jabatan[$kode];

            for ($n = 0; $n < $jumlah; $n++, $i++) {
                $nip   = sprintf('1999%06d%08d', $i, 10000000 + $i);
                $idP   = (int) array_keys($pangkat)[$i % 2];
                $tahun = 1965 + ($i % 35);
                $peg[] = ['nip' => $nip, 'nama' => 'Pegawai Volume ' . $i, 'tgl_lahir' => sprintf('%04d-%02d-15', $tahun, 1 + ($i % 12)), 'jenis_kelamin' => 1 + ($i % 2), 'status' => 1, 'id_jenis_pegawai' => 1];
                $pmj[] = ['nip' => $nip, 'jenis_jabatan' => 1, 'jenis_mutasi' => 1, 'tmtsk' => '2015-01-01', 'id_unit' => $j['id_unit'], 'id_satker' => $j['id_satker'], 'id_group_jabatan' => $j['id_group_jabatan'], 'id_sub_group_jabatan' => $j['id_sub_group_jabatan'], 'id_jabatan' => $j['id_jabatan'], 'unit' => $j['unit'], 'satker' => $j['satker'], 'jabatan' => $j['jabatan']];
                $pkp[] = ['nip' => $nip, 'id_pangkat' => $idP, 'tmtsk' => sprintf('%04d-04-01', 2005 + ($i % 20)), 'tgl_sk' => '2005-03-01', 'jenis_kp' => 'Reguler', 'gol' => $pangkat[$idP][0], 'ruang' => $pangkat[$idP][1], 'gol_ruang' => $pangkat[$idP][2], 'pangkat' => 'Pangkat ' . $pangkat[$idP][2]];
            }
        }

        foreach ([['pegawai', $peg], ['pegawai_mutasi_jabatan', $pmj], ['pegawai_kp', $pkp]] as [$tabel, $rows]) {
            foreach (array_chunk($rows, 500) as $chunk) {
                $this->db->table($tabel)->insertBatch($chunk);
            }
        }
    }
}
