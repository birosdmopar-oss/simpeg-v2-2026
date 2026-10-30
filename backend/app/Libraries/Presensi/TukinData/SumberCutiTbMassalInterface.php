<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

/**
 * Varian massal {@see SumberCutiTbInterface} untuk laporan/rekap Tukin banyak pegawai (PRD 5B §7.1.1, §7.3.7): jumlah
 * kueri tetap terhadap jumlah NIP. Hasil per NIP WAJIB sama dengan `untuk()` NIP itu; data tidak sah satu pegawai tidak
 * menggagalkan pegawai lain — nilainya berupa {@see GalatDataTukin} (yang dilempar `untuk()` untuk NIP itu).
 */
interface SumberCutiTbMassalInterface
{
    /**
     * @param list<string> $nip
     *
     * @return array<string, array<string, array{tugas_belajar?: true, cuti?: array{id?: int, jenis: string, mulai: string, akhir: string, lama: int}}>|GalatDataTukin>
     *                                                                                                                                                         per NIP (setiap NIP masukan menjadi kunci) lalu per tanggal YYYY-MM-DD, atau galat NIP itu
     */
    public function untukBanyak(array $nip, string $awal, string $akhir): array;
}
