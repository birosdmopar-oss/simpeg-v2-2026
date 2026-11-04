<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use App\Interfaces\Integrasi\IntegrasiLogInterface;
use App\Interfaces\SiasnGatewayInterface;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\Log\CatatanIntegrasi;
use App\Libraries\Siasn\SiasnAdapter;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use InvalidArgumentException;

/**
 * Orkestrasi `siasn:tarik --jenis=…` (H-03, F6-14 ikut legacy penuh; PRD Fase 6 §7.3, MAKE-067 M1), padanan
 * `SS/*::get_siasn` per batch:
 *
 * 1. pelaku = akun sistem integrasi (`AkunSistemIntegrasi::id()` di AWAL, Z-1; belum siap → berhenti sebelum apa pun);
 * 2. daftar status disinkronkan ({@see StatusTarikSiasn::sinkronDaftar()}), batch NIP dipilih, ditandai proses;
 * 3. API per NIP di LUAR transaksi (BP-4); kegagalan per NIP dicatat, tidak menghentikan batch (AC-03.1);
 * 4. {@see JenisTarikSiasn::rencana()} → {@see PenulisTarik} satu transaksi; galat DB → flag batal (`0`) lalu dilempar;
 * 5. flag selesai + satu baris ringkasan `integrasi_log` (jumlah saja, tanpa NIP/nama).
 *
 * `dryRun`: tanpa tulis apa pun (termasuk daftar status & flag), hanya rencana dan jumlahnya. Ulang = tanpa duplikat
 * (AC-03.2): pasangan dicari lewat `id_rw_siasn` dulu.
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

    private function sekarang(): string
    {
        return $this->jam === null ? gmdate('Y-m-d H:i:s') : ($this->jam)();
    }

    /**
     * Rakitan produksi (konfigurasi SIASN diperiksa saat dibuat, AC-K.4).
     */
    public static function dariLayanan(): self
    {
        $db = db_connect();

        return new self($db, service('siasnGateway'), service('integrasiLog'), new AkunSistemIntegrasi($db), self::semuaJenis());
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
     * @return array{jenis: string, nip: int, sukses: int, gagal: int, jumlah: array<string, int>, ditolak: array<string, int>, info: array<string, int>, dry_run: bool}
     */
    public function jalankan(string $nama, int $limit = self::LIMIT_BAWAAN, ?string $nip = null, bool $dryRun = false): array
    {
        $jenis    = $this->jenis[$nama] ?? throw new InvalidArgumentException("Jenis tarik SIASN '{$nama}' tidak dikenal.");
        $idAkun   = $this->akun->id();
        $status   = new StatusTarikSiasn($this->db);
        $sekarang = $this->sekarang();

        if (! $dryRun) {
            $status->sinkronDaftar($sekarang);
        }

        $daftar = $status->pilih($jenis, max(1, $limit), $nip, $sekarang);

        if (! $dryRun) {
            $status->tandaiProses($jenis, $daftar, $sekarang);
        }

        $hasil = [];

        foreach ($daftar as $n) {
            $hasil[$n] = $jenis->ambil($this->siasn, $n);
        }

        $sukses  = count(array_filter($hasil, static fn (array $h): bool => $h['status_code'] === SiasnAdapter::SUKSES));
        $rencana = $jenis->rencana($this->db, $hasil, $idAkun, $sekarang);

        // Putusan reviewer B6 M1 (2): sukses dengan data kosong = INFO, tanpa aksi destruktif (dijaga tiap jenis).
        $rencana->info['data_kosong'] = count(array_filter($hasil, static fn (array $h): bool => $h['status_code'] === SiasnAdapter::SUKSES && $h['data'] === []));

        if (! $dryRun) {
            try {
                (new PenulisTarik($this->db))->terapkan($rencana);
            } catch (DatabaseException $e) {
                $status->tandaiBatal($jenis, $daftar, $this->sekarang());
                $this->catat($nama, CatatanIntegrasi::GAGAL, 'galat_db', count($daftar), $sukses, $rencana);

                throw $e;
            }

            $status->tandaiSelesai($jenis, $daftar, $this->sekarang());
            $this->catat($nama, $sukses === count($daftar) ? CatatanIntegrasi::SUKSES : CatatanIntegrasi::GAGAL, $sukses === count($daftar) ? null : 'sebagian_gagal', count($daftar), $sukses, $rencana);
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

    private function catat(string $nama, int $statusLog, ?string $kode, int $nip, int $sukses, RencanaTarik $r): void
    {
        $j     = $r->jumlah;
        $i     = $r->info;
        $pesan = "nip {$nip}, sukses {$sukses}, gagal " . ($nip - $sukses)
            . ", sisip {$j['sisip']}, ubah {$j['ubah']}, pasang {$j['pasang']}, lepas {$j['lepas']}, hapus {$j['hapus']}, ganda {$j['ganda']}, ditolak " . array_sum($r->ditolak)
            . ", data_kosong {$i['data_kosong']}, pairing_ganda {$i['pairing_ganda']}, referensi_tak_terpetakan {$i['referensi_tak_terpetakan']}";

        $this->log->catat(new CatatanIntegrasi(CatatanIntegrasi::ARAH_KELUAR, SiasnAdapter::LAYANAN, 'tarik_' . str_replace('-', '_', $nama), $statusLog, null, $kode, $pesan));
    }
}
