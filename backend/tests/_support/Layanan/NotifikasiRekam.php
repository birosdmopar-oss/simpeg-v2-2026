<?php

declare(strict_types=1);

namespace Tests\Support\Layanan;

use App\Interfaces\Layanan\NotifikasiLayananInterface;

/**
 * Perekam titik sambung notifikasi (MAKE-024 M5, sebelum M4): mencatat setiap kirim() dan enqueuePush() untuk
 * diperiksa test (penerima, judul, tipe, status sumber; enqueue hanya sesudah commit).
 */
final class NotifikasiRekam implements NotifikasiLayananInterface
{
    /** @var list<array{modul: string, id: int|string, tipe: int, penerima: list<string>, judul: string, isi: string, reason_note: ?string, status: int, url: string}> */
    public array $kirim = [];

    /** @var list<list<int>> */
    public array $enqueue = [];

    private int $id = 0;

    public function kirim(string $modul, int|string $idRwy, int $tipe, array $penerima, string $judul, string $isi, ?string $reasonNote, int $statusSumber, string $url): array
    {
        $this->kirim[] = ['modul' => $modul, 'id' => $idRwy, 'tipe' => $tipe, 'penerima' => $penerima, 'judul' => $judul, 'isi' => $isi, 'reason_note' => $reasonNote, 'status' => $statusSumber, 'url' => $url];

        return array_map(fn (): int => ++$this->id, $penerima);
    }

    public function enqueuePush(array $ids): void
    {
        $this->enqueue[] = $ids;
    }
}
