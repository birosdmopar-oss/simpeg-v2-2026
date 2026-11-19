<?php

declare(strict_types=1);

namespace Tests\Layanan\Cuti;

use App\Libraries\Layanan\Cuti\ApproverTerjangkau;
use App\Libraries\Layanan\Cuti\StatusCuti;
use App\Libraries\Layanan\Cuti\TteAtasNama;
use CodeIgniter\Events\Events;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\CutiUjiTrait;

/**
 * CR-160 (B-05) — daftar cuti admin dan pengingat membaca keterjangkauan approver dan "keputusan final atas nama" sekali
 * per halaman. Penjaga: item daftar wajib sama persis dengan item yang dibentuk per baris tanpa prefetch (jalur lama, masih
 * dipakai detail), jumlah kueri lookup tidak bertambah mengikuti jumlah baris, dan versi banyak dari kedua lookup
 * mengembalikan hasil yang sama dengan versi tunggalnya.
 *
 * @internal
 */
final class CutiDaftarPrefetchTest extends DatabaseTestCase
{
    use CutiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    public function testDaftarAdminSamaDenganItemPerBarisDanLookupSekaliPerHalaman(): void
    {
        $s     = $this->skenario();
        $admin = $s['admin']['auth'];
        $svc   = $this->cutiService();

        [$hasil, $kueri] = $this->rekam(static fn (): array => $svc->daftar($admin, ['mode' => 'admin', 'per_page' => '100']));

        $this->assertSame(count($s['id']), $hasil['total']);
        $this->assertCount(1, $this->cocok($kueri, 'pengguna', '`nip` IN'), 'keterjangkauan approver: satu kueri per halaman');
        $this->assertCount(1, $this->cocok($kueri, 'audit_logs', 'entity_id'), 'audit atas nama: satu kueri per halaman');
        $this->assertSame([], $this->cocok($kueri, 'pengguna', "`nip` = '"), 'tanpa lookup akun per baris');

        $perId = array_column($hasil['items'], null, 'id_riwayat_cuti');

        foreach ($s['id'] as $nama => $id) {
            $harap = $svc->item($admin, (array) $svc->ambil($id), ['lampiran' => false, 'surat' => false, 'tte' => false], true);
            $this->assertSame($harap, $perId[$id], "item {$nama} sama dengan jalur per baris");
        }

        $this->assertTrue($perId[$s['id']['alRole4']]['perlu_atas_nama']);
        $this->assertTrue($perId[$s['id']['alRole4']]['aksi']['proses_atas_nama']);
        $this->assertTrue($perId[$s['id']['pybTanpaAkun']]['perlu_atas_nama']);
        $this->assertTrue($perId[$s['id']['eselonI']]['perlu_atas_nama']);
        $this->assertFalse($perId[$s['id']['terjangkau']]['perlu_atas_nama']);
        $this->assertFalse($perId[$s['id']['terjangkau']]['aksi']['proses_atas_nama']);
        $this->assertTrue($perId[$s['id']['finalAtasNama']]['aksi']['esign_atas_nama'], 'final diproses atas nama');
        $this->assertFalse($perId[$s['id']['finalBiasa']]['aksi']['esign_atas_nama'], 'final tanpa audit atas nama');
    }

    public function testPengingatSamaDenganItemPerBarisDanSuperAdminSatuLookup(): void
    {
        $al    = $this->buatPemohon(1);
        $ids   = [];
        $nipPm = $this->buatPejabat(2)['nip'];

        for ($i = 0; $i < 4; $i++) {
            $ids[] = $this->buatBarisCuti($nipPm, ['nip_atasan_langsung' => $al['nip'], 'tgl_mulai' => sprintf('2026-11-%02d', 10 + $i), 'tgl_akhir' => sprintf('2026-11-%02d', 10 + $i), 'lama_cuti' => 1]);
        }

        $svc = $this->cutiService();

        [$hasil, $kueri] = $this->rekam(static fn (): array => $svc->pengingat($al['auth']));

        $this->assertSame(4, $hasil['total']);
        $this->assertCount(1, $this->cocok($kueri, 'pengguna', '`nip` IN'));
        $this->assertSame([], $this->cocok($kueri, 'pengguna', "`nip` = '"));

        foreach ($hasil['items'] as $item) {
            $this->assertContains((int) $item['id_riwayat_cuti'], $ids);
            $this->assertSame($svc->item($al['auth'], (array) $svc->ambil((int) $item['id_riwayat_cuti']), ['lampiran' => false, 'surat' => false]), $item);
        }
    }

    public function testAlasanBanyakSamaDenganPerNip(): void
    {
        $nip = [
            'ok'       => $this->buatPejabat(2)['nip'],
            'role4'    => $this->buatPejabat(4)['nip'],
            'tanpa'    => $this->buatPejabat(null)['nip'],
            'nonaktif' => $this->buatPejabat(2)['nip'],
            'terhapus' => $this->buatPejabat(2)['nip'],
        ];
        $this->db->table('pengguna')->where('nip', $nip['nonaktif'])->update(['status' => '0']);
        $this->db->table('pengguna')->where('nip', $nip['terhapus'])->update(['deleted_at' => '2026-10-01 00:00:00']);

        $svc    = new ApproverTerjangkau($this->db);
        $daftar = [...array_values($nip), null, '  ', ' ' . $nip['ok'] . ' ', $nip['role4']];

        [$banyak, $kueri] = $this->rekam(static fn (): array => $svc->alasanBanyak($daftar));

        $this->assertCount(1, $kueri, 'satu kueri untuk semua NIP');
        $this->assertSame(ApproverTerjangkau::ALASAN_ESELON_I, $banyak['']);
        $this->assertCount(count($nip) + 1, $banyak, 'kunci = NIP unik yang sudah di-trim, ditambah kunci kosong');

        foreach ($nip as $nama => $n) {
            $this->assertSame($svc->alasanTakTerjangkau($n), $banyak[$n], $nama);
        }

        $this->assertNull($banyak[$nip['ok']]);
        $this->assertSame(ApproverTerjangkau::ALASAN_ROLE, $banyak[$nip['role4']]);
        $this->assertSame(ApproverTerjangkau::ALASAN_TANPA_AKUN, $banyak[$nip['tanpa']]);
        $this->assertSame(ApproverTerjangkau::ALASAN_NONAKTIF, $banyak[$nip['nonaktif']]);
        $this->assertSame(ApproverTerjangkau::ALASAN_TERHAPUS, $banyak[$nip['terhapus']]);
        $this->assertSame([], $svc->alasanBanyak([]));
    }

    public function testAlasanBanyakNipBedaHurufIkutCollationSepertiPerNip(): void
    {
        $this->db->table('pegawai')->insert(['nip' => 'ap01x', 'nama' => 'Approver', 'tgl_lahir' => '1980-01-01', 'jenis_kelamin' => 1, 'status' => 1]);
        $this->db->table('pengguna')->insert([
            'nip'    => 'ap01x', 'username' => 'ap01x', 'name' => 'Approver', 'password' => 'x', 'user_level' => 2,
            'status' => '1', 'created_at' => '2026-01-01 00:00:00',
        ]);

        $svc = new ApproverTerjangkau($this->db);

        $this->assertSame(['AP01X' => $svc->alasanTakTerjangkau('AP01X'), 'TIDAKADA' => ApproverTerjangkau::ALASAN_TANPA_AKUN], $svc->alasanBanyak(['AP01X', 'TIDAKADA']));
    }

    public function testFinalAtasNamaBanyakSamaDenganPerCuti(): void
    {
        $s    = $this->skenario();
        $svc  = $this->cutiService();
        $cuti = array_map(static fn (int $id): array => (array) $svc->ambil($id), array_values($s['id']));

        $db               = $this->db;
        [$banyak, $kueri] = $this->rekam(static fn (): array => TteAtasNama::finalAtasNamaBanyak($db, $cuti));

        $this->assertCount(1, $kueri);
        $this->assertCount(count($cuti), $banyak);

        foreach ($cuti as $c) {
            $this->assertSame(TteAtasNama::finalAtasNama($this->db, $c), $banyak[(int) $c['id_riwayat_cuti']], 'cuti ' . $c['id_riwayat_cuti']);
        }

        $this->assertTrue($banyak[$s['id']['finalAtasNama']]);
        $this->assertSame([], TteAtasNama::finalAtasNamaBanyak($this->db, []));
    }

    /**
     * Enam cuti: empat menunggu (AL role 4, PyB tanpa akun, eselon I, AL terjangkau) dan dua final (diproses atas nama
     * lewat alur persetujuan nyata; final biasa tanpa audit atas nama).
     *
     * @return array{admin: array<string, mixed>, id: array<string, int>}
     */
    private function skenario(): array
    {
        $admin = $this->buatPemohon(1);
        $al4   = $this->buatPejabat(4);
        $pyb4  = $this->buatPejabat(4);
        $p     = $this->buatPemohon(2, [], [], ['id_atasan_es_4' => $al4['id_jabatan']]);
        $this->aturanPyb($p['jabatan'], $pyb4['id_jabatan'], 'III/a');
        $this->buatSaldo($p['nip'], 2026);

        $final   = (int) $this->cutiService()->tambah($p['auth'], $this->isianCuti($al4['nip'], $pyb4['nip']), null)['id_riwayat_cuti'];
        $approve = $this->approvalService();
        $approve->process($admin['auth'], $final, ['aksi' => 'setujui', 'atas_nama' => true, 'reason_note' => 'AL tak terjangkau']);
        $approve->process($admin['auth'], $final, ['aksi' => 'setujui', 'atas_nama' => true, 'reason_note' => 'PyB tak terjangkau']);

        $nip = $this->buatPejabat(2)['nip'];
        $ok  = $this->buatPejabat(2)['nip'];

        return [
            'admin' => $admin,
            'id'    => [
                'finalAtasNama' => $final,
                'alRole4'       => $this->buatBarisCuti($nip, ['nip_atasan_langsung' => $al4['nip'], 'nip_yang_menyetujui' => $ok]),
                'pybTanpaAkun'  => $this->buatBarisCuti($nip, ['nip_yang_menyetujui' => $this->buatPejabat(null)['nip'], 'tgl_mulai' => '2026-11-09', 'tgl_akhir' => '2026-11-09']),
                'eselonI'       => $this->buatBarisCuti($nip, ['tgl_mulai' => '2026-11-10', 'tgl_akhir' => '2026-11-10']),
                'terjangkau'    => $this->buatBarisCuti($nip, ['nip_atasan_langsung' => $ok, 'tgl_mulai' => '2026-11-11', 'tgl_akhir' => '2026-11-11']),
                'finalBiasa'    => $this->buatBarisCuti($nip, ['nip_yang_menyetujui' => $ok, 'status' => StatusCuti::EFEK_FINAL[0], 'tgl_mulai' => '2026-11-12', 'tgl_akhir' => '2026-11-12']),
            ],
        ];
    }

    /**
     * @template T
     *
     * @param callable(): T $f
     *
     * @return array{0: T, 1: list<string>}
     */
    private function rekam(callable $f): array
    {
        $kueri  = [];
        $dengar = static function ($q) use (&$kueri): void {
            $kueri[] = (string) $q;
        };
        Events::on('DBQuery', $dengar);

        try {
            return [$f(), $kueri];
        } finally {
            Events::removeListener('DBQuery', $dengar);
        }
    }

    /**
     * @param list<string> $kueri
     *
     * @return list<string>
     */
    private function cocok(array $kueri, string $tabel, string $potongan): array
    {
        return array_values(array_filter($kueri, static fn (string $q): bool => str_contains($q, "`t_{$tabel}`") && str_contains($q, $potongan)));
    }
}
