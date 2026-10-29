<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Impor\DaftarTabel;
use App\Libraries\Impor\ImporGagal;
use App\Libraries\Impor\Keputusan;
use App\Libraries\Impor\PengimporLegacy;
use App\Libraries\Impor\PenjagaTujuan;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\ImporLegacy as KonfigImpor;
use Throwable;

/**
 * MAKE-056 — impor resmi salinan legacy → DB v2 kosong (UP-1 sekaligus gladi cutover; aturan di
 * `app/Libraries/Impor/README.md`):
 *
 *   php spark impor:legacy --sumber=<skema_salinan> --zona-sumber=WIB|UTC --konfirmasi=<db_tujuan>
 *                          [--tier=1|semua] [--dry-run] [--keputusan=<berkas.json>]
 *
 * Tujuan = koneksi `default` (`.env` DB scratch hasil `migrate --all`). Sumber = skema lain di server & akun yang sama.
 * Keluaran hanya nama tabel, jumlah, dan kode aturan. Kode keluar 0 = selesai, 1 = gagal (tier berjalan di-rollback),
 * 2 = masukan salah.
 */
class ImporLegacy extends BaseCommand
{
    protected $group       = 'Impor';
    protected $name        = 'impor:legacy';
    protected $description = 'Impor salinan legacy ke DB v2 kosong per tier (MAKE-056, UP-1/cutover).';
    protected $usage       = 'impor:legacy --sumber=<skema> --zona-sumber=WIB|UTC --konfirmasi=<db_tujuan> [--tier=1|semua] [--dry-run] [--keputusan=<berkas.json>]';
    protected $options     = [
        '--sumber'      => 'Skema salinan legacy di server yang sama dengan tujuan',
        '--zona-sumber' => 'Jam server legacy untuk kolom DATETIME: WIB atau UTC (wajib, tanpa bawaan; GAP-2)',
        '--konfirmasi'  => 'Ketik ulang nama database tujuan',
        '--tier'        => 'Nomor tier atau "semua" (bawaan semua)',
        '--dry-run'     => 'Hanya pra-cek, transformasi, dan hitung; tidak menulis',
        '--keputusan'   => 'Berkas JSON keputusan DBV (pemetaan status/nilai, tanggal nol)',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $opsi = static fn (string $k): ?string => $params[$k] ?? CLI::getOption($k);

        $sumber = $opsi('sumber');
        $zona   = $opsi('zona-sumber');
        $tier   = $opsi('tier') ?? 'semua';
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;

        if (! is_string($sumber) || $sumber === '' || ! is_string($zona)) {
            CLI::error('Pemakaian: ' . $this->usage);

            return 2;
        }

        $daftarTier = $tier === 'semua' ? range(1, DaftarTabel::TIER_MAKS) : [(int) $tier];

        try {
            $tujuan = Database::connect();
            PenjagaTujuan::periksa($tujuan->getDatabase(), $sumber, $opsi('konfirmasi'), config(KonfigImpor::class)->tujuanTerlarang);
            $keputusan = is_string($opsi('keputusan')) ? Keputusan::dariBerkas((string) $opsi('keputusan')) : Keputusan::kosong();

            $laporan = (new PengimporLegacy(self::koneksiSumber($sumber), $tujuan, $zona, $keputusan, config(KonfigImpor::class)->ukuranBatch))
                ->jalankan($daftarTier, $dryRun);
        } catch (ImporGagal $e) {
            CLI::error('GAGAL: ' . $e->getMessage());

            return EXIT_ERROR;
        } catch (Throwable $e) {
            CLI::error('GAGAL (' . $e::class . '): kode ' . $e->getCode() . '. Tier berjalan di-rollback.');

            return EXIT_ERROR;
        }

        foreach ($laporan->baris() as $b) {
            CLI::write($b);
        }

        CLI::write($dryRun ? 'Dry-run selesai, tidak ada yang ditulis.' : 'Impor selesai.', 'green');

        return EXIT_SUCCESS;
    }

    private static function koneksiSumber(string $skema): BaseConnection
    {
        $grup             = config(Database::class)->{config(Database::class)->defaultGroup};
        $grup['database'] = $skema;
        $grup['DBPrefix'] = '';

        return Database::connect($grup, false);
    }
}
