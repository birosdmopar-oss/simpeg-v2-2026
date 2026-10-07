<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Stub;

use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseBuilder;

/**
 * Stub fail-closed S0-A (MAKE-002) sampai implementasi nyata WS-2 (MAKE-009): menolak semua akses dan menyaring
 * daftar menjadi kosong.
 */
final class StubPegawaiScope implements PegawaiScopeInterface
{
    public function bolehLihat(AuthContext $auth, string $nip): bool
    {
        return false;
    }

    public function bolehUbah(AuthContext $auth, string $nip): bool
    {
        return false;
    }

    public function terapkanKeQuery(BaseBuilder $builder, AuthContext $auth, string $kolomNip = 'nip'): void
    {
        $builder->where('1 = 0', null, false);
    }
}
