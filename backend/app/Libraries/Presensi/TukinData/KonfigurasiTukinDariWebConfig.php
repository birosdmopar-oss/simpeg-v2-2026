<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

use App\Libraries\MasterData\WebConfigService;
use App\Libraries\Presensi\Tukin\KonfigurasiTukin;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use InvalidArgumentException;

/**
 * `KonfigurasiTukin` dari `web_config` (PRD 5A §7.7.3, F5-16; D-05/D-07 bagian integrasi).
 *
 * - Tarif wajib `TL1/PSW1`, `TL2/PSW2`, `TL3/PSW3`, `TA`, `TK` (G-09, persen desimal ≤ 2 digit). Satu saja belum diatur
 *   (null) → {@see KonfigurasiTukinTidakLengkap}. Tarif lain = `KonfigurasiTukin::TARIF_BAWAAN` [K rekap]; `LKH` tidak
 *   dipakai (rekap tanpa LKH).
 * - `WebConfigService` mengembalikan decimal sebagai float, sedangkan `KonfigurasiTukin` menolak float (konversi tepat
 *   ke basis poin lewat string): nilai dikembalikan ke string kanonik ≤ 2 desimal (tepat untuk nilai tersimpan G-09).
 * - Jam kerja: 6 key `time` F5-16 (HH:MM:SS).
 * - Puasa: rentang `rentang_puasa` yang beririsan dengan [awal, akhir] periode; tidak ada → tanpa puasa; lebih dari
 *   satu → {@see RentangPuasaGanda}.
 * - Cache `WebConfigService` dipakai apa adanya (diinvalidasi saat tulis), jadi perubahan tarif langsung berlaku
 *   (AC-TK.4).
 */
final class KonfigurasiTukinDariWebConfig
{
    /** key web_config jam kerja (F5-16) sesuai urutan argumen konstruktor `KonfigurasiTukin`. */
    public const KUNCI_JAM = [
        'jam_masuk_normal', 'jam_pulang_normal', 'jam_pulang_jumat_normal',
        'jam_masuk_puasa', 'jam_pulang_puasa', 'jam_pulang_jumat_puasa',
    ];

    public const KUNCI_PUASA = 'rentang_puasa';

    public function __construct(private readonly WebConfigService $webConfig)
    {
    }

    public function untuk(PeriodeTukin $periode): KonfigurasiTukin
    {
        $nilai = $this->webConfig->values();

        $tarif  = [];
        $kosong = [];

        foreach (KonfigurasiTukin::KUNCI_WAJIB as $kunci) {
            $v = $nilai[$kunci] ?? null;

            if (! is_int($v) && ! is_float($v)) {
                $kosong[] = $kunci;

                continue;
            }

            $tarif[$kunci] = self::persen($v);
        }

        if ($kosong !== []) {
            throw new KonfigurasiTukinTidakLengkap($kosong);
        }

        $jam = [];

        foreach (self::KUNCI_JAM as $kunci) {
            $jam[] = (string) ($nilai[$kunci] ?? '');
        }

        $puasa = self::rentangPuasa($nilai[self::KUNCI_PUASA] ?? [], $periode);

        try {
            return new KonfigurasiTukin($tarif, ...[...$jam, $puasa['mulai'] ?? null, $puasa['selesai'] ?? null]);
        } catch (InvalidArgumentException $e) {
            throw new DataTukinTidakSah('Konfigurasi Tukin di Web Config tidak sah: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Persen dari `web_config` (int/float, ≤ 2 desimal) → string desimal kanonik untuk `KonfigurasiTukin`.
     */
    public static function persen(float|int $nilai): string
    {
        if (is_int($nilai)) {
            return (string) $nilai;
        }

        $teks = rtrim(rtrim(number_format($nilai, 2, '.', ''), '0'), '.');

        return $teks === '-0' ? '0' : $teks;
    }

    /**
     * @param mixed $rentang nilai bertipe `date_range_list`
     *
     * @return array{mulai: string, selesai: string}|null
     */
    public static function rentangPuasa(mixed $rentang, PeriodeTukin $periode): ?array
    {
        $beririsan = [];

        foreach (is_array($rentang) ? $rentang : [] as $r) {
            if (is_array($r) && isset($r['mulai'], $r['selesai']) && $r['mulai'] <= $periode->akhir && $r['selesai'] >= $periode->awal) {
                $beririsan[] = ['mulai' => (string) $r['mulai'], 'selesai' => (string) $r['selesai']];
            }
        }

        if (count($beririsan) > 1) {
            throw new RentangPuasaGanda($periode->awal, $periode->akhir);
        }

        return $beririsan[0] ?? null;
    }
}
