<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Libraries\Pdf\KopDokumen;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use DateTimeInterface;

/**
 * Data satu PDF slip gaji yang sudah disiapkan service (PRD 5A §7.3.6); generator PDF tidak mengakses DB.
 *
 * - `identitas`: nama bergelar (P-9), NIP, pangkat/golongan, jabatan/unit/satker jabatan saat ini [K]; '-' bila kosong.
 * - `sen`: 28 field `KolomGaji` + `uang_makan`, int sen.
 * - `kop`: HTML tersanitasi `web_config` (G-09) `pdf_header_*`.
 * - `logoKiri`/`logoKanan`: path berkas gambar yang sudah di-resolve service ({@see KopSlip::resolveAset()}); null → tanpa logo.
 * - `namaInstansi`: `web_config` `nama_kementerian`, kosong → literal legacy (lihat {@see KopSlip}).
 * - `urlQr`: URL verifikasi berisi token aktif; null = tanpa QR (U-3, P5-36).
 */
final readonly class DataPdfSlip
{
    /**
     * @param array{nama: string, nip: string, pangkat: string, jabatan: string, unit: string, satker: string} $identitas
     * @param array<string, int>                                                                               $sen
     * @param array{nama_kementerian: string, nama_unit: string, alamat: string}                               $kop
     */
    public function __construct(
        public PeriodeSlip $periode,
        public array $identitas,
        public array $sen,
        public ?string $uangMakanPeriode,
        public array $kop,
        public ?string $logoKiri,
        public ?string $logoKanan,
        public ?string $urlQr,
        public DateTimeInterface $dicetakPada,
        public ?DateTimeInterface $dibukaPada,
        public string $namaInstansi = KopDokumen::NAMA_INSTANSI_BAWAAN,
    ) {
    }
}
