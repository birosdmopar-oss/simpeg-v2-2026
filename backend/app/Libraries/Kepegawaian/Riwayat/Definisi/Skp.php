<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat\Definisi;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use App\Libraries\MasterData\MasterField;

/**
 * B-12a Riwayat SKP tahunan (WS-1 M5 MAKE-008), tanpa snapshot (tidak ada `pegawai_skp`).
 *
 * Sumber [K] = kode legacy (`application/`): `controllers/hr/rwy/Skp.php`, `libraries/hr/rwy/L_skp.php`
 * (`validate_param()` :525-624, `set_param()` :626-664, `list()` :27), form `views/hr/employee/rwy/skp/form.php`.
 * Hak akses: Matriks v2 `hr/rwy/skp/*` = 1, 2, 3, 7 (legacy index UL_ALL — lihat ikut Matriks); proses 1, 3.
 *
 * Penilaian per periode tahun: sampai 2021 nilai angka (SKP 60% + perilaku 40% = prestasi kerja); mulai 2022 rating
 * (1 di bawah, 2 sesuai, 3 di atas ekspektasi) — kolom milik skema lain dikosongkan (`set_param()`). Persentase, nilai
 * prestasi, dan kategori dihitung FORM (legacy menghitungnya di peramban, `form.php:722-843`; frontend v2 sama), server
 * menerima nilainya seperti legacy. SKP 2023+ berasal dari e-Kinerja (tab SKP periodik): tahun maksimal 2022 (form
 * legacy :455-475 mengganti tahun >= 2023 menjadi 2022).
 *
 * Aturan form legacy yang ditegakkan juga di server: tahun 2022 periodenya 1 Januari s.d. 31 Desember (form :476-484,
 * diisi beforeSave); batas nilai skala 120 mulai 2021 dan 100 sebelumnya (`form.php:306-372`, :578-700) untuk nilai SKP,
 * perilaku, dan prestasi, serta 60%/40%-nya (72/48 atau 60/40). Legacy 2021 mengisi nilai berbobot 60%/40% lalu
 * menurunkan nilai mentah (/0,6, /0,4); v2 mengisi nilai mentah (maks 120) lalu menghitung bobotnya — setara.
 */
final class Skp extends RiwayatDefinisi
{
    /**
     * Kode `jenis_rwy` lampiran SKP (konstanta legacy `ARSIP_RWY['skp']`).
     */
    public const LAMPIRAN_SKP = 32;

    /**
     * Tahun pertama penilaian berbasis rating (legacy `validate_param()` :552, `set_param()` :640).
     */
    public const TAHUN_RATING = 2022;

    /**
     * Tahun terakhir SKP tahunan (legacy form :455-475; 2023+ = SKP periodik e-Kinerja).
     */
    public const TAHUN_MAKS = 2022;

    /**
     * Tahun pertama skala nilai 120 (legacy form :306, :578).
     */
    public const TAHUN_SKALA_120 = 2021;

    /**
     * @var list<string>
     */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

    /**
     * @var list<string>
     */
    private const KOLOM_NILAI = ['nilai_skp', 'nilai_skp_60_persen', 'nilai_perilaku', 'nilai_perilaku_40_persen', 'nilai_prestasi_kerja'];

    /**
     * @var list<string>
     */
    private const KOLOM_RATING = ['rating_skp', 'rating_perilaku'];

    public function jenis(): string
    {
        return 'skp';
    }

    public function label(): string
    {
        return 'Riwayat SKP';
    }

    public function tabel(): string
    {
        return 'riwayat_skp';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_skp';
    }

    public function fields(): array
    {
        $rating = [1 => 'Di Bawah Ekspektasi', 2 => 'Sesuai Ekspektasi', 3 => 'Di Atas Ekspektasi'];
        $nilai  = static fn (string $nama, string $label): MasterField => new MasterField($nama, $label, MasterField::TYPE_DECIMAL, min: 0, max: 999.99);

        return [
            new MasterField('tahun', 'Tahun', MasterField::TYPE_INT, required: true, min: 1901, max: self::TAHUN_MAKS),
            new MasterField('tgl_mulai', 'Tanggal Mulai', MasterField::TYPE_DATE, required: true),
            new MasterField('tgl_akhir', 'Tanggal Akhir', MasterField::TYPE_DATE, required: true),
            new MasterField('nip_penilai', 'NIP Pejabat Penilai', maxBytes: 18),
            new MasterField('nama_penilai', 'Nama Pejabat Penilai', maxBytes: 150),
            new MasterField('jabatan_penilai', 'Jabatan Pejabat Penilai', maxBytes: 150),
            new MasterField('nip_atasan_penilai', 'NIP Atasan Pejabat Penilai', maxBytes: 18),
            new MasterField('nama_atasan_penilai', 'Nama Atasan Pejabat Penilai', maxBytes: 150),
            new MasterField('jabatan_atasan_penilai', 'Jabatan Atasan Pejabat Penilai', maxBytes: 150),
            $nilai('nilai_skp', 'Nilai SKP'),
            $nilai('nilai_skp_60_persen', 'Nilai SKP (60%)'),
            $nilai('nilai_perilaku', 'Nilai Perilaku'),
            $nilai('nilai_perilaku_40_persen', 'Nilai Perilaku (40%)'),
            $nilai('nilai_prestasi_kerja', 'Nilai Prestasi Kerja'),
            new MasterField('rating_skp', 'Rating Hasil Kerja', MasterField::TYPE_SELECT, options: $rating),
            new MasterField('rating_perilaku', 'Rating Perilaku Kerja', MasterField::TYPE_SELECT, options: $rating),
            new MasterField('kategori_nilai_prestasi', 'Kategori / Predikat', required: true, maxBytes: 50),
            new MasterField('keterangan', 'Keterangan', MasterField::TYPE_TEXTAREA, maxBytes: 255),
        ];
    }

    public function izin(): array
    {
        $semua = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PPPK];

        return [
            'lihat'  => $semua,
            'tambah' => $semua,
            'ubah'   => $semua,
            'hapus'  => $semua,
            'proses' => [Role::SUPER_ADMIN, Role::ADMIN_SATKER],
        ];
    }

    public function alur(): AlurRiwayat
    {
        return AlurRiwayat::SelfService;
    }

    /**
     * Legacy: role 1 memilih status saat edit (`views/hr/employee/rwy/skp/form.php:74-89`).
     */
    public function rolePilihStatusSaatUbah(): array
    {
        return [Role::SUPER_ADMIN];
    }

    /**
     * Legacy `list()` :27 `ORDER BY status ASC, tahun ASC, tgl_mulai ASC`.
     */
    public function urutanDaftar(): array
    {
        return [$this->kolomStatus() => 'ASC', 'tahun' => 'ASC', 'tgl_mulai' => 'ASC', $this->primaryKey() => 'ASC'];
    }

    /**
     * Legacy: lampiran SKP wajib saat tambah, dan saat ubah bila belum ada; 5 MB, jpg|jpeg|png|gif|pdf.
     */
    public function lampiran(): array
    {
        return [new AturanLampiran(self::LAMPIRAN_SKP, true, 5, self::EKSTENSI)];
    }

    public function urutanTab(): int
    {
        return 80;
    }

    /**
     * Legacy `validate_param()`: isian wajib menurut skema tahun; periode (tahun + tanggal mulai + akhir) tidak kembar
     * dengan baris berstatus 1 (legacy hanya memeriksa status 1) — atas nilai akhir baris.
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai  = $data + ($lama ?? []);
        $errors = [];
        $wajib  = self::rating((int) ($nilai['tahun'] ?? 0)) ? self::KOLOM_RATING : self::KOLOM_NILAI;

        foreach ($wajib as $kolom) {
            if ($nilai[$kolom] === null || trim((string) ($nilai[$kolom] ?? '')) === '') {
                $errors[$kolom] = [$this->labelField($kolom) . ' wajib diisi.'];
            }
        }

        if (! self::rating((int) ($nilai['tahun'] ?? 0))) {
            foreach (self::batasNilai((int) ($nilai['tahun'] ?? 0)) as $kolom => $maks) {
                if (! isset($errors[$kolom]) && is_numeric($nilai[$kolom] ?? null) && (float) $nilai[$kolom] > $maks) {
                    $errors[$kolom] = [$this->labelField($kolom) . ' maksimal ' . $maks . '.'];
                }
            }
        }

        $kembar = db_connect()->table($this->tabel())
            ->where($this->kolomNip(), $nilai[$this->kolomNip()])
            ->where('tahun', $nilai['tahun'] ?? null)
            ->where('tgl_mulai', $nilai['tgl_mulai'] ?? null)
            ->where('tgl_akhir', $nilai['tgl_akhir'] ?? null)
            ->where($this->kolomStatus(), StatusRiwayat::Disetujui->value);

        if ($lama !== null) {
            $kembar->where($this->primaryKey() . ' !=', $lama[$this->primaryKey()]);
        }

        if ($kembar->countAllResults() > 0) {
            $errors['tahun'] = ['SKP dengan tahun dan periode ini sudah terdaftar.'];
        }

        return $errors;
    }

    /**
     * Legacy `set_param()` :640-655: kolom skema lain dikosongkan (rating untuk ≤2021, nilai untuk ≥2022); tahun 2022
     * periodenya 1 Januari s.d. 31 Desember (form legacy :476-484).
     */
    public function beforeSave(array $data, ?array $lama, AuthContext $auth): array
    {
        $nilai  = $data + ($lama ?? []);
        $kosong = self::rating((int) ($nilai['tahun'] ?? 0)) ? self::KOLOM_NILAI : self::KOLOM_RATING;

        foreach ($kosong as $kolom) {
            $data[$kolom] = null;
        }

        if ((int) ($nilai['tahun'] ?? 0) === self::TAHUN_MAKS) {
            $data['tgl_mulai'] = self::TAHUN_MAKS . '-01-01';
            $data['tgl_akhir'] = self::TAHUN_MAKS . '-12-31';
        }

        return $data;
    }

    /**
     * Batas atas nilai skema ≤2021 per kolom (legacy form): skala 120 mulai 2021, 100 sebelumnya.
     *
     * @return array<string, float>
     */
    public static function batasNilai(int $tahun): array
    {
        $skala = $tahun >= self::TAHUN_SKALA_120 ? 120.0 : 100.0;

        return [
            'nilai_skp'                => $skala,
            'nilai_skp_60_persen'      => $skala * 0.6,
            'nilai_perilaku'           => $skala,
            'nilai_perilaku_40_persen' => $skala * 0.4,
            'nilai_prestasi_kerja'     => $skala,
        ];
    }

    public static function rating(int $tahun): bool
    {
        return $tahun >= self::TAHUN_RATING;
    }

    private function labelField(string $nama): string
    {
        foreach ($this->fields() as $field) {
            if ($field->name === $nama) {
                return $field->label;
            }
        }

        return $nama;
    }
}
