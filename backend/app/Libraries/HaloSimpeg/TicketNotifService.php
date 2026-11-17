<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg;

use App\Libraries\Push\AntrePushPesan;
use CodeIgniter\Database\BaseConnection;
use Config\HaloSimpeg;
use Throwable;

/**
 * Notifikasi push tiket Halo Simpeg (E-06, MAKE-072; PRD Fase 8 v1.4 §6.4, §7.6, F8-13..F8-16, H8-14 (a)). Dipanggil
 * `TiketService` **sesudah commit**; mengantre `AntrePushPesan::antreAkun` (job `push-pesan`, CR-137), kanal `semua` (F8-15).
 *
 * - Penerima (F8-13, F8-14): tiket baru → petugas aktif kategori; balasan pemilik → petugas aktif kategori; balasan petugas →
 *   pemilik; tutup → pihak lawan (oleh pemilik → petugas aktif kategori, oleh petugas → pemilik). Pelaku dikecualikan.
 * - Petugas aktif = `support_officer.status = 1`, akun aktif, role 1–7 (F8-06, S12); token akun nonaktif/terhapus juga dilewati
 *   job. Tiket berkategori NULL tidak punya petugas penerima (H8-16 (a1)).
 * - Payload terbatas F8-16 (H8-8 belum ada, v1.4): judul "Halo Simpeg", isi = teks pesan terpotong, `data = {modul, id_inbox, url,
 *   link}` (`link` = tautan SPA yang dibuka service worker web, sama dengan `url`). Tanpa NIP, jabatan, satker, token, atau isi
 *   pesan lengkap (AC-E06.5).
 * - BP8-1: tidak menulis `inbox`/`inbox_msg`. Kegagalan menentukan penerima atau mengantre dicatat dan TIDAK dilempar, sehingga
 *   pesan/tiket tetap tersimpan (AC-E06.3).
 */
final class TicketNotifService
{
    public const JUDUL = 'Halo Simpeg';

    public const MODUL = 'halo-simpeg';

    public const KANAL = 'semua';

    public const ISI_DITUTUP = 'Percakapan Halo Simpeg telah ditutup.';

    private readonly AntrePushPesan $antre;

    private readonly HaloSimpeg $config;

    public function __construct(private readonly BaseConnection $db, ?AntrePushPesan $antre = null, ?HaloSimpeg $config = null)
    {
        $this->antre  = $antre ?? new AntrePushPesan();
        $this->config = $config ?? config(HaloSimpeg::class);
    }

    /**
     * @param array<string, mixed> $t baris `disiplin_ticket`
     */
    public function tiketBaru(array $t, int $pelaku, string $pesan): void
    {
        $this->kirim(fn (): array => $this->petugasAktif($t['id_question_category']), $t, $pelaku, $this->potong($pesan));
    }

    /**
     * @param array<string, mixed> $t
     */
    public function balasan(array $t, int $pelaku, string $pesan): void
    {
        $this->kirim(fn (): array => $this->lawan($t, $pelaku), $t, $pelaku, $this->potong($pesan));
    }

    /**
     * @param array<string, mixed> $t
     */
    public function ditutup(array $t, int $pelaku): void
    {
        $this->kirim(fn (): array => $this->lawan($t, $pelaku), $t, $pelaku, self::ISI_DITUTUP);
    }

    /**
     * Akun petugas aktif kategori `$kategori` (kosong bila NULL).
     *
     * @return list<int>
     */
    public function petugasAktif(mixed $kategori): array
    {
        if ($kategori === null) {
            return [];
        }

        $rows = $this->db->table('support_officer so')->select('so.id_user')
            ->join('officer_category oc', 'oc.id_support_officer = so.id_support_officer', 'inner')
            ->join('pengguna u', 'u.id_pengguna = so.id_user', 'inner')
            ->where('oc.id_question_category', (int) $kategori)->where('so.status', MasterHaloSimpeg::STATUS_AKTIF)
            ->where('u.status', '1')->where('u.deleted_at', null)->whereIn('u.user_level', PetugasService::ROLE_PETUGAS)
            ->orderBy('so.id_user', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $r): int => (int) $r['id_user'], $rows);
    }

    /** Tautan SPA tiket (data `url`/`link`). */
    public static function url(int $idInbox): string
    {
        return '/halo-simpeg/tiket/' . $idInbox;
    }

    /**
     * Pihak lawan pelaku: pemilik bertindak → petugas aktif kategori; selain itu → pemilik.
     *
     * @param array<string, mixed> $t
     *
     * @return list<int>
     */
    private function lawan(array $t, int $pelaku): array
    {
        return (int) $t['sender'] === $pelaku ? $this->petugasAktif($t['id_question_category']) : [(int) $t['sender']];
    }

    /**
     * @param callable(): list<int> $penerima
     * @param array<string, mixed>  $t
     */
    private function kirim(callable $penerima, array $t, int $pelaku, string $isi): void
    {
        try {
            $akun = array_values(array_filter(array_unique($penerima()), static fn (int $id): bool => $id !== $pelaku));

            if ($akun === []) {
                return;
            }

            $id = (int) $t['id_inbox'];
            $this->antre->antreAkun($akun, self::JUDUL, $isi, ['modul' => self::MODUL, 'id_inbox' => (string) $id, 'url' => self::url($id), 'link' => self::url($id)], self::KANAL);
        } catch (Throwable $e) {
            log_message('warning', 'Notifikasi push Halo Simpeg gagal diantre (tiket {id}): {kelas}', ['id' => (int) ($t['id_inbox'] ?? 0), 'kelas' => $e::class]);
        }
    }

    private function potong(string $pesan): string
    {
        $pesan = trim((string) preg_replace('/\s+/u', ' ', $pesan));

        return mb_strlen($pesan) > $this->config->panjangPotongan ? mb_substr($pesan, 0, $this->config->panjangPotongan) . '…' : $pesan;
    }
}
