<?php

declare(strict_types=1);

namespace App\Libraries\Notifikasi\Pusat;

use App\Libraries\Auth\AuthContext;

/**
 * Sumber daftar notifikasi di dalam modul Pusat Notifikasi (S1 layanan, S2 `show_notif`, adapter sumber eksternal).
 *
 * Item = `{ id: "<sumber>:<id>", sumber, judul, keterangan, status_label, reason_note, url_fe, dibaca, waktu, dapat_dihapus }`
 * dengan `waktu` UTC `Y-m-d H:i:s` (diformat ISO oleh {@see PusatNotifikasiService}).
 */
interface SumberDaftar
{
    /**
     * Kunci sumber yang dilayani untuk peran pemanggil (kosong = sumber tidak berlaku bagi pemanggil).
     *
     * @return list<string>
     */
    public function kunci(AuthContext $auth): array;

    public function jumlah(AuthContext $auth, bool $hanyaBelumDibaca): int;

    /**
     * @return list<array<string, mixed>>
     */
    public function daftar(AuthContext $auth, bool $hanyaBelumDibaca, int $batas): array;

    /**
     * @param list<int> $ids
     */
    public function tandaiDibaca(AuthContext $auth, string $kunci, array $ids): int;

    public function hapus(AuthContext $auth, string $kunci, int $id): bool;
}
