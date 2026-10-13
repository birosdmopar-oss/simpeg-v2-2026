<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Struktur;

use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\StrukturServiceInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\Pdf;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use DateTimeImmutable;

/**
 * B-19 Struktur Organisasi (WS-2 M4 MAKE-012) — IKUT TEMPLAT LEGACY (keputusan user 13-10-2026).
 *
 * Sumber [K]: `controllers/hr/rwy/Jabatan.php` so() (:1187), `controllers/hr/So.php` full() (:167),
 * `libraries/hr/rwy/L_jabatan.php` so() (:7058) + so_012025() (:6402), `models/M_simpeg.php` getLUS_so_012025[_lst]()
 * (:94, :125), helper getPLT() (`helpers/function_helper.php:1946`), formatNamaGelar() (:102), views
 * `views/hr/employee/rwy/jabatan/so_012025/*`.
 *
 * Pilihan `id_unit_satker`: '0' = kementerian, '<id_unit>' = unit, 'sat_<id_satker>' = satker (hanya satker yang
 * ditawarkan legacy: unit 19 → satker 104, unit 6 → semua satkernya, unit 7 → satker 37/38/41). Format bagan sama
 * dengan legacy: `kementerian`; unit → `sesmen` (unit = web_config `id_unit_kepegawaian`), `inspektorat` (unit 16),
 * `deputi`; satker → `inspektorat` (104), `pusat` (49/50), `bpo` (37/38/41), `upt`. Isi dataset per format = kunci
 * dataset legacy (`menteri`, `staf_ahli`, `sekretaris.list_es2`, `deputi[].list_es2`, `es1`, `es2_sek`, `es2[].es3[].es4`,
 * `es3_sek`, `es3`, `es4`) dalam bentuk list JSON berurutan.
 *
 * Pejabat per jabatan pada tanggal acuan (MAKE-013, legacy so() :1187 + so_012025 maxRwyDate): `bulan` YYYY-MM sejak
 * 2022-01 (legacy `month` n-Y sejak Jan 2022); bulan berjalan → hari ini, bulan lalu → hari terakhir bulan itu. [V2]
 * semua bulan memakai templat so_012025 + struktur periode aktif (legacy memakai templat so_062023/so_before_062023
 * untuk bulan sebelum Jan 2025 / Jun 2023 — belum di-port). Plt/Plh dan golongan dimuat sekaligus (tanpa N+1):
 *  - definitif = `riwayat_mutasi_jabatan` status 1, jenis 1, TMT ≤ tanggal, pegawai dibuat sebelum tanggal dan
 *    (aktif dengan jenis status 1/12, atau nonaktif dengan TMT status sesudah tanggal); per NIP baris TMT terbaru; kunci
 *    `koord_<id_jabatan_koord>` (koordinator/subkoordinator), `struk_<id_jabatan>` (group 1), `ll_<id_jabatan>` (group 5);
 *    kunci sama → NIP terakhir (urut NIP) menang, sama dengan legacy;
 *  - teks "Nama, Gol/Ruang, NIP" (golongan = `riwayat_kp` status 1 TMT ≤ tanggal terbaru); NIP diawali 12345678910
 *    (akun dummy legacy) → nama saja;
 *  - kosong → Plt (`pegawai_plt` jabatan itu, tgl_mulai ≤ tanggal ≤ tgl_akhir), teks "Plt. …"; [V2] hanya status 1
 *    Aktif (legacy getPLT tanpa saring status; keputusan user 13-10-2026); [V2] lalu Plh (`pegawai_plh`, aturan sama,
 *    "Plh. …") bila tidak ada definitif maupun Plt — legacy bagan tidak memakai Plh (disetujui user 13-10-2026).
 *
 * [V2] simpul diambil dari `struktur_jabatan` periode aktif saja (legacy LEFT JOIN tanpa saring periode + GROUP BY).
 * Format `pusat` tidak punya view di legacy 2025 (bagan kosong); v2 merendernya dengan tata letak UPT.
 */
final class StrukturService implements StrukturServiceInterface
{
    /** Unit inspektorat (legacy `$idUnitInspektorat = '16'`, so_012025 :6463). */
    public const UNIT_INSPEKTORAT = 16;

    /** Satker inspektorat (legacy `SATKER_INSPEKTORAT`, `config/constants.php:311`). */
    public const SATKER_INSPEKTORAT = 104;

    /** Jabatan inspektur (legacy `ID_JAB_INSPEKTORAT`, `config/constants.php:315`). */
    public const JABATAN_INSPEKTORAT = 2060;

    /** @var list<int> */
    public const SATKER_PUSAT = [49, 50];

    /** @var list<int> */
    public const SATKER_BPO = [37, 38, 41];

    /** Unit yang satkernya ditawarkan di pilihan (getLUS_so_012025_lst). */
    private const UNIT_SATKER_PILIHAN = [19 => [self::SATKER_INSPEKTORAT], 6 => null, 7 => self::SATKER_BPO];

    /** Unit yang tidak bisa dipilih langsung (option disabled di view legacy). */
    private const UNIT_NONAKTIF_PILIHAN = [6];

    private const NIP_DUMMY = '12345678910';

    /** @var array<string, array<string, mixed>> */
    private array $pejabat = [];

    /** @var array<string, string> */
    private array $golongan = [];

    private string $tanggal = '';

    /** @var array<string, array<int, array<string, mixed>>> jenis (plt|plh) => id_jabatan => pegawai */
    private array $penugasan = [];

    /** Bulan pertama yang bisa dipilih (legacy so(): `new DateTime('2022-01-01')`). */
    public const BULAN_AWAL = '2022-01';

    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    public function pohon(AuthContext $auth, array $filter = []): array
    {
        $pilihan = $this->pilihan();
        $mentah  = $filter['id_unit_satker'] ?? '0';

        if (! is_string($mentah)) {
            throw ValidationException::forField('id_unit_satker', 'Data struktur organisasi Unit / Satker yang dipilih tidak tersedia.');
        }

        $kunci = trim($mentah) === '' ? '0' : trim($mentah);
        // Hanya opsi yang bisa dipilih (unit 6 `disabled` di legacy ditolak juga — keputusan user 13-10-2026).
        $aktif = array_column(array_filter($pilihan, static fn (array $p): bool => ! $p['disabled']), 'value');

        if ($kunci !== '0' && ! in_array($kunci, $aktif, true)) {
            throw ValidationException::forField('id_unit_satker', 'Data struktur organisasi Unit / Satker yang dipilih tidak tersedia.');
        }

        [$bulan, $this->tanggal] = $this->bulanDanTanggal($filter['bulan'] ?? null);
        $periode                 = $this->periodeAktif();
        $this->muatPejabat();
        $this->muatPenugasan();

        $wc    = $this->webConfig();
        $hasil = [
            'periode'        => $periode,
            'tanggal'        => $this->tanggal,
            'bulan'          => $bulan,
            'bulan_tersedia' => $this->bulanTersedia(),
            'id_unit_satker' => $kunci,
            'kementerian'    => $wc['nama_kementerian'] ?? '',
            'pilihan'        => $pilihan,
            'judul'          => '',
            'format'         => 'kementerian',
            'data'           => [],
        ];

        $idPeriode = $periode['id_periode_struktur_jabatan'] ?? null;

        if ($kunci === '0') {
            $hasil['judul'] = $wc['nama_kementerian'] ?? '';
            $hasil['data']  = $this->kementerian($idPeriode, $wc);
        } elseif (str_starts_with($kunci, 'sat_')) {
            $idSatker        = (int) substr($kunci, 4);
            $hasil['judul']  = (string) ($this->db()->table('satker')->select('satker')->where('id_satker', $idSatker)->get()->getRow()->satker ?? '');
            $hasil['format'] = match (true) {
                $idSatker === self::SATKER_INSPEKTORAT        => 'inspektorat',
                in_array($idSatker, self::SATKER_PUSAT, true) => 'pusat',
                in_array($idSatker, self::SATKER_BPO, true)   => 'bpo',
                default                                       => 'upt',
            };
            $hasil['data'] = $this->satker($idPeriode, $idSatker);
        } else {
            $idUnit          = (int) $kunci;
            $hasil['judul']  = (string) ($this->db()->table('unit')->select('unit')->where('id_unit', $idUnit)->get()->getRow()->unit ?? '');
            $hasil['format'] = match (true) {
                $idUnit === (int) ($wc['id_unit_kepegawaian'] ?? 0) => 'sesmen',
                $idUnit === self::UNIT_INSPEKTORAT                  => 'inspektorat_unit',
                default                                             => 'deputi',
            };
            $hasil['data'] = $this->unit($idPeriode, $idUnit, (int) ($wc['id_unit_kepegawaian'] ?? 0));
        }

        return $hasil;
    }

    /**
     * Pilihan unit/satker (getLUS_so_012025: unit aktif urut `order`; satker per aturan UNIT_SATKER_PILIHAN).
     *
     * @return list<array{value: string, label: string, level: int, disabled: bool}>
     */
    private function pilihan(): array
    {
        $db    = $this->db();
        $hasil = [];

        foreach ($db->table('unit')->where('status', 1)->orderBy('order', 'ASC')->orderBy('id_unit', 'ASC')->get()->getResultArray() as $unit) {
            $idUnit  = (int) $unit['id_unit'];
            $hasil[] = ['value' => (string) $idUnit, 'label' => (string) $unit['unit'], 'level' => 1, 'disabled' => in_array($idUnit, self::UNIT_NONAKTIF_PILIHAN, true)];

            if (! array_key_exists($idUnit, self::UNIT_SATKER_PILIHAN)) {
                continue;
            }

            $builder = $db->table('satker')->where('status', 1)->orderBy('order', 'ASC')->orderBy('id_satker', 'ASC');
            $hanya   = self::UNIT_SATKER_PILIHAN[$idUnit];
            $hanya === null ? $builder->where('id_unit', $idUnit) : $builder->whereIn('id_satker', $hanya);

            if ($hanya !== null && $idUnit !== 19) {
                $builder->where('id_unit', $idUnit);
            }

            foreach ($builder->get()->getResultArray() as $satker) {
                $hasil[] = ['value' => 'sat_' . $satker['id_satker'], 'label' => (string) $satker['satker'], 'level' => 2, 'disabled' => false];
            }
        }

        return $hasil;
    }

    /**
     * so_012025 cabang kementerian (:6465-6720).
     *
     * @param array<string, string> $wc
     *
     * @return array<string, mixed>
     */
    private function kementerian(?int $idPeriode, array $wc): array
    {
        $data = [
            'menteri'       => ['jabatan' => $wc['jabatan_menteri_singkat'] ?? '', 'pejabat' => $wc['nama_menteri'] ?? '', 'is_plt' => false, 'jenis' => null],
            'wakil_menteri' => ['jabatan' => $wc['jabatan_wakil_menteri_singkat'] ?? '', 'pejabat' => $wc['nama_wakil_menteri'] ?? '', 'is_plt' => false, 'jenis' => null],
            'staf_ahli'     => [],
            'inspektorat'   => null,
            'sekretaris'    => null,
            'deputi'        => [],
        ];

        // Es.I struktural (group 1, sub group 1) urut level & urutan struktur.
        $es1 = $this->jabatanStruktur($idPeriode)
            ->select('s.id_unit')
            ->where('jab.id_group_jabatan', 1)->where('jab.id_sub_group_jabatan', 1)
            ->get()->getResultArray();

        foreach ($es1 as $row) {
            $nama = strtolower((string) $row['jabatan']);

            if (str_starts_with($nama, 'staf ahli')) {
                $data['staf_ahli'][] = $this->simpul($row);
            } elseif (str_starts_with($nama, 'sekretaris') || str_starts_with($nama, 'deputi')) {
                $sek = str_starts_with($nama, 'sekretaris');
                $es2 = $this->jabatanStruktur($idPeriode)
                    ->where('s.id_unit', (int) $row['id_unit'])->where('sj.level_struktur', 3)->where('sj.id_jabatan_atasan', (int) $row['id_jabatan']);

                if ($sek) {
                    $es2->where('jab.id_satker !=', self::SATKER_INSPEKTORAT);
                }

                // Jalankan dulu: alias tabel builder CI4 dicatat per koneksi, query lain (simpul()) menimpanya.
                $barisEs2           = $es2->get()->getResultArray();
                $simpul             = $this->simpul($row);
                $simpul['list_es2'] = array_map(fn (array $r): array => $this->simpul($r, true), $barisEs2);

                if ($sek) {
                    $data['sekretaris'] = $simpul;
                } else {
                    $data['deputi'][] = $simpul;
                }
            }
        }

        $insp = $this->db()->table('jabatan')->where('id_jabatan', self::JABATAN_INSPEKTORAT)->where('status', 1)->get()->getRowArray();

        if ($insp !== null) {
            $data['inspektorat'] = $this->simpul($insp) + ['satker' => 'Inspektorat'];
        }

        return $data;
    }

    /**
     * so_012025 cabang unit (:6820-6965). Sesmen: Es.I staf ahli dilewati, Es.II hanya "Kepala Biro", Es.IV satker
     * pusat 49/50 dilewati.
     *
     * @return array<string, mixed>
     */
    private function unit(?int $idPeriode, int $idUnit, int $idUnitKepegawaian): array
    {
        $rows = $this->jabatanStruktur($idPeriode)
            ->where('s.id_unit', $idUnit)->whereIn('sj.level_struktur', [2, 3, 4, 5])
            ->get()->getResultArray();

        $data   = ['es1' => null, 'es2_sek' => [], 'es2' => []];
        $sesmen = $idUnit === $idUnitKepegawaian;
        $es2Sek = $es2 = $atasEs3 = [];

        foreach ($rows as $row) {
            $level = (int) $row['level_struktur'];
            $nama  = strtolower((string) $row['jabatan']);

            if ($sesmen && (($level === 2 && str_starts_with($nama, 'staf ahli'))
                || ($level === 3 && ! str_starts_with($nama, 'kepala biro'))
                || ($level === 5 && in_array((int) $row['id_satker'], self::SATKER_PUSAT, true)))) {
                continue;
            }

            $id = (int) $row['id_jabatan'];

            if ($level === 2) {
                $data['es1'] = $this->simpul($row, false, ['Inspektur' => 'Inspektorat', 'Sekretaris' => 'Sekretariat']);
            } elseif ($level === 3) {
                if (str_starts_with($nama, 'sekretaris')) {
                    $es2Sek[$id] = $this->simpul($row, false, ['Sekretaris' => 'Sekretariat']) + ['es3' => []];
                } else {
                    $es2[$id] = $this->simpul($row, false, ['Kepala ' => '', 'Inspektur' => 'Inspektorat']) + ['es3' => []];
                }
            } elseif ($level === 4) {
                $atas         = (int) $row['id_jabatan_atasan'];
                $atasEs3[$id] = $atas;
                $simpul       = $this->simpul($row, false, ['Kepala ' => '']) + ['es4' => []];

                if (isset($es2Sek[$atas])) {
                    $es2Sek[$atas]['es3'][$id] = $simpul;
                } elseif (isset($es2[$atas])) {
                    $es2[$atas]['es3'][$id] = $simpul;
                }
            } elseif ($level === 5) {
                $atas3 = (int) $row['id_jabatan_atasan'];
                $atas2 = $atasEs3[$atas3] ?? null;

                // Legacy hanya menempelkan Es.IV di bawah Es.III milik Es.II biasa (bukan sekretariat).
                if ($atas2 !== null && isset($es2[$atas2]['es3'][$atas3])) {
                    $es2[$atas2]['es3'][$atas3]['es4'][] = $this->simpul($row, false, ['Kepala ' => '']);
                }
            }
        }

        $daftar = static fn (array $es2List): array => array_values(array_map(static function (array $e): array {
            $e['es3'] = array_values($e['es3']);

            return $e;
        }, $es2List));

        $data['es2_sek'] = $daftar($es2Sek);
        $data['es2']     = $daftar($es2);

        return $data;
    }

    /**
     * so_012025 cabang satker (:6722-6818).
     *
     * @return array<string, mixed>
     */
    private function satker(?int $idPeriode, int $idSatker): array
    {
        $rows = $this->jabatanStruktur($idPeriode)
            ->where('jab.id_satker', $idSatker)->whereIn('sj.level_struktur', [3, 4, 5])
            ->get()->getResultArray();

        $pusat = in_array($idSatker, self::SATKER_PUSAT, true);
        $bpo   = in_array($idSatker, self::SATKER_BPO, true);
        $data  = ['es2_sek' => null, 'es2' => null, 'es3_sek' => null, 'es3' => [], 'es4' => []];
        $es3   = [];
        $es4   = [];

        foreach ($rows as $row) {
            $level = (int) $row['level_struktur'];
            $nama  = strtolower((string) $row['jabatan']);

            if ($level === 3) {
                if (str_starts_with($nama, 'sekretaris')) {
                    $data['es2_sek'] = $this->simpul($row, false, ['Sekretaris' => 'Sekretariat']);
                } else {
                    $data['es2'] = $this->simpul($row, false, ['Kepala ' => '', 'Inspektur' => 'Inspektorat']);
                }
            } elseif ($level === 4) {
                if ($bpo && str_starts_with($nama, 'satuan pemeriksaan intern')) {
                    $data['es3_sek'] = $this->simpul($row);
                } else {
                    $es3[(int) $row['id_jabatan']] = $this->simpul($row, false, $bpo ? [] : ['Kepala ' => '']) + ['es4' => []];
                }
            } elseif ($level === 5) {
                $atas   = (int) $row['id_jabatan_atasan'];
                $simpul = $this->simpul($row, false, ['Kepala ' => '']);

                if ($pusat || ! isset($es3[$atas])) {
                    $es4[] = $simpul;
                } else {
                    $es3[$atas]['es4'][] = $simpul;
                }
            }
        }

        $data['es3'] = array_values($es3);
        $data['es4'] = $es4;

        return $data;
    }

    /**
     * Jabatan aktif + simpul struktur periode aktif (satu baris per jabatan), urut level & urutan.
     */
    private function jabatanStruktur(?int $idPeriode): \CodeIgniter\Database\BaseBuilder
    {
        $builder = $this->db()->table('jabatan jab')
            ->select('jab.id_jabatan, jab.jabatan, jab.id_satker, jab.id_group_jabatan, sj.level_struktur, sj.id_jabatan_atasan, s.satker')
            ->join('struktur_jabatan sj', 'sj.id_jabatan = jab.id_jabatan', 'inner')
            ->join('satker s', 's.id_satker = jab.id_satker', 'left')
            ->where('jab.status', 1)
            ->where('sj.status', 1)
            ->orderBy('sj.level_struktur', 'ASC')->orderBy('sj.urutan', 'ASC')->orderBy('sj.id_struktur_jabatan', 'ASC');

        $idPeriode === null
            ? $builder->where('1 = 0', null, false)
            : $builder->where('sj.id_periode_struktur_jabatan', $idPeriode);

        return $builder;
    }

    /**
     * Satu kotak bagan: jabatan (dengan penggantian teks legacy), pejabat, is_plt.
     *
     * @param array<string, mixed>  $row
     * @param array<string, string> $ganti
     *
     * @return array{id_jabatan: int, jabatan: string, pejabat: string, is_plt: bool, jenis: string|null, satker?: string|null}
     */
    private function simpul(array $row, bool $denganSatker = false, array $ganti = []): array
    {
        $id      = (int) $row['id_jabatan'];
        $pejabat = $this->pejabatJabatan($id, (int) ($row['id_group_jabatan'] ?? 0));
        $simpul  = [
            'id_jabatan' => $id,
            'jabatan'    => $ganti === [] ? (string) $row['jabatan'] : str_replace(array_keys($ganti), array_values($ganti), (string) $row['jabatan']),
            'pejabat'    => $pejabat['teks'],
            'is_plt'     => $pejabat['jenis'] === 'plt' || $pejabat['jenis'] === 'plh',
            'jenis'      => $pejabat['jenis'],
        ];

        if ($denganSatker) {
            $simpul['satker'] = $row['satker'] ?? null;
        }

        return $simpul;
    }

    /**
     * @return array{teks: string, jenis: string|null}
     */
    private function pejabatJabatan(int $idJabatan, int $idGroup): array
    {
        $kunci = $idGroup === 5 ? "ll_{$idJabatan}" : "struk_{$idJabatan}";

        if (isset($this->pejabat[$kunci])) {
            return ['teks' => $this->teks($this->pejabat[$kunci]), 'jenis' => 'definitif'];
        }

        foreach (['plt', 'plh'] as $jenis) {
            $row = $this->penugasan[$jenis][$idJabatan] ?? null;

            if ($row !== null) {
                return ['teks' => ($jenis === 'plt' ? 'Plt. ' : 'Plh. ') . $this->teks($row), 'jenis' => $jenis];
            }
        }

        return ['teks' => '', 'jenis' => null];
    }

    /**
     * @param array<string, mixed> $p
     */
    private function teks(array $p): string
    {
        $nama = self::namaGelar($p['glr_awal'] ?? null, (string) $p['nama'], $p['glr_akhir'] ?? null);
        $nip  = (string) $p['nip'];

        if (str_starts_with($nip, self::NIP_DUMMY)) {
            return $nama;
        }

        $gol = $this->golongan[$nip] ?? $this->golonganPegawai($nip);

        return $gol !== '' ? "{$nama}, {$gol}, {$nip}" : "{$nama}, {$nip}";
    }

    /**
     * Pejabat definitif per kunci (so_012025 :6430-6460).
     */
    private function muatPejabat(): void
    {
        $tgl  = $this->tanggal;
        $rows = $this->db()->table('riwayat_mutasi_jabatan rmj')
            ->select('rmj.nip, rmj.id_group_jabatan, rmj.jenis_jabatan_koord, rmj.id_jabatan_koord, rmj.id_jabatan, p.glr_awal, p.nama, p.glr_akhir')
            ->join('pegawai p', 'p.nip = rmj.nip', 'left')
            ->where('rmj.status', 1)->where('rmj.tmtsk <=', $tgl)->where('rmj.jenis_jabatan', 1)->where('p.created_at <', $tgl)
            ->groupStart()
            ->groupStart()->where('p.status', 1)->whereIn('p.id_jenis_status', [1, 12])->groupEnd()
            ->orGroupStart()->where('p.status', 2)->where('p.tmt_status >', $tgl)->groupEnd()
            ->groupEnd()
            ->orderBy('rmj.nip', 'ASC')->orderBy('rmj.tmtsk', 'DESC')
            ->get()->getResultArray();

        $this->pejabat  = [];
        $sudah          = [];
        $this->golongan = [];

        foreach ($rows as $row) {
            if (isset($sudah[$row['nip']])) {
                continue;
            }

            $sudah[$row['nip']] = true;
            $kunci              = match (true) {
                in_array((int) $row['jenis_jabatan_koord'], [1, 2], true) => 'koord_' . $row['id_jabatan_koord'],
                (int) $row['id_group_jabatan'] === 1                      => 'struk_' . $row['id_jabatan'],
                (int) $row['id_group_jabatan'] === 5                      => 'll_' . $row['id_jabatan'],
                default                                                   => '',
            };

            if ($kunci !== '') {
                $this->pejabat[$kunci] = $row;
            }
        }

        $this->golongan = [];
    }

    /**
     * Plt/Plh berstatus 1 yang berlaku pada tanggal acuan, per id_jabatan (yang terlama dibuat menang, legacy getPLT
     * `row_array()`), plus golongan semua pejabat — dua kueri per jenis, bukan per simpul (temuan review 4 MAKE-012).
     */
    private function muatPenugasan(): void
    {
        $this->penugasan = ['plt' => [], 'plh' => []];

        foreach (['plt', 'plh'] as $jenis) {
            $rows = $this->db()->table("pegawai_{$jenis} t")
                ->select('t.id_jabatan, p.nip, p.glr_awal, p.nama, p.glr_akhir')
                ->join('pegawai p', "p.nip = t.nip_{$jenis}", 'inner')
                ->where('t.status', 1)->where('t.id_jabatan IS NOT NULL', null, false)
                ->where('t.tgl_mulai <=', $this->tanggal)->where('t.tgl_akhir >=', $this->tanggal)
                ->orderBy("t.id_pegawai_{$jenis}", 'ASC')->get()->getResultArray();

            foreach ($rows as $r) {
                $this->penugasan[$jenis][(int) $r['id_jabatan']] ??= $r;
            }
        }

        $nip = array_values(array_unique(array_merge(
            array_column($this->pejabat, 'nip'),
            array_column($this->penugasan['plt'], 'nip'),
            array_column($this->penugasan['plh'], 'nip'),
        )));

        foreach (array_chunk($nip, 500) as $potong) {
            $rows = $this->db()->table('riwayat_kp')->select('nip, gol_ruang')
                ->whereIn('nip', $potong)->where('status', 1)->where('tmtsk <=', $this->tanggal)
                ->orderBy('nip', 'ASC')->orderBy('tmtsk', 'DESC')->get()->getResultArray();

            foreach ($rows as $r) {
                $this->golongan[(string) $r['nip']] ??= trim((string) $r['gol_ruang']);
            }
        }
    }

    /**
     * `bulan` (YYYY-MM, bawaan bulan berjalan WIB) → [bulan, tanggal acuan]. Di luar 2022-01…bulan berjalan → 422.
     *
     * @return array{0: string, 1: string}
     */
    private function bulanDanTanggal(mixed $bulan): array
    {
        $hariIni  = TanggalBisnis::hariIni();
        $sekarang = substr($hariIni, 0, 7);
        $bulan    = is_string($bulan) && trim($bulan) !== '' ? trim($bulan) : $sekarang;

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan) !== 1 || $bulan < self::BULAN_AWAL || $bulan > $sekarang) {
            throw ValidationException::forField('bulan', 'Data struktur organisasi untuk bulan tersebut tidak tersedia.');
        }

        return [$bulan, $bulan === $sekarang ? $hariIni : (new DateTimeImmutable("{$bulan}-01"))->format('Y-m-t')];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function bulanTersedia(): array
    {
        $nama  = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $hasil = [];
        $bulan = new DateTimeImmutable(substr(TanggalBisnis::hariIni(), 0, 7) . '-01');

        while ($bulan->format('Y-m') >= self::BULAN_AWAL) {
            $hasil[] = ['value' => $bulan->format('Y-m'), 'label' => $nama[(int) $bulan->format('n')] . ' ' . $bulan->format('Y')];
            $bulan   = $bulan->modify('-1 month');
        }

        return $hasil;
    }

    /**
     * Ekspor PDF bagan (legacy export_so :1281 dengan wkhtmltopdf) — [V2] lewat pembungkus TCPDF (ADR-033) sebagai tabel
     * hierarki berindentasi (jabatan | pejabat) mengikuti susunan templat yang sama dengan layar.
     *
     * @param array<string, mixed> $filter
     *
     * @return array{isi: string, nama: string}
     */
    public function pdf(AuthContext $auth, array $filter = []): array
    {
        $so    = $this->pohon($auth, $filter);
        $baris = '';

        foreach ($this->barisPdf($so['format'], $so['data']) as [$level, $kotak]) {
            $pejabat = (string) ($kotak['pejabat'] ?? '');
            $baris .= '<tr><td style="padding-left:' . ($level * 12) . 'px;">' . str_repeat('&nbsp;&nbsp;&nbsp;', $level) . esc((string) $kotak['jabatan'])
                . ($kotak['satker'] ?? null ? '<br/><span style="font-size:7pt;color:#555;">' . esc((string) $kotak['satker']) . '</span>' : '')
                . '</td><td>' . ($pejabat === '' ? '<i>Lowong</i>' : esc($pejabat)) . '</td></tr>';
        }

        $html = '<h3 style="text-align:center;">STRUKTUR ORGANISASI<br/>' . esc(mb_strtoupper((string) $so['judul'])) . '</h3>'
            . '<p style="text-align:center;font-size:8pt;">Kondisi ' . esc(TanggalBisnis::formatIndonesia((string) $so['tanggal']))
            . ($so['periode'] ? ' · Periode struktur ' . esc((string) $so['periode']['periode_struktur_jabatan']) : '') . '</p>'
            . '<table border="1" cellpadding="4" width="100%"><tr style="font-weight:bold;background-color:#eeeeee;"><td width="55%">Jabatan</td><td width="45%">Pejabat</td></tr>'
            . ($baris === '' ? '<tr><td colspan="2">Tidak ada jabatan struktural.</td></tr>' : $baris) . '</table>';

        $isi = Pdf::renderer()->render(new DokumenPdf(
            'Struktur Organisasi ' . $so['judul'],
            $html,
            kakiHtml: 'SIMPEG v2, ' . Time::now('Asia/Jakarta')->format('d-m-Y H:i'),
            orientasi: 'L',
        ));

        return ['isi' => $isi, 'nama' => 'struktur_organisasi_' . $so['id_unit_satker'] . '_' . $so['bulan'] . '.pdf'];
    }

    /**
     * Baris PDF berurutan [level, kotak] — susunan sama dengan `soTemplat.ts` FE.
     *
     * @param array<string, mixed> $d
     *
     * @return list<array{0: int, 1: array<string, mixed>}>
     */
    private function barisPdf(string $format, array $d): array
    {
        $hasil  = [];
        $jf     = ['jabatan' => 'Jabatan Fungsional dan Jabatan Pelaksana', 'pejabat' => '-'];
        $tambah = static function (int $level, ?array $k) use (&$hasil): void {
            if ($k !== null) {
                $hasil[] = [$level, $k];
            }
        };

        if ($format === 'kementerian') {
            $tambah(0, $d['menteri'] ?? null);
            $tambah(0, $d['wakil_menteri'] ?? null);

            foreach ($d['staf_ahli'] ?? [] as $k) {
                $tambah(1, $k);
            }

            $tambah(1, $d['inspektorat'] ?? null);

            foreach (array_merge(($d['sekretaris'] ?? null) ? [$d['sekretaris']] : [], $d['deputi'] ?? []) as $es1) {
                $tambah(1, $es1);

                foreach ($es1['list_es2'] ?? [] as $es2) {
                    $tambah(2, $es2);
                }
            }

            return $hasil;
        }

        if (in_array($format, ['sesmen', 'deputi', 'inspektorat_unit'], true)) {
            $tambah(0, $d['es1'] ?? null);

            foreach (array_merge($d['es2_sek'] ?? [], $d['es2'] ?? []) as $es2) {
                $tambah(1, $es2);

                foreach ($es2['es3'] ?? [] as $es3) {
                    $tambah(2, $es3);

                    foreach ($es3['es4'] ?? [] as $es4) {
                        $tambah(3, $es4);
                    }
                }
            }

            return $hasil;
        }

        $tambah(0, $d['es2'] ?? null);
        $tambah(1, $d['es2_sek'] ?? null);
        $tambah(1, $d['es3_sek'] ?? null);

        foreach ($d['es3'] ?? [] as $es3) {
            $tambah(1, $es3);

            foreach ($es3['es4'] ?? [] as $es4) {
                $tambah(2, $es4);
            }
        }

        foreach ($d['es4'] ?? [] as $es4) {
            $tambah(1, $es4);
        }

        $tambah(1, $jf);

        return $hasil;
    }

    private function golonganPegawai(string $nip): string
    {
        $row = $this->db()->table('riwayat_kp')->select('gol_ruang')
            ->where('nip', $nip)->where('status', 1)->where('tmtsk <=', $this->tanggal)
            ->orderBy('tmtsk', 'DESC')->limit(1)->get()->getRowArray();

        return $this->golongan[$nip] = trim((string) ($row['gol_ruang'] ?? ''));
    }

    /**
     * @return array{id_periode_struktur_jabatan: int, periode_struktur_jabatan: string}|null
     */
    private function periodeAktif(): ?array
    {
        $row = $this->db()->table('periode_struktur_jabatan')->where('status', 1)
            ->orderBy('id_periode_struktur_jabatan', 'DESC')->limit(1)->get()->getRowArray();

        return $row === null ? null : [
            'id_periode_struktur_jabatan' => (int) $row['id_periode_struktur_jabatan'],
            'periode_struktur_jabatan'    => (string) $row['periode_struktur_jabatan'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function webConfig(): array
    {
        $db  = $this->db();
        $ada = $db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$db->prefixTable('web_config')],
        )->getRowArray() !== null;

        if (! $ada) {
            return [];
        }

        $hasil = [];

        foreach ($db->table('web_config')->select('config_name, config_value')->get()->getResultArray() as $row) {
            $hasil[(string) $row['config_name']] = (string) ($row['config_value'] ?? '');
        }

        return $hasil;
    }

    /**
     * Legacy `formatNamaGelar()`: "<gelar depan> <nama>, <gelar belakang>".
     */
    public static function namaGelar(?string $awal, string $nama, ?string $akhir): string
    {
        $hasil = trim(trim((string) $awal) . ' ' . trim($nama));

        return trim((string) $akhir) === '' ? $hasil : "{$hasil}, " . trim((string) $akhir);
    }

    private function db(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
