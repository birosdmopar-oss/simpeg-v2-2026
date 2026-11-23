<?php

declare(strict_types=1);

namespace Tests\Layanan\Esign;

use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Esign\MockEsignAdapter;
use App\Libraries\Layanan\Cuti\CutiService;
use App\Libraries\Layanan\Cuti\EsignCutiService;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\EsignUjiTrait;

/**
 * CR-180a (K12 opsi d, analisis HC 22-11 §4): cuti legacy hasil impor yang sudah ber-TTE di legacy belum punya
 * `document_attachment` kode 45 sampai pemetaan cutover (CR-180b) jalan. Legacy `L_cuti::process_esign` (allow_esign = 1)
 * mengisi `file_sc_signed_bef` (PDF pra-TTE) DAN `file_surat_cuti` (PDF ber-TTE); v2 tidak pernah mengisi kedua kolom itu.
 * Pasangan itu kini dianggap ber-TTE: aksi `esign`/`esign_atas_nama` tersembunyi (detail dan daftar) dan
 * `POST cuti/{id}/esign` → 422 "Surat cuti sudah ditandatangani." tanpa gateway, tanpa surat baru, tanpa `esign_hist`.
 * Tanpa kueri tambahan di daftar.
 *
 * Cuti legacy final yang hanya punya `file_surat_cuti` (disetujui tanpa TTE di legacy) TIDAK diubah: perilaku sekarang
 * dikunci test (menunggu QA-30).
 *
 * @internal
 */
final class EsignTteLegacyTest extends DatabaseTestCase
{
    use EsignUjiTrait;

    private const NIK_PYB = '3171000000000088';

    /** Path sintetis kolom legacy (bentuk `arsip/...`, tanpa berkas nyata). */
    private const LEGACY_TTE = ['file_sc_unsign' => 'cuti/2025/01/sc_unsign_uji.pdf', 'file_sc_signed_bef' => 'cuti/2025/01/sc_bef_uji.pdf', 'file_surat_cuti' => 'cuti/2025/01/sc_signed_uji.pdf'];

    protected $seed = MasterDataSeeder::class;

    public function testLegacyBerTteTanpaKode45TombolTersembunyiDanTerbitkan422TanpaGateway(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $this->tandaiLegacy($id, self::LEGACY_TTE);

        // Penanda tangan (role 2) hanya melihat cuti final lewat detail (antrean `persetujuan` memuat yang menunggu saja).
        $this->assertFalse($this->aksiDetail($a['pyb']['auth'], $id)['esign'], 'detail: tombol TTE tersembunyi');

        $gateway = new class () extends MockEsignAdapter {
            public int $panggilan = 0;

            public function signPdf(#[\SensitiveParameter] string $pdfContent, #[\SensitiveParameter] string $nip, #[\SensitiveParameter] string $passphrase, array $options = []): array
            {
                $this->panggilan++;

                return parent::signPdf($pdfContent, $nip, $passphrase, $options);
            }
        };

        try {
            $this->esignService([], $gateway)->terbitkan($a['pyb']['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], '10.0.0.5', 'PHPUnit');
            $this->fail('Seharusnya 422 sudah ditandatangani');
        } catch (ValidationException $e) {
            $this->assertSame([EsignCutiService::PESAN_SUDAH_TTE], $e->getErrors()['status']);
        }

        $this->assertSame(0, $gateway->panggilan, 'gateway tidak dipanggil');
        $this->assertSame([], $this->lampiranUji->daftar($a['pemohon']['nip'], CutiService::ID_SURAT_BELUM_TTE, $id), 'tanpa surat belum TTE baru');
        $this->assertSame([], $this->lampiranUji->daftar($a['pemohon']['nip'], CutiService::ID_SURAT_TTE, $id), 'tanpa surat ber-TTE baru');
        $this->assertSame(0, $this->db->table('esign_hist')->where('id_rw', $id)->countAllResults(), 'tanpa esign_hist baru');
        $this->assertSame(self::LEGACY_TTE['file_surat_cuti'], $this->cutiService()->ambil($id)['file_surat_cuti'], 'kolom legacy utuh');
    }

    /**
     * R1 review: `GET cuti/{id}/esign/status` (statusSertifikat) memakai pemeriksaan yang sama → 422 sebelum `checkUser`.
     */
    public function testLegacyBerTteStatusSertifikat422TanpaCheckUser(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $this->tandaiLegacy($id, self::LEGACY_TTE);

        $gateway = new class () extends MockEsignAdapter {
            public int $cekUser = 0;

            public function checkUser(#[\SensitiveParameter] string $nip): array
            {
                $this->cekUser++;

                return parent::checkUser($nip);
            }
        };

        try {
            $this->esignService([], $gateway)->statusSertifikat($a['pyb']['auth'], $id);
            $this->fail('Seharusnya 422 sudah ditandatangani');
        } catch (ValidationException $e) {
            $this->assertSame([EsignCutiService::PESAN_SUDAH_TTE], $e->getErrors()['status']);
        }

        $this->assertSame(0, $gateway->cekUser, 'checkUser gateway tidak dipanggil');
    }

    public function testLegacyBerTteAtasNamaSuperAdminTersembunyiDanDitolak(): void
    {
        ['id' => $id, 'admin' => $admin] = $this->finalAtasNama();
        $this->assertTrue($this->aksiDetail($admin['auth'], $id)['esign_atas_nama'], 'prasyarat: sebelum ditandai legacy, atas nama tersedia');

        $this->tandaiLegacy($id, self::LEGACY_TTE);

        $this->assertFalse($this->aksiDetail($admin['auth'], $id)['esign_atas_nama'], 'detail');
        $this->assertFalse($this->aksiDaftar($admin['auth'], $id, CutiService::MODE_ADMIN)['esign_atas_nama'], 'daftar');

        try {
            $this->esignService()->terbitkan($admin['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI, 'atas_nama' => true], '10.0.0.9', 'PHPUnit');
            $this->fail('Seharusnya 422 sudah ditandatangani');
        } catch (ValidationException $e) {
            $this->assertSame([EsignCutiService::PESAN_SUDAH_TTE], $e->getErrors()['status']);
        }

        $this->assertSame(0, $this->db->table('esign_hist')->where('id_rw', $id)->countAllResults());
    }

    /**
     * Daftar admin (Super Admin, item ⋮ "… atas nama", MAKE-075): penanda legacy dibaca dari baris `rc.*` yang sudah dimuat.
     * R2 review: dua baris legacy atas nama + pemanasan; sesudah ditandai tidak ada SQL ke `audit_logs` sama sekali — baik
     * lookup halaman (`finalAtasNamaBanyak`) maupun per baris (`finalAtasNama` di `aksi`, yang tidak dievaluasi karena
     * `! $adaSuratTte` lebih dulu). Bila urutan itu dipindah, test ini merah (N+1).
     */
    public function testDaftarAdminTanpaKueriTambahanUntukPenandaLegacy(): void
    {
        ['id' => $id1, 'admin' => $admin] = $this->finalAtasNama();
        ['id' => $id2]                    = $this->finalAtasNama();
        $svc                              = $this->cutiService();
        $auth                             = $admin['auth'];
        $daftar                           = static fn (): array => $svc->daftar($auth, ['mode' => CutiService::MODE_ADMIN, 'per_page' => '100']);

        $daftar(); // pemanasan (cache metadata koneksi)
        [$awal, $sebelum] = $this->rekam($daftar);
        $this->assertTrue($this->itemDari($awal, $id1)['aksi']['esign_atas_nama'], 'prasyarat');
        $this->assertTrue($this->itemDari($awal, $id2)['aksi']['esign_atas_nama'], 'prasyarat');
        $this->assertSame(1, $this->jumlahAuditLogs($sebelum), 'prasyarat: satu lookup atas nama per halaman');

        $this->tandaiLegacy($id1, self::LEGACY_TTE);
        $this->tandaiLegacy($id2, self::LEGACY_TTE);
        [$hasil, $sesudah] = $this->rekam($daftar);

        $this->assertSame(0, $this->jumlahAuditLogs($sesudah), 'tanpa lookup audit_logs untuk baris TTE legacy (tanpa N+1)');
        $this->assertSame(count($sebelum) - 1, count($sesudah), 'tanpa kueri tambahan (lookup halaman hilang)');
        $this->assertFalse($this->itemDari($hasil, $id1)['aksi']['esign_atas_nama']);
        $this->assertFalse($this->itemDari($hasil, $id2)['aksi']['esign_atas_nama']);
    }

    /**
     * Menunggu QA-30: cuti legacy final yang di legacy disetujui TANPA TTE (`allow_esign` ≠ 1, input admin, regenerasi) hanya
     * punya `file_surat_cuti` (surat tanpa TTE). Perilaku SEKARANG dikunci, tidak diubah CR-180a: penanda tangan tetap
     * melihat tombol TTE, dan menandatangani membuat surat belum TTE baru dari templat v2 lalu menyimpan versi ber-TTE.
     */
    public function testMenungguQa30LegacyFinalHanyaFileSuratCutiPerilakuSekarangDikunci(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $this->tandaiLegacy($id, ['file_surat_cuti' => 'cuti/2025/01/sc_tanpa_tte_uji.pdf']);

        $this->assertTrue($this->aksiDetail($a['pyb']['auth'], $id)['esign'], 'menunggu QA-30: tombol TTE tetap ada');

        $this->esignService()->terbitkan($a['pyb']['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], '10.0.0.5', 'PHPUnit');

        $this->assertCount(1, $this->lampiranUji->daftar($a['pemohon']['nip'], CutiService::ID_SURAT_BELUM_TTE, $id), 'menunggu QA-30: surat dari templat v2');
        $this->assertCount(1, $this->lampiranUji->daftar($a['pemohon']['nip'], CutiService::ID_SURAT_TTE, $id));
    }

    /**
     * Hanya `file_sc_signed_bef` (tanpa `file_surat_cuti`, mis. data rusak/parsial) bukan penanda ber-TTE: perilaku tidak
     * berubah (tombol TTE tetap ada).
     */
    public function testHanyaFileScSignedBefBukanPenandaTte(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $this->tandaiLegacy($id, ['file_sc_signed_bef' => 'cuti/2025/01/sc_bef_uji.pdf', 'file_surat_cuti' => '  ']);

        $this->assertTrue($this->aksiDetail($a['pyb']['auth'], $id)['esign']);
    }

    // ---- Bantuan -------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $kolom
     */
    private function tandaiLegacy(int $id, array $kolom): void
    {
        $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->update($kolom);
    }

    /**
     * @return array<string, mixed>
     */
    private function aksiDetail(AuthContext $auth, int $id): array
    {
        $svc = $this->cutiService();

        return $svc->itemDetail($auth, (array) $svc->ambil($id))['aksi'];
    }

    /**
     * @return array<string, mixed>
     */
    private function aksiDaftar(AuthContext $auth, int $id, string $mode): array
    {
        return $this->itemDari($this->cutiService()->daftar($auth, ['mode' => $mode, 'per_page' => '100']), $id)['aksi'];
    }

    /**
     * @param array<string, mixed> $hasil
     *
     * @return array<string, mixed>
     */
    private function itemDari(array $hasil, int $id): array
    {
        foreach ($hasil['items'] as $item) {
            if ((int) $item['id_riwayat_cuti'] === $id) {
                return $item;
            }
        }

        $this->fail("Cuti {$id} tidak ada di daftar");
    }

    /**
     * Cuti dua langkah dengan AL & PyB role 4 (tak terjangkau), disetujui atas nama sampai final (pola EsignAtasNamaTest).
     *
     * @return array{id: int, admin: array<string, mixed>}
     */
    private function finalAtasNama(): array
    {
        $al    = $this->buatPejabat(4);
        $pyb   = $this->buatPejabat(4);
        $p     = $this->buatPemohon(2, [], [], ['id_atasan_es_4' => $al['id_jabatan']]);
        $admin = $this->buatPemohon(1);
        $this->aturanPyb($p['jabatan'], $pyb['id_jabatan'], 'III/a');
        $this->buatSaldo($p['nip'], 2026);
        $this->db->table('pegawai')->where('nip', $pyb['nip'])->update(['nik' => self::NIK_PYB]);

        $id  = (int) $this->cutiService()->tambah($p['auth'], $this->isianCuti($al['nip'], $pyb['nip']), null)['id_riwayat_cuti'];
        $svc = $this->approvalService();
        $svc->process($admin['auth'], $id, ['aksi' => 'setujui', 'atas_nama' => true, 'reason_note' => 'AL tak terjangkau']);
        $svc->process($admin['auth'], $id, ['aksi' => 'setujui', 'atas_nama' => true, 'reason_note' => 'PyB tak terjangkau']);

        return ['id' => $id, 'admin' => $admin];
    }

    /**
     * @param list<string> $sql
     */
    private function jumlahAuditLogs(array $sql): int
    {
        return count(array_filter($sql, static fn (string $q): bool => str_contains($q, 'audit_logs')));
    }

    /**
     * @return array{0: mixed, 1: list<string>} [hasil, SQL yang dijalankan]
     */
    private function rekam(callable $f): array
    {
        $jumlah = [];
        $hitung = static function (Query $q) use (&$jumlah): void {
            $jumlah[] = $q->getQuery();
        };

        Events::on('DBQuery', $hitung);

        try {
            $hasil = $f();
        } finally {
            Events::removeListener('DBQuery', $hitung);
        }

        return [$hasil, $jumlah];
    }
}
