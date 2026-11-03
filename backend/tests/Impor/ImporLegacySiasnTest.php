<?php

declare(strict_types=1);

namespace Tests\Impor;

use App\Libraries\Impor\AturanSiasn;
use App\Libraries\Impor\ImporGagal;
use App\Libraries\Impor\Keputusan;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Impor\ImporLegacyTestCase;

/**
 * MAKE-056 B3 — tabel Fase 6 (DBV-027 §13.3): kunci ganda `riwayat_skp_periodik` (K-3/K-4), tier SIASN (K-6..K-9).
 * NIP dan id SIASN sintetis.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class ImporLegacySiasnTest extends ImporLegacyTestCase
{
    private const NIP_A = '000000000000000001';
    private const NIP_B = '000000000000000002';

    private const REFERENSI = [
        'JABATAN_FUNGSIONAL_ID'      => null,
        'JABATAN_FUNGSIONAL_UMUM_ID' => null,
        'JENIS_JABATAN_ID'           => 'id_group_jabatan',
        'LATIHAN_STRUKTURAL_ID'      => 'id_diklat',
        'TK_PENDIDIKAN'              => 'id_jenjang_pendidikan',
        'JENIS_KP_ID'                => 'id_jenis_kp',
        'GOLONGAN_ID'                => 'id_pangkat',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->isiMasterDasar();
        $this->src->table('jenis_pegawai')->insert(['id_jenis_pegawai' => 1, 'jenis_pegawai' => 'PNS', 'order' => 1, 'status' => 1]);
        $this->src->table('pegawai')->insertBatch([
            ['nip' => self::NIP_A, 'nama' => 'Pegawai Uji A', 'tgl_lahir' => '1990-01-01', 'id_jenis_pegawai' => 1, 'jenis_kelamin' => 1, 'status' => 1],
            ['nip' => self::NIP_B, 'nama' => 'Pegawai Uji B', 'tgl_lahir' => '1991-02-02', 'id_jenis_pegawai' => 1, 'jenis_kelamin' => 2, 'status' => 1],
        ]);
        $this->jalankanImpor([1, 2]);

        // Legacy tanpa kunci UNIQUE v2 (B-14, DBV-027 §3.3).
        $this->src->query('ALTER TABLE riwayat_skp_periodik DROP INDEX uq_rsp_pns_periode_skp');
        $this->src->query('ALTER TABLE siasn_upload_arsip DROP INDEX uq_sua_rw_da');
        $this->src->query('ALTER TABLE siasn_upload_arsip MODIFY id_da INT NULL');
        $this->lepasCheck('siasn_upload_arsip');
    }

    /**
     * @param array<string, mixed> $kolom
     */
    private function skp(int $id, string $nip, ?string $periode, string $skp, int $status, ?string $hasil, array $kolom = []): array
    {
        return $kolom + [
            'id_riwayat_skp_periodik' => $id, 'id_pns' => 'pns-' . substr($nip, -1), 'nip' => $nip, 'nama' => 'Uji',
            'periode_id'              => $periode, 'skp_id' => $skp, 'status' => $status, 'hasil_akhir' => $hasil, 'created_at' => '2026-05-01 08:00:00',
        ];
    }

    public function testSkpKunciGandaMempertahankanBarisYangDibacaTukin(): void
    {
        $this->src->table('riwayat_skp_periodik')->insertBatch([
            // Grup 1: baris Tukin = status 1 + hasil_akhir NOT NULL (string kosong terisi), id terkecil → 12.
            $this->skp(10, self::NIP_A, 'p-4', 's-1', 2, 'Baik'),
            $this->skp(11, self::NIP_A, 'p-4', 's-1', 1, null),
            $this->skp(12, self::NIP_A, 'p-4', 's-1', 1, ''),
            $this->skp(13, self::NIP_A, 'p-4', 's-1', 1, 'Baik'),
            // Grup 2: tanpa baris Tukin → id terkecil.
            $this->skp(20, self::NIP_B, 'p-5', 's-2', 2, 'Baik'),
            $this->skp(21, self::NIP_B, 'p-5', 's-2', 0, 'Baik'),
            // skp_id lain pada periode yang sama (SKP Plt/Plh) bukan kunci ganda.
            $this->skp(22, self::NIP_B, 'p-5', 's-3', 1, 'Baik'),
            // periode_id NULL tidak dibatasi UNIQUE.
            $this->skp(30, self::NIP_A, null, 's-9', 1, null),
            $this->skp(31, self::NIP_A, null, 's-9', 1, null),
        ]);

        $kering = $this->jalankanImpor([4], dryRun: true);
        $this->assertSame(['DBV-027 K-3 kunci ganda' => 4], $kering['riwayat_skp_periodik']['dibuang'], 'dry-run menghitung hal yang sama');
        $this->assertSame(0, $this->db->table('riwayat_skp_periodik')->countAllResults());

        $laporan = $this->jalankanImpor([4]);
        $this->assertSame(['DBV-027 K-3 kunci ganda' => 4], $laporan['riwayat_skp_periodik']['dibuang']);
        $this->assertSame(5, $laporan['riwayat_skp_periodik']['ditulis']);

        $id = array_map('intval', array_column($this->db->table('riwayat_skp_periodik')->select('id_riwayat_skp_periodik')->orderBy('id_riwayat_skp_periodik')->get()->getResultArray(), 'id_riwayat_skp_periodik'));
        $this->assertSame([12, 20, 22, 30, 31], $id);
    }

    public function testSkpGrupKunciDenganNipBerbedaGagal(): void
    {
        $this->src->table('riwayat_skp_periodik')->insertBatch([
            $this->skp(10, self::NIP_A, 'p-4', 's-1', 1, 'Baik'),
            $this->skp(11, self::NIP_B, 'p-4', 's-1', 1, 'Baik', ['id_pns' => 'pns-1']),
        ]);

        try {
            $this->jalankanImpor([4], dryRun: true);
            $this->fail('Grup kunci dengan NIP berbeda harus gagal (K-4).');
        } catch (ImporGagal $e) {
            $this->assertSame('riwayat_skp_periodik: 1 grup kunci (id_pns, periode_id, skp_id) berisi NIP berbeda; diputus DBV per kasus (DBV-027 K-4).', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $kolom
     */
    private function arsip(int $id, string $rw, int $idRw, ?int $idDa, int $status, array $kolom = []): array
    {
        return $kolom + [
            'id_siasn_upload_arsip' => $id, 'nip' => self::NIP_A, 'rw_name' => $rw, 'id_rw' => $idRw, 'id_da' => $idDa,
            'arsip_path'            => 'arsip/uji/berkas.pdf', 'status' => $status, 'created_at' => '2026-06-01 08:00:00',
        ];
    }

    public function testTanpaSumberSiasnReferensiDilewatiDanArsipDiimpor(): void
    {
        // Kunci (Rw Jabatan, 5, 50): terunggah lebih dulu walau id lebih kecil. Backslash: respons BKN & error_msg mentah,
        // arsip_path ikut stripslashes.
        $this->src->table('siasn_upload_arsip')->insert($this->arsip(1, 'Rw Jabatan', 5, 50, 1, ['resp_dok_uri' => 'peremajaan\dok\1.pdf', 'error_msg' => "Gagal \\'x\\'", 'arsip_path' => "arsip/uji/Bu\\'s.pdf"]));
        $this->src->table('siasn_upload_arsip')->insertBatch([
            $this->arsip(2, 'Rw Jabatan', 5, 50, 2),
            $this->arsip(3, 'Rw Jabatan', 5, 50, 0),
            // Kunci (Rw Sertifikasi, 6, 60): tanpa unggahan → id terbesar.
            $this->arsip(4, 'Rw Sertifikasi', 6, 60, 0),
            $this->arsip(5, 'Rw Sertifikasi', 6, 60, 2),
            // Sedang diproses saat cutover.
            $this->arsip(6, 'Rw Jabatan', 7, 70, 3),
        ]);
        $this->src->table('double_riwayat_kp')->insert(['nip' => self::NIP_B, 'created_at' => '2026-06-01 08:00:00']);
        $this->src->query('ALTER TABLE siasn_upload_arsip AUTO_INCREMENT = 500');

        $laporan = $this->jalankanImpor([7]);

        $this->assertStringContainsString('dilewati: tanpa --sumber-siasn', $laporan['siasn_ref_unor<REF_UNOR']['catatan']);
        $this->assertStringContainsString('dilewati: tanpa --sumber-siasn', $laporan['siasn_referensi<GOLONGAN_ID']['catatan']);
        $this->assertSame(0, $this->db->table('siasn_referensi')->countAllResults());

        $sua = $laporan['siasn_upload_arsip'];
        $this->assertSame(['DBV-027 K-6 kunci ganda' => 3], $sua['dibuang']);
        $this->assertSame(1, $sua['koreksi']['DBV-027 K-7 status 3→2']);
        $this->assertSame(3, $sua['koreksi']['INFO id_da tanpa rujukan: document_attachment belum diimpor (kosong)']);
        $this->assertSame(2, $sua['koreksi']['INFO id_rw Rw Jabatan tanpa rujukan: riwayat_mutasi_jabatan belum diimpor (kosong)']);
        $this->assertSame(1, $sua['koreksi']['INFO id_rw Rw Sertifikasi tanpa rujukan: riwayat_diklat belum diimpor (kosong)']);
        $this->assertSame(1, $sua['koreksi']['counter AUTO_INCREMENT 7→500'], 'counter disalin dari sumber');

        $baris = $this->db->table('siasn_upload_arsip')->select('id_siasn_upload_arsip, status, error_msg, resp_dok_uri, arsip_path, created_at')->orderBy('id_siasn_upload_arsip')->get()->getResultArray();
        $this->assertSame(['1', '5', '6'], array_column($baris, 'id_siasn_upload_arsip'));
        $this->assertSame('2', $baris[2]['status']);
        $this->assertSame(AturanSiasn::PESAN_TERHENTI, $baris[2]['error_msg']);
        $this->assertSame('2026-06-01 01:00:00', $baris[0]['created_at'], 'R1a WIB→UTC');
        $this->assertSame('peremajaan\dok\1.pdf', $baris[0]['resp_dok_uri'], 'respons BKN disalin mentah');
        $this->assertSame("Gagal \'x\'", $baris[0]['error_msg'], 'pesan galat disalin mentah');
        $this->assertSame("arsip/uji/Bu's.pdf", $baris[0]['arsip_path'], 'arsip_path ikut stripslashes (DBV-029)');

        $this->assertSame(1, $this->db->table('double_riwayat_kp')->where('nip', self::NIP_B)->countAllResults());
    }

    public function testArsipIdDaNullGagalDenganJumlah(): void
    {
        $this->src->table('siasn_upload_arsip')->insertBatch([$this->arsip(1, 'Rw Jabatan', 5, null, 0), $this->arsip(2, 'Rw Jabatan', 6, null, 1)]);

        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('siasn_upload_arsip: 2 baris id_da NULL (id_da NOT NULL, DBV-027 K-6).');

        $this->jalankanImpor([7], dryRun: true);
    }

    public function testDoubleRiwayatKpNipTanpaPegawaiGagal(): void
    {
        $this->src->table('double_riwayat_kp')->insert(['nip' => '000000000000000099', 'created_at' => '2026-06-01 08:00:00']);

        try {
            $this->jalankanImpor([7]);
            $this->fail('NIP tanpa pegawai harus gagal (K-8, FK).');
        } catch (ImporGagal $e) {
            $this->assertSame('Rujukan yatim double_riwayat_kp.nip: 1 nilai tidak ada di tabel induk (audit DBV-019).', $e->getMessage());
        }

        $this->assertSame(0, $this->db->table('double_riwayat_kp')->countAllResults());
    }

    public function testReferensiDanRefUnorDariSkemaSiasn(): void
    {
        $this->siapkanSumberSiasn(self::REFERENSI);
        $this->sia->table('REF_UNOR')->insertBatch([
            ['id' => 'unor-1', 'nama_unor' => "Biro\\'s Uji", 'id_jabatan' => 7, 'id_unit' => 5, 'id_satker' => 9, 'lvl_uker' => 2, 'created_at' => '2026-06-01 08:00:00'],
            ['id' => 'unor-2', 'nama_unor' => 'Biro Uji 2', 'id_jabatan' => null, 'id_unit' => null, 'id_satker' => null, 'lvl_uker' => null, 'created_at' => null],
        ]);
        $this->sia->table('GOLONGAN_ID')->insertBatch([['ID' => '31', 'NAMA' => 'III/a', 'id_pangkat' => 9], ['ID' => '32', 'NAMA' => 'III/b', 'id_pangkat' => null]]);
        $this->sia->table('JABATAN_FUNGSIONAL_ID')->insert(['ID' => 'jf-1', 'NAMA' => 'Analis Uji']);
        // Kode SIASN sama di dua jenis tidak bentrok (PK jenis + id_siasn).
        $this->sia->table('JENIS_KP_ID')->insert(['ID' => '31', 'NAMA' => 'Reguler', 'id_jenis_kp' => 1]);

        $laporan = $this->jalankanImpor([7]);

        $this->assertSame(2, $laporan['siasn_ref_unor<REF_UNOR']['ditulis']);
        $this->assertSame(2, $laporan['siasn_referensi<GOLONGAN_ID']['ditulis']);
        $this->assertSame(0, $laporan['siasn_referensi<TK_PENDIDIKAN']['ditulis']);

        $unor = $this->db->table('siasn_ref_unor')->where('id', 'unor-1')->get()->getRowArray();
        $this->assertSame("Biro\\'s Uji", $unor['nama_unor'], 'disalin mentah (sinkron BKN)');
        $this->assertSame(['7', '5', '9', '2'], [$unor['id_jabatan'], $unor['id_unit'], $unor['id_satker'], $unor['lvl_uker']], 'pemetaan lokal dipertahankan');
        $this->assertSame('2026-06-01 01:00:00', $unor['created_at']);

        $ref = $this->db->table('siasn_referensi')->select('jenis, id_siasn, nama, id_lokal')->orderBy('jenis')->orderBy('id_siasn')->get()->getResultArray();
        $this->assertSame([
            ['jenis' => 'golongan', 'id_siasn' => '31', 'nama' => 'III/a', 'id_lokal' => '9'],
            ['jenis' => 'golongan', 'id_siasn' => '32', 'nama' => 'III/b', 'id_lokal' => null],
            ['jenis' => 'jabatan_fungsional', 'id_siasn' => 'jf-1', 'nama' => 'Analis Uji', 'id_lokal' => null],
            ['jenis' => 'jenis_kp', 'id_siasn' => '31', 'nama' => 'Reguler', 'id_lokal' => '1'],
        ], $ref);
    }

    public function testDryRunArsipMenghitungK6K7TanpaMenulisDanTanpaInfo(): void
    {
        $this->src->table('siasn_upload_arsip')->insertBatch([
            // Dua baris terunggah dengan kunci sama → id terbesar menang.
            $this->arsip(1, 'Rw Jabatan', 5, 50, 1),
            $this->arsip(2, 'Rw Jabatan', 5, 50, 1),
            $this->arsip(3, 'Rw Jabatan', 7, 70, 3),
        ]);

        $kering = $this->jalankanImpor([7], dryRun: true)['siasn_upload_arsip'];
        $this->assertSame(['DBV-027 K-6 kunci ganda' => 1], $kering['dibuang']);
        $this->assertSame(['DBV-027 K-7 status 3→2' => 1], $kering['koreksi'], 'INFO rujukan hanya sesudah tulis');
        $this->assertSame(0, $this->db->table('siasn_upload_arsip')->countAllResults());

        $this->jalankanImpor([7]);
        $this->assertSame(['2', '3'], array_column($this->db->table('siasn_upload_arsip')->select('id_siasn_upload_arsip')->orderBy('id_siasn_upload_arsip')->get()->getResultArray(), 'id_siasn_upload_arsip'));
    }

    public function testInfoRujukanIndukTerisiDanRwNameLain(): void
    {
        $this->db->table('document_attachment')->insert(['id_attachment' => 50, 'NIP' => self::NIP_A]);
        $this->src->table('siasn_upload_arsip')->insertBatch([
            $this->arsip(1, 'Rw Jabatan', 5, 50, 0),
            $this->arsip(2, 'Rw Jabatan', 6, 51, 0),
            $this->arsip(3, 'Rw Lain', 6, 50, 0),
        ]);

        $koreksi = $this->jalankanImpor([7])['siasn_upload_arsip']['koreksi'];
        $this->assertSame(1, $koreksi['INFO id_da tanpa rujukan di document_attachment']);
        $this->assertSame(1, $koreksi['INFO rw_name di luar Rw Jabatan/Rw Sertifikasi']);
        $this->assertArrayNotHasKey('INFO id_da tanpa rujukan: document_attachment belum diimpor (kosong)', $koreksi);
    }

    public function testArsipRwNameAtauIdRwNullGagal(): void
    {
        $this->src->query('ALTER TABLE siasn_upload_arsip MODIFY rw_name VARCHAR(50) NULL, MODIFY id_rw INT NULL');
        $this->src->table('siasn_upload_arsip')->insertBatch([$this->arsip(1, 'Rw Jabatan', 5, 50, 0, ['rw_name' => null]), $this->arsip(2, 'Rw Jabatan', 6, 60, 0)]);

        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('siasn_upload_arsip: 1 baris rw_name/id_rw NULL (NOT NULL, DBV-027 K-6).');

        $this->jalankanImpor([7], dryRun: true);
    }

    public function testSkpStatusDariBerkasKeputusan(): void
    {
        $this->lepasCheck('riwayat_skp_periodik');
        $this->src->table('riwayat_skp_periodik')->insertBatch([
            $this->skp(10, self::NIP_A, 'p-4', 's-1', 2, 'Baik'),
            // Status lama 7 dipetakan ke 1 oleh berkas keputusan → baris Tukin.
            $this->skp(11, self::NIP_A, 'p-4', 's-1', 7, 'Baik'),
        ]);

        $laporan = $this->jalankanImpor([4], k: Keputusan::dariArray(['status' => ['riwayat_skp_periodik' => ['7' => 1]]]));
        $this->assertSame(['DBV-027 K-3 kunci ganda' => 1], $laporan['riwayat_skp_periodik']['dibuang']);
        $this->assertSame(['11'], array_column($this->db->table('riwayat_skp_periodik')->select('id_riwayat_skp_periodik')->get()->getResultArray(), 'id_riwayat_skp_periodik'));
    }

    public function testSkpGrupDenganNipNullDanNipTerisiGagal(): void
    {
        $this->src->query('ALTER TABLE riwayat_skp_periodik MODIFY nip VARCHAR(50) NULL');
        $this->src->table('riwayat_skp_periodik')->insertBatch([
            $this->skp(10, self::NIP_A, 'p-4', 's-1', 1, 'Baik'),
            $this->skp(11, self::NIP_A, 'p-4', 's-1', 1, 'Baik', ['nip' => null]),
        ]);

        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('1 grup kunci (id_pns, periode_id, skp_id) berisi NIP berbeda');

        $this->jalankanImpor([4], dryRun: true);
    }

    public function testKolomPetaKolomTidakAdaDiSumberGagal(): void
    {
        $referensi                = self::REFERENSI;
        $referensi['JENIS_KP_ID'] = null;
        $this->siapkanSumberSiasn($referensi);

        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessageMatches('/^Kolom sumber jenis_kp_id\.id_jenis_kp \(untuk siasn_referensi\.id_lokal\) tidak ada\.$/i');

        $this->jalankanImpor([7], dryRun: true);
    }

    public function testKunciSumberGandaSesudahNormalisasiGagal(): void
    {
        $this->src->query('ALTER TABLE double_riwayat_kp DROP PRIMARY KEY');
        $this->src->table('double_riwayat_kp')->insertBatch([
            ['nip' => self::NIP_A, 'created_at' => '2026-06-01 08:00:00'],
            ['nip' => self::NIP_A . "\u{200B}", 'created_at' => '2026-06-01 08:00:00'],
            ['nip' => self::NIP_B, 'created_at' => '2026-06-01 08:00:00'],
        ]);

        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('Kunci sumber double_riwayat_kp (nip) ganda sesudah normalisasi: 1 nilai.');

        $this->jalankanImpor([7], dryRun: true);
    }
}
