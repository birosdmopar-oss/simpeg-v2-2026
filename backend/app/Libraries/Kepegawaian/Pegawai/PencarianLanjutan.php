<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Pegawai;

use App\Exceptions\ValidationException;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use LogicException;

/**
 * Pencarian lanjutan pegawai (B-20 penutup, MAKE-014) — port legacy `L_employee::advsearch` (:10900-11557,
 * `controllers/hr/Employee.php:1047` role 1, 3, 4, 5; keputusan user 13-10-2026 Q-M6-1).
 *
 * Bentuk sama dengan legacy: setiap kriteria = jenis riwayat (`jenis_rwy`) × jenis data (`last` = snapshot `pegawai_*`
 * / `rwy` = seluruh riwayat `riwayat_*`, status 1) × atribut × operator (`= != < <= > >= IN, NOT IN, LIKE`) × nilai,
 * digabung `AND`/`OR` (kriteria pertama tanpa penghubung; presedensi SQL: AND lebih dulu). Hanya pegawai status 1.
 *
 * [V2] Atribut & operator WHITELIST (diambil dari `$const_attr`/`$const_opr` legacy; di luar itu 422), nilai lewat
 * query builder (tidak diinterpolasi — legacy menyisipkan kolom/nilai langsung ke SQL), lingkup role 3 lewat
 * PegawaiScope jalur daftar (legacy tidak membatasi), setiap kriteria riwayat dievaluasi sebagai `EXISTS` per baris
 * riwayat (legacy LEFT JOIN + GROUP BY), dan hasil = kolom Daftar Pegawai (legacy menampilkan semua atribut tabel).
 */
final class PencarianLanjutan
{
    /** @var list<string> */
    public const OPERATOR = ['=', '!=', '<', '<=', '>', '>=', 'IN', 'NOT IN', 'LIKE'];

    public const MAKS_KRITERIA = 10;
    public const MAKS_NILAI_IN = 50;

    /**
     * Jenis riwayat legacy `$const_rwy` + tabel `$const_tbl` (null = tidak tersedia; legacy: umum/seminar/org/skp tanpa
     * data terakhir).
     *
     * @var array<string, array{label: string, last: string|null, rwy: string}>
     */
    public const JENIS = [
        'umum'       => ['label' => 'Umum', 'last' => null, 'rwy' => 'pegawai'],
        'alamat'     => ['label' => 'Alamat', 'last' => 'pegawai_alamat', 'rwy' => 'riwayat_alamat'],
        'keluarga'   => ['label' => 'Keluarga', 'last' => 'pegawai_keluarga', 'rwy' => 'riwayat_keluarga'],
        'pendidikan' => ['label' => 'Pendidikan', 'last' => 'pegawai_pendidikan', 'rwy' => 'riwayat_pendidikan'],
        'pelatihan'  => ['label' => 'Pelatihan', 'last' => 'pegawai_diklat', 'rwy' => 'riwayat_diklat'],
        'seminar'    => ['label' => 'Kursus/Seminar', 'last' => null, 'rwy' => 'riwayat_seminar'],
        'org'        => ['label' => 'Organisasi', 'last' => null, 'rwy' => 'riwayat_organisasi'],
        'skp'        => ['label' => 'Sasaran Kerja', 'last' => null, 'rwy' => 'riwayat_skp'],
        'pangkat'    => ['label' => 'Pangkat', 'last' => 'pegawai_kp', 'rwy' => 'riwayat_kp'],
        'jabatan'    => ['label' => 'Jabatan', 'last' => 'pegawai_mutasi_jabatan', 'rwy' => 'riwayat_mutasi_jabatan'],
        'kgb'        => ['label' => 'KGB', 'last' => 'pegawai_kgb', 'rwy' => 'riwayat_kgb'],
        'tj'         => ['label' => 'Tanda Jasa', 'last' => 'pegawai_tanda_jasa', 'rwy' => 'riwayat_tanda_jasa'],
        'hd'         => ['label' => 'Hukuman Disiplin', 'last' => 'pegawai_hukdis', 'rwy' => 'riwayat_hukdis'],
    ];

    /**
     * Atribut per jenis = legacy `$const_attr` (kolom => label).
     *
     * @var array<string, array<string, string>>
     */
    public const ATRIBUT = [
        'umum' => [
            'nama'              => 'Nama Pegawai', 'glr_awal' => 'Glr. Awal', 'glr_akhir' => 'Glr. Akhir', 'tgl_lahir' => 'Tgl. Lahir',
            'provinsi_lahir'    => 'Provinsi Lahir', 'kabupaten_kota_lahir' => 'Kabupaten/Kota Lahir', 'id_agama' => 'Agama',
            'jenis_kelamin'     => 'Jenis Kelamin', 'npwp' => 'NPWP', 'no_hp' => 'No. HP', 'no_telp_kerabat' => 'No. Telp. Kerabat',
            'status_pernikahan' => 'Status Pernikahan', 'id_jenis_pegawai' => 'Jenis Pegawai', 'id_jenis_status' => 'Jenis Status',
            'tmt_status'        => 'TMT Status',
        ],
        'alamat' => [
            'provinsi' => 'Provinsi', 'kabupaten_kota' => 'Kabupaten/Kota', 'kecamatan' => 'Kecamatan', 'kelurahan' => 'Kelurahan',
            'kd_pos'   => 'Kode Pos', 'alamat' => 'Alamat',
        ],
        'keluarga' => [
            'urutan_perkawinan' => 'Urutan Perkawinan', 'tgl_perkawinan' => 'Tgl. Perkawinan', 'kota_perkawinan' => 'Kota Perkawinan',
            'nama_pasangan'     => 'Nama Pasangan', 'jumlah_anak' => 'Jumlah Anak',
        ],
        'pendidikan' => [
            'id_jenjang_pendidikan' => 'Jenjang Pendidikan', 'id_bidang_pendidikan' => 'Bidang Pendidikan',
            'id_jurusan_pendidikan' => 'Jurusan Pendidikan', 'tgl_lulus' => 'Tgl. Lulus', 'no_ijazah' => 'No. Ijazah',
            'institusi_pendidikan'  => 'Institusi Pendidikan', 'nem' => 'NEM', 'ipk' => 'IPK',
        ],
        'pelatihan' => [
            'jenis_diklat' => 'Jenis Diklat', 'tgl_sertifikat' => 'Tgl. Sertifikat', 'no_sertifikat' => 'No. Sertifikat',
            'jumlah_jp'    => 'Jumlah JP', 'deskripsi' => 'Nama Diklat', 'instansi_penyelenggara' => 'Instansi Penyelenggara',
        ],
        'seminar' => [
            'jenis_seminar' => 'Jenis', 'bidang_seminar' => 'Bidang', 'nama_seminar' => 'Nama', 'tgl_sertifikat' => 'Tgl. Sertifikat',
            'no_sertifikat' => 'No. Sertifikat', 'jumlah_jp' => 'Jumlah JP', 'instansi_penyelenggara' => 'Instansi Penyelenggara',
        ],
        'org' => [
            'nama_organisasi' => 'Nama Organisasi', 'kedudukan' => 'Kedudukan', 'tgl_mulai' => 'Tgl. Mulai', 'tgl_akhir' => 'Tgl. Akhir',
        ],
        'skp' => [
            'tahun'                   => 'Tahun', 'tgl_mulai' => 'Tgl. Mulai', 'tgl_akhir' => 'Tgl. Akhir', 'nip_penilai' => 'NIP Penilai',
            'nama_penilai'            => 'Nama Penilai', 'jabatan_penilai' => 'Jabatan Penilai', 'nip_atasan_penilai' => 'NIP Atasan Penilai',
            'nama_atasan_penilai'     => 'Nama Atasan Penilai', 'jabatan_atasan_penilai' => 'Jabatan Atasan Penilai',
            'nilai_skp'               => 'Nilai Kinerja', 'nilai_perilaku' => 'Nilai Perilaku', 'nilai_prestasi_kerja' => 'Nilai Prestasi Kerja',
            'kategori_nilai_prestasi' => 'Kategori Nilai',
        ],
        'pangkat' => [
            'id_jenis_kp' => 'Jenis KP', 'id_pangkat' => 'Pangkat', 'tmtsk' => 'TMT KP', 'tgl_sk' => 'Tgl. SK', 'no_sk' => 'No. SK',
            'mker_th'     => 'MKer Th.', 'mker_bl' => 'MKer Bl.', 'gaji_pokok' => 'Gaji Pokok',
        ],
        'jabatan' => [
            'id_group_jabatan'     => 'Group Jabatan', 'sub_group_jabatan' => 'Sub Group Jabatan', 'id_unit' => 'Unit', 'satker' => 'Satker',
            'id_atasan_es_1'       => 'Jabatan Atasan Es.I', 'id_atasan_es_2' => 'Jabatan Atasan Es.II', 'id_atasan_es_3' => 'Jabatan Atasan Es.III',
            'id_atasan_es_3_koord' => 'Jabatan Atasan Koordinator', 'id_atasan_es_4' => 'Jabatan Atasan Es.IV',
            'id_atasan_es_4_koord' => 'Jabatan Atasan Subkoordinator', 'jabatan' => 'Jabatan', 'jabatan_koord' => 'Jabatan Koordinasi',
            'jenis_jabatan_koord'  => 'Jenis Jabatan Koordinasi', 'tmtsk' => 'TMT Jabatan', 'no_sk' => 'No. SK', 'tgl_sk' => 'Tgl. SK',
            'jabatan_lain'         => 'Jabatan Lain', 'kelas_jabatan' => 'Kelas Jabatan', 'kredit_jft' => 'Kredit JFT',
            'no_induk_jft'         => 'No. Induk JFT', 'status_jft' => 'Status JFT',
        ],
        'kgb' => [
            'tmtsk' => 'TMT KGB', 'no_sk' => 'No. SK', 'tgl_sk' => 'Tgl. SK', 'mker_gol_th' => 'MKer Gol. Th.', 'gaji_pokok' => 'Gaji Pokok',
            'jym'   => 'Pejabat Yang Menandatangani',
        ],
        'tj' => [
            'id_tanda_jasa' => 'Jenis Tanda Jasa', 'tgl_sertifikat' => 'Tgl. Sertifikat', 'no_sertifikat' => 'No. Sertifikat',
            'tanda_jasa'    => 'Nama Tanda Jasa', 'tanda_jasa_lain' => 'Nama Tanda Jasa Lain', 'negara' => 'Negara',
        ],
        'hd' => [
            'id_tingkat_hukdis' => 'Tingkat Hukdis', 'id_jenis_hukdis' => 'Jenis Hukdis', 'tmtsk' => 'TMT Hukdis', 'tgl_sk' => 'Tgl. SK',
            'no_sk'             => 'No. SK', 'masa_hukuman' => 'Masa Hukuman', 'akhir_hukdis' => 'Tgl. Akhir Hukdis',
            'aturan_dilanggar'  => 'Aturan Dilanggar', 'alasan_hukuman' => 'Alasan Hukuman',
        ],
    ];

    /**
     * Atribut berpilihan (legacy `$const_dm`): entity master options v2 atau daftar tetap. [V2] `id_tingkat_hukdis` /
     * `id_jenis_hukdis` memakai master yang benar (legacy tertukar: tanda_jasa / tingkat_hukdis).
     *
     * @var array<string, string|list<array{id: string, nama: string}>>
     */
    public const PILIHAN = [
        'id_agama'              => 'agama',
        'jenis_kelamin'         => [['id' => '1', 'nama' => 'Laki-laki'], ['id' => '2', 'nama' => 'Perempuan']],
        'id_jenis_pegawai'      => 'jenis-pegawai',
        'id_jenis_status'       => 'jenis-status',
        'id_jenjang_pendidikan' => 'jenjang-pendidikan',
        'id_bidang_pendidikan'  => 'bidang-pendidikan',
        'id_jurusan_pendidikan' => 'jurusan-pendidikan',
        'id_jenis_kp'           => 'jenis-kp',
        'id_pangkat'            => 'pangkat',
        'id_group_jabatan'      => 'group-jabatan',
        'id_unit'               => 'unit',
        'jenis_jabatan_koord'   => [['id' => '1', 'nama' => 'Koordinator'], ['id' => '2', 'nama' => 'Subkoordinator'], ['id' => '3', 'nama' => 'Tidak Ada']],
        'status_jft'            => [['id' => '1', 'nama' => 'Aktif'], ['id' => '2', 'nama' => 'Pembebasan Sementara']],
        'id_tanda_jasa'         => 'tanda-jasa',
        'id_tingkat_hukdis'     => 'tingkat-hukdis',
        'id_jenis_hukdis'       => 'jenis-hukdis',
    ];

    /**
     * Atribut yang hanya ada di tabel riwayat (snapshot v2 tidak punya kolomnya) → hanya jenis data `rwy`.
     *
     * @var array<string, list<string>>
     */
    public const HANYA_RWY = ['pelatihan' => ['jenis_diklat'], 'tj' => ['tanda_jasa_lain']];

    /**
     * Katalog untuk form (jenis → jenis data, atribut, pilihan; operator).
     *
     * @return array{jenis: list<array{key: string, label: string, jenis_data: list<string>, atribut: list<array{field: string, label: string, pilihan: string|list<array{id: string, nama: string}>|null, hanya_rwy: bool}>}>, operator: list<string>}
     */
    public static function katalog(): array
    {
        $jenis = [];

        foreach (self::JENIS as $key => $j) {
            $jenis[] = [
                'key'        => $key,
                'label'      => $j['label'],
                'jenis_data' => $j['last'] === null ? ['rwy'] : ['last', 'rwy'],
                'atribut'    => array_map(
                    static fn (string $field, string $label): array => [
                        'field'     => $field,
                        'label'     => $label,
                        'pilihan'   => self::PILIHAN[$field] ?? null,
                        'hanya_rwy' => in_array($field, self::HANYA_RWY[$key] ?? [], true),
                    ],
                    array_keys(self::ATRIBUT[$key]),
                    array_values(self::ATRIBUT[$key]),
                ),
            ];
        }

        return ['jenis' => $jenis, 'operator' => self::OPERATOR];
    }

    /**
     * Validasi kriteria (whitelist) → bentuk normal. 422 per `kriteria.<i>.<kunci>`.
     *
     * @return list<array{jenis_rwy: string, tabel: string, atribut: string, operator: string, nilai: string|list<string>, hubung: string}>
     */
    public static function normalisasi(mixed $kriteria): array
    {
        if (! is_array($kriteria) || $kriteria === [] || ! array_is_list($kriteria)) {
            throw ValidationException::forField('kriteria', 'Isi minimal satu kriteria pencarian.');
        }

        if (count($kriteria) > self::MAKS_KRITERIA) {
            throw ValidationException::forField('kriteria', 'Maksimal ' . self::MAKS_KRITERIA . ' kriteria pencarian.');
        }

        $hasil  = [];
        $errors = [];

        foreach ($kriteria as $i => $k) {
            $k     = is_array($k) ? $k : [];
            $jenis = self::teks($k['jenis_rwy'] ?? null);
            $data  = self::teks($k['jenis_data'] ?? null);
            $attr  = self::teks($k['atribut'] ?? null);
            $op    = strtoupper(self::teks($k['operator'] ?? null));
            $hub   = strtoupper(self::teks($k['hubung'] ?? 'AND'));
            $nilai = $k['nilai'] ?? null;
            $err   = [];

            if (! isset(self::JENIS[$jenis])) {
                $err['jenis_rwy'] = 'Jenis riwayat tidak dikenal.';
            } else {
                $tabel = self::JENIS[$jenis][$data === 'last' ? 'last' : 'rwy'];

                if (! in_array($data, ['last', 'rwy'], true) || $tabel === null) {
                    $err['jenis_data'] = 'Jenis data tidak tersedia untuk jenis riwayat ini.';
                }

                if (! isset(self::ATRIBUT[$jenis][$attr])) {
                    $err['atribut'] = 'Atribut tidak dikenal untuk jenis riwayat ini.';
                } elseif ($data === 'last' && in_array($attr, self::HANYA_RWY[$jenis] ?? [], true)) {
                    $err['atribut'] = 'Atribut ini hanya tersedia untuk Seluruh Riwayat.';
                }
            }

            if (! in_array($op, self::OPERATOR, true)) {
                $err['operator'] = 'Operator tidak dikenal.';
            }

            if ($i > 0 && ! in_array($hub, ['AND', 'OR'], true)) {
                $err['hubung'] = 'Penghubung harus AND atau OR.';
            }

            if (! is_scalar($nilai) || trim((string) $nilai) === '' || mb_strlen((string) $nilai) > 255) {
                $err['nilai'] = 'Nilai wajib diisi (maksimal 255 karakter).';
            }

            $isi = is_scalar($nilai) ? trim((string) $nilai) : '';

            // Perkuat pembentukan SQL: pola `:nama:` ditolak (titik dua tunggal tetap boleh, mis. "Jl. A: 5").
            if (! isset($err['nilai']) && preg_match('/:[A-Za-z0-9_.\-]+:/', $isi) === 1) {
                $err['nilai'] = 'Nilai tidak boleh memuat pola :kata:.';
            }

            if (! isset($err['operator']) && ! isset($err['nilai']) && in_array($op, ['IN', 'NOT IN'], true)) {
                $isi = array_values(array_filter(array_map('trim', explode(',', $isi)), static fn (string $v): bool => $v !== ''));

                if ($isi === [] || count($isi) > self::MAKS_NILAI_IN) {
                    $err['nilai'] = 'Daftar nilai IN dipisah koma, 1–' . self::MAKS_NILAI_IN . ' nilai.';
                }
            }

            foreach ($err as $kunci => $pesan) {
                $errors["kriteria.{$i}.{$kunci}"] = [$pesan];
            }

            if ($err === []) {
                $hasil[] = [
                    'jenis_rwy' => $jenis,
                    'tabel'     => (string) self::JENIS[$jenis][$data === 'last' ? 'last' : 'rwy'],
                    'atribut'   => $attr,
                    'operator'  => $op,
                    'nilai'     => $isi,
                    'hubung'    => $i === 0 ? 'AND' : $hub,
                ];
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        return $hasil;
    }

    /**
     * Terapkan kriteria (sudah dinormalisasi) ke builder daftar pegawai (`pegawai` sebagai tabel utama).
     *
     * Dua tahap: NIP yang cocok dicari dengan SQL ber-bind posisi (`?`) yang dirakit dari whitelist (identifier) dan
     * hanya placeholder (tanpa literal nilai), lalu builder daftar disaring `whereIn` NIP itu — teks SQL kriteria tidak
     * pernah digabung ke builder lain.
     *
     * @param list<array{jenis_rwy: string, tabel: string, atribut: string, operator: string, nilai: string|list<string>, hubung: string}> $kriteria
     */
    public static function terapkan(BaseBuilder $builder, array $kriteria, BaseConnection $db): void
    {
        [$sql, $binds] = self::sqlNip($kriteria, $db);
        $nip           = array_column($db->query($sql, $binds)->getResultArray(), 'nip');

        $nip === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn('pegawai.nip', array_map('strval', $nip));
    }

    /**
     * SQL pencarian NIP (pegawai status 1, legacy) + bind posisi. Identifier hanya dari whitelist JENIS/ATRIBUT.
     *
     * @param list<array{jenis_rwy: string, tabel: string, atribut: string, operator: string, nilai: string|list<string>, hubung: string}> $kriteria
     *
     * @return array{0: string, 1: list<int|string>}
     */
    public static function sqlNip(array $kriteria, BaseConnection $db): array
    {
        $id     = static fn (string $nama): string => $db->escapeIdentifiers($nama);
        $peg    = $id($db->prefixTable('pegawai'));
        $binds  = [1];
        $syarat = '';

        foreach ($kriteria as $i => $k) {
            if (! isset(self::JENIS[$k['jenis_rwy']], self::ATRIBUT[$k['jenis_rwy']][$k['atribut']]) || ! in_array($k['operator'], self::OPERATOR, true)) {
                throw new LogicException('Kriteria pencarian belum dinormalisasi.');
            }

            [$op, $nilai] = self::operasi($k['operator'], $k['nilai']);
            array_push($binds, ...$nilai);

            if ($k['tabel'] === 'pegawai') {
                $kondisi = "p.{$id($k['atribut'])} {$op}";
            } else {
                $status  = str_starts_with($k['tabel'], 'riwayat_') ? ' AND r.' . $id('status') . ' = 1' : '';
                $kondisi = 'EXISTS (SELECT 1 FROM ' . $id($db->prefixTable($k['tabel'])) . ' r WHERE r.' . $id('nip') . ' = p.' . $id('nip')
                    . $status . " AND r.{$id($k['atribut'])} {$op})";
            }

            $syarat .= ($i === 0 ? '' : ($k['hubung'] === 'OR' ? ' OR ' : ' AND ')) . "({$kondisi})";
        }

        return ["SELECT p.{$id('nip')} AS nip FROM {$peg} p WHERE p.{$id('status')} = ? AND ({$syarat})", $binds];
    }

    /**
     * Operator → potongan SQL ber-placeholder + nilai bind.
     *
     * @param list<string>|string $nilai
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function operasi(string $op, array|string $nilai): array
    {
        if (in_array($op, ['IN', 'NOT IN'], true)) {
            $daftar = array_map('strval', is_array($nilai) ? $nilai : [$nilai]);

            return [$op . ' (' . implode(', ', array_fill(0, count($daftar), '?')) . ')', $daftar];
        }

        if ($op === 'LIKE') {
            // Kata kunci literal (legacy `%nilai%`): wildcard di dalam nilai di-escape.
            return ["LIKE ? ESCAPE '!'", ['%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $nilai) . '%']];
        }

        return ["{$op} ?", [(string) $nilai]];
    }

    private static function teks(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
