<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Pegawai\EksporTabel;
use App\Libraries\Kepegawaian\Pegawai\PencarianLanjutan;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;
use ZipArchive;

/**
 * B-20 penutup (MAKE-014): pencarian lanjutan (port advsearch legacy, whitelist + parameter terikat + lingkup role 3)
 * dan ekspor CSV/XLSX daftar pegawai (filter & lingkup sama, kolom tampil, NIP teks, escape formula). PegawaiScope NYATA.
 *
 * @internal
 */
final class PegawaiPencarianEksporTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatUjiTrait;

    private int $satker;
    private string $dalam;
    private string $luar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->satker = $this->buatSatker();
        $this->dalam  = $this->buatPegawaiDiSatker($this->satker);
        $this->luar   = $this->buatPegawaiDiSatker($this->buatSatker());
        $this->db->table('pegawai')->where('nip', $this->dalam)->update(['nama' => 'Budi Pencarian', 'glr_akhir' => 'S.Kom.']);
        $this->db->table('pegawai')->where('nip', $this->luar)->update(['nama' => '=HYPERLINK("x") Budi Luar']);
    }

    // ---- Pencarian lanjutan --------------------------------------------

    public function testKatalogDanHakAkses(): void
    {
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        $katalog     = $this->json($this->asUser($super)->get('api/v1/pegawai/pencarian/katalog'))['data'];

        $this->assertSame(array_keys(PencarianLanjutan::JENIS), array_column($katalog['jenis'], 'key'));
        $this->assertSame(PencarianLanjutan::OPERATOR, $katalog['operator']);
        $this->assertSame(['rwy'], $katalog['jenis'][0]['jenis_data'], 'umum hanya data terakhir pegawai (legacy)');

        foreach ([Role::PIMPINAN, Role::PEGAWAI, Role::PTT] as $role) {
            [, , $u] = $this->aktor($role);
            $this->asUser($u)->get('api/v1/pegawai/pencarian/katalog')->assertStatus(403);
            $this->asUser($u)->withBodyFormat('json')->post('api/v1/pegawai/pencarian', ['kriteria' => [$this->k('umum', 'rwy', 'nama', 'LIKE', 'Budi')]])->assertStatus(403);
        }
    }

    public function testKriteriaUmumDanSnapshotDenganAndOr(): void
    {
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        $idUnit      = (int) $this->db->table('pegawai_mutasi_jabatan')->where('nip', $this->dalam)->get()->getRow('id_unit');

        $this->assertSame([$this->dalam], $this->cari($super, [$this->k('umum', 'rwy', 'nama', 'LIKE', 'Pencarian')]));
        // Snapshot jabatan (data terakhir) + AND.
        $this->assertSame([$this->dalam], $this->cari($super, [
            $this->k('umum', 'rwy', 'nama', 'LIKE', 'Budi'),
            $this->k('jabatan', 'last', 'id_unit', '=', (string) $idUnit, 'AND'),
        ]));
        // OR menggabungkan; IN dipisah koma.
        $this->assertEqualsCanonicalizing([$this->dalam, $this->luar], $this->cari($super, [
            $this->k('umum', 'rwy', 'nama', 'LIKE', 'Pencarian'),
            $this->k('umum', 'rwy', 'nama', 'LIKE', 'Luar', 'OR'),
        ]));
        $this->assertEqualsCanonicalizing([$this->dalam, $this->luar], $this->cari($super, [$this->k('umum', 'rwy', 'jenis_kelamin', 'IN', '1, 2')]));
        $this->assertSame([], $this->cari($super, [$this->k('umum', 'rwy', 'jenis_kelamin', 'NOT IN', '1,2')]));

        // Pegawai status 2 tidak ikut (legacy pegawai.status = 1).
        $this->db->table('pegawai')->where('nip', $this->luar)->update(['status' => 2]);
        $this->assertSame([], $this->cari($super, [$this->k('umum', 'rwy', 'nama', 'LIKE', 'Luar')]));
    }

    public function testKriteriaSeluruhRiwayatExistsStatus1(): void
    {
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        $this->db->table('riwayat_seminar')->insert(['nip' => $this->dalam, 'nama_seminar' => 'Seminar Kearsipan', 'jenis_seminar' => 1, 'status' => 1]);
        $this->db->table('riwayat_seminar')->insert(['nip' => $this->luar, 'nama_seminar' => 'Seminar Kearsipan', 'jenis_seminar' => 1, 'status' => 0]);

        $this->assertSame([$this->dalam], $this->cari($super, [$this->k('seminar', 'rwy', 'nama_seminar', 'LIKE', 'kearsipan')]));
        $this->assertSame([$this->dalam], $this->cari($super, [$this->k('seminar', 'rwy', 'jenis_seminar', 'IN', '1, 2')]));
        $this->assertSame([], $this->cari($super, [$this->k('seminar', 'rwy', 'jenis_seminar', '>', '1')]));
    }

    public function testRole3DibatasiLingkup(): void
    {
        [, , $admin3] = $this->aktor(Role::ADMIN_SATKER, [], $this->satker);

        // [V2] legacy advsearch tidak membatasi role 3.
        $this->assertSame([$this->dalam], $this->cari($admin3, [$this->k('umum', 'rwy', 'nama', 'LIKE', 'Budi')]));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function injeksiProvider(): array
    {
        return [
            'atribut bukan whitelist'     => [['atribut' => 'nama` = 1 OR 1=1 -- '], 'kriteria.0.atribut'],
            'atribut kolom lain tabel'    => [['atribut' => 'password'], 'kriteria.0.atribut'],
            'operator bukan whitelist'    => [['operator' => '= 1 OR 1 ='], 'kriteria.0.operator'],
            'jenis riwayat tidak dikenal' => [['jenis_rwy' => 'pengguna'], 'kriteria.0.jenis_rwy'],
            'umum tanpa data terakhir'    => [['jenis_data' => 'last'], 'kriteria.0.jenis_data'],
            'nilai kosong'                => [['nilai' => ' '], 'kriteria.0.nilai'],
        ];
    }

    /**
     * @param array<string, string> $ubah
     */
    #[DataProvider('injeksiProvider')]
    public function testInjeksiDitolak422(array $ubah, string $kunci): void
    {
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        $res         = $this->asUser($super)->withBodyFormat('json')->post('api/v1/pegawai/pencarian', ['kriteria' => [$ubah + $this->k('umum', 'rwy', 'nama', 'LIKE', 'Budi')]]);

        $res->assertStatus(422);
        $this->assertArrayHasKey($kunci, $this->json($res)['errors']);
    }

    public function testValidasiLainDanNilaiBerkutipAman(): void
    {
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        $post        = fn (array $body) => $this->asUser($super)->withBodyFormat('json')->post('api/v1/pegawai/pencarian', $body);

        $this->assertArrayHasKey('kriteria', $this->galat422($post(['kriteria' => []])));
        $this->assertArrayHasKey('kriteria.1.hubung', $this->galat422($post(['kriteria' => [
            $this->k('umum', 'rwy', 'nama', 'LIKE', 'Budi'), $this->k('umum', 'rwy', 'nama', 'LIKE', 'x', 'XOR'),
        ]])));
        $this->assertArrayHasKey('kriteria.0.atribut', $this->galat422($post(['kriteria' => [$this->k('pelatihan', 'last', 'jenis_diklat', '=', '1')]])), 'atribut hanya riwayat');

        // Nilai berkutip/komentar SQL diperlakukan sebagai data (tanpa galat SQL, tanpa hasil).
        $this->assertSame([], $this->cari($super, [$this->k('umum', 'rwy', 'nama', '=', "x' OR '1'='1")]));
        $this->assertSame([], $this->cari($super, [$this->k('umum', 'rwy', 'nama', 'LIKE', '%')]), 'wildcard LIKE di-escape');
    }

    public function testRegresiPembentukanSqlPencarian(): void
    {
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        $kriteria    = [
            $this->k('umum', 'rwy', 'nama', '=', 'OR (SELECT 1 FROM pengguna LIMIT 1)=1 OR'),
            $this->k('alamat', 'rwy', 'alamat', '=', 'q:pegawai.nama:q', 'OR'),
        ];
        $res = $this->asUser($super)->withBodyFormat('json')->post('api/v1/pegawai/pencarian', ['kriteria' => $kriteria]);
        $this->assertArrayHasKey('kriteria.1.nilai', $this->galat422($res));

        // Titik dua tunggal tetap boleh.
        $this->assertSame([], $this->cari($super, [$this->k('alamat', 'rwy', 'alamat', '=', 'Jl. A: 5')]));

        // Tanpa penolakan pola pun, nilai tidak pernah menjadi teks SQL: builder ber-named-bind tidak tersentuh.
        $mentah = [
            ['jenis_rwy' => 'umum', 'tabel' => 'pegawai', 'atribut' => 'nama', 'operator' => '=', 'nilai' => 'OR (SELECT 1 FROM pengguna LIMIT 1)=1 OR', 'hubung' => 'AND'],
            ['jenis_rwy' => 'alamat', 'tabel' => 'riwayat_alamat', 'atribut' => 'alamat', 'operator' => '=', 'nilai' => 'q:pegawai.status:q', 'hubung' => 'OR'],
            ['jenis_rwy' => 'umum', 'tabel' => 'pegawai', 'atribut' => 'nama', 'operator' => 'LIKE', 'nilai' => "%' OR 1=1 -- :pegawai.status:", 'hubung' => 'OR'],
        ];
        [$sql] = PencarianLanjutan::sqlNip($mentah, $this->db);
        $this->assertStringNotContainsString('pengguna', $sql);
        $this->assertStringNotContainsString(':pegawai', $sql);

        $builder = $this->db->table('pegawai')->select('pegawai.nip')->where('pegawai.status', 1);
        PencarianLanjutan::terapkan($builder, $mentah, $this->db);
        $this->assertSame([], array_column($builder->get()->getResultArray(), 'nip'));
    }

    // ---- Ekspor --------------------------------------------------------

    public function testEksporCsvKolomTampilLingkupDanEscapeFormula(): void
    {
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        $res         = $this->asUser($super)->get('api/v1/pegawai/export?format=csv&kolom=jabatan,nip_lama&search=Budi');

        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertMatchesRegularExpression('/^attachment; filename="daftar_pegawai_\d{8}_\d{6}\.csv"$/', $res->response()->getHeaderLine('Content-Disposition'));
        $csv = $res->response()->getBody();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $baris = array_map(str_getcsv(...), array_filter(explode("\n", substr($csv, 3))));
        $this->assertSame(['Nama', 'NIP', 'Jabatan', 'NIP Lama'], $baris[0]);
        $nama = array_column(array_slice($baris, 1), 0);
        $this->assertContains('Budi Pencarian, S.Kom.', $nama);
        $this->assertContains("'=HYPERLINK(\"x\") Budi Luar", $nama, 'formula diberi apostrof');
        $this->assertContains($this->dalam, array_column(array_slice($baris, 1), 1));

        // Role 3: hanya lingkupnya.
        [, , $admin3] = $this->aktor(Role::ADMIN_SATKER, [], $this->satker);
        $csv3         = $this->asUser($admin3)->get('api/v1/pegawai/export?format=csv&search=Budi')->response()->getBody();
        $this->assertStringContainsString($this->dalam, $csv3);
        $this->assertStringNotContainsString($this->luar, $csv3);
    }

    public function testEksporXlsxNipTeksDanHakAkses(): void
    {
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        $res         = $this->asUser($super)->get('api/v1/pegawai/export?format=xlsx&search=Budi');

        $res->assertStatus(200);
        $res->assertHeader('Content-Type', EksporTabel::MIME[EksporTabel::XLSX]);
        $sheet = $this->sheet($res->response()->getBody());
        $this->assertStringContainsString('<t xml:space="preserve">' . $this->dalam . '</t>', $sheet, 'NIP sel teks');
        $this->assertStringContainsString('t="inlineStr"', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);
        // XLSX: sel teks inlineStr tidak pernah dievaluasi → tanpa apostrof.
        $this->assertStringContainsString('preserve">=HYPERLINK', $sheet);

        foreach ([['format=pdf', 'format'], ['kolom=password', 'kolom']] as [$q, $kunci]) {
            $this->assertArrayHasKey($kunci, $this->galat422($this->asUser($super)->get("api/v1/pegawai/export?{$q}")));
        }

        [, , $pegawai] = $this->aktor(Role::PEGAWAI);
        $this->asUser($pegawai)->get('api/v1/pegawai/export')->assertStatus(403);
        [, , $pimpinan] = $this->aktor(Role::PIMPINAN);
        $this->asUser($pimpinan)->get('api/v1/pegawai/export?format=csv')->assertStatus(200);
        $this->asUser($pimpinan)->withBodyFormat('json')->post('api/v1/pegawai/pencarian/export', ['format' => 'csv', 'kriteria' => [$this->k('umum', 'rwy', 'nama', 'LIKE', 'Budi')]])->assertStatus(403);
    }

    public function testEksporHasilPencarianLanjutan(): void
    {
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        $res         = $this->asUser($super)->withBodyFormat('json')->post('api/v1/pegawai/pencarian/export', [
            'format' => 'csv', 'kolom' => 'jabatan', 'kriteria' => [$this->k('umum', 'rwy', 'nama', 'LIKE', 'Pencarian')],
        ]);

        $res->assertStatus(200);
        $csv = $res->response()->getBody();
        $this->assertStringContainsString($this->dalam, $csv);
        $this->assertStringNotContainsString($this->luar, $csv);
    }

    public function testPenulisTabelUnit(): void
    {
        $this->assertSame(["'=1+1", "'+62", "'-x", "'@a", "'\tt", "' =1", 'biasa', ''], array_map(EksporTabel::aman(...), ['=1+1', '+62', '-x', '@a', "\tt", ' =1', 'biasa', '']));
        $this->assertSame(['A', 'Z', 'AA', 'AZ', 'BA'], array_map(EksporTabel::kolom(...), [0, 25, 26, 51, 52]));
        $sheet = $this->sheet(EksporTabel::xlsx(['Nama'], [["A & <B>\x01"]]));
        $this->assertStringContainsString('A &amp; &lt;B&gt;</t>', $sheet, 'XML di-escape dan karakter kontrol dibuang');
    }

    // ---- Bantuan -------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function k(string $jenis, string $data, string $atribut, string $op, string $nilai, string $hubung = 'AND'): array
    {
        return ['jenis_rwy' => $jenis, 'jenis_data' => $data, 'atribut' => $atribut, 'operator' => $op, 'nilai' => $nilai, 'hubung' => $hubung];
    }

    /**
     * NIP hasil pencarian (hanya NIP fixture uji ini).
     *
     * @param array<string, mixed>       $user
     * @param list<array<string, string>> $kriteria
     *
     * @return list<string>
     */
    private function cari(array $user, array $kriteria): array
    {
        $res = $this->asUser($user)->withBodyFormat('json')->post('api/v1/pegawai/pencarian', ['kriteria' => $kriteria, 'per_page' => 100]);
        $res->assertStatus(200);

        return array_values(array_intersect(array_column($this->json($res)['data']['items'], 'nip'), [$this->dalam, $this->luar]));
    }

    /**
     * @return array<string, mixed>
     */
    private function galat422(TestResponse $res): array
    {
        $res->assertStatus(422);

        return $this->json($res)['errors'];
    }

    private function sheet(string $xlsx): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'uji');
        file_put_contents($tmp, $xlsx);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp));
        $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
        $isi = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($tmp);

        return $isi;
    }
}
