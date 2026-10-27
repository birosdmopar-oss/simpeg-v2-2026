<?php

declare(strict_types=1);

namespace App\Libraries\Layanan;

use App\Interfaces\Layanan\NotifikasiLayananInterface;
use App\Models\Layanan\InboxModel;
use App\Models\Layanan\InboxMsgModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

/**
 * Notifikasi satu arah layanan (PRD Fase 4 v1.3 §6.6, C-06): menulis `inbox` (satu induk per penerima per pengajuan) dan
 * `inbox_msg` (satu baris per kejadian) v2, lalu mengantrekan push mock FCM. TIDAK pernah menyentuh `disiplin_ticket*`
 * dan tidak punya dependensi ke model/tabel ticketing (AC-06.4).
 *
 * Pola pemanggil ({@see NotifikasiLayananInterface}): `kirim()` di DALAM transaksi bisnis, `enqueuePush()` SESUDAH commit.
 * Penerima duplikat digabung; NIP pelaku aksi (`authContext`) tidak dikirimi notifikasi atas aksinya sendiri; NIP yang tidak
 * terdaftar sebagai pegawai dilewati (FK `inbox.nip_penerima`). Stempel `created_at` UTC diisi aplikasi (G2).
 *
 * Push: satu job `push-notif` di antrean `push` per `inbox_msg`. Gagal enqueue → `log_message('error')` +
 * `push_status = 2`, dan transaksi bisnis yang sudah commit tidak dibatalkan.
 */
class NotifikasiService implements NotifikasiLayananInterface
{
    public const ANTREAN = 'push';

    public const JOB = 'push-notif';

    private const MAKS_JUDUL = 150;

    private const MAKS_URL = 255;

    private const MAKS_ISI = 500;

    private const MAKS_ALASAN = 255;

    /** `jenis_layanan.id_jenis_layanan` seed DBV-022; cuti bukan `jenis_layanan` (NULL). */
    private const JENIS_LAYANAN = ['tb' => 3, 'ib' => 4, 'pmk' => 5];

    /**
     * @param Closure(int): bool|null $antre pengantre job per id `inbox_msg` (uji); bawaan = `service('queue')`
     */
    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly ?Closure $antre = null,
    ) {
    }

    public function kirim(string $modul, int|string $idRwy, int $tipe, array $penerima, string $judul, string $isi, ?string $reasonNote, int $statusSumber, string $url): array
    {
        $nipPelaku = service('authContext')->nip();
        $nips      = [];

        foreach ($penerima as $nip) {
            $nip = trim((string) $nip);

            if ($nip !== '' && $nip !== $nipPelaku) {
                $nips[$nip] = $nip;
            }
        }

        if ($nips === []) {
            return [];
        }

        $db       = $this->db ?? db_connect();
        $ada      = array_flip(array_map('strval', array_column($db->table('pegawai')->select('nip')->whereIn('nip', array_values($nips))->get()->getResultArray(), 'nip')));
        $sekarang = gmdate('Y-m-d H:i:s');
        $inbox    = new InboxModel();
        $pesan    = new InboxMsgModel();
        $ids      = [];

        foreach ($nips as $nip) {
            if (! isset($ada[$nip])) {
                continue;
            }

            $induk = $db->table('inbox')->select('id_inbox')->where('nip_penerima', $nip)->where('modul', $modul)->where('id_rwy', (int) $idRwy)->get()->getRowArray();

            if ($induk !== null) {
                $idInbox = (int) $induk['id_inbox'];
                $inbox->update($idInbox, ['judul' => $this->potong($judul, self::MAKS_JUDUL), 'url' => $this->potong($url, self::MAKS_URL), 'is_read' => 0, 'read_at' => null]);
            } else {
                $idInbox = (int) $inbox->insert([
                    'nip_penerima'         => $nip,
                    'id_pengguna_penerima' => $this->idPengguna($db, $nip),
                    'modul'                => $modul,
                    'id_jenis_layanan'     => self::JENIS_LAYANAN[$modul] ?? null,
                    'id_rwy'               => (int) $idRwy,
                    'judul'                => $this->potong($judul, self::MAKS_JUDUL),
                    'url'                  => $this->potong($url, self::MAKS_URL),
                    'is_read'              => 0,
                    'created_at'           => $sekarang,
                ]);
            }

            if ($idInbox === 0) {
                throw new RuntimeException('Notifikasi gagal disimpan.');
            }

            $idMsg = (int) $pesan->insert([
                'id_inbox'      => $idInbox,
                'tipe'          => $tipe,
                'status_sumber' => $statusSumber,
                'isi'           => $this->potong($isi, self::MAKS_ISI),
                'reason_note'   => $reasonNote === null || trim($reasonNote) === '' ? null : $this->potong(trim($reasonNote), self::MAKS_ALASAN),
                'push_status'   => InboxMsgModel::PUSH_ANTRE,
                'created_at'    => $sekarang,
            ]);

            if ($idMsg === 0) {
                throw new RuntimeException('Notifikasi gagal disimpan.');
            }

            $ids[] = $idMsg;
        }

        return $ids;
    }

    public function enqueuePush(array $ids): void
    {
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            try {
                if (! $this->antrekan($id)) {
                    throw new RuntimeException('antrean menolak job');
                }
            } catch (Throwable $e) {
                log_message('error', '[NotifikasiService] gagal mengantrekan push inbox_msg={id}: {pesan}', ['id' => $id, 'pesan' => $e->getMessage()]);

                try {
                    (new InboxMsgModel())->update($id, ['push_status' => InboxMsgModel::PUSH_GAGAL]);
                } catch (Throwable $e2) {
                    log_message('error', '[NotifikasiService] gagal menandai push_status inbox_msg={id}: {pesan}', ['id' => $id, 'pesan' => $e2->getMessage()]);
                }
            }
        }
    }

    private function antrekan(int $idMsg): bool
    {
        if ($this->antre !== null) {
            return ($this->antre)($idMsg);
        }

        return service('queue')->push(self::ANTREAN, self::JOB, ['id_inbox_msg' => $idMsg])->getStatus();
    }

    private function idPengguna(BaseConnection $db, string $nip): ?int
    {
        $akun = $db->table('pengguna')->select('id_pengguna')->where('nip', $nip)->where('deleted_at', null)->orderBy('id_pengguna', 'ASC')->get()->getRowArray();

        return $akun === null ? null : (int) $akun['id_pengguna'];
    }

    private function potong(string $teks, int $maks): string
    {
        return mb_substr($teks, 0, $maks);
    }
}
