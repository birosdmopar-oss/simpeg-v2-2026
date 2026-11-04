<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Arsip;

use App\Interfaces\Integrasi\IntegrasiLogInterface;
use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
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
use Throwable;

/**
 * `siasn:unggah-arsip` (H-03, F6-14; MAKE-067 M3) — antre ({@see AntreArsip}) lalu unggah antrean `siasn_upload_arsip`,
 * padanan `SS/Arsip::upload_siasn` [K `Arsip.php:10-155`]:
 *
 * - ambil status 0 urut id (bawaan 1 baris per jalan, legacy "every minute" `limit 1`), tandai 3 + `updated_at` (DBV-027);
 * - berkas dibaca lewat storage v2 (`attachmentService->ambil`, normalisasi path legacy MAKE-069); tidak ada → status 2
 *   "Berkas tidak ditemukan" (DBV-Q4);
 * - wajib PDF dan ≤ 1 MB dari isi berkas [K `:87`], selain itu status 2 "Invalid file type or file size, size: …MB" [K];
 * - `POST upload-dok-rw` multipart, `id_riwayat` = `id_rw_siasn`, `id_ref_dokumen` 872 (jabatan) / 881 (sertifikasi) [K];
 *   TIDAK di-retry (BP-3); sukses (`code == 1`) → status 1 + `resp_dok_id/dok_nama/dok_uri/object/slug` MENTAH (dipotong
 *   panjang kolom); gagal → status 2 + `error_msg` legacy ("Failed to upload to SIASN, size: …MB" / timeout → "Timeout
 *   upload, size: …MB", dihitung INFO `status_tidak_diketahui`);
 * - hasil ditulis per baris; exception → baris status 3 yang belum selesai dikembalikan ke 0; ringkasan jumlah saja.
 *
 * Bentuk permintaan unggah = asumsi di {@see SiasnAdapter::unggahDokumen()} sampai bentuk `postApi_siasnArsip` legacy
 * dikonfirmasi (B6 M3 Q1).
 */
final class UnggahArsip
{
    public const LIMIT_BAWAAN = 1;

    public const BATAS_BYTE = 1048576;

    /** `rw_name` → `id_ref_dokumen` SIASN [K `Arsip.php:59-68`]. */
    private const SLUG = [AntreArsip::RW_JABATAN => '872', AntreArsip::RW_SERTIFIKASI => '881'];

    /**
     * @param (Closure(): string)|null $jam
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly SiasnDorongInterface $siasn,
        private readonly AttachmentServiceInterface $lampiran,
        private readonly IntegrasiLogInterface $log,
        private readonly AkunSistemIntegrasi $akun,
        private readonly ?Closure $jam = null,
    ) {
    }

    /**
     * @throws SiasnTidakSiap
     * @throws \App\Libraries\Integrasi\KonfigurasiIntegrasiTidakLengkap konfigurasi BKN belum lengkap (AC-K.4)
     */
    public static function dariLayanan(): self
    {
        $driver = config(Integrations::class)->siasn['driver'];

        if ($driver !== 'bkn') {
            throw new SiasnTidakSiap("integrations.siasn.driver harus 'bkn' untuk siasn:unggah-arsip (sekarang '{$driver}').");
        }

        $gateway = service('siasnGateway');

        if (! $gateway instanceof SiasnAdapter) {
            throw new SiasnTidakSiap('Gateway SIASN bukan adapter BKN nyata; siasn:unggah-arsip tidak dijalankan.');
        }

        $db = KoneksiCli::buka();

        return new self($db, $gateway, service('attachmentService'), service('integrasiLog'), new AkunSistemIntegrasi($db));
    }

    public static function namaKunci(BaseConnection $db): string
    {
        return 'siasn_arsip:' . substr(sha1($db->getDatabase() . '|' . $db->getPrefix()), 0, 16);
    }

    /**
     * @param 'antre'|'semua'|'unggah' $langkah
     *
     * @return array{antre: array{baru: int, sudah_ada: int, path_tidak_sah: int}|null, unggah: array{diproses: int, sukses: int, gagal: int, berkas_hilang: int, tidak_sah: int, status_tidak_diketahui: int}|null, dry_run: bool}
     *
     * @throws SiasnTarikSibuk
     * @throws DatabaseException
     * @throws \App\Libraries\Integrasi\AkunSistemTidakSiap
     */
    public function jalankan(string $langkah = 'semua', int $limit = self::LIMIT_BAWAAN, bool $dryRun = false): array
    {
        $this->akun->id();

        if ($dryRun) {
            return $this->proses($langkah, max(1, $limit), true);
        }

        $kunci = self::namaKunci($this->db);
        $dapat = $this->db->query('SELECT GET_LOCK(?, 0) AS dapat', [$kunci])->getRowArray();

        if ((string) ($dapat['dapat'] ?? '') !== '1') {
            throw new SiasnTarikSibuk('Unggah arsip SIASN sedang berjalan di proses lain.');
        }

        try {
            return $this->proses($langkah, max(1, $limit), false);
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$kunci]);
        }
    }

    /**
     * @return array{antre: array{baru: int, sudah_ada: int, path_tidak_sah: int}|null, unggah: array{diproses: int, sukses: int, gagal: int, berkas_hilang: int, tidak_sah: int, status_tidak_diketahui: int}|null, dry_run: bool}
     */
    private function proses(string $langkah, int $limit, bool $dryRun): array
    {
        $antre = null;

        if ($langkah !== 'unggah') {
            $antre = (new AntreArsip($this->db))->jalankan($dryRun, $this->sekarang());

            if (! $dryRun && $antre['baru'] > 0) {
                $this->catat('antre_arsip', CatatanIntegrasi::SUKSES, null, "baru {$antre['baru']}, sudah_ada {$antre['sudah_ada']}, path_tidak_sah {$antre['path_tidak_sah']}");
            }
        }

        return ['antre' => $antre, 'unggah' => $langkah === 'antre' ? null : $this->unggah($limit, $dryRun), 'dry_run' => $dryRun];
    }

    /**
     * @return array{diproses: int, sukses: int, gagal: int, berkas_hilang: int, tidak_sah: int, status_tidak_diketahui: int}
     */
    private function unggah(int $limit, bool $dryRun): array
    {
        $hasil = ['diproses' => 0, 'sukses' => 0, 'gagal' => 0, 'berkas_hilang' => 0, 'tidak_sah' => 0, 'status_tidak_diketahui' => 0];
        $baris = $this->db->table('siasn_upload_arsip')->where('status', 0)->orderBy('id_siasn_upload_arsip', 'ASC')->limit($limit)->get()->getResultArray();

        if ($baris === [] || $dryRun) {
            $hasil['diproses'] = count($baris);

            return $hasil;
        }

        $id    = array_map(static fn (array $b): int => (int) $b['id_siasn_upload_arsip'], $baris);
        $belum = array_flip($id);

        if ($this->db->table('siasn_upload_arsip')->whereIn('id_siasn_upload_arsip', $id)->update(['status' => 3, 'updated_at' => $this->sekarang()]) === false) {
            throw new DatabaseException('Penanda proses unggah arsip SIASN gagal.');
        }

        try {
            foreach ($baris as $b) {
                $hasil['diproses']++;
                $this->tulis((int) $b['id_siasn_upload_arsip'], $this->satu($b, $hasil));
                unset($belum[(int) $b['id_siasn_upload_arsip']]);
            }
        } catch (Throwable $e) {
            try {
                if ($belum !== []) {
                    $this->db->table('siasn_upload_arsip')->whereIn('id_siasn_upload_arsip', array_keys($belum))->where('status', 3)->update(['status' => 0, 'updated_at' => $this->sekarang()]);
                }
            } catch (Throwable $e2) {
                log_message('error', 'Unggah arsip SIASN: penanda proses gagal dikembalikan ({kelas}).', ['kelas' => $e2::class]);
            }

            $this->catat('unggah_arsip', CatatanIntegrasi::GAGAL, $e instanceof DatabaseException ? 'galat_db' : 'galat', $this->pesan($hasil));

            throw $e;
        }

        $semua = $hasil['sukses'] === $hasil['diproses'];
        $this->catat('unggah_arsip', $semua ? CatatanIntegrasi::SUKSES : CatatanIntegrasi::GAGAL, $semua ? null : 'sebagian_gagal', $this->pesan($hasil));

        return $hasil;
    }

    /**
     * Proses satu baris antrean → data UPDATE-nya.
     *
     * @param array<string, mixed>                                                                                            $b
     * @param array{diproses: int, sukses: int, gagal: int, berkas_hilang: int, tidak_sah: int, status_tidak_diketahui: int} $hasil
     *
     * @return array<string, mixed>
     */
    private function satu(array $b, array &$hasil): array
    {
        $idRwSiasn = trim((string) $b['id_rw_siasn']);

        if ($idRwSiasn === '') {
            $hasil['tidak_sah']++;

            return $this->gagal('Riwayat belum memiliki id SIASN.');
        }

        try {
            $berkas = $this->lampiran->ambil((string) $b['nip'], (int) $b['id_da']);
        } catch (Throwable) {
            $hasil['berkas_hilang']++;

            return $this->gagal('Berkas tidak ditemukan.');
        }

        $isi      = $berkas['isi'];
        $ukuran   = strlen($isi);
        $ukuranMb = round($ukuran / 1048576, 2);
        $nama     = basename(str_replace('\\', '/', (string) $b['arsip_path']));
        $ekstensi = strtolower(pathinfo($nama, PATHINFO_EXTENSION));

        if ($ekstensi !== 'pdf' || ! str_starts_with($isi, '%PDF') || $ukuran > self::BATAS_BYTE) {
            $hasil['tidak_sah']++;

            return $this->gagal("Invalid file type or file size, size: {$ukuranMb}MB");
        }

        $balasan = $this->siasn->unggahDokumen($idRwSiasn, self::SLUG[(string) $b['rw_name']] ?? '872', $isi, $nama, [(string) $b['nip']]);

        if ($balasan['status_code'] === SiasnAdapter::SUKSES && is_array($balasan['data'])) {
            $hasil['sukses']++;
            $d = $balasan['data'];

            return [
                'status'        => 1,
                'error_msg'     => null,
                'resp_dok_id'   => self::potong($d['dok_id'] ?? null, 100),
                'resp_dok_nama' => self::potong($d['dok_nama'] ?? null, 255),
                'resp_dok_uri'  => self::potong($d['dok_uri'] ?? null, 500),
                'resp_object'   => self::potong($d['object'] ?? null, 255),
                'resp_slug'     => self::potong($d['slug'] ?? null, 50),
                'updated_at'    => $this->sekarang(),
            ];
        }

        $hasil['gagal']++;

        if (in_array($balasan['status_code'], ['timeout', 'koneksi'], true)) {
            $hasil['status_tidak_diketahui']++;

            return $this->gagal("Timeout upload, size: {$ukuranMb}MB");
        }

        return $this->gagal("Failed to upload to SIASN, size: {$ukuranMb}MB");
    }

    /**
     * @return array<string, mixed>
     */
    private function gagal(string $pesan): array
    {
        return ['status' => 2, 'error_msg' => mb_substr($pesan, 0, 500), 'updated_at' => $this->sekarang()];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function tulis(int $id, array $data): void
    {
        $r = new RencanaTarik();
        $r->ubah('siasn_upload_arsip', ['id_siasn_upload_arsip' => $id], $data);
        (new PenulisTarik($this->db))->terapkan($r);
    }

    /**
     * Nilai respons mentah (tanpa diubah), dipotong sepanjang kolom; bukan skalar → NULL.
     */
    private static function potong(mixed $nilai, int $maks): ?string
    {
        return is_scalar($nilai) && (string) $nilai !== '' ? mb_substr((string) $nilai, 0, $maks) : null;
    }

    /**
     * @param array{diproses: int, sukses: int, gagal: int, berkas_hilang: int, tidak_sah: int, status_tidak_diketahui: int} $h
     */
    private function pesan(array $h): string
    {
        return "diproses {$h['diproses']}, sukses {$h['sukses']}, gagal {$h['gagal']}, berkas_hilang {$h['berkas_hilang']}, tidak_sah {$h['tidak_sah']}, status_tidak_diketahui {$h['status_tidak_diketahui']}";
    }

    private function catat(string $operasi, int $status, ?string $kode, string $pesan): void
    {
        $this->log->catat(new CatatanIntegrasi(CatatanIntegrasi::ARAH_KELUAR, SiasnAdapter::LAYANAN, $operasi, $status, null, $kode, $pesan));
    }

    private function sekarang(): string
    {
        return $this->jam === null ? gmdate('Y-m-d H:i:s') : ($this->jam)();
    }
}
