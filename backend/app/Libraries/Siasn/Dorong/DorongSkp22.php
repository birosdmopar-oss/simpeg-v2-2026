<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Dorong;

use CodeIgniter\Database\BaseConnection;

/**
 * `siasn:dorong --jenis=skp22` — padanan `SS/Rw_skp::upload_rw_skp22` [K `Rw_skp.php:524-618`] (= `SI::upload_rw_skp22`
 * 7704): SKP tahun 2022 aktif dengan rating & kuadran terisi, belum pernah terkirim (`id_rw_siasn IS NULL` +
 * `siasn_flag = 0`, CR-090), PNS aktif berpasangan → `POST skp22/save`; id = `mapData` itu sendiri [K `:579`].
 *
 * - rating lokal → SIASN 3↔1, 1↔3, lainnya 2 [K `:548-549`]; kuadran per nama [K `:528-534`];
 * - kuadran tidak dikenal → tidak dikirim, `siasn_flag = 3` + "Data Invalid, kategori_nilai_prestasi:…" [K `:599-607`];
 * - penilai: golongan SIASN dari `pegawai_kp` atasan penilai (via `siasn_referensi` jenis `golongan`), unor = satker
 *   `pegawai_mutasi_jabatan` atasan penilai [K `:551-569`].
 */
final class DorongSkp22 extends DorongDasar
{
    /** Kuadran lokal → nilai SIASN [K]. */
    public const KUADRAN = ['Sangat Baik' => 1, 'Baik' => 2, 'Butuh Perbaikan' => 3, 'Kurang / Miss Conduct' => 4, 'Sangat Kurang' => 5];

    public function nama(): string
    {
        return 'skp22';
    }

    public function tabel(): string
    {
        return 'riwayat_skp';
    }

    public function pk(): string
    {
        return 'id_riwayat_skp';
    }

    public function jalur(): string
    {
        return 'skp22/save';
    }

    protected function kunciId(): ?string
    {
        return null;
    }

    public function pilih(BaseConnection $db, int $limit, ?string $nip, string $sekarang): array
    {
        $qb = $db->table('riwayat_skp rw')->select('rw.*, peg.id_pns_siasn AS pns_id')
            ->join('pegawai peg', 'peg.nip = rw.nip', 'inner')
            ->where(['rw.status' => 1, 'rw.tahun' => 2022, 'rw.siasn_flag' => 0])
            ->where('rw.rating_skp IS NOT NULL')->where('rw.rating_perilaku IS NOT NULL')->where('rw.kategori_nilai_prestasi <>', '')
            ->where('rw.id_rw_siasn IS NULL');
        $this->saringPegawai($qb, [1], $nip);

        return array_values($qb->orderBy('rw.id_riwayat_skp', 'DESC')->limit($limit)->get()->getResultArray());
    }

    public function payload(BaseConnection $db, array $baris): array
    {
        $kategori = (string) $baris['kategori_nilai_prestasi'];

        if (! isset(self::KUADRAN[$kategori])) {
            throw new PayloadTidakSah('Data Invalid, kategori_nilai_prestasi:' . mb_substr($kategori, 0, 50));
        }

        $nipPenilai = trim((string) ($baris['nip_atasan_penilai'] ?? ''));
        $penilai    = $nipPenilai === '' ? null : $db->table('pegawai_kp pkp')->select('pkp.id_pangkat, pmj.satker')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = pkp.nip', 'left')
            ->where('pkp.nip', $nipPenilai)->get(1)->getRowArray();

        return [
            'id'                  => null,
            'tahun'               => (int) $baris['tahun'],
            'hasilKinerjaNilai'   => self::rating($baris['rating_skp']),
            'perilakuKerjaNilai'  => self::rating($baris['rating_perilaku']),
            'kuadranKinerjaNilai' => self::KUADRAN[$kategori],
            'path'                => null,
            'penilaiGolongan'     => $penilai === null ? '' : $this->referensiLokal($db, 'golongan', $penilai['id_pangkat'] ?? null),
            'penilaiJabatan'      => $baris['jabatan_atasan_penilai'],
            'penilaiNama'         => $baris['nama_atasan_penilai'],
            'penilaiNipNrp'       => $baris['nip_atasan_penilai'],
            'penilaiUnorNama'     => $penilai !== null && trim((string) ($penilai['satker'] ?? '')) !== '' ? (string) $penilai['satker'] : '',
            'pnsDinilaiOrang'     => $this->wajib($baris['pns_id'] ?? null, 'id_pns_siasn'),
            'statusPenilai'       => 'ASN',
        ];
    }

    public function dataGagal(array $baris, ?string $kode, string $pesan, int $idAkun, string $sekarang): array
    {
        // Payload tidak sah (kuadran tidak dikenal) → flag 3 [K `:603`]; gagal kirim → flag 2 seperti lainnya.
        return ['siasn_flag' => $kode === null ? 3 : 2] + parent::dataGagal($baris, $kode, $pesan, $idAkun, $sekarang);
    }

    /**
     * Rating lokal → SIASN: 3 → 1, 1 → 3, lainnya 2 [K].
     */
    private static function rating(mixed $lokal): int
    {
        return match ((string) $lokal) {
            '3'     => 1,
            '1'     => 3,
            default => 2,
        };
    }
}
