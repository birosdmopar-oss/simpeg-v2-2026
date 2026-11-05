<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\KoneksiCli;
use App\Libraries\Cli\OpsiCli;
use App\Libraries\Integrasi\Klien\HakKlien;
use App\Libraries\Integrasi\Klien\PenyimpanKlien;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\KlienLayanan;
use InvalidArgumentException;
use ReflectionClass;
use Throwable;

/**
 * Kelola klien layanan antar-sistem (MAKE-058, ADR-036, PRD Fase 6 §7.0.1, F6-08):
 *
 *   php spark integrasi:klien buat --nama="…" --username=<u> --jenis=<1|2|3> --hak=<a,b> [--ip=<ip/cidr,…>]
 *   php spark integrasi:klien rotasi <username>
 *   php spark integrasi:klien nonaktif <username>
 *   php spark integrasi:klien aktifkan <username>
 *   php spark integrasi:klien hapus <username>
 *   php spark integrasi:klien daftar
 *   php spark integrasi:klien bersihkan-token [--hari=N] [--dry-run]
 *
 * Rahasia klien ditampilkan SEKALI saat `buat`/`rotasi` dan tidak pernah disimpan mentah atau dicatat; nonaktif/hapus/rotasi
 * mencabut semua token klien. Allowlist IP: IPv4/IPv6/CIDR dipisah koma, kosong ditolak, tanpa `--ip` = semua IP. Kode
 * keluaran ≠ 0 bila gagal.
 */
class IntegrasiKlien extends BaseCommand
{
    protected $group       = 'Integrasi';
    protected $name        = 'integrasi:klien';
    protected $description = 'Kelola klien layanan antar-sistem: buat, rotasi rahasia, nonaktif, aktifkan, hapus, daftar, bersihkan token kedaluwarsa.';
    protected $usage       = 'integrasi:klien <buat|rotasi|nonaktif|aktifkan|hapus|daftar|bersihkan-token> [username] [opsi]';
    protected $arguments   = [
        'aksi'     => 'buat | rotasi | nonaktif | aktifkan | hapus | daftar | bersihkan-token',
        'username' => 'Username klien (rotasi, nonaktif, aktifkan, hapus)',
    ];
    protected $options = [
        '--nama'     => 'buat: nama sistem pemanggil',
        '--username' => 'buat: username HTTP Basic (huruf kecil, angka, . _ -)',
        '--jenis'    => 'buat: 1 SIMAPI lama, 2 SIMAPI baru, 3 layanan',
        '--hak'      => 'buat: daftar hak dipisah koma (simapi, stat, dm, esign, sinkron)',
        '--ip'       => 'buat: allowlist IP/CIDR dipisah koma (bawaan: semua IP)',
        '--hari'     => 'bersihkan-token: retensi hari sejak kedaluwarsa (bawaan konfigurasi)',
        '--dry-run'  => 'bersihkan-token: hanya hitung',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['nama' => true, 'username' => true, 'jenis' => true, 'hak' => true, 'ip' => true, 'hari' => true, 'dry-run' => false]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $aksi  = $argumen[0] ?? '';
        $uname = $argumen[1] ?? null;

        if (! in_array($aksi, ['buat', 'rotasi', 'nonaktif', 'aktifkan', 'hapus', 'daftar', 'bersihkan-token'], true) || count($argumen) > (in_array($aksi, ['buat', 'daftar', 'bersihkan-token'], true) ? 1 : 2)) {
            CLI::error('Aksi tidak dikenal atau argumen berlebih. Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        if (in_array($aksi, ['rotasi', 'nonaktif', 'aktifkan', 'hapus'], true) && ($uname === null || $uname === '')) {
            CLI::error("Aksi {$aksi} wajib diberi username. Pemakaian: " . $this->usage);

            return EXIT_USER_INPUT;
        }

        $cfg = config(KlienLayanan::class);

        try {
            $penyimpan = new PenyimpanKlien(KoneksiCli::buka(), null, $cfg->rahasiaPanjang);

            return match ($aksi) {
                'buat'     => $this->buat($penyimpan, $opsi),
                'rotasi'   => $this->rotasi($penyimpan, (string) $uname),
                'nonaktif' => $this->status($penyimpan, (string) $uname, PenyimpanKlien::STATUS_NONAKTIF, 'dinonaktifkan'),
                'aktifkan' => $this->status($penyimpan, (string) $uname, PenyimpanKlien::STATUS_AKTIF, 'diaktifkan'),
                'hapus'    => $this->status($penyimpan, (string) $uname, PenyimpanKlien::STATUS_DIHAPUS, 'dihapus'),
                'daftar'   => $this->daftar($penyimpan),
                default    => $this->bersihkan($penyimpan, $opsi, $cfg->tokenRetensiHari),
            };
        } catch (InvalidArgumentException $e) {
            // Pesan dari validasi aplikasi: tanpa rahasia/NIP.
            CLI::error('Integrasi klien GAGAL: ' . $e->getMessage());

            return EXIT_USER_INPUT;
        } catch (Throwable $e) {
            log_message('error', 'integrasi:klien gagal: ' . (new ReflectionClass($e))->getShortName() . ' kode ' . $e->getCode() . '.');
            CLI::error('Integrasi klien GAGAL: ' . ($e instanceof DatabaseException ? 'galat basis data' : 'galat tak terduga') . ' (' . (new ReflectionClass($e))->getShortName() . ', kode ' . $e->getCode() . ').');

            return EXIT_ERROR;
        }
    }

    /**
     * @param array<string, string|true> $opsi
     */
    private function buat(PenyimpanKlien $penyimpan, array $opsi): int
    {
        foreach (['nama', 'username', 'jenis', 'hak'] as $wajib) {
            if (! isset($opsi[$wajib]) || ! is_string($opsi[$wajib]) || $opsi[$wajib] === '') {
                CLI::error("Opsi --{$wajib} wajib diisi untuk buat. Pemakaian: " . $this->usage);

                return EXIT_USER_INPUT;
            }
        }

        $jenisMentah = (string) $opsi['jenis'];

        if (! ctype_digit($jenisMentah)) {
            CLI::error('--jenis harus 1, 2, atau 3.');

            return EXIT_USER_INPUT;
        }

        $ip = $opsi['ip'] ?? null;
        $r  = $penyimpan->buat((string) $opsi['nama'], (string) $opsi['username'], (int) $jenisMentah, HakKlien::urai((string) $opsi['hak']), is_string($ip) ? $ip : null);

        CLI::write("Klien dibuat (id {$r['id']}, username {$opsi['username']}).", 'green');
        $this->tampilkanRahasia($r['rahasia']);

        return EXIT_SUCCESS;
    }

    private function rotasi(PenyimpanKlien $penyimpan, string $username): int
    {
        $r = $penyimpan->rotasi($username);

        CLI::write("Rahasia klien {$username} dirotasi; token aktif dicabut: {$r['token_dicabut']}.", 'green');
        $this->tampilkanRahasia($r['rahasia']);

        return EXIT_SUCCESS;
    }

    private function status(PenyimpanKlien $penyimpan, string $username, int $status, string $kata): int
    {
        $r = $penyimpan->ubahStatus($username, $status);

        CLI::write("Klien {$username} {$kata}; token aktif dicabut: {$r['token_dicabut']}.", 'green');

        return EXIT_SUCCESS;
    }

    private function daftar(PenyimpanKlien $penyimpan): int
    {
        $baris = array_map(static fn (array $k): array => [
            $k['id_klien'], $k['username'], $k['nama'], $k['jenis'], $k['hak'] === '' ? '-' : $k['hak'],
            $k['ip_allowlist'] === null ? 'semua IP' : count(explode(',', $k['ip_allowlist'])) . ' entri',
            [1 => 'aktif', 2 => 'nonaktif', 10 => 'dihapus'][$k['status']] ?? (string) $k['status'], $k['token_aktif'],
        ], $penyimpan->daftar());

        if ($baris === []) {
            CLI::write('Belum ada klien layanan.');

            return EXIT_SUCCESS;
        }

        CLI::table($baris, ['id', 'username', 'nama', 'jenis', 'hak', 'allowlist IP', 'status', 'token aktif']);

        return EXIT_SUCCESS;
    }

    /**
     * @param array<string, string|true> $opsi
     */
    private function bersihkan(PenyimpanKlien $penyimpan, array $opsi, int $bawaanHari): int
    {
        $hari = $opsi['hari'] ?? null;

        if ($hari !== null && (! is_string($hari) || ! ctype_digit($hari) || (int) $hari < 1)) {
            CLI::error('--hari harus angka minimal 1.');

            return EXIT_USER_INPUT;
        }

        $dryRun = isset($opsi['dry-run']);
        $r      = $penyimpan->bersihkanToken($hari === null ? $bawaanHari : (int) $hari, $dryRun);

        CLI::write(($dryRun ? '[dry-run] Akan dihapus: ' : 'Dihapus: ') . $r['jumlah'] . " token kedaluwarsa sebelum {$r['batas']} UTC.");

        return EXIT_SUCCESS;
    }

    private function tampilkanRahasia(string $rahasia): void
    {
        CLI::newLine();
        CLI::write('RAHASIA (ditampilkan SEKALI, simpan sekarang; tidak dapat ditampilkan lagi):', 'yellow');
        CLI::write($rahasia);
        CLI::newLine();
    }
}
