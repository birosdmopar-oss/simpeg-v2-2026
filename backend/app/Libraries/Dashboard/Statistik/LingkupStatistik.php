<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Statistik;

/**
 * Lingkup statistik (PRD Fase 7 F7-2): `kementerian` (dirinci per unit eselon I), `unit` (dirinci per satker di bawah
 * unit itu), `satker` (satu satker). Padanan legacy `id_unit_satker` = `0` / `<id_unit>` / `sat_<id>`
 * (`controllers/hr/Chart.php:34`).
 */
final class LingkupStatistik
{
    public const KEMENTERIAN = 'kementerian';

    public const UNIT = 'unit';

    public const SATKER = 'satker';

    public const TIPE = [self::KEMENTERIAN, self::UNIT, self::SATKER];

    public function __construct(
        public readonly string $tipe,
        public readonly ?int $id,
        public readonly string $nama,
    ) {
    }

    /**
     * Label kolom kategori pada tabel/ekspor: kementerian dirinci per unit, lainnya per satker.
     */
    public function labelKategori(): string
    {
        return $this->tipe === self::KEMENTERIAN ? 'Unit Kerja Eselon I' : 'Satuan Kerja';
    }

    /**
     * @return array{tipe: string, id: int|null, nama: string}
     */
    public function toArray(): array
    {
        return ['tipe' => $this->tipe, 'id' => $this->id, 'nama' => $this->nama];
    }
}
