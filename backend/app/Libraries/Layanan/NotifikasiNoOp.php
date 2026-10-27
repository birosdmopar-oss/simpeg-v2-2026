<?php

declare(strict_types=1);

namespace App\Libraries\Layanan;

use App\Interfaces\Layanan\NotifikasiLayananInterface;

/**
 * Binding sementara `Config\Services::notifikasiLayanan()` sampai M4: tidak menulis apa pun dan tidak mengantrekan
 * push. Binding diganti NotifikasiService di M4 (MAKE-023); pemanggil TB/IB/PMK (M5) tidak berubah.
 */
final class NotifikasiNoOp implements NotifikasiLayananInterface
{
    public function kirim(string $modul, int|string $idRwy, int $tipe, array $penerima, string $judul, string $isi, ?string $reasonNote, int $statusSumber, string $url): array
    {
        return [];
    }

    public function enqueuePush(array $ids): void
    {
    }
}
