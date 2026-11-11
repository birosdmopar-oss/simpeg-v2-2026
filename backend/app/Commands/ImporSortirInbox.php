<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\KoneksiCli;
use App\Libraries\Cli\OpsiCli;
use App\Libraries\Impor\AturanTiketLama;
use App\Libraries\Impor\ImporGagal;
use App\Libraries\Impor\Keputusan;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use InvalidArgumentException;
use Throwable;

/**
 * F-02 MAKE-086 — sortir inbox legacy TANPA menulis ke DB mana pun (dry-run cutover, PRD Fase 7 v2.0 §5.2):
 *
 *   php spark impor:sortir-inbox --sumber=<skema_salinan> --keluaran=<berkas.json> [--keputusan=<berkas.json>]
 *
 * Membaca `inbox`/`inbox_msg` salinan legacy, mengklasifikasi per baris ({@see AturanTiketLama}), lalu menulis manifest
 * JSON: jumlah per kelas (T-OK / A-ESK …, M-OK / M-ESK / M-IKUT …) dan daftar eskalasi `id → kode` untuk baris ambigu
 * yang belum diputuskan. Berkas hanya berisi id dan kode (tanpa subjek/pesan/NIP/nama); simpan di folder kerja di luar
 * repo. Daftar eskalasi diserahkan ke reviewer → Horii/DBV; putusannya diisi di berkas keputusan bagian `baris`
 * (`disiplin_ticket.f02` / `disiplin_ticket_msg.f02`), lalu `impor:legacy --tier=13` dijalankan saat cutover.
 * Layar hanya menampilkan jumlah. Kode keluar 0 = manifest ditulis, 1 = gagal, EXIT_USER_INPUT = masukan salah.
 */
class ImporSortirInbox extends BaseCommand
{
    protected $group       = 'Impor';
    protected $name        = 'impor:sortir-inbox';
    protected $description = 'F-02: klasifikasi inbox legacy (tiket Halo Simpeg) + daftar eskalasi, tanpa menulis DB (MAKE-086).';
    protected $usage       = 'impor:sortir-inbox --sumber=<skema> --keluaran=<berkas.json> [--keputusan=<berkas.json>]';
    protected $options     = [
        '--sumber'    => 'Skema salinan legacy (dibaca saja)',
        '--keluaran'  => 'Berkas manifest JSON (folder kerja di luar repo); ditimpa bila ada',
        '--keputusan' => 'Berkas keputusan DBV/Horii (bagian baris disiplin_ticket.f02 / disiplin_ticket_msg.f02)',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['sumber' => true, 'keluaran' => true, 'keputusan' => true]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $sumber   = $opsi['sumber'] ?? null;
        $keluaran = $opsi['keluaran'] ?? null;
        $berkas   = $opsi['keputusan'] ?? null;

        if ($argumen !== [] || ! is_string($sumber) || $sumber === '' || ! is_string($keluaran) || $keluaran === '' || ($berkas !== null && ! is_string($berkas))) {
            CLI::error('Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        try {
            $db        = KoneksiCli::baru($sumber);
            $keputusan = is_string($berkas) ? Keputusan::dariBerkas($berkas) : Keputusan::kosong();
            CLI::write(sprintf('Sumber: %s:%s / %s (dibaca saja)', $db->hostname, (string) $db->port, $db->getDatabase()), 'yellow');

            $manifest = (new AturanTiketLama())->manifest($db, $this->tabel($db, 'inbox'), $this->tabel($db, 'inbox_msg'), $keputusan);
            $isi      = json_encode(['dibuat_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'sumber' => $db->getDatabase()] + $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if (@file_put_contents($keluaran, $isi . "\n") === false) {
                throw new ImporGagal('Berkas keluaran tidak bisa ditulis.');
            }
        } catch (ImporGagal $e) {
            CLI::error('GAGAL: ' . $e->getMessage());

            return EXIT_ERROR;
        } catch (Throwable $e) {
            CLI::error('GAGAL (' . $e::class . '): kode ' . $e->getCode() . '.');

            return EXIT_ERROR;
        }

        foreach (['tiket' => 'inbox → disiplin_ticket', 'pesan' => 'inbox_msg → disiplin_ticket_msg'] as $k => $label) {
            CLI::write("{$label}: total {$manifest[$k]['total']}");

            foreach ($manifest[$k]['kelas'] as $kelas => $n) {
                CLI::write("  {$kelas}: {$n}");
            }
        }

        foreach ($manifest['di_luar_domain'] as $kolom => $nilai) {
            CLI::write("Di luar domain {$kolom}: " . implode(', ', array_map(static fn (string $v, int $n): string => "{$v}={$n}", array_map('strval', array_keys($nilai)), $nilai)) . ' (petakan di bagian status berkas keputusan)');
        }

        $tak = $manifest['keputusan_tak_terpakai'];

        if ($tak['tiket'] + $tak['pesan'] > 0) {
            CLI::write("Keputusan tak terpakai: tiket {$tak['tiket']}, pesan {$tak['pesan']} (id tidak ambigu atau tidak ada di snapshot; periksa berkas keputusan).", 'yellow');
        }

        $esk = count($manifest['eskalasi']['tiket']) + count($manifest['eskalasi']['pesan']);
        CLI::write($esk === 0 ? 'Tidak ada baris ambigu yang belum diputuskan.' : "Eskalasi: {$esk} baris ambigu belum diputuskan (id + kode di berkas keluaran; jangan ditebak).", $esk === 0 ? 'green' : 'yellow');
        CLI::write('Manifest ditulis. Berkas berisi id dan kode saja; simpan di luar repo.', 'green');

        return EXIT_SUCCESS;
    }

    private function tabel(BaseConnection $db, string $nama): string
    {
        $hasil = $db->query('SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND LOWER(TABLE_NAME) = LOWER(?)', [$db->getDatabase(), $nama]);
        $rows  = $hasil instanceof ResultInterface ? $hasil->getResultArray() : [];

        if (count($rows) !== 1) {
            throw new ImporGagal("Tabel sumber {$nama} tidak ada (atau ganda beda huruf besar/kecil).");
        }

        return (string) $rows[0]['n'];
    }
}
