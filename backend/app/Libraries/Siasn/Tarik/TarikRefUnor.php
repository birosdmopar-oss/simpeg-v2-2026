<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use App\Interfaces\Integrasi\IntegrasiLogInterface;
use App\Interfaces\SiasnReferensiInterface;
use App\Libraries\Cli\KoneksiCli;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\Log\CatatanIntegrasi;
use App\Libraries\Siasn\SiasnAdapter;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Integrations;
use Throwable;

/**
 * `siasn:tarik --jenis=ref-unor` — padanan `SS/Ref_unor::sync` [K `Ref_unor.php:9-136`] ke `siasn_ref_unor` (DBV-027
 * §3.5): satu panggilan `referensi/ref-unor` untuk instansi (bukan per NIP).
 *
 * - id belum ada → INSERT, kolom pemetaan lokal (`id_jabatan`, `id_unit`, `id_satker`, `lvl_uker`) NULL [K `:52-68`];
 * - ada + salah satu kolom respons berbeda [K `:74-79`] → UPDATE kolom respons saja; pemetaan lokal TIDAK disentuh;
 * - tanpa hapus [K]; nilai kosong → NULL [K], kecuali `nama_jabatan` apa adanya;
 * - validasi panjang per kolom; baris tidak sah ditolak & dihitung; satu transaksi; kunci `GET_LOCK`; akun sistem di awal
 *   (Z-1); jawaban gagal / bentuk tidak dikenal → tanpa tulis.
 */
final class TarikRefUnor
{
    /** Kolom tabel → kunci respons SIASN [K `$columnPair`] + batas panjang kolom. */
    private const KOLOM = [
        'instansi_id'     => ['InstansiId', 100],
        'atasan_id'       => ['DiatasanId', 100],
        'eselon_id'       => ['EselonId', 50],
        'nama_unor'       => ['NamaUnor', 255],
        'nama_jabatan'    => ['NamaJabatan', 255],
        'induk_unor_id'   => ['IndukUnorId', 100],
        'pemimpin_pns_id' => ['PemimpinPnsId', 100],
        'jenis_unor_id'   => ['JenisUnorId', 50],
        'cepat_kode'      => ['CepatKode', 100],
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly SiasnReferensiInterface $siasn,
        private readonly IntegrasiLogInterface $log,
        private readonly AkunSistemIntegrasi $akun,
    ) {
    }

    /**
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

        return new self($db, $gateway, service('integrasiLog'), new AkunSistemIntegrasi($db));
    }

    /**
     * @return array{jenis: string, sukses: bool, sisip: int, ubah: int, tetap: int, ditolak: array<string, int>, dry_run: bool}
     *
     * @throws SiasnTarikSibuk
     * @throws DatabaseException
     */
    public function jalankan(bool $dryRun = false): array
    {
        $this->akun->id();

        if ($dryRun) {
            return $this->proses(true);
        }

        $kunci = TarikSiasn::namaKunci($this->db, 'ref-unor');
        $dapat = $this->db->query('SELECT GET_LOCK(?, 0) AS dapat', [$kunci])->getRowArray();

        if ((string) ($dapat['dapat'] ?? '') !== '1') {
            throw new SiasnTarikSibuk('Tarik SIASN ref-unor sedang berjalan di proses lain.');
        }

        try {
            return $this->proses(false);
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$kunci]);
        }
    }

    /**
     * @return array{jenis: string, sukses: bool, sisip: int, ubah: int, tetap: int, ditolak: array<string, int>, dry_run: bool}
     */
    private function proses(bool $dryRun): array
    {
        $hasil    = $this->siasn->refUnor();
        $rencana  = new RencanaTarik();
        $tetap    = 0;
        $sukses   = $hasil['status_code'] === SiasnAdapter::SUKSES;
        $sekarang = gmdate('Y-m-d H:i:s');

        if ($sukses) {
            $ada = array_column($this->db->table('siasn_ref_unor')->get()->getResultArray(), null, 'id');

            foreach ($hasil['data'] as $si) {
                try {
                    $id    = NilaiSiasn::teks($si, 'Id', 100) ?? throw new NilaiTidakSah('Id', 'tipe');
                    $kolom = $this->kolom($si);
                } catch (NilaiTidakSah $e) {
                    $rencana->tolak($e->alasan);

                    continue;
                }

                if (! isset($ada[$id])) {
                    $rencana->sisip('siasn_ref_unor', ['id' => $id] + $kolom + ['id_jabatan' => null, 'id_unit' => null, 'id_satker' => null, 'lvl_uker' => null, 'created_at' => $sekarang, 'updated_at' => $sekarang]);
                    $ada[$id] = ['id' => $id] + $kolom;

                    continue;
                }

                $berubah = false;

                foreach ($kolom as $k => $v) {
                    if (NilaiSiasn::berbeda($ada[$id][$k] ?? null, $v)) {
                        $berubah = true;

                        break;
                    }
                }

                if ($berubah) {
                    $rencana->ubah('siasn_ref_unor', ['id' => $id], $kolom + ['updated_at' => $sekarang]);
                } else {
                    $tetap++;
                }
            }
        }

        if (! $dryRun) {
            try {
                (new PenulisTarik($this->db))->terapkan($rencana);
            } catch (Throwable $e) {
                $this->catat(CatatanIntegrasi::GAGAL, 'galat_db', $rencana, $tetap);

                throw $e;
            }

            // Saran review B6 M3 #14: sebagian baris ditolak → GAGAL `sebagian_ditolak` (yang sah tetap ditulis).
            $ditolak = array_sum($rencana->ditolak) > 0;
            $this->catat(
                $sukses && ! $ditolak ? CatatanIntegrasi::SUKSES : CatatanIntegrasi::GAGAL,
                $sukses ? ($ditolak ? 'sebagian_ditolak' : null) : 'gagal_' . $hasil['status_code'],
                $rencana,
                $tetap,
            );
        }

        return ['jenis' => 'ref-unor', 'sukses' => $sukses, 'sisip' => $rencana->jumlah['sisip'], 'ubah' => $rencana->jumlah['ubah'], 'tetap' => $tetap, 'ditolak' => $rencana->ditolak, 'dry_run' => $dryRun];
    }

    /**
     * @param array<string, mixed> $si
     *
     * @return array<string, int|string|null>
     */
    private function kolom(array $si): array
    {
        $kolom = [];

        foreach (self::KOLOM as $k => [$kunci, $maks]) {
            $kolom[$k] = NilaiSiasn::teks($si, $kunci, $maks);
        }

        // [K :58] nama_jabatan disalin apa adanya (kosong tetap kosong, bukan NULL).
        $kolom['nama_jabatan'] ??= array_key_exists('NamaJabatan', $si) && $si['NamaJabatan'] !== null ? '' : null;
        $kolom['is_unor_sekolah'] = self::bendera($si['IsUnorSekolah'] ?? null);

        return $kolom;
    }

    /**
     * `IsUnorSekolah` (boolean/angka/teks) → 1/0; kosong → NULL [K `!= '' ? … : NULL`].
     */
    private static function bendera(mixed $nilai): ?int
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        if (is_bool($nilai)) {
            return $nilai ? 1 : 0;
        }

        $teks = strtolower(trim((string) (is_scalar($nilai) ? $nilai : '')));

        return match ($teks) {
            '1', 'true'  => 1,
            '0', 'false' => 0,
            default      => throw new NilaiTidakSah('IsUnorSekolah', 'tipe'),
        };
    }

    private function catat(int $status, ?string $kode, RencanaTarik $r, int $tetap): void
    {
        $pesan = "sisip {$r->jumlah['sisip']}, ubah {$r->jumlah['ubah']}, tetap {$tetap}, ditolak " . array_sum($r->ditolak);

        $this->log->catat(new CatatanIntegrasi(CatatanIntegrasi::ARAH_KELUAR, SiasnAdapter::LAYANAN, 'tarik_ref_unor', $status, null, $kode, $pesan));
    }
}
