<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use App\Libraries\Waktu\StempelUtc;
use CodeIgniter\Database\BaseConnection;

/**
 * Status tarik SIASN per NIP per jenis (`siasn_status_pegawai`, padanan `siasn_simpeg.dsp` [K], DBV-027):
 *
 * - {@see self::sinkronDaftar()} = `SS/Data_utama::sync_dsp` [K]: satu baris per pegawai `status = 1` (kecuali NIP `-` dan
 *   `1`; daftar NIP_TESTER tidak dipakai, jawaban B0 Q4); baris pegawai yang tidak aktif lagi dihapus.
 * - Pilih batch: riwayat → flag `0` atau stempel lebih lama dari 6 jam atau kosong [K `SS/Rw_*::get_siasn`]; data utama
 *   → `du = 0` dan `du_lu` sebelum hari ini atau kosong [K `Data_utama::sync_siasn`]. Urut NIP, dibatasi `limit`.
 * - Tandai: proses = `2` + stempel sekarang; selesai = `1` + stempel (data utama: `0` + tanggal hari ini); batal (galat DB)
 *   = `0` [K]. Stempel DATETIME `_lu` dalam UTC; `du_lu` (DATE "per hari") = tanggal bisnis WIB (BP-10, putusan reviewer
 *   B6 (f)) agar batas hari sama dengan legacy (`date("Ymd")` waktu server WIB).
 */
final class StatusTarikSiasn
{
    public const TABEL = 'siasn_status_pegawai';

    public const USANG_DETIK = 6 * 3600;

    private const NIP_DILEWATI = ['-', '1'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return array{tambah: int, hapus: int}
     */
    public function sinkronDaftar(string $sekarang): array
    {
        $aktif = array_column($this->db->table('pegawai')->select('nip')->where('status', 1)
            ->whereNotIn('nip', self::NIP_DILEWATI)->get()->getResultArray(), 'nip', 'nip');
        $ada = array_column($this->db->table(self::TABEL)->select('nip')->get()->getResultArray(), 'nip', 'nip');

        $tambah = array_values(array_diff_key($aktif, $ada));
        $hapus  = array_values(array_diff_key($ada, $aktif));

        foreach (array_chunk($tambah, 500) as $potong) {
            $this->db->table(self::TABEL)->insertBatch(array_map(static fn (string $nip): array => ['nip' => $nip, 'created_at' => $sekarang, 'updated_at' => $sekarang], $potong));
        }

        foreach (array_chunk($hapus, 500) as $potong) {
            $this->db->table(self::TABEL)->whereIn('nip', $potong)->delete();
        }

        return ['tambah' => count($tambah), 'hapus' => count($hapus)];
    }

    /**
     * @return list<string>
     */
    public function pilih(JenisTarikSiasn $jenis, int $limit, ?string $nip, string $sekarang): array
    {
        $k  = $jenis->kolomStatus();
        $qb = $this->db->table(self::TABEL . ' s')->select('s.nip')
            ->join('pegawai p', 'p.nip = s.nip', 'inner')
            ->where('p.status', 1);

        if ($jenis->jenisPegawai() !== null) {
            $qb->whereIn('p.id_jenis_pegawai', $jenis->jenisPegawai());
        }

        if ($nip !== null) {
            // Tarik manual satu NIP: tanpa syarat usang.
            $qb->where('s.nip', $nip);
        } elseif ($k === 'du') {
            $hariIni = (string) StempelUtc::tanggalWib($sekarang);
            $qb->where('s.du', 0)->groupStart()->where('s.du_lu <', $hariIni)->orWhere('s.du_lu', null)->groupEnd();
        } else {
            $batas = gmdate('Y-m-d H:i:s', (int) strtotime($sekarang . ' UTC') - self::USANG_DETIK);
            $qb->groupStart()->where("s.{$k}", 0)->orWhere("s.{$k}_lu <", $batas)->orWhere("s.{$k}_lu", null)->groupEnd();
        }

        return array_map('strval', array_column($qb->orderBy('s.nip', 'ASC')->limit($limit)->get()->getResultArray(), 'nip'));
    }

    /**
     * @param list<string> $nip
     */
    public function tandaiProses(JenisTarikSiasn $jenis, array $nip, string $sekarang): void
    {
        $k = $jenis->kolomStatus();
        $this->tandai($nip, $k === 'du' ? ['du' => 2] : [$k => 2, "{$k}_lu" => $sekarang], $sekarang);
    }

    /**
     * @param list<string> $nip
     */
    public function tandaiSelesai(JenisTarikSiasn $jenis, array $nip, string $sekarang): void
    {
        $k = $jenis->kolomStatus();
        $this->tandai($nip, $k === 'du' ? ['du' => 0, 'du_lu' => (string) StempelUtc::tanggalWib($sekarang)] : [$k => 1, "{$k}_lu" => $sekarang], $sekarang);
    }

    /**
     * @param list<string> $nip
     */
    public function tandaiBatal(JenisTarikSiasn $jenis, array $nip, string $sekarang): void
    {
        $k = $jenis->kolomStatus();
        $this->tandai($nip, $k === 'du' ? ['du' => 0] : [$k => 0, "{$k}_lu" => $sekarang], $sekarang);
    }

    /**
     * @param list<string>         $nip
     * @param array<string, mixed> $data
     */
    private function tandai(array $nip, array $data, string $sekarang): void
    {
        if ($nip === []) {
            return;
        }

        $this->db->table(self::TABEL)->whereIn('nip', $nip)->update($data + ['updated_at' => $sekarang]);
    }
}
