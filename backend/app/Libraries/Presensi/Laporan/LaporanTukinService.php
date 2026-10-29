<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Libraries\MasterData\WebConfigService;
use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\Pdf;
use App\Libraries\Presensi\Rekap\PenyesuaianTukin;
use App\Libraries\Presensi\Slip\TarifUangMakan;
use App\Libraries\Presensi\Slip\UangMakanSlipPresensi;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use App\Libraries\Presensi\Tukin\DataPegawaiTukin;
use App\Libraries\Presensi\Tukin\HariKerja;
use App\Libraries\Presensi\Tukin\HasilTukin;
use App\Libraries\Presensi\TukinData\SumberPeriodeTukin;
use App\Models\MasterData\MasterModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Presensi;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Laporan Tukin per pegawai (D-08, MAKE-041, PRD 5B §7.3.2) — port `Presensi::laporan_tukin` + `laporan_tukin_skp`
 * (`Presensi.php:244-401`, `L_presensi.php:6510-7752`) legacy `0cf7e67`, varian `skp` saja (F5B-4).
 *
 * - Akses (F5B-7, urutan PRD §6): route role 1–7; role 2/6/7 hanya NIP sendiri (NIP lain → 403, tidak dialihkan);
 *   NIP tidak ada → 404; role 3 → `PegawaiScope::bolehLihat` (satker akun, unit bila tanpa satker; fail-closed) → 403;
 *   role 1/4/5 semua NIP; `bulan` → 422.
 * - Angka = **kalkulasi rekap** {@see KalkulasiLaporanTukin} (K-1 [V2]: legacy layar per pegawai memakai `laporan_tukin_skp`
 *   yang berbeda 4 aturan dari rekap; v2 satu kalkulasi sehingga layar = baris rekap). Layar JSON persen saja, tanpa uang
 *   makan & nominal (F5B-8). Galat terkendali → 422 ber-`code`.
 * - PDF (TCPDF, ADR-033) = render legacy persis [K `print/laporan_tukin_skp_pdf.php:55-257`, F5B-8]: kop unit berlogo
 *   {@see KopLaporanTukin} (keputusan Horii 28-10-2026), A4 potret, identitas,
 *   tabel TANGGAL | JAM MASUK | JAM PULANG | KET. | POTONGAN PRESENSI; baris konket = tanggal + keterangan `colspan=4` + sel
 *   "Rp. <tarif>" **hanya** bila tanggal itu hari dibayar uang makan menurut aturan slip (F5B-2, `HariUangMakan` per bulan
 *   kalender tanggal itu, tarif bulan itu); tanpa kolom/total uang makan; "TOTAL PENGURANGAN TUKIN" =
 *   "<total>%<akhiran penyesuaian>". Nama berkas `tukin_<nip>_<YYYY-MM>.pdf` [V2 tanpa angka acak].
 * - `harian[]` (CR-076, keputusan Horii 29-10-2026) memuat **setiap** tanggal awal periode s.d. `dihitung_sampai` seperti
 *   legacy [K `L_presensi.php:7302-7345`], urut tanggal. Hari kerja = hasil `KalkulasiTukin`; hari yang dilewati kalkulasi
 *   diisi di lapisan tampilan ini dengan urutan cek legacy: di luar masa kerja (`di_luar_masa_kerja`, keterangan "") →
 *   hari libur (`libur`, "(Libur) <nama libur>") → Sabtu/Minggu (`akhir_pekan` [V2 nama status], "Sabtu"/"Minggu");
 *   jam null, potongan "0.00", `kode` []. Total, jumlah, rincian, dan rekap tidak berubah (kalkulasi tidak disentuh).
 *   [CATAT] PDF memberi warna baris dengan palet yang sama dengan layar FE ({@see self::WARNA_BARIS_PDF}); [V2] legacy PDF
 *   memakai palet berbeda (akhir pekan kuning, konket hijau, `print/laporan_tukin_skp_pdf.php:188`).
 */
final class LaporanTukinService
{
    /**
     * Warna latar baris PDF per status (keputusan "satu palet" 29-10-2026, sama dengan layar FE): akhir pekan abu-abu,
     * libur biru, konket jingga, cuti ungu, tugas belajar merah muda, di luar masa kerja abu-abu; baris presensi tanpa warna.
     * Nilai = token FE `status.*.soft` (`frontend/tailwind.config.js`, MAKE-047) — ubah bersamaan.
     */
    public const WARNA_BARIS_PDF = [
        'akhir_pekan'        => '#F1F5F9', // status.abu.soft
        'libur'              => '#E0F7FA', // status.biru.soft
        'konket'             => '#FFE9D6', // status.jingga.soft
        'cuti'               => '#EFE9F7', // status.ungu.soft
        'tugas_belajar'      => '#FBE6F0', // status.pink.soft
        'di_luar_masa_kerja' => '#F1F5F9', // status.abu.soft
    ];

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
        private readonly ?PegawaiScopeInterface $scope = null,
        private readonly ?Presensi $config = null,
        private readonly ?WebConfigService $webConfig = null,
    ) {
        $this->db        = $db ?? db_connect();
        $this->jam       = $jam ?? static fn (): DateTimeInterface => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->kalkulasi = $kalkulasi ?? new KalkulasiLaporanTukin($this->db);
    }

    /**
     * `GET presensi/tukin/laporan/{nip}`.
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function laporan(AuthContext $auth, string $nip, array $query): array
    {
        [$p, $data] = $this->siapkan($auth, $nip, $query);

        return $data + ['bulan' => $p->kunci()];
    }

    /**
     * `GET presensi/tukin/laporan/{nip}/pdf`.
     *
     * @param array<string, mixed> $query
     *
     * @return array{isi: string, nama: string, mime: string}
     */
    public function pdf(AuthContext $auth, string $nip, array $query): array
    {
        [$p, $data] = $this->siapkan($auth, $nip, $query);
        $config     = $this->config ?? config(Presensi::class);
        $kop        = KopLaporanTukin::html($this->db, ($this->webConfig ?? service('webConfigService'))->values(), $config, $data['identitas']['id_unit'], $data['identitas']['id_satker']);
        $rp         = $this->rupiahKonket($nip, $data['harian']);

        $html = view('pdf/laporan_tukin_pegawai', [
            'd'       => $data,
            'periode' => $p->label(),
            'rp'      => $rp,
            'akhiran' => PenyesuaianTukin::akhiranTotal($data['penyesuaian']),
            'warna'   => self::WARNA_BARIS_PDF,
        ], ['saveData' => false]);

        return [
            'isi'  => Pdf::renderer()->render(new DokumenPdf('Laporan Tukin ' . $data['identitas']['nama'] . ' - ' . $p->label(), $html, kopHtml: $kop, marginAtasKop: 42.0)),
            'nama' => sprintf('tukin_%s_%s.pdf', $nip, $p->kunci()),
            'mime' => 'application/pdf',
        ];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{0: ParameterRekap, 1: array<string, mixed>}
     */
    private function siapkan(AuthContext $auth, string $nip, array $query): array
    {
        $role = $auth->role();

        if (in_array($role, Role::UL_PEGAWAI, true) && $auth->nip() !== $nip) {
            throw new ForbiddenException('Anda hanya dapat melihat laporan Tukin Anda sendiri.');
        }

        if (! in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, ...Role::UL_PEGAWAI], true)) {
            throw new ForbiddenException('Anda tidak memiliki akses ke laporan ini.');
        }

        $pegawai = $this->db->table('pegawai')->select('nip, nama, glr_awal, glr_akhir, id_jenis_pegawai')->where('nip', $nip)->get()->getRowArray();

        if (! is_array($pegawai)) {
            throw new NotFoundException('Data pegawai tidak ditemukan.');
        }

        if ($role === Role::ADMIN_SATKER && ! ($this->scope ?? service('pegawaiScope'))->bolehLihat($auth, $nip)) {
            throw new ForbiddenException('Pegawai di luar lingkup Anda.');
        }

        $hariIni = TanggalBisnis::hariIni(($this->jam)());
        $p       = RekapTukinService::parameter($query, $hariIni, $this->db, false);
        $hasil   = $this->kalkulasi->hitung([$nip], $p->tahun, $p->bulan, $hariIni)[$nip] ?? null;

        if ($hasil === null || isset($hasil['galat'])) {
            $galat = $hasil['galat'] ?? ['code' => 'data_tukin_tidak_sah', 'message' => 'Laporan Tukin pegawai tidak dapat dihitung.'];

            throw new ApiException($galat['message'], 422, kode: $galat['code']);
        }

        /** @var HasilTukin $h */
        $h       = $hasil['hasil'];
        $periode = $h->periode;
        // T-01 (data harian tidak dimuat) dan `lewatiLoopHarian` (presensi/konket tidak dibaca): tampilan tanpa jam/konket,
        // sama dengan angka (CR-083).
        $argumen = $hasil['tanpa_data_harian'] || ($hasil['pegawai']->lewatiLoopHarian ?? false) ? [] : $this->harianMentah($nip, $periode->awal, $h->akhirDihitung);

        return [$p, [
            'identitas'      => $this->identitas($pegawai, $periode->awal) + ['predikat_skp' => $hasil['predikat_skp']],
            'periode'        => ['awal' => $periode->awal, 'akhir' => $periode->akhir, 'dihitung_sampai' => $h->akhirDihitung, 'sumber' => (new SumberPeriodeTukin($this->db))->barisBkn($p->tahun, $p->bulan) !== null ? 'bkn' : 'bawaan'],
            'harian'         => $this->harian($h, $argumen, $hasil['pegawai'] ?? null, $this->namaLibur($periode->awal, $h->akhirDihitung)),
            'jumlah'         => $h->jumlah,
            'rincian'        => array_map(static fn (int $bp): string => KalkulasiLaporanTukin::persen($bp), $h->rincian),
            'total_presensi' => KalkulasiLaporanTukin::persen($h->totalPresensi),
            'total_skp'      => KalkulasiLaporanTukin::persen($h->totalSkp),
            'total_potongan' => KalkulasiLaporanTukin::persen($h->totalPotongan),
            'alasan'         => $h->alasan,
            'penyesuaian'    => $hasil['penyesuaian'],
            'keterangan'     => $hasil['keterangan'],
        ]];
    }

    /**
     * Identitas [K `L_presensi.php:7069-7087`]: pangkat & jabatan berlaku per awal periode.
     *
     * @param array<string, mixed> $pegawai
     *
     * @return array<string, mixed>
     */
    private function identitas(array $pegawai, string $awal): array
    {
        $nip = (string) $pegawai['nip'];
        $kp  = $this->db->table('riwayat_kp')->select('gol_ruang')->where('nip', $nip)->where('status', 1)->where('tmtsk <=', $awal)
            ->orderBy('tmtsk', 'DESC')->orderBy('id_riwayat_kp', 'DESC')->get(1)->getRowArray();
        $jab = $this->db->table('riwayat_mutasi_jabatan')->select('jabatan, satker, id_unit, id_satker, kelas_jabatan')->where('nip', $nip)->where('status', 1)
            ->where('jenis_jabatan', 1)->where('tmtsk <=', $awal)->orderBy('tmtsk', 'DESC')->orderBy('id_riwayat_mutasi_jabatan', 'DESC')->get(1)->getRowArray();

        return [
            'nip'           => $nip,
            'nama'          => StrukturService::namaGelar($pegawai['glr_awal'] ?? null, (string) ($pegawai['nama'] ?? ''), $pegawai['glr_akhir'] ?? null),
            'gol_ruang'     => (int) ($pegawai['id_jenis_pegawai'] ?? 0) === 1 ? (string) ($kp['gol_ruang'] ?? '') : null,
            'satker'        => (string) ($jab['satker'] ?? ''),
            'jabatan'       => (string) ($jab['jabatan'] ?? ''),
            'kelas_jabatan' => isset($jab['kelas_jabatan']) ? (int) $jab['kelas_jabatan'] : null,
            'id_unit'       => isset($jab['id_unit']) ? (int) $jab['id_unit'] : null,
            'id_satker'     => isset($jab['id_satker']) ? (int) $jab['id_satker'] : null,
        ];
    }

    /**
     * @return array<string, array{masuk: ?string, pulang: ?string, kategori: ?int}>
     */
    private function harianMentah(string $nip, string $awal, string $akhir): array
    {
        $hasil = [];

        foreach ($this->db->table('dh_online')->select('tgl, jam_masuk, jam_pulang')->where('nip', $nip)->where('tgl >=', $awal)->where('tgl <=', $akhir)->get()->getResultArray() as $b) {
            $hasil[(string) $b['tgl']] = ['masuk' => $b['jam_masuk'] === null ? null : (string) $b['jam_masuk'], 'pulang' => $b['jam_pulang'] === null ? null : (string) $b['jam_pulang'], 'kategori' => null];
        }

        foreach ($this->db->table('absen_ijin')->select('date_start, date_end, kategori')->where('nip', $nip)->where('status', 'V')->where('affect_tukin', 1)
            ->where('DATE(date_start) <=', $akhir)->where('DATE(date_end) >=', $awal)->orderBy('date_start', 'ASC')->orderBy('id', 'ASC')->get()->getResultArray() as $b) {
            foreach (\App\Libraries\Presensi\Tukin\HariKerja::kalender(max(substr((string) $b['date_start'], 0, 10), $awal), min(substr((string) $b['date_end'], 0, 10), $akhir)) as $t) {
                $hasil[$t]             = ($hasil[$t] ?? ['masuk' => null, 'pulang' => null]) + ['kategori' => null];
                $hasil[$t]['kategori'] = (int) $b['kategori'];
            }
        }

        return $hasil;
    }

    /**
     * Setiap tanggal awal periode s.d. `akhirDihitung` (CR-076): hari kerja dari `HasilTukin::harian`, sisanya baris
     * non-kerja dengan urutan cek legacy (di luar masa kerja → libur → akhir pekan).
     *
     * @param array<string, array{masuk: ?string, pulang: ?string, kategori: ?int}> $mentah
     * @param array<string, string>                                               $libur  tanggal → nama libur
     *
     * `kode` (CR-075) = `HasilHarian::kategori` apa adanya (mis. `['TL2', 'PSW1']`, `['CS']`, `[]`) untuk color-coding FE.
     *
     * @return list<array{tanggal: string, status: string, jam_masuk: ?string, jam_pulang: ?string, potongan: string, keterangan: string, kode: list<string>, kategori_konket: ?int}>
     */
    private function harian(HasilTukin $h, array $mentah, ?DataPegawaiTukin $pegawai, array $libur): array
    {
        $baris = [];

        foreach (HariKerja::kalender($h->periode->awal, $h->akhirDihitung) as $tgl) {
            $hh = $h->harian[$tgl] ?? null;

            if ($hh === null) {
                [$status, $ket] = match (true) {
                    $pegawai !== null && ($pegawai->lewatiLoopHarian || ! $pegawai->dalamMasaKerja($tgl)) => ['di_luar_masa_kerja', ''],
                    isset($libur[$tgl])                                                                   => ['libur', '(Libur) ' . $libur[$tgl]],
                    HariKerja::nomorHari($tgl) === 6                                                      => ['akhir_pekan', 'Sabtu'],
                    HariKerja::nomorHari($tgl) === 7                                                      => ['akhir_pekan', 'Minggu'],
                    default                                                                               => [null, ''],
                };

                if ($status !== null) {
                    $baris[] = ['tanggal' => $tgl, 'status' => $status, 'jam_masuk' => null, 'jam_pulang' => null, 'potongan' => KalkulasiLaporanTukin::persen(0), 'keterangan' => $ket, 'kode' => [], 'kategori_konket' => null];
                }

                continue;
            }

            $m       = $mentah[$tgl] ?? ['masuk' => null, 'pulang' => null, 'kategori' => null];
            $baris[] = [
                'tanggal'    => (string) $tgl,
                'status'     => $hh->status,
                'jam_masuk'  => $m['masuk'],
                'jam_pulang' => $m['pulang'],
                'potongan'   => KalkulasiLaporanTukin::persen($hh->potongan),
                'keterangan' => match ($hh->status) {
                    'tugas_belajar'      => 'Tugas Belajar',
                    'cuti'               => 'Cuti' . ($hh->jenisCuti === null ? '' : ' ' . str_replace('_', ' ', $hh->jenisCuti)),
                    'konket'             => 'Konket',
                    'libur'              => 'Libur',
                    'di_luar_masa_kerja' => '',
                    default              => implode(' - ', $hh->kategori),
                },
                'kode'            => $hh->kategori,
                'kategori_konket' => $hh->status === 'konket' ? $m['kategori'] : null,
            ];
        }

        return $baris;
    }

    /**
     * Nama hari libur aktif per tanggal untuk rentang laporan (CR-076), satu kueri; sumber sama dengan `TukinData`
     * (`hari_libur` status aktif, `HariLiburService::tanggalLibur`). Libur bertumpuk: baris `id_libur` terbesar menang
     * (legacy menimpa peta `listLibur_per` berurutan, `L_presensi.php:1724-1733`).
     *
     * @return array<string, string>
     */
    private function namaLibur(string $awal, string $akhir): array
    {
        $hasil = [];

        if ($awal > $akhir) {
            return $hasil;
        }

        $rows = $this->db->table('hari_libur')->select('tgl_mulai, tgl_akhir, nama_libur')->where('status', MasterModel::STATUS_ACTIVE)
            ->where('tgl_mulai <=', $akhir)->where('tgl_akhir >=', $awal)->orderBy('id_libur', 'ASC')->get()->getResultArray();

        foreach ($rows as $r) {
            foreach (HariKerja::kalender(max($awal, (string) $r['tgl_mulai']), min($akhir, (string) $r['tgl_akhir'])) as $t) {
                $hasil[$t] = (string) $r['nama_libur'];
            }
        }

        return $hasil;
    }

    /**
     * Nominal "Rp." baris konket PDF (F5B-8): tanggal konket yang termasuk hari dibayar uang makan menurut aturan slip pada
     * bulan kalender tanggal itu → tarif bulan itu (sen). Tanggal lain tidak dicetak.
     *
     * @param list<array{tanggal: string, status: string, kategori_konket: ?int}> $harian
     *
     * @return array<string, int>
     */
    private function rupiahKonket(string $nip, array $harian): array
    {
        $bulanan = [];

        foreach ($harian as $b) {
            if ($b['status'] === 'konket') {
                $bulanan[substr($b['tanggal'], 0, 7)][] = $b['tanggal'];
            }
        }

        $hasil  = [];
        $sumber = new UangMakanSlipPresensi($this->db, null, null, $this->jam);
        $tarif  = new TarifUangMakan($this->db);

        foreach ($bulanan as $ym => $tanggal) {
            [$t, $b] = [(int) substr($ym, 0, 4), (int) substr($ym, 5, 2)];
            $dibayar = array_flip($sumber->hariDibayarBanyak([$nip], $t, $b)[$nip] ?? []);
            $sen     = TarifUangMakan::totalSen($tarif->tarifRupiah($nip, PeriodeSlip::buat($t, $b)), 1);

            foreach ($tanggal as $tgl) {
                if (isset($dibayar[$tgl]) && $sen > 0) {
                    $hasil[$tgl] = $sen;
                }
            }
        }

        return $hasil;
    }
}
