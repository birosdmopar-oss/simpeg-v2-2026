<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Dashboard\Konten\Gambar\JenisGambar;
use App\Libraries\Dashboard\Konten\Gambar\KontenGambarService;
use App\Libraries\Dashboard\Konten\Gambar\PenyimpananGambar;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Konten;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * F-04 Popup / F-05 Banner (MAKE-079/080; PRD Fase 7 v2.0 §5.3/5.4): kelola (target → snapshot penerima, sinkron selisih,
 * urutan, hapus), validasi gambar (tipe dari isi, rasio banner 4:1) dan periode, tampil `aktif` per NIP + periode WIB,
 * akses gambar (role 1 / penerima), audit_logs.
 *
 * @internal
 */
final class KontenGambarServiceTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private string $akar;

    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->akar = sys_get_temp_dir() . '/konten_uji_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }

        foreach (glob($this->akar . '/*/*') ?: [] as $f) {
            @unlink($f);
        }

        foreach (glob($this->akar . '/*') ?: [] as $d) {
            @rmdir($d);
        }

        @rmdir($this->akar);
        parent::tearDown();
    }

    public function testBuatPopupMembuatSnapshotPenerimaUrutanDanAudit(): void
    {
        [$satker, $p1, $p2] = $this->satkerDenganDuaPegawai();
        $svc                = $this->service(JenisGambar::Popup);

        $r = $svc->buat($this->input(['target' => ['unit_satker' => ["sat_{$satker}"], 'kecuali' => [$p2]]]), $this->gambar(300, 200), 77);
        $s = $svc->buat($this->input(['target' => ['pegawai' => [$p1]]]), $this->gambar(300, 200), 77);

        $this->assertSame(1, $r['urutan']);
        $this->assertSame(2, $s['urutan']);
        $this->assertSame(1, $r['jumlah_penerima']);
        $this->assertSame('/konten/popup/' . $r['id'] . '/gambar', $r['gambar_url']);
        $this->assertSame('2026-11-01 08:00:00', $r['tgl_mulai']);

        $row = $this->db->table('popup')->where('id_popup', $r['id'])->get()->getRowArray();
        $this->assertMatchesRegularExpression('#^popup/[0-9a-f]{32}\.png$#', $row['file']);
        $this->assertFileExists($this->akar . '/' . $row['file']);
        $this->assertSame(77, (int) $row['created_by']);

        $audit = $this->db->table('audit_logs')->where('entity', 'popup')->where('entity_id', (string) $r['id'])->get()->getResultArray();
        $this->assertNotEmpty($audit);
    }

    public function testValidasiPeriodeTargetDanBerkas(): void
    {
        $svc = $this->service(JenisGambar::Popup);
        $ok  = $this->input(['target' => ['unit_satker' => ['0']]]);

        foreach ([
            'tgl_akhir' => [['tgl_akhir' => '2026-10-31 23:59'] + $ok, $this->gambar(300, 200)],
            'tgl_mulai' => [['tgl_mulai' => '2026-11-01'] + $ok, $this->gambar(300, 200)],
            'target'    => [['target' => ['unit_satker' => []]] + $ok, $this->gambar(300, 200)],
            'url'       => [['url' => 'javascript:alert(1)'] + $ok, $this->gambar(300, 200)],
            'berkas'    => [$ok, null],
        ] as $field => [$input, $berkas]) {
            try {
                $svc->buat($input, $berkas, 1);
                $this->fail("Harus 422 untuk {$field}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->getErrors() ?? [], $field);
            }
        }

        $this->assertSame(0, $this->db->table('popup')->countAllResults());
    }

    public function testBannerWajibRasioEmpatBanding1DanGambarDitolakBilaBukanGambar(): void
    {
        $svc = $this->service(JenisGambar::Banner);
        $in  = ['tgl_mulai' => '2026-11-01', 'tgl_akhir' => '2026-11-30', 'target' => ['unit_satker' => ['0']]];

        $this->assertSame(1, $svc->buat($in, $this->gambar(1600, 400), 1)['urutan']);

        foreach ([$this->gambar(1600, 800), $this->gambar(400, 100), $this->teks()] as $bad) {
            try {
                $svc->buat($in, $bad, 1);
                $this->fail('Harus 422');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('berkas', $e->getErrors() ?? []);
            }
        }

        $this->assertSame(1, $this->db->table('banner')->countAllResults());
    }

    public function testUbahMenyinkronkanSelisihDanMenggantiGambar(): void
    {
        [$satker, $p1, $p2] = $this->satkerDenganDuaPegawai();
        $svc                = $this->service(JenisGambar::Popup);
        $r                  = $svc->buat($this->input(['target' => ['pegawai' => [$p1, $p2]]]), $this->gambar(300, 200), 1);
        $lama               = $this->db->table('popup')->where('id_popup', $r['id'])->get()->getRowArray()['file'];

        $u    = $svc->ubah($r['id'], $this->input(['target' => ['pegawai' => [$p1]], 'keterangan' => 'baru']), $this->gambar(320, 220), 2);
        $baru = $this->db->table('popup')->where('id_popup', $r['id'])->get()->getRowArray();

        $this->assertSame(1, $u['jumlah_penerima']);
        $this->assertSame('baru', $u['keterangan']);
        $this->assertNotSame($lama, $baru['file']);
        $this->assertFileDoesNotExist($this->akar . '/' . $lama);
        $this->assertFileExists($this->akar . '/' . $baru['file']);
        $this->assertSame(2, (int) $baru['updated_by']);

        // tanpa berkas baru: gambar lama dipertahankan
        $svc->ubah($r['id'], $this->input(['target' => ['pegawai' => [$p1]]]), null, 2);
        $this->assertSame($baru['file'], $this->db->table('popup')->where('id_popup', $r['id'])->get()->getRowArray()['file']);
    }

    public function testGeserDanHapus(): void
    {
        $svc = $this->service(JenisGambar::Banner);
        $in  = ['tgl_mulai' => '2026-11-01', 'tgl_akhir' => '2026-11-30', 'target' => ['unit_satker' => ['0']]];
        $a   = $svc->buat($in, $this->gambar(1600, 400), 1);
        $b   = $svc->buat($in, $this->gambar(1600, 400), 1);
        $c   = $svc->buat($in, $this->gambar(1600, 400), 1);

        $svc->geser($c['id'], 'naik');
        $urut = array_column($svc->daftar([])['items'], 'id');
        $this->assertSame([$a['id'], $c['id'], $b['id']], $urut);

        $svc->geser($a['id'], 'naik'); // sudah paling atas → tidak berubah
        $this->assertSame($urut, array_column($svc->daftar([])['items'], 'id'));

        $file = $this->db->table('banner')->where('id_banner', $b['id'])->get()->getRowArray()['file'];
        $svc->hapus($b['id']);
        $this->assertFileDoesNotExist($this->akar . '/' . $file);
        $this->assertSame(0, $this->db->table('user_banner')->where('id_banner', $b['id'])->countAllResults());
        $this->assertSame(2, $svc->daftar([])['total']);

        // Q7-16: audit hapus menyimpan snapshot baris SEBELUM dihapus (termasuk kunci berkas).
        $audit = $this->db->table('audit_logs')->where(['entity' => 'banner', 'entity_id' => (string) $b['id'], 'event' => 'delete'])->get()->getRowArray();
        $this->assertIsArray($audit);
        $sebelum = json_decode((string) $audit['before_json'], true);
        $this->assertSame($file, $sebelum['file']);
        $this->assertSame('2026-11-30', $sebelum['tgl_akhir']);

        $this->expectException(NotFoundException::class);
        $svc->hapus($b['id']);
    }

    public function testAktifMenurutNipPeriodeWibDanUrutan(): void
    {
        [, $p1, $p2] = $this->satkerDenganDuaPegawai();
        $now         = '2026-11-15 10:30:00';
        $svc         = $this->service(JenisGambar::Popup, $now);

        $a = $svc->buat($this->input(['target' => ['pegawai' => [$p1]]]), $this->gambar(300, 200), 1);
        $b = $svc->buat($this->input(['target' => ['pegawai' => [$p1, $p2]], 'tgl_mulai' => '2026-11-15 10:30', 'tgl_akhir' => '2026-11-15 10:30']), $this->gambar(300, 200), 1);
        $svc->buat($this->input(['target' => ['pegawai' => [$p1]], 'tgl_mulai' => '2026-11-15 10:31', 'tgl_akhir' => '2026-11-16 00:00']), $this->gambar(300, 200), 1); // belum mulai
        $svc->buat($this->input(['target' => ['pegawai' => [$p1]], 'tgl_mulai' => '2026-11-01 00:00', 'tgl_akhir' => '2026-11-15 10:29']), $this->gambar(300, 200), 1); // sudah lewat

        $this->assertSame([$a['id'], $b['id']], array_column($svc->aktif($p1), 'id'));
        $this->assertSame([$b['id']], array_column($svc->aktif($p2), 'id'));
        $this->assertSame([], $svc->aktif(null), 'role tanpa NIP (role 1) tidak punya popup');
        $this->assertSame(['id', 'url', 'gambar_url', 'keterangan'], array_keys($svc->aktif($p2)[0]));
    }

    public function testBannerAktifHariIniWib(): void
    {
        [, $p1] = $this->satkerDenganDuaPegawai();
        $svc    = $this->service(JenisGambar::Banner, '2026-11-30 23:59:00');
        $in     = ['target' => ['pegawai' => [$p1]], 'tgl_mulai' => '2026-11-01', 'tgl_akhir' => '2026-11-30'];
        $svc->buat($in, $this->gambar(1600, 400), 1);

        $this->assertCount(1, $svc->aktif($p1), 'hari terakhir masih berlaku');
        $this->assertCount(0, $this->service(JenisGambar::Banner, '2026-12-01 00:00:00')->aktif($p1));
    }

    public function testAksesGambarHanyaRole1AtauPenerima(): void
    {
        [, $p1, $p2] = $this->satkerDenganDuaPegawai();
        $svc         = $this->service(JenisGambar::Popup);
        $r           = $svc->buat($this->input(['target' => ['pegawai' => [$p1]]]), $this->gambar(300, 200), 1);

        $this->assertSame('image/png', $svc->bacaGambar($r['id'], Role::SUPER_ADMIN, null)['mime']);
        $this->assertSame('image/png', $svc->bacaGambar($r['id'], Role::PEGAWAI, $p1)['mime']);

        $this->expectException(NotFoundException::class);
        $svc->bacaGambar($r['id'], Role::PEGAWAI, $p2);
    }

    public function testPenerimaBerisiNipDanNama(): void
    {
        [, $p1, $p2] = $this->satkerDenganDuaPegawai();
        $svc         = $this->service(JenisGambar::Popup);
        $r           = $svc->buat($this->input(['target' => ['pegawai' => [$p1, $p2]]]), $this->gambar(300, 200), 1);

        $hasil = $svc->penerima($r['id'], []);

        $this->assertSame(2, $hasil['total']);
        $this->assertEqualsCanonicalizing([$p1, $p2], array_column($hasil['items'], 'nip'));
        $this->assertStringContainsString('Pegawai Fixture', $hasil['items'][0]['nama']);
    }

    public function testTargetMengembalikanLabelPegawai(): void
    {
        [, $p1, $p2] = $this->satkerDenganDuaPegawai();
        $svc         = $this->service(JenisGambar::Popup);
        $r           = $svc->buat($this->input(['target' => ['unit_satker' => ['0'], 'pegawai' => [$p1], 'kecuali' => [$p2]]]), $this->gambar(300, 200), 1);

        $t = $svc->target($r['id']);

        $this->assertSame(['0'], $t['unit_satker']);
        $this->assertSame([$p1], $t['pegawai']);
        $this->assertSame([$p2], $t['kecuali']);
        $this->assertEqualsCanonicalizing([$p1, $p2], array_map('strval', array_keys($t['label_pegawai'])));
        $this->assertStringStartsWith($p1 . ' - ', $t['label_pegawai'][$p1]);
    }

    public function testOpsiTargetUnitSatker(): void
    {
        $unit   = $this->buatUnit(['unit' => 'Unit Uji Opsi']);
        $satker = $this->buatSatker($unit, ['satker' => 'Satker Uji Opsi']);
        $svc    = $this->service(JenisGambar::Popup);

        // Kunci numerik-string menjadi int di array PHP → cari lewat nilai, bukan kunci.
        $label = static function (array $opsi, string $nilai): ?string {
            foreach ($opsi as $o) {
                if ($o['value'] === $nilai) {
                    return $o['label'];
                }
            }

            return null;
        };

        $unitSatker = $svc->opsiUnitSatker();
        $this->assertSame('Seluruh kementerian', $label($unitSatker, '0'));
        $this->assertSame('Unit Uji Opsi', $label($unitSatker, (string) $unit));
        $this->assertSame('Satker Uji Opsi', $label($unitSatker, "sat_{$satker}"));
    }

    // ------------------------------------------------------------------

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function satkerDenganDuaPegawai(): array
    {
        $satker = $this->buatSatker($this->buatUnit());

        return [$satker, $this->buatPegawaiDiSatker($satker), $this->buatPegawaiDiSatker($satker)];
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    private function input(array $o = []): array
    {
        return $o + ['tgl_mulai' => '2026-11-01 08:00', 'tgl_akhir' => '2026-11-30 17:00', 'url' => 'https://contoh.test/a', 'keterangan' => 'Uji'];
    }

    public function testUrlMaksimal255Karakter(): void
    {
        $svc = $this->service(JenisGambar::Popup);
        $in  = $this->input(['target' => ['unit_satker' => ['0']]]);

        try {
            $svc->buat(['url' => 'https://contoh.test/' . str_repeat('a', 240)] + $in, $this->gambar(300, 200), 1);
            $this->fail('URL > 255 karakter harus ditolak (VARCHAR(255), DBV-032)');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('url', $e->getErrors() ?? []);
        }
    }

    /**
     * CR-119 (QA T-07): token unit/satker pada masukan API harus ada dan aktif — popup & banner, buat & ubah. 422 di field `target`
     * dengan pesan setara berita; tanpa baris baru, tanpa berkas tersisa, target tersimpan tidak berubah. Pegawai eksplisit
     * tetap tanpa filter status ([K] PRD v2.1 §11-6).
     */
    public function testTargetUnitSatkerNonaktifAtauTidakAdaDitolak(): void
    {
        $unitAktif    = $this->buatUnit();
        $satkerAktif  = $this->buatSatker($unitAktif);
        $unitNonaktif = $this->buatUnit();
        $satkerNon    = $this->buatSatker($unitAktif);
        $this->db->table('unit')->where('id_unit', $unitNonaktif)->update(['status' => 2]);
        $this->db->table('satker')->where('id_satker', $satkerNon)->update(['status' => 2]);

        $salah = [
            'unit nonaktif'    => [(string) $unitNonaktif, 'Unit target tidak ditemukan atau tidak aktif.'],
            'unit tidak ada'   => ['99999', 'Unit target tidak ditemukan atau tidak aktif.'],
            'satker nonaktif'  => ["sat_{$satkerNon}", 'Satker target tidak ditemukan atau tidak aktif.'],
            'satker tidak ada' => ['sat_99999', 'Satker target tidak ditemukan atau tidak aktif.'],
        ];

        foreach ([[JenisGambar::Popup, 'popup', 300, 200], [JenisGambar::Banner, 'banner', 1600, 400]] as [$jenis, $tabel, $w, $h]) {
            $svc   = $this->service($jenis);
            $tgl   = $jenis === JenisGambar::Popup ? ['tgl_mulai' => '2026-11-01 08:00', 'tgl_akhir' => '2026-11-30 17:00'] : ['tgl_mulai' => '2026-11-01', 'tgl_akhir' => '2026-11-30'];
            $baik  = $svc->buat($tgl + ['target' => ['unit_satker' => [(string) $unitAktif, "sat_{$satkerAktif}"]]], $this->gambar($w, $h), 1);
            $awal  = $this->db->table($tabel)->where("id_{$tabel}", $baik['id'])->get()->getRowArray();
            $files = glob($this->akar . "/{$tabel}/*") ?: [];
            $this->assertSame(1, $this->db->table($tabel)->countAllResults(), 'target aktif diterima');
            $this->assertCount(1, $files);

            $campur = ['campur aktif + nonaktif' => [(string) $unitNonaktif, 'Unit target tidak ditemukan atau tidak aktif.']];

            foreach ($salah + $campur as $nama => [$token, $pesan]) {
                $target = $nama === 'campur aktif + nonaktif'
                    ? ['unit_satker' => [(string) $unitAktif, $token]]
                    : ['unit_satker' => [$token]];

                foreach (['buat', 'ubah'] as $aksi) {
                    try {
                        $aksi === 'buat'
                            ? $svc->buat($tgl + ['target' => $target], $this->gambar($w, $h), 1)
                            : $svc->ubah($baik['id'], $tgl + ['target' => $target], $this->gambar($w, $h), 1);
                        $this->fail("{$jenis->value} {$aksi} harus 422 untuk {$nama}");
                    } catch (ValidationException $e) {
                        $this->assertSame([$pesan], $e->getErrors()['target'] ?? null, "{$jenis->value} {$aksi} {$nama}");
                    }
                }
            }

            $this->assertSame(1, $this->db->table($tabel)->countAllResults(), 'tidak ada baris baru');
            $this->assertCount(1, glob($this->akar . "/{$tabel}/*") ?: [], 'tidak ada berkas tersisa');
            $this->assertSame($awal, $this->db->table($tabel)->where("id_{$tabel}", $baik['id'])->get()->getRowArray(), 'ubah yang ditolak tidak mengubah baris');

            // token "0" (seluruh kementerian) dan pegawai eksplisit tidak diperiksa status unit/satker
            $this->assertSame($baik['id'], $svc->ubah($baik['id'], $tgl + ['target' => ['unit_satker' => ['0']]], null, 1)['id']);
        }
    }

    private function service(JenisGambar $jenis, string $sekarang = '2026-11-15 10:00:00'): KontenGambarService
    {
        $c                  = new Konten();
        $c->akarPenyimpanan = $this->akar;

        return new KontenGambarService(
            $jenis,
            $this->db,
            new PenyimpananGambar($c),
            static fn (): DateTimeImmutable => new DateTimeImmutable($sekarang, new DateTimeZone('Asia/Jakarta')),
        );
    }

    private function gambar(int $w, int $h): UploadedFile
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 160));
        $f = tempnam(sys_get_temp_dir(), 'gbr') . '.png';
        imagepng($im, $f);
        $this->tmp[] = $f;

        return new UploadedFile($f, 'uji.png', 'image/png', (int) filesize($f), UPLOAD_ERR_OK);
    }

    private function teks(): UploadedFile
    {
        $f = tempnam(sys_get_temp_dir(), 'txt') . '.png';
        file_put_contents($f, 'bukan gambar');
        $this->tmp[] = $f;

        return new UploadedFile($f, 'uji.png', 'image/png', (int) filesize($f), UPLOAD_ERR_OK);
    }
}
