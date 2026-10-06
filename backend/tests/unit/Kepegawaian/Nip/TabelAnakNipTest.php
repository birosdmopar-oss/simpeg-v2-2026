<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Nip;

use App\Libraries\Kepegawaian\Nip\JenisRujukanNip;
use App\Libraries\Kepegawaian\Nip\KeberadaanV2;
use App\Libraries\Kepegawaian\Nip\RujukanNip;
use App\Libraries\Kepegawaian\Nip\TabelAnakNip;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * CR-036 — kelengkapan data registry kolom NIP untuk B-06, tanpa DB.
 *
 * Belum diuji di sini: kecocokan registry dengan skema nyata. Test ber-DB B-21 di `tests/Kepegawaian/` akan
 * mencocokkan `TabelAnakNip::sampaiFase(n)` dengan `information_schema.KEY_COLUMN_USAGE` (FK ke `pegawai.nip`) dan
 * kolom NIP tanpa FK, setelah tabel B-01/B-02 ada. Draf DBV-012 sudah punya `NipReferenceRegistryTest` untuk tabel
 * B-01/B-02; penyatuannya diputuskan saat draf itu masuk.
 *
 * @internal
 */
final class TabelAnakNipTest extends CIUnitTestCase
{
    /**
     * Tabel B-01 (draf DBV-012 revisi D1, Bagian 4.3: 16 FK, termasuk `pegawai_ak_siasn` dari keputusan 8 #6).
     */
    private const FASE_3_B01 = [
        'pegawai_hist.nip', 'pegawai_foto.nip', 'pegawai_kp.nip', 'pegawai_cpns.nip', 'pegawai_pns.nip', 'pegawai_kgb.nip',
        'pegawai_pendidikan.nip', 'pegawai_diklat.nip', 'pegawai_hukdis.nip', 'pegawai_ak.nip', 'pegawai_ak_siasn.nip', 'pegawai_keluarga.nip',
        'pegawai_alamat.nip', 'pegawai_alamat_kantor.nip', 'pegawai_tanda_jasa.nip', 'pegawai_mutasi_jabatan.nip',
    ];

    /**
     * Tabel B-02 (draf DBV-013, Bagian 4.3: 24 FK).
     */
    private const FASE_3_B02 = [
        'riwayat_mutasi_jabatan.nip', 'pegawai_plt.nip_plt', 'pegawai_plh.nip_plh', 'riwayat_kp.nip', 'riwayat_kgb.nip',
        'riwayat_pendidikan.nip', 'riwayat_diklat.nip', 'riwayat_seminar.nip', 'riwayat_skp.nip', 'riwayat_lckh.nip',
        'riwayat_lckh.nip_atasan', 'absen_ijin.nip', 'riwayat_hukdis.nip', 'riwayat_ak.nip', 'riwayat_ak_siasn.nip',
        'konv_ak.nip', 'ignore_konv_ak.nip', 'riwayat_keluarga.nip', 'riwayat_alamat.nip', 'riwayat_karpeg.nip',
        'riwayat_kariskarsu.nip', 'riwayat_tanda_jasa.nip', 'riwayat_organisasi.nip', 'document_attachment.nip',
    ];

    public function testJumlahPerJenis(): void
    {
        $this->assertCount(79, TabelAnakNip::semua());
        $this->assertCount(75, TabelAnakNip::perJenis(JenisRujukanNip::FkLegacy));
        $this->assertCount(1, TabelAnakNip::perJenis(JenisRujukanNip::NonFkUbahNipLegacy));
        $this->assertCount(1, TabelAnakNip::perJenis(JenisRujukanNip::NonFkSlipGaji));
        $this->assertCount(2, TabelAnakNip::perJenis(JenisRujukanNip::NonFkFkV2));

        $tabelFk = array_unique(array_map(
            static fn (RujukanNip $r): string => $r->tabel,
            TabelAnakNip::perJenis(JenisRujukanNip::FkLegacy),
        ));
        $this->assertCount(70, $tabelFk, 'FK legacy ke pegawai(nip) tersebar di 70 tabel dump D1');
    }

    public function testTidakAdaDuplikat(): void
    {
        $kunci = array_map(static fn (RujukanNip $r): string => $r->kunci(), TabelAnakNip::semua());
        $this->assertSame(array_values(array_unique($kunci)), $kunci, 'tabel.kolom (tanpa peka huruf) harus unik');

        $namaFk = array_map(
            static fn (RujukanNip $r): string => strtolower((string) $r->namaFkLegacy),
            TabelAnakNip::perJenis(JenisRujukanNip::FkLegacy),
        );
        $this->assertSame(array_values(array_unique($namaFk)), $namaFk, 'nama FK legacy harus unik');
    }

    public function testSetiapEntriLengkap(): void
    {
        $pelanggaran = [];

        foreach (TabelAnakNip::semua() as $r) {
            $id = $r->tabel . '.' . $r->kolom;

            if (preg_match('/^[a-z][a-z0-9_]*\z/', $r->tabel) !== 1 || preg_match('/^[A-Za-z][A-Za-z0-9_]*\z/', $r->kolom) !== 1) {
                $pelanggaran[] = "{$id}: nama tabel/kolom tidak valid";
            }

            if (preg_match('/^\[K(-kode)?\] \S/', $r->sumber) !== 1) {
                $pelanggaran[] = "{$id}: sumber harus berlabel [K] atau [K-kode]";
            }

            if (trim($r->catatan) === '') {
                $pelanggaran[] = "{$id}: catatan kosong";
            }

            if ($r->jenis === JenisRujukanNip::FkLegacy) {
                if ($r->namaFkLegacy === null || ! str_starts_with($r->sumber, '[K] FK ' . $r->namaFkLegacy)) {
                    $pelanggaran[] = "{$id}: FK legacy tanpa nama FK di sumber";
                }
            } elseif ($r->namaFkLegacy !== null) {
                $pelanggaran[] = "{$id}: kolom tanpa FK tidak boleh punya nama FK";
            }

            if ($r->keberadaan === KeberadaanV2::Ada) {
                if ($r->fase === null || $r->fase < TabelAnakNip::FASE_MIN || $r->fase > TabelAnakNip::FASE_MAX
                    || preg_match('/^(F0|[A-H])-\d{2}\z/', (string) $r->pemilik) !== 1) {
                    $pelanggaran[] = "{$id}: tabel v2 wajib punya fase 0-8 dan task pemilik";
                }
            } elseif ($r->fase !== null || $r->pemilik !== null) {
                $pelanggaran[] = "{$id}: tabel yang tidak diimpor/belum diputuskan tidak boleh punya fase/pemilik";
            }

            if ($r->kolomV2 !== null && $r->keberadaan !== KeberadaanV2::Ada) {
                $pelanggaran[] = "{$id}: kolomV2 hanya untuk tabel yang ada di v2";
            }
        }

        $this->assertSame([], $pelanggaran);
    }

    public function testKolomDiV2(): void
    {
        $berbeda = array_values(array_filter(TabelAnakNip::semua(), static fn (RujukanNip $r): bool => $r->kolomV2 !== null));

        $this->assertCount(1, $berbeda);
        $this->assertSame('pengguna.id_pegawai', $berbeda[0]->tabel . '.' . $berbeda[0]->kolom);
        $this->assertSame('nip', $berbeda[0]->kolomDiV2());
        $this->assertSame('NIP', $this->cari('document_attachment', 'NIP')->kolomDiV2());
    }

    public function testDiubahLegacy(): void
    {
        $this->assertTrue(JenisRujukanNip::FkLegacy->diubahLegacy());
        $this->assertTrue(JenisRujukanNip::NonFkUbahNipLegacy->diubahLegacy());
        $this->assertFalse(JenisRujukanNip::NonFkSlipGaji->diubahLegacy());
        $this->assertFalse(JenisRujukanNip::NonFkFkV2->diubahLegacy());

        $this->assertSame(JenisRujukanNip::NonFkUbahNipLegacy, $this->cari('pengguna', 'username')->jenis);
        $this->assertSame(JenisRujukanNip::NonFkSlipGaji, $this->cari('gaji_pegawai', 'nip')->jenis);
        $this->assertSame(JenisRujukanNip::NonFkFkV2, $this->cari('riwayat_cuti', 'nip_atasan_langsung')->jenis);
        $this->assertSame(JenisRujukanNip::NonFkFkV2, $this->cari('riwayat_cuti', 'nip_yang_menyetujui')->jenis);
    }

    public function testJumlahPerFaseDanKeberadaan(): void
    {
        $harapan = [0 => 1, 1 => 2, 2 => 2, 3 => 40, 4 => 8, 5 => 2, 6 => 1, 7 => 5, 8 => 0];
        $aktual  = [];

        for ($fase = TabelAnakNip::FASE_MIN; $fase <= TabelAnakNip::FASE_MAX; $fase++) {
            $aktual[$fase] = count(TabelAnakNip::perFase($fase));
        }

        $this->assertSame($harapan, $aktual);
        $this->assertCount(61, TabelAnakNip::perKeberadaan(KeberadaanV2::Ada));
        $this->assertCount(11, TabelAnakNip::perKeberadaan(KeberadaanV2::TidakDiimpor));
        $this->assertCount(7, TabelAnakNip::perKeberadaan(KeberadaanV2::BelumDiputuskan));
    }

    public function testFase3SamaDenganDrafB01B02(): void
    {
        $fase3 = array_map(static fn (RujukanNip $r): string => $r->kunci(), TabelAnakNip::perFase(3));
        $b01   = array_map(static fn (RujukanNip $r): string => $r->kunci(), $this->perPemilik(3, 'B-01'));
        $b02   = array_map(static fn (RujukanNip $r): string => $r->kunci(), $this->perPemilik(3, 'B-02'));

        sort($fase3);
        sort($b01);
        sort($b02);
        $harapanB01 = self::FASE_3_B01;
        $harapanB02 = self::FASE_3_B02;
        sort($harapanB01);
        sort($harapanB02);

        $this->assertSame($harapanB01, $b01);
        $this->assertSame($harapanB02, $b02);

        $semua = array_merge($harapanB01, $harapanB02);
        sort($semua);
        $this->assertSame($semua, $fase3);
    }

    public function testSampaiFase(): void
    {
        $kunci = static fn (array $daftar): array => array_map(static fn (RujukanNip $r): string => $r->kunci(), $daftar);

        $this->assertSame(['token.nip'], $kunci(TabelAnakNip::sampaiFase(0)));

        $fase3 = $kunci(TabelAnakNip::sampaiFase(3));
        $this->assertCount(45, $fase3);

        foreach (['token.nip', 'pengguna.id_pegawai', 'pengguna.username', 'faq_rate.nip', 'user_lokasi_presensi.nip', 'riwayat_kp.nip'] as $harus) {
            $this->assertContains($harus, $fase3);
        }

        foreach (['riwayat_cuti.nip', 'gaji_pegawai.nip', 'riwayat_lckh_2019.nip', 'email_pool.nip'] as $belum) {
            $this->assertNotContains($belum, $fase3);
        }

        $this->assertSame(
            $kunci(TabelAnakNip::perKeberadaan(KeberadaanV2::Ada)),
            $kunci(TabelAnakNip::sampaiFase(TabelAnakNip::FASE_MAX)),
        );
    }

    public function testFaseDiLuarRentangDitolak(): void
    {
        foreach ([static fn () => TabelAnakNip::perFase(-1), static fn () => TabelAnakNip::perFase(9), static fn () => TabelAnakNip::sampaiFase(9)] as $panggil) {
            try {
                $panggil();
                $this->fail('InvalidArgumentException tidak dilempar');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Fase harus 0-8', $e->getMessage());
            }
        }
    }

    private function cari(string $tabel, string $kolom): RujukanNip
    {
        foreach (TabelAnakNip::semua() as $r) {
            if ($r->tabel === $tabel && $r->kolom === $kolom) {
                return $r;
            }
        }

        $this->fail("{$tabel}.{$kolom} tidak ada di registry");
    }

    /**
     * @return list<RujukanNip>
     */
    private function perPemilik(int $fase, string $pemilik): array
    {
        return array_values(array_filter(TabelAnakNip::perFase($fase), static fn (RujukanNip $r): bool => $r->pemilik === $pemilik));
    }
}
