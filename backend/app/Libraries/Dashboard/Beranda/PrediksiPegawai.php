<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Beranda;

use App\Constants\Role;
use App\Libraries\Integrasi\Stat\KalenderStat;
use App\Libraries\Integrasi\Stat\PenyajiStat;
use App\Libraries\Integrasi\Stat\RekomendasiKgb;
use App\Libraries\Integrasi\Stat\RekomendasiKp;
use App\Libraries\Integrasi\Stat\RekomendasiPensiun;
use App\Libraries\Kepegawaian\AngkaKredit\AkPermenpanrb;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Kartu prediksi beranda pegawai (`views/user/dashboard_pns.php:40-96`, `dashboard_pppk.php:45-54`) — `L_user::dashboard_pns`
 * :705-838 dan `dashboard_pppk` [K]:
 * - role 2: Pensiun (`get_pensiun`: BUP dari jabatan, perlu snapshot jabatan), Naik Pangkat (JF bila `pmj.id_group_jabatan` = 2
 *   → AK `getAKAccu` = {@see AkPermenpanrb}; selain itu port `next_kp` {@see self::kpBerikutnya()} + "pangkat puncak"), Tanda
 *   Jasa (`next_tj` = {@see ProyeksiTandaJasa::prediksiPegawai()}), KGB (`next_kgb` = {@see RekomendasiKgb});
 * - role 7: Pensiun = akhir masa perjanjian kerja `pmj.mhpk_akhir`, KGB (view PPPK hanya dua kartu itu);
 * - role 6: tanpa kartu.
 * KP, TJ, KGB hanya bila tanggalnya sebelum tanggal pensiun (atau pensiun tidak diketahui). `sisa` = selisih mutlak hari ini
 * ke tanggal ("N Tahun M Bulan", legacy `DateTime::diff`); TJ yang sudah lewat → `sisa` '-'. `teks` = "Bulan Tahun".
 */
final class PrediksiPegawai
{
    public const ID_GROUP_JF = 2;

    private const BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(private readonly BaseConnection $db, private readonly string $hariIni, private readonly ?AkPermenpanrb $ak = null)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function kartu(int $role, string $nip): array
    {
        if (! in_array($role, [Role::PEGAWAI, Role::PPPK], true)) {
            return [];
        }

        $p = $this->db->table('pegawai peg')
            ->select('peg.nip, peg.id_jenis_pegawai, peg.tgl_lahir, '
                . 'pmj.nip AS pmj_nip, pmj.id_group_jabatan, pmj.id_sub_group_jabatan, pmj.tmtsk AS tmtsk_pmj, pmj.mhpk_akhir, pmj.gol_pppk, '
                . 'jab.umur_pensiun, pkp.nip AS kp_nip, pkp.gol_ruang, pkp.tmtsk AS tmtsk_pangkat, pkp.id_jenis_kp, '
                . 'ppen.jenjang_pendidikan_singkat, pcpns.tmtsk AS tmtsk_cpns, pkgb.tmtsk AS tmtsk_kgb')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'left')
            ->join('jabatan jab', 'jab.id_jabatan = pmj.id_jabatan', 'left')
            ->join('pegawai_kp pkp', 'pkp.nip = peg.nip', 'left')
            ->join('pegawai_pendidikan ppen', 'ppen.nip = peg.nip', 'left')
            ->join('pegawai_cpns pcpns', 'pcpns.nip = peg.nip', 'left')
            ->join('pegawai_kgb pkgb', 'pkgb.nip = peg.nip', 'left')
            ->where('peg.nip', $nip)
            ->get()->getRowArray();

        if ($p === null) {
            return [];
        }

        $pensiun = $role === Role::PPPK ? self::tanggalValid($p['mhpk_akhir'] ?? null) : $this->tanggalPensiun($p);
        $kartu   = [$this->kartuTanggal('pensiun', 'Pensiun', $pensiun, true)];

        if ($role === Role::PEGAWAI) {
            $kartu[] = $this->kartuKp($p, $pensiun);
            $tj      = ProyeksiTandaJasa::prediksiPegawai($p['tmtsk_cpns'] ?? null, $this->hariIni, $this->tandaJasaDimiliki($nip));
            $kartu[] = $this->kartuTanggal('tj', 'Tanda Jasa', $this->sebelumPensiun($tj['tgl_penghargaan'] ?? null, $pensiun), false);
        }

        $kgb     = (new RekomendasiKgb($this->hariIni))->hitung($p)['tmtsk_kgb'];
        $kartu[] = $this->kartuTanggal('kgb', 'KGB', $this->sebelumPensiun($kgb === '' ? null : $kgb, $pensiun), true);

        return $kartu;
    }

    /**
     * @param array<string, mixed> $p
     */
    private function tanggalPensiun(array $p): ?string
    {
        if (($p['pmj_nip'] ?? null) === null) {
            return null; // [K] get_pensiun: tanpa snapshot jabatan → tgl_pensiun ''
        }

        $tgl = (new RekomendasiPensiun($this->hariIni))->hitung($p['tgl_lahir'] ?? null, $p['umur_pensiun'] ?? null, $p['id_group_jabatan'] ?? null, $p['id_sub_group_jabatan'] ?? null)['tgl_pensiun'];

        return $tgl === '' ? null : $tgl;
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array<string, mixed>
     */
    private function kartuKp(array $p, ?string $pensiun): array
    {
        if ((int) ($p['id_group_jabatan'] ?? 0) === self::ID_GROUP_JF) {
            $ak = ($this->ak ?? new AkPermenpanrb($this->db))->hitung((string) $p['nip']);

            return [
                'kode' => 'kp', 'label' => 'Naik Pangkat', 'jenis' => 'JF', 'tanggal' => null, 'teks' => null, 'sisa' => null, 'pangkat_puncak' => false,
                'ak'   => ['sekarang' => (float) $ak['current_ak'], 'target' => (float) $ak['ak_for_kp'], 'kurang' => (float) $ak['ak_kurang']],
            ];
        }

        $golRuang = (string) ($p['gol_ruang'] ?? '');
        $strata   = (string) ($p['jenjang_pendidikan_singkat'] ?? '');
        $eselon   = PenyajiStat::eselon($p['id_sub_group_jabatan'] ?? null);
        $punyaKp  = ($p['kp_nip'] ?? null) !== null;
        $tglKp    = null;

        if ($punyaKp) {
            $sub   = ($p['id_sub_group_jabatan'] ?? null) === null ? null : (int) $p['id_sub_group_jabatan'];
            $tglKp = self::kpBerikutnya(
                $golRuang,
                (string) ($p['tmtsk_pangkat'] ?? ''),
                (string) ($p['id_jenis_kp'] ?? ''),
                $strata,
                $eselon,
                $sub === null ? null : $this->tmtStrukturalTerawal((string) $p['nip'], $sub),
            );
        }

        $kartu = $this->kartuTanggal('kp', 'Naik Pangkat', $this->sebelumPensiun($tglKp, $pensiun), true);

        return $kartu + ['jenis' => 'ST', 'pangkat_puncak' => $punyaKp && ! self::bisaNaik($golRuang, $strata, $eselon), 'ak' => null];
    }

    /**
     * Port `L_employee::next_kp` (:1882-2054) [K] — BUKAN `get_all_kp`/`RekomendasiKp::deteksi` (yang hanya mendeteksi KP tahun
     * berjalan): reguler = TMT pangkat terakhir + 4 tahun (3 bila `id_jenis_kp` = 2) dibulatkan ke 1 April (bulan ≤ 4) /
     * 1 Oktober (≤ 10) / 1 April tahun berikutnya, hanya bila {@see self::bisaNaik()}; percepatan struktural = TMT jabatan
     * struktural terawal (sub grup sama) + 1 tahun dibulatkan ke periode berikutnya, bila golongan di bawah minimum eselon
     * (tanpa penjaga `gol_ruang` kosong, legacy); yang lebih awal menang.
     */
    private static function kpBerikutnya(string $golRuang, string $tmtPangkat, string $idJenisKp, string $strata, string $eselon, ?string $tmtStruktural): ?string
    {
        $tgl = null;

        if (self::bisaNaik($golRuang, $strata, $eselon)) {
            $periode = $idJenisKp === '2' ? 3 : 4;
            $tahun   = KalenderStat::tahunLegacy($tmtPangkat);
            $bulan   = KalenderStat::bulanLegacy($tmtPangkat);
            $tgl     = $bulan <= 4 ? sprintf('%04d-04-01', $tahun + $periode) : ($bulan <= 10 ? sprintf('%04d-10-01', $tahun + $periode) : sprintf('%04d-04-01', $tahun + $periode + 1));
        }

        $minimum    = RekomendasiKp::MIN_ESELON[$eselon] ?? null;
        $percepatan = $minimum !== null && (RekomendasiKp::URUT_PANGKAT[$golRuang] ?? null) < RekomendasiKp::URUT_PANGKAT[$minimum];

        if ($percepatan && $tmtStruktural !== null && KalenderStat::valid($tmtStruktural)) {
            $dt   = KalenderStat::parse($tmtStruktural)->modify('+1 year');
            $per1 = KalenderStat::parse($dt->format('Y') . '-04-01');
            $per2 = KalenderStat::parse($dt->format('Y') . '-10-01');
            $perc = $dt <= $per1 ? $per1->format('Y-m-d') : ($dt <= $per2 ? $per2->format('Y-m-d') : $per1->modify('+1 year')->format('Y-m-d'));
            $tgl  = $tgl === null || $perc < $tgl ? $perc : $tgl;
        }

        return $tgl;
    }

    /**
     * `next_kp` `$isNaikPangkat` (L_employee.php:1950-1975): di bawah plafon reguler per strata, atau di bawah plafon eselon.
     */
    private static function bisaNaik(string $golRuang, string $strata, string $eselon): bool
    {
        $urut = RekomendasiKp::URUT_PANGKAT[$golRuang] ?? null;
        $maks = RekomendasiKp::URUT_PANGKAT[RekomendasiKp::PLAFON_REGULER[$strata] ?? ''] ?? 0;

        if ($urut < $maks) { // [K] null < n bernilai true untuk n > 0 (gol_ruang tak dikenal)
            return true;
        }

        $plafon = RekomendasiKp::PLAFON_ESELON[$eselon][$strata] ?? null;

        return $plafon !== null && $urut < RekomendasiKp::URUT_PANGKAT[$plafon];
    }

    /**
     * @return array{kode: string, label: string, tanggal: string|null, teks: string|null, sisa: string|null}
     */
    private function kartuTanggal(string $kode, string $label, ?string $tanggal, bool $sisaMutlak): array
    {
        if ($tanggal === null) {
            return ['kode' => $kode, 'label' => $label, 'tanggal' => null, 'teks' => null, 'sisa' => null];
        }

        $sisa = ! $sisaMutlak && $tanggal <= $this->hariIni ? '-' : self::selisih($this->hariIni, $tanggal);

        return [
            'kode' => $kode, 'label' => $label, 'tanggal' => $tanggal,
            'teks' => self::BULAN[(int) substr($tanggal, 5, 2)] . ' ' . substr($tanggal, 0, 4),
            'sisa' => $sisa,
        ];
    }

    private function sebelumPensiun(?string $tanggal, ?string $pensiun): ?string
    {
        if ($tanggal === null) {
            return null;
        }

        return $pensiun === null || $tanggal < $pensiun ? $tanggal : null;
    }

    private static function selisih(string $dari, string $ke): string
    {
        $d      = (new DateTimeImmutable($dari))->diff(new DateTimeImmutable($ke));
        $bagian = [];

        if ($d->y > 0) {
            $bagian[] = $d->y . ' Tahun';
        }

        if ($d->m > 0) {
            $bagian[] = $d->m . ' Bulan';
        }

        return implode(' ', $bagian);
    }

    private static function tanggalValid(mixed $nilai): ?string
    {
        return is_string($nilai) && KalenderStat::valid(substr($nilai, 0, 10)) ? substr($nilai, 0, 10) : null;
    }

    /**
     * `next_kp` :2001: riwayat jabatan berstatus 1 dengan sub grup yang sama, TMT paling awal.
     */
    private function tmtStrukturalTerawal(string $nip, int $sub): ?string
    {
        $r = $this->db->table('riwayat_mutasi_jabatan')->select('tmtsk')
            ->where('nip', $nip)->where('id_sub_group_jabatan', $sub)->where('status', 1)
            ->orderBy('tmtsk', 'ASC')->get(1)->getRowArray();

        return $r === null || $r['tmtsk'] === null ? null : (string) $r['tmtsk'];
    }

    /**
     * @return list<int>
     */
    private function tandaJasaDimiliki(string $nip): array
    {
        return array_map(static fn (array $r): int => (int) $r['id_tanda_jasa'], $this->db->table('riwayat_tanda_jasa')->select('id_tanda_jasa')
            ->where('nip', $nip)
            ->whereIn('id_tanda_jasa', [ProyeksiTandaJasa::ID_XXX, ProyeksiTandaJasa::ID_XX, ProyeksiTandaJasa::ID_X])
            ->where('status', 1)
            ->get()->getResultArray());
    }
}
