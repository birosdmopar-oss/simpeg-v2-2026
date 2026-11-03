<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Cli\OpsiCli;
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
use InvalidArgumentException;
use Throwable;

/**
 * MAKE-056 — impor resmi salinan legacy → DB v2 kosong (UP-1 sekaligus gladi cutover; aturan di
 * `app/Libraries/Impor/README.md`):
 *
 *   php spark impor:legacy --sumber=<skema_salinan> --zona-sumber=WIB|UTC --konfirmasi=<db_tujuan>
 *                          [--tier=1|semua] [--dry-run] [--keputusan=<berkas.json>] [--batch=1000]
 *                          [--sumber-siasn=<skema_salinan_siasn_simpeg>]
 *
 * Tujuan = koneksi `default` (`.env` DB scratch hasil `migrate --all`) WAJIB DB scratch di mesin lokal DBA, bukan server
 * dev/produksi; penjaga tidak bisa mengenali host, jadi host:port + nama DB tujuan dicetak lebih dulu untuk diperiksa
 * sebelum `--konfirmasi`. Sumber = skema salinan di server & akun yang sama. Kedua koneksi dibuat dengan `DBDebug = true`
 * apa pun environment-nya (galat query = exception, tidak diam-diam).
 * Opsi bernilai menerima `--k=v` maupun `--k v`; opsi tak dikenal, ganda, atau tanpa nilai ditolak (`OpsiCli`, CR-080).
 * `--sumber-siasn` = salinan skema `siasn_simpeg` (snapshot yang sama, server & akun yang sama) untuk `siasn_ref_unor` dan
 * `siasn_referensi` (DBV-027 K-9); tanpa opsi itu kedua tabel dilaporkan dilewati.
 * Keluaran hanya nama tabel, jumlah, dan kode aturan. Kode keluar 0 = selesai, 1 = gagal (tier berjalan di-rollback),
 * EXIT_USER_INPUT = masukan salah.
 */
class ImporLegacy extends BaseCommand
{
    protected $group       = 'Impor';
    protected $name        = 'impor:legacy';
    protected $description = 'Impor salinan legacy ke DB v2 kosong per tier (MAKE-056, UP-1/cutover).';
    protected $usage       = 'impor:legacy --sumber=<skema> --zona-sumber=WIB|UTC --konfirmasi=<db_tujuan> [--tier=1|semua] [--dry-run] [--keputusan=<berkas.json>] [--batch=1000] [--sumber-siasn=<skema>]';
    protected $options     = [
        '--sumber'       => 'Skema salinan legacy di server yang sama dengan tujuan',
        '--zona-sumber'  => 'Jam server legacy untuk kolom DATETIME: WIB atau UTC (wajib, tanpa bawaan; GAP-2)',
        '--konfirmasi'   => 'Ketik ulang nama database tujuan',
        '--tier'         => 'Nomor tier atau "semua" (bawaan semua)',
        '--dry-run'      => 'Hanya pra-cek, transformasi, dan hitung; tidak menulis',
        '--keputusan'    => 'Berkas JSON keputusan DBV (pemetaan status/nilai, tanggal nol)',
        '--batch'        => 'Baris per batch baca/tulis (bawaan Config\ImporLegacy::$ukuranBatch = 1000)',
        '--sumber-siasn' => 'Skema salinan siasn_simpeg (REF_UNOR + 7 referensi) di server yang sama; tanpa opsi → tabelnya dilewati',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        try {
            ['argumen' => $argumen, 'opsi' => $opsi] = OpsiCli::urai($params, [
                'sumber' => true, 'zona-sumber' => true, 'konfirmasi' => true, 'tier' => true, 'keputusan' => true,
                'batch'  => true, 'dry-run' => false, 'sumber-siasn' => true,
            ]);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage() . ' Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $sumber  = $opsi['sumber'] ?? null;
        $zona    = $opsi['zona-sumber'] ?? null;
        $tier    = $opsi['tier'] ?? 'semua';
        $dryRun  = isset($opsi['dry-run']);
        $siasn   = $opsi['sumber-siasn'] ?? null;
        $batch   = $opsi['batch'] ?? (string) config(KonfigImpor::class)->ukuranBatch;
        $tierSah = $tier === 'semua' || (is_string($tier) && ctype_digit($tier) && (int) $tier >= 1 && (int) $tier <= DaftarTabel::TIER_MAKS);

        if ($argumen !== [] || ! is_string($sumber) || ! is_string($zona) || ! is_string($batch) || ! ctype_digit($batch) || (int) $batch < 1 || ! $tierSah || ($siasn !== null && ! is_string($siasn))) {
            CLI::error('Pemakaian: ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $daftarTier = $tier === 'semua' ? range(1, DaftarTabel::TIER_MAKS) : [(int) $tier];

        try {
            $tujuan = self::koneksi(null);
            CLI::write(sprintf('Tujuan: %s:%s / %s — sumber: %s, sumber SIASN: %s (server & akun yang sama)', $tujuan->hostname, (string) $tujuan->port, $tujuan->getDatabase(), $sumber, is_string($siasn) ? $siasn : '-'), 'yellow');
            $konfirmasi = $opsi['konfirmasi'] ?? null;
            $berkas     = $opsi['keputusan'] ?? null;
            PenjagaTujuan::periksa($tujuan->getDatabase(), $sumber, is_string($konfirmasi) ? $konfirmasi : null, config(KonfigImpor::class)->tujuanTerlarang);

            if (is_string($siasn)) {
                PenjagaTujuan::periksa($tujuan->getDatabase(), $siasn, is_string($konfirmasi) ? $konfirmasi : null, config(KonfigImpor::class)->tujuanTerlarang);
            }

            $keputusan = is_string($berkas) ? Keputusan::dariBerkas($berkas) : Keputusan::kosong();

            $laporan = (new PengimporLegacy(self::koneksi($sumber), $tujuan, $zona, $keputusan, (int) $batch, is_string($siasn) ? self::koneksi($siasn) : null))
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
