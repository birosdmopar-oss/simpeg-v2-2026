<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use CodeIgniter\Database\BaseConnection;

/**
 * Satu jenis tarik SIASN (`siasn:tarik --jenis=…`, PRD Fase 6 §7.3): padanan satu `SS/*::get_siasn` legacy. Hanya
 * menghitung {@see RencanaTarik} dari hasil API + baris lokal; penulisan, flag status, dan log diurus {@see TarikSiasn}.
 */
interface JenisTarikSiasn
{
    /** Nama `--jenis` (mis. `golongan`). */
    public function nama(): string;

    /** Kolom flag di `siasn_status_pegawai` (`du`, `rw_gol`, …); stempel = kolom + `_lu`. */
    public function kolomStatus(): string;

    /**
     * Saring `pegawai.id_jenis_pegawai` [K]; null = semua pegawai `status = 1`.
     *
     * @return list<int>|null
     */
    public function jenisPegawai(): ?array;

    /**
     * Ambil data SIASN satu NIP lewat {@see \App\Interfaces\SiasnGatewayInterface} (dipanggil di luar transaksi, BP-4).
     *
     * @return array{status_code: string, message: string, data: mixed}
     */
    public function ambil(\App\Interfaces\SiasnGatewayInterface $siasn, string $nip): array;

    /**
     * @param array<string, array{status_code: string, message: string, data: mixed}> $hasil NIP → hasil API
     */
    public function rencana(BaseConnection $db, array $hasil, int $idAkun, string $sekarang): RencanaTarik;
}
