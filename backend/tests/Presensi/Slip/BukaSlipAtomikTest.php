<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Exceptions\ApiException;
use App\Libraries\Presensi\Slip\KodeGalatSlip;
use App\Libraries\Presensi\Slip\TokenSlipService;
use App\Libraries\Presensi\Slip\UangMakanBelumTersedia;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use App\Libraries\Presensi\SlipGaji\TokenQr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\SlipUjiTrait;

/**
 * PRD 5A §7.3.3 (AC-D11.4, AC-D11.5, AC-D11.6): buka slip sekali & atomik — respons buka memuat rincian 28 kolom +
 * ringkasan + uang makan; daftar/status hanya metadata (sebelum & sesudah dibuka); buka kedua → 422 `slip_sudah_dibuka`;
 * token baru tiap buka, DB hanya menyimpan hash SHA-256 (+ terenkripsi, Q5A-01) — tidak ada kolom berisi token mentah;
 * satu baris riwayat per buka berisi snapshot verifikasi; audit `update` tanpa IP, token, dan nominal; PDF pegawai hanya
 * setelah dibuka.
 *
 * @internal
 */
final class BukaSlipAtomikTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SlipUjiTrait;

    private string $nip;

    private int $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSlip();
        $this->nip = $this->pegawaiSlip('Budi Santoso')['nip'];
        $this->id  = $this->slip($this->nip, 2026, 9, ['tjistri' => '300000.00']);
    }

    protected function tearDown(): void
    {
        $this->bersihkanSlip();
        parent::tearDown();
    }

    public function testBukaSekaliDanNominalHanyaDiRespons(): void
    {
        $auth  = $this->authPegawaiSlip($this->nip);
        $slip  = $this->slipPegawai('2026-10-27 09:00:00', new UangMakanBelumTersedia()); // sumber 5A: kolom uang makan tidak disentuh
        $tanpa = static fn (array $d): array => array_intersect_key($d, array_flip(['rincian', 'ringkasan', 'uang_makan', ...KolomGaji::semuaField()]));

        $status = $slip->status($auth, '2026-09');
        $this->assertSame([false, null], [$status['dibuka'], $status['viewed_at']]);
        $this->assertSame([], $tanpa($status), 'status tanpa nominal');

        try {
            $slip->pdf($auth, '2026-09');
            $this->fail('PDF sebelum dibuka');
        } catch (ApiException $e) {
            $this->assertSame([422, KodeGalatSlip::SLIP_BELUM_DIBUKA], [$e->getStatusCode(), $e->getKode()]);
        }

        $buka = $slip->buka($auth, '2026-09', '203.0.113.20');
        $this->assertSame(['2026-09', 'September 2026', '2026-10-27T02:00:00Z', '27 Oktober 2026, 09:00 WIB'], [$buka['periode'], $buka['label'], $buka['viewed_at'], $buka['viewed_at_wib']]);
        $this->assertSame('3000000.00', $buka['rincian']['gjpokok']);
        $this->assertCount(28, $buka['rincian']);
        $this->assertSame('4600000.00', $buka['ringkasan']['total_penghasilan'], 'bersih_gpp + bersih_tk + uang_makan');
        $this->assertSame(['nominal' => '0.00', 'hari' => null, 'periode_kehadiran' => null, 'tersedia' => false], $buka['uang_makan']);
        $this->assertArrayNotHasKey('token', $buka);
        $this->assertSame('Dr. Budi Santoso, M.Si.', $buka['pegawai']['nama'], 'identitas kartu slip [K _slip_content.php]');
        $this->assertSame([$this->nip, 'Analis Uji', 'Biro Uji'], [$buka['pegawai']['nip'], $buka['pegawai']['jabatan'], $buka['pegawai']['unit']]);
        $this->assertStringStartsWith('Satker Uji ', $buka['pegawai']['satker']);
        $this->assertArrayHasKey('pangkat', $buka['pegawai']);

        $status = $slip->status($auth, '2026-09');
        $this->assertTrue($status['dibuka']);
        $this->assertSame([], $tanpa($status), 'status sesudah dibuka tetap tanpa nominal');
        $this->assertSame([], $tanpa($slip->daftar($auth)['items'][0]));

        try {
            $slip->buka($auth, '2026-09', '203.0.113.20');
            $this->fail('buka kedua');
        } catch (ApiException $e) {
            $this->assertSame([422, KodeGalatSlip::SLIP_SUDAH_DIBUKA], [$e->getStatusCode(), $e->getKode()]);
        }

        $this->assertStringStartsWith('%PDF-', $slip->pdf($auth, '2026-09')['isi']);
    }

    public function testTokenHanyaHashDanRiwayat(): void
    {
        $this->slipPegawai()->buka($this->authPegawaiSlip($this->nip), '2026-09', '203.0.113.20');

        $row = $this->db->table('gaji_pegawai')->where('id', $this->id)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertTrue(TokenQr::valid((string) $row['qr_token']));
        $mentah = (new TokenSlipService())->mentah($row['qr_token_enkripsi']);
        $this->assertIsString($mentah);
        $this->assertNotSame($mentah, $row['qr_token'], 'kolom qr_token = hash, bukan token mentah');
        $this->assertSame(hash('sha256', $mentah), $row['qr_token']);
        $this->assertSame('203.0.113.20', $row['viewed_ip']);

        foreach ($row as $kolom => $nilai) {
            $this->assertNotSame($mentah, $nilai, "kolom {$kolom} tidak berisi token mentah");
        }

        $riwayat = $this->db->table('gaji_pegawai_qr_history')->where('id_gaji_pegawai', $this->id)->get()->getResultArray();
        $this->assertCount(1, $riwayat);
        $this->assertSame([$row['qr_token'], $row['viewed_at'], 'Satker Uji ' . $this->pegawaiSatker(), 'B*** S***'], [
            $riwayat[0]['qr_token'], $riwayat[0]['viewed_at'], $riwayat[0]['snapshot_satker'], $riwayat[0]['snapshot_nama'],
        ]);

        $audit = $this->db->table('audit_logs')->where(['entity' => 'gaji_pegawai', 'entity_id' => (string) $this->id, 'event' => 'update'])->get()->getResultArray();
        $this->assertCount(1, $audit);
        $json = (string) $audit[0]['before_json'] . (string) $audit[0]['after_json'];

        foreach (['203.0.113.20', $mentah, $row['qr_token'], '3000000'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $json, 'audit tanpa IP, token, nominal');
        }
    }

    private function pegawaiSatker(): string
    {
        return (string) $this->db->table('pegawai_mutasi_jabatan')->select('id_satker')->where('nip', $this->nip)->get()->getRowArray()['id_satker'];
    }
}
