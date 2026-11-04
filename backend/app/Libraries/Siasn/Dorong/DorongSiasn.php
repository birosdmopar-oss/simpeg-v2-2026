<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Dorong;

use App\Interfaces\Integrasi\IntegrasiLogInterface;
use App\Interfaces\SiasnDorongInterface;
use App\Libraries\Cli\KoneksiCli;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\Log\CatatanIntegrasi;
use App\Libraries\Siasn\SiasnAdapter;
use App\Libraries\Siasn\Tarik\PenulisTarik;
use App\Libraries\Siasn\Tarik\RencanaTarik;
use App\Libraries\Siasn\Tarik\SiasnTarikSibuk;
use App\Libraries\Siasn\Tarik\SiasnTidakSiap;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Integrations;
use InvalidArgumentException;
use Throwable;

/**
 * Orkestrasi `siasn:dorong --jenis=…` (H-03, F6-14; PRD Fase 6 §7.3, MAKE-067 M2), padanan `upload_*` legacy:
 *
 * 1. pelaku = akun sistem integrasi di AWAL (Z-1) → `updated_by`;
 * 2. kunci `GET_LOCK` per jenis (tunggu 0; sibuk → {@see SiasnTarikSibuk});
 * 3. baris dipilih ({@see JenisDorongSiasn::pilih()}); jenis berpenanda proses (jabatan) ditandai lebih dulu;
 * 4. per baris: payload divalidasi (tidak sah → tidak dikirim, flag gagal + alasan) → POST di LUAR transaksi, TIDAK di-retry
 *    otomatis (BP-3) → hasil DITULIS SEGERA dalam transaksinya sendiri (POST yang sukses tidak hilang bila proses terhenti
 *    di baris berikutnya; usulan B6 M2 Q6); id SIASN disimpan hanya dari balasan sukses yang sah;
 * 5. exception apa pun → penanda proses baris yang belum selesai dikembalikan, ringkasan `galat`, dilempar ulang;
 * 6. satu ringkasan `integrasi_log` (`dorong_<jenis>`, jumlah saja; batch kosong tidak dicatat).
 *
 * `siasn_error` = pesan BKN dipotong dengan id PNS & NIP disensor, tanpa payload (usulan Q7). `dryRun`: tanpa POST dan
 * tanpa tulis apa pun; hanya jumlah yang akan dikirim / tidak sah.
 */
final class DorongSiasn
{
    public const LIMIT_BAWAAN = 20;

    /**
     * @param array<string, JenisDorongSiasn> $jenis
     * @param (Closure(): string)|null        $jam   waktu sekarang UTC (test)
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly SiasnDorongInterface $siasn,
        private readonly IntegrasiLogInterface $log,
        private readonly AkunSistemIntegrasi $akun,
        private readonly array $jenis,
        private readonly ?Closure $jam = null,
    ) {
    }

    /**
     * Rakitan produksi: driver SIASN wajib `bkn` (review B6 M1 B-1c), koneksi `DBDebug` dipaksa.
     *
     * @throws SiasnTidakSiap
     */
    public static function dariLayanan(): self
    {
        $driver = config(Integrations::class)->siasn['driver'];

        if ($driver !== 'bkn') {
            throw new SiasnTidakSiap("integrations.siasn.driver harus 'bkn' untuk siasn:dorong (sekarang '{$driver}').");
        }

        $gateway = service('siasnGateway');

        if (! $gateway instanceof SiasnAdapter) {
            throw new SiasnTidakSiap('Gateway SIASN bukan adapter BKN nyata; siasn:dorong tidak dijalankan.');
        }

        $db = KoneksiCli::buka();

        return new self($db, $gateway, service('integrasiLog'), new AkunSistemIntegrasi($db), self::semuaJenis());
    }

    /**
     * @return array<string, JenisDorongSiasn>
     */
    public static function semuaJenis(): array
    {
        $daftar = [new DorongJabatan(config(Integrations::class)->bkn), new DorongDiklat(), new DorongSertifikasi(), new DorongKursus(), new DorongSkp22()];

        return array_combine(array_map(static fn (JenisDorongSiasn $j): string => $j->nama(), $daftar), $daftar);
    }

    public static function namaKunci(BaseConnection $db, string $jenis): string
    {
        return 'siasn_dorong:' . substr(sha1($db->getDatabase() . '|' . $db->getPrefix()), 0, 16) . ':' . $jenis;
    }

    /**
     * @return array{jenis: string, baris: int, terkirim: int, sukses: int, gagal: int, tidak_sah: int, sukses_tanpa_id: int, dry_run: bool}
     *
     * @throws SiasnTarikSibuk
     * @throws DatabaseException
     */
    public function jalankan(string $nama, int $limit = self::LIMIT_BAWAAN, ?string $nip = null, bool $dryRun = false): array
    {
        $jenis  = $this->jenis[$nama] ?? throw new InvalidArgumentException("Jenis dorong SIASN '{$nama}' tidak dikenal.");
        $idAkun = $this->akun->id();

        if ($dryRun) {
            return $this->proses($nama, $jenis, $idAkun, max(1, $limit), $nip, true);
        }

        $kunci = self::namaKunci($this->db, $nama);
        $dapat = $this->db->query('SELECT GET_LOCK(?, 0) AS dapat', [$kunci])->getRowArray();

        if ((string) ($dapat['dapat'] ?? '') !== '1') {
            throw new SiasnTarikSibuk("Dorong SIASN {$nama} sedang berjalan di proses lain.");
        }

        try {
            return $this->proses($nama, $jenis, $idAkun, max(1, $limit), $nip, false);
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$kunci]);
        }
    }

    /**
     * @return array{jenis: string, baris: int, terkirim: int, sukses: int, gagal: int, tidak_sah: int, sukses_tanpa_id: int, dry_run: bool}
     */
    private function proses(string $nama, JenisDorongSiasn $jenis, int $idAkun, int $limit, ?string $nip, bool $dryRun): array
    {
        $baris = $jenis->pilih($this->db, $limit, $nip, $this->sekarang());
        $hasil = ['jenis' => $nama, 'baris' => count($baris), 'terkirim' => 0, 'sukses' => 0, 'gagal' => 0, 'tidak_sah' => 0, 'sukses_tanpa_id' => 0, 'dry_run' => $dryRun];

        /** @var array<int, int> $belum id baris berpenanda proses yang belum ditulis hasilnya → flag asal */
        $belum = [];

        if (! $dryRun && $jenis->flagProses() !== null && $baris !== []) {
            $belum = $this->tandaiProses($jenis, $baris);
        }

        try {
            foreach ($baris as $b) {
                $id = (int) $b[$jenis->pk()];

                try {
                    $payload = $jenis->payload($this->db, $b);
                } catch (PayloadTidakSah $e) {
                    $hasil['tidak_sah']++;

                    if (! $dryRun) {
                        $this->tulis($jenis, $id, $jenis->dataGagal($b, null, $e->getMessage(), $idAkun, $this->sekarang()));
                        unset($belum[$id]);
                    }

                    continue;
                }

                if ($dryRun) {
                    $hasil['terkirim']++;

                    continue;
                }

                $rahasia = array_values(array_filter([(string) ($b['pns_id'] ?? ''), (string) ($b['nip'] ?? '')]));
                $balasan = $this->siasn->simpanRiwayat($jenis->jalur(), $payload, $rahasia);
                $hasil['terkirim']++;

                if ($balasan['status_code'] === SiasnAdapter::SUKSES) {
                    $idSiasn = $jenis->idDariBalasan($balasan['data']);
                    $hasil['sukses']++;
                    $hasil['sukses_tanpa_id'] += $idSiasn === null ? 1 : 0;
                    $data = $jenis->dataSukses($b, $idSiasn, $idAkun, $this->sekarang());
                } else {
                    $hasil['gagal']++;
                    $data = $jenis->dataGagal($b, $balasan['status_code'], self::pesanAman($balasan, $rahasia), $idAkun, $this->sekarang());
                }

                $this->tulis($jenis, $id, $data);
                unset($belum[$id]);
            }
        } catch (Throwable $e) {
            if (! $dryRun) {
                $this->pulihkan($jenis, $belum);
                $this->catat($nama, CatatanIntegrasi::GAGAL, $e instanceof DatabaseException ? 'galat_db' : 'galat', $hasil);
            }

            throw $e;
        }

        if (! $dryRun && $baris !== []) {
            $semua = $hasil['gagal'] === 0 && $hasil['tidak_sah'] === 0;
            $this->catat($nama, $semua ? CatatanIntegrasi::SUKSES : CatatanIntegrasi::GAGAL, $semua ? null : 'sebagian_gagal', $hasil);
        }

        return $hasil;
    }

    /**
     * Penanda proses [K jabatan flag 3]; `updated_at = updated_at` agar `ON UPDATE CURRENT_TIMESTAMP` tidak menggeser
     * stempel (kriteria `siasn_lu < updated_at`).
     *
     * @param list<array<string, mixed>> $baris
     *
     * @return array<int, int> id → flag asal
     */
    private function tandaiProses(JenisDorongSiasn $jenis, array $baris): array
    {
        $asal = [];

        foreach ($baris as $b) {
            $asal[(int) $b[$jenis->pk()]] = (int) $b['siasn_flag'];
        }

        $hasil = $this->db->table($jenis->tabel())->whereIn($jenis->pk(), array_keys($asal))
            ->set('siasn_flag', (int) $jenis->flagProses())->set('updated_at', 'updated_at', false)->update();

        if ($hasil === false) {
            throw new DatabaseException('Penanda proses dorong SIASN gagal.');
        }

        return $asal;
    }

    /**
     * @param array<int, int> $belum
     */
    private function pulihkan(JenisDorongSiasn $jenis, array $belum): void
    {
        try {
            foreach ($belum as $id => $flag) {
                $this->db->table($jenis->tabel())->where($jenis->pk(), $id)->set('siasn_flag', $flag)->set('updated_at', 'updated_at', false)->update();
            }
        } catch (Throwable $e) {
            log_message('error', 'Dorong SIASN: penanda proses gagal dikembalikan ({kelas}).', ['kelas' => $e::class]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function tulis(JenisDorongSiasn $jenis, int $id, array $data): void
    {
        $r = new RencanaTarik();
        $r->ubah($jenis->tabel(), [$jenis->pk() => $id], $data);
        (new PenulisTarik($this->db))->terapkan($r);
    }

    /**
     * Pesan gagal untuk `siasn_error`: kode + pesan adapter (≤ 200), nilai rahasia (id PNS, NIP) disensor.
     *
     * @param array{status_code: string, message: string, data: mixed} $balasan
     * @param list<string>                                              $rahasia
     */
    public static function pesanAman(array $balasan, array $rahasia): string
    {
        $pesan = $balasan['message'] === 'Internal Server Error' ? $balasan['message'] : match ($balasan['status_code']) {
            'timeout', 'koneksi' => 'Gagal terhubung (' . $balasan['status_code'] . '); status di SIASN tidak diketahui.',
            'token'              => 'Token BKN tidak dapat diperoleh.',
            'respons'            => 'Balasan SIASN tidak sah: ' . $balasan['message'],
            default              => 'HTTP ' . $balasan['status_code'] . ': ' . $balasan['message'],
        };

        foreach ($rahasia as $r) {
            $pesan = str_replace($r, '***', $pesan);
        }

        return mb_substr($pesan, 0, 255);
    }

    private function sekarang(): string
    {
        return $this->jam === null ? gmdate('Y-m-d H:i:s') : ($this->jam)();
    }

    /**
     * @param array{jenis: string, baris: int, terkirim: int, sukses: int, gagal: int, tidak_sah: int, sukses_tanpa_id: int, dry_run: bool} $h
     */
    private function catat(string $nama, int $status, ?string $kode, array $h): void
    {
        $pesan = "baris {$h['baris']}, terkirim {$h['terkirim']}, sukses {$h['sukses']}, gagal {$h['gagal']}, tidak_sah {$h['tidak_sah']}, sukses_tanpa_id {$h['sukses_tanpa_id']}";

        $this->log->catat(new CatatanIntegrasi(CatatanIntegrasi::ARAH_KELUAR, SiasnAdapter::LAYANAN, 'dorong_' . $nama, $status, null, $kode, $pesan));
    }
}
