<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\HaloSimpegFixtureTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * CR-149 arah sebaliknya: alur tiket helpdesk Halo Simpeg (buat → balas petugas → balas pemilik → tutup) tidak menyentuh
 * tabel layanan Fase 4 (TB / IB / PMK, petugas layanan, `inbox` layanan, `document_attachment`; PRD Fase 8 "tidak ter-mix").
 * Arah layanan → tiket: `Tests\Layanan\LayananTanpaTiketTest`. Data sintetis.
 *
 * @internal
 */
final class TiketTanpaLayananTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use HaloSimpegFixtureTrait;
    use PegawaiFixtureTrait;

    private const URL = 'api/v1/halo-simpeg/';

    protected $seed = AuthSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangThrottlerHs();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    public function testAlurTiketTidakMenyentuhTabelLayanan(): void
    {
        $kategori = $this->buatKategoriHs('Kepegawaian');
        $pemilik  = $this->akunPegawai('Pelapor CR149', Role::PEGAWAI);
        $petugas  = $this->idAkunRole(Role::SUPER_ADMIN);
        $this->buatPetugasHs($petugas, [$kategori]);

        $awal = $this->jejakLayanan();

        $buat = $this->sebagai($pemilik)->post(self::URL . 'tiket', ['id_question_category' => $kategori, 'subject' => 'Tanya tugas belajar', 'msg' => 'Bagaimana alur izin belajar?']);
        $buat->assertStatus(201);
        $id = (int) json_decode($buat->getJSON(), true)['data']['id'];

        $this->sebagai($petugas)->post(self::URL . "tiket/{$id}/pesan", ['msg' => 'Silakan ajukan lewat menu layanan.'])->assertStatus(201);
        $this->sebagai($pemilik)->post(self::URL . "tiket/{$id}/pesan", ['msg' => 'Terima kasih.'])->assertStatus(201);
        $this->sebagai($petugas)->post(self::URL . "tiket/{$id}/tutup")->assertStatus(200);
        $this->assertSame(1, (int) $this->db->table('disiplin_ticket')->where('id_inbox', $id)->get()->getRow('status'), 'tiket tertutup');

        $this->assertSame(3, $this->db->table('disiplin_ticket_msg')->where('id_inbox', $id)->countAllResults(), 'alur benar-benar dijalankan');
        $this->assertSame($awal, $this->jejakLayanan(), 'tiket helpdesk tidak menyentuh tabel layanan');
    }

    /**
     * Jumlah baris tiap tabel layanan Fase 4 (dibaca dari skema, supaya tabel riwayat TB/IB/PMK baru ikut diperiksa).
     *
     * @return array<string, int>
     */
    private function jejakLayanan(): array
    {
        $pola  = array_map(fn (string $p): string => $this->db->prefixTable($p), ['riwayat_tb%', 'riwayat_ib%', 'riwayat_pmk%']);
        $tabel = array_column($this->db->query(
            'SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND (TABLE_NAME LIKE ? OR TABLE_NAME LIKE ? OR TABLE_NAME LIKE ?) ORDER BY TABLE_NAME',
            [$this->db->getDatabase(), ...$pola],
        )->getResultArray(), 'n');
        $this->assertGreaterThanOrEqual(3, count($tabel), 'tabel riwayat TB/IB/PMK ditemukan');

        $hasil = [];

        foreach ([...$tabel, $this->db->prefixTable('petugas_layanan'), $this->db->prefixTable('inbox'), $this->db->prefixTable('inbox_msg'), $this->db->prefixTable('document_attachment')] as $t) {
            $hasil[$t] = (int) $this->db->query('SELECT COUNT(*) AS n FROM ' . $this->db->escapeIdentifiers($t))->getRow('n');
        }

        $hasil['audit_layanan'] = $this->db->table('audit_logs')->groupStart()->like('entity', 'riwayat_tb', 'after')->orLike('entity', 'riwayat_ib', 'after')->orLike('entity', 'riwayat_pmk', 'after')->orWhereIn('entity', ['petugas_layanan', 'inbox', 'document_attachment'])->groupEnd()->countAllResults();

        return $hasil;
    }

    private function akunPegawai(string $nama, int $role): int
    {
        return $this->buatAkunUntuk($this->buatPegawai(['nama' => $nama]), $role);
    }

    private function idAkunRole(int $role): int
    {
        return (int) $this->db->table('pengguna')->where('username', AuthSeeder::nipForRole($role))->get()->getRow('id_pengguna');
    }

    private function sebagai(int $idPengguna): static
    {
        return $this->asUser((array) $this->db->table('pengguna')->where('id_pengguna', $idPengguna)->get()->getRowArray());
    }
}
