<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Lifecycle akun otomatis (A-09): saat pegawai baru dibuat di Modul B (B-05), akun `pengguna`
 * di-generate dengan username = NIP. Idempotent: kalau akun untuk NIP sudah ada, dikembalikan apa adanya.
 *
 * Password awal: dokumen sumber tidak menyebut kebijakan password default legacy, jadi provisioner
 * membuat password acak yang dikembalikan SEKALI ke pemanggil (Modul B menentukan cara penyampaian,
 * atau pegawai memakai alur lupa password A-07). Keputusan final perlu konfirmasi Tech Lead.
 * Regression test lintas modul dijalankan ulang di akhir Fase 3 (lihat B-05).
 *
 * NIP ikut legacy (DBV-010): angka saja, maksimal 18 digit — NIK 16 digit pegawai Non-PNS diterima. Nama dan email akun
 * diisi dari data pegawai bila tersedia (opsional; akun ber-NIP tidak wajib bernama).
 *
 * NIP sudah dipakai sebagai username akun LAIN (username bebas akun non-pegawai, termasuk akun terhapus) → 422
 * errors.nip berpesan jelas, akun tidak dibuat dan tidak ditautkan ke akun itu (ISSUE-023/CR-019; sebelumnya 1062 →
 * 500). Balapan dua proses untuk NIP yang sama tetap idempoten (yang kalah menerima akun yang sudah ada, created=false).
 *
 * NIP wajib sudah ada di `pegawai.nip` (MAKE-015, prasyarat FK masuk DBV-019): pemanggil (B-05) menyisipkan baris
 * `pegawai` lebih dulu di transaksi yang sama. NIP asing → 422 errors.nip PenggunaModel::NIP_TIDAK_TERDAFTAR sebelum
 * insert; 1452 (FK aktif, baris pegawai hilang di antara cek dan tulis) diterjemahkan ke 422 yang sama, bukan 500.
 *
 * Email (syarat DBV-010 D-6, CR-019): email pegawai yang sudah dipakai akun lain yang belum dihapus (dibandingkan
 * collation unicode_ci) TIDAK ikut disimpan — akun tetap dibuat tanpa email dan hasil memuat `email_skipped: true`
 * agar pemanggil (B-05) memberi tahu admin. Pegawai tidak digagalkan karena email: satu orang bisa sudah punya akun
 * non-pegawai dengan email yang sama. Perilaku final dikonfirmasi saat B-05.
 */
class AccountProvisioner
{
    public function __construct(
        private PenggunaModel $pengguna,
        private PasswordVerifier $passwords,
    ) {
    }

    /**
     * @param array<string, mixed> $pegawai minimal ['nip' => ..., 'id_unit' => ?, 'id_satker' => ?, 'name' => ?, 'email' => ?]
     *
     * @return array{pengguna: array<string, mixed>, created: bool, initial_password: string|null, email_skipped: bool}
     */
    public function provisionForPegawai(array $pegawai, int $userLevel = Role::PEGAWAI, ?string $initialPassword = null): array
    {
        return $this->provision($pegawai, $userLevel, $initialPassword, false);
    }

    /**
     * Jalur ADITIF B-05 Tambah Pegawai (MAKE-011): password awal = NIP [K] ikut legacy `libraries/hr/L_employee.php:157-166`
     * (keputusan user 13-10-2026). Sengaja MELEWATI PasswordPolicy (K4 menolak NIP) — hanya untuk akun pegawai baru yang
     * dibuat Super Admin; pemilik akun wajib segera mengganti password (penanda "wajib ganti password" menunggu DBV-020).
     * Hash tetap v2 (Argon2id lewat PasswordVerifier), bukan md5; `password_decode` tidak ada. Validasi lain (format NIP,
     * NIP terdaftar di `pegawai` MAKE-015, username dipakai akun lain, email D-6, idempoten) sama dengan
     * provisionForPegawai(). Jalur provisioning lain tetap password acak yang lolos kebijakan.
     *
     * @param array<string, mixed> $pegawai sama dengan provisionForPegawai()
     *
     * @return array{pengguna: array<string, mixed>, created: bool, initial_password: string|null, email_skipped: bool}
     */
    public function provisionDenganPasswordNip(array $pegawai, int $userLevel = Role::PEGAWAI): array
    {
        return $this->provision($pegawai, $userLevel, trim((string) ($pegawai['nip'] ?? '')), true);
    }

    /**
     * @param array<string, mixed> $pegawai
     *
     * @return array{pengguna: array<string, mixed>, created: bool, initial_password: string|null, email_skipped: bool}
     */
    private function provision(array $pegawai, int $userLevel, ?string $initialPassword, bool $lewatiKebijakan): array
    {
        $nip = trim((string) ($pegawai['nip'] ?? ''));

        if (preg_match('/^\d{1,' . PenggunaModel::NIP_MAX_DIGITS . '}$/', $nip) !== 1) {
            throw ValidationException::forField('nip', 'NIP harus berupa angka, maksimal ' . PenggunaModel::NIP_MAX_DIGITS . ' digit.');
        }

        if (! Role::isValid($userLevel)) {
            throw ValidationException::forField('user_level', 'Role tidak valid (1-8).');
        }

        $name  = trim((string) ($pegawai['name'] ?? ''));
        $email = trim((string) ($pegawai['email'] ?? ''));

        if (mb_strlen($name) > PenggunaModel::NAME_MAX) {
            throw ValidationException::forField('name', 'Nama maksimal ' . PenggunaModel::NAME_MAX . ' karakter.');
        }

        if ($email !== '' && (mb_strlen($email) > PenggunaModel::EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw ValidationException::forField('email', 'Email tidak valid (maks. ' . PenggunaModel::EMAIL_MAX . ' karakter).');
        }

        // Password awal dari pemanggil tunduk pada kebijakan yang sama dengan admin set password (K4).
        $policyErrors = $initialPassword === null || $lewatiKebijakan ? [] : $this->passwords->policyErrors($initialPassword);

        if ($policyErrors !== []) {
            throw new ValidationException('Validasi gagal.', ['password' => $policyErrors]);
        }

        $existing = $this->pengguna->withDeleted()->where('nip', $nip)->first();

        if (is_array($existing)) {
            return ['pengguna' => PenggunaModel::toPublic($existing), 'created' => false, 'initial_password' => null, 'email_skipped' => false];
        }

        // ISSUE-023: username = NIP sudah dipegang akun lain (username bebas akun non-pegawai, termasuk akun terhapus —
        // UNIQUE username mencakup baris soft-deleted). Gagal dengan pesan jelas; akun tidak dibuat/ditautkan diam-diam.
        if ($this->pengguna->withDeleted()->where('username', $nip)->countAllResults() > 0) {
            throw self::usernameTaken();
        }

        if (! $this->pengguna->nipTerdaftarPegawai($nip)) {
            throw self::nipTidakTerdaftar();
        }

        // D-6: jangan menulis email duplikat; akun dibuat tanpa email dan pemanggil diberi penanda.
        $emailSkipped = $email !== '' && $this->pengguna->emailTaken($email);

        if ($emailSkipped) {
            $email = '';
        }

        $plain = $initialPassword ?? self::generatePassword();

        try {
            $id = $this->pengguna->insert([
                'nip'                 => $nip,
                'username'            => $nip,
                'name'                => $name === '' ? null : $name,
                'email'               => $email === '' ? null : $email,
                'password'            => $this->passwords->hash($plain),
                'password_legacy'     => null,
                'user_level'          => $userLevel,
                'id_unit'             => $pegawai['id_unit'] ?? null,
                'id_satker'           => $pegawai['id_satker'] ?? null,
                'status'              => PenggunaModel::STATUS_ACTIVE,
                'password_changed_at' => null,
                // MAKE-020 [V2]: password awal diatur sistem (NIP / acak / dari admin) → wajib ganti saat login pertama.
                'must_change_password' => 1,
            ] + PasswordExpiry::forNewAccount(PenggunaModel::STATUS_ACTIVE, time()));
        } catch (DatabaseException $e) {
            if ($e->getCode() === PenggunaModel::ER_FK_TANPA_INDUK && ! $this->pengguna->nipTerdaftarPegawai($nip)) {
                throw self::nipTidakTerdaftar();
            }

            if ($e->getCode() !== 1062) {
                throw $e;
            }

            // Balapan lolos cek di atas → cek ulang lewat query builder baru (bukan state model).
            $db = db_connect();
            /** @var array<string, mixed>|null $raced */
            $raced = $db->table('pengguna')->where('nip', $nip)->get()->getRowArray();

            if (is_array($raced)) {
                // Proses lain lebih dulu membuat akun untuk NIP yang sama: tetap idempoten.
                return ['pengguna' => PenggunaModel::toPublic($raced), 'created' => false, 'initial_password' => null, 'email_skipped' => false];
            }

            if ($db->table('pengguna')->where('username', $nip)->countAllResults() > 0) {
                throw self::usernameTaken();
            }

            throw $e;
        }

        /** @var array<string, mixed> $row */
        $row = $this->pengguna->find((int) $id);

        return ['pengguna' => PenggunaModel::toPublic($row), 'created' => true, 'initial_password' => $plain, 'email_skipped' => $emailSkipped];
    }

    /**
     * 422 errors.nip. Pemanggil (B-05) membatalkan pembuatan pegawai; admin mengganti username akun pemegang NIP itu
     * lebih dulu (akun yang sudah dihapus perlu ditangani admin basis data karena belum ada fitur pulihkan akun).
     */
    private static function usernameTaken(): ValidationException
    {
        return ValidationException::forField(
            'nip',
            'NIP ini sudah dipakai sebagai username akun lain; ganti username akun tersebut sebelum membuat akun pegawai.',
        );
    }

    private static function nipTidakTerdaftar(): ValidationException
    {
        return ValidationException::forField('nip', PenggunaModel::NIP_TIDAK_TERDAFTAR);
    }

    /**
     * Password acak (default 12 karakter) yang selalu memenuhi PasswordPolicy (K4): minimal 1 huruf besar, 1 huruf
     * kecil, dan 1 angka, lalu posisinya diacak. Karakter yang mirip (I/l/1, O/o/0) tidak dipakai.
     */
    public static function generatePassword(int $length = 12): string
    {
        $classes  = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghjkmnpqrstuvwxyz', '23456789'];
        $alphabet = implode('', $classes);
        $chars    = [];

        foreach ($classes as $class) {
            $chars[] = $class[random_int(0, strlen($class) - 1)];
        }

        while (count($chars) < $length) {
            $chars[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        // Fisher-Yates dengan random_int: karakter wajib tidak selalu di posisi yang sama.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j                       = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
