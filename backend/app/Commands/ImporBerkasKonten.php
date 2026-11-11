<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\KoneksiCli;
use App\Libraries\Cli\OpsiCli;
use App\Libraries\Impor\BerkasKonten;
use App\Libraries\Impor\ImporGagal;
use App\Libraries\Impor\Keputusan;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Konten;
use InvalidArgumentException;
use Throwable;

/**
 * MAKE-089 M3 (Q-089-1 b) — salin berkas legacy Fase 7 (popup, banner, lampiran berita, DIBO, jawaban form digital) ke
 * penyimpanan privat v2 (`Config\Konten::akarPenyimpanan`) dengan kunci yang SAMA dengan hasil `impor:legacy --tier=14`:
 *
 *   php spark impor:berkas-konten --sumber=<skema_salinan> --dasar=<folder salinan aplikasi legacy> [--keputusan=<berkas.json>] [--dry-run]
 *
 * - `--sumber`: skema salinan legacy (DIBACA saja, tidak ada DB yang ditulis).
 * - `--dasar`: folder salinan aplikasi legacy yang memuat `assets/upload/…` (disediakan operator, mis. hasil salinan
 *   webroot atau `lampiran:tarik --sumber=aplikasi`). Path legacy di luar folder ini (traversal/symlink) ditolak.
 * - `--keputusan`: berkas keputusan yang sama dengan impor tier 14; baris `"__buang"` dilewati.
 * - Idempoten: kunci sudah ada dengan isi sama dilewati; isi berbeda TIDAK ditimpa (dilaporkan `beda`).
 * Layar hanya jumlah per jenis (tanpa nama berkas). Agen/coder tidak menjalankan ini ke server mana pun.
 */
class ImporBerkasKonten extends BaseCommand
{
    protected $group       = 'Impor';
    protected $name        = 'impor:berkas-konten';
    protected $description = 'MAKE-089: salin berkas legacy Fase 7 ke penyimpanan privat v2 dengan kunci tier 14 (baca-saja sumber).';
    protected $usage       = 'impor:berkas-konten --sumber=<skema> --dasar=<folder> [--keputusan=<berkas.json>] [--dry-run]';
    protected $options     = [
        '--sumber'    => 'Skema salinan legacy (dibaca saja)',
        '--dasar'     => 'Folder salinan aplikasi legacy yang memuat assets/upload/…',
        '--keputusan' => 'Berkas keputusan tier 14 (baris "__buang" dilewati)',
        '--dry-run'   => 'Hitung saja, tidak menyalin',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, ['sumber' => true, 'dasar' => true, 'keputusan' => true, 'dry-run' => false]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $sumber = $opsi['sumber'] ?? null;
        $dasar  = $opsi['dasar'] ?? null;
        $berkas = $opsi['keputusan'] ?? null;
        $dryRun = isset($opsi['dry-run']);

        if ($argumen !== [] || ! is_string($sumber) || $sumber === '' || ! is_string($dasar) || ! is_dir($dasar) || ($berkas !== null && ! is_string($berkas))) {
            CLI::error('Pemakaian: ' . $this->usage . ' (--dasar wajib folder yang ada)');

            return EXIT_USER_INPUT;
        }

        try {
            $db        = KoneksiCli::baru($sumber);
            $keputusan = is_string($berkas) ? Keputusan::dariBerkas($berkas) : Keputusan::kosong();
            CLI::write(sprintf('Sumber: %s:%s / %s (dibaca saja) — tujuan berkas: penyimpanan konten v2', $db->hostname, (string) $db->port, $db->getDatabase()), 'yellow');

            $laporan = (new BerkasKonten($db, new LocalStorageAdapter($dasar), new LocalStorageAdapter(config(Konten::class)->akarPenyimpanan), $keputusan))->jalankan($dryRun);
        } catch (ImporGagal $e) {
            CLI::error('GAGAL: ' . $e->getMessage());

            return EXIT_ERROR;
        } catch (Throwable $e) {
            CLI::error('GAGAL (' . $e::class . '): kode ' . $e->getCode() . '.');

            return EXIT_ERROR;
        }

        foreach ($laporan as $jenis => $hitung) {
            CLI::write($jenis . ': ' . implode(', ', array_map(static fn (string $k, int $n): string => "{$k} {$n}", array_keys($hitung), $hitung)));
        }

        CLI::write($dryRun ? 'Dry-run selesai, tidak ada berkas yang disalin.' : 'Penyalinan selesai.', 'green');

        return EXIT_SUCCESS;
    }
}
