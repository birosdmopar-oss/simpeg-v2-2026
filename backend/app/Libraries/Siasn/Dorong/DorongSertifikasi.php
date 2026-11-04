<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Dorong;

use CodeIgniter\Database\BaseConnection;

/**
 * `siasn:dorong --jenis=sertifikasi` — padanan `SS/Rw_pelatihan::upload_rw_sertifikasi` [K `Rw_pelatihan.php:326-403`]:
 * sertifikasi (`riwayat_diklat.jenis_diklat = 5`) aktif dengan rumpun & lembaga sertifikasi di master (INNER JOIN [K]),
 * belum pernah terkirim (`id_rw_siasn IS NULL` + `siasn_flag = 0`, CR-090), pegawai aktif berpasangan SIASN (tanpa saring
 * jenis pegawai [K]) → `POST sertifikasi/save`, id = `mapData.rwSertifikasiId`. Nama kunci `masaBerlakuSertSelasai` ikut
 * API BKN apa adanya [K]. Tanggal sertifikat dan masa berlaku wajib (putusan reviewer B6 M2 Q4; legacy memakai hari ini).
 */
final class DorongSertifikasi extends DorongDasar
{
    public function nama(): string
    {
        return 'sertifikasi';
    }

    public function tabel(): string
    {
        return 'riwayat_diklat';
    }

    public function pk(): string
    {
        return 'id_riwayat_diklat';
    }

    public function jalur(): string
    {
        return 'sertifikasi/save';
    }

    protected function kunciId(): string
    {
        return 'rwSertifikasiId';
    }

    public function pilih(BaseConnection $db, int $limit, ?string $nip, string $sekarang): array
    {
        $qb = $db->table('riwayat_diklat rw')
            ->select('rw.*, peg.id_pns_siasn AS pns_id, rs.id_rumpun_sertifikasi AS rumpun_id, ls.id_lembaga_sertifikasi AS lembaga_id, ls.lembaga_sertifikasi AS lembaga_nama')
            ->join('pegawai peg', 'peg.nip = rw.nip', 'inner')
            ->join('rumpun_sertifikasi rs', 'rs.id_rumpun_sertifikasi = rw.id_rumpun_sertifikasi', 'inner')
            ->join('lembaga_sertifikasi ls', 'ls.id_lembaga_sertifikasi = rw.id_lembaga_sertifikasi', 'inner')
            ->where(['rw.status' => 1, 'rw.jenis_diklat' => 5, 'rw.siasn_flag' => 0])->where('rw.id_rw_siasn IS NULL');
        $this->saringPegawai($qb, null, $nip);

        return array_values($qb->orderBy('rw.id_riwayat_diklat', 'ASC')->limit($limit)->get()->getResultArray());
    }

    public function payload(BaseConnection $db, array $baris): array
    {
        return [
            'gelarBelakangSert'      => '',
            'gelarDepanSert'         => '',
            'lembagaSertifikasiId'   => (string) $baris['lembaga_id'],
            'lembagaSertifikasiNama' => (string) $baris['lembaga_nama'],
            'masaBerlakuSertMulai'   => $this->tanggal($baris['mb_sertifikat_awal'] ?? null, 'mb_sertifikat_awal', true),
            'masaBerlakuSertSelasai' => $this->tanggal($baris['mb_sertifikat_akhir'] ?? null, 'mb_sertifikat_akhir', true),
            'namaSertifikasi'        => trim((string) ($baris['nama_diklat'] ?? '')),
            'nomorSertifikat'        => trim((string) ($baris['no_sertifikat'] ?? '')),
            'pnsOrangId'             => $this->wajib($baris['pns_id'] ?? null, 'id_pns_siasn'),
            'rumpunJabatanId'        => (string) $baris['rumpun_id'],
            'tanggalSertifikat'      => $this->tanggal($baris['tgl_sertifikat'] ?? null, 'tgl_sertifikat', true),
            'path'                   => null,
        ];
    }
}
