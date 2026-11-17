<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg\Chatbot;

use App\Constants\Role;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestLogger;
use CodeIgniter\Test\TestResponse;
use Config\Chatbot;
use Config\Logger as LoggerConfig;
use Config\Services;
use ReflectionProperty;
use Stringable;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\Chatbot\ChatbotFixtureTrait;

/**
 * AC-E10.19 sisi log aplikasi (CR-133; PRD Fase 8 v1.3 `:1215-1217`): isi pesan pengguna dan `log_chatbot` tidak masuk log
 * aplikasi (semua level) maupun `audit_logs`. Logger uji menampung semua entri di memori (pesan + konteks). Data sintetis.
 *
 * @internal
 */
final class LogAplikasiChatbotTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use ChatbotFixtureTrait;
    use FeatureTestTrait;

    private const URL = 'api/v1/halo-simpeg/chatbot/';

    /** Teks penanda unik; tidak mungkin muncul di log kecuali isi pesan bocor. */
    private const PENANDA = 'PenandaCR133zqx';

    protected $seed = AuthSeeder::class;

    private int $awalOpLogs = 0;

    /**
     * Konteks entri `service('logger')` (diisi logger uji).
     *
     * @var list<string>
     */
    public static array $konteksLog = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangConfigChatbot(self::KUNCI_UJI, static function (Chatbot $c): void {
            $c->throttleBalas = 4; // cocok, tidak ditemukan, 422, satu lagi → kelima 429
        });
        $this->pasangThrottlerChatbot();

        $kat = $this->buatKategori('Cuti', 1, 1);
        $this->buatQna('cara mengajukan cuti tahunan', $kat, [$this->buatTag('cuti')]);
        $this->buatQna('cara mengganti kata sandi', $this->buatKategori('Akun', 1, 1));
        $this->buatQna('syarat pensiun dini', $kat);

        // Dua jalur log CI4: `log_message()` di ENVIRONMENT testing selalu memakai TestLogger (dibaca dari `op_logs` statis,
        // dihitung sejak titik ini), sedangkan `service('logger')` diganti logger uji yang juga menyimpan konteks.
        $this->awalOpLogs = count(self::opLogs());
        self::$konteksLog = [];
        $logger           = new class (config(LoggerConfig::class)) extends TestLogger {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                LogAplikasiChatbotTest::$konteksLog[] = (string) json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);
                parent::log($level, $message, $context);
            }
        };
        Services::injectMock('logger', $logger);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        Factories::reset('config');
        parent::tearDown();
    }

    public function testIsiPesanTidakMasukLogAplikasiMaupunAuditLogs(): void
    {
        $p = self::PENANDA;

        // Kirim: cocok, tidak ditemukan, lalu penilaian (sah dan 422).
        // Penanda menurunkan skor TF-IDF, jadi jalur cocok memakai pertanyaan persis; penanda ada di semua jalur lain.
        $cocok = $this->json($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => 'cara mengajukan cuti tahunan']), 200);
        $this->assertTrue($cocok['cocok']);
        $this->assertFalse($this->json($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => "{$p} tidak ada padanannya"]), 200)['cocok']);
        $this->json($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/feedback', ['id_log' => $cocok['id_log'], 'nilai' => 1, 'token' => $cocok['token_feedback']]), 200);
        $this->json($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/feedback', ['id_log' => $cocok['id_log'], 'nilai' => $p, 'token' => $p]), 422);

        // 422: pesan terlalu panjang; 429: kuota balas habis.
        $this->json($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => str_repeat("{$p} ", 40)]), 422);
        $this->json($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => $p]), 200);
        $this->json($this->asRole(Role::PEGAWAI)->post(self::URL . 'widget/balas', ['pesan' => $p]), 429);

        // Riwayat admin memuat pesan pengguna (di respons, bukan di log); parameter tak dikenal juga tidak dilog.
        $riwayat = $this->asRole(Role::SUPER_ADMIN)->get(self::URL . "riwayat?cari={$p}");
        $riwayat->assertStatus(200);
        $this->assertStringContainsString($p, $riwayat->getJSON(), 'pesan memang tersimpan di log_chatbot');
        $this->asRole(Role::SUPER_ADMIN)->get(self::URL . 'statistik')->assertStatus(200);

        $this->assertGreaterThan(0, $this->db->table('log_chatbot')->like('pesan_user', $p)->countAllResults(), 'alur benar-benar dijalankan');

        // Kontrol positif: logger uji benar-benar menampung entri log_message() selama alur.
        log_message('debug', 'kontrol logger CR-133');
        service('logger')->info('kontrol service logger CR-133');
        $entri = $this->entriLog();
        $this->assertNotSame([], array_filter($entri, static fn (string $e): bool => str_contains($e, 'kontrol logger CR-133')));
        $this->assertNotSame([], array_filter($entri, static fn (string $e): bool => str_contains($e, 'kontrol service logger CR-133')));

        $bocor = array_values(array_filter($entri, static fn (string $e): bool => stripos($e, $p) !== false));
        $this->assertSame([], $bocor, 'isi pesan tidak boleh ada di log aplikasi (AC-E10.19)');

        $this->assertSame(0, $this->hitungAudit($p), 'isi pesan tidak masuk audit_logs');
        $this->assertSame(0, $this->hitungAudit('log_chatbot'), 'log_chatbot tanpa audit');
    }

    /**
     * @return array<string, mixed>
     */
    private function json(TestResponse $r, int $status): array
    {
        $r->assertStatus($status);

        return (array) (json_decode($r->getJSON(), true)['data'] ?? []);
    }

    /**
     * Semua entri log sejak setUp: pesan (level + teks final) dan konteks logger uji.
     *
     * @return list<string>
     */
    private function entriLog(): array
    {
        $pesan = array_map(
            static fn (array $l): string => strtolower((string) $l['level']) . ' ' . $l['message'],
            array_slice(self::opLogs(), $this->awalOpLogs),
        );

        return [...$pesan, ...self::$konteksLog];
    }

    /**
     * @return list<array{level: mixed, message: string, file: string|null}>
     */
    private static function opLogs(): array
    {
        /** @var list<array{level: mixed, message: string, file: string|null}> */
        return (new ReflectionProperty(TestLogger::class, 'op_logs'))->getValue();
    }

    private function hitungAudit(string $teks): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM ' . $this->db->escapeIdentifiers($this->db->prefixTable('audit_logs')) . " WHERE CONCAT_WS(' ', " . $this->kolomAudit() . ') LIKE ?',
            ['%' . $teks . '%'],
        )->getRow('n');
    }

    /**
     * Semua kolom teks `audit_logs` (dibaca dari skema, supaya kolom baru ikut diperiksa).
     */
    private function kolomAudit(): string
    {
        $kolom = array_column($this->db->query(
            "SELECT COLUMN_NAME AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND DATA_TYPE IN ('char','varchar','text','mediumtext','longtext','json')",
            [$this->db->getDatabase(), $this->db->prefixTable('audit_logs')],
        )->getResultArray(), 'n');
        $this->assertNotSame([], $kolom);

        return implode(', ', array_map(static fn (string $k): string => "`{$k}`", $kolom));
    }
}
