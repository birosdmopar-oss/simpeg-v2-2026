<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Push\PenerimaTokenFcm;
use App\Libraries\Push\PengirimPushFcm;
use App\Libraries\Push\TemplatPushRiwayat;
use CodeIgniter\Queue\BaseJob;
use CodeIgniter\Queue\Interfaces\JobInterface;
use RuntimeException;

/**
 * Job `push-riwayat` di antrean `push` (PRD Fase 6 §7.4, F6-13; MAKE-066) — padanan `fb_pn_queue` + `Cron::
 * process_queue_fcm_desktop` legacy [K `FH:588-915`, `C:1138`]: push WEB riwayat/usulan/LKH/Konket ke penerima legacy
 * (admin pembawah, atasan LKH, petugas Karpeg/Kariskarsu, atau pegawai). Data `{tipe, jenis, id, nip, aksi}` dari
 * {@see \App\Libraries\Push\AntrePushRiwayat} sesudah commit.
 *
 * Baris dibaca saat job berjalan; baris hilang → dilewati. Tanpa token → selesai tanpa galat. Semua kiriman gagal → dilempar
 * agar worker mencoba ulang sampai {@see self::TRIES} lalu `queue_jobs_failed` (tidak retry selamanya). Token tidak sah
 * dihapus ({@see PengirimPushFcm}). Isi token tidak pernah dicatat.
 */
class PushRiwayatJob extends BaseJob implements JobInterface
{
    public const ANTREAN = 'push';

    public const NAMA = 'push-riwayat';

    public const TRIES = 3;

    public const HASIL_TERKIRIM = 'terkirim';

    public const HASIL_TANPA_TOKEN = 'tanpa_token';

    public const HASIL_DILEWATI = 'dilewati';

    protected int $retryAfter = 60;

    protected int $tries = self::TRIES;

    public function process(): string
    {
        $tipe          = (int) ($this->data['tipe'] ?? 0);
        $jenis         = (string) ($this->data['jenis'] ?? '');
        $id            = (int) ($this->data['id'] ?? 0);
        $jenisPenerima = TemplatPushRiwayat::penerima($tipe, $jenis);

        if (! TemplatPushRiwayat::didukung($jenis) || $jenisPenerima === null || $id <= 0) {
            return self::HASIL_DILEWATI;
        }

        [, $tabel, $pk, $idPetugas] = TemplatPushRiwayat::JENIS[$jenis];
        $db                         = db_connect();
        $baris                      = $db->table($tabel)->where($pk, $id)->get(1)->getRowArray();

        if ($baris === null) {
            return self::HASIL_DILEWATI;
        }

        $nip     = (string) ($baris['nip'] ?? '');
        $pegawai = $tipe === 2 ? null : $db->table('pegawai')->select('glr_awal, nama, glr_akhir')->where('nip', $nip)->get(1)->getRowArray();
        $konket  = $jenis === 'konket'
            ? $db->table('jenis_konket')->select('jenis_konket')->where('old_id', (int) ($baris['kategori'] ?? 0))->get(1)->getRowArray()
            : null;
        $pesan = TemplatPushRiwayat::susun($tipe, $jenis, $baris, $pegawai, isset($this->data['aksi']) ? (string) $this->data['aksi'] : null, $konket['jenis_konket'] ?? null);

        if ($pesan === null) {
            return self::HASIL_DILEWATI;
        }

        $token    = new PenerimaTokenFcm($db, $this->unitKepegawaian());
        $penerima = match ($jenisPenerima) {
            TemplatPushRiwayat::PENERIMA_PEGAWAI => $token->webPegawai([$nip]),
            TemplatPushRiwayat::PENERIMA_ATASAN  => $token->webPegawai([(string) ($baris['nip_atasan'] ?? '')]),
            TemplatPushRiwayat::PENERIMA_PETUGAS => $token->webPetugasLayanan((int) $idPetugas),
            default                              => $token->webAdmin($nip),
        };

        if ($penerima === []) {
            return self::HASIL_TANPA_TOKEN;
        }

        $hasil = (new PengirimPushFcm(service('pushNotifGateway'), $db))->kirim($penerima, $pesan['judul'], $pesan['isi'], [
            'icon' => \App\Libraries\Push\FcmV1Adapter::IKON_BAWAAN,
            'link' => $pesan['link'],
        ]);

        if ($hasil['sukses'] === 0 && $hasil['gagal'] > 0) {
            throw new RuntimeException("Push riwayat {$jenis}#{$id} gagal: {$hasil['pesan']}");
        }

        return self::HASIL_TERKIRIM;
    }

    private function unitKepegawaian(): ?int
    {
        $nilai = service('webConfigService')->value('id_unit_kepegawaian');

        return is_numeric($nilai) ? (int) $nilai : null;
    }
}
