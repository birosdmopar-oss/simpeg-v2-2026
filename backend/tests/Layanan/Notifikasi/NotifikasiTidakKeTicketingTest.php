<?php

declare(strict_types=1);

namespace Tests\Layanan\Notifikasi;

use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\EsignUjiTrait;

/**
 * MAKE-023 M4 (PRD Fase 4 §6.6, AC-06.4) — regresi wajib: notifikasi satu arah TIDAK PERNAH menyentuh ticketing
 * (`disiplin_ticket*`). Setelah seluruh skenario cuti (ajukan, ubah, setujui, tolak, tangguhkan, e-sign) kedua tabel ticketing
 * tetap kosong dan `inbox` v2 terisi; sumber kode notifikasi tidak memuat nama tabel/model ticketing.
 *
 * @internal
 */
final class NotifikasiTidakKeTicketingTest extends DatabaseTestCase
{
    use EsignUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private const BERKAS_NOTIFIKASI = [
        'app/Libraries/Layanan/NotifikasiService.php',
        'app/Libraries/Layanan/Notifikasi/TemplatNotifikasi.php',
        'app/Libraries/Layanan/Notifikasi/PenerimaNotifikasi.php',
        'app/Libraries/Layanan/Cuti/CutiNotifikasiService.php',
        'app/Jobs/PushNotifLayananJob.php',
        'app/Models/Layanan/InboxModel.php',
        'app/Models/Layanan/InboxMsgModel.php',
        'app/Models/Layanan/FirebaseTokenModel.php',
    ];

    public function testSeluruhSkenarioCutiTidakMenulisTicketing(): void
    {
        $this->pasangNotifikasi();
        $admin = $this->buatPejabat(1);

        // ajukan -> ubah -> AL setujui -> PyB setujui
        $a  = $this->buatAlurDuaLangkah();
        $kt = $this->buatPegawai();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $this->db->table('pegawai')->where('nip', $a['pyb']['nip'])->update(['nik' => '3171000000000009']);
        $svc = $this->cutiService();
        $id  = (int) $svc->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip'], ['ketua_tim' => [$kt]]), null)['id_riwayat_cuti'];
        $svc->ubah($a['pemohon']['auth'], $id, $this->isianCuti($a['al']['nip'], $a['pyb']['nip'], ['keterangan' => 'Keperluan keluarga yang diubah']), null);
        $this->approvalService()->process($a['al']['auth'], $id, ['aksi' => 'setujui']);
        $this->approvalService()->process($a['pyb']['auth'], $id, ['aksi' => 'setujui']);

        // tolak (AL) dan tangguhkan (AL)
        foreach (['tolak', 'tangguhkan'] as $aksi) {
            $b = $this->buatAlurDuaLangkah();
            $this->buatSaldo($b['pemohon']['nip'], 2026);
            $idB = (int) $this->cutiService()->tambah($b['pemohon']['auth'], $this->isianCuti($b['al']['nip'], $b['pyb']['nip']), null)['id_riwayat_cuti'];
            $this->approvalService()->process($b['al']['auth'], $idB, ['aksi' => $aksi, 'reason_note' => 'Alasan uji']);
        }

        // e-sign sesudah final
        $this->esignService()->terbitkan($admin['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);

        $this->assertGreaterThan(0, $this->db->table('inbox')->countAllResults(), 'notifikasi v2 terisi');
        $this->assertSame(0, $this->db->table('disiplin_ticket')->countAllResults());
        $this->assertSame(0, $this->db->table('disiplin_ticket_msg')->countAllResults());
    }

    public function testSumberNotifikasiTidakMemuatDependensiTicketing(): void
    {
        $akar = dirname(__DIR__, 3) . '/';

        foreach (self::BERKAS_NOTIFIKASI as $berkas) {
            $kode = $this->kodeTanpaKomentar((string) file_get_contents($akar . $berkas));
            $this->assertStringNotContainsString('disiplin_ticket', $kode, $berkas);
            $this->assertStringNotContainsString('ticket', $kode, $berkas);
        }
    }

    /**
     * Isi berkas PHP tanpa komentar/docblock (huruf kecil): yang diperiksa hanya kode, bukan dokumentasi.
     */
    private function kodeTanpaKomentar(string $sumber): string
    {
        $hasil = '';

        foreach (token_get_all($sumber) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $hasil .= $token[1];
            } else {
                $hasil .= $token;
            }
        }

        return strtolower($hasil);
    }

    public function testTabelNotifikasiV2TidakMemuatKolomTicketing(): void
    {
        foreach (['inbox', 'inbox_msg'] as $tabel) {
            $kolom = array_column($this->db->getFieldData($tabel), 'name');

            foreach (['status', 'allow_reply', 'closed_by', 'is_read_off', 'notify_pic'] as $terlarang) {
                $this->assertNotContains($terlarang, $kolom, "{$tabel}.{$terlarang} (AC-01.2)");
            }
        }
    }
}
