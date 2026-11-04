<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Dorong;

use CodeIgniter\Database\BaseConnection;

/**
 * Satu jenis dorong SIASN (`siasn:dorong --jenis=…`, PRD Fase 6 §7.3; padanan satu `upload_*` legacy). Memilih baris,
 * menyusun payload (tervalidasi), dan menyusun data hasil; POST, kunci, transaksi, dan ringkasan diurus {@see DorongSiasn}.
 */
interface JenisDorongSiasn
{
    public function nama(): string;

    public function tabel(): string;

    public function pk(): string;

    /** Path relatif API (mis. `diklat/save`). */
    public function jalur(): string;

    /**
     * Baris yang perlu didorong [K kriteria per tabel, CR-090]. Setiap baris memuat `pk()`, `nip`, `siasn_flag`, `pns_id`.
     *
     * @return list<array<string, mixed>>
     */
    public function pilih(BaseConnection $db, int $limit, ?string $nip, string $sekarang): array;

    /**
     * Flag "sedang diproses" yang ditulis sebelum POST [K jabatan `3`]; null = tanpa penanda.
     */
    public function flagProses(): ?int;

    /**
     * @param array<string, mixed> $baris
     *
     * @return array<string, mixed>
     *
     * @throws PayloadTidakSah
     */
    public function payload(BaseConnection $db, array $baris): array;

    /**
     * Id riwayat SIASN dari `mapData` balasan sukses; null bila tidak ada / tidak sah.
     */
    public function idDariBalasan(mixed $mapData): ?string;

    /**
     * Data UPDATE sesudah balasan sukses.
     *
     * @param array<string, mixed> $baris
     *
     * @return array<string, mixed>
     */
    public function dataSukses(array $baris, ?string $idSiasn, int $idAkun, string $sekarang): array;

    /**
     * Data UPDATE sesudah gagal (kode `status_code` adapter) atau payload tidak sah (`$kode` = null).
     *
     * @param array<string, mixed> $baris
     *
     * @return array<string, mixed>
     */
    public function dataGagal(array $baris, ?string $kode, string $pesan, int $idAkun, string $sekarang): array;
}
