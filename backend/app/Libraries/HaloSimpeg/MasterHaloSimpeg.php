<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg;

use App\Controllers\Api\ApiController;
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

    /**
     * Teks masukan `$in[$field]` sesudah trim. Tidak dikirim / null → ''. Nilai bukan teks/angka (array, objek, boolean) → '' dan
     * `$errors[$field]` = "Isian harus berupa teks." (`ApiController::NON_TEXT_FIELD_MESSAGE`, M4), bukan dianggap kosong.
     *
     * @param array<string, mixed>  $in
     * @param array<string, string> $errors
     */
    protected static function teksDari(array $in, string $field, array &$errors): string
    {
        $v = $in[$field] ?? null;

        if ($v === null) {
            return '';
        }

        if (! is_string($v) && ! is_int($v) && ! is_float($v)) {
            $errors[$field] = ApiController::NON_TEXT_FIELD_MESSAGE;

            return '';
        }

        return trim((string) $v);
    }

    /**
     * Kunci baris `$tabel` (FOR UPDATE) di dalam transaksi berjalan, urut id menaik agar urutan kunci konsisten (tanpa deadlock).
     * Mengunci `question_category` juga menahan sisip tiket/relasi baru ke kategori itu (FK mengambil kunci bersama induk).
     *
     * @param list<int> $id
     */
    protected function kunci(string $tabel, string $pk, array $id): void
    {
        if ($id === []) {
            return;
        }

        sort($id);
        $tanda = implode(', ', array_fill(0, count($id), '?'));
        $this->db->query('SELECT ' . $this->db->escapeIdentifiers($pk) . ' FROM ' . $this->db->escapeIdentifiers($this->db->prefixTable($tabel))
            . ' WHERE ' . $this->db->escapeIdentifiers($pk) . " IN ({$tanda}) ORDER BY " . $this->db->escapeIdentifiers($pk) . ' FOR UPDATE', $id);
    }

    /** Galat DB 1062 milik UNIQUE `$namaKunci` (pesan MySQL/MariaDB memuat nama kunci). */
    protected static function duplikatKunci(DatabaseException $e, string $namaKunci): bool
    {
        return $e->getCode() === 1062 && str_contains($e->getMessage(), $namaKunci);
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
     * Jalankan tulis (dan cek yang harus konsisten dengannya) dalam satu transaksi. Galat API (422/404) yang dilempar dari dalam
     * diteruskan apa adanya sesudah rollback. Galat DB yang lolos cek aplikasi (balapan: 1062, 1451) diterjemahkan lewat
     * `$galatDb` bila ia mengembalikan ApiException; selain itu galat asli dilempar ulang.
     *
     * @template T
     *
     * @param callable(): T                                      $tulis
     * @param (callable(DatabaseException): (ApiException|null))|null $galatDb
     *
     * @return T
     */
    protected function dalamTransaksi(callable $tulis, ?callable $galatDb = null): mixed
    {
        // Di dalam transaksi CI4 tidak melempar galat query kecuali transException aktif; tanpa ini kode galat asli (1062/1451/
        // 1205) hilang dan langkah berikutnya tetap berjalan. Dikembalikan ke bawaan (false) di finally agar tidak menempel di
        // koneksi bersama.
        $this->db->transException(true)->transBegin();

        try {
            $hasil = $tulis();

            if ($this->db->transStatus() === false) {
                $galat = $this->db->error();

                throw new DatabaseException('Simpan data Halo Simpeg gagal: ' . $galat['message'], (int) $galat['code']);
            }

            $this->db->transCommit();

            return $hasil;
        } catch (Throwable $e) {
            $this->db->transRollback();

            if ($galatDb !== null && $e instanceof DatabaseException) {
                $terjemahan = $galatDb($e);

                if ($terjemahan !== null) {
                    throw $terjemahan;
                }
            }

            throw $e;
        } finally {
            $this->db->transException(false);
            $this->db->resetTransStatus();
        }
    }
}
