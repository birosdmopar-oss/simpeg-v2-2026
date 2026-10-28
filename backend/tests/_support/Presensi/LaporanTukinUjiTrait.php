<?php

declare(strict_types=1);

namespace Tests\Support\Presensi;

use App\Interfaces\PdfRendererInterface;
use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\Pdf;
use App\Libraries\Presensi\Laporan\DaftarPegawaiTukinService;
use App\Libraries\Presensi\Laporan\LaporanTukinService;
use App\Libraries\Presensi\Laporan\RekapTukinService;
use ArrayObject;

/**
 * Fixture laporan Tukin D-08 (MAKE-041): tarif Tukin `web_config` (TARIF_UJI), service dengan jam disuntik, dan renderer
 * PDF palsu yang menangkap HTML (`$this->pdfTertangkap`). Bulan 2026-09 tanpa baris BKN = periode 16 Agu – 15 Sep 2026
 * (22 hari kerja tanpa libur). Dipakai bersama `PegawaiFixtureTrait`, `RekapUjiTrait`, `TukinDataUjiTrait`.
 */
trait LaporanTukinUjiTrait
{
    /** @var ArrayObject<int, DokumenPdf> */
    protected ArrayObject $pdfTertangkap;

    protected function siapkanLaporanTukin(): void
    {
        $this->resetWebConfig();
        $this->aturWebConfig(self::TARIF_UJI);
        $this->siapkanJenisPegawai();
        $this->pdfTertangkap = new ArrayObject();

        Pdf::pakai(new class ($this->pdfTertangkap) implements PdfRendererInterface {
            /** @param ArrayObject<int, DokumenPdf> $tangkap */
            public function __construct(private readonly ArrayObject $tangkap)
            {
            }

            public function render(DokumenPdf $dokumen): string
            {
                $this->tangkap->append($dokumen);

                return '%PDF-1.4 palsu';
            }
        });
    }

    protected function bersihkanLaporanTukin(): void
    {
        Pdf::reset();
        $this->resetWebConfig();
    }

    protected function serviceRekapTukin(string $wib = '2026-10-27 09:00:00'): RekapTukinService
    {
        return new RekapTukinService($this->db, self::jamRekap($wib));
    }

    protected function serviceLaporanTukin(string $wib = '2026-10-27 09:00:00'): LaporanTukinService
    {
        return new LaporanTukinService($this->db, self::jamRekap($wib));
    }

    protected function serviceDaftarTukin(string $wib = '2026-10-27 09:00:00'): DaftarPegawaiTukinService
    {
        return new DaftarPegawaiTukinService($this->db, self::jamRekap($wib));
    }

    /**
     * Hadir tepat waktu pada semua hari kerja periode Tukin 2026-09 (16 Agu – 15 Sep 2026).
     */
    protected function hadirSepanjangPeriode(string $nip): void
    {
        foreach ([...range(17, 21), ...range(24, 28), 31] as $h) {
            $this->hadir($nip, sprintf('2026-08-%02d', $h), '07:30:00', $h === 21 || $h === 28 ? '16:30:00' : '16:00:00');
        }

        foreach ([1, 2, 3, 4, 7, 8, 9, 10, 11, 14, 15] as $h) {
            $this->hadir($nip, sprintf('2026-09-%02d', $h), '07:30:00', $h === 4 || $h === 11 ? '16:30:00' : '16:00:00');
        }
    }
}
