<?php

declare(strict_types=1);

namespace Tests\Support\Kepegawaian;

use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseBuilder;

/**
 * Fake PegawaiScope untuk test (S0-A MAKE-002) — TIDAK pernah dipakai kode produksi. Suntik lewat
 * `Services::injectMock('pegawaiScope', $fake)`.
 *
 * Default izinkan semua; izinkanHanya() membatasi ke daftar NIP (lihat dan/atau ubah). Pemanggilan dicatat di
 * $panggilan untuk assertion.
 */
final class FakePegawaiScope implements PegawaiScopeInterface
{
    /**
     * @var list<string>|null null = semua NIP
     */
    private ?array $nipLihat = null;

    /**
     * @var list<string>|null null = semua NIP
     */
    private ?array $nipUbah = null;

    /**
     * @var list<array{0: string, 1: string}> [method, nip]
     */
    public array $panggilan = [];

    public static function izinkanSemua(): self
    {
        return new self();
    }

    /**
     * @param list<string>      $lihat NIP yang boleh dilihat
     * @param list<string>|null $ubah  NIP yang boleh diubah; null = sama dengan $lihat
     */
    public static function izinkanHanya(array $lihat, ?array $ubah = null): self
    {
        $fake           = new self();
        $fake->nipLihat = $lihat;
        $fake->nipUbah  = $ubah ?? $lihat;

        return $fake;
    }

    public static function tolakSemua(): self
    {
        return self::izinkanHanya([], []);
    }

    public function bolehLihat(AuthContext $auth, string $nip): bool
    {
        $this->panggilan[] = ['bolehLihat', $nip];

        return $this->nipLihat === null || in_array($nip, $this->nipLihat, true);
    }

    public function bolehUbah(AuthContext $auth, string $nip): bool
    {
        $this->panggilan[] = ['bolehUbah', $nip];

        return $this->nipUbah === null || in_array($nip, $this->nipUbah, true);
    }

    public function terapkanKeQuery(BaseBuilder $builder, AuthContext $auth, string $kolomNip = 'nip'): void
    {
        if ($this->nipLihat === null) {
            return;
        }

        if ($this->nipLihat === []) {
            $builder->where('1 = 0', null, false);

            return;
        }

        $builder->whereIn($kolomNip, $this->nipLihat);
    }
}
