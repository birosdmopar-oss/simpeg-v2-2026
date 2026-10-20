<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

use App\Libraries\Presensi\Tukin\PeriodeTukin;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Periode Tukin dari `bkn_periode_ekinper` (PRD 5A §7.7.4, D-04 bagian integrasi), pemetaan rekap legacy
 * `L_presensi.php:11852-11876` [K]:
 *   - baris `tahun` = T, `bulan` = MM (dua digit), `jenis_periode IS NULL` (tanpa saring `status`, sama dengan legacy);
 *   - awal = `T-{periode_awal}`, akhir = `T-{periode_akhir}` (`periode_awal/akhir` berformat `MM-DD`);
 *   - Januari: awal selalu 16 Desember tahun sebelumnya (:11859-11862; `PeriodeTukin::dariBulan` menegakkan hal sama);
 *   - tidak ada baris → bawaan 16 bulan lalu s.d. 15 bulan berjalan (:11869-11874).
 * Baris ganda untuk satu bulan atau `periode_*` tidak berformat `MM-DD` → {@see DataTukinTidakSah} (fail-closed; legacy
 * mengambil baris pertama tanpa urutan).
 */
final class SumberPeriodeTukin
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return array{id: string, awal: string, akhir: string}|null
     */
    public function barisBkn(int $tahun, int $bulan): ?array
    {
        /** @var list<array<string, mixed>> $baris */
        $baris = $this->db->table('bkn_periode_ekinper')
            ->select(['id', 'periode_awal', 'periode_akhir'])
            ->where('tahun', $tahun)
            ->where('bulan', sprintf('%02d', $bulan))
            ->where('jenis_periode', null)
            ->get(2)
            ->getResultArray();

        if ($baris === []) {
            return null;
        }

        if (count($baris) > 1) {
            throw new DataTukinTidakSah(sprintf('Periode BKN %04d-%02d tercatat lebih dari satu baris (jenis_periode kosong).', $tahun, $bulan));
        }

        $awal  = (string) ($baris[0]['periode_awal'] ?? '');
        $akhir = (string) ($baris[0]['periode_akhir'] ?? '');

        if (preg_match('/^\d{2}-\d{2}\z/', $awal) !== 1 || preg_match('/^\d{2}-\d{2}\z/', $akhir) !== 1) {
            throw new DataTukinTidakSah(sprintf('Periode BKN %04d-%02d tidak berformat MM-DD.', $tahun, $bulan));
        }

        return [
            'id'    => (string) $baris[0]['id'],
            'awal'  => $bulan === 1 ? sprintf('%04d-12-16', $tahun - 1) : "{$tahun}-{$awal}",
            'akhir' => "{$tahun}-{$akhir}",
        ];
    }

    /**
     * @param array{id: string, awal: string, akhir: string}|null $bkn hasil {@see self::barisBkn()}
     */
    public static function periode(int $tahun, int $bulan, ?array $bkn): PeriodeTukin
    {
        try {
            return PeriodeTukin::dariBulan($tahun, $bulan, $bkn === null ? null : ['awal' => $bkn['awal'], 'akhir' => $bkn['akhir']]);
        } catch (InvalidArgumentException $e) {
            throw new DataTukinTidakSah(sprintf('Periode Tukin %04d-%02d tidak sah: %s', $tahun, $bulan, $e->getMessage()), 0, $e);
        }
    }
}
