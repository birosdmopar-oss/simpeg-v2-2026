<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Kehadiran;

use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Models\AuditLogModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Presensi;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * D-03 presensi manual oleh akun khusus (PRD 5A §7.6, QASMTASK-078) — port `Presensi.php:1284-1455` /
 * `L_presensi.php:3304-3478` [K] dengan validasi [V2] (F5-14). Pengecualian akun khusus disengaja (SRS-NFR-006).
 *
 * - Otorisasi: route role 1; service `AuthContext::idPengguna ∈ Config\Presensi::$akunPresensiManual`, selain itu 403
 *   `bukan_akun_presensi_manual` — dicek sebelum 404/422.
 * - Validasi per field (§7.6.2, whitelist): `nip` pegawai ada (status apa pun [K]); `tgl` YYYY-MM-DD ≤ hari ini lokal satker
 *   pegawai [V2]; jam `HH:MM`/`HH:MM:SS` → `HH:MM:SS`, kosong = NULL, minimal satu terisi [K], masuk ≤ pulang [V2];
 *   duplikat `(nip, tgl)` saat tambah → 422 `errors.tgl` (pra-cek + tangkap 1062). Jendela & geofence tidak berlaku [K].
 * - Tambah: INSERT `sumber = 2`, koordinat NULL; Ubah: `nip`/`tgl` dari path, jam yang berubah mengosongkan bukti lokasi
 *   jam itu (Q5A-16), `sumber = 2`; Hapus: fisik [K], audit `delete` dengan baris lengkap (pengganti `d_dh_online`).
 *   Semua dalam satu transaksi dengan audit `dh_online` `{nip}:{tgl}`.
 */
final class PresensiManualService
{
    public const PESAN_BUKAN_AKUN = 'Hanya akun khusus yang dapat mengelola presensi manual.';

    public const PESAN_TIDAK_DITEMUKAN = 'Data presensi tidak ditemukan.';

    public const PESAN_DUPLIKAT = 'Presensi pegawai ini pada tanggal tersebut sudah ada. Gunakan Edit.';

    private const ER_DUP_ENTRY = 1062;

    private readonly Presensi $config;

    /** @var Closure(): DateTimeInterface */
    private readonly Closure $jam;

    /**
     * @param (Closure(): DateTimeInterface)|null   $jam              instan sekarang (bawaan jam server); disuntik test
     * @param (Closure(string, string): void)|null $kaitSebelumTulis seam test duplikat bersamaan (AC-D03.4, jalur tangkap
     *                                                                 1062): dipanggil setelah pra-cek, tepat sebelum
     *                                                                 transaksi tulis (pola `PresensiOnlineService`)
     */
    public function __construct(
        private readonly ?BaseConnection $db = null,
        ?Presensi $config = null,
        ?Closure $jam = null,
        private readonly ?Closure $kaitSebelumTulis = null,
    ) {
        $this->config = $config ?? config(Presensi::class);
        $this->jam    = $jam ?? static fn (): DateTimeInterface => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function akunKhusus(AuthContext $auth): bool
    {
        $id = $auth->idPengguna();

        return $id !== null && in_array($id, $this->config->akunPresensiManual, true);
    }

    /**
     * `POST presensi/manual` (201).
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function tambah(AuthContext $auth, array $body): array
    {
        $this->wajibAkunKhusus($auth);
        $db     = $this->koneksi();
        $errors = [];
        $nip    = $this->teks($body['nip'] ?? null);

        if ($nip === '') {
            $errors['nip'][] = 'NIP wajib diisi.';
        } elseif ($db->table('pegawai')->where('nip', $nip)->countAllResults() === 0) {
            $errors['nip'][] = 'Pegawai tidak ditemukan.';
        }

        $tgl = $this->tanggal($body['tgl'] ?? null, $errors);

        if ($tgl !== null && ! isset($errors['nip'])) {
            $hariIni = ZonaSatker::waktuLokal(($this->jam)(), (new ZonaSatker($db))->zonasi($nip))->format('Y-m-d');

            if ($tgl > $hariIni) {
                $errors['tgl'][] = 'Tanggal tidak boleh melebihi hari ini.';
            }
        }

        [$masuk, $pulang] = $this->jamDariBody($body, $errors);
        $this->lemparBilaGalat($errors);

        if ($this->baris($db, $nip, (string) $tgl) !== null) {
            throw ValidationException::forField('tgl', self::PESAN_DUPLIKAT);
        }

        $baru = [
            'nip'        => $nip, 'tgl' => $tgl, 'jam_masuk' => $masuk, 'jam_pulang' => $pulang, 'sumber' => PresensiOnlineService::SUMBER_MANUAL,
            'created_at' => gmdate('Y-m-d H:i:s'), 'created_by' => $auth->idPengguna(), 'updated_by' => $auth->idPengguna(),
        ];

        if ($this->kaitSebelumTulis !== null) {
            ($this->kaitSebelumTulis)($nip, (string) $tgl);
        }

        $this->transaksi($db, function (BaseConnection $db) use ($auth, $baru, $nip, $tgl): void {
            try {
                $ok = $db->table('dh_online')->insert($baru);
            } catch (DatabaseException $e) {
                if ((int) $e->getCode() === self::ER_DUP_ENTRY) {
                    throw ValidationException::forField('tgl', self::PESAN_DUPLIKAT);
                }

                throw $e;
            }

            if ($ok === false) {
                if ((int) ($db->error()['code'] ?? 0) === self::ER_DUP_ENTRY) {
                    throw ValidationException::forField('tgl', self::PESAN_DUPLIKAT);
                }

                throw new RuntimeException('Tulis dh_online gagal.');
            }

            $this->audit($db, $auth, $nip, (string) $tgl, AuditLogModel::EVENT_CREATE, null, $baru);
        });

        return $this->baris($db, $nip, (string) $tgl) ?? [];
    }

    /**
     * `PUT presensi/manual/{nip}/{tgl}` (200). Body `jam_masuk`, `jam_pulang` (kosong = NULL); field lain diabaikan.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function ubah(AuthContext $auth, string $nip, string $tgl, array $body): array
    {
        $this->wajibAkunKhusus($auth);
        $db   = $this->koneksi();
        $lama = $this->barisPath($db, $nip, $tgl);

        $errors           = [];
        [$masuk, $pulang] = $this->jamDariBody($body, $errors);
        $this->lemparBilaGalat($errors);

        $ubah = ['jam_masuk' => $masuk, 'jam_pulang' => $pulang, 'sumber' => PresensiOnlineService::SUMBER_MANUAL, 'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => $auth->idPengguna()];

        foreach (['masuk' => $masuk, 'pulang' => $pulang] as $jenis => $nilai) {
            if ($nilai !== $lama["jam_{$jenis}"]) {
                $ubah += ["lat_{$jenis}" => null, "lng_{$jenis}" => null, "akurasi_{$jenis}" => null, "id_lokasi_{$jenis}" => null];
            }
        }

        $this->transaksi($db, function (BaseConnection $db) use ($auth, $nip, $tgl, $lama, $ubah): void {
            $db->table('dh_online')->where(['nip' => $nip, 'tgl' => $tgl])->update($ubah);
            $this->audit($db, $auth, $nip, $tgl, AuditLogModel::EVENT_UPDATE, array_intersect_key($lama, $ubah), $ubah);
        });

        return $this->baris($db, $nip, $tgl) ?? [];
    }

    /**
     * `DELETE presensi/manual/{nip}/{tgl}` (200) — hapus fisik [K], audit `delete` dengan baris lengkap.
     */
    public function hapus(AuthContext $auth, string $nip, string $tgl): void
    {
        $this->wajibAkunKhusus($auth);
        $db   = $this->koneksi();
        $lama = $this->barisPath($db, $nip, $tgl);

        $this->transaksi($db, function (BaseConnection $db) use ($auth, $nip, $tgl, $lama): void {
            $db->table('dh_online')->where(['nip' => $nip, 'tgl' => $tgl])->delete();
            $this->audit($db, $auth, $nip, $tgl, AuditLogModel::EVENT_DELETE, $lama, null);
        });
    }

    private function wajibAkunKhusus(AuthContext $auth): void
    {
        if (! $this->akunKhusus($auth)) {
            throw new ApiException(self::PESAN_BUKAN_AKUN, 403, kode: KodeGalatPresensi::BUKAN_AKUN_PRESENSI_MANUAL);
        }
    }

    /**
     * Baris dari path (`nip`/`tgl`); tidak ada atau path tidak sah → 404.
     *
     * @return array<string, mixed>
     */
    private function barisPath(BaseConnection $db, string $nip, string $tgl): array
    {
        if (preg_match('/^\d{1,30}$/', $nip) !== 1 || ! $this->tanggalSah($tgl)) {
            throw new NotFoundException(self::PESAN_TIDAK_DITEMUKAN);
        }

        return $this->baris($db, $nip, $tgl) ?? throw new NotFoundException(self::PESAN_TIDAK_DITEMUKAN);
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function tanggal(mixed $nilai, array &$errors): ?string
    {
        $tgl = $this->teks($nilai);

        if ($tgl === '') {
            $errors['tgl'][] = 'Tanggal wajib diisi.';

            return null;
        }

        if (! $this->tanggalSah($tgl)) {
            $errors['tgl'][] = 'Format tanggal harus YYYY-MM-DD.';

            return null;
        }

        return $tgl;
    }

    private function tanggalSah(string $tgl): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $tgl);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) === 1 && $d !== false && $d->format('Y-m-d') === $tgl;
    }

    /**
     * @param array<string, mixed>        $body
     * @param array<string, list<string>> $errors
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function jamDariBody(array $body, array &$errors): array
    {
        $hasil = [];

        foreach (['jam_masuk', 'jam_pulang'] as $field) {
            $nilai = $body[$field] ?? null;

            if ($nilai === null || (is_string($nilai) && trim($nilai) === '')) {
                $hasil[$field] = null;

                continue;
            }

            if (! is_string($nilai) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', trim($nilai), $m) !== 1) {
                $errors[$field][] = 'Format jam harus HH:MM atau HH:MM:SS.';
                $hasil[$field]    = null;

                continue;
            }

            $hasil[$field] = sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
        }

        if (! isset($errors['jam_masuk']) && ! isset($errors['jam_pulang'])) {
            if ($hasil['jam_masuk'] === null && $hasil['jam_pulang'] === null) {
                $errors['jam_masuk'][] = 'Isi minimal salah satu: jam masuk atau jam pulang.';
            } elseif ($hasil['jam_masuk'] !== null && $hasil['jam_pulang'] !== null && $hasil['jam_masuk'] > $hasil['jam_pulang']) {
                $errors['jam_pulang'][] = 'Jam pulang tidak boleh lebih awal dari jam masuk.';
            }
        }

        return [$hasil['jam_masuk'], $hasil['jam_pulang']];
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function lemparBilaGalat(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }
    }

    private function teks(mixed $nilai): string
    {
        return is_string($nilai) || is_int($nilai) ? trim((string) $nilai) : '';
    }

    /**
     * @param Closure(BaseConnection): void $kerja
     */
    private function transaksi(BaseConnection $db, Closure $kerja): void
    {
        $db->transBegin();

        try {
            $kerja($db);

            if ($db->transStatus() === false) {
                throw new RuntimeException('Transaksi presensi manual gagal.');
            }

            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();
            $db->resetTransStatus();

            throw $e;
        }
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function audit(BaseConnection $db, AuthContext $auth, string $nip, string $tgl, string $event, ?array $before, ?array $after): void
    {
        $id = (new AuditLogModel($db))->record('dh_online', "{$nip}:{$tgl}", $event, $before, $after, $auth->idPengguna(), $auth->nip());

        if ($id <= 0) {
            throw new RuntimeException('Audit presensi manual gagal ditulis.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function baris(BaseConnection $db, string $nip, string $tgl): ?array
    {
        return $db->table('dh_online')->where(['nip' => $nip, 'tgl' => $tgl])->get()->getRowArray();
    }

    private function koneksi(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
