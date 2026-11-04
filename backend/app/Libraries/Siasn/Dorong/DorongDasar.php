<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Dorong;

use CodeIgniter\Database\BaseConnection;

/**
 * Perilaku bersama dorong legacy [K `upload_*`]: sukses → `id_rw_siasn` (bila sah), `siasn_flag = 1`, `siasn_error`
 * NULL, `keterangan` + " **Upload To SIASN**"; gagal → `id_rw_siasn` tetap (jabatan) / NULL, `siasn_flag = 2`,
 * `siasn_error` pesan aman (tanpa payload). `updated_by` = akun sistem (putusan reviewer B6 Q6).
 */
abstract class DorongDasar implements JenisDorongSiasn
{
    public const PENANDA = '**Upload To SIASN**';

    protected const NIP_DILEWATI = ['-', '1'];

    /** Panjang kolom `siasn_error` (TINYTEXT di riwayat_diklat/seminar/skp). */
    protected const ERROR_MAKS = 255;

    /** Kunci id di `mapData`; null = `mapData` itu sendiri id-nya (skp22). */
    abstract protected function kunciId(): ?string;

    public function flagProses(): ?int
    {
        return null;
    }

    public function idDariBalasan(mixed $mapData): ?string
    {
        $id = $this->kunciId() === null ? $mapData : (is_array($mapData) ? ($mapData[$this->kunciId()] ?? null) : null);

        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        $id = trim((string) $id);

        return $id === '' || mb_strlen($id) > 100 ? null : $id;
    }

    public function dataSukses(array $baris, ?string $idSiasn, int $idAkun, string $sekarang): array
    {
        $keterangan = trim((string) ($baris['keterangan'] ?? ''));

        return [
            'id_rw_siasn' => $idSiasn,
            'siasn_flag'  => 1,
            'siasn_error' => null,
            'keterangan'  => $keterangan !== '' ? "{$keterangan} " . self::PENANDA : self::PENANDA,
            'updated_by'  => $idAkun,
        ];
    }

    public function dataGagal(array $baris, ?string $kode, string $pesan, int $idAkun, string $sekarang): array
    {
        return [
            'id_rw_siasn' => null,
            'siasn_flag'  => 2,
            'siasn_error' => mb_substr($pesan, 0, self::ERROR_MAKS),
            'updated_by'  => $idAkun,
        ];
    }

    /**
     * Saring pegawai aktif yang sudah berpasangan SIASN [K]; `$jenisPegawai` null = semua jenis.
     *
     * @param \CodeIgniter\Database\BaseBuilder $qb
     * @param list<int>|null                    $jenisPegawai
     */
    protected function saringPegawai($qb, ?array $jenisPegawai, ?string $nip): void
    {
        $qb->where('peg.status', 1)->whereNotIn('peg.nip', self::NIP_DILEWATI)->where('peg.id_pns_siasn IS NOT NULL');

        if ($jenisPegawai !== null) {
            $qb->whereIn('peg.id_jenis_pegawai', $jenisPegawai);
        }

        if ($nip !== null) {
            $qb->where('peg.nip', $nip);
        }
    }

    /**
     * `Y-m-d` lokal → `d-m-Y` SIASN; kosong/tidak sah → {@see PayloadTidakSah} bila wajib, '' bila tidak.
     */
    protected function tanggal(mixed $nilai, string $kolom, bool $wajib): string
    {
        $teks = is_string($nilai) ? substr(trim($nilai), 0, 10) : '';

        if ($teks === '' || $teks === '0000-00-00' || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $teks, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            if ($wajib) {
                throw new PayloadTidakSah("Data tidak lengkap: {$kolom}");
            }

            return '';
        }

        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }

    /**
     * Teks wajib (tidak kosong).
     */
    protected function wajib(mixed $nilai, string $kolom): string
    {
        $teks = is_scalar($nilai) ? trim((string) $nilai) : '';

        if ($teks === '') {
            throw new PayloadTidakSah("Data tidak lengkap: {$kolom}");
        }

        return $teks;
    }

    /**
     * Id SIASN dari `siasn_referensi` (`jenis` + `id_lokal`), '' bila tidak ada.
     */
    protected function referensiLokal(BaseConnection $db, string $jenis, int|string|null $idLokal): string
    {
        if ($idLokal === null || (string) $idLokal === '') {
            return '';
        }

        $baris = $db->table('siasn_referensi')->select('id_siasn')->where(['jenis' => $jenis, 'id_lokal' => (string) $idLokal])
            ->orderBy('id_siasn', 'ASC')->get(1)->getRowArray();

        return $baris === null ? '' : (string) $baris['id_siasn'];
    }
}
