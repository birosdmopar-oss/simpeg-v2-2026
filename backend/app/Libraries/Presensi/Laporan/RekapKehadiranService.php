<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use App\Exceptions\ApiException;
use App\Interfaces\Layanan\StatusTugasBelajarInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\MasterData\HariLiburService;
use App\Libraries\MasterData\WebConfigService;
use App\Libraries\Presensi\Rekap\JadwalKerjaRekap;
use App\Libraries\Presensi\Rekap\KlasifikasiKehadiran;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use App\Libraries\Presensi\TukinData\KonfigurasiTukinDariWebConfig;
use App\Libraries\Presensi\TukinData\RentangPuasaGanda;
use Closure;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Rekap kehadiran bulan kalender (D-09b, MAKE-042, PRD 5B §6.2, §7.4.1) — port `Presensi::rekap` + `L_presensi::rekap`
 * (`:415-810`) dan `rekap_full_xls` (`:824-1436`) legacy `0cf7e67` [K]. Role route 1, 3, 4 (F5B-10); lingkup lewat
 * {@see CakupanLaporan} (role 3 dipaksa satkernya). Populasi {@see PopulasiRekap::dh()}; klasifikasi murni
 * {@see KlasifikasiKehadiran}.
 *
 * Data per batch {@see PopulasiRekap::BATCH_NIP} NIP dengan jumlah kueri tetap: `riwayat_cuti` status 1, `absen_ijin`
 * status `V` (affect_tukin apa pun [K `:567-591`]), `dh_online` bulan itu, TB lewat Fase 4
 * `rentangBebasPresensiBanyak` (F5B-10); libur aktif `HariLiburService`. Paginasi JSON: kalkulasi hanya untuk pegawai di
 * halaman (urutan populasi stabil). Jam kerja dari `web_config` F5-16 + `rentang_puasa` [V2].
 *
 * **Ikut legacy (keputusan Horii 28-10-2026):** presensi hanya dibaca untuk pegawai yang sudah ada sebelum bulan itu —
 * ringkas (JSON & XLSX) `DATE(peg.created_at) <= awal bulan` [K `rekap` `L_presensi.php:599`], grid harian
 * `peg.created_at < awal bulan` [K `rekap_full_xls` `:1053`]; pegawai yang dibuat sesudahnya tetap masuk populasi
 * (`DATE(created_at) ≤` akhir bulan) tetapi seluruh hari kerjanya TK. Rekap uang makan **tidak** ikut aturan ini (F5B-2,
 * MAKE-040: pegawai baru dihitung dari presensinya), sehingga kedua rekap berbeda untuk pegawai baru.
 */
final class RekapKehadiranService
{
    public const KOLOM_XLSX = ['NO', 'NAMA', 'NIP', 'JABATAN', 'HARI KERJA', 'HADIR', 'TL', 'PSW', 'TK', 'SAKIT', 'DINAS LUAR', 'LAIN LAIN', 'CUTI', 'TIDAK HADIR', 'TB'];

    private readonly BaseConnection $db;

    /** @var Closure(): DateTimeInterface */
    private readonly Closure $jam;

    /**
     * @param (Closure(): DateTimeInterface)|null $jam instan sekarang (bawaan jam server); disuntik test
     */
    public function __construct(
        ?BaseConnection $db = null,
        ?Closure $jam = null,
        private readonly ?StatusTugasBelajarInterface $tb = null,
        private readonly ?HariLiburService $hariLibur = null,
        private readonly ?WebConfigService $webConfig = null,
    ) {
        $this->db  = $db ?? db_connect();
        $this->jam = $jam ?? static fn (): DateTimeInterface => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * `GET presensi/rekap-kehadiran`.
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function daftar(AuthContext $auth, array $query): array
    {
        [$p, $lingkup, $populasi] = $this->siapkan($auth, $query);
        $halaman                  = array_slice($populasi, ($p->page - 1) * $p->perPage, $p->perPage);
        $hasil                    = $this->hitung($halaman, $p);

        return [
            'items'    => array_map(static fn (array $peg): array => ['nip' => $peg['nip'], 'nama' => $peg['nama'], 'jabatan' => $peg['jabatan']] + $hasil[$peg['nip']]['ringkas'], $halaman),
            'total'    => count($populasi),
            'page'     => $p->page,
            'per_page' => $p->perPage,
            'bulan'    => $p->kunci(),
            'lingkup'  => $lingkup->toArray(),
        ];
    }

    /**
     * `GET presensi/rekap-kehadiran/xlsx` — ringkas, seluruh lingkup [K `V/rekap.php:90-127`] + kolom TB [V2].
     *
     * @param array<string, mixed> $query
     *
     * @return array{isi: string, nama: string, mime: string}
     */
    public function xlsx(AuthContext $auth, array $query): array
    {
        [$p, $lingkup, $populasi] = $this->siapkan($auth, $query);
        $hasil                    = $this->hitung($populasi, $p);
        $baris                    = [];

        foreach ($populasi as $i => $peg) {
            $r       = $hasil[$peg['nip']]['ringkas'];
            $baris[] = [$i + 1, $peg['nama'], $peg['nip'], $peg['jabatan'], ...array_map(static fn (string $k): int => $r[$k], KlasifikasiKehadiran::KOLOM)];
        }

        return [
            'isi'  => EksporRekapXlsx::ringkas([$this->judul($p), $lingkup->nama, 'Periode: ' . $p->label()], self::KOLOM_XLSX, $baris),
            'nama' => sprintf('rekap_kehadiran_%s_%s.xlsx', $lingkup->kode(), $p->kunci()),
            'mime' => EksporRekapXlsx::MIME,
        ];
    }

    /**
     * `GET presensi/rekap-kehadiran/xlsx-harian` — grid harian [K `L_presensi.php:1156-1424`].
     *
     * @param array<string, mixed> $query
     *
     * @return array{isi: string, nama: string, mime: string}
     */
    public function xlsxHarian(AuthContext $auth, array $query): array
    {
        [$p, $lingkup, $populasi] = $this->siapkan($auth, $query);
        $hasil                    = $this->hitung($populasi, $p, true);
        $baris                    = [];

        foreach ($populasi as $i => $peg) {
            $baris[] = ['no' => $i + 1, 'nama' => $peg['nama'], 'nip' => $peg['nip'], 'unit' => $peg['unit'], 'satker' => $peg['satker'], 'harian' => $hasil[$peg['nip']]['harian']];
        }

        $judul   = 'LAPORAN KEHADIRAN ' . mb_strtoupper($this->namaJenis($p->jenisPegawai)) . "\n" . $lingkup->nama . "\nPeriode: " . $p->label();
        $tanggal = array_keys(KlasifikasiKehadiran::rekap($p->tahun, $p->bulan, $this->hariIni(), [], [], [], [], [], $this->jadwal($p))['harian']);

        return [
            'isi'  => EksporRekapXlsx::harian($judul, $tanggal, $this->libur($p), $baris),
            'nama' => sprintf('rekap_kehadiran_harian_%s_%s.xlsx', $lingkup->kode(), $p->kunci()),
            'mime' => EksporRekapXlsx::MIME,
        ];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{0: ParameterRekap, 1: LingkupLaporan, 2: list<array<string, mixed>>}
     */
    private function siapkan(AuthContext $auth, array $query): array
    {
        $p       = ParameterRekap::dari($query, $this->hariIni(), $this->db);
        $lingkup = (new CakupanLaporan($this->db, $this->webConfig))->lingkup($auth, $p->unitSatker);

        return [$p, $lingkup, (new PopulasiRekap($this->db))->dh($p->awal, $p->akhir, $lingkup, $p->jenisPegawai)];
    }

    /**
     * @param list<array<string, mixed>> $populasi
     *
     * @param bool $harian grid harian memakai syarat presensi `rekap_full_xls` (`created_at <` awal), ringkas `rekap`
     *                     (`DATE(created_at) <=` awal)
     *
     * @return array<string, array{ringkas: array<string, int>, harian: array<string, string|null>}>
     */
    private function hitung(array $populasi, ParameterRekap $p, bool $harian = false): array
    {
        $hasil = [];

        if ($populasi === []) {
            return $hasil;
        }

        $jadwal  = $this->jadwal($p);
        $libur   = $this->libur($p);
        $hariIni = $this->hariIni();
        $baca    = [];

        foreach ($populasi as $peg) {
            $dibuat = (string) ($peg['dibuat'] ?? '');

            if ($harian ? $dibuat < $p->awal : substr($dibuat, 0, 10) <= $p->awal) {
                $baca[(string) $peg['nip']] = true;
            }
        }

        foreach (array_chunk(array_map(static fn (array $peg): string => (string) $peg['nip'], $populasi), PopulasiRekap::BATCH_NIP) as $nip) {
            $data = DataRekapBulan::muat($this->db, $nip, $p->awal, $p->akhir);
            $tb   = ($this->tb ?? service('statusTugasBelajar'))->rentangBebasPresensiBanyak($nip, $p->awal, $p->akhir);

            foreach ($nip as $n) {
                $hasil[$n] = KlasifikasiKehadiran::rekap(
                    $p->tahun,
                    $p->bulan,
                    $hariIni,
                    $libur,
                    $data['cuti'][$n] ?? [],
                    array_map(static fn (string $t): array => [$t, $t], $tb[$n] ?? []),
                    $data['konket'][$n] ?? [],
                    isset($baca[$n]) ? ($data['presensi'][$n] ?? []) : [],
                    $jadwal,
                );
            }
        }

        return $hasil;
    }

    private function jadwal(ParameterRekap $p): JadwalKerjaRekap
    {
        $nilai = ($this->webConfig ?? service('webConfigService'))->values();

        try {
            $puasa = KonfigurasiTukinDariWebConfig::rentangPuasa($nilai[KonfigurasiTukinDariWebConfig::KUNCI_PUASA] ?? [], new PeriodeTukin($p->awal, $p->akhir));
        } catch (RentangPuasaGanda $e) {
            throw new ApiException($e->getMessage(), 422, kode: 'rentang_puasa_ganda');
        }

        try {
            return new JadwalKerjaRekap(
                ...[...array_map(static fn (string $k): string => (string) ($nilai[$k] ?? ''), KonfigurasiTukinDariWebConfig::KUNCI_JAM), $puasa['mulai'] ?? null, $puasa['selesai'] ?? null],
            );
        } catch (InvalidArgumentException) {
            throw new ApiException('Jam kerja di Web Config belum diatur atau tidak sah.', 422, kode: 'jam_kerja_tidak_sah');
        }
    }

    /**
     * @return list<string>
     */
    private function libur(ParameterRekap $p): array
    {
        return ($this->hariLibur ?? service('hariLiburService'))->tanggalLibur($p->awal, $p->akhir);
    }

    private function hariIni(): string
    {
        return TanggalBisnis::hariIni(($this->jam)());
    }

    private function judul(ParameterRekap $p): string
    {
        return 'Rekap Presensi ' . $this->namaJenis($p->jenisPegawai);
    }

    /**
     * @param list<int> $jenis
     */
    private function namaJenis(array $jenis): string
    {
        $nama = $this->db->table('jenis_pegawai')->select('jenis_pegawai')->whereIn('id_jenis_pegawai', $jenis)->orderBy('id_jenis_pegawai', 'ASC')->get()->getResultArray();

        return implode(', ', array_map(static fn (array $b): string => (string) $b['jenis_pegawai'], $nama));
    }
}
