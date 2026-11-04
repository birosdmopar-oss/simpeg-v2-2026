<?php

declare(strict_types=1);

namespace App\Libraries\Push;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Push **Android** LKH dan Konket ikut legacy `andr_notif_queue` (PRD Fase 6 §7.4 F6-13, MAKE-066; koreksi reviewer
 * 04-11-2026 review MAKE-066 P1: kanal Android ikut legacy penuh) — dipanggil SESUDAH commit:
 *
 * - LKH diajukan [K `L_lkh.php:335-404`] / diubah kembali menunggu [K `:579-640`] → atasan (`nip_atasan`), judul
 *   "Monitoring LKH", isi "{nama} telah mengajukan|mengubah LKH untuk tanggal {tgl}", `status_str` "Menunggu";
 * - LKH diproses [K `:1071-1140`] → pegawai, judul "LKH", isi "LKH anda pada tanggal {tgl} {disetujui|ditolak|diubah
 *   menjadi "Revisi"} oleh {nama_atasan}";
 * - Konket diproses [K `L_konket.php:1891-1972`] → pemohon DAN (dinas gabungan: kategori 8 + jenis_dinas 1) setiap peserta,
 *   judul "Konfirmasi Ketidakhadiran", isi "Konfirmasi Ketidakhadiran anda pada tanggal {mulai} s/d {akhir} {status} oleh
 *   {Admin | nama akun}".
 *
 * `data` = bentuk legacy `{title, body, data: JSON baris}`: LKH = baris `riwayat_lckh` tanpa kolom kegiatan mentah + `status_str`
 * + `file_lckh_url` + `detail[]`; Konket = baris `absen_ijin` + `file_bukti_url` + `status_str` + `jenis` (baris
 * `jenis_konket`). URL berkas [V2] selalu '' (legacy `ARSIP . path` = server arsip lama; berkas v2 hanya lewat endpoint
 * ber-login). Hanya driver push `fcm`; gagal apa pun hanya dicatat — operasi sudah commit.
 */
final class PushAndroidLkhKonket
{
    /** Kolom kegiatan LKH yang diganti `detail[]` di payload legacy [K `L_lkh.php:349-369`]. */
    private const KOLOM_KEGIATAN = ['kegiatan', 'output', 'jumlah_diselesaikan', 'jam_mulai', 'jam_selesai'];

    private string $driver;

    public function __construct(private readonly AntrePushPesan $antre = new AntrePushPesan(), ?string $driver = null, private readonly ?BaseConnection $db = null)
    {
        $this->driver = $driver ?? config(\Config\Integrations::class)->push['driver'];
    }

    public function lkhDiajukan(int $id, bool $baru): void
    {
        $this->aman(function () use ($id, $baru): void {
            $lkh = $this->db()->table('riwayat_lckh')->where('id_riwayat_lckh', $id)->get(1)->getRowArray();
            $nip = trim((string) ($lkh['nip_atasan'] ?? ''));

            if ($lkh === null || $nip === '') {
                return;
            }

            $isi = trim((string) $lkh['nama']) . ' telah ' . ($baru ? 'mengajukan' : 'mengubah') . ' LKH untuk tanggal ' . TemplatPushRiwayat::tanggal($lkh['tgl_laporan']);
            $this->kirim($nip, 'Monitoring LKH', $isi, self::barisLkh($lkh, 'Menunggu'));
        });
    }

    public function lkhDiproses(int $id): void
    {
        $this->aman(function () use ($id): void {
            $lkh = $this->db()->table('riwayat_lckh')->where('id_riwayat_lckh', $id)->get(1)->getRowArray();

            if ($lkh === null) {
                return;
            }

            [$statusStr, $kata] = match ((int) $lkh['status']) {
                1       => ['Disetujui', 'disetujui'],
                2       => ['Ditolak', 'ditolak'],
                3       => ['Direvisi', 'diubah menjadi "Revisi"'],
                default => ['', ''],
            };
            $isi = 'LKH anda pada tanggal ' . TemplatPushRiwayat::tanggal($lkh['tgl_laporan']) . " {$kata} oleh " . trim((string) ($lkh['nama_atasan'] ?? ''));
            $this->kirim((string) $lkh['nip'], 'LKH', $isi, self::barisLkh($lkh, $statusStr));
        });
    }

    public function konketDiproses(int $id, AuthContext $pemroses): void
    {
        $this->aman(function () use ($id, $pemroses): void {
            $db    = $this->db();
            $induk = $db->table('absen_ijin')->where('id', $id)->get(1)->getRowArray();

            if ($induk === null) {
                return;
            }

            $statusStr = match ((string) $induk['status']) {
                'V'     => 'Disetujui',
                'X'     => 'Ditolak',
                default => '',
            };
            $jenis    = $db->table('jenis_konket')->where('old_id', (int) $induk['kategori'])->get(1)->getRowArray();
            $approver = in_array($pemroses->role(), [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true) ? 'Admin' : $this->namaAkun($pemroses);
            $baris    = [$induk];

            if ((string) $induk['kategori'] === '8' && (string) ($induk['jenis_dinas'] ?? '') === '1') {
                // Peserta yang sudah dikeluarkan (status 10, v2 menandai — legacy menghapus) tidak dikirimi.
                $baris = array_merge($baris, $db->table('absen_ijin')->where('id_parent', $id)->where('status !=', '10')->orderBy('id', 'ASC')->get()->getResultArray());
            }

            foreach ($baris as $b) {
                $isi = 'Konfirmasi Ketidakhadiran anda pada tanggal ' . TemplatPushRiwayat::tanggal($b['date_start']) . ' s/d ' . TemplatPushRiwayat::tanggal($b['date_end']) . " {$statusStr} oleh {$approver}";
                $this->kirim((string) $b['nip'], 'Konfirmasi Ketidakhadiran', $isi, $b + ['file_bukti_url' => '', 'status_str' => $statusStr, 'jenis' => $jenis]);
            }
        });
    }

    /**
     * Baris LKH bentuk legacy: kolom kegiatan mentah diganti `detail[]` [K `L_lkh.php:344-369`].
     *
     * @param array<string, mixed> $lkh
     *
     * @return array<string, mixed>
     */
    public static function barisLkh(array $lkh, string $statusStr): array
    {
        $kolom = [];

        foreach (self::KOLOM_KEGIATAN as $k) {
            $nilai     = json_decode((string) ($lkh[$k] ?? ''), true);
            $kolom[$k] = is_array($nilai) ? array_values($nilai) : [];
        }

        $detail = [];

        foreach ($kolom['kegiatan'] as $i => $kegiatan) {
            $detail[] = [
                'kegiatan'            => $kegiatan,
                'output'              => $kolom['output'][$i] ?? '',
                'jumlah_diselesaikan' => $kolom['jumlah_diselesaikan'][$i] ?? '',
                'jam_mulai'           => $kolom['jam_mulai'][$i] ?? '',
                'jam_selesai'         => $kolom['jam_selesai'][$i] ?? '',
            ];
        }

        return array_diff_key($lkh, array_flip(self::KOLOM_KEGIATAN)) + ['status_str' => $statusStr, 'file_lckh_url' => '', 'detail' => $detail];
    }

    /**
     * @param array<string, mixed> $baris
     */
    private function kirim(string $nip, string $judul, string $isi, array $baris): void
    {
        $this->antre->antre([$nip], $judul, $isi, [
            'title' => $judul,
            'body'  => $isi,
            'data'  => (string) json_encode($baris, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], 'android');
    }

    private function namaAkun(AuthContext $auth): string
    {
        $akun = $this->db()->table('pengguna')->select('name')->where('id_pengguna', (int) $auth->idPengguna())->get(1)->getRowArray();

        return trim((string) ($akun['name'] ?? ''));
    }

    private function db(): BaseConnection
    {
        return $this->db ?? db_connect();
    }

    private function aman(callable $kerja): void
    {
        if ($this->driver !== 'fcm') {
            return;
        }

        try {
            $kerja();
        } catch (Throwable $e) {
            log_message('warning', 'Push Android LKH/Konket tidak diantre: {pesan}', ['pesan' => $e->getMessage()]);
        }
    }
}
