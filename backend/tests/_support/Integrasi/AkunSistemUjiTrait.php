<?php

declare(strict_types=1);

namespace Tests\Support\Integrasi;

use App\Libraries\Integrasi\AkunSistemIntegrasi;

/**
 * Menyiapkan akun sistem integrasi di test lewat service command `integrasi:siapkan-akun-sistem` (DBV-027 Z-1 opsi c),
 * bukan INSERT tiruan, supaya bentuk akunnya selalu sama dengan cutover.
 */
trait AkunSistemUjiTrait
{
    /**
     * @return array<string, mixed> baris `pengguna` akun sistem
     */
    protected function siapkanAkunSistem(): array
    {
        $layanan = new AkunSistemIntegrasi($this->db);
        $layanan->siapkan();

        /** @var array<string, mixed> $baris */
        $baris = $this->db->table('pengguna')->where('id_pengguna', $layanan->id())->get()->getRowArray();

        return $baris;
    }
}
