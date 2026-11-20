<?php

declare(strict_types=1);

namespace Tests\Paritas\Step3\Presensi;

use App\Exceptions\ApiException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\Slip\ImporSlipService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\ImporSlipUjiTrait;
use Throwable;

/**
 * CR-164 butir 8 (pagar paritas step 3, test karakterisasi): kunci baris commit impor slip vs koneksi DB kedua. Commit
 * memegang `SELECT … WHERE nip IN (…) FOR UPDATE` (ImporSlipService::kunciSudahAda, ImporSlipService.php:389-390) selama
 * transaksi; koneksi kedua (sesi `innodb_lock_wait_timeout = 1`) menjalankan UPDATE bentuk "buka slip"
 * (SlipGajiService.php:134: `WHERE id = ? AND viewed_at IS NULL`) dari seam `kaitSebelumTulis`, saat kunci commit masih
 * dipegang. Koneksi kedua butuh data ter-commit → `db-isolasi-penuh`; data & koneksi dibersihkan di finally/tearDown.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class ImporSlipKunciParitasTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use ImporSlipUjiTrait;
    use PegawaiFixtureTrait;

    private const ER_LOCK_WAIT_TIMEOUT = 1205;

    private ?BaseConnection $lain = null;

    /** @var list<string> */
    private array $nipDibuat = [];

    /** @var list<int> */
    private array $penggunaDibuat = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanImporSlip();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->lain !== null) {
                $this->lain->transRollback();
                $this->lain->close();
                $this->lain = null;
            }

            $this->db->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');

            if ($this->nipDibuat !== []) {
                $this->db->table('gaji_pegawai')->whereIn('nip', $this->nipDibuat)->delete();
                $this->db->table('pegawai_mutasi_jabatan')->whereIn('nip', $this->nipDibuat)->delete();
                $this->db->table('pegawai')->whereIn('nip', $this->nipDibuat)->delete();
            }

            $this->db->table('audit_logs')->where('entity', ImporSlipService::ENTITAS_AUDIT)->delete();

            if ($this->penggunaDibuat !== []) {
                $this->db->table('pengguna')->whereIn('id_pengguna', $this->penggunaDibuat)->delete();
            }
        } finally {
            $this->nipDibuat      = [];
            $this->penggunaDibuat = [];
            $this->bersihkanImporSlip();
            $this->clearAuthState();
            parent::tearDown();
        }
    }

    /**
     * Perilaku saat ini; akan diubah di CR-167 (#18.2).
     */
    public function testPerilakuSebelumCr167BukaSlipPeriodeLainIkutMenunggu(): void
    {
        // Perilaku saat ini; akan diubah di CR-167 (#18.2).
        [$nip, $admin, $token] = $this->siapkan();
        $idAgustus             = $this->slip($nip, 8);
        $this->slip($nip, 9);

        $hasil = $this->commitDenganBukaDariKoneksiLain($admin, $token, $idAgustus);

        // perilaku sekarang (dikunci CR-164): FOR UPDATE memakai `WHERE nip IN (…)` tanpa periode, jadi baris Agustus (tidak
        // ada di berkas) ikut terkunci — UPDATE buka slip Agustus dari koneksi lain menunggu lalu 1205.
        $this->assertSame(self::ER_LOCK_WAIT_TIMEOUT, $hasil['kode_lain'], 'UPDATE buka slip periode lain ikut menunggu kunci impor');
        $this->assertSame([0, 1], [$hasil['commit']['disisip'], $hasil['commit']['diperbarui']], 'commit impor tetap berhasil');
        $this->assertNull($this->db->table('gaji_pegawai')->where('id', $idAgustus)->get()->getRowArray()['viewed_at'], 'buka slip Agustus tidak terjadi');
    }

    public function testBukaSlipPeriodeSamaIkutMenunggu(): void
    {
        [$nip, $admin, $token] = $this->siapkan();
        $idSeptember           = $this->slip($nip, 9);

        $hasil = $this->commitDenganBukaDariKoneksiLain($admin, $token, $idSeptember);

        $this->assertSame(self::ER_LOCK_WAIT_TIMEOUT, $hasil['kode_lain'], 'UPDATE buka slip periode yang sedang diimpor menunggu kunci impor');
        $this->assertSame([0, 1], [$hasil['commit']['disisip'], $hasil['commit']['diperbarui']]);
        $row = $this->db->table('gaji_pegawai')->where('id', $idSeptember)->get()->getRowArray();
        $this->assertSame([null, '3000000.00'], [$row['viewed_at'], $row['gjpokok']], 'buka gagal; nominal dari berkas tersimpan');
    }

    /**
     * Arah sebaliknya: koneksi lain memegang kunci baris slip; commit impor menunggu lalu 1205 → rollback + 500 generik.
     */
    public function testCommitMenungguKunciKoneksiLainJadi500GagalCommit(): void
    {
        [$nip, $admin, $token] = $this->siapkan();
        $this->slip($nip, 9);
        $this->lain = Database::connect('tests', false);
        $this->lain->transBegin();
        $this->lain->query('SELECT id FROM ' . $this->lain->prefixTable('gaji_pegawai') . ' WHERE nip = ' . $this->lain->escape($nip) . ' FOR UPDATE');
        $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            (new ImporSlipService())->commit($this->auth($admin), $token, true);
            $this->fail('commit harus gagal karena lock wait');
        } catch (ApiException $e) {
            // perilaku sekarang (dikunci CR-164): ImporSlipService.php:241-245 — 1205 tidak dipetakan khusus (bukan 409/503),
            // jatuh ke 500 generik "gagal commit".
            $this->assertSame([500, ImporSlipService::PESAN_GAGAL_COMMIT, null], [$e->getStatusCode(), $e->getMessage(), $e->getKode()]);
        } finally {
            $this->lain->transRollback();
            $this->db->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }

        $this->assertSame('0.00', $this->db->table('gaji_pegawai')->where('nip', $nip)->get()->getRowArray()['tjistri'], 'rollback: nominal berkas tidak tersimpan');
        $this->assertSame(0, $this->db->table('audit_logs')->where('entity', ImporSlipService::ENTITAS_AUDIT)->where('event', 'import')->countAllResults());
        $this->assertSame([], $this->isiDirPratinjau());
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    private function siapkan(): array
    {
        $nip                    = $this->buatPegawai(['nama' => 'Citra Tiga']);
        $this->nipDibuat[]      = $nip;
        $admin                  = $this->adminSlip();
        $this->penggunaDibuat[] = (int) $admin['id_pengguna'];
        $token                  = $this->dataOk($this->unggahSlip($admin, $this->berkasSlip([$this->barisGpp($nip, 9, 2026, 'Citra Tiga')], [$this->barisTk($nip, 9, 2026, 'Citra Tiga')])))['token_pratinjau'];
        Services::resetSingle('response');

        return [$nip, $admin, (string) $token];
    }

    private function slip(string $nip, int $bulan): int
    {
        $this->db->table('gaji_pegawai')->insert(['nip' => $nip, 'bulan' => $bulan, 'tahun' => 2026, 'gjpokok' => '1000000.00', 'tjistri' => '0.00', 'created_at' => '2026-09-01 00:00:00']);

        return (int) $this->db->insertID();
    }

    /**
     * Commit impor; tepat sebelum tulis pertama (kunci FOR UPDATE sudah dipegang) koneksi lain mencoba UPDATE buka slip.
     *
     * @param array<string, mixed> $admin
     *
     * @return array{kode_lain: int|null, commit: array<string, mixed>}
     */
    private function commitDenganBukaDariKoneksiLain(array $admin, string $token, int $idSlip): array
    {
        $this->lain = Database::connect('tests', false);
        $this->lain->query('SET SESSION innodb_lock_wait_timeout = 1');
        $lain = $this->lain;
        $kode = null;
        $kait = static function () use ($lain, $idSlip, &$kode): void {
            if ($kode !== null) {
                return;
            }

            try {
                $ok   = $lain->table('gaji_pegawai')->where('id', $idSlip)->where('viewed_at', null)->update(['viewed_at' => '2026-10-27 02:00:00', 'qr_token' => str_repeat('d', 64)]);
                $kode = $ok === false ? (int) ($lain->error()['code'] ?? -1) : 0;
            } catch (Throwable $e) {
                $kode = (int) $e->getCode();
            }
        };

        $commit = (new ImporSlipService(null, null, null, null, $kait))->commit($this->auth($admin), $token, true);

        return ['kode_lain' => $kode, 'commit' => $commit];
    }

    /**
     * @param array<string, mixed> $akun
     */
    private function auth(array $akun): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => (string) $akun['id_pengguna'], 'id_pengguna' => (int) $akun['id_pengguna'], 'role' => (int) $akun['user_level'], 'id_satker' => $akun['id_satker'], 'nip' => null]);

        return $auth;
    }
}
