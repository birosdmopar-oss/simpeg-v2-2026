<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Exceptions\ValidationException;
use CodeIgniter\Database\BaseConnection;

/**
 * ISSUE-019 — penjaga parameter query endpoint daftar (list), dipakai bersama MasterService dan UserService supaya
 * kedua endpoint memperlakukan query string dengan cara yang sama.
 *
 * Query string PHP boleh berbentuk array (`?search[]=a`), sedangkan seluruh parameter daftar hanya bermakna sebagai
 * nilai tunggal. Tanpa penjaga ini `(string) $filters['search']` memicu "Array to string conversion" yang di CI4
 * menjadi ErrorException → 500. Di sini bentuk array/objek ditolak 422 dengan pesan per key (ADR-001), sehingga
 * klien tahu parameter mana yang salah.
 *
 * `page` juga dibatasi: nilai yang bukan angka bulat positif atau yang melewati PAGE_MAX ditolak 422, karena
 * offset ((page - 1) * per_page) dari nilai sebesar itu meluap jangkauan int dan membuat
 * BaseBuilder::limit() melempar TypeError → 500. Halaman valid yang melewati jumlah data TIDAK ditolak: query tetap
 * dijalankan dan menghasilkan daftar kosong (200).
 */
final class ListQuery
{
    /**
     * Batas atas `page`. Cukup jauh di atas kebutuhan nyata (PER_PAGE_MAX 100 → 100 juta baris) dan cukup kecil
     * agar (page - 1) * per_page selalu aman sebagai int.
     */
    public const PAGE_MAX = 1000000;

    /** Pesan 422 gabungan; rincian per parameter ada di `errors`. */
    public const INVALID_MESSAGE = 'Parameter daftar tidak valid.';

    public const SCALAR_MESSAGE = 'Parameter ini hanya boleh berisi satu nilai teks atau angka.';

    public const PAGE_MESSAGE = 'Halaman harus berupa angka bulat 1 sampai ' . self::PAGE_MAX . '.';

    /**
     * @param array<string, string> $values nilai yang sudah dipastikan skalar dan dipangkas spasi
     */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * Saring parameter daftar dari query string. Semua key yang dikirim sebagai array/objek dikumpulkan menjadi
     * SATU 422 dengan pesan per key. Key yang tidak dikirim tidak masuk hasil (dianggap kosong).
     *
     * @param array<string, mixed> $query
     * @param list<string>         $keys
     */
    public static function from(array $query, array $keys): self
    {
        $values = [];
        $errors = [];

        foreach ($keys as $key) {
            $raw = $query[$key] ?? null;

            if ($raw === null) {
                continue;
            }

            if (! is_scalar($raw)) {
                $errors[$key] = [self::SCALAR_MESSAGE];

                continue;
            }

            $values[$key] = trim((string) $raw);
        }

        if ($errors !== []) {
            throw new ValidationException(self::INVALID_MESSAGE, $errors);
        }

        return new self($values);
    }

    /**
     * Nilai parameter; '' bila tidak dikirim atau hanya berisi spasi.
     */
    public function string(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    /**
     * Parameter dikirim dengan isi (bukan kosong) — dipakai sebagai syarat memasang filter.
     */
    public function filled(string $key): bool
    {
        return $this->string($key) !== '';
    }

    /**
     * Nomor halaman. Tidak dikirim/kosong → 1; selain angka bulat 1..PAGE_MAX → 422.
     */
    public function page(): int
    {
        $raw = $this->string('page');

        if ($raw === '') {
            return 1;
        }

        // Dibandingkan sebagai untai digit, bukan lewat (int): nilai yang jauh melewati jangkauan int
        // (mis. ?page=99999999999999999999) akan terpotong diam-diam kalau di-cast lebih dulu.
        $digits = preg_match('/^\d+$/', $raw) === 1 ? ltrim($raw, '0') : '';

        if ($digits === '' || strlen($digits) > strlen((string) self::PAGE_MAX) || (int) $digits > self::PAGE_MAX) {
            throw ValidationException::forField('page', self::PAGE_MESSAGE);
        }

        return (int) $digits;
    }

    /**
     * Jumlah baris per halaman, dijepit ke 1..$max (nilai di luar jangkauan dijepit, bukan ditolak).
     */
    public function perPage(int $default, int $max): int
    {
        $raw = $this->string('per_page');

        return $raw === '' ? $default : min($max, max(1, (int) $raw));
    }

    /**
     * Kata kunci pencarian sebagai teks biasa untuk LIKE. Query Builder CI4 menambahkan ESCAPE '!' tetapi tidak
     * meng-escape wildcard di nilainya, jadi `!`, `%`, dan `_` di-escape di sini agar `?search=%` mencari karakter
     * '%' dan bukan mencocokkan semua baris (tanda kutip tetap di-escape oleh binding builder).
     */
    public static function likeLiteral(BaseConnection $db, string $search): string
    {
        $escape = $db->likeEscapeChar;

        return str_replace([$escape, '%', '_'], [$escape . $escape, $escape . '%', $escape . '_'], $search);
    }
}
