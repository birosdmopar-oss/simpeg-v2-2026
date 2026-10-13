<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Nip\JenisRujukanNip;
use App\Libraries\Kepegawaian\Nip\KeberadaanV2;
use App\Libraries\Kepegawaian\Nip\RujukanNip;
use App\Libraries\Kepegawaian\Nip\TabelAnakNip;
use App\Libraries\Kepegawaian\NipCascadeService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * B-06 Koreksi NIP + butir cascade B-21 (MAKE-010): satu transaksi ikut `update_nip` legacy (keputusan #10) atas
 * `TabelAnakNip::sampaiFase(3)` — data dummy di 20+ tabel riwayat & snapshot berpindah semua, rollback penuh bila satu
 * langkah gagal, akun pegawai ikut legacy penuh (username + password = NIP baru, keputusan user 13-10-2026), sesi
 * dicabut, registry cocok dengan skema nyata.
 *
 * @internal
 */
final class NipCascadeTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    private const NIP_BARU = '198801012010011999';

    /**
     * Tabel registry Fase 3 yang belum ada di skema `main` (temuan registry MAKE-010; G-03 membuat
     * `dm_user_lokasi_presensi`). Bila tabel ini dibuat, perbarui daftar ini (koreksi NIP mulai mengarahkannya).
     */
    private const TERLEWAT = ['user_lokasi_presensi.nip'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testCascadeSeluruhTabelRegistryDalamSatuTransaksi(): void
    {
        [$lama, $super] = $this->siapkan();
        $atasanLain     = $this->buatPegawai();
        $harapan        = $this->isiSemuaTabelAnak($lama, $atasanLain);

        $this->assertGreaterThanOrEqual(20, count($harapan), 'DoD B-06: data dummy di 20+ tabel');

        $hasil = (new NipCascadeService())->koreksi($super, $lama, ' ' . self::NIP_BARU . ' ');

        $this->assertSame([$lama, self::NIP_BARU], [$hasil['nip_lama'], $hasil['nip_baru']]);
        $this->assertSame(self::TERLEWAT, $hasil['dilewati']);
        $this->assertSame(0, $this->db->table('pegawai')->where('nip', $lama)->countAllResults());
        $this->assertSame(1, $this->db->table('pegawai')->where('nip', self::NIP_BARU)->countAllResults());

        foreach ($harapan as $kunci => $jumlah) {
            [$tabel, $kolom] = explode('.', $kunci);
            $this->assertSame(0, $this->db->table($tabel)->where($kolom, $lama)->countAllResults(), "{$kunci} tidak tersisa NIP lama");
            $this->assertSame($jumlah, $this->db->table($tabel)->where($kolom, self::NIP_BARU)->countAllResults(), "{$kunci} pindah ke NIP baru");
            $this->assertSame($jumlah, $hasil['tabel'][$kunci] ?? null, "{$kunci} tercatat di hasil");
        }

        // Baris milik pegawai lain yang menunjuk NIP lama sebagai atasan ikut diarahkan; NIP pemiliknya tetap.
        $this->assertSame(1, $this->db->table('riwayat_lckh')->where('nip', $atasanLain)->where('nip_atasan', self::NIP_BARU)->countAllResults());

        $audit = $this->db->table('audit_logs')->where('entity', 'pegawai')->where('entity_id', self::NIP_BARU)->get()->getRowArray();
        $this->assertIsArray($audit);
        $this->assertSame('update', $audit['event']);
        $this->assertSame(['nip' => $lama], json_decode((string) $audit['before_json'], true));
    }

    public function testRollbackPenuhBilaSatuTabelGagal(): void
    {
        [$lama, $super] = $this->siapkan();
        $harapan        = $this->isiSemuaTabelAnak($lama, $this->buatPegawai());

        // Entri terakhir menunjuk kolom yang tidak ada: semua UPDATE sebelumnya sudah jalan saat langkah ini gagal.
        $rusak    = new RujukanNip('riwayat_kp', 'kolom_tidak_ada', JenisRujukanNip::FkLegacy, '[K] uji', 'fk_uji', false, KeberadaanV2::Ada, 3, 'B-02', 'uji rollback');
        $registry = [...TabelAnakNip::sampaiFase(3), $rusak];

        try {
            (new NipCascadeService(null, $registry))->koreksi($super, $lama, self::NIP_BARU);
            $this->fail('Koreksi seharusnya gagal.');
        } catch (DatabaseException) {
            // Diharapkan.
        }

        $this->assertSame(1, $this->db->table('pegawai')->where('nip', $lama)->countAllResults());
        $this->assertSame(0, $this->db->table('pegawai')->where('nip', self::NIP_BARU)->countAllResults());

        foreach ($harapan as $kunci => $jumlah) {
            [$tabel, $kolom] = explode('.', $kunci);
            $this->assertSame($jumlah, $this->db->table($tabel)->where($kolom, $lama)->countAllResults(), "{$kunci} kembali ke NIP lama");
            $this->assertSame(0, $this->db->table($tabel)->where($kolom, self::NIP_BARU)->countAllResults(), "{$kunci} tanpa NIP baru");
        }

        $this->assertSame(0, $this->db->table('audit_logs')->where('entity', 'pegawai')->where('entity_id', self::NIP_BARU)->countAllResults());
    }

    public function testAkunIkutLegacyPenuhDanSesiDicabut(): void
    {
        $lama      = $this->buatPegawai();
        $idAkun    = $this->buatAkunUntuk($lama, Role::PEGAWAI);
        [, $super] = $this->siapkan();

        $login = $this->login($lama, AuthSeeder::PASSWORD);
        $login->assertStatus(200);
        $this->assertGreaterThan(0, $this->db->table('token')->where('id_pengguna', $idAkun)->countAllResults());

        $hasil = (new NipCascadeService())->koreksi($super, $lama, self::NIP_BARU);

        $akun = $this->db->table('pengguna')->where('id_pengguna', $idAkun)->get()->getRowArray();
        $this->assertSame([self::NIP_BARU, self::NIP_BARU], [$akun['nip'], $akun['username']]);
        $this->assertNull($akun['password_legacy']);
        $this->assertTrue(password_verify(self::NIP_BARU, (string) $akun['password']));
        $this->assertSame(0, $this->db->table('token')->where('id_pengguna', $idAkun)->countAllResults());
        $this->assertGreaterThan(0, $hasil['sesi_dicabut']);

        $this->clearAuthState();
        $this->login(self::NIP_BARU, AuthSeeder::PASSWORD)->assertStatus(401);
        $this->clearAuthState();
        $this->login($lama, self::NIP_BARU)->assertStatus(401);
        $this->clearAuthState();
        $this->login(self::NIP_BARU, self::NIP_BARU)->assertStatus(200);
    }

    public function testRole1Saja(): void
    {
        $lama = $this->buatPegawai();

        foreach ([Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            $aktor = $this->buatPegawai();
            $auth  = $this->authUntukAkun($this->buatAkunUntuk($aktor, $role));

            try {
                (new NipCascadeService())->koreksi($auth, $lama, self::NIP_BARU);
                $this->fail("role {$role} seharusnya 403");
            } catch (ForbiddenException) {
                $this->assertSame(1, $this->db->table('pegawai')->where('nip', $lama)->countAllResults());
            }
        }
    }

    public function testValidasiDanKeunikan(): void
    {
        [$lama, $super] = $this->siapkan();
        $lain           = $this->buatPegawai(['nama' => 'Pegawai Lain', 'glr_awal' => 'Dr.']);
        $service        = new NipCascadeService();

        $this->assertField('nip_baru', fn () => $service->koreksi($super, $lama, ''));
        $this->assertField('nip_baru', fn () => $service->koreksi($super, $lama, '12AB'));
        $this->assertField('nip_baru', fn () => $service->koreksi($super, $lama, ['x']));
        $this->assertField('nip_baru', fn () => $service->koreksi($super, $lama, $lama));
        $this->assertField('nip_lama', fn () => $service->koreksi($super, '', self::NIP_BARU));

        try {
            $service->koreksi($super, $lama, $lain);
            $this->fail('NIP terdaftar seharusnya 422');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Dr. Pegawai Lain', $e->getErrors()['nip_baru'][0]);
        }

        // NIP baru sudah dipakai sebagai username akun lain (akun tanpa NIP).
        $this->buatAkunTanpaNip(self::NIP_BARU, Role::ADMIN_VIEW_ESELON1);
        $this->assertField('nip_baru', fn () => $service->koreksi($super, $lama, self::NIP_BARU));

        $this->expectException(NotFoundException::class);
        $service->koreksi($super, '999999999999999999', '199901012020011001');
    }

    public function testRegistryCocokDenganSkema(): void
    {
        // Setiap FK ke pegawai(nip) di skema tercakup registry Fase 3 (B-21): FK baru tanpa entri registry = test gagal.
        $registry = array_map(static fn (RujukanNip $r): string => strtolower($r->tabel . '.' . $r->kolomDiV2()), TabelAnakNip::sampaiFase(3));
        $prefix   = $this->db->getPrefix();
        $rows     = $this->db->query(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?',
            [$prefix . 'pegawai', 'nip'],
        )->getResultArray();

        $this->assertNotSame([], $rows);

        foreach ($rows as $row) {
            $kunci = strtolower(substr((string) $row['t'], strlen($prefix)) . '.' . $row['c']);
            $this->assertContains($kunci, $registry, "FK {$kunci} belum ada di TabelAnakNip::sampaiFase(3)");
        }

        $terlewat = [];

        foreach (TabelAnakNip::sampaiFase(3) as $r) {
            if (! $this->tabelAda($r->tabel)) {
                $terlewat[] = $r->tabel . '.' . $r->kolomDiV2();
            }
        }

        $this->assertSame(self::TERLEWAT, $terlewat);
        $this->assertTrue($this->db->fieldExists('nip', 'jabatan_koordinasi'));
    }

    public function testEndpointKoreksiNip(): void
    {
        $lama             = $this->buatPegawai();
        [, , $superRow]   = $this->aktorBaris(Role::SUPER_ADMIN);
        [, , $pegawaiRow] = $this->aktorBaris(Role::PEGAWAI);

        $this->withBodyFormat('json')->post("api/v1/pegawai/{$lama}/koreksi-nip", ['nip_baru' => self::NIP_BARU])->assertStatus(401);
        $this->asUser($pegawaiRow)->withBodyFormat('json')->post("api/v1/pegawai/{$lama}/koreksi-nip", ['nip_baru' => self::NIP_BARU])->assertStatus(403);
        $this->asUser($superRow)->withBodyFormat('json')->post("api/v1/pegawai/{$lama}/koreksi-nip", ['nip_baru' => 'abc'])->assertStatus(422);

        $result = $this->asUser($superRow)->withBodyFormat('json')->post("api/v1/pegawai/{$lama}/koreksi-nip", ['nip_baru' => self::NIP_BARU]);

        $result->assertStatus(200);
        $this->assertSame(self::NIP_BARU, $this->json($result)['data']['nip_baru']);
        $this->assertSame(1, $this->db->table('pegawai')->where('nip', self::NIP_BARU)->countAllResults());
    }

    /**
     * Pegawai sasaran + AuthContext Super Admin.
     *
     * @return array{0: string, 1: \App\Libraries\Auth\AuthContext}
     */
    private function siapkan(): array
    {
        $super = $this->buatPegawai();

        return [$this->buatPegawai(), $this->authUntukAkun($this->buatAkunUntuk($super, Role::SUPER_ADMIN))];
    }

    /**
     * @return array{0: string, 1: int, 2: array<string, mixed>}
     */
    private function aktorBaris(int $role): array
    {
        $nip = $this->buatPegawai();
        $id  = $this->buatAkunUntuk($nip, $role);

        return [$nip, $id, (array) $this->db->table('pengguna')->where('id_pengguna', $id)->get()->getRowArray()];
    }

    /**
     * Satu (atau dua) baris dummy di setiap tabel registry Fase 3 yang ada di skema, menunjuk $nip; mengembalikan
     * `tabel.kolom` => jumlah baris yang harus berpindah. Kolom NOT NULL tanpa default diisi nilai sintetis per tipe;
     * FK lain dibiarkan NULL kecuali yang wajib (disiapkan di sini).
     *
     * @return array<string, int>
     */
    private function isiSemuaTabelAnak(string $nip, string $pegawaiLain): array
    {
        $harapan = [];
        $akun    = $this->buatAkunUntuk($nip, Role::PEGAWAI);
        $this->assertGreaterThan(0, $akun);
        $harapan['pengguna.nip'] = 1;

        // Riwayat induk lebih dulu (snapshot pegawai_cpns/pegawai_alamat merujuknya, NOT NULL).
        $idKp     = $this->sisip('riwayat_kp', ['nip' => $nip]);
        $idAlamat = $this->sisip('riwayat_alamat', ['nip' => $nip]);
        $idKel    = $this->sisip('riwayat_keluarga', ['nip' => $nip]);
        $this->sisip('detail_anak', ['id_riwayat_keluarga' => $idKel]);
        $this->sisip('riwayat_kp', ['nip' => $nip]);

        $khusus = [
            'riwayat_kp.nip'             => 2,
            'riwayat_alamat.nip'         => 1,
            'riwayat_keluarga.nip'       => 1,
            'pengguna.nip'               => 1,
            'pegawai_mutasi_jabatan.nip' => 1, // dibuat fixture buatPegawai
        ];
        $harapan = array_merge($harapan, $khusus);

        $isian = [
            'pegawai_cpns'          => ['id_riwayat_kp' => $idKp],
            'pegawai_alamat'        => ['id_riwayat_alamat' => $idAlamat],
            'pegawai_alamat_kantor' => ['id_riwayat_alamat' => $idAlamat],
            'faq_rate'              => ['id_faq_article' => $this->buatArtikelFaq(), 'rate' => 1],
            'jabatan_koordinasi'    => ['jenis' => 1, 'jabatan' => 'Koordinator Uji'],
            'pegawai_hist'          => ['tgl_lahir' => '1990-01-01'],
        ];

        foreach (TabelAnakNip::sampaiFase(3) as $r) {
            $kunci = $r->tabel . '.' . $r->kolomDiV2();

            if (isset($khusus[$kunci]) || ! $this->tabelAda($r->tabel) || in_array($r->kunci(), ['token.nip', 'pengguna.username'], true)) {
                continue;
            }

            if ($kunci === 'riwayat_lckh.nip_atasan') {
                $this->sisip('riwayat_lckh', ['nip' => $pegawaiLain, 'nip_atasan' => $nip]);
                $harapan[$kunci] = 1;

                continue;
            }

            $baris = array_merge($isian[$r->tabel] ?? [], [$r->kolomDiV2() => $nip]);

            if ($r->tabel === 'riwayat_lckh') {
                $baris['nip_atasan'] = $pegawaiLain;
            }

            $this->sisip($r->tabel, $baris);
            $harapan[$kunci] = 1;
        }

        return $harapan;
    }

    /**
     * Tabel ada di skema aktif (information_schema, tanpa cache daftar tabel CI4).
     */
    private function tabelAda(string $tabel): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($tabel)],
        )->getRowArray() !== null;
    }

    /**
     * @param array<string, mixed> $isi
     */
    private function sisip(string $tabel, array $isi): int
    {
        $prefix = $this->db->getPrefix();
        $kolom  = $this->db->query(
            'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$prefix . $tabel],
        )->getResultArray();

        $baris = [];

        foreach ($kolom as $k) {
            $nama = (string) $k['COLUMN_NAME'];

            if (array_key_exists($nama, $isi) || $k['IS_NULLABLE'] === 'YES' || $k['COLUMN_DEFAULT'] !== null || str_contains((string) $k['EXTRA'], 'auto_increment')) {
                continue;
            }

            $baris[$nama] = match ((string) $k['DATA_TYPE']) {
                'date'                                                                            => '2020-01-01',
                'datetime', 'timestamp'                                                           => '2020-01-01 00:00:00',
                'year'                                                                            => 2020,
                'int', 'tinyint', 'smallint', 'bigint', 'mediumint', 'decimal', 'double', 'float' => 1,
                default                                                                           => substr('Uji ' . $nama, 0, max(1, (int) ($k['CHARACTER_MAXIMUM_LENGTH'] ?? 10))),
            };
        }

        $this->db->table($tabel)->insert(array_merge($baris, $isi));

        return (int) $this->db->insertID();
    }

    private function buatArtikelFaq(): int
    {
        $topik = $this->sisip('faq_topic', []);
        $sub   = $this->sisip('faq_sub_topic', ['id_faq_topic' => $topik]);

        return $this->sisip('faq_article', ['id_faq_sub_topic' => $sub, 'status' => 1]);
    }

    private function assertField(string $field, callable $panggil): void
    {
        try {
            $panggil();
            $this->fail("Seharusnya 422 errors.{$field}");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->getErrors());
        }
    }
}
