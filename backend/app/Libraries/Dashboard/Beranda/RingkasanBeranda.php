<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Beranda;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\CacheService;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Dashboard;

/**
 * `GET api/v1/dashboard/ringkasan` (F-13, MAKE-087; PRD Fase 7 v2.0 §5.10) — bentuk per keluarga role.
 *
 * **Admin (role 1/3/4/5/8)** — padanan tile komposisi `views/user/dashboard_ad.php:46-200` [K]; sumber hitungan
 * `libraries/hr/L_user.php` `dashboard_sa` (kementerian, role 1/4/5/8), `dashboard_satker` / `dashboard_unit` (role 3: satker
 * bila akun punya satker, selain itu unit — `User.php:526-567`). `tile` berurutan sama dengan view legacy:
 * PNS, PTT, Non PNS, Staff Khusus, Tenaga Ahli, Tugas Belajar, Izin Belajar, CLTN, Cuti Online, Presensi Online, PPPK;
 * `persen` = round(jumlah / total_aktif × 100) seperti view legacy (0 bila total 0).
 *
 * Aturan hitung [K]:
 * - jenis pegawai: `pegawai.status = 1` + `id_jenis_pegawai` 1..6; CLTN: `pegawai.status = 1` + `id_jenis_status = 3`;
 * - TB: `riwayat_tb.status = 1` dan hari ini dalam [tgl_mulai, tgl_akhir]; **lingkup kementerian juga** dalam
 *   [tgl_mulai_perpan, tgl_akhir_perpan]; role 3 tanpa perpanjangan (Q7-41);
 * - IB / cuti: `status = 1` dan hari ini dalam [tgl_mulai, tgl_akhir] (cuti dibandingkan per tanggal);
 * - presensi online: baris `dh_online` bertanggal hari ini; "hari ini" = tanggal bisnis WIB (`TanggalBisnis::hariIni`).
 *
 * Pengecualian NIP (Q7-41, `Config\Dashboard::$nipDisembunyikan`, ikut letak `$whereHide_nip` legacy):
 * - kementerian: semua tile, TIDAK pada `total_aktif`;
 * - satker: tile jenis pegawai + CLTN + PPPK dan `total_aktif`; TIDAK pada TB/IB/cuti/presensi online;
 * - unit: tile jenis pegawai + CLTN + PPPK; TIDAK pada `total_aktif`, TB/IB/cuti/presensi online.
 *
 * Lingkup role 3 = `pegawai_mutasi_jabatan.id_satker` / `.id_unit` milik akun persis seperti legacy dashboard (BUKAN
 * predikat daftar `PegawaiScope` yang untuk unit tertentu ikut UPT/BPO). Aturan "satker ada" sama dengan `PegawaiScope`:
 * satker bukan NULL/'' tetapi bukan angka kanonik → tanpa data (fail-closed), tidak jatuh ke unit.
 *
 * `pengingat` = 4 kartu Pensiun / Naik Pangkat / Tanda Jasa / KGB ({@see PengingatBeranda}; tanpa pengecualian NIP, ikut legacy).
 *
 * Cache: hitungan jenis pegawai/CLTN/total dan pengingat (kunci + tanggal) di-cache per lingkup selama `ttlRingkasanDetik`
 * (0 = tanpa cache); angka "hari ini" (TB, IB, cuti, presensi online) selalu dihitung ulang.
 *
 * **Pegawai (role 2/6/7)** — `{keluarga: 'pegawai', tanggal, prediksi}`: kartu prediksi {@see PrediksiPegawai} (role 2: Pensiun,
 * Naik Pangkat, Tanda Jasa, KGB; role 7: Pensiun (akhir masa perjanjian) + KGB; role 6: kosong) dan `bawahan` {@see BawahanPegawai}
 * (role 2 saja). Kelengkapan data: menyusul.
 */
final class RingkasanBeranda
{
    public const KELUARGA_ADMIN = 'admin';

    public const KELUARGA_PEGAWAI = 'pegawai';

    public const ROLE_ADMIN = [Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN];

    public const LINGKUP_KEMENTERIAN = 'kementerian';

    public const LINGKUP_SATKER = 'satker';

    public const LINGKUP_UNIT = 'unit';

    public const KELOMPOK_KOMPOSISI = 'komposisi';

    public const KELOMPOK_HARI_INI = 'hari_ini';

    /**
     * Urutan & label tile = view legacy `dashboard_ad.php` (`sn-title`).
     *
     * @var list<array{kode: string, label: string, kelompok: string}>
     */
    public const TILE = [
        ['kode' => 'pns', 'label' => 'Total PNS', 'kelompok' => self::KELOMPOK_KOMPOSISI],
        ['kode' => 'ptt', 'label' => 'Total PTT', 'kelompok' => self::KELOMPOK_KOMPOSISI],
        ['kode' => 'non_pns', 'label' => 'Total Non PNS', 'kelompok' => self::KELOMPOK_KOMPOSISI],
        ['kode' => 'staf_khusus', 'label' => 'Total Staff Khusus', 'kelompok' => self::KELOMPOK_KOMPOSISI],
        ['kode' => 'tenaga_ahli', 'label' => 'Total Tenaga Ahli', 'kelompok' => self::KELOMPOK_KOMPOSISI],
        ['kode' => 'tb', 'label' => 'Tugas Belajar', 'kelompok' => self::KELOMPOK_HARI_INI],
        ['kode' => 'ib', 'label' => 'Izin Belajar', 'kelompok' => self::KELOMPOK_HARI_INI],
        ['kode' => 'cltn', 'label' => 'CLTN', 'kelompok' => self::KELOMPOK_HARI_INI],
        ['kode' => 'cuti', 'label' => 'Cuti Online', 'kelompok' => self::KELOMPOK_HARI_INI],
        ['kode' => 'presensi_online', 'label' => 'Presensi Online', 'kelompok' => self::KELOMPOK_HARI_INI],
        ['kode' => 'pppk', 'label' => 'Total PPPK', 'kelompok' => self::KELOMPOK_KOMPOSISI],
    ];

    /**
     * 4 kartu pengingat `dashboard_ad.php:8-37` (label `<small>` legacy); hitungan {@see PengingatBeranda}.
     *
     * @var list<array{kode: string, label: string}>
     */
    public const PENGINGAT = [
        ['kode' => 'pensiun', 'label' => 'Pensiun'],
        ['kode' => 'kp', 'label' => 'Naik Pangkat'],
        ['kode' => 'tj', 'label' => 'Tanda Jasa'],
        ['kode' => 'kgb', 'label' => 'KGB'],
    ];

    /** `id_jenis_pegawai` per kode komposisi [K]. */
    private const JENIS_PEGAWAI = ['pns' => 1, 'ptt' => 2, 'non_pns' => 3, 'staf_khusus' => 4, 'tenaga_ahli' => 5, 'pppk' => 6];

    private const ID_JENIS_STATUS_CLTN = 3;

    /** Tile yang memakai pengecualian NIP di lingkup role 3 (satker & unit) [K]. */
    private const SEMBUNYI_ROLE3 = ['pns', 'ptt', 'non_pns', 'staf_khusus', 'tenaga_ahli', 'cltn', 'pppk'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Dashboard $config,
        private readonly ?CacheService $cache = null,
        private readonly ?string $hariIni = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function ringkasan(AuthContext $auth): array
    {
        $role = $auth->role();

        if ($role !== null && in_array($role, self::ROLE_ADMIN, true)) {
            return $this->admin($auth);
        }

        $nip     = $auth->nip();
        $tanggal = $this->hariIni ?? TanggalBisnis::hariIni();
        $adaNip  = $role !== null && $nip !== null && $nip !== '';

        return [
            'keluarga' => self::KELUARGA_PEGAWAI,
            'tanggal'  => $tanggal,
            'prediksi' => $adaNip ? (new PrediksiPegawai($this->db, $tanggal))->kartu($role, $nip) : [],
            // [K] presensi & AK bawahan hanya di dashboard_pns (role 2); PTT/PPPK tanpa bagian ini.
            'bawahan' => $adaNip && $role === Role::PEGAWAI ? (new BawahanPegawai($this->db, $tanggal))->untuk($nip) : ['atasan' => false, 'presensi' => [], 'ak' => []],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function admin(AuthContext $auth): array
    {
        $lingkup = $this->lingkup($auth);
        $tanggal = $this->hariIni ?? TanggalBisnis::hariIni();

        /** @var array{total_aktif: int, jenis: array<string, int>, cltn: int} $tetap */
        $tetap = $this->ingat('tetap', $lingkup, '', fn (): array => $this->hitungTetap($lingkup));

        /** @var array{pensiun: int, kp: int, tj: int, kgb: int} $pengingat */
        $pengingat = $this->ingat('pengingat', $lingkup, $tanggal, fn (): array => (new PengingatBeranda($this->db, $tanggal))->hitung($lingkup));
        $hariIni   = $this->hitungHariIni($lingkup, $tanggal);
        $total     = $tetap['total_aktif'];

        $tile = [];

        foreach (self::TILE as $t) {
            $jumlah = $t['kode'] === 'cltn' ? $tetap['cltn'] : ($tetap['jenis'][$t['kode']] ?? $hariIni[$t['kode']] ?? 0);
            $tile[] = $t + ['jumlah' => $jumlah, 'persen' => $total > 0 ? (int) round($jumlah / $total * 100) : 0];
        }

        return [
            'keluarga'           => self::KELUARGA_ADMIN,
            'lingkup'            => ['jenis' => $lingkup['jenis'], 'id' => $lingkup['id']],
            'tampilan_komposisi' => $this->config->modeKomposisi(),
            'tanggal'            => $tanggal,
            'total_aktif'        => $total,
            'pengingat'          => array_map(static fn (array $k): array => $k + ['jumlah' => $pengingat[$k['kode']]], self::PENGINGAT),
            'tile'               => $tile,
        ];
    }

    /**
     * @return array{jenis: string, id: int|null, kosong: bool}
     */
    private function lingkup(AuthContext $auth): array
    {
        if ($auth->role() !== Role::ADMIN_SATKER) {
            return ['jenis' => self::LINGKUP_KEMENTERIAN, 'id' => null, 'kosong' => false];
        }

        $satker = $auth->idSatker();

        if ($satker !== null && $satker !== '') {
            $id = self::keInt($satker);

            return ['jenis' => self::LINGKUP_SATKER, 'id' => $id, 'kosong' => $id === null];
        }

        $unit = self::keInt($auth->idUnit());

        return ['jenis' => self::LINGKUP_UNIT, 'id' => $unit, 'kosong' => $unit === null];
    }

    /**
     * Hitungan yang boleh di-cache: total aktif, jenis pegawai, CLTN.
     *
     * @param array{jenis: string, id: int|null, kosong: bool} $lingkup
     *
     * @return array{total_aktif: int, jenis: array<string, int>, cltn: int}
     */
    private function hitungTetap(array $lingkup): array
    {
        $jenis = [];

        foreach (self::JENIS_PEGAWAI as $kode => $id) {
            $jenis[$kode] = $this->hitung('pegawai', $lingkup, $this->sembunyi($lingkup, $kode), static function (BaseBuilder $b) use ($id): void {
                $b->where('status', 1)->where('id_jenis_pegawai', $id);
            });
        }

        return [
            'total_aktif' => $this->hitung('pegawai', $lingkup, $lingkup['jenis'] === self::LINGKUP_SATKER, static function (BaseBuilder $b): void {
                $b->where('status', 1);
            }),
            'jenis' => $jenis,
            'cltn'  => $this->hitung('pegawai', $lingkup, $this->sembunyi($lingkup, 'cltn'), static function (BaseBuilder $b): void {
                $b->where('status', 1)->where('id_jenis_status', self::ID_JENIS_STATUS_CLTN);
            }),
        ];
    }

    /**
     * @param array{jenis: string, id: int|null, kosong: bool} $lingkup
     *
     * @return array<string, int>
     */
    private function hitungHariIni(array $lingkup, string $tanggal): array
    {
        $kementerian = $lingkup['jenis'] === self::LINGKUP_KEMENTERIAN;

        return [
            'tb' => $this->hitung('riwayat_tb', $lingkup, $kementerian, static function (BaseBuilder $b) use ($tanggal, $kementerian): void {
                $b->where('status', 1)->groupStart()
                    ->groupStart()->where('tgl_mulai <=', $tanggal)->where('tgl_akhir >=', $tanggal)->groupEnd();

                if ($kementerian) {
                    $b->orGroupStart()->where('tgl_mulai_perpan <=', $tanggal)->where('tgl_akhir_perpan >=', $tanggal)->groupEnd();
                }

                $b->groupEnd();
            }),
            'ib' => $this->hitung('riwayat_ib', $lingkup, $kementerian, static function (BaseBuilder $b) use ($tanggal): void {
                $b->where('status', 1)->where('tgl_mulai <=', $tanggal)->where('tgl_akhir >=', $tanggal);
            }),
            'cuti' => $this->hitung('riwayat_cuti', $lingkup, $kementerian, static function (BaseBuilder $b) use ($tanggal): void {
                $b->where('status', 1)->where('DATE(tgl_mulai) <=', $tanggal)->where('DATE(tgl_akhir) >=', $tanggal);
            }),
            'presensi_online' => $this->hitung('dh_online', $lingkup, $kementerian, static function (BaseBuilder $b) use ($tanggal): void {
                $b->where('tgl', $tanggal);
            }),
        ];
    }

    /**
     * Pengecualian NIP untuk tile $kode pada $lingkup (letak legacy): kementerian = semua tile; role 3 = SEMBUNYI_ROLE3.
     *
     * @param array{jenis: string, id: int|null, kosong: bool} $lingkup
     */
    private function sembunyi(array $lingkup, string $kode): bool
    {
        return $lingkup['jenis'] === self::LINGKUP_KEMENTERIAN || in_array($kode, self::SEMBUNYI_ROLE3, true);
    }

    /**
     * COUNT baris $tabel (kolom `nip`) dalam $lingkup, ber-pengecualian NIP bila $sembunyi.
     *
     * @param array{jenis: string, id: int|null, kosong: bool} $lingkup
     * @param callable(BaseBuilder): void                       $syarat
     */
    private function hitung(string $tabel, array $lingkup, bool $sembunyi, callable $syarat): int
    {
        if ($lingkup['kosong']) {
            return 0;
        }

        $b = $this->db->table($tabel);
        $syarat($b);

        if ($lingkup['jenis'] !== self::LINGKUP_KEMENTERIAN) {
            $kolom = $lingkup['jenis'] === self::LINGKUP_SATKER ? 'id_satker' : 'id_unit';
            $b->whereIn('nip', fn (BaseBuilder $sub): BaseBuilder => $sub->select('nip')->from('pegawai_mutasi_jabatan')->where($kolom, $lingkup['id']));
        }

        $nip = $sembunyi ? $this->config->daftarNipDisembunyikan() : [];

        if ($nip !== []) {
            $b->whereNotIn('nip', $nip);
        }

        return $b->countAllResults();
    }

    /**
     * Cache per jenis hitungan + lingkup (+ $tanggal bila hasilnya bergantung tanggal, mis. pengingat).
     *
     * @param array{jenis: string, id: int|null, kosong: bool} $lingkup
     * @param callable(): array<string, mixed>                  $hitung
     *
     * @return array<string, mixed>
     */
    private function ingat(string $jenis, array $lingkup, string $tanggal, callable $hitung): array
    {
        $ttl = $this->config->ttlRingkasanDetik;

        if ($this->cache === null || $ttl <= 0) {
            return $hitung();
        }

        // Sidik daftar NIP (bukan isinya) supaya perubahan konfigurasi tidak memakai angka lama; NIP tidak masuk kunci.
        $kunci = sprintf('dashboard_beranda_%s_%s_%s_%s_%s', $jenis, $lingkup['jenis'], $lingkup['id'] ?? 'x', $tanggal === '' ? 'x' : $tanggal, substr(hash('sha256', implode(',', $this->config->daftarNipDisembunyikan())), 0, 12));

        /** @var array<string, mixed> */
        return $this->cache->remember($kunci, $ttl, $hitung(...));
    }

    private static function keInt(?string $nilai): ?int
    {
        return $nilai !== null && preg_match('/^[1-9]\d*$/', $nilai) === 1 ? (int) $nilai : null;
    }
}
