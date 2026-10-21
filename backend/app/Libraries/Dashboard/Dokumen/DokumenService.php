<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Dokumen;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\TooManyRequestsException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Dashboard\Statistik\LingkupStatistik;
use App\Libraries\Dashboard\Statistik\StatistikService;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Pegawai\EksporTabel;
use App\Libraries\ListQuery;
use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Throttle\ThrottlerInterface;
use Config\Database;
use Config\Services;

/**
 * Generator Dokumen F-09 (PRD Fase 7 §7.2, MAKE-037): DUK, Nominatif, Proyeksi Pensiun, Baperjakat — JSON berhalaman,
 * PDF lanskap sinkron, XLSX; kop `web_config`; audit unduhan (F7-27); throttle unduhan 6/menit per akun (§6.4).
 * Padanan legacy `controllers/hr/Document.php` + `libraries/hr/L_document.php` (di legacy bukan PDF; F7-15 [V2]).
 *
 * Hak: role 1, 3, 4, 5, 8 (Matriks v2 `hr/document/*`); role 3 terbatas satker/unit akun (`PegawaiScope`, F7-22).
 * Lingkup `lingkup`/`id` memakai aturan yang sama dengan statistik (`StatistikService::lingkup`). Pabrik statis
 * (tidak ada entri `Config\Services`).
 */
final class DokumenService
{
    /**
     * @var list<string>
     */
    public const JENIS = [KolomDokumen::DUK, KolomDokumen::NOMINATIF, KolomDokumen::PENSIUN, KolomDokumen::BAPERJAKAT];

    public const PER_PAGE_DEFAULT = 20;

    public const PER_PAGE_MAX = 100;

    /**
     * Unduhan PDF/XLSX per akun per menit (§6.4).
     */
    public const UNDUH_PER_MENIT = 6;

    public const ENTITY_AUDIT = 'dokumen_unduhan';

    /**
     * Fallback LOCKED F7-15 (ADR-034): PDF hanya per unit/satker dan maksimal PDF_MAKS_BARIS baris; XLSX tetap seluruh
     * lingkup. Angka dari uji volume M3 (DokumenVolumeTest): 5.000 baris kementerian kehabisan memori TCPDF di 512 MB;
     * 2.000 baris = 270 MB (> 256 MB); 1.700 baris = 10,6 s / 238 MB, termasuk ±60 MB dasar proses uji.
     */
    public const PDF_MAKS_BARIS = 1700;

    public const PESAN_PDF_LINGKUP = 'PDF hanya tersedia per unit/satker.';

    public const PESAN_PDF_BATAS = 'Persempit lingkup atau unduh XLSX.';

    public const PESAN_PDF_BATAS_NIP = 'Terlalu banyak NIP untuk PDF (maksimal ' . self::PDF_MAKS_BARIS . '); kurangi pilihan atau unduh XLSX.';

    private ?KueriDaftarPegawai $kueri = null;

    private ?StatistikService $statistik = null;

    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly ?PegawaiScopeInterface $scope = null,
        private readonly ?ThrottlerInterface $throttler = null,
        private readonly ?AuditLogModel $audit = null,
        private readonly ?RenderDokumenPdf $render = null,
    ) {
    }

    /**
     * Daftar JSON berhalaman (`page`, `per_page`, `search`, `lingkup`, `id`; Pensiun + `tahun`, `sampai`).
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function daftar(AuthContext $auth, string $jenis, array $query): array
    {
        $this->wajibRole($auth);
        $this->wajibJenis($jenis);

        $q       = ListQuery::from($query, ['lingkup', 'id', 'search', 'page', 'per_page', 'tahun', 'sampai']);
        $lingkup = $this->statistik()->lingkup($auth, $q);
        $page    = $q->page();
        $perPage = $q->perPage(self::PER_PAGE_DEFAULT, self::PER_PAGE_MAX);
        $hariIni = TanggalBisnis::hariIni();
        $kolom   = KolomDokumen::kolomXlsx($jenis);

        if ($jenis === KolomDokumen::PENSIUN) {
            $rentang = ProyeksiPensiun::rentang($q->string('tahun'), $q->string('sampai'), $hariIni);
            $semua   = $this->barisPensiun($auth, $lingkup, $q->string('search'), $rentang);
            $total   = count($semua);
            $rows    = array_slice($semua, ($page - 1) * $perPage, $perPage);
            $param   = $rentang;
        } else {
            $b = $this->kueri()->builder($auth, $jenis, $lingkup, $q->string('search'));
            // countAllResults(true) me-reset alias tabel di koneksi (resetSelect) sehingga builder asli kehilangan alias `peg`.
            $total = (clone $b)->countAllResults(false);
            $rows  = $this->kueri()->urutkan($b, $jenis)->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
            $param = [];
        }

        $items = [];

        foreach ($rows as $i => $r) {
            $baris   = KolomDokumen::baris($r, $hariIni);
            $items[] = ['no' => ($page - 1) * $perPage + $i + 1] + array_intersect_key($baris, array_flip($kolom));
        }

        return [
            'jenis'     => $jenis,
            'judul'     => KolomDokumen::JUDUL[$jenis],
            'lingkup'   => $lingkup->toArray(),
            'parameter' => $param,
            'kolom'     => $jenis === KolomDokumen::PENSIUN ? KolomDokumen::KOLOM_PENSIUN : KolomDokumen::KOLOM_LENGKAP,
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'periode'   => $hariIni,
        ];
    }

    /**
     * Unduh PDF (F7-15) atau XLSX (F7-16) dokumen daftar; seluruh baris pada lingkup (tanpa paginasi).
     *
     * @param array<string, mixed> $query
     *
     * @return array{isi: string, nama: string, mime: string}
     */
    public function unduh(AuthContext $auth, string $jenis, string $format, array $query): array
    {
        $this->wajibRole($auth);
        $this->wajibJenis($jenis);

        if ($jenis === KolomDokumen::BAPERJAKAT) {
            throw new NotFoundException('Baperjakat diunduh lewat POST dengan daftar NIP.');
        }

        $q       = ListQuery::from($query, ['lingkup', 'id', 'search', 'tahun', 'sampai']);
        $lingkup = $this->statistik()->lingkup($auth, $q);
        $hariIni = TanggalBisnis::hariIni();
        $param   = [];

        // Fallback F7-15: ditolak sebelum throttle agar permintaan yang pasti 422 tidak memakan jatah unduhan
        // (kecuali batas baris Pensiun, yang baru diketahui sesudah kueri).
        if ($format === 'pdf' && $lingkup->tipe === LingkupStatistik::KEMENTERIAN) {
            throw ValidationException::forField('lingkup', self::PESAN_PDF_LINGKUP);
        }

        if ($jenis === KolomDokumen::PENSIUN) {
            $param = ProyeksiPensiun::rentang($q->string('tahun'), $q->string('sampai'), $hariIni);
            $this->wajibThrottle($auth);
            // Disaring di PHP (BUP per jabatan), jadi batas baris PDF baru bisa dihitung sesudah kueri.
            $rows = $this->barisPensiun($auth, $lingkup, $q->string('search'), $param);
            $this->wajibBatasPdf($format, count($rows));
        } else {
            $b = $this->kueri()->builder($auth, $jenis, $lingkup, $q->string('search'));
            // Batas dihitung lewat COUNT sebelum baris diambil, agar lingkup besar tidak sempat dimuat ke memori.
            $this->wajibBatasPdf($format, (clone $b)->countAllResults(false));
            $this->wajibThrottle($auth);
            $rows = $this->kueri()->urutkan($b, $jenis)->get()->getResultArray();
        }

        $baris  = self::petakanBaris($rows, $hariIni);
        $jumlah = count($baris);
        $berkas = $this->berkas($jenis, $format, $baris, $lingkup, $hariIni, $this->pencetak($auth));

        $this->catatUnduhan($auth, $jenis, $format, $lingkup, $param + ['search' => $q->string('search')], $jumlah);

        return $berkas;
    }

    /**
     * Baperjakat (F7-21): NIP terpilih + judul/subjudul/teks → PDF atau XLSX.
     *
     * @param array<string, mixed> $body
     *
     * @return array{isi: string, nama: string, mime: string}
     */
    public function baperjakat(AuthContext $auth, string $format, array $body): array
    {
        $this->wajibRole($auth);

        $input = BaperjakatInput::dari($body);

        // Batas PDF yang sama dengan dokumen daftar (BaperjakatInput mengizinkan s.d. 2.000 NIP untuk XLSX).
        if ($format === 'pdf' && count($input['nip']) > self::PDF_MAKS_BARIS) {
            throw ValidationException::forField('nip', self::PESAN_PDF_BATAS_NIP);
        }

        $this->wajibThrottle($auth);

        $rows   = $this->kueri()->baperjakat($auth, $input['nip']);
        $ada    = array_map(static fn (array $r): string => (string) $r['nip'], $rows);
        $hilang = array_values(array_diff($input['nip'], $ada));

        if ($hilang !== []) {
            throw BaperjakatInput::galatNip($hilang);
        }

        $hariIni = TanggalBisnis::hariIni();
        $lingkup = new LingkupStatistik(LingkupStatistik::KEMENTERIAN, null, $input['subjudul']);
        $baris   = self::petakanBaris($rows, $hariIni);
        $jumlah  = count($baris);
        $berkas  = $this->berkas(KolomDokumen::BAPERJAKAT, $format, $baris, $lingkup, $hariIni, $this->pencetak($auth), [
            'judul'    => $input['judul'],
            'subjudul' => $input['subjudul'],
            'teks'     => $input['teks'],
        ]);

        $this->catatUnduhan($auth, KolomDokumen::BAPERJAKAT, $format, $lingkup, ['judul' => $input['judul'], 'jumlah_nip' => count($input['nip'])], $jumlah);

        return $berkas;
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * Baris kueri → baris dokumen; baris kueri dilepas satu per satu agar dua salinan penuh tidak hidup bersamaan
     * (memori XLSX 5.000 baris, uji volume F7-15). $rows dikosongkan.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @param-out array<int, array<string, mixed>> $rows
     *
     * @return list<array<string, string>>
     */
    private static function petakanBaris(array &$rows, string $hariIni): array
    {
        $baris = [];

        foreach (array_keys($rows) as $k) {
            $baris[] = KolomDokumen::baris($rows[$k], $hariIni);
            unset($rows[$k]);
        }

        return $baris;
    }

    /**
     * @param list<array<string, string>> $baris dikosongkan untuk XLSX (baris dilepas saat dipindah ke sel)
     * @param array{judul?: string, subjudul?: string, teks?: string} $ekstra
     *
     * @param-out array<int, array<string, string>> $baris
     *
     * @return array{isi: string, nama: string, mime: string}
     */
    private function berkas(string $jenis, string $format, array &$baris, LingkupStatistik $lingkup, string $hariIni, string $pencetak, array $ekstra = []): array
    {
        $dasar = sprintf('%s_%s%s_%s', $jenis, $lingkup->tipe, $lingkup->id ?? '', date('YmdHis'));

        if ($format === 'pdf') {
            $kolom = KolomDokumen::kolomPdf($jenis);
            $isi   = ($this->render ?? new RenderDokumenPdf($this->db))->render($jenis, $kolom, $baris, $lingkup, $hariIni, $pencetak, $ekstra);

            return ['isi' => $isi, 'nama' => $dasar . '.pdf', 'mime' => 'application/pdf'];
        }

        $kolom  = KolomDokumen::kolomXlsx($jenis);
        $header = ['NO', ...KolomDokumen::headerXlsx($jenis)];
        $rows   = [];

        foreach (array_keys($baris) as $i) {
            $r      = $baris[$i];
            $rows[] = [(string) ($i + 1), ...array_map(static fn (string $k): string => $r[$k] ?? '', $kolom)];
            unset($baris[$i], $r);
        }

        $judulSheet = mb_substr($ekstra['judul'] ?? KolomDokumen::JUDUL[$jenis], 0, 31);

        return ['isi' => EksporTabel::xlsx($header, $rows, $judulSheet), 'nama' => $dasar . '.xlsx', 'mime' => EksporTabel::MIME[EksporTabel::XLSX]];
    }

    /**
     * Baris pensiun pada rentang tahun, urut TMT pensiun (F7-20); disaring di PHP (BUP per jabatan).
     *
     * @param array{tahun: int, sampai: int} $rentang
     *
     * @return list<array<string, mixed>>
     */
    private function barisPensiun(AuthContext $auth, LingkupStatistik $lingkup, string $search, array $rentang): array
    {
        $rows = $this->kueri()->builder($auth, KolomDokumen::PENSIUN, $lingkup, $search)->get()->getResultArray();
        $rows = array_values(array_filter($rows, static fn (array $r): bool => ProyeksiPensiun::masuk($r, $rentang['tahun'], $rentang['sampai'])));
        usort($rows, [ProyeksiPensiun::class, 'bandingkan']);

        return $rows;
    }

    /**
     * @param array<string, mixed> $parameter
     */
    private function catatUnduhan(AuthContext $auth, string $jenis, string $format, LingkupStatistik $lingkup, array $parameter, int $jumlahBaris): void
    {
        ($this->audit ?? new AuditLogModel())->record(self::ENTITY_AUDIT, $jenis, 'create', null, [
            'jenis'        => $jenis,
            'format'       => $format,
            'lingkup'      => $lingkup->tipe,
            'id'           => $lingkup->id,
            'parameter'    => $parameter,
            'jumlah_baris' => $jumlahBaris,
        ], $auth->idPengguna(), $auth->nip());
    }

    private function wajibBatasPdf(string $format, int $jumlah): void
    {
        if ($format === 'pdf' && $jumlah > self::PDF_MAKS_BARIS) {
            throw ValidationException::forField('lingkup', self::PESAN_PDF_BATAS);
        }
    }

    private function wajibThrottle(AuthContext $auth): void
    {
        $key = 'dok_unduh_' . ($auth->idPengguna() ?? 0);

        if (! ($this->throttler ?? Services::throttler())->check($key, self::UNDUH_PER_MENIT, MINUTE, 1)) {
            throw new TooManyRequestsException('Terlalu banyak unduhan dokumen. Coba lagi dalam satu menit.');
        }
    }

    private function wajibRole(AuthContext $auth): void
    {
        if (! $auth->isAuthenticated() || ! in_array($auth->role(), StatistikService::ROLE_LIHAT, true)) {
            throw new ForbiddenException('Anda tidak berhak mengakses dokumen kepegawaian.');
        }
    }

    private function wajibJenis(string $jenis): void
    {
        if (! in_array($jenis, self::JENIS, true)) {
            throw new NotFoundException('Jenis dokumen tidak dikenal.');
        }
    }

    private function pencetak(AuthContext $auth): string
    {
        return $auth->nip() ?? ('akun #' . ($auth->idPengguna() ?? 0));
    }

    private function kueri(): KueriDaftarPegawai
    {
        return $this->kueri ??= new KueriDaftarPegawai($this->koneksi(), $this->scope ?? Services::pegawaiScope());
    }

    private function statistik(): StatistikService
    {
        return $this->statistik ??= new StatistikService($this->koneksi(), $this->scope);
    }

    private function koneksi(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}
