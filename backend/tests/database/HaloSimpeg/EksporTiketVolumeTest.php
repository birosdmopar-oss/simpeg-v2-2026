<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Config\HaloSimpeg;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\HaloSimpegFixtureTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-074 — pengukuran memori nyata ekspor rekap petugas (PRD Fase 8 v1.4 §7.3a `eksporMaksKarakter`, R8-22). Kasus terburuk
 * yang masih lolos pengaman: tepat `eksporMaksBaris` pesan dengan total `SUM(CHAR_LENGTH(msg))` = `eksporMaksKarakter`, lewat
 * request HTTP penuh (kueri, susun baris, `EksporTabel::xlsx`, audit). Memori puncak per request diukur dengan
 * `memory_reset_peak_usage()` sebelum request (pola `DokumenVolumeTest`). Berat (±10 MB data sintetis): di-skip kecuali env
 * `RUN_VOLUME_TEST=1`; hasil lokal dicatat di README bagian ekspor dan `docs/progress/08-HaloSimpeg.md`.
 *
 * @internal
 */
#[Group('volume')]
final class EksporTiketVolumeTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use HaloSimpegFixtureTrait;
    use PegawaiFixtureTrait;

    /** Puncak memori yang diizinkan per request ekspor (MB), separuh `memory_limit` 256M yang disyaratkan README-deploy. */
    public const MAKS_MB = 128;

    public const MAKS_DETIK = 30;

    protected $seed = AuthSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_VOLUME_TEST') !== '1') {
            $this->markTestSkipped('Uji volume ekspor Halo Simpeg hanya dijalankan dengan env RUN_VOLUME_TEST=1.');
        }

        $this->clearAuthState();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    /**
     * Pola isi per kasus: teks Latin (± 1 byte/karakter), CJK (3 byte), emoji (4 byte, terburuk utf8mb4). Ambang dihitung dalam
     * karakter (`CHAR_LENGTH`), jadi kasus terburuk = byte terbanyak per karakter.
     *
     * @return array<string, array{0: string}>
     */
    public static function isiProvider(): array
    {
        return ['latin' => ['Pertanyaan kepegawaian é '], 'cjk-3-byte' => ['汉字测试'], 'emoji-4-byte' => ["\u{1F600}\u{1F4C4}"]];
    }

    #[DataProvider('isiProvider')]
    public function testEksporDiAmbangBarisDanKarakter(string $pola): void
    {
        $config  = config(HaloSimpeg::class);
        $baris   = $config->eksporMaksBaris;
        $perSel  = intdiv($config->eksporMaksKarakter, $baris);
        $k       = $this->buatKategoriHs('Kepegawaian');
        $pemilik = $this->buatAkunUntuk($this->buatPegawai(), Role::PEGAWAI);
        $petugas = $this->buatAkunUntuk($this->buatPegawai(), Role::PTT);
        $this->buatPetugasHs($petugas, [$k]);

        // 100 tiket × 100 pesan, masing-masing tepat `$perSel` karakter.
        $isi = mb_substr(str_repeat($pola, (int) ceil($perSel / mb_strlen($pola))), 0, $perSel);

        for ($t = 0; $t < 100; $t++) {
            $id = $this->buatTiketHs($k, $pemilik);
            $this->db->table('disiplin_ticket')->where('id_inbox', $id)->update(['created_at' => '2026-11-10 03:00:00']);
            $this->db->table('disiplin_ticket_msg')->insertBatch(array_map(static fn (int $i): array => [
                'id_inbox' => $id, 'msg_sender' => $pemilik, 'msg_sender_name' => 'Penanya Volume', 'msg_receiver' => 0, 'msg' => $isi, 'created_at' => '2026-11-10 03:00:00',
            ], range(1, intdiv($baris, 100))));
        }

        $agregat = $this->db->query('SELECT COUNT(*) AS n, SUM(CHAR_LENGTH(msg)) AS c, SUM(LENGTH(msg)) AS b FROM ' . $this->db->prefixTable('disiplin_ticket_msg'))->getRowArray();
        $this->assertSame($baris, (int) $agregat['n']);
        $this->assertLessThanOrEqual($config->eksporMaksKarakter, (int) $agregat['c']);

        gc_collect_cycles();
        $dasar = memory_get_usage();
        memory_reset_peak_usage();
        $mulai = microtime(true);
        $r     = $this->asUser((array) $this->db->table('pengguna')->where('id_pengguna', $petugas)->get()->getRowArray())
            ->get('api/v1/halo-simpeg/tiket/ekspor?ds=2026-11-01&de=2026-11-30');
        $detik = microtime(true) - $mulai;
        $mb    = (memory_get_peak_usage() - $dasar) / 1048576;

        $r->assertStatus(200);
        fwrite(STDERR, sprintf(
            "\n[ukur ekspor HS %s] baris=%d karakter=%d byte=%d puncak_tambahan=%.1f MB dasar=%.1f MB waktu=%.1f s berkas=%.1f MB memory_limit=%s\n",
            (string) $this->dataName(),
            (int) $agregat['n'],
            (int) $agregat['c'],
            (int) $agregat['b'],
            $mb,
            $dasar / 1048576,
            $detik,
            strlen((string) $r->response()->getBody()) / 1048576,
            (string) ini_get('memory_limit'),
        ));
        $this->assertLessThanOrEqual(self::MAKS_MB, $mb);
        $this->assertLessThanOrEqual(self::MAKS_DETIK, $detik);
    }
}
