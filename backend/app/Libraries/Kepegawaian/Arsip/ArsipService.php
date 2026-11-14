<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Arsip;

use App\Exceptions\NotFoundException;
use App\Exceptions\TooManyRequestsException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Interfaces\Kepegawaian\RiwayatRegistryInterface;
use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Pegawai\EksporTabel;
use App\Libraries\ListQuery;
use App\Models\AuditLogModel;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Throttle\ThrottlerInterface;
use Config\Arsip;
use RuntimeException;

/**
 * Halaman Arsip F-10 Tahap 1 (PRD F-10 §5, §8.1–§8.2; MAKE-090): folder agregat per `jenis_rwy`, daftar berkas
 * berhalaman, dan endpoint privat `buka`.
 *
 * - Isi = SEMUA baris `document_attachment` milik NIP (kode riwayat, 0, 37, 38, 42–50, NULL), tanpa syarat status riwayat
 *   (F10-05, F10-16). Folder diturunkan dari `id_riwayat`, bukan dari path (§1.2).
 * - Respons memakai daftar kolom TERTUTUP (AC1-10): `path`, `url`, `basename`, `document_id` tidak pernah dikirim.
 * - Status/URL berkas per baris lewat {@see PenyajiUrlBerkas} (`ada()` untuk semua baris folder, tanpa cache).
 * - Pembukaan folder/daftar arsip pegawai LAIN oleh role 1/3/4/5 dicatat `audit_logs` `arsip_lihat` (F10-17), fail-closed,
 *   dedup per (aktor, NIP) 15 menit (F10-26), tanpa nama berkas dan tanpa teks `q`.
 * - `buka` (F10-34..36): hanya baris yang saat itu berakses 'privat'; urutan galat 404 baris → 404 bukan privat / tak
 *   cocok / hilang → 429 throttle (token bucket per akun) → `baca()` → audit `arsip_unduh` (hasil tidak diperiksa, pola
 *   F-09) → isi.
 */
final class ArsipService
{
    private const SORT = ['id', 'nama', 'jenis', 'created_at'];

    private const TANPA_JENIS = 'tanpa_jenis';

    /**
     * Ekspresi urut `sort=nama` = {@see self::namaArsip()} di SQL: `tag` → `display_name` → `filename` (kecuali baris
     * berkunci v2) → "Berkas <id>". Regex kunci v2 = Config\Arsip::REGEX_KUNCI_V2 (`[.]` pengganti `\.`), dibandingkan
     * peka huruf (`utf8mb4_bin`) seperti PHP. Beda kecil yang diterima: TRIM SQL hanya membuang spasi.
     */
    public const SQL_NAMA_ARSIP = "CASE WHEN TRIM(da.tag) <> '' THEN da.tag"
        . " WHEN TRIM(da.display_name) <> '' THEN da.display_name"
        . " WHEN TRIM(da.filename) <> '' AND NOT (COALESCE(da.path, '') COLLATE utf8mb4_bin REGEXP '^[0-9]{4}/[0-9]{2}/[0-9a-f]{32}[.][a-z0-9]{1,10}$') THEN da.filename"
        . " ELSE CONCAT('Berkas ', da.id_attachment) END";

    public function __construct(
        private readonly BaseConnection $db,
        private readonly PegawaiScopeInterface $scope,
        private readonly StorageAdapterInterface $storage,
        private readonly Arsip $config,
        private readonly ?ThrottlerInterface $throttler = null,
        private readonly ?AuditLogModel $audit = null,
        private readonly ?CacheInterface $cache = null,
        private readonly ?RiwayatRegistryInterface $registry = null,
    ) {
    }

    /**
     * `GET pegawai/{nip}/arsip`.
     *
     * @return array<string, mixed>
     */
    public function folder(AuthContext $auth, string $nip): array
    {
        $pegawai = $this->otorisasi()->bolehLihat($auth, $nip);
        $this->catatLihat($auth, $nip, 'folder', ['id_jenis_rwy' => null, 'belum_terhubung' => null, 'ada_pencarian' => false]);

        $rows = $this->dasar($nip)
            ->select('da.id_attachment, da.id_riwayat, da.path, jr.kode AS kode_jenis, jr.jenis_rwy AS label_jenis, jr.`order` AS urutan_jenis', false)
            ->get()->getResultArray();

        $penyaji = $this->penyaji();
        $folder  = [];
        $total   = ['jumlah' => 0, 'hilang' => 0, 'tak' => 0];

        foreach ($rows as $r) {
            $kode  = $r['id_riwayat'] === null ? null : (int) $r['id_riwayat'];
            $kunci = $kode === null ? self::TANPA_JENIS : (string) $kode;

            $folder[$kunci] ??= [
                'id_jenis_rwy'            => $kode,
                'kode'                    => $kode === null ? self::TANPA_JENIS : (string) ($r['kode_jenis'] ?? ''),
                'label'                   => $kode === null ? 'Tanpa Jenis' : (string) ($r['label_jenis'] ?? ''),
                'urutan'                  => $kode === null || $r['urutan_jenis'] === null ? null : (int) $r['urutan_jenis'],
                'jumlah'                  => 0,
                'jumlah_hilang'           => 0,
                'jumlah_tak_dapat_dibuka' => 0,
            ];

            $status = $penyaji->nilai($r['path'] === null ? null : (string) $r['path'], $nip, (int) $r['id_attachment'])['status_berkas'];
            $folder[$kunci]['jumlah']++;
            $total['jumlah']++;

            if ($status === PenyajiUrlBerkas::STATUS_HILANG) {
                $folder[$kunci]['jumlah_hilang']++;
                $total['hilang']++;
            } elseif ($status === PenyajiUrlBerkas::STATUS_TAK_DAPAT_DIBUKA) {
                $folder[$kunci]['jumlah_tak_dapat_dibuka']++;
                $total['tak']++;
            }
        }

        $folder = array_values($folder);
        usort($folder, static fn (array $a, array $b): int => [self::kelompok($a), $a['urutan'] ?? 0, $a['id_jenis_rwy'] ?? 0]
            <=> [self::kelompok($b), $b['urutan'] ?? 0, $b['id_jenis_rwy'] ?? 0]);

        return [
            'pegawai'                 => $pegawai,
            'folder'                  => $folder,
            'total'                   => $total['jumlah'],
            'total_hilang'            => $total['hilang'],
            'total_tak_dapat_dibuka'  => $total['tak'],
            'penyajian_berkas_legacy' => $this->config->modeLegacy(),
            // Tahap 2 (MAKE-092, F10-38 (3)): dihitung per aktor; FE memakainya untuk `hidden`, server tetap menegakkan.
            'aksi' => $this->aksiFolder($auth, $nip),
        ];
    }

    /**
     * `GET pegawai/{nip}/arsip/berkas?id_jenis_rwy=&q=&sort=&order=&page=&per_page=`.
     *
     * @param array<string, mixed> $query
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function daftar(AuthContext $auth, string $nip, array $query): array
    {
        $this->otorisasi()->bolehLihat($auth, $nip);

        $q       = ListQuery::from($query, ['id_jenis_rwy', 'belum_terhubung', 'q', 'sort', 'order', 'page', 'per_page']);
        $saring  = $this->saringan($q);
        $page    = $q->page();
        $perPage = $q->perPage(Arsip::PER_PAGE_BAWAAN, Arsip::PER_PAGE_MAKS);

        $this->catatLihat($auth, $nip, 'berkas', $saring['audit']);

        $b     = $this->tersaring($nip, $saring, $q);
        $total = (clone $b)->countAllResults(false);
        $this->pilihKolom($b);
        $this->urutkan($b, $saring['sort'], $saring['arah']);
        $rows = $b->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['items' => $this->bentukItem($auth, $nip, $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Satu item daftar (bentuk sama dengan `daftar()`), mis. respons sesudah Ubah Nama / Hubungkan (MAKE-092). Tanpa
     * catatan F10-17 (pemanggil sudah lolos otorisasi tulis); 404 bila baris tidak ada / milik NIP lain.
     *
     * @return array<string, mixed>
     */
    public function item(AuthContext $auth, string $nip, int $idAttachment): array
    {
        $b = $this->dasar($nip)->where('da.id_attachment', $idAttachment);
        $this->pilihKolom($b);
        $items = $this->bentukItem($auth, $nip, $b->get()->getResultArray());

        if ($items === []) {
            throw new NotFoundException('Arsip tidak ditemukan.');
        }

        return $items[0];
    }

    /**
     * `GET pegawai/{nip}/arsip/ekspor?format=xlsx|csv&<filter daftar>` (§6.1 Ekspor, AC2-10): kolom Nama Arsip, Jenis
     * Riwayat, Data Riwayat [K], tanpa URL berkas; filter & urutan sama dengan daftar (tanpa paginasi). Lewat `EksporTabel`
     * (CSV UTF-8 BOM ber-`.csv` F10-27, XLSX `inlineStr`; sel berawalan `= + - @ TAB CR` aman). Ekspor arsip pegawai lain
     * oleh role 1/3/4/5 dicatat SETIAP kali (F10-26, tanpa dedup, `endpoint: "ekspor"`).
     *
     * @param array<string, mixed> $query
     *
     * @return array{isi: string, nama: string, mime: string}
     */
    public function ekspor(AuthContext $auth, string $nip, array $query): array
    {
        $this->otorisasi()->bolehLihat($auth, $nip);

        $q      = ListQuery::from($query, ['format', 'id_jenis_rwy', 'belum_terhubung', 'q', 'sort', 'order']);
        $format = $q->string('format') === '' ? EksporTabel::XLSX : strtolower($q->string('format'));

        if (! isset(EksporTabel::MIME[$format])) {
            throw ValidationException::forField('format', 'Format ekspor harus csv atau xlsx.');
        }

        $saring = $this->saringan($q);
        $this->catatLihat($auth, $nip, 'ekspor', $saring['audit'], false);

        $b = $this->tersaring($nip, $saring, $q);
        $this->pilihKolom($b);
        $this->urutkan($b, $saring['sort'], $saring['arah']);
        $rows = $b->get()->getResultArray();

        $ringkasan = $this->ringkasan($nip, $rows);
        $baris     = array_map(static fn (array $r): array => [
            self::namaArsip($r),
            $r['id_riwayat'] === null ? 'Tanpa Jenis' : (string) ($r['label_jenis'] ?? ''),
            $ringkasan[(int) $r['id_attachment']] ?? RingkasanRiwayat::KOSONG,
        ], $rows);
        $header = ['Nama Arsip', 'Jenis Riwayat', 'Data Riwayat'];

        return [
            'isi'  => $format === EksporTabel::CSV ? EksporTabel::csv($header, $baris) : EksporTabel::xlsx($header, $baris, 'Arsip'),
            'nama' => 'Arsip_' . preg_replace('/[^0-9A-Za-z]+/', '', $nip) . '_' . date('Ymd') . '.' . $format,
            'mime' => EksporTabel::MIME[$format],
        ];
    }

    /**
     * Saringan daftar/ekspor: `id_jenis_rwy` (angka / `tanpa_jenis`), `belum_terhubung` (`1` = kode 0, digabung AND
     * dengan `id_jenis_rwy`, §6.1 [K]: centang + jenis lain → kosong), `sort`, `order`. 422 per kolom.
     *
     * @return array{jenis: string|null, belum: bool, sort: string, arah: string, audit: array<string, mixed>}
     */
    private function saringan(ListQuery $q): array
    {
        $jenis = $this->filterJenis($q->string('id_jenis_rwy'));
        $belum = $q->string('belum_terhubung');
        $sort  = $q->string('sort') === '' ? 'id' : $q->string('sort');
        $arah  = $q->string('order') === '' ? ($sort === 'id' ? 'desc' : 'asc') : strtolower($q->string('order'));

        if (! in_array($belum, ['', '0', '1'], true)) {
            throw ValidationException::forField('belum_terhubung', 'Saringan belum terhubung harus 0 atau 1.');
        }

        if (! in_array($sort, self::SORT, true)) {
            throw ValidationException::forField('sort', 'Urutan harus salah satu dari: ' . implode(', ', self::SORT) . '.');
        }

        if (! in_array($arah, ['asc', 'desc'], true)) {
            throw ValidationException::forField('order', 'Arah urutan harus asc atau desc.');
        }

        return [
            'jenis' => $jenis,
            'belum' => $belum === '1',
            'sort'  => $sort,
            'arah'  => $arah,
            'audit' => ['id_jenis_rwy' => $jenis, 'belum_terhubung' => $belum === '1', 'ada_pencarian' => $q->filled('q')],
        ];
    }

    /**
     * @param array{jenis: string|null, belum: bool, sort: string, arah: string, audit: array<string, mixed>} $saring
     */
    private function tersaring(string $nip, array $saring, ListQuery $q): BaseBuilder
    {
        $b = $this->dasar($nip);

        if ($saring['jenis'] === self::TANPA_JENIS) {
            $b->where('da.id_riwayat', null);
        } elseif ($saring['jenis'] !== null) {
            $b->where('da.id_riwayat', (int) $saring['jenis']);
        }

        if ($saring['belum']) {
            $b->where('da.id_riwayat', 0);
        }

        if ($q->filled('q')) {
            // F10-21: literal — `%`/`_` di-escape (ListQuery::likeLiteral, pola proyek).
            $like = ListQuery::likeLiteral($this->db, $q->string('q'));
            $b->groupStart()->like('da.tag', $like)->orLike('da.display_name', $like)->orLike('da.filename', $like)->groupEnd();
        }

        return $b;
    }

    private function pilihKolom(BaseBuilder $b): void
    {
        $b->select('da.id_attachment, da.filename, da.id_riwayat, da.id_entri, da.tag, da.path, da.display_name, da.file_type, da.created_at, jr.jenis_rwy AS label_jenis');
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, string>
     */
    private function ringkasan(string $nip, array $rows): array
    {
        return (new RingkasanRiwayat($this->db))->untuk($nip, array_map(static fn (array $r): array => [
            'id_attachment' => $r['id_attachment'],
            'id_riwayat'    => $r['id_riwayat'],
            'id_entri'      => $r['id_entri'] === null ? null : (string) $r['id_entri'],
        ], $rows));
    }

    /**
     * Item daftar (kolom TERTUTUP, AC1-10) + `aksi` per baris (Tahap 2, F10-38 (4)).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function bentukItem(AuthContext $auth, string $nip, array $rows): array
    {
        $ringkasan = $this->ringkasan($nip, $rows);
        $penyaji   = $this->penyaji();
        $peran     = $this->otorisasi()->peran($auth, $nip);
        $items     = [];

        foreach ($rows as $r) {
            $id    = (int) $r['id_attachment'];
            $kode  = $r['id_riwayat'] === null ? null : (int) $r['id_riwayat'];
            $nilai = $penyaji->nilai($r['path'] === null ? null : (string) $r['path'], $nip, $id);

            $items[] = [
                'id_attachment'     => $id,
                'nama_arsip'        => self::namaArsip($r),
                'tag'               => $r['tag'],
                'display_name'      => $r['display_name'],
                'id_riwayat'        => $kode,
                'jenis_riwayat'     => $kode === null ? 'Tanpa Jenis' : (string) ($r['label_jenis'] ?? ''),
                'id_entri'          => $r['id_entri'] === null ? null : (string) $r['id_entri'],
                'ringkasan_riwayat' => $ringkasan[$id] ?? RingkasanRiwayat::KOSONG,
                'file_type'         => $r['file_type'],
                'created_at'        => $r['created_at'],
                'berkas_ada'        => $nilai['berkas_ada'],
                'status_berkas'     => $nilai['status_berkas'],
                'akses_berkas'      => $nilai['akses_berkas'],
                'url_berkas'        => $nilai['url_berkas'],
                'jenis_buka'        => $nilai['jenis_buka'],
                'aksi'              => OtorisasiArsip::aksiBaris($auth, $peran, $kode),
            ];
        }

        return $items;
    }

    /**
     * `GET pegawai/{nip}/arsip/berkas/{id}/buka` — isi berkas privat + atribut header (F10-34).
     *
     * @return array{isi: string, tipe: string, inline: bool, nama: string}
     */
    public function buka(AuthContext $auth, string $nip, int $idAttachment): array
    {
        $this->otorisasi()->bolehLihat($auth, $nip);

        $row = $this->db->table('document_attachment')
            ->select('id_attachment, id_riwayat, filename, tag, path, display_name')
            ->where('id_attachment', $idAttachment)
            ->where('NIP', $nip)
            ->get()->getRowArray();

        if (! is_array($row)) {
            throw new NotFoundException('Arsip tidak ditemukan.');
        }

        $path  = $row['path'] === null ? null : (string) $row['path'];
        $nilai = $this->penyaji()->nilai($path, $nip, $idAttachment);

        // Baris legacy saat saklar 'publik' (FE memakai URL publik), path tak cocok regex, atau berkas hilang → 404.
        if ($path === null || $nilai['akses_berkas'] !== Arsip::PENYAJIAN_PRIVAT || $nilai['url_berkas'] === null) {
            throw new NotFoundException('Berkas arsip tidak tersedia.');
        }

        // F10-36: sesudah semua penolakan pasti, sebelum baca() — permintaan yang pasti gagal tidak memakan jatah.
        if (! ($this->throttler ?? service('throttler'))->check('arsip_unduh_' . ($auth->idPengguna() ?? 0), Arsip::BUKA_PER_MENIT, MINUTE, 1)) {
            throw new TooManyRequestsException(Arsip::PESAN_THROTTLE);
        }

        try {
            $isi = $this->storage->baca($path);
        } catch (RuntimeException) {
            throw new NotFoundException('Berkas arsip tidak tersedia.');
        }

        // F10-35: setiap pembukaan berhasil, semua role (termasuk pemilik), tanpa dedup, tanpa nama berkas/path. Hasil
        // record() tidak diperiksa (pola F-09); exception DB tetap menjadi 500.
        ($this->audit ?? new AuditLogModel($this->db))->record(Arsip::AUDIT_UNDUH, $nip, 'create', null, [
            'id_attachment' => $idAttachment,
            'id_riwayat'    => $row['id_riwayat'] === null ? null : (int) $row['id_riwayat'],
            'jenis_buka'    => $nilai['jenis_buka'],
        ], $auth->idPengguna(), $auth->nip());

        $ekstensi = $nilai['ekstensi'];
        $nama     = self::namaArsip($row);

        if ($ekstensi !== '' && ! str_ends_with(strtolower($nama), '.' . $ekstensi)) {
            $nama .= '.' . $ekstensi;
        }

        return [
            'isi'    => $isi,
            'tipe'   => PenyajiUrlBerkas::tipe($ekstensi),
            'inline' => $nilai['jenis_buka'] === PenyajiUrlBerkas::JENIS_PRATINJAU,
            'nama'   => $nama,
        ];
    }

    /**
     * Nama arsip (F10-19): `tag` → `display_name` → `filename`; baris berkunci v2 tanpa `tag`/`display_name` →
     * "Berkas <id>" (basename kunci tidak pernah ke klien). Teks apa adanya; FE menampilkannya sebagai teks biasa.
     *
     * @param array<string, mixed> $row
     */
    public static function namaArsip(array $row): string
    {
        foreach (['tag', 'display_name'] as $kolom) {
            if (trim((string) ($row[$kolom] ?? '')) !== '') {
                return (string) $row[$kolom];
            }
        }

        $filename = trim((string) ($row['filename'] ?? ''));

        if ($filename !== '' && ! PenyajiUrlBerkas::kunciV2($row['path'] === null ? null : (string) $row['path'])) {
            return (string) $row['filename'];
        }

        return 'Berkas ' . (int) $row['id_attachment'];
    }

    private function dasar(string $nip): BaseBuilder
    {
        return $this->db->table('document_attachment da')
            ->join('jenis_rwy jr', 'jr.id_jenis_rwy = da.id_riwayat', 'left')
            ->where('da.NIP', $nip);
    }

    /**
     * `id_jenis_rwy`: '' = semua; angka = kode; `tanpa_jenis` = `id_riwayat` NULL; selain itu 422.
     */
    private function filterJenis(string $nilai): ?string
    {
        if ($nilai === '') {
            return null;
        }

        if ($nilai === self::TANPA_JENIS || (ctype_digit($nilai) && strlen($nilai) <= 9)) {
            return $nilai === self::TANPA_JENIS ? $nilai : (string) (int) $nilai;
        }

        throw ValidationException::forField('id_jenis_rwy', 'Jenis riwayat harus berupa angka atau tanpa_jenis.');
    }

    private function urutkan(BaseBuilder $b, string $sort, string $arah): void
    {
        $arahSql = strtoupper($arah);

        match ($sort) {
            'nama' => $b->orderBy(self::SQL_NAMA_ARSIP . ' ' . $arahSql, '', false),
            // "Tanpa Jenis" (id_riwayat NULL) selalu di belakang, apa pun arahnya (F10-29).
            'jenis'      => $b->orderBy('jr.jenis_rwy IS NULL', '', false)->orderBy('jr.jenis_rwy', $arahSql),
            'created_at' => $b->orderBy('da.created_at', $arahSql),
            default      => $b->orderBy('da.id_attachment', $arahSql),
        };

        if ($sort !== 'id') {
            $b->orderBy('da.id_attachment', 'DESC');
        }
    }

    /**
     * F10-17/F10-26: catat pembukaan arsip pegawai lain oleh role 1/3/4/5, fail-closed (hasil ≤ 0 → 500), dedup 15 menit
     * per (aktor, NIP) untuk folder/daftar (cache diisi SESUDAH insert berhasil).
     *
     * @param array<string, mixed> $filter
     */
    private function catatLihat(AuthContext $auth, string $nip, string $endpoint, array $filter, bool $dedup = true): void
    {
        if (! OtorisasiArsip::wajibDicatat($auth, $nip)) {
            return;
        }

        $cache = $this->cache ?? service('cache');
        $kunci = 'arsip_lihat_' . hash('sha256', ($auth->idPengguna() ?? 0) . '|' . $nip);

        // Ekspor dicatat setiap kali (F10-26) dan tidak mengisi/membaca kunci dedup folder/daftar.
        if ($dedup && $cache->get($kunci) !== null) {
            return;
        }

        $id = ($this->audit ?? new AuditLogModel($this->db))->record(Arsip::AUDIT_LIHAT, $nip, 'create', null, [
            'endpoint' => $endpoint,
            'filter'   => $filter,
        ], $auth->idPengguna(), $auth->nip());

        if ($id <= 0) {
            throw new RuntimeException('Catatan pembukaan arsip gagal ditulis.');
        }

        if ($dedup) {
            $cache->save($kunci, 1, Arsip::DEDUP_LIHAT_DETIK);
        }
    }

    /**
     * Kelompok urutan folder: kode riwayat (0), lalu "Belum Terhubung" kode 0 (1), lalu "Tanpa Jenis" (2) (F10-29).
     *
     * @param array<string, mixed> $folder
     */
    private static function kelompok(array $folder): int
    {
        return match ($folder['id_jenis_rwy']) {
            null    => 2,
            0       => 1,
            default => 0,
        };
    }

    /**
     * `aksi` folder: `{ tulis, jenis_hubungkan: [ { id_jenis_rwy, label } ] }` — label dari `jenis_rwy.jenis_rwy`, urut
     * `jenis_rwy.order` (dialog Hubungkan FE, kode tanpa folder berisi berkas tetap berlabel).
     *
     * @return array{tulis: bool, jenis_hubungkan: list<array{id_jenis_rwy: int, label: string}>}
     */
    private function aksiFolder(AuthContext $auth, string $nip): array
    {
        $peran = $this->otorisasi()->peran($auth, $nip);
        $jenis = [];

        if ($peran['jenis_hubungkan'] !== []) {
            $rows = $this->db->table('jenis_rwy')->select('id_jenis_rwy, jenis_rwy')
                ->whereIn('id_jenis_rwy', $peran['jenis_hubungkan'])
                ->orderBy('order', 'ASC')->orderBy('id_jenis_rwy', 'ASC')->get()->getResultArray();
            $jenis = array_map(static fn (array $r): array => ['id_jenis_rwy' => (int) $r['id_jenis_rwy'], 'label' => (string) $r['jenis_rwy']], $rows);
        }

        return ['tulis' => $peran['tulis'], 'jenis_hubungkan' => $jenis];
    }

    private function penyaji(): PenyajiUrlBerkas
    {
        return new PenyajiUrlBerkas($this->storage, $this->config);
    }

    private function otorisasi(): OtorisasiArsip
    {
        return new OtorisasiArsip($this->scope, $this->db, $this->registry);
    }
}
