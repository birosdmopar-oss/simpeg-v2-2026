<?php

declare(strict_types=1);

namespace Tests\Layanan\Esign;

use App\Exceptions\ApiException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Layanan\Cuti\CutiService;
use App\Libraries\Layanan\Cuti\EsignCutiService;
use App\Libraries\Layanan\Cuti\TteAtasNama;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\EsignUjiTrait;

/**
 * MAKE-075 (Q6-40, ISSUE-055 opsi d) — TTE surat cuti "atas nama" penanda tangan oleh Super Admin dengan passphrase pejabat:
 * hanya bila keputusan final diproses atas nama (audit `cuti.process_atas_nama` terakhir yang final), TTE atas identitas
 * PyB (`esign_hist.nip`/`nik` PyB), audit pelaku = admin + `atas_nama`, pembatas Q6-20 per PyB + cuti dipakai bersama
 * (admin A, admin B, dan PyB), tanpa passphrase → surat tanpa TTE, F6-18 tetap untuk kasus lain. Alur persetujuan nyata
 * lewat {@see \App\Libraries\Layanan\Cuti\CutiApprovalService} (AL & PyB role 4 = tak terjangkau).
 *
 * @internal
 */
final class EsignAtasNamaTest extends DatabaseTestCase
{
    use EsignUjiTrait;

    private const NIK_PYB = '3171000000000077';

    protected $seed = MasterDataSeeder::class;

    /**
     * Cuti dua langkah dengan AL & PyB role 4 (tak terjangkau, punya akun), disetujui atas nama sampai final.
     *
     * @return array{id: int, a: array<string, mixed>, admin: array<string, mixed>}
     */
    private function finalAtasNama(string $aksiPyb = 'setujui'): array
    {
        $al    = $this->buatPejabat(4);
        $pyb   = $this->buatPejabat(4);
        $p     = $this->buatPemohon(2, [], [], ['id_atasan_es_4' => $al['id_jabatan']]);
        $admin = $this->buatPemohon(1);
        $this->aturanPyb($p['jabatan'], $pyb['id_jabatan'], 'III/a');
        $this->buatSaldo($p['nip'], 2026);
        $this->db->table('pegawai')->where('nip', $pyb['nip'])->update(['nik' => self::NIK_PYB]);

        $id  = (int) $this->cutiService()->tambah($p['auth'], $this->isianCuti($al['nip'], $pyb['nip']), null)['id_riwayat_cuti'];
        $svc = $this->approvalService();
        $svc->process($admin['auth'], $id, ['aksi' => 'setujui', 'atas_nama' => true, 'reason_note' => 'AL tak terjangkau']);
        $svc->process($admin['auth'], $id, ['aksi' => $aksiPyb, 'atas_nama' => true, 'reason_note' => 'PyB tak terjangkau']);

        return ['id' => $id, 'a' => ['pemohon' => $p, 'al' => $al, 'pyb' => $pyb], 'admin' => $admin];
    }

    /**
     * @return array<string, bool>
     */
    private function aksi(AuthContext $auth, int $id): array
    {
        $svc = $this->cutiService();

        return $svc->itemDetail($auth, (array) $svc->ambil($id))['aksi'];
    }

    public function testAtasNamaSuksesTteIdentitasPybAuditAdminLaluTersembunyiDanPybTidakBisaUlang(): void
    {
        ['id' => $id, 'a' => $a, 'admin' => $admin] = $this->finalAtasNama();

        $this->assertSame(1, (int) $this->cutiService()->ambil($id)['status']);
        $this->assertTrue($this->aksi($admin['auth'], $id)['esign_atas_nama']);
        $this->assertFalse($this->aksi($admin['auth'], $id)['esign'], 'F6-18: bukan penanda tangan');
        $this->assertSame(EsignCutiService::SERTIFIKAT_AKTIF, $this->esignService()->statusSertifikat($admin['auth'], $id, true)['status']);

        $hasil = $this->esignService()->terbitkan($admin['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI, 'atas_nama' => true], '10.0.0.9', 'PHPUnit Admin');

        $this->assertCount(1, $this->lampiranUji->daftar($a['pemohon']['nip'], CutiService::ID_SURAT_TTE, $id));
        $hist = $this->db->table('esign_hist')->where('id_rw', $id)->get()->getResultArray();
        $this->assertSame([['1', $a['pyb']['nip'], self::NIK_PYB, '10.0.0.9']], array_map(static fn (array $h): array => [$h['status'], $h['nip'], $h['nik'], $h['ip_address']], $hist), 'esign_hist mencatat PyB');

        $audit = $this->auditEsign($id);
        $this->assertSame([true, $a['pyb']['nip']], [$audit['after']['atas_nama'] ?? null, $audit['after']['nip_penanda']]);
        $this->assertSame([(int) $admin['id_pengguna'], $admin['nip']], [(int) $audit['id_pengguna_actor'], $audit['nip_actor']], 'pelaku = admin');

        $this->assertFalse($hasil['aksi']['esign_atas_nama'], 'sesudah TTE: item ⋮ tersembunyi');
        $this->assertFalse($this->aksi($a['pyb']['auth'], $id)['esign'], 'PyB tidak bisa menandatangani ulang');

        try {
            $this->esignService()->terbitkan($a['pyb']['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
            $this->fail('PyB menandatangani ulang harus ditolak');
        } catch (ValidationException $e) {
            $this->assertSame(EsignCutiService::PESAN_SUDAH_TTE, $e->getErrors()['status'][0] ?? null);
        }

        $semua = json_encode([$hasil, $this->db->table('audit_logs')->get()->getResultArray(), $hist], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::PASSPHRASE_UJI, $semua);
        $this->assertStringNotContainsString(self::NIK_PYB, json_encode($hasil, JSON_THROW_ON_ERROR), 'NIK tidak di respons');
    }

    public function testTanpaPassphraseSuratTanpaTteDanBisaDiulang(): void
    {
        ['id' => $id, 'a' => $a, 'admin' => $admin] = $this->finalAtasNama('tangguhkan');

        $this->assertSame(4, (int) $this->cutiService()->ambil($id)['status']);

        foreach ([[], ['passphrase' => '  ']] as $body) {
            try {
                $this->esignService()->terbitkan($admin['auth'], $id, $body + ['atas_nama' => true], null, null);
                $this->fail('tanpa passphrase harus 422');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('passphrase', $e->getErrors());
            }
        }

        $this->assertSame([], $this->lampiranUji->daftar($a['pemohon']['nip'], CutiService::ID_SURAT_TTE, $id), 'surat tanpa TTE');

        // S1 review: status final terminal — pejabat tidak bisa memutus ulang (mis. 4 → 4) sesudah keputusan atas nama, jadi
        // entri atas nama final terakhir = keputusan final terakhir.
        foreach (['setujui', 'tangguhkan'] as $aksiUlang) {
            try {
                $this->approvalService()->process($a['pyb']['auth'], $id, ['aksi' => $aksiUlang]);
                $this->fail("pejabat memproses ulang cuti final ({$aksiUlang}) harus ditolak");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->getErrors(), $aksiUlang);
            }
        }

        $this->assertSame(4, (int) $this->cutiService()->ambil($id)['status']);
        $this->assertTrue($this->aksi($admin['auth'], $id)['esign_atas_nama'], 'bisa diulang (Q6-19)');
        $this->assertSame(0, $this->db->table('esign_hist')->countAllResults());
    }

    public function testDuaAdminBerbedaKunciQ620BersamaDenganPyb(): void
    {
        ['id' => $id, 'a' => $a, 'admin' => $adminA] = $this->finalAtasNama();
        $adminB                                      = $this->buatPemohon(1);
        $gagal                                       = $this->esignService([$a['pyb']['nip']]);

        for ($i = 0; $i < EsignCutiService::BATAS_PERCOBAAN; $i++) {
            try {
                $gagal->terbitkan($adminA['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI, 'atas_nama' => true], null, null);
            } catch (ValidationException) {
                // sertifikat PyB tidak aktif → percobaan gagal dicatat atas NIP PyB
            }
        }

        foreach (['admin B' => [$adminB['auth'], true], 'PyB' => [$a['pyb']['auth'], false]] as $siapa => [$auth, $atasNama]) {
            try {
                $this->esignService()->terbitkan($auth, $id, ['passphrase' => self::PASSPHRASE_UJI] + ($atasNama ? ['atas_nama' => true] : []), null, null);
                $this->fail("{$siapa} harus terkunci Q6-20");
            } catch (ApiException $e) {
                $this->assertSame([429, EsignCutiService::KODE_TERKUNCI], [$e->getStatusCode(), $e->getKode()], $siapa);
            }
        }

        $this->assertSame(EsignCutiService::BATAS_PERCOBAAN, $this->db->table('esign_hist')->where(['id_rw' => $id, 'nip' => $a['pyb']['nip']])->countAllResults());

        // Jendela lewat → admin B boleh mengulang sesudah admin A gagal.
        $this->db->table('esign_hist')->where('id_rw', $id)->update(['created_at' => gmdate('Y-m-d H:i:s', time() - 16 * 60)]);
        $this->esignService()->terbitkan($adminB['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI, 'atas_nama' => true], null, null);
        $this->assertSame((int) $adminB['id_pengguna'], (int) $this->auditEsign($id)['id_pengguna_actor']);
    }

    public function testFinalOlehPybSendiriAtauAtasNamaNonFinalDitolak403(): void
    {
        // Final diputus PyB sendiri (tanpa audit atas nama) → F6-18: admin tidak boleh.
        ['id' => $id] = $this->cutiFinal();
        $admin        = $this->buatPemohon(1);

        $this->assertFalse($this->aksi($admin['auth'], $id)['esign_atas_nama']);
        $this->assertDitolak(fn () => $this->esignService()->terbitkan($admin['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI, 'atas_nama' => true], null, null));
        $this->assertDitolak(fn () => $this->esignService()->statusSertifikat($admin['auth'], $id, true));
        $this->assertDitolak(fn () => $this->esignService()->terbitkan($admin['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null), 'tanpa atas_nama: F6-18 tetap');

        // Atas nama hanya di tahap AL (status 3, belum final) → tidak membuka TTE (Q2).
        $al  = $this->buatPejabat(4);
        $pyb = $this->buatPejabat(2);
        $p   = $this->buatPemohon(2, [], [], ['id_atasan_es_4' => $al['id_jabatan']]);
        $this->aturanPyb($p['jabatan'], $pyb['id_jabatan'], 'III/a');
        $this->buatSaldo($p['nip'], 2026);
        $id2 = (int) $this->cutiService()->tambah($p['auth'], $this->isianCuti($al['nip'], $pyb['nip']), null)['id_riwayat_cuti'];
        $this->approvalService()->process($admin['auth'], $id2, ['aksi' => 'setujui', 'atas_nama' => true, 'reason_note' => 'AL tak terjangkau']);

        $this->assertFalse(TteAtasNama::finalAtasNama($this->db, (array) $this->cutiService()->ambil($id2)));
        $this->assertDitolak(fn () => $this->esignService()->terbitkan($admin['auth'], $id2, ['passphrase' => self::PASSPHRASE_UJI, 'atas_nama' => true], null, null), 'non-final');
        $this->assertSame(0, $this->db->table('esign_hist')->countAllResults(), 'penolakan tidak menulis percobaan');
    }

    public function testBukanRole1DenganAtasNamaDitolakDanTolakAtasNamaTanpaTte(): void
    {
        ['id' => $id, 'a' => $a, 'admin' => $admin] = $this->finalAtasNama();

        foreach (['pemohon' => $a['pemohon']['auth'], 'AL' => $a['al']['auth']] as $siapa => $auth) {
            $this->assertFalse($this->aksi($auth, $id)['esign_atas_nama'], $siapa);
            $this->assertDitolak(fn () => $this->esignService()->terbitkan($auth, $id, ['passphrase' => self::PASSPHRASE_UJI, 'atas_nama' => true], null, null), $siapa);
        }

        // PyB sendiri tetap boleh lewat alur F6-18 biasa (bukan atas nama).
        $this->assertTrue($this->aksi($a['pyb']['auth'], $id)['esign']);

        // Tolak atas nama (status 2) → tidak ada surat, tidak ada TTE.
        ['id' => $idTolak] = $this->finalAtasNama('tolak');
        $this->assertSame(2, (int) $this->cutiService()->ambil($idTolak)['status']);
        $this->assertFalse($this->aksi($admin['auth'], $idTolak)['esign_atas_nama']);
    }

    private function assertDitolak(callable $f, string $pesan = ''): void
    {
        try {
            $f();
            $this->fail('harus 403: ' . $pesan);
        } catch (ForbiddenException $e) {
            $this->assertSame(403, $e->getStatusCode(), $pesan);
        }
    }

    /**
     * Audit `cuti.esign_terbit` terakhir untuk cuti ini.
     *
     * @return array{after: array<string, mixed>, id_pengguna_actor: mixed, nip_actor: mixed}
     */
    private function auditEsign(int $id): array
    {
        foreach ($this->db->table('audit_logs')->where('entity', 'riwayat_cuti')->where('entity_id', (string) $id)->orderBy('id_log', 'DESC')->get()->getResultArray() as $r) {
            $after = json_decode((string) $r['after_json'], true);

            if (is_array($after) && ($after['jenis_audit'] ?? null) === EsignCutiService::JENIS_AUDIT) {
                return ['after' => $after, 'id_pengguna_actor' => $r['id_pengguna_actor'], 'nip_actor' => $r['nip_actor']];
            }
        }

        $this->fail('audit esign tidak ada');
    }
}
