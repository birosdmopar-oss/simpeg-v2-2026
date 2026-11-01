<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

use App\Interfaces\Layanan\StatusTugasBelajarInterface;
use App\Libraries\Presensi\Tukin\StatusHarian;
use CodeIgniter\Database\BaseConnection;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;

/**
 * Sumber cuti & tugas belajar nyata dari data Fase 4 (D-06, MAKE-039, PRD 5B §7.1) — pengganti
 * {@see SumberCutiTbBelumTersedia} lewat `Config\Presensi::$sumberCutiTb`. Hanya MEMBACA tabel Fase 4; precedence harian
 * tetap di library murni `Presensi/Tukin`.
 *
 * Cuti [K rekap `L_presensi.php:12201-12267`]: `riwayat_cuti` `status = 1` saja (Fase 4 §8) yang beririsan [awal, akhir];
 * setiap tanggal kalender `tgl_mulai..tgl_akhir` di dalam [awal, akhir] (termasuk akhir pekan/libur — library yang
 * melewatinya) mendapat `{id, jenis, mulai, akhir, lama}` (`id` = `id_riwayat_cuti`, untuk keterangan per riwayat, CR-085). `jenis` = kebalikan `StatusHarian::JENIS_CUTI`; `id_jenis_cuti` di
 * luar 1..6 → {@see DataTukinTidakSah} (F5B-17; legacy diam-diam membebaskan). Tumpang tindih pada tanggal sama →
 * baris `id_riwayat_cuti` terbesar menang (F5B-17; legacy: `tgl_mulai` terbesar, seri tak pasti).
 *
 * Tugas belajar: tanggal hasil `StatusTugasBelajarInterface::rentangBebasPresensiBanyak` (aturan Fase 4 `BebasPresensiTb`:
 * TB status 1 + perpanjangan, celah tidak bebas) → `tugas_belajar = true`. Aturan TB tidak disalin ke sini.
 *
 * Jumlah kueri tetap per batch NIP (`riwayat_cuti` satu kueri per {@see self::BATCH_NIP} NIP + kueri TB Fase 4).
 * `untuk()` = `untukBanyak([$nip])[$nip]` (satu jalur aturan); galat jenis cuti hanya menandai NIP itu di varian massal
 * dan dilempar oleh `untuk()`.
 */
final class SumberCutiTbFase4 implements SumberCutiTbInterface, SumberCutiTbMassalInterface, SumberRiwayatCutiInterface
{
    public const BATCH_NIP = 500;

    private readonly BaseConnection $db;

    private readonly StatusTugasBelajarInterface $tugasBelajar;

    public function __construct(?BaseConnection $db = null, ?StatusTugasBelajarInterface $tugasBelajar = null)
    {
        $this->db           = $db ?? db_connect();
        $this->tugasBelajar = $tugasBelajar ?? service('statusTugasBelajar');
    }

    public function untuk(string $nip, string $awal, string $akhir): array
    {
        $hasil = $this->untukBanyak([$nip], $awal, $akhir)[$nip] ?? [];

        if ($hasil instanceof GalatDataTukin) {
            throw $hasil;
        }

        return $hasil;
    }

    public function untukBanyak(array $nip, string $awal, string $akhir): array
    {
        $unik  = array_values(array_unique(array_map('strval', $nip)));
        $hasil = array_fill_keys($unik, []);

        if ($unik === []) {
            return [];
        }

        $galat = [];

        foreach (array_chunk($unik, self::BATCH_NIP) as $batch) {
            [$cuti, $galatBatch] = $this->cuti($batch, $awal, $akhir);
            $galat += $galatBatch;

            foreach ($cuti as $n => $perTanggal) {
                foreach ($perTanggal as $tanggal => $baris) {
                    $hasil[$n][$tanggal]['cuti'] = $baris;
                }
            }
        }

        foreach ($this->tugasBelajar->rentangBebasPresensiBanyak($unik, $awal, $akhir) as $n => $tanggalTb) {
            foreach ($tanggalTb as $tanggal) {
                $hasil[(string) $n][$tanggal]['tugas_belajar'] = true;
            }
        }

        foreach ($hasil as $n => $perTanggal) {
            if (isset($galat[$n])) {
                $hasil[$n] = $galat[$n];

                continue;
            }

            ksort($perTanggal, SORT_STRING);
            $hasil[$n] = $perTanggal;
        }

        return $hasil;
    }

    public function riwayatCutiBanyak(array $nip, string $awal, string $akhir): array
    {
        $jenis = array_flip(StatusHarian::JENIS_CUTI);
        $hasil = [];

        foreach (array_chunk(array_values(array_unique(array_map('strval', $nip))), self::BATCH_NIP) as $batch) {
            foreach ($this->db->table('riwayat_cuti')
                ->select(['id_riwayat_cuti', 'nip', 'id_jenis_cuti', 'tgl_mulai', 'tgl_akhir', 'lama_cuti'])
                ->whereIn('nip', $batch)
                ->where('status', 1)
                ->where('tgl_mulai <=', $akhir)
                ->where('tgl_akhir >=', $awal)
                ->orderBy('nip', 'ASC')->orderBy('tgl_mulai', 'ASC')->orderBy('id_riwayat_cuti', 'ASC')
                ->get()->getResultArray() as $b) {
                $idJenis = (int) $b['id_jenis_cuti'];

                if (isset($jenis[$idJenis])) {
                    $hasil[(string) $b['nip']][] = [
                        'id'    => (int) $b['id_riwayat_cuti'],
                        'jenis' => $jenis[$idJenis],
                        'mulai' => (string) $b['tgl_mulai'],
                        'akhir' => (string) $b['tgl_akhir'],
                        'lama'  => (int) $b['lama_cuti'],
                    ];
                }
            }
        }

        return $hasil;
    }

    /**
     * @param list<string> $nip
     *
     * @return array{0: array<string, array<string, array{id: int, jenis: string, mulai: string, akhir: string, lama: int}>>, 1: array<string, DataTukinTidakSah>}
     *                                                                                                              cuti per NIP per tanggal, dan galat per NIP
     */
    private function cuti(array $nip, string $awal, string $akhir): array
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = $this->db->table('riwayat_cuti')
            ->select(['id_riwayat_cuti', 'nip', 'id_jenis_cuti', 'tgl_mulai', 'tgl_akhir', 'lama_cuti'])
            ->whereIn('nip', $nip)
            ->where('status', 1)
            ->where('tgl_mulai <=', $akhir)
            ->where('tgl_akhir >=', $awal)
            ->orderBy('nip', 'ASC')
            ->orderBy('id_riwayat_cuti', 'ASC')
            ->get()
            ->getResultArray();

        $jenis = array_flip(StatusHarian::JENIS_CUTI);
        $hasil = [];
        $galat = [];

        foreach ($baris as $b) {
            $n  = (string) $b['nip'];
            $id = (int) $b['id_jenis_cuti'];

            if (! isset($jenis[$id])) {
                $galat[$n] ??= new DataTukinTidakSah("Jenis cuti pegawai {$n} tidak dikenal (id_jenis_cuti {$id}); hanya 1..6 yang dihitung untuk Tukin.");

                continue;
            }

            $cuti = [
                'id'    => (int) $b['id_riwayat_cuti'],
                'jenis' => $jenis[$id],
                'mulai' => (string) $b['tgl_mulai'],
                'akhir' => (string) $b['tgl_akhir'],
                'lama'  => (int) $b['lama_cuti'],
            ];

            // Urut id naik: baris belakangan (id terbesar) menimpa tanggal yang sama (F5B-17).
            foreach (self::tanggal(max($cuti['mulai'], $awal), min($cuti['akhir'], $akhir)) as $tanggal) {
                $hasil[$n][$tanggal] = $cuti;
            }
        }

        return [$hasil, $galat];
    }

    /**
     * @return list<string>
     */
    private static function tanggal(string $mulai, string $selesai): array
    {
        if ($mulai > $selesai) {
            return [];
        }

        $hasil = [];

        foreach (new DatePeriod(new DateTimeImmutable($mulai), new DateInterval('P1D'), (new DateTimeImmutable($selesai))->modify('+1 day')) as $hari) {
            $hasil[] = $hari->format('Y-m-d');
        }

        return $hasil;
    }
}
