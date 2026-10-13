<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat\Definisi;

use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;

/**
 * B-17 Usulan Kartu Istri/Suami (Karis/Karsu, WS-1 M4 MAKE-007) — alur usulan mandiri, pola sama dengan Karpeg.
 *
 * Sumber [K] = `controllers/hr/rwy/Kariskarsu.php`, `libraries/hr/rwy/L_kariskarsu.php`, views `kariskarsu/*`. Beda dengan
 * Karpeg (keputusan user 12-10-2026 ikut legacy): tanpa jenis permohonan; ajukan mensyaratkan minimal satu riwayat keluarga
 * berstatus selain 2/10 (legacy `Kariskarsu.php:83-88`); label Kartu Istri/Suami menurut `pegawai.jenis_kelamin`
 * (1 → Istri, 2 → Suami, ditangani frontend); berkas `file_1..5` wajib, `file_surat_hilang` dan `file_alasan`
 * (akta kematian/surat cerai) opsional. Pemroses, antrean, hapus, dan PDF tanda terima: lihat Karpeg.
 */
final class Kariskarsu extends RiwayatDefinisi
{
    /**
     * @var array<string, string>
     */
    public const BERKAS = [
        'file_1'            => 'SK CPNS',
        'file_2'            => 'SK PNS',
        'file_3'            => 'Buku Nikah',
        'file_4'            => 'Laporan Perkawinan',
        'file_5'            => 'Pas Foto Istri/Suami',
        'file_surat_hilang' => 'Surat Keterangan Hilang',
        'file_alasan'       => 'Akta Kematian/Surat Cerai',
    ];

    /**
     * @var list<string>
     */
    public const OPSIONAL = ['file_surat_hilang', 'file_alasan'];

    public function jenis(): string
    {
        return 'kariskarsu';
    }

    public function label(): string
    {
        return 'Usulan Kartu Istri/Suami';
    }

    public function tabel(): string
    {
        return 'riwayat_kariskarsu';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_kariskarsu';
    }

    public function fields(): array
    {
        return [];
    }

    public function izin(): array
    {
        return (new Karpeg())->izin();
    }

    public function alur(): AlurRiwayat
    {
        return AlurRiwayat::Usulan;
    }

    public function pemetaanStatus(): array
    {
        return Karpeg::statusTanpaDiproses();
    }

    public function urutanDaftar(): array
    {
        return ['updated_at' => 'DESC', $this->primaryKey() => 'DESC'];
    }

    public function kolomBerkas(): array
    {
        $aturan = [];

        foreach (array_keys(self::BERKAS) as $kolom) {
            $aturan[$kolom] = new AturanLampiran(0, ! in_array($kolom, self::OPSIONAL, true), 5, Karpeg::EKSTENSI);
        }

        return $aturan;
    }

    /**
     * Ajukan butuh minimal satu riwayat keluarga aktif (legacy); ubah tidak diperiksa ulang.
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        if ($lama !== null || self::punyaKeluarga((string) $data[$this->kolomNip()])) {
            return [];
        }

        return ['keluarga' => ['Lengkapi data riwayat keluarga terlebih dahulu sebelum mengajukan Karis/Karsu.']];
    }

    public static function punyaKeluarga(string $nip): bool
    {
        return db_connect()->table('riwayat_keluarga')
            ->where('nip', $nip)
            ->whereNotIn('status', [StatusRiwayat::Ditolak->value, StatusRiwayat::Dihapus->value])
            ->countAllResults() > 0;
    }
}
