<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Layanan\InboxMsgModel;
use App\Libraries\Push\PenerimaTokenFcm;
use App\Libraries\Push\PengirimPushFcm;
use CodeIgniter\Queue\BaseJob;
use CodeIgniter\Queue\Interfaces\JobInterface;
use RuntimeException;

/**
 * Job `push-notif` di antrean `push` (PRD Fase 4 v1.3 §6.6.3, C-06): kirim push mock FCM untuk satu `inbox_msg`.
 * Worker: `php spark queue:work push --stop-when-empty --max-time 55` (README-deploy.md §1b).
 *
 * Token (PRD Fase 6 F6-13, MAKE-066) = token Android (`platform` 2) milik `inbox.nip_penerima` [K `andr_notif_queue`] +
 * token web (`platform` 1) milik akun ber-NIP itu [K `fcmQueue_layanan` → `fb_token`], lewat {@see PenerimaTokenFcm}.
 * Tanpa token → `push_status = 3`, selesai tanpa galat. Sukses → 1. Gagal → 2 dan dilempar agar worker mencoba ulang
 * sampai {@see self::TRIES}, lalu `queue_jobs_failed` (tidak retry selamanya, H-04). Token yang dilaporkan tidak valid
 * dihapus per baris (`id_firebase_token`). Payload `{title, body, data: {modul, id_rwy, url}}`;
 * tidak memuat NIK/passphrase dan isi token tidak pernah dicatat. Idempoten: pesan yang sudah terkirim tidak dikirim ulang.
 */
class PushNotifLayananJob extends BaseJob implements JobInterface
{
    public const TRIES = 5;

    public const HASIL_TERKIRIM = 'terkirim';

    public const HASIL_TANPA_TOKEN = 'tanpa_token';

    public const HASIL_DILEWATI = 'dilewati';

    protected int $retryAfter = 60;

    protected int $tries = self::TRIES;

    public function process(): string
    {
        $idMsg = is_int($this->data['id_inbox_msg'] ?? null) ? $this->data['id_inbox_msg'] : (int) ($this->data['id_inbox_msg'] ?? 0);
        $db    = db_connect();

        $pesan = $db->table('inbox_msg')
            ->select('inbox_msg.id_inbox_msg, inbox_msg.isi, inbox_msg.push_status, inbox.nip_penerima, inbox.modul, inbox.id_rwy, inbox.judul, inbox.url')
            ->join('inbox', 'inbox.id_inbox = inbox_msg.id_inbox')
            ->where('inbox_msg.id_inbox_msg', $idMsg)
            ->get()->getRowArray();

        if ($pesan === null || (int) $pesan['push_status'] === InboxMsgModel::PUSH_TERKIRIM) {
            return self::HASIL_DILEWATI;
        }

        $model    = new InboxMsgModel();
        $nip      = (string) $pesan['nip_penerima'];
        $tokens   = new PenerimaTokenFcm($db);
        $penerima = array_merge($tokens->android([$nip]), $tokens->webAkunNip([$nip]));

        if ($penerima === []) {
            $model->update($idMsg, ['push_status' => InboxMsgModel::PUSH_TANPA_TOKEN]);

            return self::HASIL_TANPA_TOKEN;
        }

        $hasil = (new PengirimPushFcm(service('pushNotifGateway'), $db))->kirim($penerima, (string) $pesan['judul'], (string) $pesan['isi'], [
            'modul'  => (string) $pesan['modul'],
            'id_rwy' => (string) $pesan['id_rwy'],
            'url'    => (string) $pesan['url'],
        ]);

        if ($hasil['sukses'] > 0) {
            $model->update($idMsg, ['push_status' => InboxMsgModel::PUSH_TERKIRIM]);

            return self::HASIL_TERKIRIM;
        }

        $model->update($idMsg, ['push_status' => InboxMsgModel::PUSH_GAGAL]);

        throw new RuntimeException('Push inbox_msg=' . $idMsg . ' gagal: ' . $hasil['pesan']);
    }
}
