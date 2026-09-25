<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Models\Auth\PenggunaModel;

/**
 * Lifecycle akun otomatis (A-09): saat pegawai baru dibuat di Modul B (B-05), akun `pengguna`
 * di-generate dengan username = NIP. Idempotent: kalau akun untuk NIP sudah ada, dikembalikan apa adanya.
 *
 * Password awal: dokumen sumber tidak menyebut kebijakan password default legacy, jadi provisioner
 * membuat password acak yang dikembalikan SEKALI ke pemanggil (Modul B menentukan cara penyampaian,
 * atau pegawai memakai alur lupa password A-07). Keputusan final perlu konfirmasi Tech Lead.
 * Regression test lintas modul dijalankan ulang di akhir Fase 3 (lihat B-05).
 */
class AccountProvisioner
{
    public function __construct(
        private PenggunaModel $pengguna,
        private PasswordVerifier $passwords,
    ) {
    }

    /**
     * @param array<string, mixed> $pegawai minimal ['nip' => ..., 'id_unit' => ?, 'id_satker' => ?]
     *
     * @return array{pengguna: array<string, mixed>, created: bool, initial_password: string|null}
     */
    public function provisionForPegawai(array $pegawai, int $userLevel = Role::PEGAWAI, ?string $initialPassword = null): array
    {
        $nip = trim((string) ($pegawai['nip'] ?? ''));

        if (preg_match('/^\d{18}$/', $nip) !== 1) {
            throw ValidationException::forField('nip', 'NIP harus 18 digit angka.');
        }

        if (! Role::isValid($userLevel)) {
            throw ValidationException::forField('user_level', 'Role tidak valid (1-8).');
        }

        // Password awal dari pemanggil tunduk pada kebijakan yang sama dengan admin set password (K4).
        $policyErrors = $initialPassword === null ? [] : $this->passwords->policyErrors($initialPassword);

        if ($policyErrors !== []) {
            throw new ValidationException('Validasi gagal.', ['password' => $policyErrors]);
        }

        $existing = $this->pengguna->withDeleted()->where('nip', $nip)->first();

        if (is_array($existing)) {
            return ['pengguna' => PenggunaModel::toPublic($existing), 'created' => false, 'initial_password' => null];
        }

        $plain = $initialPassword ?? self::generatePassword();

        $id = $this->pengguna->insert([
            'nip'                 => $nip,
            'username'            => $nip,
            'password'            => $this->passwords->hash($plain),
            'password_legacy'     => null,
            'user_level'          => $userLevel,
            'id_unit'             => $pegawai['id_unit'] ?? null,
            'id_satker'           => $pegawai['id_satker'] ?? null,
            'status'              => PenggunaModel::STATUS_ACTIVE,
            'password_changed_at' => null,
        ]);

        /** @var array<string, mixed> $row */
        $row = $this->pengguna->find((int) $id);

        return ['pengguna' => PenggunaModel::toPublic($row), 'created' => true, 'initial_password' => $plain];
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
