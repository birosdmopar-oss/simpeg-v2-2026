<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Libraries\Presensi\Slip\KodeGalatSlip;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\SlipUjiTrait;

/**
 * PRD 5A §7.3.4–7.3.5 (AC-D11.2, .3, .7, .8): kelola admin Slip Gaji — monitoring (periode wajib, ringkasan, filter
 * status & cari, role 3 dipaksa satker akun), detail (rincian + aksi, tanpa ubah status), koreksi 28 kolom wajib (negatif
 * → `nominal_negatif`, audit before/after, status/token/uang makan tetap), buka ulang (hanya bila dibuka; riwayat token &
 * snapshot uang makan dipertahankan; token baru saat dibuka lagi), PDF admin kapan saja tanpa mengubah `viewed_at` dan
 * tanpa QR bila tidak ada token aktif; role 3 di luar cakupan → 403, tanpa satker → 403; role lain → 403.
 *
 * @internal
 */
final class SlipAdminTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SlipUjiTrait;

    /** @var array{nip: string, id_satker: int} */
    private array $a;

    /** @var array{nip: string, id_satker: int} */
    private array $b;

    private int $idA;

    private int $idB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSlip();
        $this->a   = $this->pegawaiSlip('Ani Admin');
        $this->b   = $this->pegawaiSlip('Bayu Lain');
        $this->idA = $this->slip($this->a['nip'], 2026, 9);
        $this->idB = $this->slip($this->b['nip'], 2026, 9, ['viewed_at' => '2026-10-05 01:00:00', 'viewed_ip' => '203.0.113.9']);
        $this->slip($this->a['nip'], 2026, 8);
    }

    protected function tearDown(): void
    {
        $this->bersihkanSlip();
        parent::tearDown();
    }

    public function testMonitoring(): void
    {
        $admin = $this->slipAdmin();
        $data  = $admin->daftar($this->authAdminSlip(), ['periode' => '2026-09']);

        $this->assertSame(['jumlah' => 2, 'dibuka' => 1, 'belum_dibuka' => 1], $data['ringkasan']);
        $this->assertSame([$this->idA, $this->idB], array_column($data['items'], 'id'), 'urut nama');
        $this->assertSame(['2026-09', '2026-08'], $data['periode_tersedia']);
        $this->assertSame(['lihat' => true, 'cetak' => true, 'koreksi' => true, 'buka_ulang' => true], $data['items'][1]['aksi']);
        $this->assertSame(['203.0.113.9', '5 Oktober 2026, 08:00 WIB'], [$data['items'][1]['viewed_ip'], $data['items'][1]['viewed_at_wib']]);
        $this->assertSame('Dr. Ani Admin, M.Si.', $data['items'][0]['nama']);

        $this->assertSame([$this->idB], array_column($admin->daftar($this->authAdminSlip(), ['periode' => '2026-09', 'status_view' => 'dibuka'])['items'], 'id'));
        $this->assertSame([$this->idA], array_column($admin->daftar($this->authAdminSlip(), ['periode' => '2026-09', 'status_view' => 'belum'])['items'], 'id'));
        $this->assertSame([$this->idB], array_column($admin->daftar($this->authAdminSlip(), ['periode' => '2026-09', 'search' => 'Bayu'])['items'], 'id'));

        $satker3 = $admin->daftar($this->authAdminSlip(Role::ADMIN_SATKER, (string) $this->a['id_satker']), ['periode' => '2026-09', 'unit_satker' => (string) $this->b['id_satker']]);
        $this->assertSame([$this->idA], array_column($satker3['items'], 'id'), 'role 3 dipaksa satker akun');

        try {
            $admin->daftar($this->authAdminSlip(), []);
            $this->fail('periode wajib');
        } catch (ApiException $e) {
            $this->assertSame([422, 'periode_wajib'], [$e->getStatusCode(), $e->getKode()]);
        }
    }

    public function testDetailDanCakupan(): void
    {
        $admin = $this->slipAdmin();
        $data  = $admin->detail($this->authAdminSlip(), $this->idB);

        $this->assertSame([true, '3000000.00', '4600000.00'], [$data['dibuka'], $data['rincian']['gjpokok'], $data['ringkasan']['total_penghasilan']]);
        $this->assertTrue($data['aksi']['buka_ulang']);
        $this->assertSame(['nip', 'nama', 'pangkat', 'jabatan', 'unit', 'satker'], array_keys($data['pegawai']));
        $this->assertSame($data['nip'], $data['pegawai']['nip']);
        $this->assertSame($data['nama'], $data['pegawai']['nama']);
        $this->assertFalse($admin->detail($this->authAdminSlip(), $this->idA)['aksi']['buka_ulang']);
        $this->seeInDatabase('gaji_pegawai', ['id' => $this->idA, 'viewed_at' => null]);

        $this->assertSame($this->idA, $admin->detail($this->authAdminSlip(Role::ADMIN_SATKER, (string) $this->a['id_satker']), $this->idA)['id']);

        foreach ([
            [fn () => $admin->detail($this->authAdminSlip(Role::ADMIN_SATKER, (string) $this->a['id_satker']), $this->idB), ForbiddenException::class],
            [fn () => $admin->detail($this->authAdminSlip(Role::ADMIN_SATKER, ''), $this->idA), ForbiddenException::class],
            [fn () => $admin->detail($this->authAdminSlip(Role::ADMIN_VIEW_ESELON1), $this->idA), ForbiddenException::class],
            [fn () => $admin->detail($this->authAdminSlip(), 999_999), NotFoundException::class],
        ] as [$aksi, $kelas]) {
            try {
                $aksi();
                $this->fail($kelas);
            } catch (ApiException $e) {
                $this->assertInstanceOf($kelas, $e);
            }
        }
    }

    public function testKoreksi(): void
    {
        $admin = $this->slipAdmin();
        $isi   = array_fill_keys(KolomGaji::semuaField(), '0.00');
        $isi   = ['gjpokok' => '3000000.00', 'bersih_gpp' => '2700000.00', 'kotor' => '2000000.00', 'bersih_tk' => '1900000.00'] + $isi;

        try {
            $admin->koreksi($this->authAdminSlip(), $this->idB, ['gjpokok' => '-1'] + $isi);
            $this->fail('negatif');
        } catch (ApiException $e) {
            $this->assertSame([422, KodeGalatSlip::NOMINAL_NEGATIF], [$e->getStatusCode(), $e->getKode()]);
            $this->assertSame(['Nilai harus berupa angka non-negatif.'], $e->getErrors()['gjpokok'] ?? null);
        }

        try {
            $admin->koreksi($this->authAdminSlip(), $this->idB, ['gjpokok' => '3000000.00']);
            $this->fail('28 kolom wajib');
        } catch (ApiException $e) {
            $this->assertCount(27, (array) $e->getErrors());
        }

        $data = $admin->koreksi($this->authAdminSlip(), $this->idB, ['gjpokok' => '3100000.50', 'potlain' => 25000] + $isi);
        $this->assertSame(['3100000.50', '25000.00'], [$data['rincian']['gjpokok'], $data['rincian']['potlain']]);

        $row = $this->db->table('gaji_pegawai')->where('id', $this->idB)->get()->getRowArray();
        $this->assertSame(['2026-10-05 01:00:00', '203.0.113.9', '0.00', '8101'], [$row['viewed_at'], $row['viewed_ip'], $row['uang_makan'], (string) $row['updated_by']], 'status & uang makan tetap');

        $audit = $this->db->table('audit_logs')->where(['entity' => 'gaji_pegawai', 'entity_id' => (string) $this->idB, 'event' => 'update'])->get()->getResultArray();
        $this->assertCount(1, $audit);
        $this->assertSame('3000000.00', json_decode((string) $audit[0]['before_json'], true)['gjpokok'] ?? null);
        $this->assertSame('3100000.50', json_decode((string) $audit[0]['after_json'], true)['gjpokok'] ?? null);
        $this->assertStringNotContainsString('203.0.113.9', (string) $audit[0]['before_json'] . (string) $audit[0]['after_json'], 'IP dimasking');
    }

    public function testBukaUlangDanPdfAdmin(): void
    {
        $admin = $this->slipAdmin();
        $auth  = $this->authPegawaiSlip($this->a['nip']);
        $this->slipPegawai()->buka($auth, '2026-09', '203.0.113.30');
        $token1 = (string) $this->db->table('gaji_pegawai')->select('qr_token')->where('id', $this->idA)->get()->getRowArray()['qr_token'];

        $pdf = $admin->pdf($this->authAdminSlip(), $this->idA);
        $this->assertStringStartsWith('%PDF-', $pdf['isi']);
        $this->assertSame('Slip_Gaji_ANI_ADMIN_September_2026.pdf', $pdf['nama']);

        $data = $admin->bukaUlang($this->authAdminSlip(), $this->idA);
        $this->assertFalse($data['dibuka']);
        $this->seeInDatabase('gaji_pegawai', ['id' => $this->idA, 'viewed_at' => null, 'viewed_ip' => null, 'qr_token' => null, 'qr_token_enkripsi' => null]);
        $this->assertSame(1, $this->db->table('gaji_pegawai_qr_history')->where('id_gaji_pegawai', $this->idA)->countAllResults(), 'riwayat token dipertahankan');

        try {
            $admin->bukaUlang($this->authAdminSlip(), $this->idA);
            $this->fail('belum dibuka');
        } catch (ApiException $e) {
            $this->assertSame([422, KodeGalatSlip::SLIP_BELUM_DIBUKA], [$e->getStatusCode(), $e->getKode()]);
        }

        // PDF admin tanpa token aktif: tetap terbit, tanpa QR, status tidak berubah.
        $this->assertStringStartsWith('%PDF-', $admin->pdf($this->authAdminSlip(), $this->idA)['isi']);
        $this->seeInDatabase('gaji_pegawai', ['id' => $this->idA, 'viewed_at' => null]);

        $this->slipPegawai('2026-10-27 11:00:00')->buka($auth, '2026-09', '203.0.113.31');
        $token2 = (string) $this->db->table('gaji_pegawai')->select('qr_token')->where('id', $this->idA)->get()->getRowArray()['qr_token'];
        $this->assertNotSame($token1, $token2, 'token baru setelah buka ulang');
        $this->assertSame(2, $this->db->table('gaji_pegawai_qr_history')->where('id_gaji_pegawai', $this->idA)->countAllResults());
        $this->assertSame(1, $this->db->table('audit_logs')->where(['entity' => 'gaji_pegawai', 'entity_id' => (string) $this->idA])->like('after_json', 'buka_ulang')->countAllResults());
    }
}
