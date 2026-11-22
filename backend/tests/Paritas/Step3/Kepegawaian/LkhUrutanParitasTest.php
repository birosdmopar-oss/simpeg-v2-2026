<?php

declare(strict_types=1);

namespace Tests\Paritas\Step3\Kepegawaian;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lkh\AtasanLangsung;
use App\Libraries\Kepegawaian\Lkh\LkhService;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use CodeIgniter\I18n\Time;
use Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * CR-164 pagar paritas step 3 (no. 4) — test karakterisasi urutan LKH: mengunci perilaku SEKARANG, bukan memperbaiki.
 *
 * - `LkhService::daftar()` ORDER BY: Revisi (3) lalu Menunggu (0) dengan tanggal naik, lalu Disetujui (1) tanggal turun;
 *   Ditolak (2) dan Dihapus (10) tidak tampil.
 * - `LkhService::verifikasi()` ORDER BY `tgl_laporan` ASC lalu `id_riwayat_lckh` ASC; bawaan hanya status Menunggu.
 *
 * Baris disisipkan langsung ke `riwayat_lckh` (data sintetis) agar status/tanggal campuran benar-benar menguji urutan.
 *
 * @internal
 */
final class LkhUrutanParitasTest extends DatabaseTestCase
{
    use RiwayatUjiTrait;

    private string $nip;
    private string $nipAtasan;
    private AuthContext $pegawai;
    private AuthContext $atasan;
    private int $idSatker;

    protected function setUp(): void
    {
        parent::setUp();

        Time::setTestNow('2026-10-13 10:00:00', 'Asia/Jakarta');
        Services::injectMock('pegawaiScope', new PegawaiScope());

        $this->idSatker                   = $this->buatSatker();
        [$this->nipAtasan, $this->atasan] = $this->aktor(Role::PEGAWAI, [], $this->idSatker);
        [$this->nip, $this->pegawai]      = $this->aktor(Role::PTT, [], $this->idSatker);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        Services::reset(true);

        parent::tearDown();
    }

    public function testDaftarUrutRevisiMenungguNaikLaluDisetujuiTurun(): void
    {
        // Disisipkan sengaja acak (id tidak searah tanggal).
        $this->lkh($this->nip, '2026-10-05', LkhService::DISETUJUI);
        $this->lkh($this->nip, '2026-10-09', LkhService::MENUNGGU);
        $this->lkh($this->nip, '2026-10-02', LkhService::DISETUJUI);
        $this->lkh($this->nip, '2026-10-08', LkhService::REVISI);
        $this->lkh($this->nip, '2026-10-01', LkhService::MENUNGGU);
        $this->lkh($this->nip, '2026-10-07', LkhService::DISETUJUI);
        $this->lkh($this->nip, '2026-10-03', LkhService::REVISI);
        $this->lkh($this->nip, '2026-10-06', LkhService::DITOLAK);
        $this->lkh($this->nip, '2026-10-04', LkhService::DIHAPUS);

        $hasil = $this->svc()->daftar($this->pegawai);

        $this->assertSame([
            ['2026-10-03', 3], ['2026-10-08', 3],
            ['2026-10-01', 0], ['2026-10-09', 0],
            ['2026-10-07', 1], ['2026-10-05', 1], ['2026-10-02', 1],
        ], $this->tglStatus($hasil['items']), 'urutan daftar LKH (LkhService::daftar ORDER BY CASE)');
        $this->assertSame(7, $hasil['meta']['total'], 'Ditolak dan Dihapus tidak dihitung');
    }

    public function testDaftarUrutanBerlakuLintasHalaman(): void
    {
        $this->lkh($this->nip, '2026-10-05', LkhService::DISETUJUI);
        $this->lkh($this->nip, '2026-10-09', LkhService::MENUNGGU);
        $this->lkh($this->nip, '2026-10-08', LkhService::REVISI);
        $this->lkh($this->nip, '2026-10-07', LkhService::DISETUJUI);

        $h1 = $this->svc()->daftar($this->pegawai, ['page' => 1, 'per_page' => 2]);
        $h2 = $this->svc()->daftar($this->pegawai, ['page' => 2, 'per_page' => 2]);

        $this->assertSame([['2026-10-08', 3], ['2026-10-09', 0]], $this->tglStatus($h1['items']));
        $this->assertSame([['2026-10-07', 1], ['2026-10-05', 1]], $this->tglStatus($h2['items']));
        $this->assertSame(['page' => 2, 'per_page' => 2, 'total' => 4], $h2['meta']);
    }

    public function testVerifikasiUrutTanggalNaikLaluIdNaik(): void
    {
        $b1 = $this->buatPegawaiDiSatker($this->idSatker, ['nama' => 'Bawahan Satu']);
        $b2 = $this->buatPegawaiDiSatker($this->idSatker, ['nama' => 'Bawahan Dua']);

        $a = $this->lkh($b2, '2026-10-09', LkhService::MENUNGGU, $this->nipAtasan);
        $b = $this->lkh($b1, '2026-10-02', LkhService::MENUNGGU, $this->nipAtasan);
        $c = $this->lkh($b1, '2026-10-09', LkhService::MENUNGGU, $this->nipAtasan); // tanggal sama dengan $a, id lebih besar
        $d = $this->lkh($b2, '2026-10-05', LkhService::DISETUJUI, $this->nipAtasan);
        $e = $this->lkh($b2, '2026-10-01', LkhService::DITOLAK, $this->nipAtasan);
        $f = $this->lkh($b1, '2026-10-03', LkhService::DIHAPUS, $this->nipAtasan);
        $this->lkh($b1, '2026-10-04', LkhService::MENUNGGU, $this->nip); // atasan lain → tidak tampil

        $bawaan = $this->svc()->verifikasi($this->atasan);
        $this->assertSame([$b, $a, $c], array_column($bawaan['items'], 'id'), 'bawaan status Menunggu, urut tgl ASC lalu id ASC');
        $this->assertSame(3, $bawaan['meta']['total']);

        // Status di luar 0..3 → tanpa saringan status (kecuali Dihapus).
        $semua = $this->svc()->verifikasi($this->atasan, ['status' => 'semua']);
        $this->assertSame([$e, $b, $d, $a, $c], array_column($semua['items'], 'id'));
        $this->assertNotContains($f, array_column($semua['items'], 'id'));

        $this->assertSame([$d], array_column($this->svc()->verifikasi($this->atasan, ['status' => '1'])['items'], 'id'));
    }

    public function testVerifikasiStatusKosongBerartiSemua(): void
    {
        $b1 = $this->buatPegawaiDiSatker($this->idSatker);
        $x  = $this->lkh($b1, '2026-10-06', LkhService::DISETUJUI, $this->nipAtasan);
        $y  = $this->lkh($b1, '2026-10-02', LkhService::MENUNGGU, $this->nipAtasan);

        // perilaku sekarang (dikunci CR-164): status '' (string kosong) tidak masuk 0..3 → semua status tampil,
        // sedangkan status tidak dikirim → bawaan Menunggu (LkhService.php:119-123).
        $this->assertSame([$y, $x], array_column($this->svc()->verifikasi($this->atasan, ['status' => ''])['items'], 'id'));
        $this->assertSame([$y], array_column($this->svc()->verifikasi($this->atasan)['items'], 'id'));
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array{0: string, 1: int}>
     */
    private function tglStatus(array $items): array
    {
        return array_map(static fn (array $r): array => [(string) $r['tgl_laporan'], (int) $r['status']], $items);
    }

    private function lkh(string $nip, string $tgl, int $status, ?string $nipAtasan = null): int
    {
        $this->db->table('riwayat_lckh')->insert([
            'nip'         => $nip,
            'tgl_laporan' => $tgl,
            'nama'        => 'Pegawai Uji',
            'nip_atasan'  => $nipAtasan ?? $this->nipAtasan,
            'nama_atasan' => 'Atasan Uji',
            'kegiatan'    => '["Kegiatan"]',
            'output'      => '["Output"]',
            'status'      => $status,
        ]);

        return (int) $this->db->insertID();
    }

    private function svc(): LkhService
    {
        return new LkhService(null, null, new AtasanLangsung($this->db));
    }
}
