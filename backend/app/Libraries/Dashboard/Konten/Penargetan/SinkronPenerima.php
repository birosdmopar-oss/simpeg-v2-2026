<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten\Penargetan;

use Closure;
use CodeIgniter\Database\BaseConnection;

/**
 * Menyinkronkan snapshot penerima satu induk (popup/banner/form digital) dengan daftar NIP baru (PRD Fase 7 v2.0 §5.3):
 * hanya SELISIH yang ditulis — NIP baru ditambah, NIP yang tak lagi ditarget dihapus, sisanya dipertahankan. Berbeda dengan
 * legacy (`update_popup:279` hapus semua lalu buat ulang) agar penerima yang bertahan tidak kehilangan baris.
 *
 * `$bolehHapus(string $nip): bool` (opsional) menahan penghapusan baris tertentu — dipakai Jalur B untuk menjaga penerima
 * form digital yang sudah mengisi. NIP yang ditahan dihitung `dipertahankan`. Berjalan dalam transaksi; aman dipanggil di
 * dalam transaksi pemanggil (CI4 menyarangkan lewat kedalaman transaksi).
 */
final class SinkronPenerima
{
    private const UKURAN_BATCH = 500;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<string>         $nipBaru
     * @param (Closure(string): bool)|null $bolehHapus
     *
     * @return array{ditambah: int, dihapus: int, dipertahankan: int}
     */
    public function sinkron(TabelPenerima $tabel, int $idInduk, array $nipBaru, ?Closure $bolehHapus = null): array
    {
        $nama  = $tabel->value;
        $kolom = $tabel->kolomInduk();
        $baru  = array_values(array_unique(array_map('strval', $nipBaru)));

        $ada = array_map(
            'strval',
            array_column($this->db->table($nama)->select('nip')->where($kolom, $idInduk)->get()->getResultArray(), 'nip'),
        );

        $tambah = array_values(array_diff($baru, $ada));
        $lepas  = array_values(array_diff($ada, $baru));

        if ($bolehHapus !== null) {
            $lepas = array_values(array_filter($lepas, $bolehHapus));
        }

        $this->db->transStart();

        foreach (array_chunk($tambah, self::UKURAN_BATCH) as $potong) {
            $this->db->table($nama)->insertBatch(array_map(static fn (string $nip): array => [$kolom => $idInduk, 'nip' => $nip], $potong));
        }

        foreach (array_chunk($lepas, self::UKURAN_BATCH) as $potong) {
            $this->db->table($nama)->where($kolom, $idInduk)->whereIn('nip', $potong)->delete();
        }

        $this->db->transComplete();

        return [
            'ditambah'      => count($tambah),
            'dihapus'       => count($lepas),
            'dipertahankan' => count($ada) - count($lepas),
        ];
    }
}
