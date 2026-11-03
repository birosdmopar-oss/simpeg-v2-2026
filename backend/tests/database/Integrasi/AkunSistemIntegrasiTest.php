<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Commands\IntegrasiSiapkanAkunSistem;
use App\Constants\AkunSistem;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\AkunSistemMenyimpang;
use App\Libraries\Integrasi\AkunSistemTidakSiap;
use Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * DBV-027 Z-1 opsi (c) — akun sistem integrasi dibuat command `integrasi:siapkan-akun-sistem`, bukan seed migration:
 * bentuk akun (level 1, status '0', tanpa nip/email/password, stempel UTC), idempoten (2× = 1 baris), akun menyimpang
 * ditolak dengan nama kolom tanpa nilai dan tidak diperbaiki, `id()` gagal eksplisit bila akun belum ada/menyimpang (job
 * integrasi tidak boleh menulis pelaku NULL/0), dan exit code command.
 *
 * @internal
 */
final class AkunSistemIntegrasiTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    public function testDibuatSekaliDanIdempoten(): void
    {
        $layanan = new AkunSistemIntegrasi($this->db);

        $this->assertSame(AkunSistemIntegrasi::HASIL_DIBUAT, $layanan->siapkan());
        $this->assertSame(AkunSistemIntegrasi::HASIL_SUDAH_ADA, $layanan->siapkan());
        $this->assertSame(1, $this->db->table('pengguna')->where('username', AkunSistem::USERNAME)->countAllResults());

        $baris = $this->db->table('pengguna')->where('username', AkunSistem::USERNAME)->get()->getRowArray();
        $this->assertSame(
            ['Sistem Integrasi', null, null, null, null, '1', '0', '0'],
            [$baris['name'], $baris['nip'], $baris['email'], $baris['password'], $baris['password_legacy'], (string) $baris['user_level'], (string) $baris['status'], (string) $baris['must_change_password']],
        );
        $this->assertEqualsWithDelta(time(), strtotime($baris['created_at'] . ' UTC'), 30, 'stempel UTC');
        $this->assertSame((int) $baris['id_pengguna'], $layanan->id());
    }

    public function testAkunMenyimpangDitolakTanpaNilaiDanTidakDiperbaiki(): void
    {
        $layanan = new AkunSistemIntegrasi($this->db);
        $layanan->siapkan();
        $nip = $this->buatPegawai();

        foreach ([
            'status'          => ['status' => '1'],
            'password'        => ['password' => 'hash-rahasia-uji'],
            'password_legacy' => ['password_legacy' => 'legacy-rahasia-uji'],
            'nip'             => ['nip' => $nip],
            'user_level'      => ['user_level' => 3],
        ] as $kolom => $ubah) {
            $this->db->transBegin();
            $this->db->table('pengguna')->where('username', AkunSistem::USERNAME)->update($ubah);

            try {
                $layanan->siapkan();
                $this->fail("{$kolom}: akun menyimpang harus ditolak");
            } catch (AkunSistemMenyimpang $e) {
                $this->assertSame([$kolom], $e->kolom);
                $this->assertStringNotContainsString('rahasia', $e->getMessage());
                $this->assertStringNotContainsString($nip, $e->getMessage());
            }

            $sesudah = $this->db->table('pengguna')->where('username', AkunSistem::USERNAME)->get()->getRowArray();
            $this->assertSame((string) current($ubah), (string) $sesudah[$kolom], "{$kolom}: tidak diperbaiki otomatis");

            try {
                $layanan->id();
                $this->fail("{$kolom}: id() harus gagal untuk akun menyimpang");
            } catch (AkunSistemTidakSiap) {
                $this->addToAssertionCount(1);
            }

            $this->db->transRollback();
        }

        $this->assertSame(1, $this->db->table('pengguna')->where('username', AkunSistem::USERNAME)->countAllResults());
    }

    public function testIdTanpaAkunGagalEksplisit(): void
    {
        $this->expectException(AkunSistemTidakSiap::class);
        $this->expectExceptionMessage('integrasi:siapkan-akun-sistem');

        (new AkunSistemIntegrasi($this->db))->id();
    }

    public function testExitCodeCommand(): void
    {
        $command = new IntegrasiSiapkanAkunSistem(Services::logger(), Services::commands());

        $this->assertSame(EXIT_SUCCESS, $this->jalankan($command, []));
        $this->assertSame(EXIT_SUCCESS, $this->jalankan($command, []), 'diulang = no-op');
        $this->assertSame(EXIT_USER_INPUT, $this->jalankan($command, ['lebih']));

        $this->db->table('pengguna')->where('username', AkunSistem::USERNAME)->update(['status' => '1']);
        $this->assertSame(EXIT_ERROR, $this->jalankan($command, []), 'akun menyimpang → exit ≠ 0');
        $this->assertSame(1, $this->db->table('pengguna')->where('username', AkunSistem::USERNAME)->countAllResults());
    }

    /**
     * @param list<string> $params
     */
    private function jalankan(IntegrasiSiapkanAkunSistem $command, array $params): int
    {
        ob_start();

        try {
            return $command->run($params);
        } finally {
            ob_end_clean();
        }
    }
}
