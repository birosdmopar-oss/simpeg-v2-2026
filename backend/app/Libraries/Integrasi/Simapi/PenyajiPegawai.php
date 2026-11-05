<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Simapi;

use App\Libraries\Kepegawaian\Lampiran\KolomBerkas;
use App\Libraries\Kepegawaian\Lkh\AtasanLangsung;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use Throwable;

/**
 * MAKE-059 (H-06) — data pegawai SIMAPI `a_employee/{method}/{nip}` [K `controllers/simapi/v1/A_employee.php`, rujukan baris
 * `AE:` per method]. Nama field, urutan field, sumber tabel, filter `status = 1` riwayat, urutan baris, dan pemetaan nilai teks
 * persis legacy; bentuk nilai string/null seperti `row_array()` legacy ({@see Nilai::mentah()}).
 *
 * Penyimpangan yang disengaja (dicatat di README & progres):
 *  - [V2] riwayat tanpa ORDER BY di legacy (`riwayat_pendidikan`, `riwayat_pelatihan`, `riwayat_kp`) diurut PK ASC, dan
 *    setiap urutan diberi kunci sekunder PK agar stabil (legacy bergantung urutan fisik InnoDB = PK);
 *  - [V2] `email` = akun `pengguna` aktif dengan `id_pengguna` terkecil (legacy LEFT JOIN + GROUP BY: baris acak);
 *  - [V2] `foto` = URL endpoint foto khusus klien SIMAPI (`simapi/v1/a_employee/foto/{nip}`, keputusan Horii), legacy `pathToSrc()`;
 *  - [K] bug legacy B-9 (keputusan Horii 04-11, Q6-36): `detil` tidak memilih nama `atasan_es_3/_4(_koord)` (`AE:278`),
 *    jadi bagian `unit_es_3/4` yang berasal dari atasan selalu "" — dipertahankan.
 *
 * Fungsi publik {@see sajikan()} mengembalikan data atau null (= "Data … tidak tersedia", {@see pesanKosong()}).
 */
final class PenyajiPegawai
{
    /** Method → [pesan bila data tidak ada]. Urutan = urutan method di legacy. [K] */
    public const METHOD = [
        'data_umum'                     => 'Data pegawai tidak tersedia',
        'detil'                         => 'Data pegawai tidak tersedia',
        'alamat_rumah'                  => 'Data alamat rumah tidak tersedia',
        'alamat_kantor'                 => 'Data alamat kantor tidak tersedia',
        'keluarga'                      => 'Data keluarga tidak tersedia',
        'riwayat_keluarga'              => 'Data riwayat keluarga tidak tersedia',
        'pendidikan_terakhir'           => 'Data pendidikan terakhir tidak tersedia',
        'riwayat_pendidikan'            => 'Data riwayat pendidikan tidak tersedia',
        'pelatihan_struktural_terakhir' => 'Data pelatihan struktural terakhir tidak tersedia',
        'riwayat_pelatihan'             => 'Data riwayat pelatihan tidak tersedia',
        'skp_terakhir'                  => 'Data SKP terakhir tidak tersedia',
        'riwayat_skp'                   => 'Data riwayat SKP tidak tersedia',
        'riwayat_kursus_seminar'        => 'Data riwayat kursus & seminar tidak tersedia',
        'riwayat_organisasi'            => 'Data riwayat organisasi tidak tersedia',
        'pengangkatan_cpns'             => 'Data pengangkatan CPNS pegawai tidak tersedia',
        'pengangkatan_pns'              => 'Data pengangkatan PNS pegawai tidak tersedia',
        'kp_terakhir'                   => 'Data pangkat terakhir pegawai tidak tersedia',
        'riwayat_kp'                    => 'Data riwayat kenaikan pangkat pegawai tidak tersedia',
        'jabatan_terakhir'              => 'Data jabatan terakhir tidak tersedia',
        'riwayat_jabatan'               => 'Data riwayat jabatan tidak tersedia',
        'kgb_terakhir'                  => 'Data KGB terakhir tidak tersedia',
        'riwayat_kgb'                   => 'Data riwayat KGB tidak tersedia',
        'tj_terakhir'                   => 'Data tanda jasa terakhir tidak tersedia',
        'riwayat_tj'                    => 'Data riwayat tanda jasa tidak tersedia',
        'riwayat_hukdis'                => 'Data riwayat hukuman disiplin tidak tersedia',
    ];

    public function __construct(private readonly BaseConnection $db, private readonly ?AtasanLangsung $atasan = null, private readonly ?KolomBerkas $berkas = null)
    {
    }

    public static function pesanKosong(string $method): string
    {
        return self::METHOD[$method] ?? throw new InvalidArgumentException("Method SIMAPI tidak dikenal: {$method}.");
    }

    /**
     * [K] pegawai ada (status apa pun, termasuk 10) — dasar "NIP not valid" (`AE:161-166`).
     */
    public function nipAda(string $nip): bool
    {
        return $this->db->table('pegawai')->where('nip', $nip)->countAllResults() > 0;
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>|null null = data tidak tersedia
     */
    public function sajikan(string $method, string $nip): ?array
    {
        $hasil = match ($method) {
            'data_umum'                     => $this->dataUmum($nip, false),
            'detil'                         => $this->dataUmum($nip, true),
            'alamat_rumah'                  => $this->alamat('pegawai_alamat', $nip, false),
            'alamat_kantor'                 => $this->alamat('pegawai_alamat_kantor', $nip, true),
            'keluarga'                      => $this->satu($this->db->table('pegawai_keluarga')->where('nip', $nip), $this->keluarga(...)),
            'riwayat_keluarga'              => $this->daftar($this->db->table('riwayat_keluarga')->where('nip', $nip)->where('status', 1)->orderBy('tgl_perkawinan', 'ASC')->orderBy('id_riwayat_keluarga', 'ASC'), $this->keluarga(...)),
            'pendidikan_terakhir'           => $this->satu($this->pendidikan('pegawai_pendidikan', $nip), $this->barisPendidikan(...)),
            'riwayat_pendidikan'            => $this->daftar($this->pendidikan('riwayat_pendidikan', $nip)->where('x.status', 1)->orderBy('x.id_riwayat_pendidikan', 'ASC'), $this->barisPendidikan(...)),
            'pelatihan_struktural_terakhir' => $this->satu($this->diklat('pegawai_diklat', $nip), static fn (array $r): array => self::barisDiklat($r, true)),
            'riwayat_pelatihan'             => $this->daftar($this->diklat('riwayat_diklat', $nip)->where('x.status', 1)->orderBy('x.id_riwayat_diklat', 'ASC'), static fn (array $r): array => self::barisDiklat($r, false)),
            'skp_terakhir'                  => $this->satu($this->skp($nip, 'DESC'), self::barisSkp(...)),
            'riwayat_skp'                   => $this->daftar($this->skp($nip, 'ASC'), self::barisSkp(...)),
            'riwayat_kursus_seminar'        => $this->daftar($this->db->table('riwayat_seminar')->where('nip', $nip)->where('status', 1)->orderBy('tgl_sertifikat', 'ASC')->orderBy('id_riwayat_seminar', 'ASC'), self::barisSeminar(...)),
            'riwayat_organisasi'            => $this->daftar($this->db->table('riwayat_organisasi')->where('nip', $nip)->where('status', 1)->orderBy('tgl_mulai', 'ASC')->orderBy('id_riwayat_organisasi', 'ASC'), self::barisOrganisasi(...)),
            'pengangkatan_cpns'             => $this->satu($this->kp('pegawai_cpns', $nip), self::barisKp(...)),
            'pengangkatan_pns'              => $this->satu($this->kp('pegawai_pns', $nip), self::barisKp(...)),
            'kp_terakhir'                   => $this->satu($this->kp('pegawai_kp', $nip), self::barisKp(...)),
            'riwayat_kp'                    => $this->daftar($this->kp('riwayat_kp', $nip)->where('x.status', 1)->orderBy('x.id_riwayat_kp', 'ASC'), self::barisKp(...)),
            'jabatan_terakhir'              => $this->satu($this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip), static fn (array $r): array => self::barisJabatan($r, false)),
            'riwayat_jabatan'               => $this->daftar($this->db->table('riwayat_mutasi_jabatan')->where('nip', $nip)->where('status', 1)->orderBy('tmtsk', 'ASC')->orderBy('id_riwayat_mutasi_jabatan', 'ASC'), static fn (array $r): array => self::barisJabatan($r, true)),
            'kgb_terakhir'                  => $this->satu($this->db->table('pegawai_kgb')->where('nip', $nip), self::barisKgb(...)),
            'riwayat_kgb'                   => $this->daftar($this->db->table('riwayat_kgb')->where('nip', $nip)->where('status', 1)->orderBy('tmtsk', 'ASC')->orderBy('id_riwayat_kgb', 'ASC'), self::barisKgb(...)),
            'tj_terakhir'                   => $this->satu($this->tandaJasa('pegawai_tanda_jasa', $nip), static fn (array $r): array => self::barisTandaJasa($r, false)),
            'riwayat_tj'                    => $this->daftar($this->tandaJasa('riwayat_tanda_jasa', $nip)->where('x.status', 1)->orderBy('x.tgl_sertifikat', 'ASC')->orderBy('x.id_riwayat_tanda_jasa', 'ASC'), static fn (array $r): array => self::barisTandaJasa($r, true)),
            'riwayat_hukdis'                => $this->daftar($this->hukdis($nip), self::barisHukdis(...)),
            default                         => throw new InvalidArgumentException("Method SIMAPI tidak dikenal: {$method}."),
        };

        return $hasil === [] ? null : $hasil;
    }

    // ------------------------------------------------------------------
    // data_umum `AE:122-221` / detil `AE:222-366`
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function dataUmum(string $nip, bool $detil): ?array
    {
        $q = $this->db->table('pegawai peg')
            ->select('peg.*, prov.provinsi AS provinsi_lahir_upd, kabkota.kabupaten_kota AS kabupaten_kota_lahir_upd, aga.agama AS agama_upd, jenpeg.jenis_pegawai AS jenis_pegawai_upd, jensta.jenis_status AS jenis_status_upd')
            ->join('provinsi prov', 'prov.id_provinsi = peg.id_provinsi_lahir', 'left')
            ->join('kabupaten_kota kabkota', 'kabkota.id_kabupaten_kota = peg.id_kabupaten_kota_lahir', 'left')
            ->join('agama aga', 'aga.id_agama = peg.id_agama', 'left')
            ->join('jenis_pegawai jenpeg', 'jenpeg.id_jenis_pegawai = peg.id_jenis_pegawai', 'left')
            ->join('jenis_status jensta', 'jensta.id_jenis_status = peg.id_jenis_status', 'left')
            ->where('peg.nip', $nip);

        if ($detil) {
            // [K AE:278-281] snapshot jabatan/KP/pendidikan; nama atasan_es_3/_4 TIDAK dipilih (bug legacy B-9).
            $q->select('pmj.id_group_jabatan, pmj.id_sub_group_jabatan, pmj.jenis_jabatan_koord, pmj.jabatan, pmj.jabatan_koord, pmj.unit, pmj.satker, pmj.tmtsk AS tmtsk_jabatan')
                ->select('pkp.gol_ruang, pkp.tmtsk AS tmtsk_kp, pkp.pangkat, pkp.gaji_pokok')
                ->select('ppen.tgl_lulus, ppen.jenjang_pendidikan_singkat, ppen.id_jurusan_pendidikan, ppen.jurusan_pendidikan, ppen.jurusan_pendidikan_lain, ppen.institusi_pendidikan, ppen.nem, ppen.ipk, ppen.id_jenjang_pendidikan')
                ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'left')
                ->join('pegawai_kp pkp', 'pkp.nip = peg.nip', 'left')
                ->join('pegawai_pendidikan ppen', 'ppen.nip = peg.nip', 'left');
        }

        $r = $q->get()->getRowArray();

        if ($r === null) {
            return null;
        }

        $data = [
            'nip'                  => Nilai::mentah($r['nip']),
            'nama'                 => Nilai::mentah($r['nama']),
            'glr_awal'             => Nilai::mentah($r['glr_awal']),
            'glr_akhir'            => Nilai::mentah($r['glr_akhir']),
            'tgl_lahir'            => Nilai::mentah($r['tgl_lahir']),
            'provinsi_lahir'       => self::lain($r, 'id_provinsi_lahir', '99', 'provinsi_lahir_lain', 'provinsi_lahir_upd'),
            'kabupaten_kota_lahir' => self::lain($r, 'id_kabupaten_kota_lahir', '9999', 'kabupaten_kota_lahir_lain', 'kabupaten_kota_lahir_upd'),
            'agama'                => Nilai::mentah($r['agama_upd']),
            'jenis_kelamin'        => self::sama($r['jenis_kelamin'], '1') ? 'Laki-laki' : 'Perempuan',
            'npwp'                 => Nilai::mentah($r['npwp']),
            'email'                => $this->email($nip),
            'no_hp'                => Nilai::mentah($r['no_hp']),
            'no_telp_kerabat'      => Nilai::mentah($r['no_telp_kerabat']),
            'status_pernikahan'    => self::sama($r['status_pernikahan'], '1') ? 'Menikah' : 'Tidak Menikah',
            'jenis_pegawai'        => Nilai::mentah($r['jenis_pegawai_upd']),
            'status'               => self::sama($r['status'], '1') ? 'Aktif' : 'Tidak Aktif',
            'jenis_status'         => Nilai::mentah($r['jenis_status_upd']),
            'tmt_status'           => Nilai::mentah($r['tmt_status']),
            'foto'                 => $this->urlFoto($nip, (string) ($r['foto'] ?? '')),
        ];

        if (! $detil) {
            return $data;
        }

        [$unitEs3, $unitEs4] = self::unitEs34($r);

        return $data + [
            'tmt_jabatan'                => Nilai::mentah($r['tmtsk_jabatan']),
            'jabatan'                    => Nilai::mentah($r['jabatan']),
            'jabatan_koordinasi'         => Nilai::mentah($r['jabatan_koord']),
            'unit_es_1'                  => Nilai::mentah($r['unit']),
            'unit_es_2'                  => Nilai::mentah($r['satker']),
            'unit_es_3'                  => $unitEs3,
            'unit_es_4'                  => $unitEs4,
            'atasan_langsung'            => ($this->atasan ?? new AtasanLangsung($this->db))->untuk($nip) ?? [], // [K] getAL() → [] bila tak ada
            'tmt_kp'                     => Nilai::mentah($r['tmtsk_kp']),
            'gol_ruang'                  => Nilai::mentah($r['gol_ruang']),
            'pangkat'                    => Nilai::mentah($r['pangkat']),
            'gaji_pokok'                 => Nilai::mentah($r['gaji_pokok']),
            'tgl_lulus'                  => Nilai::mentah($r['tgl_lulus']),
            'jenjang_pendidikan_singkat' => Nilai::mentah($r['jenjang_pendidikan_singkat']),
            'institusi_pendidikan'       => Nilai::mentah($r['institusi_pendidikan']),
            'jurusan_pendidikan'         => self::lain($r, 'id_jurusan_pendidikan', '1185', 'jurusan_pendidikan_lain', 'jurusan_pendidikan'),
            'nilai_pendidikan'           => self::nilaiPendidikan($r),
        ];
    }

    /**
     * [K AE:303-317] unit Es.III/IV dari jabatan sendiri atau atasan. Nama atasan tidak pernah dipilih di kueri legacy
     * (`AE:278`, bug B-9, keputusan Horii 04-11 = ikut legacy), jadi cabang atasan selalu "".
     *
     * @param array<string, mixed> $r
     *
     * @return array{0: string, 1: string} [unit_es_3, unit_es_4]
     */
    private static function unitEs34(array $r): array
    {
        $atasanEs3 = '';
        $atasanEs4 = ''; // [K] B-9: `atasan_es_3(_koord)`/`atasan_es_4(_koord)` tidak ada di baris hasil → null → ""
        $sendiri   = ! self::kosong($r['jabatan_koord']) ? self::namaUnit((string) $r['jabatan_koord']) : (! self::kosong($r['jabatan']) ? self::namaUnit((string) $r['jabatan']) : '');

        if (self::sama($r['id_sub_group_jabatan'], '4') || self::sama($r['jenis_jabatan_koord'], '2')) {
            return [$atasanEs3, $sendiri];
        }

        if (self::sama($r['id_sub_group_jabatan'], '3') || self::sama($r['jenis_jabatan_koord'], '1')) {
            return [$sendiri, ''];
        }

        return [$atasanEs3, $atasanEs4];
    }

    /**
     * SATU-SATUNYA titik pembentuk URL `foto` SIMAPI. Keputusan Horii (= A, pola Q6-38): URL absolut endpoint foto khusus klien
     * SIMAPI `simapi/v1/a_employee/foto/{nip}` (token SIMAPI). "" bila kolom `foto` kosong atau berkas tidak ada di storage v2
     * (mis. foto legacy belum dimigrasi). Path berkas tidak pernah keluar.
     */
    public function urlFoto(string $nip, string $path): string
    {
        if ($path === '') {
            return '';
        }

        try {
            $ada = ($this->berkas ?? new KolomBerkas())->ada($path);
        } catch (Throwable) {
            $ada = false; // path arsip remote legacy / tidak valid bagi adapter
        }

        return $ada ? site_url('simapi/v1/a_employee/foto/' . rawurlencode($nip)) : '';
    }

    /**
     * Port `fixUnitName()` [K `function_helper.php:1925-1944`]: buang awalan jabatan; tanpa awalan dikenal → "".
     */
    public static function namaUnit(string $nama): string
    {
        foreach (['koordinator ', 'kepala bagian ', 'subkoordinator ', 'kepala sub bagian ', 'kepala subbagian '] as $awalan) {
            if (strtolower(substr($nama, 0, strlen($awalan))) === $awalan) {
                return substr($nama, strlen($awalan));
            }
        }

        return '';
    }

    /**
     * [V2] akun aktif dengan id terkecil (legacy: LEFT JOIN `pengguna` status 1 + GROUP BY nip → baris tak tentu).
     */
    private function email(string $nip): ?string
    {
        // `pengguna.status` ENUM('0','1'): bandingkan sebagai STRING (angka 1 = indeks ENUM pertama = '0').
        $r = $this->db->table('pengguna')->select('email')->where('nip', $nip)->where('status', '1')
            ->orderBy('id_pengguna', 'ASC')->limit(1)->get()->getRowArray();

        return $r === null ? null : Nilai::mentah($r['email']);
    }

    // ------------------------------------------------------------------
    // alamat `AE:415-442`, `AE:499-530`
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function alamat(string $tabel, string $nip, bool $kantor): ?array
    {
        $r = $this->db->table($tabel . ' pal')
            ->select('pal.*, prov.provinsi AS provinsi_upd, kabkota.kabupaten_kota AS kabupaten_kota_upd, kec.kecamatan AS kecamatan_upd, kel.kelurahan AS kelurahan_upd')
            ->join('provinsi prov', 'prov.id_provinsi = pal.id_provinsi', 'left')
            ->join('kabupaten_kota kabkota', 'kabkota.id_kabupaten_kota = pal.id_kabupaten_kota', 'left')
            ->join('kecamatan kec', 'kec.id_kecamatan = pal.id_kecamatan', 'left')
            ->join('kelurahan kel', 'kel.id_kelurahan = pal.id_kelurahan', 'left')
            ->where('pal.nip', $nip)->get()->getRowArray();

        if ($r === null) {
            return null;
        }

        $data = [
            'id_rwy'         => Nilai::mentah($r['id_riwayat_alamat']),
            'nip'            => Nilai::mentah($r['nip']),
            'alamat'         => Nilai::mentah($r['alamat']),
            'provinsi'       => self::lain($r, 'id_provinsi', '99', 'provinsi_lain', 'provinsi_upd'),
            'kabupaten_kota' => self::lain($r, 'id_kabupaten_kota', '9999', 'kabupaten_kota_lain', 'kabupaten_kota_upd'),
            'kecamatan'      => self::lain($r, 'id_kecamatan', '9999999', 'kecamatan_lain', 'kecamatan_upd'),
            'kelurahan'      => self::lain($r, 'id_kelurahan', '9999999999', 'kelurahan_lain', 'kelurahan_upd'),
            'kode_pos'       => Nilai::mentah($r['kd_pos']),
        ];

        return ! $kantor ? $data : $data + [
            'nama_kantor' => Nilai::mentah($r['nama_kantor']),
            'lantai'      => Nilai::mentah($r['lantai']),
            'telp'        => Nilai::mentah($r['telp']),
            'faks'        => Nilai::mentah($r['faks']),
        ];
    }

    // ------------------------------------------------------------------
    // keluarga `AE:589-617` / riwayat_keluarga `AE:675-707`
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function keluarga(array $r): array
    {
        $anak = $this->db->table('detail_anak')->where('id_riwayat_keluarga', $r['id_riwayat_keluarga'])
            ->orderBy('urutan', 'ASC')->orderBy('id_detail_anak', 'ASC')->get()->getResultArray(); // [K] tanpa filter status

        return [
            'id_rwy'            => Nilai::mentah($r['id_riwayat_keluarga']),
            'nip'               => Nilai::mentah($r['nip']),
            'urutan_perkawinan' => Nilai::mentah($r['urutan_perkawinan']),
            'tgl_perkawinan'    => Nilai::mentah($r['tgl_perkawinan']),
            'kota_perkawinan'   => Nilai::mentah($r['kota_perkawinan']),
            'nama_pasangan'     => Nilai::mentah($r['nama_pasangan']),
            'jumlah_anak'       => Nilai::mentah($r['jumlah_anak']),
            'daftar_anak'       => array_map(static fn (array $a): array => [
                'id_rwy'            => Nilai::mentah($a['id_detail_anak']),
                'id_rwy_perkawinan' => Nilai::mentah($r['id_riwayat_keluarga']),
                'urutan_anak'       => Nilai::mentah($a['urutan']),
                'tgl_lahir_anak'    => Nilai::mentah($a['tgl_lahir']),
                'nama_anak'         => Nilai::mentah($a['nama']),
                'tempat_lahir_anak' => Nilai::mentah($a['tempat_lahir']),
            ], $anak),
        ];
    }

    // ------------------------------------------------------------------
    // pendidikan `AE:769-795`, `AE:852-879`
    // ------------------------------------------------------------------

    private function pendidikan(string $tabel, string $nip): BaseBuilder
    {
        return $this->db->table($tabel . ' x')
            ->select('x.*, jpen.jenjang_pendidikan_singkat AS jenjang_pendidikan_singkat_upd, bpen.bidang_pendidikan AS bidang_pendidikan_upd, jupen.jurusan_pendidikan AS jurusan_pendidikan_upd')
            ->join('jenjang_pendidikan jpen', 'jpen.id_jenjang_pendidikan = x.id_jenjang_pendidikan', 'left')
            ->join('bidang_pendidikan bpen', 'bpen.id_bidang_pendidikan = x.id_bidang_pendidikan', 'left')
            ->join('jurusan_pendidikan jupen', 'jupen.id_jurusan_pendidikan = x.id_jurusan_pendidikan', 'left')
            ->where('x.nip', $nip);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function barisPendidikan(array $r): array
    {
        return [
            'id_rwy'                     => Nilai::mentah($r['id_riwayat_pendidikan']),
            'nip'                        => Nilai::mentah($r['nip']),
            'tgl_lulus'                  => Nilai::mentah($r['tgl_lulus']),
            'no_ijazah'                  => Nilai::mentah($r['no_ijazah']),
            'institusi_pendidikan'       => Nilai::mentah($r['institusi_pendidikan']),
            'jenjang_pendidikan_singkat' => Nilai::mentah($r['jenjang_pendidikan_singkat_upd']),
            'bidang_pendidikan'          => self::lain($r, 'id_bidang_pendidikan', '98', 'bidang_pendidikan_lain', 'bidang_pendidikan_upd'),
            'jurusan_pendidikan'         => self::lain($r, 'id_jurusan_pendidikan', '1185', 'jurusan_pendidikan_lain', 'jurusan_pendidikan_upd'),
            'nilai'                      => self::nilaiPendidikan($r),
        ];
    }

    // ------------------------------------------------------------------
    // diklat `AE:939-970`, `AE:1027-1058`
    // ------------------------------------------------------------------

    private function diklat(string $tabel, string $nip): BaseBuilder
    {
        return $this->db->table($tabel . ' x')->select('x.*, dik.nama_diklat AS nama_diklat_upd')
            ->join('diklat dik', 'dik.id_diklat = x.id_diklat', 'left')->where('x.nip', $nip);
    }

    /**
     * Snapshot `pegawai_diklat` tidak punya kolom `jenis_diklat` (D1, sama di legacy), jadi cabang jenis 3/4 tidak pernah
     * berlaku untuk `pelatihan_struktural_terakhir` [K].
     *
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private static function barisDiklat(array $r, bool $struktural): array
    {
        $jenis = $r['jenis_diklat'] ?? null;
        $nama  = ! self::kosong($r['nama_diklat_upd']) ? Nilai::mentah($r['nama_diklat_upd']) : Nilai::mentah($r['nama_diklat']);

        if (self::sama($jenis, '3')) {
            $nama = Nilai::teks($r['deskripsi']) . ' (' . Nilai::teks($r['sub_group_jabatan']) . ')';
        } elseif (self::sama($jenis, '4')) {
            $nama = Nilai::teks($r['deskripsi']) . ' (' . Nilai::teks($r['nama_diklat']) . ')';
        }

        return [
            'id_rwy'                 => Nilai::mentah($r['id_riwayat_diklat']),
            'nip'                    => Nilai::mentah($r['nip']),
            'jenis_pelatihan'        => $struktural ? 'Struktural' : self::jenisDiklat($jenis),
            'nama_pelatihan'         => $nama,
            'no_sertifikat'          => Nilai::mentah($r['no_sertifikat']),
            'tgl_sertifikat'         => Nilai::mentah($r['tgl_sertifikat']),
            'jumlah_jp'              => Nilai::mentah($r['jumlah_jp']),
            'instansi_penyelenggara' => Nilai::mentah($r['instansi_penyelenggara']),
            'keterangan'             => Nilai::mentah($r['keterangan']),
        ];
    }

    /**
     * Port `getJenisDiklat()` [K `function_helper.php:220-238`].
     */
    private static function jenisDiklat(mixed $id): string
    {
        return [1 => 'Struktural', 2 => 'Teknis', 3 => 'Fungsional', 4 => 'Prajabatan', 5 => 'Sertifikasi'][(int) ($id ?? 0)] ?? '';
    }

    // ------------------------------------------------------------------
    // SKP `AE:1118-1142` (terakhir: tahun DESC, tgl_mulai DESC), `AE:1199-1223` (ASC)
    // ------------------------------------------------------------------

    private function skp(string $nip, string $arah): BaseBuilder
    {
        return $this->db->table('riwayat_skp')->where('nip', $nip)->where('status', 1)
            ->orderBy('tahun', $arah)->orderBy('tgl_mulai', $arah)->orderBy('id_riwayat_skp', $arah);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, string|null>
     */
    private static function barisSkp(array $r): array
    {
        return [
            'id_rwy'                 => Nilai::mentah($r['id_riwayat_skp']),
            'nip'                    => Nilai::mentah($r['nip']),
            'tahun'                  => Nilai::mentah($r['tahun']),
            'tgl_mulai'              => Nilai::mentah($r['tgl_mulai']),
            'tgl_akhir'              => Nilai::mentah($r['tgl_akhir']),
            'nip_penilai'            => Nilai::mentah($r['nip_penilai']),
            'nama_penilai'           => Nilai::mentah($r['nama_penilai']),
            'jabatan_penilai'        => Nilai::mentah($r['jabatan_penilai']),
            'nip_atasan_penilai'     => Nilai::mentah($r['nip_atasan_penilai']),
            'nama_atasan_penilai'    => Nilai::mentah($r['nama_atasan_penilai']),
            'jabatan_atasan_penilai' => Nilai::mentah($r['jabatan_atasan_penilai']),
            'nilai_skp'              => Nilai::mentah($r['nilai_skp']),
            'nilai_perilaku'         => Nilai::mentah($r['nilai_perilaku']),
            'nilai_prestasi_kerja'   => Nilai::mentah($r['nilai_prestasi_kerja']),
            'kategori_nilai'         => Nilai::mentah($r['kategori_nilai_prestasi']),
        ];
    }

    // ------------------------------------------------------------------
    // seminar `AE:1283-1302`, organisasi `AE:1362-1378`
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, string|null>
     */
    private static function barisSeminar(array $r): array
    {
        return [
            'id_rwy'                 => Nilai::mentah($r['id_riwayat_seminar']),
            'nip'                    => Nilai::mentah($r['nip']),
            'jenis'                  => self::sama($r['jenis_seminar'], '1') ? 'Seminar' : 'Kursus',
            'tgl_sertifikat'         => Nilai::mentah($r['tgl_sertifikat']),
            'no_sertifikat'          => Nilai::mentah($r['no_sertifikat']),
            'bidang'                 => Nilai::mentah($r['bidang_seminar']),
            'nama'                   => Nilai::mentah($r['nama_seminar']),
            'jumlah_jp'              => Nilai::mentah($r['jumlah_jp']),
            'instansi_penyelenggara' => Nilai::mentah($r['instansi_penyelenggara']),
            'keterangan'             => Nilai::mentah($r['keterangan']),
        ];
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, string|null>
     */
    private static function barisOrganisasi(array $r): array
    {
        return [
            'id_rwy'          => Nilai::mentah($r['id_riwayat_organisasi']),
            'nip'             => Nilai::mentah($r['nip']),
            'tgl_mulai'       => Nilai::mentah($r['tgl_mulai']),
            'tgl_akhir'       => Nilai::mentah($r['tgl_akhir']),
            'nama_organisasi' => Nilai::mentah($r['nama_organisasi']),
            'kedudukan'       => Nilai::mentah($r['kedudukan']),
            'keterangan'      => Nilai::mentah($r['keterangan']),
        ];
    }

    // ------------------------------------------------------------------
    // CPNS/PNS/KP `AE:1438-1466`, `:1523-1551`, `:1608-1636`, `:1693-1722`
    // ------------------------------------------------------------------

    private function kp(string $tabel, string $nip): BaseBuilder
    {
        return $this->db->table($tabel . ' x')
            ->select('x.*, jkp.jenis_kp AS jenis_kp_upd, pang.gol AS gol_upd, pang.ruang AS ruang_upd, pang.gol_ruang AS gol_ruang_upd, pang.pangkat AS pangkat_upd')
            ->join('jenis_kp jkp', 'jkp.id_jenis_kp = x.id_jenis_kp', 'left')
            ->join('pangkat pang', 'pang.id_pangkat = x.id_pangkat', 'left')
            ->where('x.nip', $nip);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, string|null>
     */
    private static function barisKp(array $r): array
    {
        return [
            'id_rwy'     => Nilai::mentah($r['id_riwayat_kp']),
            'nip'        => Nilai::mentah($r['nip']),
            'tmtsk'      => Nilai::mentah($r['tmtsk']),
            'tgl_sk'     => Nilai::mentah($r['tgl_sk']),
            'no_sk'      => Nilai::mentah($r['no_sk']),
            'jenis_kp'   => Nilai::mentah($r['jenis_kp_upd']),
            'gol'        => Nilai::mentah($r['gol_upd']),
            'ruang'      => Nilai::mentah($r['ruang_upd']),
            'gol_ruang'  => Nilai::mentah($r['gol_ruang_upd']),
            'pangkat'    => Nilai::mentah($r['pangkat_upd']),
            'mker_th'    => Nilai::mentah($r['mker_th']),
            'mker_bl'    => Nilai::mentah($r['mker_bl']),
            'gaji_pokok' => Nilai::mentah($r['gaji_pokok']),
        ];
    }

    // ------------------------------------------------------------------
    // jabatan `AE:1782-1812`, `AE:1869-1902`
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, string|null>
     */
    private static function barisJabatan(array $r, bool $riwayat): array
    {
        $awal = [
            'id_rwy' => Nilai::mentah($r['id_riwayat_mutasi_jabatan']),
            'nip'    => Nilai::mentah($r['nip']),
            'tmtsk'  => Nilai::mentah($r['tmtsk']),
            'tgl_sk' => Nilai::mentah($r['tgl_sk']),
            'no_sk'  => Nilai::mentah($r['no_sk']),
        ];

        if ($riwayat) {
            // [K AE:1884-1885] ejaan legacy "Nomenlaktur" dipertahankan.
            $awal['jenis_jabatan']            = self::sama($r['jenis_jabatan'], '1') ? 'Nomenlaktur' : (self::sama($r['jenis_jabatan'], '2') ? 'Instansi Lain' : 'Pelaksana Tugas (PLT)');
            $awal['jenis_jabatan_koordinasi'] = self::sama($r['jenis_jabatan_koord'], '1') ? 'Koordinator' : (self::sama($r['jenis_jabatan_koord'], '2') ? 'Subkoordinator' : 'Tidak Ada');
        }

        return $awal + [
            'id_jabatan'            => Nilai::mentah($r['id_jabatan']),
            'id_jabatan_koordinasi' => Nilai::mentah($r['id_jabatan_koord']),
            'jabatan'               => Nilai::mentah($r['jabatan']),
            'jabatan_koordinasi'    => Nilai::mentah($r['jabatan_koord']),
            'jabatan_lain'          => Nilai::mentah($r['jabatan_lain']),
            'kelas_jabatan'         => Nilai::mentah($r['kelas_jabatan']),
            'group_jabatan'         => Nilai::mentah($r['group_jabatan']),
            'sub_group_jabatan'     => Nilai::mentah($r['sub_group_jabatan']),
            'unit'                  => Nilai::mentah($r['unit']),
            'satker'                => Nilai::mentah($r['satker']),
            'atasan_es_1'           => Nilai::mentah($r['atasan_es_1']),
            'atasan_es_2'           => Nilai::mentah($r['atasan_es_2']),
            'atasan_es_3'           => ! self::kosong($r['atasan_es_3_koord']) ? Nilai::mentah($r['atasan_es_3_koord']) : Nilai::mentah($r['atasan_es_3']),
            'atasan_es_4'           => ! self::kosong($r['atasan_es_4_koord']) ? Nilai::mentah($r['atasan_es_4_koord']) : Nilai::mentah($r['atasan_es_4']),
            'kredit_jft'            => Nilai::mentah($r['kredit_jft']),
            'no_induk_jft'          => Nilai::mentah($r['no_induk_jft']),
            'status_jft'            => self::sama($r['status_jft'], '1') ? 'Aktif' : (self::sama($r['status_jft'], '2') ? 'Pembebasan Sementara' : ''),
        ];
    }

    // ------------------------------------------------------------------
    // KGB `AE:1962-1978`, `AE:2035-2052`
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, string|null>
     */
    private static function barisKgb(array $r): array
    {
        return [
            'id_rwy'                      => Nilai::mentah($r['id_riwayat_kgb']),
            'nip'                         => Nilai::mentah($r['nip']),
            'tmtsk'                       => Nilai::mentah($r['tmtsk']),
            'tgl_sk'                      => Nilai::mentah($r['tgl_sk']),
            'no_sk'                       => Nilai::mentah($r['no_sk']),
            'mker_gol_th'                 => Nilai::mentah($r['mker_gol_th']),
            'gaji_pokok'                  => Nilai::mentah($r['gaji_pokok']),
            'jabatan_yang_menandatangani' => Nilai::mentah($r['jym']),
        ];
    }

    // ------------------------------------------------------------------
    // tanda jasa `AE:2112-2132`, `AE:2189-2211`
    // ------------------------------------------------------------------

    private function tandaJasa(string $tabel, string $nip): BaseBuilder
    {
        return $this->db->table($tabel . ' x')->select('x.*, tj.tanda_jasa AS tanda_jasa_upd')
            ->join('tanda_jasa tj', 'tj.id_tanda_jasa = x.id_tanda_jasa', 'left')->where('x.nip', $nip);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, string|null>
     */
    private static function barisTandaJasa(array $r, bool $riwayat): array
    {
        // [K] hanya riwayat yang memeriksa 'LAIN-LAIN' (snapshot tidak punya kolom tanda_jasa_lain).
        $nama = $riwayat && ($r['tanda_jasa'] ?? null) === 'LAIN-LAIN' ? Nilai::mentah($r['tanda_jasa_lain']) : Nilai::mentah($r['tanda_jasa_upd']);

        return [
            'id_rwy'         => Nilai::mentah($r['id_riwayat_tanda_jasa']),
            'nip'            => Nilai::mentah($r['nip']),
            'tgl_sertifikat' => Nilai::mentah($r['tgl_sertifikat']),
            'no_sertifikat'  => Nilai::mentah($r['no_sertifikat']),
            'tanda_jasa'     => $nama,
            'negara'         => Nilai::mentah($r['negara']),
            'keterangan'     => Nilai::mentah($r['keterangan']),
        ];
    }

    // ------------------------------------------------------------------
    // hukdis `AE:2271-2306` (tidak ada hukdis_terakhir [K])
    // ------------------------------------------------------------------

    private function hukdis(string $nip): BaseBuilder
    {
        return $this->db->table('riwayat_hukdis rhd')
            ->select('rhd.*, pk.gol AS gol_upd, pk.ruang AS ruang_upd, pk.gol_ruang AS gol_ruang_upd, pk.pangkat AS pangkat_upd, jh.jenis_hukdis AS jenis_hukdis_upd, th.tingkat_hukdis AS tingkat_hukdis_upd')
            ->join('pangkat pk', 'pk.id_pangkat = rhd.id_pangkat', 'left')
            ->join('jenis_hukdis jh', 'jh.id_jenis_hukdis = rhd.id_jenis_hukdis', 'left')
            ->join('tingkat_hukdis th', 'th.id_tingkat_hukdis = jh.id_tingkat_hukdis', 'left')
            ->where('rhd.nip', $nip)->where('rhd.status', 1)
            ->orderBy('rhd.tmtsk', 'ASC')->orderBy('rhd.id_riwayat_hukdis', 'ASC');
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, string|null>
     */
    private static function barisHukdis(array $r): array
    {
        return [
            'id_rwy'           => Nilai::mentah($r['id_riwayat_hukdis']),
            'nip'              => Nilai::mentah($r['nip']),
            'tmtsk'            => Nilai::mentah($r['tmtsk']),
            'tgl_sk'           => Nilai::mentah($r['tgl_sk']),
            'no_sk'            => Nilai::mentah($r['no_sk']),
            'tingkat_hukdis'   => Nilai::mentah($r['tingkat_hukdis_upd']),
            'jenis_hukdis'     => Nilai::mentah($r['jenis_hukdis_upd']),
            'gol'              => Nilai::mentah($r['gol_upd']),
            'ruang'            => Nilai::mentah($r['ruang_upd']),
            'gol_ruang'        => Nilai::mentah($r['gol_ruang_upd']),
            'pangkat'          => Nilai::mentah($r['pangkat_upd']),
            'masa_hukuman'     => Nilai::mentah($r['masa_hukuman']),
            'akhir_hukdis'     => Nilai::mentah($r['akhir_hukdis']),
            'aturan_dilanggar' => Nilai::mentah($r['aturan_dilanggar']),
            'alasan_hukuman'   => Nilai::mentah($r['alasan_hukuman']),
            'keterangan'       => Nilai::mentah($r['keterangan']),
        ];
    }

    // ------------------------------------------------------------------
    // Bantu
    // ------------------------------------------------------------------

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $peta
     *
     * @return array<string, mixed>|null
     */
    private function satu(BaseBuilder $q, callable $peta): ?array
    {
        $r = $q->limit(1)->get()->getRowArray();

        return $r === null ? null : $peta($r);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $peta
     *
     * @return list<array<string, mixed>>
     */
    private function daftar(BaseBuilder $q, callable $peta): array
    {
        return array_values(array_map($peta, $q->get()->getResultArray()));
    }

    /**
     * [K] `$r[id] == '<kode lain>' ? $r[lain] : $r[upd]` (id 99/9999/9999999/9999999999/98/1185 = "lain").
     *
     * @param array<string, mixed> $r
     */
    private static function lain(array $r, string $kolomId, string $kode, string $kolomLain, string $kolomUpd): ?string
    {
        return self::sama($r[$kolomId] ?? null, $kode) ? Nilai::mentah($r[$kolomLain] ?? null) : Nilai::mentah($r[$kolomUpd] ?? null);
    }

    /**
     * [K AE:356, :795] nilai: NEM untuk jenjang 1/2/3, selain itu IPK (`in_array` legacy).
     *
     * @param array<string, mixed> $r
     */
    private static function nilaiPendidikan(array $r): ?string
    {
        return in_array((string) ($r['id_jenjang_pendidikan'] ?? ''), ['1', '2', '3'], true) ? Nilai::mentah($r['nem']) : Nilai::mentah($r['ipk']);
    }

    /**
     * Perbandingan longgar legacy `$a == 'kode'` untuk nilai kolom (string/angka/null).
     */
    private static function sama(mixed $nilai, string $kode): bool
    {
        return $nilai !== null && (string) $nilai === $kode;
    }

    /**
     * Legacy `$x != ''` salah ↔ null atau "" (perbandingan longgar PHP).
     */
    private static function kosong(mixed $nilai): bool
    {
        return $nilai === null || (string) $nilai === '';
    }
}
