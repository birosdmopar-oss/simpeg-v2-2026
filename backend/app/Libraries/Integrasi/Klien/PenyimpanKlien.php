<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Klien;

use App\Models\AuditLogModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * MAKE-058 — penyimpanan klien layanan (`klien_layanan`, DBV-026) untuk command `integrasi:klien` (PRD Fase 6 §7.0.1, F6-08).
 * Semua perubahan satu transaksi pada SATU koneksi bersama `audit_logs` (model audit dibuat dari koneksi yang sama) dan
 * mencabut token (`simapi_token.status = 2`) bila klien dinonaktifkan/dihapus/dirotasi (DBV-026 §13.2 A1). Rahasia mentah
 * hanya dikembalikan ke pemanggil (ditampilkan sekali); tidak disimpan, tidak dicatat, tidak masuk audit.
 */
final class PenyimpanKlien
{
    public const STATUS_AKTIF    = 1;
    public const STATUS_NONAKTIF = 2;
    public const STATUS_DIHAPUS  = 10;

    public const AUDIT_ENTITAS = 'klien_layanan';

    private const ER_DUP_ENTRY = 1062;

    /**
     * @param (Closure(BaseConnection): AuditLogModel)|null $pabrikAudit pembuat model audit pada koneksi yang diberikan (hanya test)
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly ?Closure $pabrikAudit = null,
        private readonly int $rahasiaPanjang = 40,
    ) {
    }

    /**
     * Klien baru. `$hak` daftar hak sah; `$ipAllowlist` null = semua IP, selain itu daftar IP/CIDR (divalidasi, kosong ditolak).
     *
     * @param list<string> $hak
     *
     * @return array{id: int, rahasia: string}
     *
     * @throws InvalidArgumentException masukan tidak sah atau username sudah dipakai
     */
    public function buat(string $nama, string $username, int $jenis, array $hak, ?string $ipAllowlist, ?int $idPelaku = null): array
    {
        $nama = trim($nama);
        self::periksaNama($nama);
        self::periksaUsername($username);
        HakKlien::periksa($jenis, $hak);
        $ip       = AllowlistIp::normalisasi($ipAllowlist);
        $rahasia  = PembangkitKredensial::rahasia($this->rahasiaPanjang);
        $sekarang = gmdate('Y-m-d H:i:s');

        return $this->transaksi(function () use ($nama, $username, $jenis, $hak, $ip, $rahasia, $sekarang, $idPelaku): array {
            try {
                $ok = $this->db->table('klien_layanan')->insert([
                    'nama'              => $nama,
                    'username'          => $username,
                    'rahasia_hash'      => PembangkitKredensial::hashRahasia($rahasia),
                    'jenis'             => $jenis,
                    'hak'               => implode(',', $hak),
                    'ip_allowlist'      => $ip,
                    'status'            => self::STATUS_AKTIF,
                    'rahasia_diubah_at' => $sekarang,
                    'created_at'        => $sekarang,
                    'updated_at'        => $sekarang,
                    'created_by'        => $idPelaku,
                    'updated_by'        => $idPelaku,
                ]);

                if ($ok === false) {
                    // Koneksi tanpa DBDebug: insert mengembalikan false alih-alih melempar.
                    throw (int) ($this->db->error()['code'] ?? 0) === self::ER_DUP_ENTRY ? new InvalidArgumentException('Username klien sudah dipakai.') : new RuntimeException('Klien layanan gagal disimpan.');
                }
            } catch (DatabaseException $e) {
                if ((int) $e->getCode() === self::ER_DUP_ENTRY) {
                    throw new InvalidArgumentException('Username klien sudah dipakai.');
                }

                throw $e;
            }

            $id = (int) $this->db->insertID();
            $this->audit($id, AuditLogModel::EVENT_CREATE, null, ['username' => $username, 'jenis' => $jenis, 'hak' => $hak, 'ip_allowlist' => $ip, 'status' => self::STATUS_AKTIF], $idPelaku);

            return ['id' => $id, 'rahasia' => $rahasia];
        });
    }

    /**
     * Rahasia baru; token aktif klien dicabut (rahasia lama dianggap bocor). Klien status 10 tidak bisa dirotasi.
     *
     * @return array{id: int, rahasia: string, token_dicabut: int}
     */
    public function rotasi(string $username, ?int $idPelaku = null): array
    {
        $rahasia  = PembangkitKredensial::rahasia($this->rahasiaPanjang);
        $sekarang = gmdate('Y-m-d H:i:s');

        return $this->transaksi(function () use ($username, $rahasia, $sekarang, $idPelaku): array {
            $klien = $this->kunci($username);

            if ((int) $klien['status'] === self::STATUS_DIHAPUS) {
                throw new InvalidArgumentException('Klien sudah dihapus; buat klien baru.');
            }

            $id = (int) $klien['id_klien'];
            $this->db->table('klien_layanan')->where('id_klien', $id)->update([
                'rahasia_hash'      => PembangkitKredensial::hashRahasia($rahasia),
                'rahasia_diubah_at' => $sekarang,
                'updated_at'        => $sekarang,
                'updated_by'        => $idPelaku,
            ]);
            $dicabut = $this->cabutToken($id);
            $this->audit($id, AuditLogModel::EVENT_UPDATE, null, ['aksi' => 'rotasi_rahasia', 'token_dicabut' => $dicabut], $idPelaku);

            return ['id' => $id, 'rahasia' => $rahasia, 'token_dicabut' => $dicabut];
        });
    }

    /**
     * Ubah status klien: 1 aktif, 2 nonaktif, 10 dihapus (lunak). 2 dan 10 mencabut semua token aktifnya dalam transaksi yang
     * sama (DBV-026 §13.2 A1). Klien status 10 tidak dapat diaktifkan kembali.
     *
     * @return array{id: int, status_lama: int, token_dicabut: int}
     */
    public function ubahStatus(string $username, int $status, ?int $idPelaku = null): array
    {
        if (! in_array($status, [self::STATUS_AKTIF, self::STATUS_NONAKTIF, self::STATUS_DIHAPUS], true)) {
            throw new InvalidArgumentException('Status klien harus 1, 2, atau 10.');
        }

        $sekarang = gmdate('Y-m-d H:i:s');

        return $this->transaksi(function () use ($username, $status, $sekarang, $idPelaku): array {
            $klien = $this->kunci($username);
            $lama  = (int) $klien['status'];
            $id    = (int) $klien['id_klien'];

            if ($lama === self::STATUS_DIHAPUS) {
                throw new InvalidArgumentException('Klien sudah dihapus; buat klien baru.');
            }

            $this->db->table('klien_layanan')->where('id_klien', $id)->update([
                'status'     => $status,
                'deleted_at' => $status === self::STATUS_DIHAPUS ? $sekarang : null,
                'updated_at' => $sekarang,
                'updated_by' => $idPelaku,
            ]);
            $dicabut = $status === self::STATUS_AKTIF ? 0 : $this->cabutToken($id);
            $this->audit($id, AuditLogModel::EVENT_UPDATE, ['status' => $lama], ['status' => $status, 'token_dicabut' => $dicabut], $idPelaku);

            return ['id' => $id, 'status_lama' => $lama, 'token_dicabut' => $dicabut];
        });
    }

    /**
     * Daftar klien (tanpa rahasia/hash) dengan jumlah token aktif.
     *
     * @return list<array{id_klien: int, username: string, nama: string, jenis: int, hak: string, ip_allowlist: string|null, status: int, rahasia_diubah_at: string|null, token_aktif: int}>
     */
    public function daftar(): array
    {
        $klien = $this->db->table('klien_layanan')
            ->select('id_klien, username, nama, jenis, hak, ip_allowlist, status, rahasia_diubah_at')
            ->orderBy('id_klien')->get()->getResultArray();
        $aktif = [];

        $rows = $this->db->table('simapi_token')->select('id_klien, COUNT(*) AS jumlah', false)->where('status', 1)->where('expired_at > UTC_TIMESTAMP()', null, false)->groupBy('id_klien')->get()->getResultArray();

        foreach ($rows as $r) {
            $aktif[(int) $r['id_klien']] = (int) $r['jumlah'];
        }

        return array_map(static fn (array $r): array => [
            'id_klien'          => (int) $r['id_klien'],
            'username'          => (string) $r['username'],
            'nama'              => (string) $r['nama'],
            'jenis'             => (int) $r['jenis'],
            'hak'               => (string) $r['hak'],
            'ip_allowlist'      => $r['ip_allowlist'] === null ? null : (string) $r['ip_allowlist'],
            'status'            => (int) $r['status'],
            'rahasia_diubah_at' => $r['rahasia_diubah_at'] === null ? null : (string) $r['rahasia_diubah_at'],
            'token_aktif'       => $aktif[(int) $r['id_klien']] ?? 0,
        ], $klien);
    }

    /**
     * Hapus token yang sudah kedaluwarsa lebih dari `$retensiHari` hari (DBV-026 §13.2 A2). Dry-run hanya menghitung.
     *
     * @return array{batas: string, jumlah: int}
     */
    public function bersihkanToken(int $retensiHari, bool $dryRun): array
    {
        if ($retensiHari < 1) {
            throw new InvalidArgumentException('Retensi token minimal 1 hari.');
        }

        $batas  = gmdate('Y-m-d H:i:s', time() - $retensiHari * 86400);
        $jumlah = $this->db->table('simapi_token')->where('expired_at <', $batas)->countAllResults();

        if (! $dryRun && $jumlah > 0) {
            $this->db->table('simapi_token')->where('expired_at <', $batas)->delete();
        }

        return ['batas' => $batas, 'jumlah' => $jumlah];
    }

    // ------------------------------------------------------------------------------------------------------------

    /**
     * Cabut semua token berstatus 1 milik klien (termasuk yang sudah kedaluwarsa, supaya bersih); mengembalikan jumlah token
     * yang MASIH BERLAKU saat dicabut (konsisten dengan `daftar`).
     */
    private function cabutToken(int $idKlien): int
    {
        $berlaku = $this->db->table('simapi_token')->where('id_klien', $idKlien)->where('status', 1)->where('expired_at > UTC_TIMESTAMP()', null, false)->countAllResults();
        $this->db->table('simapi_token')->where('id_klien', $idKlien)->where('status', 1)->update(['status' => 2]);

        return $berlaku;
    }

    /**
     * @return array<string, mixed>
     */
    private function kunci(string $username): array
    {
        $sql   = $this->db->table('klien_layanan')->select('id_klien, status')->where('username', $username)->limit(1)->getCompiledSelect() . ' FOR UPDATE';
        $klien = $this->db->query($sql)->getRowArray();

        if ($klien === null) {
            throw new InvalidArgumentException('Klien tidak ditemukan.');
        }

        return $klien;
    }

    /**
     * @param array<string, mixed>|null $sebelum
     * @param array<string, mixed>      $sesudah
     */
    private function audit(int $idKlien, string $event, ?array $sebelum, array $sesudah, ?int $idPelaku): void
    {
        $model = $this->pabrikAudit !== null ? ($this->pabrikAudit)($this->db) : new AuditLogModel($this->db);

        if ($model->record(self::AUDIT_ENTITAS, $idKlien, $event, $sebelum, $sesudah, $idPelaku, null) <= 0) {
            throw new RuntimeException('Audit klien layanan gagal ditulis.');
        }
    }

    /**
     * Jalankan `$aksi` dalam satu transaksi (pola proyek: cek `=== false`, `transStatus`, commit/rollback, reset).
     *
     * @template T
     *
     * @param Closure(): T $aksi
     *
     * @return T
     */
    private function transaksi(Closure $aksi): mixed
    {
        if ($this->db->transBegin() === false) {
            $this->db->resetTransStatus();

            throw new RuntimeException('Transaksi klien layanan tidak dapat dimulai.');
        }

        try {
            $hasil = $aksi();

            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Transaksi klien layanan gagal.');
            }

            if ($this->db->transCommit() === false) {
                throw new RuntimeException('Transaksi klien layanan gagal di-commit.');
            }

            $this->db->resetTransStatus();

            return $hasil;
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        }
    }

    private static function periksaNama(string $nama): void
    {
        if ($nama === '' || mb_strlen($nama) > 150) {
            throw new InvalidArgumentException('Nama klien wajib diisi (maks 150 karakter).');
        }
    }

    private static function periksaUsername(string $username): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,99}$/', $username) !== 1) {
            throw new InvalidArgumentException('Username klien: 3-100 karakter huruf kecil, angka, titik, garis bawah, atau strip.');
        }
    }
}
