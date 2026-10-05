<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Nip;

/**
 * CR-036 — satu kolom yang merujuk NIP pegawai (entri {@see TabelAnakNip}).
 *
 * `tabel`/`kolom` memakai nama legacy apa adanya, termasuk huruf besar (`document_attachment.NIP`). Bila nama kolom di v2
 * berbeda, `kolomV2` berisi nama v2 (satu-satunya: `pengguna.id_pegawai` → `pengguna.nip`, DBV-010). `nullable` = kolom
 * legacy boleh NULL. `fase`/`pemilik` hanya terisi bila `keberadaan` = {@see KeberadaanV2::Ada}.
 */
final readonly class RujukanNip
{
    public function __construct(
        public string $tabel,
        public string $kolom,
        public JenisRujukanNip $jenis,
        public string $sumber,
        public ?string $namaFkLegacy,
        public bool $nullable,
        public KeberadaanV2 $keberadaan,
        public ?int $fase,
        public ?string $pemilik,
        public string $catatan,
        public ?string $kolomV2 = null,
    ) {
    }

    /**
     * Nama kolom yang dipakai di skema v2.
     */
    public function kolomDiV2(): string
    {
        return $this->kolomV2 ?? $this->kolom;
    }

    /**
     * Kunci unik `tabel.kolom` tanpa peka huruf, sama dengan cara MySQL membandingkan nama kolom.
     */
    public function kunci(): string
    {
        return strtolower($this->tabel . '.' . $this->kolom);
    }
}
