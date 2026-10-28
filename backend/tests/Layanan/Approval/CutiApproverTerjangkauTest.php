<?php

declare(strict_types=1);

namespace Tests\Layanan\Approval;

use App\Libraries\Layanan\Cuti\ApproverTerjangkau;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-022 M3 (PRD Fase 4 v1.3 §6.4.10) — tabel kasus keterjangkauan approver: NIP kosong (eselon I), tanpa akun,
 * nonaktif, terhapus, role 4/5/8 tak terjangkau; role 1/2/3/6/7 aktif terjangkau.
 *
 * @internal
 */
final class CutiApproverTerjangkauTest extends DatabaseTestCase
{
    /**
     * @return iterable<string, array{?int, string, ?string, ?string}>
     */
    public static function kasus(): iterable
    {
        yield 'role 1 aktif' => [1, '1', null, null];
        yield 'role 2 aktif' => [2, '1', null, null];
        yield 'role 3 aktif' => [3, '1', null, null];
        yield 'role 6 aktif' => [6, '1', null, null];
        yield 'role 7 aktif' => [7, '1', null, null];
        yield 'role 4' => [4, '1', null, ApproverTerjangkau::ALASAN_ROLE];
        yield 'role 5' => [5, '1', null, ApproverTerjangkau::ALASAN_ROLE];
        yield 'role 8' => [8, '1', null, ApproverTerjangkau::ALASAN_ROLE];
        yield 'nonaktif' => [2, '0', null, ApproverTerjangkau::ALASAN_NONAKTIF];
        yield 'terhapus' => [2, '1', '2026-10-01 00:00:00', ApproverTerjangkau::ALASAN_TERHAPUS];
        yield 'tanpa akun' => [null, '1', null, ApproverTerjangkau::ALASAN_TANPA_AKUN];
    }

    #[DataProvider('kasus')]
    public function testKeterjangkauan(?int $role, string $status, ?string $deletedAt, ?string $alasan): void
    {
        $this->db->table('pegawai')->insert(['nip' => 'AP01', 'nama' => 'Approver', 'tgl_lahir' => '1980-01-01', 'jenis_kelamin' => 1, 'status' => 1]);

        if ($role !== null) {
            $this->db->table('pengguna')->insert([
                'nip'    => 'AP01', 'username' => 'ap01', 'name' => 'Approver', 'password' => 'x', 'user_level' => $role,
                'status' => $status, 'deleted_at' => $deletedAt, 'created_at' => '2026-01-01 00:00:00',
            ]);
        }

        $svc = new ApproverTerjangkau($this->db);

        $this->assertSame($alasan, $svc->alasanTakTerjangkau('AP01'));
        $this->assertSame($alasan === null, $svc->periksa('AP01'));
    }

    public function testNipKosongAtauNullTakTerjangkauEselonI(): void
    {
        $svc = new ApproverTerjangkau($this->db);

        foreach ([null, '', '  '] as $nip) {
            $this->assertFalse($svc->periksa($nip));
            $this->assertSame(ApproverTerjangkau::ALASAN_ESELON_I, $svc->alasanTakTerjangkau($nip));
        }
    }
}
