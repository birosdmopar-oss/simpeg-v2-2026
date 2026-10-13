<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Pegawai;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Lampiran\KolomBerkas;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\Pdf;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Throwable;

/**
 * Cetak Daftar Riwayat Hidup (B-20 penutup, MAKE-014) — port legacy `Employee::print_drh` (:642-684, UL_ALL) +
 * `L_employee::print_drh` (:7108-7240) dan templat `views/hr/employee/print/drh_pdf.php` (bagian A–L).
 *
 * Hak (keputusan user 13-10-2026 Q-M6-5, ikut legacy UL_ALL): role 2/6/7 hanya DRH sendiri (legacy memaksa NIP sendiri;
 * v2 → 403 untuk NIP lain), role 1/3/4/5/8 sesuai PegawaiScope lihat (role 3 lingkup; 1/4/5/8 semua). [V2] PDF saja
 * (TCPDF, ADR-033): format HTML/DOCX legacy dan `drh_generator` (ZIP massal role 1) tidak dibuat. Data riwayat hanya
 * baca (tabel WS-1/WS-2, status 1, urutan legacy). Kop = nama kementerian + unit (unit 6/7: satker) + alamat header,
 * tanpa logo (aset lokal menyusul, sama dengan PDF LKH); foto pegawai disematkan bila berkasnya tersedia.
 */
final class DrhPegawai
{
    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly ?PegawaiScopeInterface $scope = null,
        private readonly ?KolomBerkas $berkas = null,
    ) {
    }

    /**
     * @return array{isi: string, nama: string}
     */
    public function pdf(AuthContext $auth, string $nip): array
    {
        $this->wajibBoleh($auth, $nip);

        $db = $this->db();
        $du = $db->table('pegawai')->where('nip', $nip)->get()->getRowArray();

        if ($du === null) {
            throw new NotFoundException('Pegawai tidak ditemukan.');
        }

        $pmj    = $db->table('pegawai_mutasi_jabatan')->select('id_unit, unit, id_satker, satker')->where('nip', $nip)->get()->getRowArray() ?? [];
        $alamat = $db->table('pegawai_alamat')->where('nip', $nip)->get()->getRowArray() ?? [];
        $nama   = StrukturService::namaGelar($du['glr_awal'], (string) $du['nama'], $du['glr_akhir']);
        $html   = view('pdf/drh', [
            'nama'    => $nama,
            'du'      => $du,
            'lahir'   => $this->tempatLahir($du),
            'alamat'  => $this->alamat($alamat),
            'bagian'  => $this->bagian($nip),
            'kgb'     => $db->table('pegawai_kgb')->where('nip', $nip)->get()->getRowArray(),
            'fotoTag' => $this->foto((string) ($du['foto'] ?? '')),
        ]);

        $isi = Pdf::renderer()->render(new DokumenPdf(
            'Daftar Riwayat Hidup ' . $nama . ' ' . TanggalBisnis::formatIndonesia(TanggalBisnis::hariIni()),
            $html,
            kopHtml: $this->kop($pmj),
            kakiHtml: 'SIMPEG v2, ' . Time::now('Asia/Jakarta')->format('d-m-Y H:i'),
            marginAtasKop: 42,
        ));

        return ['isi' => $isi, 'nama' => 'drh_' . $nip . '_' . date('Ymd') . '.pdf'];
    }

    private function wajibBoleh(AuthContext $auth, string $nip): void
    {
        if (! $auth->isAuthenticated() || ! in_array($auth->role(), Role::all(), true)) {
            throw new ForbiddenException('Anda tidak berhak mencetak DRH.');
        }

        if (in_array($auth->role(), Role::UL_PEGAWAI, true)) {
            if ((string) $auth->nip() !== $nip) {
                throw new ForbiddenException('Anda hanya dapat mencetak DRH sendiri.');
            }

            return;
        }

        if (! $this->scope()->bolehLihat($auth, $nip)) {
            throw new ForbiddenException('Anda tidak berhak mengakses data pegawai ini.');
        }
    }

    /**
     * Bagian B–I, K, L (legacy print_drh): judul, kolom [label, lebar %], baris teks.
     *
     * @return list<array{kode: string, judul: string, sub?: string, kolom: list<array{0: string, 1: int}>, baris: list<list<string>>}>
     */
    private function bagian(string $nip): array
    {
        $tgl  = static fn (mixed $v): string => is_string($v) && $v !== '' && ! str_starts_with($v, '0000') ? TanggalBisnis::formatIndonesia(substr($v, 0, 10)) : '';
        $th   = static fn (mixed $v): string => is_string($v) && strlen($v) >= 4 && ! str_starts_with($v, '0000') ? substr($v, 0, 4) : '';
        $lain = static fn (array $r, string $k): string => strtoupper((string) ($r[$k] ?? '')) === 'LAIN-LAIN' && trim((string) ($r[$k . '_lain'] ?? '')) !== '' ? (string) $r[$k . '_lain'] : (string) ($r[$k] ?? '');
        $no   = static fn (array $rows, callable $isi): array => array_map(static fn (array $r, int $i): array => [(string) ($i + 1), ...$isi($r)], $rows, array_keys($rows));
        $ket  = static fn (array $r): string => trim(str_replace(['**Upload To SIASN**', 'Sync SIASN'], '', (string) ($r['keterangan'] ?? '')));

        $diklat = fn (int $jenis): array => $this->riwayat('riwayat_diklat', $nip, ['tgl_sertifikat'], ['jenis_diklat' => $jenis]);

        $keluarga = [];

        foreach ($this->riwayat('riwayat_keluarga', $nip, ['tgl_perkawinan']) as $r) {
            $anak       = $this->db()->table('detail_anak')->where('id_riwayat_keluarga', $r['id_riwayat_keluarga'])->orderBy('urutan', 'ASC')->get()->getResultArray();
            $keluarga[] = [
                (string) ($r['nama_pasangan'] ?? ''),
                $tgl($r['tgl_perkawinan'] ?? null),
                implode("\n", array_map(static fn (array $a): string => trim(($a['urutan'] ?? '') . '. ' . ($a['nama'] ?? '') . ' (' . $tgl($a['tgl_lahir'] ?? null) . ')'), $anak)),
            ];
        }

        $pelatihan = static fn (string $sub): array => ['kolom' => [['NO.', 5], ['NAMA PELATIHAN', 30], ['DESKRIPSI', 30], ['TAHUN', 10], ['KETERANGAN', 25]], 'sub' => $sub];

        return [
            ['kode'     => 'B', 'judul' => 'RIWAYAT KEPANGKATAN', 'kolom' => [['NO.', 5], ['TMT SK', 18], ['PANGKAT', 77]],
                'baris' => $no($this->riwayat('riwayat_kp', $nip, ['tmtsk']), static fn (array $r): array => [
                    $tgl($r['tmtsk'] ?? null),
                    trim((string) ($r['gol_ruang'] ?? '')) !== '' ? ($r['pangkat'] ?? '') . ' (' . $r['gol_ruang'] . ')' : (string) ($r['pangkat'] ?? ''),
                ])],
            ['kode'     => 'C', 'judul' => 'RIWAYAT PENDIDIKAN', 'kolom' => [['NO.', 5], ['STRATA', 18], ['INSTITUSI PENDIDIKAN', 31], ['JURUSAN', 31], ['TAHUN LULUS', 15]],
                'baris' => $no($this->riwayat('riwayat_pendidikan', $nip, ['tgl_lulus']), static fn (array $r): array => [
                    (string) ($r['jenjang_pendidikan_singkat'] ?? $r['jenjang_pendidikan'] ?? ''), (string) ($r['institusi_pendidikan'] ?? ''), $lain($r, 'jurusan_pendidikan'), $th($r['tgl_lulus'] ?? null),
                ])],
            ['kode'     => 'D', 'judul' => 'RIWAYAT JABATAN', 'kolom' => [['NO.', 5], ['TMT', 18], ['JABATAN', 29], ['UNIT', 24], ['SATKER', 24]],
                'baris' => $no($this->riwayat('riwayat_mutasi_jabatan', $nip, ['tmtsk']), static fn (array $r): array => [
                    $tgl($r['tmtsk'] ?? null),
                    trim((string) ($r['jabatan_lain'] ?? '')) !== '' ? ($r['jabatan'] ?? '') . ' (' . $r['jabatan_lain'] . ')' : (string) ($r['jabatan'] ?? ''),
                    (string) ($r['unit'] ?? ''), (string) ($r['satker'] ?? ''),
                ])],
            ['kode'     => 'E', 'judul' => 'RIWAYAT PENDIDIKAN DAN PELATIHAN', ...$pelatihan('E.1. PELATIHAN PERJENJANGAN'),
                'baris' => $no($diklat(1), static fn (array $r): array => [(string) (($r['nama_diklat'] ?? '') !== '' ? $r['nama_diklat'] : ($r['deskripsi'] ?? '')), (string) ($r['deskripsi'] ?? ''), $th($r['tgl_sertifikat'] ?? null), $ket($r)])],
            ['kode'     => '', 'judul' => '', ...$pelatihan('E.2. PELATIHAN TEKNIS'),
                'baris' => $no($diklat(2), static fn (array $r): array => [(string) (($r['nama_diklat'] ?? '') !== '' ? $r['nama_diklat'] : ($r['deskripsi'] ?? '')), (string) ($r['deskripsi'] ?? ''), $th($r['tgl_sertifikat'] ?? null), $ket($r)])],
            ['kode'     => '', 'judul' => '', ...$pelatihan('E.3. PELATIHAN FUNGSIONAL'),
                'baris' => $no($diklat(3), static fn (array $r): array => [(string) ($r['sub_group_jabatan'] ?? ''), (string) ($r['deskripsi'] ?? ''), $th($r['tgl_sertifikat'] ?? null), $ket($r)])],
            ['kode'     => 'F', 'judul' => 'RIWAYAT KURSUS / SEMINAR', 'kolom' => [['NO.', 5], ['JENIS', 12], ['NAMA', 38], ['BIDANG', 27], ['TGL. SERTIFIKAT', 18]],
                'baris' => $no($this->riwayat('riwayat_seminar', $nip, ['tgl_sertifikat']), static fn (array $r): array => [
                    (string) ($r['jenis_seminar'] ?? '') === '1' ? 'SEMINAR' : 'KURSUS', (string) ($r['nama_seminar'] ?? ''), (string) ($r['bidang_seminar'] ?? ''), $tgl($r['tgl_sertifikat'] ?? null),
                ])],
            ['kode'     => 'G', 'judul' => 'RIWAYAT PENGHARGAAN', 'kolom' => [['NO.', 5], ['TAHUN', 10], ['NAMA TANDA JASA', 85]],
                'baris' => $no($this->riwayat('riwayat_tanda_jasa', $nip, ['tgl_sertifikat']), static fn (array $r): array => [$th($r['tgl_sertifikat'] ?? null), $lain($r, 'tanda_jasa')])],
            ['kode'     => 'H', 'judul' => 'SASARAN KERJA PEGAWAI', 'kolom' => [['TAHUN', 10], ['TGL. MULAI', 19], ['TGL. AKHIR', 19], ['NILAI PRESTASI KERJA', 26], ['KATEGORI NILAI', 26]],
                'baris' => array_map(static fn (array $r): array => [
                    (string) ($r['tahun'] ?? ''), $tgl($r['tgl_mulai'] ?? null), $tgl($r['tgl_akhir'] ?? null), (string) ($r['nilai_prestasi_kerja'] ?? ''), (string) ($r['kategori_nilai_prestasi'] ?? ''),
                ], $this->riwayat('riwayat_skp', $nip, ['tahun', 'tgl_mulai']))],
            ['kode'     => 'I', 'judul' => 'DATA KELUARGA', 'kolom' => [['NO.', 5], ['NAMA PASANGAN', 25], ['TGL. NIKAH', 18], ['DATA ANAK', 52]],
                'baris' => $no($keluarga, static fn (array $r): array => $r)],
            ['kode'     => 'K', 'judul' => 'RIWAYAT ORGANISASI', 'kolom' => [['NO.', 5], ['ORGANISASI', 34], ['KEDUDUKAN', 25], ['MULAI', 18], ['SAMPAI', 18]],
                'baris' => $no($this->riwayat('riwayat_organisasi', $nip, ['tgl_mulai']), static fn (array $r): array => [
                    (string) ($r['nama_organisasi'] ?? ''), (string) ($r['kedudukan'] ?? ''), $tgl($r['tgl_mulai'] ?? null), $tgl($r['tgl_akhir'] ?? null),
                ])],
            ['kode'     => 'L', 'judul' => 'RIWAYAT HUKUMAN DISIPLIN', 'kolom' => [['NO.', 5], ['TMT', 18], ['JENIS', 18], ['MASA HUKUMAN', 25], ['ALASAN HUKUMAN', 34]],
                'baris' => $no($this->riwayat('riwayat_hukdis', $nip, ['tmtsk']), static fn (array $r): array => [
                    $tgl($r['tmtsk'] ?? null), (string) ($r['jenis_hukdis'] ?? ''), (string) ($r['masa_hukuman'] ?? ''), (string) ($r['alasan_hukuman'] ?? ''),
                ])],
        ];
    }

    /**
     * Baris riwayat status 1 milik {nip}, urut naik (legacy getList ... ASC).
     *
     * @param list<string>          $urut
     * @param array<string, scalar> $saring
     *
     * @return list<array<string, mixed>>
     */
    private function riwayat(string $tabel, string $nip, array $urut, array $saring = []): array
    {
        $b = $this->db()->table($tabel)->where('nip', $nip)->where('status', 1)->where($saring);

        foreach ($urut as $kolom) {
            $b->orderBy($kolom, 'ASC');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $b->get()->getResultArray();

        return $rows;
    }

    /**
     * @param array<string, mixed> $du
     */
    private function tempatLahir(array $du): string
    {
        $kota = (string) ($du['kabupaten_kota_lahir'] ?? '');

        return strtoupper($kota) === 'LAIN-LAIN' && trim((string) ($du['kabupaten_kota_lahir_lain'] ?? '')) !== '' ? (string) $du['kabupaten_kota_lahir_lain'] : $kota;
    }

    /**
     * Alamat rumah legacy: alamat, kelurahan/kecamatan, kab-kota/provinsi (nilai "lain"), kode pos.
     *
     * @param array<string, mixed> $a
     */
    private function alamat(array $a): string
    {
        if ($a === []) {
            return '';
        }

        $pilih = static fn (string $k, string $kodeLain): string => (string) ($a["id_{$k}"] ?? '') === $kodeLain && trim((string) ($a["{$k}_lain"] ?? '')) !== '' ? (string) $a["{$k}_lain"] : (string) ($a[$k] ?? '');
        $baris = [(string) ($a['alamat'] ?? '')];

        if ((string) ($a['id_kecamatan'] ?? '') !== '' && (string) ($a['id_kelurahan'] ?? '') !== '') {
            $baris[] = $pilih('kelurahan', '9999999999') . ', ' . $pilih('kecamatan', '9999999');
        }

        if ((string) ($a['id_provinsi'] ?? '') !== '' && (string) ($a['id_kabupaten_kota'] ?? '') !== '') {
            $baris[] = $pilih('kabupaten_kota', '9999') . ', ' . $pilih('provinsi', '99');
        }

        if (trim((string) ($a['kd_pos'] ?? '')) !== '') {
            $baris[] = 'Kode Pos: ' . $a['kd_pos'];
        }

        return implode("\n", array_filter($baris, static fn (string $s): bool => trim($s, ', ') !== ''));
    }

    /**
     * @param array<string, mixed> $pmj
     */
    private function kop(array $pmj): string
    {
        $db  = $this->db();
        $wc  = [];
        $ada = $db->query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$db->prefixTable('web_config')])->getRowArray() !== null;

        if ($ada) {
            foreach ($db->table('web_config')->select('config_name, config_value')->get()->getResultArray() as $r) {
                $wc[(string) $r['config_name']] = (string) ($r['config_value'] ?? '');
            }
        }

        $idUnit  = (int) ($pmj['id_unit'] ?? 0);
        $unitPdf = in_array($idUnit, [6, 7], true) ? (string) ($pmj['satker'] ?? '') : (string) ($pmj['unit'] ?? '');
        $alamat  = (string) ($wc['pdf_header_alamat_kementerian'] ?? '');
        $sumber  = $idUnit === 6
            ? $db->table('satker')->select('alamat_pdf_header')->where('id_satker', (int) ($pmj['id_satker'] ?? 0))->get()->getRow('alamat_pdf_header')
            : ($idUnit !== 7 ? $db->table('unit')->select('alamat_pdf_header')->where('id_unit', $idUnit)->get()->getRow('alamat_pdf_header') : null);

        if (is_string($sumber) && trim($sumber) !== '') {
            $alamat = $sumber;
        }

        return '<table width="100%" cellpadding="0"><tr><td style="text-align:center;font-size:12pt;"><b>'
            . esc(mb_strtoupper((string) ($wc['pdf_header_nama_kementerian'] ?? $wc['nama_kementerian'] ?? ''))) . '</b></td></tr>'
            . '<tr><td style="text-align:center;font-size:9pt;"><b>' . esc(mb_strtoupper($unitPdf)) . '</b></td></tr>'
            . '<tr><td style="text-align:center;font-size:8pt;">' . nl2br(esc($alamat)) . '</td></tr></table>'
            . '<hr style="height:2px;" />';
    }

    /**
     * Tag <img> foto pegawai (base64 tersemat) atau '' bila berkas tidak tersedia (path arsip legacy → tanpa foto).
     */
    private function foto(string $path): string
    {
        if ($path === '') {
            return '';
        }

        try {
            $kolom = $this->berkas ?? new KolomBerkas();
            $isi   = $kolom->ada($path) ? $kolom->baca($path) : '';
        } catch (Throwable) {
            return '';
        }

        if ($isi === '' || @getimagesizefromstring($isi) === false) {
            return '';
        }

        return '<img src="@' . base64_encode($isi) . '" width="110" />';
    }

    private function scope(): PegawaiScopeInterface
    {
        return $this->scope ?? service('pegawaiScope');
    }

    private function db(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
