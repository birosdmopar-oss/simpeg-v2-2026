<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Log;

use App\Exceptions\ValidationException;
use App\Libraries\ListQuery;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Daftar `integrasi_log` untuk halaman Admin → Integrasi (MAKE-078, ISSUE-058; PRD Fase 6 §6.2 `GET api/v1/integrasi/log`,
 * F6-24). Baca-saja; penulis log tetap {@see IntegrasiLog}.
 *
 * Filter (query string, semua opsional; nilai salah → 422 per parameter, semua sekaligus):
 * - `layanan`: kode layanan seperti yang ditulis penulis log (huruf kecil/angka/garis bawah, maks 30) — mis. `bkn`,
 *   `ekinerja`, `bsre`, `siasn`, `fcm`, `simapi`, `services`; dicocokkan persis.
 * - `status`: 1 sukses, 2 gagal.
 * - `tanggal_mulai` / `tanggal_selesai`: `YYYY-MM-DD` tanggal **WIB** inklusif. `created_at` disimpan UTC (CR-086),
 *   jadi rentang diubah ke UTC: [mulai 00:00 WIB, selesai+1 00:00 WIB). `tanggal_selesai` < `tanggal_mulai` → 422.
 * - `page`, `per_page`: pola {@see ListQuery} (bawaan 25, maks 100).
 *
 * Urutan terbaru dulu (`created_at` DESC, `id_integrasi_log` DESC). Kolom yang dikirim hanya kolom DBV-027 `integrasi_log`
 * (`pesan` sudah teredaksi saat ditulis, BP-5); tidak ada join ke data pegawai/akun. `created_at` dikirim UTC tanpa zona
 * (konvensi API v2; FE menampilkannya lewat `formatApiDateTime`). `layanan_tersedia` = daftar kode layanan yang ada di
 * tabel (untuk pilihan filter).
 */
final class DaftarLogIntegrasi
{
    public const PER_PAGE_DEFAULT = 25;

    public const PER_PAGE_MAX = 100;

    public const PARAMETER = ['layanan', 'status', 'tanggal_mulai', 'tanggal_selesai', 'page', 'per_page'];

    public const KOLOM = [
        'id_integrasi_log', 'arah', 'layanan', 'operasi', 'status', 'http_status', 'kode', 'pesan', 'durasi_ms',
        'percobaan', 'ref_id', 'id_klien', 'created_at',
    ];

    public const PESAN_LAYANAN = 'Layanan hanya boleh huruf kecil, angka, atau garis bawah (maks 30 karakter).';

    public const PESAN_STATUS = 'Status harus 1 (sukses) atau 2 (gagal).';

    public const PESAN_TANGGAL = 'Tanggal harus berformat YYYY-MM-DD dan ada di kalender.';

    public const PESAN_RENTANG = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';

    private const ZONA_BISNIS = 'Asia/Jakarta';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param array<string, mixed> $filters query string
     *
     * @return array{items: list<array<string, int|string|null>>, total: int, page: int, per_page: int, layanan_tersedia: list<string>}
     */
    public function daftar(array $filters): array
    {
        $query   = ListQuery::from($filters, self::PARAMETER);
        $saring  = $this->validasi($query);
        $page    = $query->page();
        $perPage = $query->perPage(self::PER_PAGE_DEFAULT, self::PER_PAGE_MAX);

        $builder = $this->db->table('integrasi_log');

        if ($saring['layanan'] !== null) {
            $builder->where('layanan', $saring['layanan']);
        }

        if ($saring['status'] !== null) {
            $builder->where('status', $saring['status']);
        }

        if ($saring['dari'] !== null) {
            $builder->where('created_at >=', $saring['dari']);
        }

        if ($saring['sampai'] !== null) {
            $builder->where('created_at <', $saring['sampai']);
        }

        $total = (clone $builder)->countAllResults();

        /** @var list<array<string, mixed>> $baris */
        $baris = $builder->select(self::KOLOM)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id_integrasi_log', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return [
            'items'            => array_map(self::baris(...), $baris),
            'total'            => $total,
            'page'             => $page,
            'per_page'         => $perPage,
            'layanan_tersedia' => $this->layananTersedia(),
        ];
    }

    /**
     * @return array{layanan: string|null, status: int|null, dari: string|null, sampai: string|null}
     */
    private function validasi(ListQuery $query): array
    {
        $errors  = [];
        $layanan = null;
        $status  = null;

        if ($query->filled('layanan')) {
            $layanan = $query->string('layanan');

            if (preg_match('/^[a-z0-9_]{1,30}$/', $layanan) !== 1) {
                $errors['layanan'] = [self::PESAN_LAYANAN];
            }
        }

        if ($query->filled('status')) {
            $raw = $query->string('status');

            if (! in_array($raw, ['1', '2'], true)) {
                $errors['status'] = [self::PESAN_STATUS];
            } else {
                $status = (int) $raw;
            }
        }

        $mulai   = $this->tanggal($query, 'tanggal_mulai', $errors);
        $selesai = $this->tanggal($query, 'tanggal_selesai', $errors);

        if ($mulai !== null && $selesai !== null && $selesai < $mulai) {
            $errors['tanggal_selesai'] = [self::PESAN_RENTANG];
        }

        if ($errors !== []) {
            throw new ValidationException(ListQuery::INVALID_MESSAGE, $errors);
        }

        return [
            'layanan' => $layanan,
            'status'  => $status,
            'dari'    => $mulai === null ? null : self::keUtc($mulai),
            'sampai'  => $selesai === null ? null : self::keUtc($selesai->modify('+1 day')),
        ];
    }

    /**
     * Tanggal WIB 00:00 dari parameter; null bila tidak dikirim. Format/kalender salah → $errors[$kunci].
     *
     * @param array<string, list<string>> $errors
     */
    private function tanggal(ListQuery $query, string $kunci, array &$errors): ?DateTimeImmutable
    {
        if (! $query->filled($kunci)) {
            return null;
        }

        $raw   = $query->string($kunci);
        $zona  = new DateTimeZone(self::ZONA_BISNIS);
        $nilai = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $zona) : false;

        // Bolak-balik format menolak tanggal yang "dinormalkan" PHP (mis. 2026-02-30 → 2026-03-02).
        if ($nilai === false || $nilai->format('Y-m-d') !== $raw) {
            $errors[$kunci] = [self::PESAN_TANGGAL];

            return null;
        }

        return $nilai;
    }

    private static function keUtc(DateTimeImmutable $wib): string
    {
        return $wib->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $b
     *
     * @return array<string, int|string|null>
     */
    private static function baris(array $b): array
    {
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $str = static fn (mixed $v): ?string => $v === null ? null : (string) $v;

        return [
            'id_integrasi_log' => (int) $b['id_integrasi_log'],
            'arah'             => (int) $b['arah'],
            'layanan'          => (string) $b['layanan'],
            'operasi'          => (string) $b['operasi'],
            'status'           => (int) $b['status'],
            'http_status'      => $int($b['http_status']),
            'kode'             => $str($b['kode']),
            'pesan'            => $str($b['pesan']),
            'durasi_ms'        => $int($b['durasi_ms']),
            'percobaan'        => (int) $b['percobaan'],
            'ref_id'           => $str($b['ref_id']),
            'id_klien'         => $int($b['id_klien']),
            'created_at'       => (string) $b['created_at'],
        ];
    }

    /**
     * @return list<string>
     */
    private function layananTersedia(): array
    {
        $baris = $this->db->table('integrasi_log')->select('layanan')->distinct()->orderBy('layanan', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $b): string => (string) $b['layanan'], $baris);
    }
}
