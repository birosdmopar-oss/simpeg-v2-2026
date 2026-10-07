<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Interfaces\Kepegawaian\RiwayatRegistryInterface;
use App\Libraries\Auth\AuthContext;
use LogicException;
use ReflectionClass;

/**
 * Registry Definisi riwayat dengan auto-discovery (S0-A MAKE-002; pemilik WS-1).
 *
 * temukan() memindai satu folder: setiap `*.php` = satu kelas `<namespace>\<nama berkas>` turunan RiwayatDefinisi
 * (kelas abstrak dilewati, boleh dipakai sebagai basis bersama). Definisi yang salah bentuk — slug di luar
 * JenisRiwayat::SLUG, slug ganda, nama tabel/kolom tidak valid, aksi/role izin tidak dikenal — ditolak LogicException
 * saat registry dibangun (kesalahan kode, gagal keras, bukan diam-diam).
 */
final class RiwayatRegistry implements RiwayatRegistryInterface
{
    private const IDENTIFIER = '/^[a-z][a-z0-9_]*$/';

    /**
     * @var array<string, RiwayatDefinisi> slug => definisi, urut urutanTab() lalu slug
     */
    private array $definisi = [];

    /**
     * @param list<RiwayatDefinisi> $definisi
     */
    public function __construct(private readonly PegawaiScopeInterface $scope, array $definisi)
    {
        foreach ($definisi as $d) {
            self::periksa($d);

            if (isset($this->definisi[$d->jenis()])) {
                throw new LogicException("Definisi riwayat '{$d->jenis()}' terdaftar lebih dari sekali.");
            }

            $this->definisi[$d->jenis()] = $d;
        }

        uasort(
            $this->definisi,
            static fn (RiwayatDefinisi $a, RiwayatDefinisi $b): int => [$a->urutanTab(), $a->jenis()] <=> [$b->urutanTab(), $b->jenis()],
        );
    }

    public static function dariFolder(PegawaiScopeInterface $scope, string $folder, string $namespace): self
    {
        return new self($scope, self::temukan($folder, $namespace));
    }

    /**
     * Auto-discovery Definisi di $folder. Folder tidak ada atau kosong → [].
     *
     * @return list<RiwayatDefinisi>
     */
    public static function temukan(string $folder, string $namespace): array
    {
        if (! is_dir($folder)) {
            return [];
        }

        $files = glob(rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_STRING);

        $hasil = [];

        foreach ($files as $file) {
            $class = rtrim($namespace, '\\') . '\\' . basename($file, '.php');

            if (! class_exists($class)) {
                throw new LogicException("Berkas Definisi riwayat {$file} tidak memuat kelas {$class}.");
            }

            $reflection = new ReflectionClass($class);

            if (! $reflection->isSubclassOf(RiwayatDefinisi::class)) {
                throw new LogicException("Kelas {$class} di folder Definisi riwayat bukan turunan RiwayatDefinisi.");
            }

            if ($reflection->isAbstract()) {
                continue;
            }

            /** @var RiwayatDefinisi $definisi */
            $definisi = $reflection->newInstance();
            $hasil[]  = $definisi;
        }

        return $hasil;
    }

    public function semua(): array
    {
        return array_values($this->definisi);
    }

    public function definisi(string $jenis): ?RiwayatDefinisi
    {
        return $this->definisi[$jenis] ?? null;
    }

    public function descriptorUntuk(AuthContext $auth, string $nip): array
    {
        $role = $auth->role();
        // Lingkup ditanya sekali per permintaan, bukan per jenis.
        $lihat = null;
        $ubah  = null;
        $hasil = [];

        foreach ($this->definisi as $d) {
            if ($d->alur() === AlurRiwayat::Usulan || ! $d->boleh(AksiRiwayat::Lihat, $role)) {
                continue;
            }

            $lihat ??= $this->scope->bolehLihat($auth, $nip);

            if (! $lihat) {
                continue;
            }

            $descriptor = ['jenis' => $d->jenis(), 'label' => $d->label()];

            foreach (AksiRiwayat::cases() as $aksi) {
                $boleh = $d->boleh($aksi, $role);

                if ($boleh && $aksi->mengubah()) {
                    $ubah ??= $this->scope->bolehUbah($auth, $nip);
                    $boleh = $ubah;
                }

                $descriptor[$aksi->kunciDescriptor()] = $boleh;
            }

            /** @var array{jenis: string, label: string, can_view: bool, can_create: bool, can_edit: bool, can_delete: bool, can_process: bool} $descriptor */
            $hasil[] = $descriptor;
        }

        return $hasil;
    }

    private static function periksa(RiwayatDefinisi $d): void
    {
        $nama = $d::class;

        if (! JenisRiwayat::valid($d->jenis())) {
            throw new LogicException("Definisi {$nama}: slug '{$d->jenis()}' tidak ada di JenisRiwayat::SLUG.");
        }

        if (trim($d->label()) === '') {
            throw new LogicException("Definisi {$nama}: label wajib diisi.");
        }

        foreach ([$d->tabel(), $d->primaryKey(), $d->kolomNip(), $d->kolomStatus()] as $identifier) {
            if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
                throw new LogicException("Definisi {$nama}: nama tabel/kolom '{$identifier}' tidak valid.");
            }
        }

        foreach ($d->izin() as $aksi => $roles) {
            if (AksiRiwayat::tryFrom($aksi) === null) {
                throw new LogicException("Definisi {$nama}: aksi izin '{$aksi}' tidak dikenal.");
            }

            foreach ($roles as $role) {
                if (! Role::isValid($role)) {
                    throw new LogicException("Definisi {$nama}: role {$role} pada izin '{$aksi}' tidak dikenal.");
                }
            }
        }
    }
}
