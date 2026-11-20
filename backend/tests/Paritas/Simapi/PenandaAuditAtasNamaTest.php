<?php

declare(strict_types=1);

namespace Tests\Paritas\Simapi;

use App\Exceptions\ForbiddenException;
use App\Libraries\Layanan\Cuti\CutiApprovalService;
use App\Libraries\Layanan\Cuti\TteAtasNama;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\EsignUjiTrait;

/**
 * CR-170 — test karakterisasi entri audit `cuti.process_atas_nama` (ditulis {@see CutiApprovalService}) sebagai syarat purge
 * X-12: {@see TteAtasNama} membaca `audit_logs` itu; dengan entri → TTE atas nama diizinkan, entri terhapus → ditolak 403
 * (lewat `EsignCutiService::statusSertifikat()` / `terbitkan()` dan `TteAtasNama` langsung). Data & NIP sintetis.
 *
 * @internal
 */
final class PenandaAuditAtasNamaTest extends DatabaseTestCase
{
    use EsignUjiTrait;

    protected $seed = MasterDataSeeder::class;

    public function testEntriAuditAtasNamaSyaratTteAtasNama(): void
    {
        $al    = $this->buatPejabat(4);
        $pyb   = $this->buatPejabat(4);
        $p     = $this->buatPemohon(2, [], [], ['id_atasan_es_4' => $al['id_jabatan']]);
        $admin = $this->buatPemohon(1);
        $this->aturanPyb($p['jabatan'], $pyb['id_jabatan'], 'III/a');
        $this->buatSaldo($p['nip'], 2026);

        $id  = (int) $this->cutiService()->tambah($p['auth'], $this->isianCuti($al['nip'], $pyb['nip']), null)['id_riwayat_cuti'];
        $svc = $this->approvalService();
        $svc->process($admin['auth'], $id, ['aksi' => 'setujui', 'atas_nama' => true, 'reason_note' => 'AL tak terjangkau']);
        $svc->process($admin['auth'], $id, ['aksi' => 'setujui', 'atas_nama' => true, 'reason_note' => 'PyB tak terjangkau']);

        $cuti = (array) $this->cutiService()->ambil($id);
        $this->assertSame(1, (int) $cuti['status']);
        $this->assertTrue(TteAtasNama::boleh($this->db, $admin['auth'], $cuti), 'dengan entri audit: diizinkan');
        $this->assertSame([$id => true], TteAtasNama::finalAtasNamaBanyak($this->db, [$cuti]));
        $this->esignService()->statusSertifikat($admin['auth'], $id, true);

        $entri = $this->db->table('audit_logs')->where(['entity' => 'riwayat_cuti', 'entity_id' => (string) $id])
            ->like('after_json', CutiApprovalService::JENIS_AUDIT_ATAS_NAMA)->countAllResults();
        $this->assertSame(2, $entri, 'satu entri per langkah atas nama (AL + PyB)');

        // Simulasi purge: hapus entri audit atas nama cuti ini.
        $this->db->table('audit_logs')->where(['entity' => 'riwayat_cuti', 'entity_id' => (string) $id])
            ->like('after_json', CutiApprovalService::JENIS_AUDIT_ATAS_NAMA)->delete();

        $this->assertFalse(TteAtasNama::boleh($this->db, $admin['auth'], $cuti), 'tanpa entri audit: ditolak');
        $this->assertSame([$id => false], TteAtasNama::finalAtasNamaBanyak($this->db, [$cuti]));

        foreach ([
            'statusSertifikat' => fn () => $this->esignService()->statusSertifikat($admin['auth'], $id, true),
            'terbitkan'        => fn () => $this->esignService()->terbitkan($admin['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI, 'atas_nama' => true], null, null),
        ] as $jalur => $f) {
            try {
                $f();
                $this->fail('harus 403: ' . $jalur);
            } catch (ForbiddenException $e) {
                $this->assertSame([403, TteAtasNama::PESAN_DITOLAK], [$e->getStatusCode(), $e->getMessage()], $jalur);
            }
        }

        $this->assertSame(0, $this->db->table('esign_hist')->where('id_rw', $id)->countAllResults(), 'penolakan tidak menulis percobaan');
    }
}
