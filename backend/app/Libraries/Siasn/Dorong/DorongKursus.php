<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Dorong;

use CodeIgniter\Database\BaseConnection;

/**
 * `siasn:dorong --jenis=kursus` — padanan `SI::upload_rw_kursus` [K `Siasn.php:4848-4955`] (versi `SS/Rw_pelatihan:9`
 * mati: `debug(); exit()`): `riwayat_seminar` aktif, belum pernah terkirim (`id_rw_siasn IS NULL` + `siasn_flag = 0`,
 * tanpa dorong ulang — CR-090), PNS aktif berpasangan SIASN → `POST kursus/save`, id = `mapData.rwKursusId`.
 * Seminar (`jenis_seminar = 1`) → jenis diklat 9 "SEMINAR/WORKSHOP/SEJENISNYA", lainnya 3 "DIKLAT TEKNIS"; jam bawaan 30;
 * nomor bawaan `SERT/<tahun>`. Tanggal sertifikat wajib (legacy memakai tanggal hari ini bila kosong — putusan reviewer B6 M2 Q4).
 */
final class DorongKursus extends DorongDasar
{
    public function nama(): string
    {
        return 'kursus';
    }

    public function tabel(): string
    {
        return 'riwayat_seminar';
    }

    public function pk(): string
    {
        return 'id_riwayat_seminar';
    }

    public function jalur(): string
    {
        return 'kursus/save';
    }

    protected function kunciId(): string
    {
        return 'rwKursusId';
    }

    public function pilih(BaseConnection $db, int $limit, ?string $nip, string $sekarang): array
    {
        $qb = $db->table('riwayat_seminar rw')->select('rw.*, peg.id_pns_siasn AS pns_id')
            ->join('pegawai peg', 'peg.nip = rw.nip', 'inner')
            ->where(['rw.status' => 1, 'rw.siasn_flag' => 0])->where('rw.id_rw_siasn IS NULL');
        $this->saringPegawai($qb, [1], $nip);

        return array_values($qb->orderBy('rw.id_riwayat_seminar', 'DESC')->limit($limit)->get()->getResultArray());
    }

    public function payload(BaseConnection $db, array $baris): array
    {
        $tanggal  = $this->tanggal($baris['tgl_sertifikat'] ?? null, 'tgl_sertifikat', true);
        $tahun    = (int) substr($tanggal, 6, 4);
        $seminar  = (string) $baris['jenis_seminar'] === '1';
        $jam      = (int) ($baris['jumlah_jp'] ?? 0);
        $nomor    = trim((string) ($baris['no_sertifikat'] ?? ''));
        $instansi = trim((string) ($baris['instansi_penyelenggara'] ?? ''));

        return [
            'id'                     => null,
            'institusiPenyelenggara' => $instansi !== '' ? $instansi : 'KEMENPAR',
            'jenisDiklatId'          => $seminar ? '9' : '3',
            'jenisKursus'            => '',
            'jenisKursusSertipikat'  => $seminar ? 'SEMINAR/WORKSHOP/SEJENISNYA' : 'DIKLAT TEKNIS',
            'jumlahJam'              => $jam > 0 ? $jam : 30,
            'lokasiId'               => '',
            'namaKursus'             => $this->wajib($baris['nama_seminar'] ?? null, 'nama_seminar'),
            'nomorSertipikat'        => $nomor !== '' ? $nomor : "SERT/{$tahun}",
            'path'                   => null,
            'pnsOrangId'             => $this->wajib($baris['pns_id'] ?? null, 'id_pns_siasn'),
            'tahunKursus'            => $tahun,
            'tanggalKursus'          => $tanggal,
            'tanggalSelesaiKursus'   => $tanggal,
        ];
    }
}
