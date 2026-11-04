<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Dorong;

use App\Libraries\Integrasi\KonfigurasiIntegrasi;
use CodeIgniter\Database\BaseConnection;

/**
 * `siasn:dorong --jenis=jabatan` — padanan `SS/Rw_jabatan::upload_siasn` [K `Rw_jabatan.php:105-339`] (= `SI::upload_rw_jabatan`
 * 3804) → `POST jabatan/unorjabatan/save`, id = `mapData.rwJabatanId`.
 *
 * - Pilih [K `:138-143`]: `riwayat_mutasi_jabatan` aktif, `jenis_jabatan = 1`, kelompok 1/2/3, TMT ≥ 2020, satker baris
 *   dan `pegawai_mutasi_jabatan` ≠ 171, PNS aktif berpasangan SIASN; DAN (belum terkirim `id_rw_siasn IS NULL` + flag 0,
 *   ATAU diubah sesudah terkirim `siasn_lu < updated_at` — satu-satunya tabel dengan dorong ulang, CR-090, ATAU gagal/
 *   diproses (flag 2/3) yang stempelnya > 6 jam).
 * - Penanda proses flag 3 sebelum POST [K `:163-167`] tanpa menggeser `updated_at` (kolom `ON UPDATE CURRENT_TIMESTAMP`).
 * - Payload [K `:180-259`]: nama jabatan SIASN ({@see self::namaJabatan()} = `setJabatanJF`), JFT/JFU dari `siasn_referensi`
 *   per nama, jenis jabatan per kelompok (bawaan 4), unor dari `siasn_ref_unor` per jabatan → per unit+satker lvl 2 →
 *   `bkn.siasnUnorBawaan`, eselon per sub kelompok struktural, instansi = unor atau `bkn.siasnInstansiId`, satuan kerja =
 *   `bkn.siasnSatkerId` (padanan konstanta legacy `SIASN_INS_KEMENPAR` / `SIASN_SAT_KEMENPAR`, hanya di `.env`).
 * - Sukses → `siasn_lu = updated_at = sekarang` [K `:265-276`]; gagal → `id_rw_siasn` tetap, flag 2 (3 bila pesan
 *   "Internal Server Error" [K `:311-313`]), stempel sama.
 */
final class DorongJabatan extends DorongDasar
{
    /** Satker yang dikecualikan [K `:140`]. */
    public const SATKER_DIKECUALIKAN = 171;

    public const FLAG_PROSES = 3;

    /**
     * @param array<string, mixed> $bkn `Config\Integrations::$bkn`
     */
    public function __construct(private readonly array $bkn)
    {
    }

    public function nama(): string
    {
        return 'jabatan';
    }

    public function tabel(): string
    {
        return 'riwayat_mutasi_jabatan';
    }

    public function pk(): string
    {
        return 'id_riwayat_mutasi_jabatan';
    }

    public function jalur(): string
    {
        return 'jabatan/unorjabatan/save';
    }

    protected function kunciId(): string
    {
        return 'rwJabatanId';
    }

    public function flagProses(): int
    {
        return self::FLAG_PROSES;
    }

    public function pilih(BaseConnection $db, int $limit, ?string $nip, string $sekarang): array
    {
        KonfigurasiIntegrasi::pastikan('bkn', $this->bkn, ['siasnInstansiId', 'siasnSatkerId', 'siasnUnorBawaan']);

        $batas = gmdate('Y-m-d H:i:s', (int) strtotime($sekarang . ' UTC') - 6 * 3600);
        $qb    = $db->table('riwayat_mutasi_jabatan rw')
            ->select('rw.*, peg.id_pns_siasn AS pns_id, jab.id_jenjang_jf, jjf.jenjang_jf')
            ->join('pegawai peg', 'peg.nip = rw.nip', 'inner')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = rw.nip', 'inner')
            ->join('jabatan jab', 'jab.id_jabatan = rw.id_jabatan', 'left')
            ->join('jenjang_jf jjf', 'jjf.id_jenjang_jf = jab.id_jenjang_jf', 'left')
            ->where(['rw.status' => 1, 'rw.jenis_jabatan' => 1])
            ->whereIn('rw.id_group_jabatan', [1, 2, 3])
            ->where('rw.tmtsk >=', '2020-01-01')
            // Ikut legacy `!= '171'`: baris ber-satker NULL ikut terbuang (putusan reviewer B6 M2 Q10).
            ->where('rw.id_satker <>', self::SATKER_DIKECUALIKAN)->where('pmj.id_satker <>', self::SATKER_DIKECUALIKAN)
            ->groupStart()
            ->groupStart()->where('rw.id_rw_siasn IS NULL')->where('rw.siasn_flag', 0)->groupEnd()
            ->orWhere('rw.siasn_lu < rw.updated_at', null, false)
            ->orGroupStart()->whereIn('rw.siasn_flag', [2, 3])->groupStart()->where('rw.siasn_lu <', $batas)->orWhere('rw.siasn_lu IS NULL')->groupEnd()->groupEnd()
            ->groupEnd();
        $this->saringPegawai($qb, [1], $nip);

        return array_values($qb->orderBy('rw.nip', 'ASC')->orderBy('rw.id_riwayat_mutasi_jabatan', 'ASC')->limit($limit)->get()->getResultArray());
    }

    public function payload(BaseConnection $db, array $baris): array
    {
        $kelompok = (string) $baris['id_group_jabatan'];
        $jabatan  = self::namaJabatan($baris);
        $jft      = $kelompok === '2' ? $this->idPerNama($db, 'jabatan_fungsional', $jabatan) : '';
        $jfu      = $kelompok === '3' ? $this->idPerNama($db, 'jabatan_fungsional_umum', $jabatan) : '';
        $unor     = $this->unor($db, $baris);
        $instansi = trim((string) ($unor['instansi_id'] ?? '')) !== '' ? (string) $unor['instansi_id'] : (string) $this->bkn['siasnInstansiId'];
        $jenisJab = $this->referensiLokal($db, 'jenis_jabatan', $kelompok);
        $mutasi   = (string) $baris['jenis_mutasi'] === '2';
        $tmt      = $this->tanggal($baris['tmtsk'] ?? null, 'tmtsk', true);

        return [
            'eselonId'                => $this->eselon($baris, $unor),
            'id'                      => trim((string) $baris['id_rw_siasn']) !== '' ? (string) $baris['id_rw_siasn'] : null,
            'instansiId'              => $instansi,
            'instansiIndukId'         => $instansi,
            'jabatanFungsionalId'     => $jft,
            'jabatanFungsionalUmumId' => $jfu !== '' ? $jfu : null,
            'jenisJabatan'            => $jenisJab !== '' ? $jenisJab : '4',
            'jenisMutasiId'           => $mutasi ? 'MU' : 'MJ',
            'jenisPenugasanId'        => '',
            'nomorSk'                 => $this->wajib($baris['no_sk'] ?? null, 'no_sk'),
            'pnsId'                   => $this->wajib($baris['pns_id'] ?? null, 'id_pns_siasn'),
            'satuanKerjaId'           => (string) $this->bkn['siasnSatkerId'],
            'subJabatanId'            => $kelompok === '2' && (string) $baris['id_sub_group_jabatan'] === '14' ? '1102' : '',
            'tanggalSk'               => $this->tanggal($baris['tgl_sk'] ?? null, 'tgl_sk', false),
            'tmtJabatan'              => $mutasi ? '' : $tmt,
            'tmtMutasi'               => $mutasi ? $tmt : '',
            'tmtPelantikan'           => $mutasi ? '' : $tmt,
            'unorId'                  => trim((string) ($unor['id'] ?? '')) !== '' ? (string) $unor['id'] : $this->unorCadangan($db, $baris),
            'path'                    => null,
        ];
    }

    /** `siasn_error` TEXT; dibatasi agar tetap ringkas. */
    protected function errorMaksByte(): int
    {
        return 1000;
    }

    /** `keterangan` MEDIUMTEXT. */
    protected function keteranganMaksByte(): ?int
    {
        return null;
    }

    public function dataSukses(array $baris, ?string $idSiasn, int $idAkun, string $sekarang): array
    {
        // Review B6 M2 P-1: sukses tanpa id tidak menimpa pasangan lama (edit berikutnya tetap memperbarui riwayat yang sama).
        return ['siasn_lu' => $sekarang, 'updated_at' => $sekarang] + parent::dataSukses($baris, $idSiasn ?? $this->idLama($baris), $idAkun, $sekarang);
    }

    public function dataGagal(array $baris, ?string $kode, string $pesan, int $idAkun, string $sekarang): array
    {
        return [
            'id_rw_siasn' => $this->idLama($baris),
            'siasn_flag'  => $pesan === 'Internal Server Error' ? 3 : 2,
            'siasn_error' => mb_strcut($pesan, 0, $this->errorMaksByte()),
            'siasn_lu'    => $sekarang,
            'updated_at'  => $sekarang,
            'updated_by'  => $idAkun,
        ];
    }

    public function dataTidakTersimpan(array $baris, ?string $idSiasn, int $idAkun, string $sekarang): array
    {
        // Flag 3 jabatan dipilih lagi setelah 6 jam → tandai SELESAI (flag 1, siasn_lu = updated_at) agar tidak di-POST ulang.
        return ['id_rw_siasn' => $idSiasn ?? $this->idLama($baris), 'siasn_flag' => 1, 'siasn_error' => self::PESAN_TIDAK_TERSIMPAN, 'siasn_lu' => $sekarang, 'updated_at' => $sekarang];
    }

    /**
     * @param array<string, mixed> $baris
     */
    private function idLama(array $baris): ?string
    {
        return trim((string) $baris['id_rw_siasn']) !== '' ? (string) $baris['id_rw_siasn'] : null;
    }

    /**
     * Nama jabatan untuk pencarian referensi SIASN — padanan `setJabatanJF` [K `:9-102`], apa adanya.
     *
     * @param array<string, mixed> $r
     */
    public static function namaJabatan(array $r): string
    {
        $jabatan  = (string) ($r['jabatan'] ?? '');
        $kelompok = (string) ($r['id_group_jabatan'] ?? '');
        $sub      = (string) ($r['id_sub_group_jabatan'] ?? '');

        if ($kelompok === '2') {
            if ((string) ($r['sub_group_jabatan'] ?? '') !== '') {
                $jabatan = (string) $r['sub_group_jabatan'];

                if ($sub === '12') {
                    $asli    = (string) ($r['jabatan'] ?? '');
                    $jabatan = match (true) {
                        strtolower(substr($asli, 0, 6)) === 'calon ' => str_replace('Calon ', '', $asli),
                        strtolower($asli) === 'guru besar'           => 'Guru Besar / Profesor',
                        default                                      => $asli,
                    };
                } else {
                    $jabatan = self::PADANAN_SDM[strtolower($jabatan)] ?? $jabatan;
                }
            }

            if ((string) ($r['jenjang_jf'] ?? '') !== '' && $sub !== '12') {
                $jabatan .= ' ' . $r['jenjang_jf'];
            }
        } elseif ($kelompok === '3') {
            $jabatan = self::PADANAN_PELAKSANA[strtolower($jabatan)] ?? $jabatan;
        }

        return $jabatan;
    }

    /** [K `:30-41`] */
    private const PADANAN_SDM = [
        'analis sdm aparatur'  => 'Analis Sumber Daya Manusia Aparatur',
        'pranata sdm aparatur' => 'Pranata Sumber Daya Manusia Aparatur',
        'assesor sdm aparatur' => 'Assessor Sumber Daya Manusia Aparatur',
    ];

    /** [K `:51-98`] */
    private const PADANAN_PELAKSANA = self::PADANAN_SDM + [
        'penyusun bahan laporan pemantauan evaluasi'                => 'PENYUSUN BAHAN LAPORAN DAN HASIL EVALUASI',
        'pengadministrasi perecananaan dan program'                 => 'PENGADMINISTRASI RENCANA DAN PROGRAM',
        'analis anggaran rencana program'                           => 'ANALIS ANGGARAN, PERENCANAAN DAN EVALUASI',
        'analis hukum advokasi hukum'                               => 'ANALIS HUKUM',
        'penyusun bahan publikasi kepariwisataan'                   => 'PENYUSUN BAHAN PUBLIKASI',
        'analis pelaporan penatausahaan barang milik negara (bmn)'  => 'ANALIS LAPORAN BARANG MILIK NEGARA',
        'penyusun bahan penatausahaan bmn'                          => 'PENYUSUN BAHAN PENGELOLAAN BARANG MILIK NEGARA',
        'pengolah daya pengelolaan sistem informasi perbendaharaan' => 'PENGOLAH DATA PENGELOLAAN SISTEM INFORMASI PERBENDAHARAAN',
        'analis data dan kerjasama diklat'                          => 'ANALIS DATA DAN KERJA SAMA DIKLAT',
        'fasilitator pemerintah'                                    => 'FASILITATOR PEMERINTAHAN',
        'pns tugas belajar s1'                                      => 'PNS TUGAS BELAJAR',
        'pns tugas belajar s2'                                      => 'PNS TUGAS BELAJAR',
        'pns tugas belajar s3'                                      => 'PNS TUGAS BELAJAR',
    ];

    private function idPerNama(BaseConnection $db, string $jenis, string $nama): string
    {
        $baris = $db->table('siasn_referensi')->select('id_siasn')->where(['jenis' => $jenis, 'nama' => $nama])->orderBy('id_siasn', 'ASC')->get(1)->getRowArray();

        return $baris === null ? '' : (string) $baris['id_siasn'];
    }

    /**
     * Unor SIASN yang dipetakan ke jabatan baris ini [K JOIN `REF_UNOR.id_jabatan`].
     *
     * @param array<string, mixed> $baris
     *
     * @return array<string, mixed>|null
     */
    private function unor(BaseConnection $db, array $baris): ?array
    {
        if ($baris['id_jabatan'] === null) {
            return null;
        }

        return $db->table('siasn_ref_unor')->select('id, eselon_id, instansi_id')->where('id_jabatan', (int) $baris['id_jabatan'])->orderBy('id', 'ASC')->get(1)->getRowArray();
    }

    /**
     * Unor per unit + satker level 2, lalu bawaan [K `:211-219`].
     *
     * @param array<string, mixed> $baris
     */
    private function unorCadangan(BaseConnection $db, array $baris): string
    {
        $unor = $db->table('siasn_ref_unor')->select('id')
            ->where(['id_unit' => $baris['id_unit'], 'id_satker' => $baris['id_satker'], 'lvl_uker' => 2])->orderBy('id', 'ASC')->get(1)->getRowArray();

        return $unor !== null && (string) $unor['id'] !== '' ? (string) $unor['id'] : (string) $this->bkn['siasnUnorBawaan'];
    }

    /**
     * Eselon unor, ditimpa untuk jabatan struktural per sub kelompok 1/2/3 → 10/21/31 [K `:223-234`].
     *
     * @param array<string, mixed>      $baris
     * @param array<string, mixed>|null $unor
     */
    private function eselon(array $baris, ?array $unor): ?string
    {
        $eselon = $unor !== null && trim((string) ($unor['eselon_id'] ?? '')) !== '' ? (string) $unor['eselon_id'] : null;

        if ((string) $baris['id_group_jabatan'] === '1') {
            $eselon = match ((string) $baris['id_sub_group_jabatan']) {
                '1'     => '10',
                '2'     => '21',
                '3'     => '31',
                default => $eselon,
            };
        }

        return $eselon;
    }
}
