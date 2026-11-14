<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Arsip;

use CodeIgniter\Database\BaseConnection;

/**
 * Kolom "Data Riwayat" halaman Arsip (F10-22, AC1-13) — bentuk `previewRwy()` legacy
 * (`legacy:helpers/function_helper.php:2260-2383`) untuk kode 1, 3, 5, 7, 9, 36, 10, 11, 13, 14, 20, 22, 23, 32; kode lain
 * (0, 37–50, NULL) dan baris yang tidak ditemukan = "—".
 *
 * Tanpa N+1: satu query per tabel riwayat untuk semua `id_entri` satu halaman (kode 9 dan 36 berbagi
 * `riwayat_mutasi_jabatan`, jadi satu query). Tanpa syarat status [K] (baris menggantung tetap diringkas, F10-16).
 * [V2] kecil: baris hanya diambil bila milik NIP itu (`nip`, kode 3 lewat induk `riwayat_keluarga`), sedangkan legacy
 * membaca menurut PK saja; `id_entri` yang menunjuk baris NIP lain tidak meringkas data orang lain. Tanggal kosong /
 * `0000-…` menghasilkan teks kosong (legacy mencetak tanggal sekarang/negatif).
 */
final class RingkasanRiwayat
{
    public const KOSONG = '—';

    /**
     * kode => [tabel, pk]. Kode 3 lewat tabel anak (`detail_anak` → `riwayat_keluarga`).
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const SUMBER = [
        1  => ['riwayat_alamat', 'id_riwayat_alamat'],
        3  => ['detail_anak', 'id_detail_anak'],
        5  => ['riwayat_diklat', 'id_riwayat_diklat'],
        7  => ['riwayat_hukdis', 'id_riwayat_hukdis'],
        9  => ['riwayat_mutasi_jabatan', 'id_riwayat_mutasi_jabatan'],
        36 => ['riwayat_mutasi_jabatan', 'id_riwayat_mutasi_jabatan'],
        10 => ['riwayat_kgb', 'id_riwayat_kgb'],
        11 => ['riwayat_kp', 'id_riwayat_kp'],
        13 => ['riwayat_organisasi', 'id_riwayat_organisasi'],
        14 => ['riwayat_pendidikan', 'id_riwayat_pendidikan'],
        20 => ['riwayat_keluarga', 'id_riwayat_keluarga'],
        22 => ['riwayat_seminar', 'id_riwayat_seminar'],
        23 => ['riwayat_tanda_jasa', 'id_riwayat_tanda_jasa'],
        32 => ['riwayat_skp', 'id_riwayat_skp'],
    ];

    private const BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Kode yang punya ringkasan.
     */
    public static function berlaku(?int $kode): bool
    {
        return $kode !== null && isset(self::SUMBER[$kode]);
    }

    /**
     * Ringkasan per `id_attachment` untuk baris-baris satu halaman milik $nip.
     *
     * @param list<array{id_attachment: int|string, id_riwayat: int|string|null, id_entri: string|null}> $baris
     *
     * @return array<int, string>
     */
    public function untuk(string $nip, array $baris): array
    {
        $perTabel = [];

        foreach ($baris as $b) {
            $kode = $b['id_riwayat'] === null ? null : (int) $b['id_riwayat'];
            $id   = trim((string) ($b['id_entri'] ?? ''));

            if (self::berlaku($kode) && $id !== '' && ctype_digit($id)) {
                $perTabel[self::SUMBER[(int) $kode][0]][$id] = true;
            }
        }

        $data = [];

        foreach ($perTabel as $tabel => $ids) {
            $data[$tabel] = $this->ambil($tabel, $nip, array_map('strval', array_keys($ids)));
        }

        $hasil = [];

        foreach ($baris as $b) {
            $kode   = $b['id_riwayat'] === null ? null : (int) $b['id_riwayat'];
            $id     = trim((string) ($b['id_entri'] ?? ''));
            $teks   = '';
            $sumber = self::berlaku($kode) ? self::SUMBER[(int) $kode] : null;

            if ($sumber !== null && isset($data[$sumber[0]][$id])) {
                $teks = self::bentuk((int) $kode, $data[$sumber[0]][$id]);
            }

            $hasil[(int) $b['id_attachment']] = trim($teks) === '' ? self::KOSONG : $teks;
        }

        return $hasil;
    }

    /**
     * Ringkasan per `id_entri` untuk calon tujuan Hubungkan kode $kode milik $nip (`opsi-hubungkan`, MAKE-092).
     *
     * @param list<string> $ids
     *
     * @return array<string, string>
     */
    public function untukEntri(int $kode, string $nip, array $ids): array
    {

        $baris = [];

        foreach ($ids as $i => $id) {
            $baris[] = ['id_attachment' => $i, 'id_riwayat' => $kode, 'id_entri' => $id];
        }

        $hasil = [];

        foreach ($this->untuk($nip, $baris) as $i => $teks) {
            $hasil[$ids[$i]] = $teks;
        }

        return $hasil;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, array<string, mixed>>
     */
    private function ambil(string $tabel, string $nip, array $ids): array
    {
        if ($tabel === 'detail_anak') {
            $rows = $this->db->table('detail_anak a')
                ->select('a.id_detail_anak AS pk, a.nama')
                ->join('riwayat_keluarga k', 'k.id_riwayat_keluarga = a.id_riwayat_keluarga', 'inner')
                ->where('k.nip', $nip)
                ->whereIn('a.id_detail_anak', $ids)
                ->get()->getResultArray();
        } else {
            $pk   = self::pk($tabel);
            $rows = array_map(
                static fn (array $r): array => ['pk' => $r[$pk]] + $r,
                $this->db->table($tabel)->where('nip', $nip)->whereIn($pk, $ids)->get()->getResultArray(),
            );
        }

        $hasil = [];

        foreach ($rows as $r) {
            $hasil[(string) $r['pk']] = $r;
        }

        return $hasil;
    }

    private static function pk(string $tabel): string
    {
        foreach (self::SUMBER as [$t, $pk]) {
            if ($t === $tabel) {
                return $pk;
            }
        }

        throw new \LogicException("Tabel ringkasan {$tabel} tidak dikenal.");
    }

    /**
     * @param array<string, mixed> $r
     */
    private static function bentuk(int $kode, array $r): string
    {
        $s = static fn (string $k): string => (string) ($r[$k] ?? '');

        return match ($kode) {
            1       => ($s('jenis_alamat') === '1' ? 'Alamat KTP' : 'Alamat DOMISILI') . ' - ' . $s('alamat'),
            3       => $s('nama'),
            5       => self::diklat($r),
            7       => self::tanggal($s('tmtsk')) . ' - ' . $s('tingkat_hukdis'),
            9, 36   => $s('jabatan') . ' - ' . $s('satker'),
            10      => 'Rp. ' . ((float) $s('gaji_pokok') > 0 ? number_format((float) $s('gaji_pokok'), 0, ',', '.') : '0') . ' - Masa Kerja: ' . $s('mker_gol_th') . ' Tahun',
            11      => $s('jenis_kp') . ' - ' . $s('gol_ruang'),
            13      => $s('nama_organisasi'),
            14      => $s('jenjang_pendidikan_singkat') . ' - ' . $s('institusi_pendidikan'),
            20      => $s('nama_pasangan') . ' - ' . $s('jumlah_anak') . ' Anak',
            22      => ($s('jenis_seminar') === '1' ? 'SEMINAR' : 'KURSUS') . ' - ' . $s('nama_seminar'),
            23      => self::tanggal($s('tgl_sertifikat')) . ' - ' . ($s('id_tanda_jasa') === '44' ? $s('tanda_jasa_lain') : $s('tanda_jasa')),
            32      => $s('tahun') . ' - ' . ((float) $s('nilai_prestasi_kerja') > 0 ? self::desimal($s('nilai_prestasi_kerja')) : '0') . ' [' . $s('kategori_nilai_prestasi') . ']',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $r
     */
    private static function diklat(array $r): string
    {
        $struktural = (string) ($r['jenis_diklat'] ?? '') === '1';
        $nama       = stripslashes((string) ($r['deskripsi'] ?? ''));

        if ($struktural && (string) ($r['nama_diklat'] ?? '') !== '') {
            $nama = stripslashes((string) $r['nama_diklat']);
        }

        return ($struktural ? 'STRUKTURAL' : 'TEKNIS') . ' - ' . $nama;
    }

    /**
     * `dateToUserFull_local` legacy: "j Bulan Y".
     */
    private static function tanggal(string $tanggal): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $tanggal, $m) !== 1 || $m[1] === '0000' || (int) $m[2] < 1 || (int) $m[2] > 12) {
            return '';
        }

        return (int) $m[3] . ' ' . self::BULAN[(int) $m[2]] . ' ' . $m[1];
    }

    /**
     * `decimalToUser` legacy: angka tanpa nol berlebih, titik → koma.
     */
    private static function desimal(string $angka): string
    {
        return str_replace('.', ',', (string) ((float) $angka + 0));
    }
}
