<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

/**
 * Lingkup laporan/rekap 5B (PRD 5B §4 "Lingkup laporan"): `kementerian` (`unit_satker = 0`), `unit` (`<id_unit>`), atau
 * `satker` (`sat_<id_satker>`) [K `V/list_lt.php:26-47`].
 */
final readonly class LingkupLaporan
{
    public const KEMENTERIAN = 'kementerian';

    public const UNIT = 'unit';

    public const SATKER = 'satker';

    public function __construct(public string $tipe, public ?int $id, public string $nama)
    {
    }

    /**
     * Nilai parameter `unit_satker` padanan legacy.
     */
    public function kode(): string
    {
        return match ($this->tipe) {
            self::UNIT   => (string) $this->id,
            self::SATKER => 'sat_' . $this->id,
            default      => '0',
        };
    }

    /**
     * Pegawai (dengan `id_unit`/`id_satker` jabatan berlaku) termasuk lingkup ini.
     *
     * @param array{id_unit?: mixed, id_satker?: mixed} $pegawai
     */
    public function mencakup(array $pegawai): bool
    {
        return match ($this->tipe) {
            self::UNIT   => (int) ($pegawai['id_unit'] ?? 0) === $this->id,
            self::SATKER => (int) ($pegawai['id_satker'] ?? 0) === $this->id,
            default      => true,
        };
    }

    /**
     * @return array{tipe: string, id: int|null, kode: string, nama: string}
     */
    public function toArray(): array
    {
        return ['tipe' => $this->tipe, 'id' => $this->id, 'kode' => $this->kode(), 'nama' => $this->nama];
    }
}
