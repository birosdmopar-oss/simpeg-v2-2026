<?php

declare(strict_types=1);

namespace Tests\Layanan\Notifikasi;

use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\CutiUjiTrait;

/**
 * MAKE-023 M4 (PRD Fase 4 §6.6.2, AC-06.1/06.2/06.5) — penerima dan templat notifikasi cuti pada setiap transisi:
 * diajukan (AL / PyB langkah tunggal / ketua tim CC), diubah, AL setuju (T4), keputusan final setuju/tolak/tangguhkan
 * (pemohon + tembusan), approver tak terjangkau (admin role 1), keputusan langsung admin. `status_sumber` = status baru.
 *
 * @internal
 */
final class NotifikasiPenerimaTest extends DatabaseTestCase
{
    use CutiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pasangNotifikasi();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pesan(string $nip): array
    {
        return $this->db->table('inbox_msg')
            ->select('inbox.judul, inbox.modul, inbox.id_rwy, inbox.url, inbox_msg.isi, inbox_msg.tipe, inbox_msg.status_sumber, inbox_msg.reason_note, inbox_msg.push_status')
            ->join('inbox', 'inbox.id_inbox = inbox_msg.id_inbox')
            ->where('inbox.nip_penerima', $nip)
            ->orderBy('inbox_msg.id_inbox_msg')
            ->get()->getResultArray();
    }

    /**
     * @return array{id: int, a: array<string, mixed>, kt: string}
     */
    private function ajukan(): array
    {
        $a  = $this->buatAlurDuaLangkah();
        $kt = $this->buatPegawai();
        $this->buatSaldo($a['pemohon']['nip'], 2026);

        $id = (int) $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip'], ['ketua_tim' => [$kt]]), null)['id_riwayat_cuti'];

        return ['id' => $id, 'a' => $a, 'kt' => $kt];
    }

    public function testDiajukanMengabariAlDanKetuaTimTanpaPemohonDanPyb(): void
    {
        ['id' => $id, 'a' => $a, 'kt' => $kt] = $this->ajukan();

        $al = $this->pesan($a['al']['nip']);
        $this->assertCount(1, $al);
        $this->assertSame('Permohonan Cuti Online Baru', $al[0]['judul']);
        $this->assertSame('cuti', $al[0]['modul']);
        $this->assertSame($id, (int) $al[0]['id_rwy']);
        $this->assertSame("/layanan/cuti/{$id}", $al[0]['url']);
        $this->assertSame(1, (int) $al[0]['tipe']);
        $this->assertSame(0, (int) $al[0]['status_sumber'], 'AC-06.1: status_sumber = status baru');
        $this->assertStringContainsString('telah mengajukan cuti (', $al[0]['isi']);

        $tembusan = $this->pesan($kt);
        $this->assertCount(1, $tembusan);
        $this->assertSame('Tembusan Cuti Online', $tembusan[0]['judul']);
        $this->assertSame(4, (int) $tembusan[0]['tipe']);
        $this->assertSame(1, $this->db->table('riwayat_cuti_notif_kt')->where('id_riwayat_cuti', $id)->where('nip_kt', $kt)->countAllResults(), 'AC-06.5: baris notif_kt terbentuk');

        $this->assertSame([], $this->pesan($a['pemohon']['nip']));
        $this->assertSame([], $this->pesan($a['pyb']['nip']), 'PyB belum dikabari sebelum AL setuju');
        $this->assertCount(2, $this->diantrekan, 'satu job push per inbox_msg, setelah commit');
    }

    public function testAlSetujuMengabariPemohonPybDanKetuaTim(): void
    {
        ['id' => $id, 'a' => $a, 'kt' => $kt] = $this->ajukan();
        $this->pasangNotifikasi();

        $this->approvalService()->process($a['al']['auth'], $id, ['aksi' => 'setujui']);

        $pemohon = $this->pesan($a['pemohon']['nip']);
        $this->assertCount(1, $pemohon);
        $this->assertSame('Cuti Online Sedang Diproses', $pemohon[0]['judul']);
        $this->assertSame(3, (int) $pemohon[0]['status_sumber']);
        $this->assertStringContainsString('menunggu persetujuan', $pemohon[0]['isi']);
        $this->assertStringNotContainsString('anda anda', $pemohon[0]['isi'], 'typo N7 diperbaiki');

        $pyb = $this->pesan($a['pyb']['nip']);
        $this->assertCount(1, $pyb);
        $this->assertSame('Permohonan Cuti Online Baru', $pyb[0]['judul']);

        $this->assertStringContainsString('sedang diproses', $this->pesan($kt)[1]['isi']);
        $this->assertCount(3, $this->diantrekan);
    }

    public function testKeputusanFinalSetujuiMengabariPemohonDanTembusan(): void
    {
        ['id' => $id, 'a' => $a, 'kt' => $kt] = $this->ajukan();
        $svc                                  = $this->approvalService();
        $svc->process($a['al']['auth'], $id, ['aksi' => 'setujui']);
        $this->pasangNotifikasi();
        $svc = $this->approvalService();

        $svc->process($a['pyb']['auth'], $id, ['aksi' => 'setujui']);

        $pemohon = $this->pesan($a['pemohon']['nip']);
        $this->assertSame('Cuti Online Disetujui', end($pemohon)['judul']);
        $this->assertSame(1, (int) end($pemohon)['status_sumber']);
        $this->assertStringContainsString('disetujui oleh', end($pemohon)['isi']);

        $kt = $this->pesan($kt);
        $this->assertSame('Tembusan Cuti Online', end($kt)['judul']);
        $this->assertSame(1, (int) end($kt)['status_sumber']);
    }

    public function testTolakMengabariPengajuDenganAlasan(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan();
        $this->pasangNotifikasi();

        $this->approvalService()->process($a['al']['auth'], $id, ['aksi' => 'tolak', 'reason_note' => 'Kuota tim penuh']);

        $pemohon = $this->pesan($a['pemohon']['nip']);
        $this->assertCount(1, $pemohon);
        $this->assertSame('Cuti Online Ditolak', $pemohon[0]['judul']);
        $this->assertSame(2, (int) $pemohon[0]['status_sumber']);
        $this->assertStringContainsString('Ket: Kuota tim penuh', $pemohon[0]['isi'], 'AC-06.2');
        $this->assertSame('Kuota tim penuh', $pemohon[0]['reason_note']);
    }

    public function testTangguhkanMengabariPemohon(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan();
        $this->pasangNotifikasi();

        $this->approvalService()->process($a['al']['auth'], $id, ['aksi' => 'tangguhkan', 'reason_note' => 'Menunggu proyek']);

        $pemohon = $this->pesan($a['pemohon']['nip']);
        $this->assertSame('Cuti Online Ditangguhkan', $pemohon[0]['judul']);
        $this->assertSame(4, (int) $pemohon[0]['status_sumber']);
        $this->assertNull($pemohon[0]['reason_note'], 'alasan hanya untuk tolak');
    }

    public function testUbahMengabariApproverTahapAktif(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan();
        $this->pasangNotifikasi();

        $this->cutiService()->ubah($a['pemohon']['auth'], $id, $this->isianCuti($a['al']['nip'], $a['pyb']['nip'], ['keterangan' => 'Keperluan keluarga yang diubah']), null);

        $al = $this->pesan($a['al']['nip']);
        $this->assertSame('Perubahan Cuti Online', end($al)['judul']);
        $this->assertSame(3, (int) end($al)['tipe']);
        $this->assertStringContainsString('oleh', end($al)['isi']);
    }

    public function testLangkahTunggalMengabariPyb(): void
    {
        $a  = $this->buatAlurDuaLangkah();
        $id = $this->buatBarisCuti($a['pemohon']['nip'], ['nip_atasan_langsung' => null, 'nip_yang_menyetujui' => $a['pyb']['nip'], 'nama_yang_menyetujui' => 'Pejabat Uji']);

        $this->notifikasiUji->diajukan($this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->get()->getRowArray());

        $this->assertSame('Permohonan Cuti Online Baru', $this->pesan($a['pyb']['nip'])[0]['judul']);
        $this->assertSame([], $this->pesan($a['al']['nip']));
    }

    public function testApproverTakTerjangkauMengabariSemuaAdminRole1(): void
    {
        $adminA = $this->buatPejabat(1);
        $adminB = $this->buatPejabat(1);
        $this->buatPejabat(2); // bukan admin
        $al      = $this->buatPejabat(null); // tanpa akun
        $pyb     = $this->buatPejabat(2);
        $pemohon = $this->buatPemohon(2, [], [], ['id_atasan_es_4' => $al['id_jabatan']]);
        $this->aturanPyb($pemohon['jabatan'], $pyb['id_jabatan'], 'III/a');
        $this->buatSaldo($pemohon['nip'], 2026);
        $this->pasangNotifikasi();

        $id = (int) $this->cutiService()->tambah($pemohon['auth'], $this->isianCuti($al['nip'], $pyb['nip']), null)['id_riwayat_cuti'];

        foreach ([$adminA['nip'], $adminB['nip']] as $nip) {
            $p = $this->pesan($nip);
            $this->assertCount(1, $p);
            $this->assertSame('Permohonan Cuti Perlu Diproses Admin', $p[0]['judul']);
            $this->assertStringContainsString('Proses atas nama melalui menu Cuti.', $p[0]['isi']);
            $this->assertSame($id, (int) $p[0]['id_rwy']);
        }

        $this->assertCount(1, $this->pesan($al['nip']), 'AL tanpa akun tetap punya inbox (kunci NIP)');
    }

    public function testApproverTerjangkauTidakMengabariAdmin(): void
    {
        $admin = $this->buatPejabat(1);
        $this->ajukan();

        $this->assertSame([], $this->pesan($admin['nip']));
    }

    public function testAdminMenyimpanLangsungDisetujuiMengabariPemohon(): void
    {
        $a     = $this->buatAlurDuaLangkah();
        $admin = $this->buatPejabat(1);
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $this->pasangNotifikasi();

        $this->cutiService()->tambah($admin['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip'], ['nip' => $a['pemohon']['nip'], 'status' => 1]), null);

        $pemohon = $this->pesan($a['pemohon']['nip']);
        $this->assertCount(1, $pemohon);
        $this->assertSame('Cuti Online Disetujui', $pemohon[0]['judul']);
        $this->assertSame(1, (int) $pemohon[0]['status_sumber']);
    }

    public function testPengajuGagalTidakMenulisNotifikasiMaupunAntrean(): void
    {
        $a = $this->buatAlurDuaLangkah(); // tanpa saldo: kuota gagal 422
        $this->pasangNotifikasi();

        try {
            $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null);
            $this->fail('Tanpa saldo harus ditolak.');
        } catch (\App\Exceptions\ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->db->table('inbox')->countAllResults());
        $this->assertSame([], $this->diantrekan);
    }
}
