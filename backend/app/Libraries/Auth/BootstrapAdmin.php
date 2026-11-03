<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Akun admin awal (MAKE-069, keputusan Horii K-M2): role 1, tanpa NIP, password acak sekali tampil, Argon2id,
 * `must_change_password = 1`. Dipakai untuk lingkungan kosong (UP/QA) dan keadaan darurat; admin legacy hasil impor T8
 * tetap bisa login dengan password lamanya. Password tidak pernah ditulis ke log, berkas, atau `audit_logs`.
 */
final class BootstrapAdmin
{
    public const ROLE_ADMIN = 1;

    private const PANJANG = 24;
    private const BESAR   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const KECIL   = 'abcdefghijkmnopqrstuvwxyz';
    private const ANGKA   = '23456789';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return array{id: int, password: string} password teks asli — hanya untuk ditampilkan sekali oleh pemanggil
     */
    public function buat(string $username, string $nama, ?string $email, bool $tambahan): array
    {
        $username = trim($username);
        $nama     = trim($nama);

        if ($username === '' || mb_strlen($username) > 100 || preg_match('/\p{C}/u', $username) === 1) {
            throw new RuntimeException('Username wajib 1–100 karakter tanpa karakter kontrol/tak terlihat.');
        }

        if ($nama === '' || mb_strlen($nama) > 150) {
            throw new RuntimeException('Nama wajib 1–150 karakter.');
        }

        if ($email !== null && ($email === '' || mb_strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new RuntimeException('Email tidak valid.');
        }

        // Dengan DBDebug mati, countAllResults() yang gagal mengembalikan 0 — jangan dibaca sebagai "tidak ada" (review B-1).
        $aktif = $this->hitung($this->db->table('pengguna')->where('user_level', self::ROLE_ADMIN)->where('status', '1')->where('deleted_at', null));
        $sama  = $this->hitung($this->db->table('pengguna')->where('username', $username));

        if ($aktif > 0 && ! $tambahan) {
            throw new RuntimeException("Sudah ada {$aktif} akun admin role 1 aktif; pakai --tambahan bila memang perlu admin lain.");
        }

        if ($sama > 0) {
            throw new RuntimeException('Username sudah dipakai.');
        }

        $password = self::passwordAcak();

        if (PasswordPolicy::fromConfig()->errors($password) !== []) {
            throw new RuntimeException('Password acak tidak memenuhi kebijakan (periksa Config\\Auth).');
        }

        $baris = [
            'nip'                  => null,
            'username'             => $username,
            'name'                 => $nama,
            'email'                => $email,
            'password'             => password_hash($password, PASSWORD_ARGON2ID),
            'password_legacy'      => null,
            'user_level'           => self::ROLE_ADMIN,
            'status'               => '1',
            'must_change_password' => 1,
            'created_at'           => gmdate('Y-m-d H:i:s'),
            'updated_at'           => gmdate('Y-m-d H:i:s'),
        ];

        $this->db->transBegin();

        try {
            $ok = $this->db->table('pengguna')->insert($baris) !== false;
            $id = (int) $this->db->insertID();
            // Audit tanpa hash/password (ADR-012): hanya identitas & peran.
            if ($ok && $id > 0) {
                $ok = model(AuditLogModel::class)->record('pengguna', $id, 'create', null, [
                    'username' => $username, 'user_level' => self::ROLE_ADMIN, 'status' => '1', 'must_change_password' => 1, 'sumber' => 'akun:bootstrap-admin',
                ]) > 0;
            }

            $ok = $ok && $id > 0 && $this->db->transStatus();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw new RuntimeException('Akun admin gagal dibuat (transaksi di-rollback).', 0, $e);
        }

        if (! $ok || $this->db->transCommit() === false) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw new RuntimeException('Akun admin gagal dibuat (transaksi di-rollback).');
        }

        return ['id' => $id, 'password' => $password];
    }

    private function hitung(\CodeIgniter\Database\BaseBuilder $kueri): int
    {
        $n = $kueri->countAllResults();

        if (! is_int($n) || (int) ($this->db->error()['code'] ?? 0) !== 0) {
            throw new RuntimeException('Galat DB saat memeriksa akun yang ada.');
        }

        return $n;
    }

    /**
     * Password acak kriptografis: minimal satu huruf besar, huruf kecil, dan angka; karakter mirip (0/O, 1/l/I) dibuang.
     */
    public static function passwordAcak(): string
    {
        $semua = self::BESAR . self::KECIL . self::ANGKA;
        $c     = [self::ambil(self::BESAR), self::ambil(self::KECIL), self::ambil(self::ANGKA)];

        while (count($c) < self::PANJANG) {
            $c[] = self::ambil($semua);
        }

        for ($i = count($c) - 1; $i > 0; $i--) {
            $j               = random_int(0, $i);
            [$c[$i], $c[$j]] = [$c[$j], $c[$i]];
        }

        return implode('', $c);
    }

    private static function ambil(string $himpunan): string
    {
        return $himpunan[random_int(0, strlen($himpunan) - 1)];
    }
}
