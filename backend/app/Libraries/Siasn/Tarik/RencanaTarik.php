<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

/**
 * Rencana tulis satu batch tarik SIASN (dihitung tanpa menulis; `--dry-run` hanya melaporkan jumlahnya). Diterapkan
 * {@see PenulisTarik} dalam satu transaksi. Jumlah per aksi dan kode alasan penolakan tanpa NIP/nilai.
 */
final class RencanaTarik
{
    /** @var list<array{tabel: string, data: array<string, mixed>}> */
    public array $sisip = [];

    /** @var list<array{tabel: string, kunci: array<string, int|string>, data: array<string, mixed>}> */
    public array $ubah = [];

    /** @var list<array{tabel: string, kunci: array<string, int|string>}> */
    public array $hapus = [];

    /** @var array{sisip: int, ubah: int, pasang: int, lepas: int, hapus: int, ganda: int} */
    public array $jumlah = ['sisip' => 0, 'ubah' => 0, 'pasang' => 0, 'lepas' => 0, 'hapus' => 0, 'ganda' => 0];

    /** @var array<string, int> kode alasan → jumlah baris SIASN yang ditolak */
    public array $ditolak = [];

    /**
     * @param array<string, mixed> $data
     */
    public function sisip(string $tabel, array $data, string $hitung = 'sisip'): void
    {
        $this->sisip[] = ['tabel' => $tabel, 'data' => $data];
        $this->jumlah[$hitung]++;
    }

    /**
     * @param array<string, int|string>    $kunci
     * @param array<string, mixed>         $data
     * @param 'lepas'|'pasang'|'ubah'|null $hitung null = pembukuan (mis. flag percobaan), tidak dihitung
     */
    public function ubah(string $tabel, array $kunci, array $data, ?string $hitung = 'ubah'): void
    {
        $this->ubah[] = ['tabel' => $tabel, 'kunci' => $kunci, 'data' => $data];

        if ($hitung !== null) {
            $this->jumlah[$hitung]++;
        }
    }

    /**
     * @param array<string, int|string> $kunci
     */
    public function hapus(string $tabel, array $kunci): void
    {
        $this->hapus[] = ['tabel' => $tabel, 'kunci' => $kunci];
        $this->jumlah['hapus']++;
    }

    public function tolak(string $alasan): void
    {
        $this->ditolak[$alasan] = ($this->ditolak[$alasan] ?? 0) + 1;
    }

    public function kosong(): bool
    {
        return $this->sisip === [] && $this->ubah === [] && $this->hapus === [];
    }
}
