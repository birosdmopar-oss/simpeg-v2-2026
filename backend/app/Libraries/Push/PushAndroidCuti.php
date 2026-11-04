<?php

declare(strict_types=1);

namespace App\Libraries\Push;

use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use Throwable;

/**
 * Push **Android** cuti ikut legacy `andr_notif_queue` (PRD Fase 6 §7.4 F6-13, MAKE-066; keputusan reviewer 04-11-2026: app
 * Android di luar repo bergantung pada kontrak ini) — dipanggil SESUDAH commit:
 *
 * - pengajuan baru [K `L_cuti.php:1063-1110`] dan perubahan [K `:1196-1245`] → atasan langsung (bila kosong: pejabat yang
 *   menyetujui), judul "Monitoring Cuti", isi "{nama} telah mengajukan|mengubah cuti untuk tanggal {mulai} s/d {akhir}";
 * - keputusan satu tahap [K `:2462-2548`, `process_esign` `:3560-3625`] → pemohon, judul "Cuti", isi "Pengajuan cuti anda
 *   anda pada tanggal {mulai} s/d {akhir} {status} oleh {pemroses}" (teks legacy apa adanya, termasuk "anda anda"); bila
 *   status 3 (diproses ke PyB) juga ke pejabat yang menyetujui dengan judul "Monitoring Cuti" + isi pengajuan.
 *
 * `data` = bentuk legacy: `{title, body, data: JSON baris riwayat_cuti + file_bukti_url '' + status_str}` (Android menerima
 * nilai data sebagai string; legacy mengirim objek yang tiba sebagai string JSON yang sama). Kanal Android saja lewat job
 * `push-pesan`; hanya bila driver push `fcm`. Gagal apa pun hanya dicatat — operasi cuti sudah commit.
 */
final class PushAndroidCuti
{
    private const STATUS_STR = [0 => 'Menunggu', 1 => 'Disetujui', 2 => 'Ditolak', 3 => 'Diproses', 4 => 'Ditangguhkan'];

    private string $driver;

    public function __construct(private readonly AntrePushPesan $antre = new AntrePushPesan(), ?string $driver = null)
    {
        $this->driver = $driver ?? config(\Config\Integrations::class)->push['driver'];
    }

    /**
     * @param array<string, mixed> $cuti baris `riwayat_cuti` sesudah commit
     */
    public function diajukan(array $cuti, bool $baru): void
    {
        $this->aman(function () use ($cuti, $baru): void {
            $nip = $this->teks($cuti['nip_atasan_langsung'] ?? null) ?: $this->teks($cuti['nip_yang_menyetujui'] ?? null);

            if ($nip !== '') {
                $this->kirim($nip, 'Monitoring Cuti', $this->isiPengajuan($cuti, $baru ? 'mengajukan' : 'mengubah'), $cuti, 'Menunggu');
            }
        });
    }

    /**
     * @param array<string, mixed> $cuti baris `riwayat_cuti` sesudah keputusan (status baru)
     */
    public function diproses(array $cuti, AuthContext $pemroses): void
    {
        $this->aman(function () use ($cuti, $pemroses): void {
            $status    = (int) ($cuti['status'] ?? -1);
            $statusStr = self::STATUS_STR[$status] ?? '';
            $isi       = 'Pengajuan cuti anda anda pada tanggal ' . $this->rentang($cuti) . ' ' . mb_strtolower($statusStr) . ' oleh ' . $this->namaPemroses($pemroses);

            $this->kirim((string) $cuti['nip'], 'Cuti', $isi, $cuti, $statusStr);

            $pyb = $this->teks($cuti['nip_yang_menyetujui'] ?? null);

            if ($status === 3 && $pyb !== '') {
                $this->kirim($pyb, 'Monitoring Cuti', $this->isiPengajuan($cuti, 'mengajukan'), $cuti, $statusStr);
            }
        });
    }

    /**
     * Bentuk `data` legacy (dipakai juga test kontrak).
     *
     * @param array<string, mixed> $cuti
     *
     * @return array<string, string>
     */
    public static function data(string $judul, string $isi, array $cuti, string $statusStr): array
    {
        $baris                   = $cuti;
        $baris['file_bukti_url'] = '';
        $baris['status_str']     = $statusStr;

        return ['title' => $judul, 'body' => $isi, 'data' => (string) json_encode($baris, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    /**
     * @param array<string, mixed> $cuti
     */
    private function kirim(string $nip, string $judul, string $isi, array $cuti, string $statusStr): void
    {
        $this->antre->antre([$nip], $judul, $isi, self::data($judul, $isi, $cuti, $statusStr), 'android');
    }

    /**
     * @param array<string, mixed> $cuti
     */
    private function isiPengajuan(array $cuti, string $kata): string
    {
        return $this->teks($cuti['nama'] ?? null) . " telah {$kata} cuti untuk tanggal " . $this->rentang($cuti);
    }

    /**
     * @param array<string, mixed> $cuti
     */
    private function rentang(array $cuti): string
    {
        return TemplatPushRiwayat::tanggal($cuti['tgl_mulai'] ?? null) . ' s/d ' . TemplatPushRiwayat::tanggal($cuti['tgl_akhir'] ?? null);
    }

    /**
     * Nama bergelar pegawai pemroses; akun tanpa pegawai → nama akun [K `L_cuti.php:2475-2477`].
     */
    private function namaPemroses(AuthContext $auth): string
    {
        $nip = (string) ($auth->nip() ?? '');
        $db  = db_connect();
        $p   = $nip === '' ? null : $db->table('pegawai')->select('glr_awal, nama, glr_akhir')->where('nip', $nip)->get(1)->getRowArray();

        if ($p !== null) {
            return StrukturService::namaGelar($p['glr_awal'], (string) $p['nama'], $p['glr_akhir']);
        }

        $akun = $db->table('pengguna')->select('name')->where('id_pengguna', (int) $auth->idPengguna())->get(1)->getRowArray();

        return $this->teks($akun['name'] ?? null);
    }

    private function aman(callable $kerja): void
    {
        if ($this->driver !== 'fcm') {
            return;
        }

        try {
            $kerja();
        } catch (Throwable $e) {
            log_message('warning', 'Push Android cuti tidak diantre: {pesan}', ['pesan' => $e->getMessage()]);
        }
    }

    private function teks(mixed $v): string
    {
        return trim((string) $v);
    }
}
