<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use App\Exceptions\ApiException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\MasterData\WebConfigService;
use App\Libraries\Pdf\Pdf;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Presensi;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Rekap Tukin (D-08, MAKE-041, PRD 5B §6.1, §7.3.3–7.3.5) — port `laporan_tukin_us_skp` / `_il` (`Presensi.php:488-635`,
 * `L_presensi.php:11752-15450`) legacy `0cf7e67`, varian `skp` saja (F5B-4). Role route 1, 3 (role 3 dipaksa satkernya
 * lewat {@see CakupanLaporan}, menutup celah legacy yang hanya membatasi di JS, L5B-02); IL hanya role 1 (F5B-6).
 *
 * - Populasi {@see PopulasiRekap::dh()} atas **periode Tukin** (awal/akhir BKN atau 16–15): status 1 atau (2 & `tmt_status >`
 *   awal periode), `DATE(created_at) ≤` akhir periode, jenis pegawai, jabatan berlaku per akhir periode; IL
 *   {@see PopulasiRekap::il()} [K `:13802-13831`].
 * - Baris = {@see KalkulasiLaporanTukin} (sama dengan laporan per pegawai, K-1): `pengurangan {TL, PSW, TK, TA, CUTI, TB}`
 *   (TB selalu 0 [K]), `total_presensi`, `total_skp`, `total_potongan` (persen dua desimal), `keterangan`, `penyesuaian`;
 *   pegawai yang gagal dihitung tetap tampil dengan `galat {code, message}` [V2]. JSON: kalkulasi hanya untuk halaman.
 * - `bulan` `YYYY-MM` 2024-04 s.d. bulan berjalan (setelah tanggal 15: bulan depan) [K `Presensi.php:90-106`]; sebelum
 *   2024-04 → 422 `periode_tidak_didukung` (F5B-4). Periode berjalan dipotong hari ini WIB.
 * - XLSX seluruh lingkup ({@see EksporTukinXlsx}); PDF hanya lingkup unit/satker ≤ `Config\Presensi::$pdfRekapMaksBaris`
 *   (dihitung sebelum kalkulasi) [V2, F5B-16]. Ekspor tidak diaudit (F5B-11).
 */
final class RekapTukinService
{
    public const BULAN_MINIMUM = '2024-04';

    public const PESAN_PERIODE = 'Laporan periode sebelum April 2024 tersedia di arsip SIMPEG lama.';

    private readonly BaseConnection $db;

    /** @var Closure(): DateTimeInterface */
    private readonly Closure $jam;

    private readonly KalkulasiLaporanTukin $kalkulasi;

    /**
     * @param (Closure(): DateTimeInterface)|null $jam instan sekarang (bawaan jam server); disuntik test
     */
    public function __construct(
        ?BaseConnection $db = null,
        ?Closure $jam = null,
        ?KalkulasiLaporanTukin $kalkulasi = null,
        private readonly ?WebConfigService $webConfig = null,
        private readonly ?Presensi $config = null,
    ) {
        $this->db        = $db ?? db_connect();
        $this->jam       = $jam ?? static fn (): DateTimeInterface => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->kalkulasi = $kalkulasi ?? new KalkulasiLaporanTukin($this->db);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function daftar(AuthContext $auth, array $query, bool $il = false): array
    {
        [$p, $lingkup, $periode, $populasi] = $this->siapkan($auth, $query, $il);
        $halaman                            = array_slice($populasi, ($p->page - 1) * $p->perPage, $p->perPage);
        $baris                              = $this->baris($halaman, $p, $il);

        return [
            'items'        => $baris,
            'total'        => count($populasi),
            'page'         => $p->page,
            'per_page'     => $p->perPage,
            'bulan'        => $p->kunci(),
            'periode'      => ['awal' => $periode->awal, 'akhir' => $periode->akhir],
            'lingkup'      => $lingkup->toArray(),
            'jumlah_galat' => count(array_filter($baris, static fn (array $b): bool => $b['galat'] !== null)),
        ];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{isi: string, nama: string, mime: string}
     */
    public function xlsx(AuthContext $auth, array $query, bool $il = false): array
    {
        [$p, $lingkup, , $populasi] = $this->siapkan($auth, $query, $il);

        return [
            'isi'  => EksporTukinXlsx::rekap($this->judul($p, $il), $lingkup->nama, $p->label(), $this->baris($populasi, $p, $il), $this->penandaTangan($lingkup)),
            'nama' => sprintf('laporan_tukin_%s_%s.xlsx', $il ? 'il' : $lingkup->kode(), $p->kunci()),
            'mime' => EksporRekapXlsx::MIME,
        ];
    }

    /**
     * PDF rekap [V2, F5B-16]: hanya lingkup unit/satker, maksimal `$pdfRekapMaksBaris` baris.
     *
     * @param array<string, mixed> $query
     *
     * @return array{isi: string, nama: string, mime: string}
     */
    public function pdf(AuthContext $auth, array $query): array
    {
        [$p, $lingkup, , $populasi] = $this->siapkan($auth, $query, false);
        $maks                       = ($this->config ?? config(Presensi::class))->pdfRekapMaksBaris;

        if ($lingkup->tipe === LingkupLaporan::KEMENTERIAN || count($populasi) > $maks) {
            throw new ApiException('Persempit lingkup atau unduh XLSX.', 422, ['unit_satker' => ['Persempit lingkup atau unduh XLSX.']]);
        }

        $html = view('pdf/rekap_tukin', [
            'judul'         => $this->judul($p, false),
            'lingkup'       => $lingkup->nama,
            'periode'       => $p->label(),
            'baris'         => $this->baris($populasi, $p),
            'penandaTangan' => $this->penandaTangan($lingkup),
        ], ['saveData' => false]);

        return [
            'isi'  => Pdf::renderer()->render(new \App\Libraries\Pdf\DokumenPdf($this->judul($p, false) . ' ' . $p->label(), $html, orientasi: 'L', marginAtasKop: 12.0, marginSisi: 10.0)),
            'nama' => sprintf('laporan_tukin_%s_%s.pdf', $lingkup->kode(), $p->kunci()),
            'mime' => 'application/pdf',
        ];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{0: ParameterRekap, 1: LingkupLaporan, 2: PeriodeTukin, 3: list<array<string, mixed>>}
     */
    private function siapkan(AuthContext $auth, array $query, bool $il): array
    {
        $p       = self::parameter($query, $this->hariIni(), $this->db, ! $il);
        $periode = $this->kalkulasi->periode($p->tahun, $p->bulan);

        if ($il) {
            $nama     = (new CakupanLaporan($this->db, $this->webConfig))->lingkup($auth, '0')->nama;
            $lingkup  = new LingkupLaporan(LingkupLaporan::KEMENTERIAN, null, $nama);
            $populasi = (new PopulasiRekap($this->db))->il($periode->awal);
        } else {
            $lingkup  = (new CakupanLaporan($this->db, $this->webConfig))->lingkup($auth, $p->unitSatker);
            $populasi = (new PopulasiRekap($this->db))->dh($periode->awal, $periode->akhir, $lingkup, $p->jenisPegawai);
        }

        return [$p, $lingkup, $periode, $populasi];
    }

    /**
     * Parameter laporan Tukin: `bulan` 2024-04 s.d. bulan berjalan (setelah tanggal 15: bulan depan).
     *
     * @param array<string, mixed> $query
     */
    public static function parameter(array $query, string $hariIni, BaseConnection $db, bool $denganJenis = true): ParameterRekap
    {
        $maks = (int) substr($hariIni, 8, 2) > 15 ? substr(TanggalBisnis::tambahBulan(substr($hariIni, 0, 7) . '-01', 1), 0, 7) : substr($hariIni, 0, 7);
        $p    = ParameterRekap::dari($query, $hariIni, $db, $denganJenis, $maks);

        if ($p->kunci() < self::BULAN_MINIMUM) {
            throw new ApiException(self::PESAN_PERIODE, 422, ['bulan' => [self::PESAN_PERIODE]], kode: 'periode_tidak_didukung');
        }

        return $p;
    }

    /**
     * @param list<array<string, mixed>> $populasi
     *
     * @return list<array<string, mixed>>
     */
    private function baris(array $populasi, ParameterRekap $p, bool $il = false): array
    {
        $baris = [];

        // Per batch (`$tukinBatchNip`): argumen & hasil harian satu batch dilepas sebelum batch berikutnya (memori, ADR-035).
        foreach (array_chunk($populasi, max(1, ($this->config ?? config(Presensi::class))->tukinBatchNip)) as $potong) {
            array_push($baris, ...$this->barisBatch($potong, $p, $il));
        }

        return $baris;
    }

    /**
     * @param list<array<string, mixed>> $populasi
     *
     * @return list<array<string, mixed>>
     */
    private function barisBatch(array $populasi, ParameterRekap $p, bool $il = false): array
    {
        // IL: tanpa aturan pegawai baru [K `laporan_tukin_us_skp_il` :13494-15452, CR-083, keputusan Horii 30-10].
        $nip   = array_map(static fn (array $peg): string => (string) $peg['nip'], $populasi);
        $hasil = $nip === [] ? [] : $this->kalkulasi->hitung($nip, $p->tahun, $p->bulan, $this->hariIni(), $il);
        $baris = [];

        foreach ($populasi as $peg) {
            $h   = $hasil[$peg['nip']] ?? ['galat' => ['code' => 'data_tukin_tidak_sah', 'message' => 'Pegawai tidak dapat dihitung.']];
            $row = ['nip' => $peg['nip'], 'nama' => $peg['nama'], 'unit_es1' => $peg['unit'], 'unit_es2' => $peg['satker']];

            if (isset($h['galat'])) {
                $baris[] = $row + ['pengurangan' => null, 'total_presensi' => null, 'total_skp' => null, 'total_potongan' => null, 'keterangan' => '', 'penyesuaian' => null, 'galat' => $h['galat']];

                continue;
            }

            $r       = $h['hasil']->rincian;
            $baris[] = $row + [
                'pengurangan' => [
                    'TL'   => KalkulasiLaporanTukin::persen($r['TL'] ?? 0),
                    'PSW'  => KalkulasiLaporanTukin::persen($r['PSW'] ?? 0),
                    'TK'   => KalkulasiLaporanTukin::persen($r['TK'] ?? 0),
                    'TA'   => KalkulasiLaporanTukin::persen($r['TA'] ?? 0),
                    'CUTI' => KalkulasiLaporanTukin::persen($r['CUTI'] ?? 0),
                    'TB'   => KalkulasiLaporanTukin::persen(0),
                ],
                'total_presensi' => KalkulasiLaporanTukin::persen($h['hasil']->totalPresensi),
                'total_skp'      => KalkulasiLaporanTukin::persen($h['hasil']->totalSkp),
                'total_potongan' => KalkulasiLaporanTukin::persen($h['hasil']->totalPotongan),
                'keterangan'     => $h['keterangan'],
                'penyesuaian'    => $h['penyesuaian'],
                'galat'          => null,
            ];
        }

        return $baris;
    }

    /**
     * @return array{kasus: string, jabatan: string, nama: string, nip: string}
     */
    private function penandaTangan(LingkupLaporan $lingkup): array
    {
        return (new PejabatPenandaTangan($this->db, $this->webConfig ?? service('webConfigService'), $this->config ?? config(Presensi::class)))
            ->untuk($lingkup, $this->hariIni());
    }

    private function judul(ParameterRekap $p, bool $il): string
    {
        if ($il) {
            return 'LAPORAN TUNJANGAN KINERJA INSTANSI LAIN';
        }

        $nama = $this->db->table('jenis_pegawai')->select('jenis_pegawai')->whereIn('id_jenis_pegawai', $p->jenisPegawai)
            ->orderBy('id_jenis_pegawai', 'ASC')->get()->getResultArray();

        return trim('LAPORAN TUNJANGAN KINERJA ' . mb_strtoupper(implode(', ', array_map(static fn (array $b): string => (string) $b['jenis_pegawai'], $nama))));
    }

    private function hariIni(): string
    {
        return TanggalBisnis::hariIni(($this->jam)());
    }
}
