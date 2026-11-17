<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg;

use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\ListQuery;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\HaloSimpeg;
use Throwable;

/**
 * Basis service master Halo Simpeg (kategori, petugas, kontak; MAKE-070, PRD Fase 8 v1.3 §6.1b, §7.2, §7.3a). Kontrak beku:
 * `Controllers/Api/HaloSimpeg/README.md`.
 *
 * - Status master **0 Tidak Aktif / 1 Aktif** (DBV-022 DQ-5, DBV-028 CHECK), berbeda dari chatbot (1/2).
 * - Teks di-trim, panjang = `mb_strlen` sesudah trim; galat 422 per kolom `errors.<field>`, `message` = galat pertama.
 * - Waktu ditulis UTC (`gmdate`) dan dikirim ISO-8601 berakhiran `Z`.
 * - Tulis lewat model beraudit; tulis berganda dalam satu transaksi (pola `SinkronEkinerja::dalamTransaksi`).
 */
abstract class MasterHaloSimpeg
{
    public const STATUS_AKTIF = 1;

    public const STATUS_NONAKTIF = 0;

    public const PESAN_TIDAK_DITEMUKAN = 'Data tidak ditemukan.';

    public const PESAN_STATUS = 'Status tidak valid.';

    protected readonly BaseConnection $db;

    protected readonly HaloSimpeg $config;

    public function __construct(?BaseConnection $db = null, ?HaloSimpeg $config = null)
    {
        $this->db     = $db ?? db_connect();
        $this->config = $config ?? config(HaloSimpeg::class);
    }

    /**
     * Penjaga query daftar (`page`, `per_page`, filter): parameter berbentuk array → 422 (ListQuery).
     *
     * @param array<string, mixed> $query
     * @param list<string>         $kunci
     */
    protected function listQuery(array $query, array $kunci): ListQuery
    {
        return ListQuery::from($query, [...$kunci, 'page', 'per_page']);
    }

    protected function perHalaman(ListQuery $q): int
    {
        return $q->perPage($this->config->perHalamanBawaan, $this->config->perHalamanMaks);
    }

    /** Filter `status` daftar master: '0' / '1' → angka; kosong → null (semua); nilai lain → 422. */
    protected function filterStatus(ListQuery $q): ?int
    {
        $s = $q->string('status');

        if ($s === '') {
            return null;
        }

        if (! in_array($s, ['0', '1'], true)) {
            throw ValidationException::forField('status', self::PESAN_STATUS);
        }

        return (int) $s;
    }

    /** Nilai status masukan (0/1, angka atau teks); selain itu null. */
    protected static function statusDari(mixed $v): ?int
    {
        return in_array($v, [0, 1, '0', '1'], true) ? (int) $v : null;
    }

    /** Teks masukan sesudah trim; bukan skalar → '' (dicek wajib/panjang oleh pemanggil). */
    protected static function teks(mixed $v): string
    {
        return is_string($v) || is_int($v) || is_float($v) ? trim((string) $v) : '';
    }

    /**
     * Kumpulkan galat per kolom lalu lempar sekali (message = galat pertama).
     *
     * @param array<string, string> $errors
     */
    protected static function lemparBilaGalat(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException((string) reset($errors), array_map(static fn (string $p): array => [$p], $errors));
        }
    }

    /** 422 bisnis ber-`code` (amplop v2 `code`), dengan `errors.<field>` bila ada kolom terkait. */
    protected static function galatKode(string $kode, string $pesan, ?string $field = null): ApiException
    {
        return new ApiException($pesan, 422, $field === null ? null : [$field => [$pesan]], $kode);
    }

    protected static function galatStatus(): ValidationException
    {
        return ValidationException::forField('status', self::PESAN_STATUS);
    }

    protected static function tidakDitemukan(): NotFoundException
    {
        return new NotFoundException(self::PESAN_TIDAK_DITEMUKAN);
    }

    protected static function sekarang(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** DATETIME (UTC) → ISO-8601 `Z`; kosong/nol → null (pola `PusatNotifikasiService::iso`). */
    protected static function iso(mixed $waktu): ?string
    {
        if (! is_string($waktu) || preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $waktu, $m) !== 1 || str_starts_with($waktu, '0000')) {
            return null;
        }

        return "{$m[1]}T{$m[2]}Z";
    }

    /**
     * Jalankan tulis berganda dalam satu transaksi. Galat API (422/404) diteruskan apa adanya; galat DB 1062 yang lolos cek
     * aplikasi (balapan) diterjemahkan lewat `$duplikat` bila diberikan.
     *
     * @template T
     *
     * @param callable(): T $tulis
     *
     * @return T
     */
    protected function dalamTransaksi(callable $tulis, ?callable $duplikat = null): mixed
    {
        $this->db->transBegin();

        try {
            $hasil = $tulis();

            if ($this->db->transStatus() === false) {
                throw new DatabaseException('Simpan data Halo Simpeg gagal (' . $this->db->error()['code'] . ').');
            }

            $this->db->transCommit();

            return $hasil;
        } catch (Throwable $e) {
            $this->db->transRollback();

            if ($duplikat !== null && $e instanceof DatabaseException && $e->getCode() === 1062) {
                throw $duplikat();
            }

            throw $e;
        } finally {
            $this->db->resetTransStatus();
        }
    }
}
