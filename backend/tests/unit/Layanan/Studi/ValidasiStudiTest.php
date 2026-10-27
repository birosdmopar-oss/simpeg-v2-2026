<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\Studi;

use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\Studi\StatusStudi;
use App\Libraries\Layanan\Studi\ValidasiStudi;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * MAKE-024 — validasi isian TB/IB (PRD Fase 4 v1.1 §6.7.2, §6.8.1): semua galat dikumpulkan (TB-03), jenjang > 3, status
 * awal TB 0|1 (+ data SK bila 1), IB selalu 0, `lama_tahun` dihitung server, tumpang tindih status {0,1,3,4} inklusif.
 *
 * @internal
 */
final class ValidasiStudiTest extends CIUnitTestCase
{
    /**
     * @return array<string, string>
     */
    private static function sah(array $timpa = []): array
    {
        return $timpa + [
            'tgl_mulai'             => '2025-09-01',
            'tgl_akhir'             => '2029-08-31',
            'id_jenjang_pendidikan' => '4',
            'institusi_pendidikan'  => 'Universitas Uji',
            'jurusan_pendidikan'    => 'Ilmu Komputer',
            'lokasi_pendidikan'     => 'Jakarta',
            'lembaga_yang_mendanai' => 'LPDP',
            'up'                    => AturanStudi::UP_BAWAAN,
        ];
    }

    public function testIsianSahMenghasilkanLamaTahunServer(): void
    {
        $hasil = ValidasiStudi::periksa(AturanStudi::JENIS_TB, self::sah(['lama_tahun' => '9.9']), null, AturanStudi::bawaan());

        $this->assertSame([], $hasil['errors']);
        $this->assertSame('3.90', $hasil['lama_tahun'], 'nilai klien diabaikan (Q-03)');
    }

    public function testSemuaGalatDikumpulkanDenganTeksPerJenis(): void
    {
        $tb = ValidasiStudi::periksa(AturanStudi::JENIS_TB, [], null, AturanStudi::bawaan())['errors'];

        $this->assertSame([
            'tgl_mulai'             => 'Tanggal mulai tugas belajar wajib diisi.',
            'tgl_akhir'             => 'Tanggal akhir tugas belajar wajib diisi.',
            'id_jenjang_pendidikan' => 'Silahkan pilih jenjang pendidikan tugas belajar.',
            'institusi_pendidikan'  => 'Institusi pendidikan wajib diisi.',
            'jurusan_pendidikan'    => 'Jurusan pendidikan wajib diisi.',
            'lokasi_pendidikan'     => 'Lokasi pendidikan tugas belajar wajib diisi.',
            'lembaga_yang_mendanai' => 'Lembaga yang mendanai tugas belajar wajib diisi.',
            'up'                    => 'U.P laporan tugas belajar wajib diisi.',
        ], $tb);

        $ib = ValidasiStudi::periksa(AturanStudi::JENIS_IB, ['tgl_mulai' => ' ', 'up' => ['x']], null, AturanStudi::bawaan())['errors'];
        $this->assertSame('Tanggal mulai izin belajar wajib diisi.', $ib['tgl_mulai'], 'spasi = kosong');
        $this->assertSame('U.P laporan izin belajar wajib diisi.', $ib['up'], 'array = kosong');
        $this->assertCount(8, $ib);
    }

    public function testTanggalDanJenjang(): void
    {
        $a = AturanStudi::bawaan();

        $terbalik = ValidasiStudi::periksa(AturanStudi::JENIS_TB, self::sah(['tgl_akhir' => '2025-08-31']), null, $a);
        $this->assertSame(['tgl_akhir' => ValidasiStudi::PESAN_AKHIR_SEBELUM_MULAI], $terbalik['errors']);
        $this->assertNull($terbalik['lama_tahun']);

        $sama = ValidasiStudi::periksa(AturanStudi::JENIS_TB, self::sah(['tgl_akhir' => '2025-09-01']), null, $a);
        $this->assertSame([], $sama['errors'], 'tanggal sama boleh [K]');
        $this->assertSame('0.00', $sama['lama_tahun']);

        $tidakValid = ValidasiStudi::periksa(AturanStudi::JENIS_TB, self::sah(['tgl_mulai' => '2025-02-29']), null, $a);
        $this->assertSame('Tanggal mulai tugas belajar tidak valid.', $tidakValid['errors']['tgl_mulai']);

        foreach (['3', '0', 'S1', '4.5', '-4'] as $jenjang) {
            $hasil = ValidasiStudi::periksa(AturanStudi::JENIS_IB, self::sah(['id_jenjang_pendidikan' => $jenjang]), null, $a);
            $this->assertSame('Jenjang pendidikan izin belajar tidak valid.', $hasil['errors']['id_jenjang_pendidikan'] ?? null, $jenjang);
        }
    }

    public function testStatusAwalTbDanIb(): void
    {
        $a = AturanStudi::bawaan();

        $this->assertSame([], ValidasiStudi::periksa(AturanStudi::JENIS_TB, self::sah(), 0, $a)['errors']);
        $this->assertSame(['status' => ValidasiStudi::PESAN_STATUS_TB], ValidasiStudi::periksa(AturanStudi::JENIS_TB, self::sah(), 2, $a)['errors']);

        $tanpaSk = ValidasiStudi::periksa(AturanStudi::JENIS_TB, self::sah(), StatusStudi::DISETUJUI, $a)['errors'];
        $this->assertSame(['no_sk', 'tgl_sk', 'ds_tb', 'no_ds_tb'], array_keys($tanpaSk));
        $this->assertSame('No. SK Tugas Belajar wajib diisi.', $tanpaSk['no_sk']);

        $sk = ['no_sk' => '1/SK', 'tgl_sk' => '2025-08-01', 'ds_tb' => 'Surat X', 'no_ds_tb' => '2/DS'];
        $this->assertSame([], ValidasiStudi::periksa(AturanStudi::JENIS_TB, self::sah($sk), 1, $a)['errors']);
        $this->assertSame(['tgl_sk' => 'Tgl. SK Tugas Belajar tidak valid.'], ValidasiStudi::periksa(AturanStudi::JENIS_TB, self::sah(['tgl_sk' => '01-08-2025'] + $sk), 1, $a)['errors']);

        // IB: tidak ada input "langsung disetujui" (§6.8.2).
        $this->assertSame([], ValidasiStudi::periksa(AturanStudi::JENIS_IB, self::sah(), 0, $a)['errors']);
        $this->assertSame(['status' => ValidasiStudi::PESAN_STATUS_IB], ValidasiStudi::periksa(AturanStudi::JENIS_IB, self::sah($sk), 1, $a)['errors']);
    }

    public function testTumpangTindihStatusDanIrisanInklusif(): void
    {
        $lain = [
            ['id' => 7, 'tgl_mulai' => '2024-01-01', 'tgl_akhir' => '2025-09-01', 'status' => '2'],
            ['id' => 8, 'tgl_mulai' => '2029-08-31', 'tgl_akhir' => '2030-01-01', 'status' => '1'],
        ];

        // Ditolak (2) tidak dihitung; Disetujui (1) dihitung [V2 TB-04]; ujung bersentuhan = beririsan.
        $this->assertSame(8, ValidasiStudi::bentrok('2025-09-01', '2029-08-31', $lain)['id'] ?? null);
        $this->assertNull(ValidasiStudi::bentrok('2025-09-01', '2029-08-31', $lain, '8'), 'baris sendiri saat ubah dilewati');

        foreach ([0, 3, 4] as $status) {
            $baris = [['tgl_mulai' => '2025-01-01', 'tgl_akhir' => '2025-12-31', 'status' => $status]];
            $this->assertNotNull(ValidasiStudi::bentrok('2025-12-31', '2026-06-30', $baris), "status {$status} [K]");
        }

        $this->assertNull(ValidasiStudi::bentrok('2026-01-01', '2026-06-30', [['tgl_mulai' => '2025-01-01', 'tgl_akhir' => '2025-12-31', 'status' => 0]]));
        $this->assertSame('Tanggal tugas belajar sudah terdaftar didalam sistem, silahkan gunakan tanggal tugas belajar yang berbeda.', ValidasiStudi::pesanBentrok(AturanStudi::JENIS_TB));
        $this->assertSame('Tanggal izin belajar sudah terdaftar didalam sistem, silahkan gunakan tanggal izin belajar yang berbeda.', ValidasiStudi::pesanBentrok(AturanStudi::JENIS_IB));
    }

    public function testJenisTidakDikenalKesalahanPemanggil(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ValidasiStudi::periksa('pmk', self::sah(), null, AturanStudi::bawaan());
    }
}
