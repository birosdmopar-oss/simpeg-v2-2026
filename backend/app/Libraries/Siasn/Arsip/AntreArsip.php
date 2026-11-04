<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Arsip;

use App\Libraries\Kepegawaian\Lampiran\PathLegacy;
use App\Libraries\Siasn\Dorong\DorongJabatan;
use App\Libraries\Siasn\Tarik\PenulisTarik;
use App\Libraries\Siasn\Tarik\RencanaTarik;
use CodeIgniter\Database\BaseConnection;

/**
 * Antrean unggah dokumen riwayat ke SIASN (`siasn_upload_arsip`, DBV-027 §3.3) — padanan `SS/Rw_jabatan::set_queue_arsip`
 * [K `Rw_jabatan.php:569-684`] dan `SS/Rw_pelatihan::set_queue_arsip_sertifikasi` [K `Rw_pelatihan.php:406-509`]:
 *
 * - Jabatan: riwayat yang SUDAH terkirim (`id_rw_siasn` terisi), aktif, `jenis_jabatan = 1`, kelompok 1/2/3, TMT ≥ 2020,
 *   satker baris & `pegawai_mutasi_jabatan` ≠ 171, PNS aktif berpasangan SIASN × lampiran `jenis_rwy` 9 (SK Jabatan) /
 *   36 (SK Jabatan JFT [K label]) / 41 (Perjanjian Kerja) berekstensi PDF;
 * - Sertifikasi: `riwayat_diklat.jenis_diklat = 5` yang sudah terkirim, PNS aktif × lampiran `jenis_rwy` 5;
 * - kunci anti-antre-ulang legacy `nip***id_rw***arsip_path` DAN UNIQUE (`rw_name`,`id_rw`,`id_da`) dicek lebih dulu di memori,
 *   lalu INSERT biasa (bukan IGNORE; pelanggaran UNIQUE akibat proses lain = galat DB, batch antre di-rollback);
 *   `arsip_path` = `document_attachment.path` apa adanya; hanya path yang lolos {@see PathLegacy::normalisasi()}.
 * - DEVIASI (putusan reviewer B6 M3 Q2(b)): saring ukuran legacy `file_size < 1200` [K `Rw_jabatan:603`] tidak dipakai
 *   saat antre karena unit `document_attachment.file_size` campuran (baris impor legacy KB, unggahan v2 byte); antre hanya
 *   menyaring PDF, ukuran diperiksa dari ISI berkas saat unggah (≤ 1 MB [K `Arsip:87`], {@see UnggahArsip}).
 */
final class AntreArsip
{
    public const RW_JABATAN = 'Rw Jabatan';

    public const RW_SERTIFIKASI = 'Rw Sertifikasi';

    /** Kode `jenis_rwy` (= `ARSIP_RWY` legacy) → `arsip_name` [K `Rw_jabatan:639`]. */
    private const LAMPIRAN_JABATAN = [9 => 'SK Jabatan', 36 => 'SK Jabatan JFT', 41 => 'Perjanjian Kerja'];

    private const LAMPIRAN_DIKLAT = 5;

    private const NIP_DILEWATI = ['-', '1'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return array{baru: int, sudah_ada: int, path_tidak_sah: int}
     */
    public function jalankan(bool $dryRun, string $sekarang): array
    {
        $hasil   = ['baru' => 0, 'sudah_ada' => 0, 'path_tidak_sah' => 0];
        $rencana = new RencanaTarik();

        foreach ([self::RW_JABATAN, self::RW_SERTIFIKASI] as $rw) {
            $ada = [];

            foreach ($this->db->table('siasn_upload_arsip')->select('nip, id_rw, id_da, arsip_path')->where('rw_name', $rw)->get()->getResultArray() as $b) {
                $ada["{$b['nip']}***{$b['id_rw']}***{$b['arsip_path']}"] = true;
                $ada["da***{$b['id_rw']}***{$b['id_da']}"]               = true;
            }

            $lampiran = $this->lampiran($rw === self::RW_JABATAN ? array_keys(self::LAMPIRAN_JABATAN) : [self::LAMPIRAN_DIKLAT]);

            foreach ($rw === self::RW_JABATAN ? $this->riwayatJabatan() : $this->riwayatSertifikasi() as $r) {
                foreach ($lampiran[$r['nip']][(string) $r['id_rw']] ?? [] as $da) {
                    if (PathLegacy::normalisasi((string) $da['path']) === null) {
                        $hasil['path_tidak_sah']++;

                        continue;
                    }

                    $kunci = "{$r['nip']}***{$r['id_rw']}***{$da['path']}";

                    if (isset($ada[$kunci]) || isset($ada["da***{$r['id_rw']}***{$da['id_attachment']}"])) {
                        $hasil['sudah_ada']++;

                        continue;
                    }

                    $rencana->sisip('siasn_upload_arsip', [
                        'nip'         => $r['nip'],
                        'rw_name'     => $rw,
                        'id_rw'       => (int) $r['id_rw'],
                        'id_rw_siasn' => $r['id_rw_siasn'],
                        'id_da'       => (int) $da['id_attachment'],
                        'arsip_path'  => $da['path'],
                        'arsip_name'  => $rw === self::RW_JABATAN
                            ? self::LAMPIRAN_JABATAN[(int) $da['id_riwayat']]
                            : mb_substr('Sertifikat ' . (string) $r['nama_diklat'], 0, 255),
                        'status'     => 0,
                        'created_at' => $sekarang,
                        'updated_at' => $sekarang,
                    ]);
                    $ada[$kunci] = true;
                    $hasil['baru']++;
                }
            }
        }

        if (! $dryRun) {
            (new PenulisTarik($this->db))->terapkan($rencana);
        }

        return $hasil;
    }

    /**
     * Lampiran PDF milik PNS aktif berpasangan SIASN, per NIP → id_entri.
     *
     * @param list<int> $kode
     *
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private function lampiran(array $kode): array
    {
        $hasil = [];

        foreach ($this->db->table('document_attachment da')->select('da.id_attachment, da.NIP AS nip, da.id_riwayat, da.id_entri, da.path, da.file_ext')
            ->join('pegawai peg', 'peg.nip = da.NIP', 'inner')
            ->where(['peg.status' => 1, 'peg.id_jenis_pegawai' => 1])->whereNotIn('peg.nip', self::NIP_DILEWATI)->where('peg.id_pns_siasn IS NOT NULL')
            ->whereIn('da.id_riwayat', $kode)->orderBy('da.id_attachment', 'ASC')->get()->getResultArray() as $da) {
            if (strtolower(ltrim(trim((string) $da['file_ext']), '.')) !== 'pdf' || trim((string) $da['path']) === '') {
                continue;
            }

            $hasil[(string) $da['nip']][(string) $da['id_entri']][] = $da;
        }

        return $hasil;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function riwayatJabatan(): array
    {
        return $this->db->table('riwayat_mutasi_jabatan rw')->select('rw.nip, rw.id_riwayat_mutasi_jabatan AS id_rw, rw.id_rw_siasn')
            ->join('pegawai peg', 'peg.nip = rw.nip', 'inner')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = rw.nip', 'inner')
            ->where(['peg.status' => 1, 'peg.id_jenis_pegawai' => 1, 'rw.status' => 1, 'rw.jenis_jabatan' => 1])
            ->whereNotIn('peg.nip', self::NIP_DILEWATI)->where('peg.id_pns_siasn IS NOT NULL')
            ->where('rw.id_rw_siasn IS NOT NULL')->where('rw.id_rw_siasn <>', '')
            ->where('rw.id_satker <>', DorongJabatan::SATKER_DIKECUALIKAN)->where('pmj.id_satker <>', DorongJabatan::SATKER_DIKECUALIKAN)
            ->whereIn('rw.id_group_jabatan', [1, 2, 3])->where('rw.tmtsk >=', '2020-01-01')
            ->orderBy('rw.id_riwayat_mutasi_jabatan', 'ASC')->get()->getResultArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function riwayatSertifikasi(): array
    {
        return $this->db->table('riwayat_diklat rw')->select('rw.nip, rw.id_riwayat_diklat AS id_rw, rw.id_rw_siasn, rw.nama_diklat')
            ->join('pegawai peg', 'peg.nip = rw.nip', 'inner')
            ->where(['peg.status' => 1, 'peg.id_jenis_pegawai' => 1, 'rw.status' => 1, 'rw.jenis_diklat' => 5])
            ->whereNotIn('peg.nip', self::NIP_DILEWATI)->where('peg.id_pns_siasn IS NOT NULL')
            ->where('rw.id_rw_siasn IS NOT NULL')->where('rw.id_rw_siasn <>', '')
            ->orderBy('rw.id_riwayat_diklat', 'ASC')->get()->getResultArray();
    }
}
