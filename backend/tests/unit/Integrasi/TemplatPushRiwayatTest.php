<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi;

use App\Libraries\Push\TemplatPushRiwayat as T;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MAKE-066 (H-04, F6-13; AC-04.4) — templat & penerima push web riwayat per jenis ikut `fcmQueue_rwy`/`fcmQueue_layanan`
 * legacy [K `FH:588-915`]: judul per tipe, pratinjau per jenis, keterangan tolak hanya pada kondisi legacy, penerima per
 * tipe/jenis, jenis tanpa push legacy dilewati.
 *
 * @internal
 */
final class TemplatPushRiwayatTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function pratinjauPerJenis(): iterable
    {
        yield 'alamat' => ['alamat', ['jenis_alamat' => 2, 'alamat' => 'Jl. Uji 1'], 'Alamat Domisili - Jl. Uji 1'];
        yield 'diklat' => ['diklat', ['jenis_diklat' => 3, 'deskripsi' => 'Diklat JF'], 'Fungsional - Diklat JF'];
        yield 'jabatan' => ['jabatan', ['jabatan' => 'Analis', 'satker' => 'Biro Uji'], 'Analis - Biro Uji'];
        yield 'kgb' => ['kgb', ['gaji_pokok' => '3500000.00', 'mker_gol_th' => 8], 'Rp. 3.500.000 - Masa Kerja: 8 Tahun'];
        yield 'kp' => ['kp', ['jenis_kp' => 'Reguler', 'gol_ruang' => 'III/c'], 'Reguler - III/c'];
        yield 'organisasi' => ['organisasi', ['nama_organisasi' => 'Korpri'], 'Korpri'];
        yield 'pendidikan' => ['pendidikan', ['jenjang_pendidikan_singkat' => 'S2', 'institusi_pendidikan' => 'Univ Uji'], 'S2 - Univ Uji'];
        yield 'keluarga' => ['keluarga', ['nama_pasangan' => 'Pasangan Uji', 'jumlah_anak' => 2], 'Pasangan Uji - 2 Anak'];
        yield 'seminar' => ['seminar', ['jenis_seminar' => 2, 'nama_seminar' => 'Kursus Uji'], 'Kursus - Kursus Uji'];
        yield 'tanda jasa' => ['tanda-jasa', ['id_tanda_jasa' => 44, 'tanda_jasa' => 'X', 'tanda_jasa_lain' => 'Penghargaan Lain', 'tgl_sertifikat' => '2026-08-17'], 'Penghargaan Lain - 17 Agustus 2026'];
        yield 'skp' => ['skp', ['tahun' => 2025, 'nilai_prestasi_kerja' => '88.50', 'kategori_nilai_prestasi' => 'Baik'], '2025 - 88,5 [Baik]'];
        yield 'karpeg' => ['karpeg', ['created_at' => '2026-11-03 02:00:00'], 'Permohonan tanggal 3 November 2026'];
        // created_at UTC 2026-01-08 17:30 = 9 Januari 2026 00:30 WIB (review MAKE-066 P6).
        yield 'kariskarsu' => ['kariskarsu', ['jenis_permohonan' => 'Baru', 'created_at' => '2026-01-08 17:30:00'], '(Baru) 9 Januari 2026'];
        yield 'lkh' => ['lkh', ['tgl_laporan' => '2026-10-12'], 'LKH 12 Oktober 2026'];
        yield 'konket' => ['konket', ['kategori' => 8, 'jenis_dinas' => 1, 'date_start' => '2026-10-01', 'date_end' => '2026-10-03'], '(Dinas Luar GABUNGAN) 1 Oktober 2026 s/d 3 Oktober 2026'];
    }

    /**
     * @param array<string, mixed> $baris
     */
    #[DataProvider('pratinjauPerJenis')]
    public function testPratinjauDanJudulPerJenis(string $jenis, array $baris, string $pratinjau): void
    {
        $nama = T::JENIS[$jenis][0];
        $p    = T::susun(1, $jenis, ['nip' => '1990'] + $baris, ['glr_awal' => '', 'nama' => 'Budi', 'glr_akhir' => 'S.E.'], null, 'Dinas Luar');

        $this->assertSame("Permohonan {$nama} Baru", $p['judul'] ?? null);
        $this->assertSame("Data: {$pratinjau} oleh Budi, S.E. / 1990", $p['isi']);
        $this->assertSame("Perubahan {$nama}", T::susun(3, $jenis, $baris, null, null, 'Dinas Luar')['judul'] ?? null);
        $this->assertSame("{$nama} Disetujui", T::susun(2, $jenis, $baris, null, 'Disetujui', 'Dinas Luar')['judul'] ?? null);
    }

    public function testKeteranganTolakHanyaKondisiLegacy(): void
    {
        $this->assertSame('Data: Korpri. Ket: Buram', T::susun(2, 'organisasi', ['nama_organisasi' => 'Korpri', 'status' => 2, 'reason_note' => 'Buram'], null, 'Ditolak')['isi'] ?? null);
        $this->assertSame('Data: Korpri', T::susun(2, 'organisasi', ['nama_organisasi' => 'Korpri', 'status' => 1, 'reason_note' => 'Buram'], null, 'Disetujui')['isi'] ?? null);
        $this->assertSame('Data: 2025 - 0 []', T::susun(2, 'skp', ['tahun' => 2025, 'status' => 2, 'reason_note' => 'Buram'], null, 'Ditolak')['isi'] ?? null, 'SKP: legacy cek status X → tidak pernah tampil');
        $this->assertSame('Data: LKH 12 Oktober 2026. Ket: Rinci', T::susun(2, 'lkh', ['tgl_laporan' => '2026-10-12', 'status' => 3, 'catatan' => 'Rinci'], null, 'Butuh Revisi')['isi'] ?? null);
        $this->assertSame('Data: (Izin) 1 Oktober 2026 s/d 1 Oktober 2026. Ket: Tidak sesuai', T::susun(2, 'konket', ['date_start' => '2026-10-01', 'date_end' => '2026-10-01', 'status' => 'X', 'reason_note' => 'Tidak sesuai'], null, 'Ditolak', 'Izin')['isi'] ?? null);
    }

    public function testTanggalKarpegDariCreatedAtUtcKeTanggalWib(): void
    {
        // review MAKE-066 P6 (BP-10): 00:00–06:59 WIB = hari sebelumnya di UTC.
        foreach (['2026-11-02 17:00:00' => '3 November 2026', '2026-11-02 23:59:59' => '3 November 2026', '2026-11-03 16:59:59' => '3 November 2026', '2026-11-03 17:00:00' => '4 November 2026'] as $utc => $wib) {
            $this->assertSame("Data: Permohonan tanggal {$wib}", T::susun(2, 'karpeg', ['created_at' => $utc], null, 'Disetujui')['isi'] ?? null, $utc);
        }
    }

    public function testPenerimaDanTautan(): void
    {
        $this->assertSame([T::PENERIMA_ADMIN, T::PENERIMA_ADMIN, T::PENERIMA_PEGAWAI], [T::penerima(1, 'kp'), T::penerima(3, 'kp'), T::penerima(2, 'kp')]);
        $this->assertSame([T::PENERIMA_ATASAN, T::PENERIMA_PEGAWAI], [T::penerima(1, 'lkh'), T::penerima(2, 'lkh')]);
        $this->assertSame([T::PENERIMA_PETUGAS, T::PENERIMA_PETUGAS], [T::penerima(1, 'karpeg'), T::penerima(3, 'kariskarsu')]);
        $this->assertNull(T::penerima(4, 'kp'));

        foreach (['ak', 'ak-siasn', 'hukdis', 'skp-periodik'] as $tanpaPush) {
            $this->assertNull(T::susun(1, $tanpaPush, [], null, null), $tanpaPush);
        }

        $this->assertSame('/pegawai/1990/riwayat?tab=kgb', T::susun(2, 'kgb', ['nip' => '1990'], null, 'Disetujui')['link'] ?? null);
        $this->assertSame(['/lkh/verifikasi?tab=verifikasi', '/lkh/verifikasi', '/konket', '/karpeg-karis?jenis=kariskarsu'], [
            T::susun(1, 'lkh', [], null, null)['link'] ?? null,
            T::susun(2, 'lkh', [], null, 'Disetujui')['link'] ?? null,
            T::susun(1, 'konket', [], null, null)['link'] ?? null,
            T::susun(2, 'kariskarsu', [], null, 'Disetujui')['link'] ?? null,
        ]);
        $this->assertSame(['', ''], [T::tanggal('0000-00-00'), T::tanggal('bukan tanggal')]);
    }
}
