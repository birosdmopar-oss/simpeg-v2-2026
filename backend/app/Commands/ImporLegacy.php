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
 *                          [--tier=1|semua] [--dry-run] [--keputusan=<berkas.json>] [--batch=1000]
 *
 * Tujuan = koneksi `default` (`.env` DB scratch hasil `migrate --all`) WAJIB DB scratch di mesin lokal DBA, bukan server
 * dev/produksi; penjaga tidak bisa mengenali host, jadi host:port + nama DB tujuan dicetak lebih dulu untuk diperiksa
 * sebelum `--konfirmasi`. Sumber = skema salinan di server & akun yang sama. Kedua koneksi dibuat dengan `DBDebug = true`
 * apa pun environment-nya (galat query = exception, tidak diam-diam).
 * Keluaran hanya nama tabel, jumlah, dan kode aturan. Kode keluar 0 = selesai, 1 = gagal (tier berjalan di-rollback),
 * 2 = masukan salah.
 */
class ImporLegacy extends BaseCommand
{
    protected $group       = 'Impor';
    protected $name        = 'impor:legacy';
    protected $description = 'Impor salinan legacy ke DB v2 kosong per tier (MAKE-056, UP-1/cutover).';
    protected $usage       = 'impor:legacy --sumber=<skema> --zona-sumber=WIB|UTC --konfirmasi=<db_tujuan> [--tier=1|semua] [--dry-run] [--keputusan=<berkas.json>] [--batch=1000]';
    protected $options     = [
        '--sumber'      => 'Skema salinan legacy di server yang sama dengan tujuan',
        '--zona-sumber' => 'Jam server legacy untuk kolom DATETIME: WIB atau UTC (wajib, tanpa bawaan; GAP-2)',
        '--konfirmasi'  => 'Ketik ulang nama database tujuan',
        '--tier'        => 'Nomor tier atau "semua" (bawaan semua)',
        '--dry-run'     => 'Hanya pra-cek, transformasi, dan hitung; tidak menulis',
        '--keputusan'   => 'Berkas JSON keputusan DBV (pemetaan status/nilai, tanggal nol)',
        '--batch'       => 'Baris per batch baca/tulis (bawaan Config\ImporLegacy::$ukuranBatch = 1000)',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $opsi = static fn (string $k): ?string => self::opsi($params, $k);

        $sumber = $opsi('sumber');
        $zona   = $opsi('zona-sumber');
        $tier   = $opsi('tier') ?? 'semua';
        $dryRun = array_key_exists('dry-run', $params) || array_key_exists('dry-run', CLI::getOptions());
        $batch  = $opsi('batch') ?? (string) config(KonfigImpor::class)->ukuranBatch;

        if (! is_string($sumber) || $sumber === '' || ! is_string($zona) || ! ctype_digit($batch) || (int) $batch < 1) {
            CLI::error('Pemakaian: ' . $this->usage);

            return 2;
        }

        $daftarTier = $tier === 'semua' ? range(1, DaftarTabel::TIER_MAKS) : [(int) $tier];

        try {
            $tujuan = self::koneksi(null);
            CLI::write(sprintf('Tujuan: %s:%s / %s — sumber: %s (server & akun yang sama)', $tujuan->hostname, (string) $tujuan->port, $tujuan->getDatabase(), $sumber), 'yellow');
            PenjagaTujuan::periksa($tujuan->getDatabase(), $sumber, $opsi('konfirmasi'), config(KonfigImpor::class)->tujuanTerlarang);
            $keputusan = is_string($opsi('keputusan')) ? Keputusan::dariBerkas((string) $opsi('keputusan')) : Keputusan::kosong();

            $laporan = (new PengimporLegacy(self::koneksi($sumber), $tujuan, $zona, $keputusan, (int) $batch))
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

    /**
     * Nilai opsi dalam bentuk `--k nilai` maupun `--k=nilai`. Parser CLI CI4 tidak memecah `=`, sehingga
     * `--sumber=x` tiba sebagai opsi bernama `sumber=x` tanpa nilai.
     *
     * @param array<int|string, string|null> $params
     */
    private static function opsi(array $params, string $k): ?string
    {
        $nilai = $params[$k] ?? CLI::getOption($k);

        if (is_string($nilai)) {
            return $nilai;
        }

        foreach ([...array_keys($params), ...array_keys(CLI::getOptions())] as $nama) {
            if (is_string($nama) && str_starts_with($nama, $k . '=')) {
                return substr($nama, strlen($k) + 1);
            }
        }

        return null;
    }

    /**
     * Koneksi dari grup bawaan dengan `DBDebug = true` (R-1 review PR #32). `$skema` null = tujuan (grup apa adanya);
     * selain itu sumber: skema lain tanpa prefix.
     */
    private static function koneksi(?string $skema): BaseConnection
    {
        $grup            = config(Database::class)->{config(Database::class)->defaultGroup};
        $grup['DBDebug'] = true;

        if ($skema !== null) {
            $grup['database'] = $skema;
            $grup['DBPrefix'] = '';
        }

        return Database::connect($grup, false);
    }
}
