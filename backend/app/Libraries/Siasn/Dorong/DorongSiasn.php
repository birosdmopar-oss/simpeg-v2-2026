<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Dorong;

use App\Interfaces\Integrasi\IntegrasiLogInterface;
use App\Interfaces\SiasnDorongInterface;
use App\Libraries\Cli\KoneksiCli;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\Log\CatatanIntegrasi;
use App\Libraries\Siasn\SiasnAdapter;
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
 *    di baris berikutnya; putusan reviewer B6 M2 Q6); id SIASN disimpan hanya dari balasan sukses yang sah;
 * 5. exception apa pun → penanda proses baris yang belum selesai dikembalikan, ringkasan `galat`, dilempar ulang;
 * 6. satu ringkasan `integrasi_log` (`dorong_<jenis>`, jumlah saja; batch kosong tidak dicatat).
 *
 * `siasn_error` = pesan BKN dipotong dengan id PNS & NIP disensor, tanpa payload (putusan Q7). `dryRun`: tanpa POST dan
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
     * @return array{jenis: string, baris: int, terkirim: int, sukses: int, gagal: int, tidak_sah: int, sukses_tanpa_id: int, status_tidak_diketahui: int, tidak_tersimpan: int, dry_run: bool}
     *
     * @throws SiasnTarikSibuk
     * @throws DatabaseException
     * @throws SiasnTokenGagal token/otorisasi BKN ditolak (batch dihentikan)
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
     * @return array{jenis: string, baris: int, terkirim: int, sukses: int, gagal: int, tidak_sah: int, sukses_tanpa_id: int, status_tidak_diketahui: int, tidak_tersimpan: int, dry_run: bool}
     */
    private function proses(string $nama, JenisDorongSiasn $jenis, int $idAkun, int $limit, ?string $nip, bool $dryRun): array
    {
        $baris = $jenis->pilih($this->db, $limit, $nip, $this->sekarang());
        $hasil = ['jenis' => $nama, 'baris' => count($baris), 'terkirim' => 0, 'sukses' => 0, 'gagal' => 0, 'tidak_sah' => 0, 'sukses_tanpa_id' => 0, 'status_tidak_diketahui' => 0, 'tidak_tersimpan' => 0, 'dry_run' => $dryRun];

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

                $rahasia = $jenis->rahasia($b);
                $balasan = $this->siasn->simpanRiwayat($jenis->jalur(), $payload, $rahasia, $jenis->tabel() . ':' . $id);

                if (in_array($balasan['status_code'], ['token', '401'], true)) {
                    // Review B6 M2 P-3: hentikan batch TANPA menulis baris ini (dipulihkan bersama sisanya).
                    throw new SiasnTokenGagal('Token/otorisasi BKN ditolak; dorong dihentikan tanpa menulis hasil baris.');
                }

                $hasil['terkirim']++;

                if ($balasan['status_code'] === SiasnAdapter::SUKSES) {
                    $idSiasn = $jenis->idDariBalasan($balasan['data']);
                    $hasil['sukses']++;
                    $hasil['sukses_tanpa_id'] += $idSiasn === null ? 1 : 0;
                    // POST sukses SUDAH terjadi: baris tidak boleh kembali ke penanda asal (review B6 M2 B-1).
                    unset($belum[$id]);
                    $this->tulisSesudahPost($nama, $jenis, $b, $id, $jenis->dataSukses($b, $idSiasn, $idAkun, $this->sekarang()), $jenis->dataTidakTersimpan($b, $idSiasn, $idAkun, $this->sekarang()), $idSiasn, $hasil);

                    continue;
                }

                $hasil['gagal']++;
                // Putusan reviewer B6 M2 Q9: timeout/koneksi → status di SIASN tidak diketahui (operator memeriksa duplikat).
                $hasil['status_tidak_diketahui'] += in_array($balasan['status_code'], ['timeout', 'koneksi'], true) ? 1 : 0;
                // POST gagal pun SUDAH terjadi (review B6 M3 P-1): timeout/5xx bisa tetap tersimpan di BKN → tidak dipulihkan
                // ke penanda asal. Sekali-dorong memakai data minimal agar tidak terpilih lagi; jenis berpenanda proses
                // (jabatan) tetap flag 3 dengan `siasn_lu` = sekarang agar aturan 6 jam [K] berlaku (siasn_lu NULL = langsung
                // terpilih lagi).
                unset($belum[$id]);
                $this->tulisSesudahPost(
                    $nama,
                    $jenis,
                    $b,
                    $id,
                    $jenis->dataGagal($b, $balasan['status_code'], self::pesanAman($balasan, $rahasia), $idAkun, $this->sekarang()),
                    $jenis->flagProses() === null
                        ? $jenis->dataTidakTersimpan($b, null, $idAkun, $this->sekarang())
                        : ['siasn_flag' => $jenis->flagProses(), 'siasn_error' => DorongDasar::PESAN_TIDAK_TERSIMPAN, 'siasn_lu' => $this->sekarang(), 'updated_at' => $this->sekarang()],
                    null,
                    $hasil,
                );
            }
        } catch (Throwable $e) {
            if (! $dryRun) {
                $this->pulihkan($jenis, $belum);
                $this->catat($nama, CatatanIntegrasi::GAGAL, $e instanceof DatabaseException ? 'galat_db' : 'galat', $hasil);
            }

            throw $e;
        }

        if (! $dryRun && $baris !== []) {
            $semua = $hasil['gagal'] === 0 && $hasil['tidak_sah'] === 0 && $hasil['tidak_tersimpan'] === 0;
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
     * Tulis hasil sesudah POST terjadi — sukses (review B6 M2 B-1) maupun gagal (review B6 M3 P-1): gagal → coba sekali lagi
     * → gagal lagi → log `critical` + `integrasi_log` kode `hasil_tidak_tersimpan` (tabel, pk, id SIASN; tanpa NIP), lalu
     * `$minimal` ({@see JenisDorongSiasn::dataTidakTersimpan()}) agar baris TIDAK terpilih lagi (mencegah duplikat di BKN).
     * Bila data minimal pun gagal, batch dihentikan (baris sudah dikeluarkan dari pemulihan, tetap di penanda prosesnya).
     *
     * @param array<string, mixed>      $b
     * @param array<string, mixed>      $data
     * @param array<string, mixed>      $minimal
     * @param array{jenis: string, baris: int, terkirim: int, sukses: int, gagal: int, tidak_sah: int, sukses_tanpa_id: int, status_tidak_diketahui: int, tidak_tersimpan: int, dry_run: bool} $hasil
     */
    private function tulisSesudahPost(string $nama, JenisDorongSiasn $jenis, array $b, int $id, array $data, array $minimal, ?string $idSiasn, array &$hasil): void
    {
        $galat = null;

        for ($coba = 0; $coba < 2; $coba++) {
            try {
                $this->tulis($jenis, $id, $data, self::snapshot($jenis, $b));

                return;
            } catch (Throwable $e) {
                $galat = $e;
            }
        }

        $hasil['tidak_tersimpan']++;
        $ref = $jenis->tabel() . ':' . $id . ' id_siasn ' . ($idSiasn ?? '-');
        log_message('critical', 'Dorong SIASN: POST terkirim tetapi hasil tidak tersimpan ({ref}, {kelas}); periksa BKN sebelum didorong lagi.', ['ref' => $ref, 'kelas' => $galat::class]);
        $this->log->catat(new CatatanIntegrasi(CatatanIntegrasi::ARAH_KELUAR, SiasnAdapter::LAYANAN, 'dorong_' . $nama, CatatanIntegrasi::GAGAL, null, 'hasil_tidak_tersimpan', $ref, refId: $jenis->tabel() . ':' . $id));

        try {
            $this->tulis($jenis, $id, $minimal);
        } catch (Throwable $e) {
            throw $e instanceof DatabaseException ? $e : new DatabaseException('Hasil dorong SIASN tidak tersimpan.', 0, $e);
        }
    }

    /**
     * Stempel `updated_at` saat baris dipilih — UPDATE bersyarat agar edit pengguna selama batch tidak hilang dari dorong
     * ulang (review B6 M2 P-2). Hanya jenis berpenanda proses (jabatan, kriteria `siasn_lu < updated_at`); tabel sekali-dorong
     * (mis. `riwayat_skp`) tidak punya `siasn_lu`.
     *
     * @param array<string, mixed> $b
     */
    private static function snapshot(JenisDorongSiasn $jenis, array $b): ?string
    {
        if ($jenis->flagProses() === null) {
            return null;
        }

        return isset($b['updated_at']) && is_string($b['updated_at']) && $b['updated_at'] !== '' ? $b['updated_at'] : null;
    }

    /**
     * UPDATE satu baris dalam transaksi pola proyek. Bila data membawa `updated_at` dan ada `$snapshot`: syarat
     * `updated_at = snapshot`; 0 baris (pengguna mengubah riwayat selama batch) → hasil tetap ditulis tetapi `updated_at`
     * pengguna dipertahankan dan `siasn_lu = snapshot`, sehingga riwayat itu terpilih lagi (`siasn_lu < updated_at`).
     *
     * @param array<string, mixed> $data
     */
    private function tulis(JenisDorongSiasn $jenis, int $id, array $data, ?string $snapshot = null): void
    {
        $bersyarat = $snapshot !== null && array_key_exists('updated_at', $data);

        $this->db->transBegin();

        try {
            $qb = $this->db->table($jenis->tabel())->where($jenis->pk(), $id);

            if ($bersyarat) {
                $qb->where('updated_at', $snapshot);
            }

            if ($qb->update($data) === false) {
                throw new DatabaseException('Hasil dorong SIASN gagal ditulis.');
            }

            if ($bersyarat && $this->db->affectedRows() === 0) {
                $ulang = $data;
                unset($ulang['updated_at']);
                $ulang['siasn_lu'] = $snapshot;

                if ($this->db->table($jenis->tabel())->where($jenis->pk(), $id)->set($ulang)->set('updated_at', 'updated_at', false)->update() === false) {
                    throw new DatabaseException('Hasil dorong SIASN gagal ditulis.');
                }
            }

            if (! $this->db->transStatus()) {
                throw new DatabaseException('Transaksi hasil dorong SIASN gagal.');
            }

            $this->db->transCommit();
            $this->db->resetTransStatus();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e instanceof DatabaseException ? $e : new DatabaseException('Hasil dorong SIASN gagal ditulis: ' . $e::class, 0, $e);
        }
    }

    /**
     * Pesan gagal untuk `siasn_error`: kode + pesan adapter, nilai rahasia (id PNS, NIP, NIP penilai) disensor; dipotong per
     * BYTE (kolom TINYTEXT, review B6 M2 P-4).
     *
     * @param array{status_code: string, message: string, data: mixed} $balasan
     * @param list<string>                                              $rahasia
     */
    public static function pesanAman(array $balasan, #[\SensitiveParameter] array $rahasia): string
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

        return mb_strcut($pesan, 0, 255);
    }

    private function sekarang(): string
    {
        return $this->jam === null ? gmdate('Y-m-d H:i:s') : ($this->jam)();
    }

    /**
     * @param array{jenis: string, baris: int, terkirim: int, sukses: int, gagal: int, tidak_sah: int, sukses_tanpa_id: int, status_tidak_diketahui: int, tidak_tersimpan: int, dry_run: bool} $h
     */
    private function catat(string $nama, int $status, ?string $kode, array $h): void
    {
        $pesan = "baris {$h['baris']}, terkirim {$h['terkirim']}, sukses {$h['sukses']}, gagal {$h['gagal']}, tidak_sah {$h['tidak_sah']}, sukses_tanpa_id {$h['sukses_tanpa_id']}, status_tidak_diketahui {$h['status_tidak_diketahui']}, tidak_tersimpan {$h['tidak_tersimpan']}";

        $this->log->catat(new CatatanIntegrasi(CatatanIntegrasi::ARAH_KELUAR, SiasnAdapter::LAYANAN, 'dorong_' . $nama, $status, null, $kode, $pesan));
    }
}
