<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\Laporan\PenyesuaianTukinService;
use CodeIgniter\Database\BaseConnection;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Throwable;

/**
 * Syarat DB Validator C-1 untuk DBV-024 (PRD 5B §7.3.10, MAKE-045): dua permintaan tambah/ubah bersamaan untuk NIP yang
 * sama tidak boleh sama-sama lolos cek tumpang tindih. Permintaan A memegang kunci `FOR UPDATE` baris pegawai; di seam
 * `kaitSetelahKunci` (setelah kunci, sebelum cek rentang) permintaan B dijalankan lewat koneksi DB kedua dengan
 * `innodb_lock_wait_timeout = 1` → B tertahan di kunci yang sama (1205) dan mendapat 409 `konflik_data` (keputusan
 * reviewer CR 28-10-2026, bukan 500), tanpa menulis apa pun. Setelah A commit, B diulang
 * → 422 tumpang tindih; hanya satu baris aktif. NIP lain tidak tertahan. Koneksi kedua melihat/menulis data test →
 * `db-isolasi-penuh`.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class PenyesuaianTukinKonkurensiTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    public function testTambahBersamaanNipSamaBerurutan(): void
    {
        $nip   = $this->buatPegawai();
        $lain  = $this->koneksiLain();
        $kodeB = null;

        $kait = function () use ($lain, $nip, &$kodeB): void {
            $kodeB = $this->kodeGalat(fn () => (new PenyesuaianTukinService($lain))->tambah($this->admin(), $this->isi($nip, '2024-05', '2024-07')));
        };

        try {
            (new PenyesuaianTukinService($this->db, null, $kait))->tambah($this->admin(), $this->isi($nip, '2024-04', '2024-06'));
            $this->assertSame([409, 'konflik_data'], $kodeB, 'B tertahan kunci per NIP milik A (1205) → 409 konflik_data, bukan lolos cek');

            try {
                (new PenyesuaianTukinService($lain))->tambah($this->admin(), $this->isi($nip, '2024-05', '2024-07'));
                $this->fail('B setelah A commit harus 422 tumpang tindih');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('bulan_mulai', $e->getErrors());
            }
        } finally {
            $lain->close();
        }

        $this->assertSame(1, $this->db->table('penyesuaian_tukin')->where('nip', $nip)->where('status', 1)->countAllResults());
    }

    public function testUbahBersamaanNipSamaBerurutan(): void
    {
        $nip     = $this->buatPegawai();
        $service = new PenyesuaianTukinService($this->db);
        $a       = $service->tambah($this->admin(), $this->isi($nip, '2024-04', '2024-04'));
        $b       = $service->tambah($this->admin(), $this->isi($nip, '2024-08', '2024-08'));
        $lain    = $this->koneksiLain();
        $kodeB   = null;

        // A memperluas baris a ke 2024-04..06; B bersamaan memperluas baris b ke 2024-05..08 (beririsan dengan hasil A).
        $kait = function () use ($lain, $nip, $b, &$kodeB): void {
            $kodeB = $this->kodeGalat(fn () => (new PenyesuaianTukinService($lain))->ubah($this->admin(), $b['id'], $this->isi($nip, '2024-05', '2024-08')));
        };

        try {
            (new PenyesuaianTukinService($this->db, null, $kait))->ubah($this->admin(), $a['id'], $this->isi($nip, '2024-04', '2024-06'));
            $this->assertSame([409, 'konflik_data'], $kodeB);

            try {
                (new PenyesuaianTukinService($lain))->ubah($this->admin(), $b['id'], $this->isi($nip, '2024-05', '2024-08'));
                $this->fail('B setelah A commit harus 422');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('bulan_mulai', $e->getErrors());
            }
        } finally {
            $lain->close();
        }

        $this->seeInDatabase('penyesuaian_tukin', ['id_penyesuaian_tukin' => $b['id'], 'bulan_mulai' => '2024-08-01']);
    }

    public function testNipLainTidakTertahan(): void
    {
        $nip     = $this->buatPegawai();
        $lain    = $this->buatPegawai();
        $koneksi = $this->koneksiLain();
        $hasilB  = null;

        $kait = function () use ($koneksi, $lain, &$hasilB): void {
            $hasilB = (new PenyesuaianTukinService($koneksi))->tambah($this->admin(), $this->isi($lain, '2024-04', '2024-06'));
        };

        try {
            (new PenyesuaianTukinService($this->db, null, $kait))->tambah($this->admin(), $this->isi($nip, '2024-04', '2024-06'));
        } finally {
            $koneksi->close();
        }

        $this->assertIsArray($hasilB);
        $this->assertSame($lain, $hasilB['nip']);
    }

    private function koneksiLain(): BaseConnection
    {
        $lain = db_connect('tests', false);
        $lain->query('SET SESSION innodb_lock_wait_timeout = 1');

        return $lain;
    }

    /**
     * @return array{0: int, 1: string|null}|null [status HTTP, `code`] bila B gagal dengan ApiException
     */
    private function kodeGalat(callable $aksi): ?array
    {
        try {
            $aksi();
        } catch (ApiException $e) {
            return [$e->getStatusCode(), $e->getKode()];
        } catch (Throwable $e) {
            $this->fail('galat kunci harus dipetakan ke ApiException 409, bukan ' . $e::class . ' (' . $e->getCode() . ')');
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function isi(string $nip, string $mulai, string $akhir): array
    {
        return ['nip' => $nip, 'bulan_mulai' => $mulai, 'bulan_akhir' => $akhir, 'persen' => '-20', 'alasan' => 'Uji konkurensi'];
    }

    private function admin(): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => '9103', 'role' => Role::SUPER_ADMIN]);

        return $auth;
    }
}
