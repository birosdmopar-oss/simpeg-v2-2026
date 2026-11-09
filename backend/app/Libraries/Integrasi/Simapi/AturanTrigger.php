<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Simapi;

use Closure;

/**
 * MAKE-060 A5b (F6-06, DBV-031 §4) — port aturan trigger legacy penulis `simapi_ch` (D1 ISSUE-056, struktur produksi; isi mentah
 * tidak disalin): untuk satu perubahan baris (`INSERT`/`UPDATE`/`DELETE`, nilai SEBELUM `$lama` dan SESUDAH `$baru`) hasilkan
 * baris `simapi_ch` yang akan ditulis trigger legacy. Murni (tanpa DB/IO); lookup yang di legacy dilakukan trigger (NIP keluarga
 * untuk `detail_anak`, URL foto) diberikan pemanggil lewat `$bantu`.
 *
 * Semantik MySQL yang ditiru persis:
 *  - `CONCAT(...)` bernilai NULL bila salah satu bagian NULL ({@see gabung()});
 *  - `COALESCE(x, '')` ({@see k()}); perbandingan `x = 'v'` / `x != y` bernilai NULL (salah) bila salah satu NULL;
 *  - pemisah `|`; tanggal/angka apa adanya dari kolom (tanpa DATE_FORMAT di trigger);
 *  - cabang ganti NIP (`OLD.nip != NEW.nip`) → baris memakai OLD.nip.
 * Penyimpangan [V2]: URL foto pegawai = `PenyajiPegawai::urlFoto()` (Q6-39), bukan host arsip legacy.
 */
final class AturanTrigger
{
    public const INSERT = 'INSERT';
    public const UPDATE = 'UPDATE';
    public const DELETE = 'DELETE';

    /** Tabel riwayat penulis simapi_ch → [id_simapi_jd, PK]. [K D1] */
    public const RIWAYAT = [
        'riwayat_alamat'         => [2, 'id_riwayat_alamat'],
        'riwayat_keluarga'       => [3, 'id_riwayat_keluarga'],
        'riwayat_pendidikan'     => [4, 'id_riwayat_pendidikan'],
        'riwayat_diklat'         => [5, 'id_riwayat_diklat'],
        'riwayat_seminar'        => [6, 'id_riwayat_seminar'],
        'riwayat_organisasi'     => [7, 'id_riwayat_organisasi'],
        'riwayat_skp'            => [8, 'id_riwayat_skp'],
        'riwayat_kp'             => [9, 'id_riwayat_kp'],
        'riwayat_mutasi_jabatan' => [10, 'id_riwayat_mutasi_jabatan'],
        'riwayat_kgb'            => [11, 'id_riwayat_kgb'],
        'riwayat_tanda_jasa'     => [12, 'id_riwayat_tanda_jasa'],
        'riwayat_hukdis'         => [13, 'id_riwayat_hukdis'],
    ];

    /** Semua tabel sumber (15). */
    public static function tabel(): array
    {
        return array_merge(['pegawai', 'pengguna', 'detail_anak'], array_keys(self::RIWAYAT));
    }

    /**
     * @param array<string, mixed>|null $lama
     * @param array<string, mixed>|null $baru
     * @param array{nip_keluarga?: Closure(mixed): ?string, url_foto?: Closure(string, string): string} $bantu
     *
     * @return list<array{id_simapi_jd: int, nip: ?string, id_rwy: ?int, action: int, field_name: ?string, field_value: ?string}>
     */
    public static function baris(string $tabel, string $peristiwa, ?array $lama, ?array $baru, array $bantu = []): array
    {
        return match (true) {
            $tabel === 'pegawai'         => self::pegawai($peristiwa, $lama, $baru, $bantu['url_foto'] ?? static fn (): string => ''),
            $tabel === 'pengguna'        => self::pengguna($peristiwa, $lama, $baru),
            $tabel === 'detail_anak'     => self::anak($peristiwa, $lama, $baru, $bantu['nip_keluarga'] ?? static fn (): ?string => null),
            isset(self::RIWAYAT[$tabel]) => self::riwayat($tabel, $peristiwa, $lama, $baru),
            default                      => [],
        };
    }

    // ------------------------------------------------------------------
    // pegawai (jd 1)
    // ------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private static function pegawai(string $p, ?array $lama, ?array $baru, Closure $urlFoto): array
    {
        if ($p === self::DELETE) {
            return [self::ch(1, $lama['nip'] ?? null, null, 2)];
        }

        $n = $baru ?? [];

        if ($p === self::INSERT) {
            if (! self::sama($n['status'] ?? null, '1')) {
                return [];
            }

            $status = 'Aktif';
        } else {
            if (self::sama($n['status'] ?? null, '1')) {
                $status = 'Aktif';
            } elseif (self::beda($n['status'] ?? null, '1') && self::sama($lama['status'] ?? null, '1')) {
                $status = 'Tidak Aktif';
            } else {
                return [];
            }
        }

        $prov = self::sama(self::k($n['id_provinsi_lahir'] ?? null), '99') && self::k($n['provinsi_lahir_lain'] ?? null) !== '' ? $n['provinsi_lahir_lain'] : self::k($n['provinsi_lahir'] ?? null);
        $kab  = self::sama(self::k($n['id_kabupaten_kota_lahir'] ?? null), '9999') && self::k($n['kabupaten_kota_lahir_lain'] ?? null) !== '' ? $n['kabupaten_kota_lahir_lain'] : self::k($n['kabupaten_kota_lahir'] ?? null);
        $jk   = match (self::k($n['jenis_kelamin'] ?? null, '0')) {
            '1'     => 'Laki-laki',
            '2'     => 'Perempuan',
            default => '',
        };
        $foto = self::k($n['foto'] ?? null) !== '' ? $urlFoto((string) $n['nip'], (string) $n['foto']) : '';

        $nilai = self::gabung($n['nip'] ?? null, '|', $n['nama'] ?? null, '|', self::k($n['glr_awal'] ?? null), '|', self::k($n['glr_akhir'] ?? null), '|', $n['tgl_lahir'] ?? null, '|', $prov, '|', $kab, '|', self::k($n['agama'] ?? null), '|', $jk, '||', self::k($n['jenis_pegawai'] ?? null), '|', $status, '|', $foto);
        $nip   = $p === self::UPDATE && self::beda($lama['nip'] ?? null, $n['nip'] ?? null) ? $lama['nip'] : ($n['nip'] ?? null);

        return [self::ch(1, $nip, null, 1, 'nip|nama|glr_awal|glr_akhir|tgl_lahir|provinsi_lahir|kabupaten_kota_lahir|agama|jenis_kelamin|email|jenis_pegawai|status|foto', $nilai)];
    }

    // ------------------------------------------------------------------
    // pengguna (jd 1) — hanya INSERT/UPDATE; v2: id_pegawai = nip, UserLevel = user_level
    // ------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private static function pengguna(string $p, ?array $lama, ?array $baru): array
    {
        if ($p === self::DELETE || $baru === null) {
            return [];
        }

        $level = in_array((string) ($baru['user_level'] ?? ''), ['2', '6', '7'], true) && ($baru['user_level'] ?? null) !== null;

        if (! self::sama($baru['status'] ?? null, '1') || ! $level) {
            return [];
        }

        if ($p === self::UPDATE && ! self::beda($lama['email'] ?? null, $baru['email'] ?? null)) {
            return []; // [K] `OLD.email != NEW.email` (NULL di salah satu → tidak menulis)
        }

        $nilai = self::gabung($baru['nip'] ?? null, '|', self::k($baru['email'] ?? null));
        $nip   = $p === self::UPDATE && self::beda($lama['nip'] ?? null, $baru['nip'] ?? null) ? $lama['nip'] : ($baru['nip'] ?? null);

        return [self::ch(1, $nip, null, 1, 'nip|email', $nilai)];
    }

    // ------------------------------------------------------------------
    // detail_anak (jd 14) — NIP dari riwayat_keluarga induk
    // ------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private static function anak(string $p, ?array $lama, ?array $baru, Closure $nipKeluarga): array
    {
        if ($p === self::DELETE) {
            return [self::ch(14, $nipKeluarga($lama['id_riwayat_keluarga'] ?? null), self::id($lama['id_detail_anak'] ?? null), 2)];
        }

        $n     = $baru ?? [];
        $id    = $n['id_detail_anak'] ?? null;
        $nilai = self::gabung($id, '|', $n['id_riwayat_keluarga'] ?? null, '|', self::k($n['urutan'] ?? null), '|', self::k($n['tgl_lahir'] ?? null), '|', self::k($n['nama'] ?? null), '|', self::k($n['tempat_lahir'] ?? null));

        return [self::ch(14, $nipKeluarga($n['id_riwayat_keluarga'] ?? null), self::id($id), 1, 'id_rwy|id_rwy_perkawinan|urutan_anak|tgl_lahir_anak|nama_anak|tempat_lahir_anak', $nilai)];
    }

    // ------------------------------------------------------------------
    // riwayat_* (jd 2..13)
    // ------------------------------------------------------------------

    /**
     * INSERT status 1 → action 1; UPDATE status 1 → 1, status 2 → 2 (status lain tidak menulis); DELETE → 2 (OLD.id, OLD.nip).
     * `riwayat_kp`: jenis KP 6 dikecualikan [K].
     *
     * @return list<array<string, mixed>>
     */
    private static function riwayat(string $tabel, string $p, ?array $lama, ?array $baru): array
    {
        [$jd, $pk] = self::RIWAYAT[$tabel];

        if ($p === self::DELETE) {
            return [self::ch($jd, $lama['nip'] ?? null, self::id($lama[$pk] ?? null), 2)];
        }

        $n   = $baru ?? [];
        $kp6 = $tabel === 'riwayat_kp';
        $nip = $p === self::UPDATE && self::beda($lama['nip'] ?? null, $n['nip'] ?? null) ? $lama['nip'] : ($n['nip'] ?? null);

        if (self::sama($n['status'] ?? null, '1') && (! $kp6 || self::beda($n['id_jenis_kp'] ?? null, '6'))) {
            [$field, $nilai] = self::isiRiwayat($tabel, $n);

            return [self::ch($jd, $nip, self::id($n[$pk] ?? null), 1, $field, $nilai)];
        }

        if ($p === self::UPDATE && self::sama($n['status'] ?? null, '2') && (! $kp6 || (self::beda($n['id_jenis_kp'] ?? null, '6') && self::beda($lama['id_jenis_kp'] ?? null, '6')))) {
            return [self::ch($jd, $nip, self::id($n[$pk] ?? null), 2)];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $n
     *
     * @return array{0: string, 1: ?string}
     */
    private static function isiRiwayat(string $tabel, array $n): array
    {
        $v  = static fn (string $k): mixed => $n[$k] ?? null;
        $c  = static fn (string $k): string => self::k($n[$k] ?? null);
        $id = $v(self::RIWAYAT[$tabel][1]);

        return match ($tabel) {
            'riwayat_alamat' => ['id_rwy|nip|jenis_alamat|alamat|provinsi|kabupaten_kota|kecamatan|kelurahan|kode_pos|nama_kantor|lantai|telp|faks', self::gabung(
                $id,
                '|',
                $v('nip'),
                '|',
                match ($c('jenis_alamat')) {
                    '1' => 'Alamat KTP', '2' => 'Alamat Domisili', '3' => 'Alamat Kantor', default => ''
                },
                '|',
                $c('alamat'),
                '|',
                self::lain($n, 'id_provinsi', '99', 'provinsi_lain', 'provinsi'),
                '|',
                self::lain($n, 'id_kabupaten_kota', '9999', 'kabupaten_kota_lain', 'kabupaten_kota'),
                '|',
                self::lain($n, 'id_kecamatan', '9999999', 'kecamatan_lain', 'kecamatan'),
                '|',
                self::lain($n, 'id_kelurahan', '9999999999', 'kelurahan_lain', 'kelurahan'),
                '|',
                $c('kd_pos'),
                '|',
                $c('nama_kantor'),
                '|',
                $c('lantai'),
                '|',
                $c('telp'),
                '|',
                $c('faks'),
            )],
            'riwayat_keluarga' => ['id_rwy|nip|urutan_perkawinan|tgl_perkawinan|kota_perkawinan|nama_pasangan|jumlah_anak', self::gabung(
                $id,
                '|',
                $v('nip'),
                '|',
                $c('urutan_perkawinan'),
                '|',
                $c('tgl_perkawinan'),
                '|',
                $c('kota_perkawinan'),
                '|',
                $c('nama_pasangan'),
                '|',
                self::k($v('jumlah_anak'), '0'),
            )],
            'riwayat_pendidikan' => ['id_rwy|nip|tgl_lulus|no_ijazah|institusi_pendidikan|jenjang_pendidikan_singkat|bidang_pendidikan|jurusan_pendidikan|nilai', self::gabung(
                $id,
                '|',
                $v('nip'),
                '|',
                $v('tgl_lulus'),
                '|',
                $c('no_ijazah'),
                '|',
                $c('institusi_pendidikan'),
                '|',
                $c('jenjang_pendidikan_singkat'),
                '|',
                self::sama($v('id_bidang_pendidikan'), '98') && $c('bidang_pendidikan_lain') !== '' ? $v('bidang_pendidikan_lain') : $c('bidang_pendidikan'),
                '|',
                self::sama($v('id_jurusan_pendidikan'), '1185') && $c('jurusan_pendidikan_lain') !== '' ? $v('jurusan_pendidikan_lain') : $c('jurusan_pendidikan'),
                '|',
                in_array(self::k($v('id_jenjang_pendidikan'), "\0"), ['1', '2', '3'], true) ? self::k($v('nem'), '0') : self::k($v('ipk'), '0'),
            )],
            'riwayat_diklat' => ['id_rwy|nip|jenis_pelatihan|nama_pelatihan|no_sertifikat|tgl_sertifikat|jumlah_jp|instansi_penyelenggara|keterangan', self::gabung(
                $id,
                '|',
                $v('nip'),
                '|',
                match (self::k($v('jenis_diklat'), '0')) {
                    '1' => 'Struktural', '2' => 'Teknis', '3' => 'Fungsional', '4' => 'Prajabatan', default => ''
                },
                '|',
                match (self::k($v('jenis_diklat'), '0')) {
                    '3'     => trim($c('deskripsi') . ' (' . $c('sub_group_jabatan') . ')'),
                    '4'     => trim($c('deskripsi') . ' (' . $c('nama_diklat') . ')'),
                    default => $c('nama_diklat'),
                },
                '|',
                $c('no_sertifikat'),
                '|',
                $c('tgl_sertifikat'),
                '|',
                $c('jumlah_jp'),
                '|',
                $c('instansi_penyelenggara'),
                '|',
                $c('keterangan'),
            )],
            'riwayat_seminar' => ['id_rwy|nip|jenis|tgl_sertifikat|no_sertifikat|bidang|nama|jumlah_jp|instansi_penyelenggara|keterangan', self::gabung(
                $id,
                '|',
                $v('nip'),
                '|',
                $c('jenis_seminar') === '1' ? 'Seminar' : 'Kursus',
                '|',
                $c('tgl_sertifikat'),
                '|',
                $c('no_sertifikat'),
                '|',
                $c('bidang_seminar'),
                '|',
                $c('nama_seminar'),
                '|',
                $c('jumlah_jp'),
                '|',
                $c('instansi_penyelenggara'),
                '|',
                $c('keterangan'),
            )],
            'riwayat_organisasi' => ['id_rwy|nip|tgl_mulai|tgl_akhir|nama_organisasi|kedudukan|keterangan', self::gabung(
                $id,
                '|',
                $v('nip'),
                '|',
                $c('tgl_mulai'),
                '|',
                $c('tgl_akhir'),
                '|',
                $c('nama_organisasi'),
                '|',
                $c('kedudukan'),
                '|',
                $c('keterangan'),
            )],
            'riwayat_skp' => ['id_rwy|nip|tahun|tgl_mulai|tgl_akhir|nip_penilai|nama_penilai|jabatan_penilai|nip_atasan_penilai|nama_atasan_penilai|jabatan_atasan_penilai|nilai_skp|nilai_perilaku|nilai_prestasi_kerja|kategori_nilai|rating_skp|rating_perilaku', self::gabung(
                $id,
                '|',
                $v('nip'),
                '|',
                $c('tahun'),
                '|',
                $c('tgl_mulai'),
                '|',
                $c('tgl_akhir'),
                '|',
                $c('nip_penilai'),
                '|',
                $c('nama_penilai'),
                '|',
                $c('jabatan_penilai'),
                '|',
                $c('nip_atasan_penilai'),
                '|',
                $c('nama_atasan_penilai'),
                '|',
                $c('jabatan_atasan_penilai'),
                '|',
                $c('nilai_skp'),
                '|',
                $c('nilai_perilaku'),
                '|',
                $c('nilai_prestasi_kerja'),
                '|',
                $c('kategori_nilai_prestasi'),
                '|',
                $c('rating_skp'),
                '|',
                $c('rating_perilaku'),
            )],
            'riwayat_kp' => ['id_rwy|nip|tmtsk|tgl_sk|no_sk|jenis_kp|gol|ruang|gol_ruang|pangkat|mker_th|mker_bl|gaji_pokok', self::gabung(
                $id,
                '|',
                $v('nip'),
                '|',
                $v('tmtsk'),
                '|',
                $v('tgl_sk'),
                '|',
                $c('no_sk'),
                '|',
                $v('jenis_kp'),
                '|',
                $v('gol'),
                '|',
                $v('ruang'),
                '|',
                $v('gol_ruang'),
                '|',
                $v('pangkat'),
                '|',
                $c('mker_th'),
                '|',
                $c('mker_bl'),
                '|',
                $c('gaji_pokok'),
            )],
            'riwayat_mutasi_jabatan' => ['id_rwy|nip|tmtsk|tgl_sk|no_sk|jenis_jabatan|jenis_jabatan_koordinasi|jabatan|jabatan_koordinasi|jabatan_lain|kelas_jabatan|group_jabatan|sub_group_jabatan|unit|satker|atasan_es_1|atasan_es_2|atasan_es_3|atasan_es_4|kredit_jft|no_induk_jft|status_jft', self::gabung(
                $id,
                '|',
                $v('nip'),
                '|',
                $v('tmtsk'),
                '|',
                $c('tgl_sk'),
                '|',
                $c('no_sk'),
                '|',
                match ($c('jenis_jabatan')) {
                    '1' => 'Nomenlaktur', '2' => 'Instansi Lain', default => 'Pelaksana Tugas (PLT)'
                },
                '|',
                match ($c('jenis_jabatan_koord')) {
                    '1' => 'Koordinator', '2' => 'Subkoordinator', default => 'Tidak Ada'
                },
                '|',
                $c('jabatan'),
                '|',
                $c('jabatan_koord'),
                '|',
                $c('jabatan_lain'),
                '|',
                $c('kelas_jabatan'),
                '|',
                $c('group_jabatan'),
                '|',
                $c('sub_group_jabatan'),
                '|',
                $c('unit'),
                '|',
                $c('satker'),
                '|',
                $c('atasan_es_1'),
                '|',
                $c('atasan_es_2'),
                '|',
                $c('atasan_es_3'),
                '|',
                $c('atasan_es_4'),
                '|',
                $c('kredit_jft'),
                '|',
                $c('no_induk_jft'),
                '|',
                match ($c('status_jft')) {
                    '1' => 'Aktif', '2' => 'Pembebasan Sementara', default => ''
                },
            )],
            'riwayat_kgb' => ['id_rwy|nip|tmtsk|tgl_sk|no_sk|mker_gol_th|gaji_pokok|jabatan_yang_menandatangani', self::gabung(
                $id,
                '|',
                $v('nip'),
                '|',
                $c('tmtsk'),
                '|',
                $c('tgl_sk'),
                '|',
                $c('no_sk'),
                '|',
                $c('mker_gol_th'),
                '|',
                $c('gaji_pokok'),
                '|',
                $c('jym'),
            )],
            'riwayat_tanda_jasa' => ['id_rwy|nip|tgl_sertifikat|no_sertifikat|tanda_jasa|negara|keterangan', self::gabung(
                $id,
                '|',
                $v('nip'),
                '|',
                $c('tgl_sertifikat'),
                '|',
                $c('no_sertifikat'),
                '|',
                $c('tanda_jasa') === 'LAIN-LAIN' && $c('tanda_jasa_lain') !== '' ? $v('tanda_jasa_lain') : $c('tanda_jasa'),
                '|',
                $c('negara'),
                '|',
                $c('keterangan'),
            )],
            'riwayat_hukdis' => ['id_rwy|nip|tmtsk|tgl_sk|no_sk|tingkat_hukdis|jenis_hukdis|gol|ruang|gol_ruang|pangkat|masa_hukuman|akhir_hukdis|aturan_dilanggar|alasan_hukuman|keterangan', self::gabung(
                $id,
                '|',
                $v('nip'),
                '|',
                $v('tmtsk'),
                '|',
                $c('tgl_sk'),
                '|',
                $c('no_sk'),
                '|',
                $c('tingkat_hukdis'),
                '|',
                $c('jenis_hukdis'),
                '|',
                $c('gol'),
                '|',
                $c('ruang'),
                '|',
                $c('gol_ruang'),
                '|',
                $c('pangkat'),
                '|',
                $c('masa_hukuman'),
                '|',
                $c('akhir_hukdis'),
                '|',
                $c('aturan_dilanggar'),
                '|',
                $c('alasan_hukuman'),
                '|',
                $c('keterangan'),
            )],
            default => throw new \InvalidArgumentException("Tabel riwayat tidak dikenal: {$tabel}."),
        };
    }

    // ------------------------------------------------------------------
    // Semantik MySQL
    // ------------------------------------------------------------------

    /**
     * `CONCAT(...)`: NULL bila salah satu bagian NULL.
     */
    public static function gabung(mixed ...$bagian): ?string
    {
        $hasil = '';

        foreach ($bagian as $b) {
            if ($b === null) {
                return null;
            }

            $hasil .= (string) $b;
        }

        return $hasil;
    }

    /**
     * `COALESCE(x, $ganti)`.
     */
    public static function k(mixed $v, string $ganti = ''): string
    {
        return $v === null ? $ganti : (string) $v;
    }

    /**
     * `x = 'v'` (NULL → salah).
     */
    public static function sama(mixed $v, string $nilai): bool
    {
        return $v !== null && (string) $v === $nilai;
    }

    /**
     * `x != y` (NULL di salah satu → salah).
     */
    public static function beda(mixed $a, mixed $b): bool
    {
        return $a !== null && $b !== null && (string) $a !== (string) $b;
    }

    /**
     * @param array<string, mixed> $n
     */
    private static function lain(array $n, string $kolomId, string $kode, string $kolomLain, string $kolom): mixed
    {
        return self::sama(self::k($n[$kolomId] ?? null), $kode) && self::k($n[$kolomLain] ?? null) !== '' ? $n[$kolomLain] : self::k($n[$kolom] ?? null);
    }

    private static function id(mixed $v): ?int
    {
        return $v === null || (string) $v === '' ? null : (int) $v;
    }

    /**
     * @return array{id_simapi_jd: int, nip: ?string, id_rwy: ?int, action: int, field_name: ?string, field_value: ?string}
     */
    private static function ch(int $jd, mixed $nip, ?int $idRwy, int $action, ?string $field = null, ?string $nilai = null): array
    {
        return ['id_simapi_jd' => $jd, 'nip' => $nip === null ? null : (string) $nip, 'id_rwy' => $idRwy, 'action' => $action, 'field_name' => $field, 'field_value' => $nilai];
    }
}
