<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Stat;

/**
 * MAKE-061 (H-08, F6-09) — deteksi rekomendasi kenaikan gaji berkala, port PERSIS [K] `libraries/hr/L_employee.php`
 * `get_all_kgb()` :4095-4220 (dipakai `services/s_stat/rekomendasi_kgb`). Murni: "hari ini" diinjeksi.
 *
 * Aturan [K]:
 * - Populasi: pegawai status 1, `id_jenis_pegawai` ∈ {1, 6} (disaring pemanggil/service).
 * - Acuan terakhir: `pegawai_kgb.tmtsk` (KGB terakhir); PNS → dibandingkan `pegawai_cpns.tmtsk`, PPPK (6) → dibandingkan
 *   `pegawai_mutasi_jabatan.tmtsk`; yang lebih baru menang (:4142-4170). Q1: baris `pegawai_kgb` = snapshot KGB terbaru.
 * - Selisih bulan `y*12 + m` dari acuan ke hari ini: > 36 → acuan + 4 tahun, lalu + 2 tahun sampai ≥ hari ini; 24–36 →
 *   hari ini; < 24 → acuan + 2 tahun (:4175-4192). `kgb_th`/`kgb_bl` = selisih acuan → hari ini.
 * - `gol_ruang`: PPPK memakai `pmj.gol_pppk`, PNS `pegawai_kp.gol_ruang` (:4132).
 *
 * Catatan [V2] kecil: legacy membandingkan `DateTime` berjam (`new DateTime()`), v2 memakai tanggal bisnis tanpa jam (Q4);
 * hasil hanya dapat berbeda bila tanggal acuan + 4/+2 tahun jatuh tepat hari ini (legacy menambah 2 tahun lagi karena
 * jam 00:00 < jam sekarang; v2 berhenti di hari ini).
 *
 * Perbedaan dengan `Kepegawaian\Kalkulasi\KenaikanGajiBerkala::proyeksiBerikutnya()` (acuan pendahulu per TMT baru,
 * tanpa siklus +4/+2 bertingkat) dicatat di README; tidak disamakan (PRD §7.6).
 */
final class RekomendasiKgb
{
    public const JENIS_PPPK = '6';

    public function __construct(private readonly string $hariIni)
    {
        KalenderStat::parse($hariIni);
    }

    /**
     * @param array<string, mixed> $r `id_jenis_pegawai`, `tmtsk_kgb` (KGB terakhir), `no_sk_last_kgb`, `gaji_last_kgb`,
     *                                `tmtsk_cpns`, `tmtsk_pmj`
     *
     * @return array{tmtsk_kgb: string, kgb_th: int|string, kgb_bl: int|string, no_sk_last_kgb: mixed, tmtsk_last_kgb: string, gaji_last_kgb: mixed}
     */
    public function hitung(array $r): array
    {
        $hasil = ['tmtsk_kgb' => '', 'kgb_th' => '', 'kgb_bl' => '', 'no_sk_last_kgb' => '', 'tmtsk_last_kgb' => '', 'gaji_last_kgb' => ''];
        $last  = '';

        if (KalenderStat::valid((string) ($r['tmtsk_kgb'] ?? ''))) {
            $last = (string) $r['tmtsk_kgb'];
            // S5 [K LE:4141-4143]: nilai kolom disalin apa adanya — NULL tetap null, bukan "".
            $hasil['no_sk_last_kgb'] = $r['no_sk_last_kgb'] ?? null;
            $hasil['tmtsk_last_kgb'] = $last;
            $hasil['gaji_last_kgb']  = $r['gaji_last_kgb'] ?? null;
        }

        $pembanding = (string) ($r['id_jenis_pegawai'] ?? '') === self::JENIS_PPPK
            ? (string) ($r['tmtsk_pmj'] ?? '')
            : (string) ($r['tmtsk_cpns'] ?? '');

        if (KalenderStat::valid($pembanding) && ($last === '' || $pembanding > $last)) {
            $last = $pembanding;
        }

        if ($last === '') {
            return $hasil;
        }

        $dtNow   = KalenderStat::parse($this->hariIni);
        $dtLast  = KalenderStat::parse($last);
        $selisih = KalenderStat::selisihBulan($dtNow, $dtLast);

        if ($selisih > 36) {
            $dt = $dtLast->modify('+4 year');

            while ($dt < $dtNow) {
                $dt = $dt->modify('+2 year');
            }

            $hasil['tmtsk_kgb'] = $dt->format('Y-m-d');
        } elseif ($selisih >= 24) {
            $hasil['tmtsk_kgb'] = $this->hariIni;
        } else {
            $hasil['tmtsk_kgb'] = $dtLast->modify('+2 year')->format('Y-m-d');
        }

        $d               = $dtNow->diff($dtLast);
        $hasil['kgb_th'] = $d->y;
        $hasil['kgb_bl'] = $d->m;

        return $hasil;
    }
}
