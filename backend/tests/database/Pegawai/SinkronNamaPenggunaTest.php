<?php

declare(strict_types=1);

namespace Tests\Database\Pegawai;

use App\Commands\PenggunaSinkronNama;
use App\Constants\AkunSistem;
use App\Constants\Role;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Kepegawaian\Pegawai\SinkronNamaPengguna;
use App\Libraries\Kepegawaian\Pegawai\SinkronNamaSibuk;
use App\Models\AuditLogModel;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Config\Services;
use RuntimeException;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-062 (F6-21 bersyarat) — `pengguna:sinkron-nama` (padanan legacy `Cron.php:2818` `sync_nama_pengguna`): cakupan
 * (role 2, status 1, ber-NIP, pegawai status 1, akun sistem dan soft-delete dikecualikan), nama bergelar, hanya update bila
 * berbeda, idempoten, audit, dry-run, pemotongan 150, kegagalan sebagian, kunci, dan keluaran tanpa nama/NIP.
 *
 * @internal
 */
final class SinkronNamaPenggunaTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use StreamFilterTrait;

    protected $seed = MasterDataSeeder::class;

    private int $pelaku;

    protected function setUp(): void
    {
        parent::setUp();
        (new AkunSistemIntegrasi($this->db))->siapkan();
        $this->pelaku = (new AkunSistemIntegrasi($this->db))->id();
    }

    private function service(?AuditLogModel $audit = null): SinkronNamaPengguna
    {
        return new SinkronNamaPengguna($this->db, $audit ?? new AuditLogModel());
    }

    /**
     * @param array<string, mixed> $pegawai
     * @param array<string, mixed> $akun
     *
     * @return array{0: string, 1: int} NIP dan id_pengguna
     */
    private function akun(array $pegawai = [], array $akun = [], int $role = Role::PEGAWAI): array
    {
        $nip = $this->buatPegawai(array_merge(['nama' => 'Budi Santoso', 'glr_awal' => 'Dr.', 'glr_akhir' => 'M.Si.'], $pegawai));

        return [$nip, $this->buatAkunUntuk($nip, $role, array_merge(['name' => 'Nama Lama'], $akun))];
    }

    private function namaAkun(int $id): ?string
    {
        $r = $this->db->table('pengguna')->select('name')->where('id_pengguna', $id)->get()->getRowArray();

        return $r['name'] === null ? null : (string) $r['name'];
    }

    public function testNamaBergelarMengikutiLegacyDanHanyaUpdateBilaBerbeda(): void
    {
        [, $beda]  = $this->akun();
        [, $sama]  = $this->akun(['nama' => 'Siti Aminah', 'glr_awal' => null, 'glr_akhir' => null], ['name' => 'Siti Aminah']);
        [, $akhir] = $this->akun(['nama' => 'Andi', 'glr_awal' => null, 'glr_akhir' => 'S.T.']);

        $hasil = $this->service()->jalankan(false, $this->pelaku);

        $this->assertSame('Dr. Budi Santoso, M.Si.', $this->namaAkun($beda));
        $this->assertSame('Siti Aminah', $this->namaAkun($sama));
        $this->assertSame('Andi, S.T.', $this->namaAkun($akhir));
        $this->assertSame(['diperiksa' => 3, 'berubah' => 2, 'sama' => 1, 'gagal' => 0], ['diperiksa' => $hasil['diperiksa'], 'berubah' => $hasil['berubah'], 'sama' => $hasil['sama'], 'gagal' => $hasil['gagal']]);
    }

    public function testIdempotenDanAuditPerBaris(): void
    {
        [, $id] = $this->akun();

        $this->service()->jalankan(false, $this->pelaku);
        $kedua = $this->service()->jalankan(false, $this->pelaku);

        $this->assertSame(0, $kedua['berubah']);
        $this->assertSame(1, $kedua['sama']);

        $baris = $this->db->table('audit_logs')->where('entity', 'pengguna')->where('entity_id', (string) $id)->get()->getResultArray();
        $this->assertCount(1, $baris, 'run kedua tidak menambah audit');
        $this->assertSame($this->pelaku, (int) $baris[0]['id_pengguna_actor']);
        $this->assertEquals(['name' => 'Nama Lama'], json_decode((string) $baris[0]['before_json'], true));
        $this->assertEquals(['name' => 'Dr. Budi Santoso, M.Si.', 'jenis_audit' => 'pengguna.sinkron_nama'], json_decode((string) $baris[0]['after_json'], true));
    }

    public function testCakupanHanyaRole2Status1BerNipPegawaiAktifDanBukanAkunSistem(): void
    {
        [, $admin]        = $this->akun([], [], Role::ADMIN_SATKER);
        [, $ptt]          = $this->akun([], [], Role::PTT);
        [, $nonaktif]     = $this->akun([], ['status' => '0']);
        [, $terhapus]     = $this->akun([], ['deleted_at' => '2026-01-01 00:00:00']);
        [, $pegawaiOff]   = $this->akun(['status' => 2]);
        [, $dalamCakupan] = $this->akun();

        $sistem = $this->db->table('pengguna')->where('username', AkunSistem::USERNAME)->get()->getRowArray();

        $hasil = $this->service()->jalankan(false, $this->pelaku);

        foreach ([$admin, $ptt, $nonaktif, $terhapus, $pegawaiOff] as $id) {
            $this->assertSame('Nama Lama', $this->namaAkun($id), "akun {$id} di luar cakupan");
        }

        $this->assertSame('Dr. Budi Santoso, M.Si.', $this->namaAkun($dalamCakupan));
        $this->assertSame(1, $hasil['diperiksa']);
        $this->assertSame($sistem['name'], $this->namaAkun((int) $sistem['id_pengguna']), 'akun sistem tidak diubah');
    }

    public function testDryRunTidakMenulisDanTidakButuhPelaku(): void
    {
        [, $id] = $this->akun();

        $hasil = $this->service()->jalankan(true, null);

        $this->assertTrue($hasil['dry_run']);
        $this->assertSame(1, $hasil['berubah']);
        $this->assertSame('Nama Lama', $this->namaAkun($id));
        $this->assertSame(0, $this->db->table('audit_logs')->where('entity', 'pengguna')->countAllResults());
    }

    public function testRunNyataTanpaPelakuDitolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->jalankan(false, null);
    }

    public function testNamaTerlaluPanjangDipotongKe150DanDihitung(): void
    {
        [, $id] = $this->akun(['nama' => str_repeat('A', 100), 'glr_awal' => str_repeat('B', 40), 'glr_akhir' => str_repeat('C', 40)]);

        $hasil = $this->service()->jalankan(false, $this->pelaku);

        $this->assertSame(SinkronNamaPengguna::PANJANG_MAKS, mb_strlen((string) $this->namaAkun($id)));
        $this->assertSame(1, $hasil['info']['dipotong']);
        $this->assertSame(0, $this->service()->jalankan(false, $this->pelaku)['berubah'], 'stabil setelah dipotong');
    }

    public function testKegagalanSatuAkunDihitungDanTidakMenghentikanYangLain(): void
    {
        [, $gagal] = $this->akun();
        [, $baik]  = $this->akun();

        $audit = new class ($gagal) extends AuditLogModel {
            public function __construct(private readonly int $idGagal)
            {
                parent::__construct();
            }

            public function record(string $entity, string|int $entityId, string $event, ?array $before, ?array $after, ?int $actorId = null, ?string $actorNip = null): int
            {
                if ((int) $entityId === $this->idGagal) {
                    throw new RuntimeException('audit gagal (uji)');
                }

                return parent::record($entity, $entityId, $event, $before, $after, $actorId, $actorNip);
            }
        };

        $hasil = $this->service($audit)->jalankan(false, $this->pelaku);

        $this->assertSame(1, $hasil['gagal']);
        $this->assertSame(1, $hasil['berubah']);
        $this->assertSame('Nama Lama', $this->namaAkun($gagal), 'transaksi akun yang gagal di-rollback');
        $this->assertSame('Dr. Budi Santoso, M.Si.', $this->namaAkun($baik));
    }

    public function testRunNyataKeduaDitolakSelamaKunciDipegang(): void
    {
        $lain  = Database::connect($this->DBGroup, false);
        $kunci = SinkronNamaPengguna::namaKunci($this->db);
        $lain->query('SELECT GET_LOCK(?, 0)', [$kunci]);

        try {
            $this->expectException(SinkronNamaSibuk::class);
            $this->service()->jalankan(false, $this->pelaku);
        } finally {
            $lain->query('SELECT RELEASE_LOCK(?)', [$kunci]);
        }
    }

    /**
     * @param array<int|string, string|null> $params
     *
     * @return array{0: int, 1: string}
     */
    private function jalankanCommand(array $params): array
    {
        $this->resetStreamFilterBuffer();
        $kode = (new PenggunaSinkronNama(Services::logger(), Services::commands()))->run($params);

        return [$kode, $this->getStreamFilterBuffer()];
    }

    public function testCommandKodeKeluarDanKeluaranTanpaNamaAtauNip(): void
    {
        [$nip, $id] = $this->akun();

        $this->assertSame(EXIT_USER_INPUT, $this->jalankanCommand(['lebih'])[0]);
        $this->assertSame(EXIT_USER_INPUT, $this->jalankanCommand(['tanggal' => '2026-01-01'])[0]);

        [$kode, $keluaran] = $this->jalankanCommand(['dry-run' => null]);
        $this->assertSame(EXIT_SUCCESS, $kode);
        $this->assertSame('Nama Lama', $this->namaAkun($id));
        $this->assertStringContainsString('[dry-run]', $keluaran);

        [$kode, $keluaran] = $this->jalankanCommand([]);
        $this->assertSame(EXIT_SUCCESS, $kode);
        $this->assertSame('Dr. Budi Santoso, M.Si.', $this->namaAkun($id));
        $this->assertStringContainsString('berubah 1', $keluaran);
        $this->assertStringNotContainsString($nip, $keluaran);
        $this->assertStringNotContainsString('Budi', $keluaran);
    }

    public function testCommandTanpaAkunSistemGagalSebelumMenulis(): void
    {
        [, $id] = $this->akun();
        $this->db->table('pengguna')->where('username', AkunSistem::USERNAME)->delete();

        [$kode, $keluaran] = $this->jalankanCommand([]);

        $this->assertSame(EXIT_ERROR, $kode);
        $this->assertStringContainsString('GAGAL', $keluaran);
        $this->assertSame('Nama Lama', $this->namaAkun($id));
        $this->assertSame(EXIT_SUCCESS, $this->jalankanCommand(['dry-run' => null])[0]);
    }
}
