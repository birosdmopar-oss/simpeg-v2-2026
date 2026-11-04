<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use App\Interfaces\Integrasi\IntegrasiLogInterface;
use App\Interfaces\SiasnGatewayInterface;
use App\Libraries\Cli\KoneksiCli;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\Log\CatatanIntegrasi;
use App\Libraries\Siasn\SiasnAdapter;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Integrations;
use InvalidArgumentException;
use Throwable;

/**
 * Orkestrasi `siasn:tarik --jenis=…` (H-03, F6-14 ikut legacy penuh; PRD Fase 6 §7.3, MAKE-067 M1), padanan
 * `SS/*::get_siasn` per batch:
 *
 * 1. pelaku = akun sistem integrasi (`AkunSistemIntegrasi::id()` di AWAL, Z-1; belum siap → berhenti sebelum apa pun);
 * 2. kunci per jenis (`GET_LOCK`, tunggu 0 — BP-6; sibuk → {@see SiasnTarikSibuk}) agar dua run jenis yang sama tidak
 *    memproses NIP yang sama;
 * 3. daftar status disinkronkan ({@see StatusTarikSiasn::sinkronDaftar()}), batch NIP dipilih, ditandai proses;
 * 4. API per NIP di LUAR transaksi (BP-4); kegagalan per NIP dicatat, tidak menghentikan batch (AC-03.1);
 * 5. {@see JenisTarikSiasn::rencana()} → {@see PenulisTarik} satu transaksi;
 * 6. flag selesai + satu baris ringkasan `integrasi_log` (jumlah saja, tanpa NIP/nama; batch kosong tidak dicatat).
 *
 * Exception APA PUN sesudah batch ditandai proses → flag dikembalikan ke `0` lalu dilempar ulang (review B6 M1 P-1).
 * `dryRun`: tanpa tulis data/flag/ringkasan (permintaan HTTP tetap tercatat teredaksi di `integrasi_log` oleh klien HTTP),
 * hanya rencana dan jumlahnya. Ulang = tanpa duplikat (AC-03.2): pasangan dicari lewat `id_rw_siasn` dulu.
 */
final class TarikSiasn
{
    public const LIMIT_BAWAAN = 20;

    /**
     * @param array<string, JenisTarikSiasn> $jenis nama → jenis
     * @param (Closure(): string)|null       $jam   waktu sekarang UTC `Y-m-d H:i:s` (test); bawaan `gmdate()`
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly SiasnGatewayInterface $siasn,
        private readonly IntegrasiLogInterface $log,
        private readonly AkunSistemIntegrasi $akun,
        private readonly array $jenis,
        private readonly ?Closure $jam = null,
    ) {
    }

    /**
     * Rakitan produksi: koneksi `DBDebug` dipaksa ({@see KoneksiCli}, galat query tidak senyap), driver SIASN wajib `bkn`
     * — gateway mock/lain mengembalikan data contoh yang bisa memicu aksi destruktif (review B6 M1 B-1c). Konfigurasi
     * BKN diperiksa saat adapter dibuat (AC-K.4).
     *
     * @throws SiasnTidakSiap
     */
    public static function dariLayanan(): self
    {
        $driver = config(Integrations::class)->siasn['driver'];

        if ($driver !== 'bkn') {
            throw new SiasnTidakSiap("integrations.siasn.driver harus 'bkn' untuk siasn:tarik (sekarang '{$driver}').");
        }

        $gateway = service('siasnGateway');

        if (! $gateway instanceof SiasnAdapter) {
            throw new SiasnTidakSiap('Gateway SIASN bukan adapter BKN nyata; siasn:tarik tidak dijalankan.');
        }

        $db = KoneksiCli::buka();

        return new self($db, $gateway, service('integrasiLog'), new AkunSistemIntegrasi($db), self::semuaJenis());
    }

    /**
     * @return array<string, JenisTarikSiasn>
     */
    public static function semuaJenis(): array
    {
        $daftar = [new TarikDataUtama(), new TarikGolongan(), new TarikPendidikan(), new TarikPmk(), new TarikSkp(), new TarikAk()];

        return array_combine(array_map(static fn (JenisTarikSiasn $j): string => $j->nama(), $daftar), $daftar);
    }

    /**
     * Nama kunci per jenis (per database + prefix, maks. 64 karakter).
     */
    public static function namaKunci(BaseConnection $db, string $jenis): string
    {
        return 'siasn_tarik:' . substr(sha1($db->getDatabase() . '|' . $db->getPrefix()), 0, 16) . ':' . $jenis;
    }

    /**
     * @return array{jenis: string, nip: int, sukses: int, gagal: int, jumlah: array<string, int>, ditolak: array<string, int>, info: array<string, int>, dry_run: bool}
     *
     * @throws SiasnTarikSibuk   jenis yang sama sedang berjalan
     * @throws DatabaseException galat DB (flag status sudah dikembalikan ke 0)
     */
    public function jalankan(string $nama, int $limit = self::LIMIT_BAWAAN, ?string $nip = null, bool $dryRun = false): array
    {
        $jenis  = $this->jenis[$nama] ?? throw new InvalidArgumentException("Jenis tarik SIASN '{$nama}' tidak dikenal.");
        $idAkun = $this->akun->id();

        if ($dryRun) {
            return $this->proses($nama, $jenis, $idAkun, max(1, $limit), $nip, true);
        }

        $kunci = self::namaKunci($this->db, $nama);
        $dapat = $this->db->query('SELECT GET_LOCK(?, 0) AS dapat', [$kunci])->getRowArray();

        if ((string) ($dapat['dapat'] ?? '') !== '1') {
            throw new SiasnTarikSibuk("Tarik SIASN {$nama} sedang berjalan di proses lain.");
        }

        try {
            return $this->proses($nama, $jenis, $idAkun, max(1, $limit), $nip, false);
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$kunci]);
        }
    }

    /**
     * @return array{jenis: string, nip: int, sukses: int, gagal: int, jumlah: array<string, int>, ditolak: array<string, int>, info: array<string, int>, dry_run: bool}
     */
    private function proses(string $nama, JenisTarikSiasn $jenis, int $idAkun, int $limit, ?string $nip, bool $dryRun): array
    {
        $status   = new StatusTarikSiasn($this->db);
        $sekarang = $this->sekarang();

        if (! $dryRun) {
            $status->sinkronDaftar($sekarang);
        }

        $daftar = $status->pilih($jenis, $limit, $nip, $sekarang);

        if (! $dryRun) {
            $status->tandaiProses($jenis, $daftar, $sekarang);
        }

        $sukses  = 0;
        $rencana = new RencanaTarik();

        try {
            $hasil = [];

            foreach ($daftar as $n) {
                $hasil[$n] = $jenis->ambil($this->siasn, $n);
            }

            $sukses  = count(array_filter($hasil, static fn (array $h): bool => $h['status_code'] === SiasnAdapter::SUKSES));
            $rencana = $jenis->rencana($this->db, $hasil, $idAkun, $sekarang);

            // Putusan reviewer B6 M1 (2): sukses dengan data kosong = INFO, tanpa aksi destruktif (dijaga tiap jenis).
            $rencana->info['data_kosong'] = count(array_filter($hasil, static fn (array $h): bool => $h['status_code'] === SiasnAdapter::SUKSES && $h['data'] === []));

            if (! $dryRun) {
                (new PenulisTarik($this->db))->terapkan($rencana);
                $status->tandaiSelesai($jenis, $daftar, $this->sekarang());
            }
        } catch (Throwable $e) {
            if (! $dryRun) {
                $this->batal($status, $jenis, $daftar);
                $this->catat($nama, CatatanIntegrasi::GAGAL, $e instanceof DatabaseException ? 'galat_db' : 'galat', count($daftar), $sukses, $rencana);
            }

            throw $e;
        }

        if (! $dryRun && $daftar !== []) {
            $semua = $sukses === count($daftar);
            $this->catat($nama, $semua ? CatatanIntegrasi::SUKSES : CatatanIntegrasi::GAGAL, $semua ? null : 'sebagian_gagal', count($daftar), $sukses, $rencana);
        }

        return [
            'jenis'   => $nama,
            'nip'     => count($daftar),
            'sukses'  => $sukses,
            'gagal'   => count($daftar) - $sukses,
            'jumlah'  => $rencana->jumlah,
            'ditolak' => $rencana->ditolak,
            'info'    => $rencana->info,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Flag kembali `0` [K rollback]; kegagalan di sini tidak menutupi galat asal (flag usang dipilih ulang setelah 6 jam).
     *
     * @param list<string> $daftar
     */
    private function batal(StatusTarikSiasn $status, JenisTarikSiasn $jenis, array $daftar): void
    {
        try {
            $status->tandaiBatal($jenis, $daftar, $this->sekarang());
        } catch (Throwable $e) {
            log_message('error', 'Tarik SIASN: flag status gagal dikembalikan ({kelas}).', ['kelas' => $e::class]);
        }
    }

    private function sekarang(): string
    {
        return $this->jam === null ? gmdate('Y-m-d H:i:s') : ($this->jam)();
    }

    private function catat(string $nama, int $statusLog, ?string $kode, int $nip, int $sukses, RencanaTarik $r): void
    {
        $j     = $r->jumlah;
        $i     = $r->info;
        $pesan = "nip {$nip}, sukses {$sukses}, gagal " . ($nip - $sukses)
            . ", sisip {$j['sisip']}, ubah {$j['ubah']}, pasang {$j['pasang']}, lepas {$j['lepas']}, hapus {$j['hapus']}, ganda {$j['ganda']}, ditolak " . array_sum($r->ditolak)
            . ", data_kosong {$i['data_kosong']}, pairing_ganda {$i['pairing_ganda']}, referensi_tak_terpetakan {$i['referensi_tak_terpetakan']}"
            . ", ditolak_tanpa_destruktif {$i['ditolak_tanpa_destruktif']}";

        $this->log->catat(new CatatanIntegrasi(CatatanIntegrasi::ARAH_KELUAR, SiasnAdapter::LAYANAN, 'tarik_' . str_replace('-', '_', $nama), $statusLog, null, $kode, $pesan));
    }
}
