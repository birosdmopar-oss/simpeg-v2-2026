<?php

declare(strict_types=1);

namespace Tests\Support\Kepegawaian;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Models\AuditLogModel;
use Config\Services;

/**
 * B-TC — test case generik riwayat (`docs/fase3/03-Kepegawaian.md` §B-TC; WS-1 M1 MAKE-004), lewat HTTP
 * `api/v1/pegawai/{nip}/riwayat/{jenis}`. Dipakai SETIAP jenis riwayat (B-07..B-17): test kelas jenis cukup
 * `use RiwayatBtcTrait`, memasang engine (RiwayatUjiTrait::pasangEngine() dengan Definisi jenisnya) di setUp(), dan
 * mengisi tiga method abstrak di bawah. Perilaku yang berbeda per jenis (alur admin vs self-service, izin per role)
 * dibaca dari Definisi, bukan ditulis ulang per test.
 *
 * Butir B-TC yang dicakup:
 *  1. Pegawai input mandiri → status 0, tidak aktif (snapshot tidak berubah) — atau 403 untuk jenis alur admin/tanpa
 *     izin tambah pegawai.
 *  2. Approval role 1/3 lewat `process` → status 1.
 *  3. Snapshot `pegawai_*` hanya berubah setelah approval final.
 *  4. Tolak → status 2, snapshot tidak tersentuh.
 *  5. Role 3 dibatasi lingkup (PegawaiScope) → 403 di luar lingkup.
 *  6. Role yang tidak berhak (izin Definisi) → 403.
 *  7. Audit log tercatat, termasuk tulisan snapshot.
 * QA Lapis 1 (grid, badge, modal) di luar cakupan build.
 *
 * Kelas pemakai wajib turunan Tests\Support\DatabaseTestCase dan memakai FeatureTestTrait, AuthTestTrait,
 * RiwayatUjiTrait.
 */
trait RiwayatBtcTrait
{
    /**
     * Definisi jenis yang diuji (yang terpasang di registry engine).
     */
    abstract protected function btcDefinisi(): RiwayatDefinisi;

    /**
     * Payload JSON tambah yang valid untuk pegawai $nip (kolom DDL).
     *
     * @return array<string, mixed>
     */
    abstract protected function btcPayload(string $nip): array;

    /**
     * Baris riwayat siap-INSERT (kolom DDL, tanpa nip/status) untuk menyiapkan pengajuan secara langsung.
     *
     * @return array<string, mixed>
     */
    abstract protected function btcBaris(string $nip): array;

    public function testBtcPegawaiInputMandiriStatus0(): void
    {
        $d               = $this->btcDefinisi();
        [$nip, , $akun]  = $this->aktor(Role::PEGAWAI);
        $snapshotSebelum = $this->btcSnapshot($nip);
        $result          = $this->asUser($akun)->withBodyFormat('json')->post($this->btcPath($nip), $this->btcPayload($nip));

        if ($d->alur() === AlurRiwayat::Admin || ! $d->boleh(AksiRiwayat::Tambah, Role::PEGAWAI)) {
            $result->assertStatus(403);

            return;
        }

        $result->assertStatus(201);
        $this->assertSame('0', (string) $this->json($result)['data'][$d->kolomStatus()]);
        $this->assertSame($snapshotSebelum, $this->btcSnapshot($nip), 'pengajuan tidak mengubah snapshot');
    }

    public function testBtcApprovalRole1DanRole3SnapshotSetelahApprovalFinal(): void
    {
        $d                 = $this->btcDefinisi();
        $idSatker          = $this->buatSatker();
        $nip               = $this->buatPegawaiDiSatker($idSatker);
        [, , $super]       = $this->aktor(Role::SUPER_ADMIN);
        [, , $adminSatker] = $this->aktor(Role::ADMIN_SATKER, [], $idSatker);

        foreach ([$super, $adminSatker] as $akun) {
            $this->assertTrue($d->boleh(AksiRiwayat::Proses, (int) $akun['user_level']), 'Matriks: role 1/3 memproses');

            $id = $this->sisipRiwayat($d, $nip, $this->btcBaris($nip) + [$d->kolomStatus() => 0]);
            $this->assertSame([], $this->btcSnapshotBerisi($nip, $id), 'sebelum approval final');

            $result = $this->asUser($akun)->withBodyFormat('json')->post($this->btcPath($nip, $id) . '/process', ['aksi' => 'setujui', 'reason_note' => '']);

            $result->assertStatus(200);
            $this->assertSame('1', (string) $this->json($result)['data'][$d->kolomStatus()]);

            if ($d->snapshot() !== []) {
                $this->assertNotSame([], $this->btcSnapshotBerisi($nip, $id), 'snapshot terisi setelah approval final');
            }
        }
    }

    public function testBtcTolakStatus2SnapshotTidakTersentuh(): void
    {
        $d           = $this->btcDefinisi();
        $nip         = $this->buatPegawai();
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        $id          = $this->sisipRiwayat($d, $nip, $this->btcBaris($nip) + [$d->kolomStatus() => 0]);
        $sebelum     = $this->btcSnapshot($nip);

        $this->asUser($super)->withBodyFormat('json')->post($this->btcPath($nip, $id) . '/process', ['aksi' => 'tolak'])->assertStatus(422);
        $result = $this->asUser($super)->withBodyFormat('json')->post($this->btcPath($nip, $id) . '/process', ['aksi' => 'tolak', 'reason_note' => 'Berkas tidak sesuai']);

        $result->assertStatus(200);
        $this->assertSame('2', (string) $this->json($result)['data'][$d->kolomStatus()]);
        $this->assertSame($sebelum, $this->btcSnapshot($nip));
    }

    public function testBtcRole3DibatasiLingkup(): void
    {
        $idSatker          = $this->buatSatker();
        $dalam             = $this->buatPegawaiDiSatker($idSatker);
        $luar              = $this->buatPegawaiDiSatker($this->buatSatker());
        [, , $adminSatker] = $this->aktor(Role::ADMIN_SATKER, [], $idSatker);
        Services::injectMock('pegawaiScope', $this->btcScopeRole3([$dalam]));

        $this->asUser($adminSatker)->get($this->btcPath($dalam))->assertStatus(200);
        $this->asUser($adminSatker)->get($this->btcPath($luar))->assertStatus(403);
        $this->asUser($adminSatker)->withBodyFormat('json')->post($this->btcPath($luar), $this->btcPayload($luar))->assertStatus(403);
    }

    public function testBtcRoleTidakBerhak403(): void
    {
        $d   = $this->btcDefinisi();
        $nip = $this->buatPegawai();
        $id  = $this->sisipRiwayat($d, $nip, $this->btcBaris($nip) + [$d->kolomStatus() => 0]);

        foreach (Role::all() as $role) {
            [, , $akun] = $this->aktor($role);

            $lihat = $this->asUser($akun)->get($this->btcPath($nip));
            $this->assertSame($d->boleh(AksiRiwayat::Lihat, $role) ? 200 : 403, $lihat->response()->getStatusCode(), "lihat role {$role}");

            if (! $d->boleh(AksiRiwayat::Proses, $role)) {
                $this->asUser($akun)->withBodyFormat('json')->post($this->btcPath($nip, $id) . '/process', ['aksi' => 'setujui'])->assertStatus(403);
            }

            if (! $d->boleh(AksiRiwayat::Tambah, $role)) {
                $this->asUser($akun)->withBodyFormat('json')->post($this->btcPath($nip), $this->btcPayload($nip))->assertStatus(403);
            }

            if (! $d->boleh(AksiRiwayat::Hapus, $role)) {
                $this->asUser($akun)->delete($this->btcPath($nip, $id))->assertStatus(403);
            }
        }
    }

    public function testBtcAuditTercatatTermasukSnapshot(): void
    {
        $d           = $this->btcDefinisi();
        $nip         = $this->buatPegawai();
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        $id          = $this->sisipRiwayat($d, $nip, $this->btcBaris($nip) + [$d->kolomStatus() => 0]);

        $this->asUser($super)->withBodyFormat('json')->post($this->btcPath($nip, $id) . '/process', ['aksi' => 'setujui'])->assertStatus(200);

        $audit = $this->auditUji($d->tabel(), (string) $id);
        $this->assertSame(AuditLogModel::EVENT_UPDATE, $audit[count($audit) - 1]['event'] ?? null);
        $this->assertSame((string) $super['id_pengguna'], (string) $audit[count($audit) - 1]['id_pengguna_actor']);

        foreach ($d->snapshot() as $aturan) {
            if ($this->snapshotUji($aturan->tabel, $nip) !== null) {
                $this->assertNotSame([], $this->auditUji($aturan->tabel, $nip), "audit snapshot {$aturan->tabel}");
            }
        }
    }

    /**
     * Scope untuk butir lingkup role 3. Default: fake S0 yang hanya mengizinkan $nipDalam. Setelah MAKE-009 di main,
     * jenis boleh menimpa ini dengan PegawaiScope nyata.
     *
     * @param list<string> $nipDalam
     */
    protected function btcScopeRole3(array $nipDalam): FakePegawaiScope
    {
        return FakePegawaiScope::izinkanHanya($nipDalam);
    }

    protected function btcPath(string $nip, ?int $id = null): string
    {
        return "api/v1/pegawai/{$nip}/riwayat/{$this->btcDefinisi()->jenis()}" . ($id === null ? '' : "/{$id}");
    }

    /**
     * Isi semua tabel snapshot Definisi untuk $nip.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function btcSnapshot(string $nip): array
    {
        $hasil = [];

        foreach ($this->btcDefinisi()->snapshot() as $aturan) {
            $hasil[$aturan->tabel] = $this->snapshotUji($aturan->tabel, $nip);
        }

        return $hasil;
    }

    /**
     * Tabel snapshot yang menunjuk baris riwayat $id.
     *
     * @return list<string>
     */
    private function btcSnapshotBerisi(string $nip, int $id): array
    {
        $pk = $this->btcDefinisi()->primaryKey();

        return array_keys(array_filter(
            $this->btcSnapshot($nip),
            static fn (?array $row): bool => $row !== null && (string) ($row[$pk] ?? '') === (string) $id,
        ));
    }
}
