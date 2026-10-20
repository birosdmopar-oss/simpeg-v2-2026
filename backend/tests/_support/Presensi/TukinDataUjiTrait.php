<?php

declare(strict_types=1);

namespace Tests\Support\Presensi;

use App\Libraries\CacheService;
use App\Libraries\Presensi\TukinData\SumberCutiTbInterface;
use Config\Services;

/**
 * Helper test adapter Tukin 5A (PRD 5A §7.7): `web_config` lewat `WebConfigService` asli (cache tanpa prefix, diinvalidasi
 * saat tulis), baris `bkn_periode_ekinper`, dan sumber cuti/TB palsu. Dipakai bersama `DatabaseTestCase` (data ditulis di
 * transaksi uji yang di-rollback).
 */
trait TukinDataUjiTrait
{
    /** Tarif potongan wajib (persen) yang sah. */
    protected const TARIF_UJI = ['TL1/PSW1' => '0.5', 'TL2/PSW2' => '1', 'TL3/PSW3' => '1.25', 'TA' => '1.5', 'TK' => '3'];

    protected function resetWebConfig(): void
    {
        foreach (['webConfigService', 'cacheService', 'htmlSanitizer'] as $name) {
            Services::resetSingle($name);
        }

        Services::injectMock('cacheService', new CacheService(service('cache'), ''));
        service('cache')->delete('web_config_values');
    }

    /**
     * @param array<string, string> $nilai config_name => config_value
     */
    protected function aturWebConfig(array $nilai): void
    {
        foreach ($nilai as $nama => $isi) {
            service('webConfigService')->set($nama, ['config_value' => $isi]);
        }
    }

    protected function barisBkn(int $tahun, int $bulan, string $awal, string $akhir, ?string $jenis = null, ?string $id = null): string
    {
        $id ??= sprintf('BKN-%04d-%02d-%s', $tahun, $bulan, $jenis ?? 'utama');

        $this->db->table('bkn_periode_ekinper')->insert([
            'id'              => $id,
            'nama'            => "Periode uji {$tahun}-{$bulan}",
            'tahun'           => $tahun,
            'bulan'           => sprintf('%02d', $bulan),
            'periode_awal'    => $awal,
            'periode_akhir'   => $akhir,
            'batas_pengisian' => '+05',
            'jenis_periode'   => $jenis,
            'status'          => 1,
        ]);

        return $id;
    }

    /**
     * Sumber cuti/TB palsu (5B akan menggantinya dengan data Fase 4).
     *
     * @param array<string, array<string, mixed>> $data per tanggal
     */
    protected function sumberCutiTbPalsu(array $data = []): SumberCutiTbInterface
    {
        return new class ($data) implements SumberCutiTbInterface {
            /**
             * @param array<string, array<string, mixed>> $data
             */
            public function __construct(private readonly array $data)
            {
            }

            public function untuk(string $nip, string $awal, string $akhir): array
            {
                return array_filter($this->data, static fn (string $tgl): bool => $tgl >= $awal && $tgl <= $akhir, ARRAY_FILTER_USE_KEY);
            }
        };
    }
}
