<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Dorong;

use CodeIgniter\Database\BaseConnection;

/**
 * `siasn:dorong --jenis=diklat` — padanan `SS/Rw_pelatihan::upload_rw_diklat` [K `Rw_pelatihan.php:164-253`]: diklat
 * struktural (`jenis_diklat = 1`) aktif, belum pernah terkirim (`id_rw_siasn IS NULL` + `siasn_flag = 0`, CR-090), bukan
 * hasil tarik (`keterangan` ≠ "Sync SIASN"; baris tanpa keterangan IKUT — putusan reviewer B6 M2 Q3, DEVIASI atas bug
 * semantik NULL legacy: `keterangan != 'Sync SIASN'` membuang baris ber-keterangan NULL), PNS aktif berpasangan →
 * `POST diklat/save`, id = `mapData.rwDiklatId`. Latihan struktural dari `siasn_referensi` jenis `latihan_struktural`
 * (`id_diklat` ≠ 8); bawaan penyelenggara "LAN RI", jam 360, nomor `DIKLATPIM/<tahun>`. Tanggal sertifikat wajib
 * (putusan Q4).
 */
final class DorongDiklat extends DorongDasar
{
    public function nama(): string
    {
        return 'diklat';
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
        return 'diklat/save';
    }

    protected function kunciId(): string
    {
        return 'rwDiklatId';
    }

    public function pilih(BaseConnection $db, int $limit, ?string $nip, string $sekarang): array
    {
        $qb = $db->table('riwayat_diklat rw')->select('rw.*, peg.id_pns_siasn AS pns_id')
            ->join('pegawai peg', 'peg.nip = rw.nip', 'inner')
            ->where(['rw.status' => 1, 'rw.jenis_diklat' => 1, 'rw.siasn_flag' => 0])->where('rw.id_rw_siasn IS NULL')
            ->groupStart()->where('rw.keterangan IS NULL')->orWhere('rw.keterangan <>', 'Sync SIASN')->groupEnd();
        $this->saringPegawai($qb, [1], $nip);

        return array_values($qb->orderBy('rw.id_riwayat_diklat', 'ASC')->limit($limit)->get()->getResultArray());
    }

    public function payload(BaseConnection $db, array $baris): array
    {
        $tanggal  = $this->tanggal($baris['tgl_sertifikat'] ?? null, 'tgl_sertifikat', true);
        $tahun    = (int) substr($tanggal, 6, 4);
        $jam      = (int) ($baris['jumlah_jp'] ?? 0);
        $nomor    = trim((string) ($baris['no_sertifikat'] ?? ''));
        $instansi = trim((string) ($baris['instansi_penyelenggara'] ?? ''));
        $idDiklat = $baris['id_diklat'] ?? null;
        $latihan  = (string) $idDiklat === '8' ? '' : $this->referensiLokal($db, 'latihan_struktural', is_scalar($idDiklat) ? (string) $idDiklat : null);

        return [
            'bobot'                  => 0,
            'id'                     => null,
            'institusiPenyelenggara' => $instansi !== '' ? $instansi : 'LAN RI',
            'jenisKompetensi'        => '',
            'jumlahJam'              => $jam > 0 ? $jam : 360,
            'latihanStrukturalId'    => $latihan !== '' ? $latihan : null,
            'nomor'                  => $nomor !== '' ? $nomor : "DIKLATPIM/{$tahun}",
            'pnsOrangId'             => $this->wajib($baris['pns_id'] ?? null, 'id_pns_siasn'),
            'tahun'                  => $tahun,
            'tanggal'                => $tanggal,
            'tanggalSelesai'         => $tanggal,
            'path'                   => null,
        ];
    }
}
