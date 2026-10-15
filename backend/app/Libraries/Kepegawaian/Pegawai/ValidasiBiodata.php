<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Pegawai;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Nip\FormatNip;
use CodeIgniter\Database\BaseConnection;

/**
 * Validasi + normalisasi isian biodata B-03 (MAKE-010), ikut legacy `validate_param()`/`set_param()`
 * (`libraries/hr/L_employee.php:788-1042`) dan form `views/hr/employee/form.php`.
 *
 * Isian per role [K]:
 *  - semua role ubah (1, 2, 3, 6, 7): tempat lahir (provinsi/kabupaten + "lain" untuk kode 99/9999), agama, jenis
 *    kelamin, NPWP, NIK, BPJS Kesehatan/Ketenagakerjaan, TASPEN, email akun, no. HP, kerabat, status pernikahan,
 *    keterangan;
 *  - role 1 dan 3: jenis pegawai (form :365, `set_param` :989-1004);
 *  - role 1 saja: nama, gelar, tanggal lahir (readonly bagi role lain di form :147-190), jenis status + TMT status
 *    (:388-425).
 * Isian di luar hak role pemanggil yang nilainya berbeda dari data sekarang → 422 (tolakIsianTanpaHak). Ubah = patch:
 * kolom yang tidak dikirim memakai nilai sekarang, lalu kewajiban legacy diperiksa atas nilai akhir.
 *
 * Normalisasi [K] `set_param`: nomor identitas/telepon hanya digit; nama master (`provinsi_lahir`, `agama`, …) diturunkan
 * dari ID; "Kerabat Lain" → `jenis_kerabat` = isian `jenis_kerabat_lain`. Deviasi [V2]: format email, tanggal, panjang
 * kolom DDL, ID master wajib ada (aktif bila berubah), kabupaten wajib milik provinsi terpilih (kecuali 9999 LAIN-LAIN),
 * jenis status wajib milik status pegawai (akhir) — legacy tidak memeriksanya. Kolom `status` pegawai 1/2 hanya Super
 * Admin (form legacy :388-400, B-05 MAKE-011); 10 Dihapus hanya lewat hapus pegawai. Mode tambah (B-05): NIP wajib
 * (format FormatNip, belum terdaftar) dan pegawai baru dianggap status 1.
 */
final class ValidasiBiodata
{
    public const PROVINSI_LAIN  = '99';
    public const KABUPATEN_LAIN = '9999';
    public const KERABAT_LAIN   = 'Kerabat Lain';

    /**
     * Pilihan jenis kerabat [K] (form :337-341).
     *
     * @var list<string>
     */
    public const JENIS_KERABAT = ['Orang Tua', 'Suami/Istri', 'Saudara Kandung', 'Anak', self::KERABAT_LAIN];

    /**
     * Isian semua role ubah.
     *
     * @var list<string>
     */
    public const ISIAN_UMUM = [
        'id_provinsi_lahir', 'provinsi_lahir_lain', 'id_kabupaten_kota_lahir', 'kabupaten_kota_lahir_lain', 'id_agama',
        'jenis_kelamin', 'npwp', 'nik', 'bpjs_kes', 'bpjs_ket', 'no_taspen', 'no_hp', 'jenis_kerabat', 'jenis_kerabat_lain',
        'no_telp_kerabat', 'status_pernikahan', 'keterangan', 'email',
    ];

    /**
     * @var list<string>
     */
    public const ISIAN_ADMIN_SATKER = ['id_jenis_pegawai'];

    /**
     * @var list<string>
     */
    public const ISIAN_SUPER_ADMIN = ['nama', 'glr_awal', 'glr_akhir', 'tgl_lahir', 'status', 'id_jenis_status', 'tmt_status'];

    /**
     * Panjang kolom gelar `pegawai_hist` [K] D1 (sementara sampai DBV-020).
     */
    public const BATAS_GELAR_PENGAJUAN = ['glr_awal' => 12, 'glr_akhir' => 24];

    /**
     * Kolom digit saja: kolom => [panjang maks DDL, wajib].
     */
    private const DIGIT = [
        'npwp'            => [20, true],
        'nik'             => [20, true],
        'bpjs_kes'        => [50, true],
        'bpjs_ket'        => [50, false],
        'no_taspen'       => [50, true],
        'no_hp'           => [20, true],
        'no_telp_kerabat' => [20, true],
    ];

    /**
     * @var array<string, list<string>>
     */
    private array $errors = [];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Mode tambah pegawai (B-05, role 1): NIP baru wajib valid dan belum terdaftar; biodata divalidasi sebagai Super
     * Admin atas baris kosong berstatus 1 (legacy `validate_param(..., 'add')` :891-911).
     *
     * @param array<string, mixed> $data
     *
     * @return array{nip: string, param: array<string, mixed>, email: string}
     */
    public function periksaTambah(array $data): array
    {
        $periksa = FormatNip::periksa($data['nip'] ?? null);
        $nip     = $periksa->valid ? (string) $periksa->detail['nip'] : '';
        $error   = [];

        if (! $periksa->valid) {
            $error['nip'] = [(string) $periksa->pesan];
        } elseif ($this->db->table('pegawai')->where('nip', $nip)->countAllResults() > 0) {
            $error['nip'] = ['NIP sudah terdaftar di dalam sistem; gunakan NIP yang berbeda.'];
        }

        $kosong = array_fill_keys([...self::ISIAN_UMUM, ...self::ISIAN_ADMIN_SATKER, ...self::ISIAN_SUPER_ADMIN], null);
        $kosong = array_merge($kosong, ['nip' => $nip, 'status' => 1]);
        unset($data['nip']);
        $data['status'] = '1'; // pegawai baru selalu Aktif (MAKE-011)

        try {
            $hasil = $this->periksa(Role::SUPER_ADMIN, $data, $kosong, null);
        } catch (ValidationException $e) {
            throw new ValidationException('Validasi gagal.', $error + (array) $e->getErrors());
        }

        if ($error !== []) {
            throw new ValidationException('Validasi gagal.', $error);
        }

        return ['nip' => $nip] + $hasil;
    }

    /**
     * Isian yang boleh dikirim role $role.
     *
     * @return list<string>
     */
    public static function isianUntuk(int $role): array
    {
        $isian = self::ISIAN_UMUM;

        if (in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true)) {
            $isian = [...$isian, ...self::ISIAN_ADMIN_SATKER];
        }

        if ($role === Role::SUPER_ADMIN) {
            $isian = [...$isian, ...self::ISIAN_SUPER_ADMIN];
        }

        return $isian;
    }

    /**
     * @param array<string, mixed> $data    payload klien
     * @param array<string, mixed> $pegawai baris `pegawai` sekarang
     * @param string|null          $email   email akun sekarang
     *
     * @return array{param: array<string, mixed>, email: string} kolom `pegawai` hasil normalisasi (hanya isian role
     *                                                               itu + nama master turunannya) dan email akhir
     *
     * @throws ValidationException 422 per kolom
     */
    public function periksa(int $role, array $data, array $pegawai, ?string $email): array
    {
        $this->errors = [];
        $isian        = self::isianUntuk($role);
        $nilai        = [];

        $this->tolakIsianTanpaHak($isian, $data, $pegawai);

        foreach ($isian as $kolom) {
            if (array_key_exists($kolom, $data)) {
                $nilai[$kolom] = $this->teks($kolom, $data[$kolom]);
            } elseif ($kolom === 'email') {
                $nilai[$kolom] = $email === null ? null : trim($email);
            } elseif ($kolom !== 'jenis_kerabat_lain') {
                $nilai[$kolom] = $pegawai[$kolom] === null ? null : trim((string) $pegawai[$kolom]);
            }
        }

        $param = [];

        $this->tempatLahir($nilai, $pegawai, $param);
        $this->master('id_agama', 'agama', 'id_agama', 'agama', 'Agama', $nilai, $pegawai, $param);
        $this->pilihan('jenis_kelamin', ['1', '2'], 'Jenis kelamin', $nilai, $param);
        $this->pilihan('status_pernikahan', ['1', '2'], 'Status pernikahan', $nilai, $param);
        $this->kerabat($nilai, $data, $param);

        foreach (self::DIGIT as $kolom => [$maks, $wajib]) {
            $digit = $nilai[$kolom] === null ? '' : (string) preg_replace('/[^0-9]/', '', (string) $nilai[$kolom]);

            if ($digit === '') {
                $wajib ? $this->gagal($kolom, 'Wajib diisi (angka).') : $param[$kolom] = null;

                continue;
            }

            strlen($digit) > $maks ? $this->gagal($kolom, "Maksimal {$maks} digit.") : $param[$kolom] = $digit;
        }

        $keterangan = $nilai['keterangan'] ?? null;
        if ($keterangan !== null && $keterangan !== '' && strlen($keterangan) > 255) {
            $this->gagal('keterangan', 'Maksimal 255 karakter.');
        }
        $param['keterangan'] = $keterangan === '' ? null : $keterangan;

        $emailAkhir = (string) ($nilai['email'] ?? '');
        if ($emailAkhir === '') {
            $this->gagal('email', 'Email wajib diisi.');
        } elseif (strlen($emailAkhir) > 150 || filter_var($emailAkhir, FILTER_VALIDATE_EMAIL) === false) {
            $this->gagal('email', 'Format email tidak valid.');
        }

        if (in_array('id_jenis_pegawai', $isian, true)) {
            $this->master('id_jenis_pegawai', 'jenis_pegawai', 'id_jenis_pegawai', 'jenis_pegawai', 'Jenis pegawai', $nilai, $pegawai, $param);
        }

        if ($role === Role::SUPER_ADMIN) {
            $this->isianSuperAdmin($nilai, $pegawai, $param);
        } else {
            $this->gelarMuatPengajuan($pegawai);
        }

        if ($this->errors !== []) {
            throw new ValidationException('Validasi gagal.', $this->errors);
        }

        return ['param' => $param, 'email' => $emailAkhir];
    }

    /**
     * Isian biodata di luar hak role yang NILAINYA BERBEDA dari data sekarang → 422 (keputusan reviewer 13-10-2026:
     * klien diberi tahu, bukan diabaikan diam-diam). Nilai yang sama tetap diterima: form legacy mengirim isian readonly
     * (nama, gelar, tanggal lahir, jenis pegawai) apa adanya (`views/hr/employee/form.php:149-190`). Kolom yang tidak
     * pernah bisa diubah lewat biodata (NIP, status, flag, foto path, kolom audit) juga ditolak bila berbeda; kunci
     * lain di luar kolom `pegawai` (mis. `_method`) diabaikan.
     *
     * @param list<string>         $isian
     * @param array<string, mixed> $data
     * @param array<string, mixed> $pegawai
     */
    private function tolakIsianTanpaHak(array $isian, array $data, array $pegawai): void
    {
        $semuaIsian = [...self::ISIAN_UMUM, ...self::ISIAN_ADMIN_SATKER, ...self::ISIAN_SUPER_ADMIN];

        foreach ($data as $kolom => $nilai) {
            $kolom = (string) $kolom;

            if (in_array($kolom, $isian, true) || (! in_array($kolom, $semuaIsian, true) && ! array_key_exists($kolom, $pegawai))) {
                continue;
            }

            $sekarang = $pegawai[$kolom] ?? null;
            $sama     = (is_string($nilai) || is_int($nilai) || $nilai === null)
                && trim((string) $nilai) === trim((string) $sekarang);

            if (! $sama) {
                $this->gagal($kolom, 'Anda tidak berhak mengubah isian ini.');
            }
        }
    }

    /**
     * @param array<string, string|null> $nilai
     * @param array<string, mixed>       $pegawai
     * @param array<string, mixed>       $param
     */
    private function tempatLahir(array $nilai, array $pegawai, array &$param): void
    {
        $prov = (string) ($nilai['id_provinsi_lahir'] ?? '');
        $kab  = (string) ($nilai['id_kabupaten_kota_lahir'] ?? '');

        $this->master('id_provinsi_lahir', 'provinsi', 'id_provinsi', 'provinsi', 'Provinsi tempat lahir', $nilai, $pegawai, $param, 'provinsi_lahir');
        $this->master('id_kabupaten_kota_lahir', 'kabupaten_kota', 'id_kabupaten_kota', 'kabupaten_kota', 'Kabupaten/kota tempat lahir', $nilai, $pegawai, $param, 'kabupaten_kota_lahir');

        if ($prov !== '' && $kab !== '' && $kab !== self::KABUPATEN_LAIN && ! isset($this->errors['id_kabupaten_kota_lahir'])) {
            $milik = $this->db->table('kabupaten_kota')->where('id_kabupaten_kota', $kab)->where('id_provinsi', $prov)->countAllResults() > 0;

            if (! $milik) {
                $this->gagal('id_kabupaten_kota_lahir', 'Kabupaten/kota tidak berada di provinsi terpilih.');
            }
        }

        if ($prov === self::PROVINSI_LAIN && $kab !== '' && $kab !== self::KABUPATEN_LAIN) {
            $this->gagal('id_kabupaten_kota_lahir', 'Provinsi LAIN-LAIN hanya dengan kabupaten/kota LAIN-LAIN.');
        }

        foreach (['provinsi_lahir_lain' => [$prov === self::PROVINSI_LAIN, 'Provinsi'], 'kabupaten_kota_lahir_lain' => [$kab === self::KABUPATEN_LAIN, 'Kabupaten/kota']] as $kolom => [$lain, $label]) {
            $isi = (string) ($nilai[$kolom] ?? '');

            if (! $lain) {
                $param[$kolom] = null;
            } elseif ($isi === '') {
                $this->gagal($kolom, "{$label} tempat lahir wajib diisi.");
            } elseif (mb_strlen($isi) > 255) {
                $this->gagal($kolom, 'Maksimal 255 karakter.');
            } else {
                $param[$kolom] = $isi;
            }
        }
    }

    /**
     * @param array<string, string|null> $nilai
     * @param array<string, mixed>       $data
     * @param array<string, mixed>       $param
     */
    private function kerabat(array $nilai, array $data, array &$param): void
    {
        $jenis = (string) ($nilai['jenis_kerabat'] ?? '');

        if ($jenis === '') {
            $this->gagal('jenis_kerabat', 'Jenis kerabat wajib dipilih.');

            return;
        }

        if (! array_key_exists('jenis_kerabat', $data)) {
            // Nilai sekarang (bisa teks "Kerabat Lain" bebas dari data lama) dipertahankan.
            $param['jenis_kerabat'] = $jenis;

            return;
        }

        if (! in_array($jenis, self::JENIS_KERABAT, true)) {
            $this->gagal('jenis_kerabat', 'Jenis kerabat tidak dikenal.');

            return;
        }

        if ($jenis !== self::KERABAT_LAIN) {
            $param['jenis_kerabat'] = $jenis;

            return;
        }

        $lain = (string) ($nilai['jenis_kerabat_lain'] ?? '');

        if ($lain === '') {
            $this->gagal('jenis_kerabat_lain', 'Jenis kerabat lain wajib diisi.');
        } elseif (mb_strlen($lain) > 100) {
            $this->gagal('jenis_kerabat_lain', 'Maksimal 100 karakter.');
        } else {
            $param['jenis_kerabat'] = $lain;
        }
    }

    /**
     * @param array<string, string|null> $nilai
     * @param array<string, mixed>       $pegawai
     * @param array<string, mixed>       $param
     */
    private function isianSuperAdmin(array $nilai, array $pegawai, array &$param): void
    {
        $nama = (string) ($nilai['nama'] ?? '');
        if ($nama === '') {
            $this->gagal('nama', 'Nama wajib diisi.');
        } elseif (mb_strlen($nama) > 100) {
            $this->gagal('nama', 'Maksimal 100 karakter.');
        } else {
            $param['nama'] = $nama;
        }

        foreach (['glr_awal', 'glr_akhir'] as $kolom) {
            $isi                                                                                  = (string) ($nilai[$kolom] ?? '');
            mb_strlen($isi) > 50 ? $this->gagal($kolom, 'Maksimal 50 karakter.') : $param[$kolom] = $isi === '' ? null : $isi;
        }

        $tgl = (string) ($nilai['tgl_lahir'] ?? '');
        if ($tgl === '') {
            $this->gagal('tgl_lahir', 'Tanggal lahir wajib diisi.');
        } elseif (! TanggalBisnis::valid($tgl)) {
            $this->gagal('tgl_lahir', 'Tanggal lahir tidak valid (YYYY-MM-DD).');
        } else {
            $param['tgl_lahir'] = $tgl;
        }

        $tmt = (string) ($nilai['tmt_status'] ?? '');
        if ($tmt !== '' && ! TanggalBisnis::valid($tmt)) {
            $this->gagal('tmt_status', 'TMT status tidak valid (YYYY-MM-DD).');
        } else {
            $param['tmt_status'] = $tmt === '' ? null : $tmt;
        }

        $status = (string) ($nilai['status'] ?? '');

        if (! in_array($status, ['1', '2'], true)) {
            $this->gagal('status', 'Status pegawai harus Aktif (1) atau Tidak Aktif (2).');
        } else {
            $param['status'] = (int) $status;
        }

        $this->master('id_jenis_status', 'jenis_status', 'id_jenis_status', 'jenis_status', 'Jenis status', $nilai, $pegawai, $param);

        if (isset($param['id_jenis_status'], $param['status'])) {
            $cocok = $this->db->table('jenis_status')
                ->where('id_jenis_status', $param['id_jenis_status'])
                ->where('status_pegawai', $param['status'])
                ->countAllResults() > 0;

            if (! $cocok) {
                $this->gagal('id_jenis_status', 'Jenis status tidak sesuai dengan status pegawai.');
            }
        }
    }

    /**
     * Jalur pengajuan menyalin gelar `pegawai` (VARCHAR 50) ke `pegawai_hist` yang kolomnya lebih pendek [K] D1:
     * `glr_awal` VARCHAR(12), `glr_akhir` VARCHAR(24). Sampai DBV-020 melebarkannya (keputusan user 13-10-2026), gelar
     * yang lebih panjang ditolak dengan pesan yang jelas — gelar bukan isian pegawai, jadi arahannya ke admin.
     *
     * @param array<string, mixed> $pegawai
     */
    private function gelarMuatPengajuan(array $pegawai): void
    {
        foreach (self::BATAS_GELAR_PENGAJUAN as $kolom => $maks) {
            if (mb_strlen((string) ($pegawai[$kolom] ?? '')) > $maks) {
                $this->gagal($kolom, "Gelar terlalu panjang untuk pengajuan (maks. {$maks} karakter); hubungi admin untuk memperbaikinya.");
            }
        }
    }

    /**
     * ID master wajib ada; bila berubah dari nilai sekarang, wajib aktif (status 1). Nama master ditulis ke $kolomTurunan.
     *
     * @param array<string, string|null> $nilai
     * @param array<string, mixed>       $pegawai
     * @param array<string, mixed>       $param
     */
    private function master(string $kolom, string $tabel, string $pk, string $kolomNama, string $label, array $nilai, array $pegawai, array &$param, ?string $kolomTurunan = null): void
    {
        $id = (string) ($nilai[$kolom] ?? '');

        if ($id === '') {
            $this->gagal($kolom, "{$label} wajib dipilih.");

            return;
        }

        $row     = $this->db->table($tabel)->select("{$kolomNama}, status")->where($pk, $id)->get()->getRowArray();
        $berubah = (string) ($pegawai[$kolom] ?? '') !== $id;

        if (! is_array($row) || ($berubah && (int) $row['status'] !== 1)) {
            $this->gagal($kolom, "{$label} tidak ditemukan atau tidak aktif.");

            return;
        }

        $param[$kolom]                      = $id;
        $param[$kolomTurunan ?? $kolomNama] = $row[$kolomNama];
    }

    /**
     * @param list<string>               $pilihan
     * @param array<string, string|null> $nilai
     * @param array<string, mixed>       $param
     */
    private function pilihan(string $kolom, array $pilihan, string $label, array $nilai, array &$param): void
    {
        $isi = (string) ($nilai[$kolom] ?? '');

        if ($isi === '') {
            $this->gagal($kolom, "{$label} wajib dipilih.");
        } elseif (! in_array($isi, $pilihan, true)) {
            $this->gagal($kolom, "{$label} tidak dikenal.");
        } else {
            $param[$kolom] = (int) $isi;
        }
    }

    /**
     * Nilai skalar isian (string/int → string ter-trim; null tetap null); array/objek/boolean → 422.
     */
    private function teks(string $kolom, mixed $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        if (is_string($nilai) || is_int($nilai)) {
            $teks = trim((string) $nilai);

            if (! mb_check_encoding($teks, 'UTF-8')) {
                $this->gagal($kolom, 'Isian mengandung karakter yang tidak valid.');

                return null;
            }

            return $teks;
        }

        $this->gagal($kolom, 'Isian harus berupa teks.');

        return null;
    }

    private function gagal(string $kolom, string $pesan): void
    {
        $this->errors[$kolom] ??= [];
        $this->errors[$kolom][] = $pesan;
    }
}
