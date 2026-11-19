<?php

declare(strict_types=1);

namespace Tests\Support\Ukur;

use Closure;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;

/**
 * CR-157: penghitung kueri DB per request lewat event CI4 `DBQuery`. Dipasang tepat sebelum request dan dilepas sesudahnya,
 * jadi kueri setUp/fixture tidak ikut terhitung. Kueri dipisah menjadi metadata (`SHOW`, `DESCRIBE`, `information_schema`,
 * `SELECT … LIMIT 0`, list tabel/kolom) dan data. Hanya untuk test; tidak menyentuh kode aplikasi.
 */
final class PenghitungKueri
{
    private int $metadata = 0;

    private int $data = 0;

    private ?Closure $pendengar = null;

    /**
     * @var list<string>
     */
    private array $teks = [];

    public function mulai(): void
    {
        $this->metadata  = 0;
        $this->data      = 0;
        $this->teks      = [];
        $this->pendengar = function (Query $q): void {
            $sql          = $q->getQuery();
            $this->teks[] = $sql;

            if (self::apakahMetadata($sql)) {
                $this->metadata++;
            } else {
                $this->data++;
            }
        };
        Events::on('DBQuery', $this->pendengar);
    }

    public function selesai(): void
    {
        if ($this->pendengar !== null) {
            Events::removeListener('DBQuery', $this->pendengar);
            $this->pendengar = null;
        }
    }

    public function total(): int
    {
        return $this->metadata + $this->data;
    }

    public function metadata(): int
    {
        return $this->metadata;
    }

    public function data(): int
    {
        return $this->data;
    }

    /**
     * Teks kueri tercatat (untuk penelusuran manual; tidak ditulis ke berkas).
     *
     * @return list<string>
     */
    public function kueri(): array
    {
        return $this->teks;
    }

    public static function apakahMetadata(string $sql): bool
    {
        $s = strtolower(ltrim($sql));

        return str_starts_with($s, 'show ')
            || str_starts_with($s, 'describe ')
            || str_starts_with($s, 'desc ')
            || str_starts_with($s, 'explain ')
            || str_contains($s, 'information_schema')
            || (bool) preg_match('/\blimit\s+0\s*$/', rtrim($s, "; \n\t"));
    }
}
