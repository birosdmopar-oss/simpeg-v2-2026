<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten\Berita;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Dashboard\Konten\NamaBerkas;
use App\Libraries\ListQuery;
use App\Models\Konten\NewsModel;
use Closure;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Konten;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * F-07 Berita (MAKE-081; PRD Fase 7 v2.0 §5.5, Q7-07/08/09/10). Padanan legacy `hr/News`, `L_news.php`.
 *
 * - Baca (semua role 1–8): terbit = `status = 1 AND publish_at <= sekarang` dan target (`1` kementerian, `2` unit, `3` satker)
 *   cocok dengan unit/satker pembaca. Role 1 melihat semua yang terbit di portal dan semua berita di detail; detail memakai aturan yang SAMA dengan portal
 *   (anomali legacy: detail role 3 tanpa pencocokan). Role 3 juga melihat berita miliknya (status berapa pun).
 * - Kelola (role 1, 3): role 1 membuat berita langsung terbit; role 3 → `status = 0` (menunggu), kategori wajib `allow_ap = 1`,
 *   hanya mengubah/menghapus miliknya (satker pembuat sama, atau unit bila tanpa satker); ubah oleh role 3 mengembalikan ke
 *   menunggu [V2]. Persetujuan (role 1): hanya status 0 → 1 (terbit) atau 2 (ditolak, alasan wajib).
 * - Isi HTML dibersihkan server ({@see SanitasiBerita}). `publish_at` disimpan UTC; masukan tanggal WIB.
 * - `news_flag`: berita terbit memberi penanda ke pengguna aktif yang masuk target; membuka portal menghapus penanda pengguna
 *   itu; hitung ulang saat target/status berita terbit berubah (perbaikan legacy: tanpa DELETE global).
 * - `show_notif` (notifikasi pembuat role 3): 1 saat diproses; 3 dibaca; 2 dihapus pembuat — dikelola {@see SumberBerita}.
 * - Lampiran belum dibawa (menunggu keputusan; FE belum memakainya). ⚑ nama kolom mengikuti draf DBV-032.
 */
final class BeritaService
{
    public const MENUNGGU = 0;

    public const TERBIT = 1;

    public const DITOLAK = 2;


    private const PER_PAGE_DEFAULT = 9;

    private const PER_PAGE_MAX = 100;

    private const JUDUL_MAKS = 255;

    private const ALASAN_MAKS = 1000;

    private const BATCH = 500;

    private readonly BaseConnection $db;

    private readonly SanitasiBerita $sanitasi;

    private readonly PenyimpananLampiran $lampiran;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $jam;

    /**
     * @param (Closure(): DateTimeImmutable)|null $jam
     */
    public function __construct(?BaseConnection $db = null, ?SanitasiBerita $sanitasi = null, ?Closure $jam = null, ?PenyimpananLampiran $lampiran = null)
    {
        $this->db       = $db ?? db_connect();
        $this->sanitasi = $sanitasi ?? new SanitasiBerita();
        $this->lampiran = $lampiran ?? new PenyimpananLampiran();
        $this->jam      = $jam ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    // ------------------------------------------------------------------
    // Baca
    // ------------------------------------------------------------------

    /**
     * Portal: berita terbit yang boleh dilihat pembaca, terbaru dulu.
     *
     * @param array<string, mixed> $query q, kategori, page, per_page
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function portal(AuthContext $auth, array $query): array
    {
        $p       = ListQuery::from($query, ['q', 'kategori', 'page', 'per_page', 'terbaru']);
        $page    = $p->page();
        $perPage = $p->perPage(self::PER_PAGE_DEFAULT, self::PER_PAGE_MAX);

        $kategori = $p->string('kategori');
        $q        = trim($p->string('q'));

        // Filter hanya merujuk kolom news.* sehingga bisa dipasang ke builder total (tanpa join) dan builder baris (dengan join).
        $saring = function (BaseBuilder $b) use ($auth, $kategori, $q): void {
            $this->batasTampil($b, $auth);

            if ($kategori !== '' && ctype_digit($kategori)) {
                $b->where('news.id_news_category', (int) $kategori);
            }

            if ($q !== '') {
                $like = ListQuery::likeLiteral($this->db, $q);
                $b->groupStart()->like('news.title', $like)->orLike('news.news', $like)->groupEnd();
            }
        };

        // Total dihitung dari builder TERPISAH tanpa join: countAllResults() pada klon builder beralias mengosongkan pelacakan
        // alias koneksi CI4, sehingga query berikutnya salah memberi prefix DBPrefix pada alias join.
        $hitung = $this->db->table('news');
        $saring($hitung);
        $total = (int) $hitung->countAllResults();

        $b = $this->dasar();
        $saring($b);
        $rows = $b->orderBy('news.publish_at', 'DESC')->orderBy('news.id_news', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'items' => array_map(fn (array $r): array => [
                'id'        => (int) $r['id_news'],
                'judul'     => (string) $r['title'],
                'ringkasan' => SanitasiBerita::ringkasan((string) $r['news']),
                'kategori'  => (string) ($r['category'] ?? ''),
                'tanggal'   => $this->iso((string) $r['publish_at']),
                'foto_url'  => null,
            ], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Daftar kelola: role 1 semua, role 3 miliknya.
     *
     * @param array<string, mixed> $query status, page, per_page
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function kelola(AuthContext $auth, array $query): array
    {
        $this->wajibPengelola($auth);

        $p       = ListQuery::from($query, ['status', 'page', 'per_page']);
        $page    = $p->page();
        $perPage = $p->perPage(20, self::PER_PAGE_MAX);

        $status = $p->string('status');

        $saring = function (BaseBuilder $b) use ($auth, $status): void {
            if ((int) $auth->role() === Role::ADMIN_SATKER) {
                $this->batasMilik($b, $auth);
            }

            if (in_array($status, ['0', '1', '2'], true)) {
                $b->where('news.status', (int) $status);
            }
        };

        $hitung = $this->db->table('news');
        $saring($hitung);
        $total = (int) $hitung->countAllResults();

        $b = $this->dasar();
        $saring($b);
        $rows = $b->orderBy('news.publish_at', 'DESC')->orderBy('news.id_news', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'items' => array_map(fn (array $r): array => [
                'id'           => (int) $r['id_news'],
                'judul'        => (string) $r['title'],
                'kategori'     => (string) ($r['category'] ?? ''),
                'id_kategori'  => (int) $r['id_news_category'],
                'penulis'      => (string) ($r['penulis'] ?? ''),
                'publish_at'   => $this->iso((string) $r['publish_at']),
                'ada_lampiran' => (string) ($r['lampiran'] ?? '') !== '',
                'status'       => (int) $r['status'],
            ], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Detail dengan aturan visibilitas yang sama dengan portal (+ milik sendiri untuk pengelola).
     *
     * @return array<string, mixed>
     */
    public function detail(AuthContext $auth, int $id): array
    {
        $b = $this->dasar()->where('news.id_news', $id);
        $r = $b->get()->getRowArray();

        if (! is_array($r) || ! $this->boleh($auth, $r)) {
            throw new NotFoundException('Berita tidak ditemukan.');
        }

        $zona = new DateTimeZone('Asia/Jakarta');

        return [
            'id'          => (int) $r['id_news'],
            'judul'       => (string) $r['title'],
            'kategori'    => (string) ($r['category'] ?? ''),
            'id_kategori' => (int) $r['id_news_category'],
            'penulis'     => (string) ($r['penulis'] ?? ''),
            'tanggal'     => $this->iso((string) $r['publish_at']),
            'publish_at'  => (new DateTimeImmutable((string) $r['publish_at'], new DateTimeZone('UTC')))->setTimezone($zona)->format('Y-m-d'),
            'foto_url'    => null,
            'lampiran'    => $this->infoLampiran($r),
            'publish_jam' => (new DateTimeImmutable((string) $r['publish_at'], new DateTimeZone('UTC')))->setTimezone($zona)->format('H:i'),
            'isi'         => (string) $r['news'],
            'status'      => (int) $r['status'],
            'target'      => $this->tokenTarget($r),
            'alasan'      => $r['reason_note'] !== null ? (string) $r['reason_note'] : null,
        ];
    }

    /**
     * Jumlah berita terbit belum dibuka pembaca (penanda `news_flag`) — penghitung lonceng.
     */
    public function jumlahBelumDibuka(AuthContext $auth): int
    {
        $id = $auth->idPengguna();

        if ($id === null) {
            return 0;
        }

        return (int) $this->db->table('news_flag nf')
            ->join('news', 'news.id_news = nf.id_news')
            ->where('nf.id_user', $id)
            ->where('news.status', self::TERBIT)
            ->where('news.publish_at <=', $this->sekarang())
            ->countAllResults();
    }

    /**
     * Pembaca membuka portal → penanda berita miliknya dihapus (Q7-09).
     */
    public function dibuka(AuthContext $auth): void
    {
        $id = $auth->idPengguna();

        if ($id !== null) {
            $this->db->table('news_flag')->where('id_user', $id)->delete();
        }
    }

    // ------------------------------------------------------------------
    // Kelola
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input judul, id_kategori, isi, publish_at, target
     *
     * @return array<string, mixed>
     */
    public function buat(AuthContext $auth, array $input, ?UploadedFile $lampiran = null): array
    {
        $this->wajibPengelola($auth);
        $nilai = $this->validasi($auth, $input);
        $admin = (int) $auth->role() === Role::SUPER_ADMIN;
        $aktor = (int) $auth->idPengguna();
        $kunci = $this->simpanLampiran($lampiran);

        $this->db->transStart();

        try {
            $id = (new NewsModel())->insert($nilai['kolom'] + ['lampiran' => $kunci] + [
                'status'            => $admin ? self::TERBIT : self::MENUNGGU,
                'ul_creator'        => (int) $auth->role(),
                'id_unit_creator'   => $admin ? null : $this->angka($auth->idUnit()),
                'id_satker_creator' => $admin ? null : $this->angka($auth->idSatker()),
                'created_at'        => gmdate('Y-m-d H:i:s'),
                'created_by'        => $aktor,
                'updated_by'        => $aktor,
            ], true);

            $this->hitungUlangFlag((int) $id);
            $this->db->transComplete();
        } catch (Throwable $e) {
            $this->batalTransaksi();
            $this->hapusLampiranDiam($kunci);

            throw $e;
        }

        if (! $this->db->transStatus()) {
            $this->hapusLampiranDiam($kunci);

            throw new ApiException('Gagal menyimpan berita.', 500);
        }

        return $this->detail($auth, (int) $id);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function ubah(AuthContext $auth, int $id, array $input, ?UploadedFile $lampiran = null): array
    {
        $this->wajibPengelola($auth);
        $lama  = $this->ambilKelola($auth, $id);
        $nilai = $this->validasi($auth, $input);
        $admin = (int) $auth->role() === Role::SUPER_ADMIN;
        $kunci = $this->simpanLampiran($lampiran);

        $data = $nilai['kolom'] + ['updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => (int) $auth->idPengguna()];

        // Tanpa berkas baru, lampiran lama dipertahankan (legacy: unggah hanya mengganti, tanpa opsi hapus).
        if ($kunci !== null) {
            $data['lampiran'] = $kunci;
        }

        // Saklar `Config\Konten::$beritaUbahRole3KembaliMenunggu`: default false = paritas legacy [K] (keputusan Horii 10-11), true = [V2] kembali menunggu.
        if (! $admin && config(Konten::class)->beritaUbahRole3KembaliMenunggu) {
            $data += ['status' => self::MENUNGGU, 'approved_at' => null, 'approved_by' => null, 'reason_note' => null, 'show_notif' => null];
        }

        $this->db->transStart();

        try {
            (new NewsModel())->update($id, $data);
            $this->hitungUlangFlag($id);
            $this->db->transComplete();
        } catch (Throwable $e) {
            $this->batalTransaksi();
            $this->hapusLampiranDiam($kunci);

            throw $e;
        }

        if (! $this->db->transStatus()) {
            $this->hapusLampiranDiam($kunci);

            throw new ApiException('Gagal menyimpan berita.', 500);
        }

        // Berkas lama dihapus SETELAH commit (legacy L_news.php:717).
        if ($kunci !== null) {
            $this->hapusLampiranDiam((string) ($lama['lampiran'] ?? ''));
        }

        return $this->detail($auth, $id);
    }

    public function hapus(AuthContext $auth, int $id): void
    {
        $this->wajibPengelola($auth);
        $lama = $this->ambilKelola($auth, $id);

        $this->db->transStart();
        (new NewsModel())->delete($id);
        $this->db->transComplete();

        if (! $this->db->transStatus()) {
            throw new ApiException('Gagal menghapus berita.', 500);
        }

        // Hapus berita menghapus lampirannya setelah commit (legacy L_news.php:812).
        $this->hapusLampiranDiam((string) ($lama['lampiran'] ?? ''));
    }

    /**
     * Isi lampiran: aturan visibilitas sama dengan detail. Tanpa lampiran / bukan hak pembaca → 404.
     *
     * @return array{isi: string, mime: string, nama: string}
     */
    public function bacaLampiran(AuthContext $auth, int $id): array
    {
        $r = $this->db->table('news')->where('id_news', $id)->get()->getRowArray();

        if (! is_array($r) || ! $this->boleh($auth, $r) || (string) ($r['lampiran'] ?? '') === '' || ! $this->lampiran->ada((string) $r['lampiran'], 'lampiran-berita', $id)) {
            throw new NotFoundException('Lampiran tidak ditemukan.');
        }

        $ext = NamaBerkas::ekstensi((string) $r['lampiran']);

        return [
            'isi'  => $this->lampiran->baca((string) $r['lampiran']),
            'mime' => PenyimpananLampiran::MIME[$ext] ?? 'application/octet-stream',
            'nama' => 'lampiran-berita-' . $id . '.' . $ext,
        ];
    }

    /**
     * Persetujuan role 1: hanya berita menunggu.
     *
     * @param array<string, mixed> $input aksi (setuju|tolak), alasan (wajib bila tolak)
     *
     * @return array<string, mixed>
     */
    public function proses(AuthContext $auth, int $id, array $input): array
    {
        if ((int) $auth->role() !== Role::SUPER_ADMIN) {
            throw new ApiException('Anda tidak berhak memproses berita.', 403);
        }

        $aksi = is_string($input['aksi'] ?? null) ? $input['aksi'] : '';

        if (! in_array($aksi, ['setuju', 'tolak'], true)) {
            throw ValidationException::forField('aksi', 'Aksi harus setuju atau tolak.');
        }

        $alasan = isset($input['alasan']) ? trim((string) $input['alasan']) : '';

        if ($aksi === 'tolak' && $alasan === '') {
            throw ValidationException::forField('alasan', 'Alasan penolakan wajib diisi.');
        }

        if (mb_strlen($alasan) > self::ALASAN_MAKS) {
            throw ValidationException::forField('alasan', 'Alasan terlalu panjang.');
        }

        $r = $this->db->table('news')->where('id_news', $id)->get()->getRowArray();

        if (! is_array($r)) {
            throw new NotFoundException('Berita tidak ditemukan.');
        }

        if ((int) $r['status'] !== self::MENUNGGU) {
            throw new ApiException('Hanya berita yang menunggu persetujuan yang dapat diproses.', 409);
        }

        $aktor = (int) $auth->idPengguna();

        $this->db->transStart();

        try {
            (new NewsModel())->update($id, [
                'status'      => $aksi === 'setuju' ? self::TERBIT : self::DITOLAK,
                'reason_note' => $aksi === 'tolak' ? $alasan : null,
                'approved_at' => gmdate('Y-m-d H:i:s'),
                'approved_by' => $aktor,
                'show_notif'  => (int) $r['ul_creator'] === Role::ADMIN_SATKER ? 1 : null,
                'updated_at'  => gmdate('Y-m-d H:i:s'),
                'updated_by'  => $aktor,
            ]);
            $this->hitungUlangFlag($id);
            $this->db->transComplete();
        } catch (Throwable $e) {
            $this->batalTransaksi();

            throw $e;
        }

        if (! $this->db->transStatus()) {
            throw new ApiException('Gagal memproses berita.', 500);
        }

        return $this->detail($auth, $id);
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $r
     *
     * @return array{nama: string, tipe: string, url: string}|null
     */
    private function infoLampiran(array $r): ?array
    {
        $kunci = (string) ($r['lampiran'] ?? '');

        if ($kunci === '') {
            return null;
        }

        $ext = NamaBerkas::ekstensi($kunci);

        return [
            'nama' => 'lampiran-berita-' . $r['id_news'] . '.' . $ext,
            'tipe' => PenyimpananLampiran::MIME[$ext] ?? 'application/octet-stream',
            'url'  => '/konten/berita/' . $r['id_news'] . '/lampiran',
        ];
    }

    private function simpanLampiran(?UploadedFile $berkas): ?string
    {
        if ($berkas === null || $berkas->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $periksa = $this->lampiran->periksa($berkas);

        return $this->lampiran->simpan($periksa['isi'], $periksa['ekstensi']);
    }

    private function hapusLampiranDiam(?string $kunci): void
    {
        if ($kunci === null || $kunci === '') {
            return;
        }

        try {
            $this->lampiran->hapus($kunci);
        } catch (Throwable) {
            // Berkas yatim tidak boleh menggagalkan permintaan; dibersihkan manual.
        }
    }

    private function dasar(): BaseBuilder
    {
        // join dulu, baru select: alias join belum dikenal bila select dipanggil lebih awal (prefix DB salah dipasang ke alias).
        return $this->db->table('news')
            ->join('news_category nc', 'nc.id_news_category = news.id_news_category', 'left')
            ->join('pengguna p', 'p.id_pengguna = news.created_by', 'left')
            ->select('news.*, nc.category, p.name AS penulis');
    }

    /**
     * Terbit dan cocok target pembaca; role 1 melihat semua yang terbit; role 3 juga miliknya.
     */
    private function batasTampil(BaseBuilder $b, AuthContext $auth): void
    {
        $b->groupStart()->where('news.status', self::TERBIT)->where('news.publish_at <=', $this->sekarang());

        if ((int) $auth->role() !== Role::SUPER_ADMIN) {
            $b->groupStart()->where('news.target', 1);

            if (($unit = $this->angka($auth->idUnit())) !== null) {
                $b->orGroupStart()->where('news.target', 2)->where('news.id_unit_target', $unit)->groupEnd();
            }

            if (($satker = $this->angka($auth->idSatker())) !== null) {
                $b->orGroupStart()->where('news.target', 3)->where('news.id_satker_target', $satker)->groupEnd();
            }

            $b->groupEnd();
        }

        $b->groupEnd();
    }

    private function batasMilik(BaseBuilder $b, AuthContext $auth): void
    {
        $b->where('news.ul_creator', Role::ADMIN_SATKER);
        $satker = $this->angka($auth->idSatker());

        if ($satker !== null) {
            $b->where('news.id_satker_creator', $satker);
        } else {
            $b->where('news.id_unit_creator', $this->angka($auth->idUnit()) ?? 0);
        }
    }

    /**
     * Aturan baca satu baris, setara {@see self::batasTampil()} + milik sendiri untuk role 3.
     *
     * @param array<string, mixed> $r
     */
    private function boleh(AuthContext $auth, array $r): bool
    {
        $role = (int) $auth->role();

        // Role 1 melihat detail semua berita (juga yang menunggu/ditolak/belum terbit) untuk kelola dan pratinjau.
        if ($role === Role::SUPER_ADMIN) {
            return true;
        }

        if ($role === Role::ADMIN_SATKER && $this->milik($auth, $r)) {
            return true;
        }

        if ((int) $r['status'] !== self::TERBIT || (string) $r['publish_at'] > $this->sekarang()) {
            return false;
        }

        if ((int) $r['target'] === 1) {
            return true;
        }

        if ((int) $r['target'] === 2) {
            return $r['id_unit_target'] !== null && (int) $r['id_unit_target'] === $this->angka($auth->idUnit());
        }

        return $r['id_satker_target'] !== null && (int) $r['id_satker_target'] === $this->angka($auth->idSatker());
    }

    /**
     * @param array<string, mixed> $r
     */
    private function milik(AuthContext $auth, array $r): bool
    {
        if ((int) $r['ul_creator'] !== Role::ADMIN_SATKER) {
            return false;
        }

        $satker = $this->angka($auth->idSatker());

        if ($satker !== null) {
            return $r['id_satker_creator'] !== null && (int) $r['id_satker_creator'] === $satker;
        }

        return $r['id_unit_creator'] !== null && (int) $r['id_unit_creator'] === $this->angka($auth->idUnit());
    }

    /**
     * @return array<string, mixed>
     */
    private function ambilKelola(AuthContext $auth, int $id): array
    {
        $r = $this->db->table('news')->where('id_news', $id)->get()->getRowArray();

        if (! is_array($r) || ((int) $auth->role() === Role::ADMIN_SATKER && ! $this->milik($auth, $r))) {
            throw new NotFoundException('Berita tidak ditemukan.');
        }

        return $r;
    }

    private function wajibPengelola(AuthContext $auth): void
    {
        if (! in_array((int) $auth->role(), [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true)) {
            throw new ApiException('Anda tidak berhak mengelola berita.', 403);
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{kolom: array<string, mixed>}
     */
    private function validasi(AuthContext $auth, array $input): array
    {
        $judul = isset($input['judul']) ? trim((string) $input['judul']) : '';

        if ($judul === '' || mb_strlen($judul) > self::JUDUL_MAKS) {
            throw ValidationException::forField('judul', 'Judul wajib diisi (maks ' . self::JUDUL_MAKS . ' karakter).');
        }

        $idKategori = isset($input['id_kategori']) && ctype_digit((string) $input['id_kategori']) ? (int) $input['id_kategori'] : 0;
        $kategori   = $idKategori > 0 ? $this->db->table('news_category')->where('id_news_category', $idKategori)->get()->getRowArray() : null;

        if (! is_array($kategori) || (int) $kategori['status'] !== 1) {
            throw ValidationException::forField('id_kategori', 'Kategori wajib dipilih dari kategori yang aktif.');
        }

        if ((int) $auth->role() === Role::ADMIN_SATKER && (int) $kategori['allow_ap'] !== 1) {
            throw ValidationException::forField('id_kategori', 'Kategori ini tidak dapat dipakai untuk berita yang menunggu persetujuan.');
        }

        $isi = $this->sanitasi->bersihkan(is_string($input['isi'] ?? null) ? $input['isi'] : '');

        if (! SanitasiBerita::adaIsi($isi)) {
            throw ValidationException::forField('isi', 'Isi berita wajib diisi.');
        }

        return ['kolom' => [
            'id_news_category' => $idKategori,
            'title'            => $judul,
            'news'             => $isi,
            'publish_at'       => $this->publishUtc($input['publish_at'] ?? null),
        ] + $this->targetKolom($input['target'] ?? null)];
    }

    /**
     * Tanggal terbit masukan WIB (`Y-m-d` atau `Y-m-d H:i`) → UTC `Y-m-d H:i:s`.
     */
    private function publishUtc(mixed $nilai): string
    {
        $s    = is_string($nilai) ? trim($nilai) : '';
        $zona = new DateTimeZone('Asia/Jakarta');

        foreach (['!Y-m-d H:i', '!Y-m-d'] as $f) {
            $d = DateTimeImmutable::createFromFormat($f, $s, $zona);

            if ($d !== false && $d->format(substr($f, 1)) === $s) {
                return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }

        throw ValidationException::forField('publish_at', 'Tanggal terbit wajib diisi dengan format YYYY-MM-DD.');
    }

    /**
     * Token target tunggal: `"0"` kementerian, `"<id_unit>"` unit, `"sat_<id_satker>"` satker.
     *
     * @return array{target: int, id_unit_target: ?int, id_satker_target: ?int}
     */
    private function targetKolom(mixed $token): array
    {
        $t = is_string($token) || is_int($token) ? trim((string) $token) : '';

        if ($t === '0') {
            return ['target' => 1, 'id_unit_target' => null, 'id_satker_target' => null];
        }

        if (preg_match('/^[1-9][0-9]{0,9}\z/', $t) === 1) {
            if ($this->db->table('unit')->where('id_unit', (int) $t)->countAllResults() === 0) {
                throw ValidationException::forField('target', 'Unit target tidak ditemukan.');
            }

            return ['target' => 2, 'id_unit_target' => (int) $t, 'id_satker_target' => null];
        }

        if (preg_match('/^sat_([1-9][0-9]{0,9})\z/', $t, $m) === 1) {
            if ($this->db->table('satker')->where('id_satker', (int) $m[1])->countAllResults() === 0) {
                throw ValidationException::forField('target', 'Satker target tidak ditemukan.');
            }

            return ['target' => 3, 'id_unit_target' => null, 'id_satker_target' => (int) $m[1]];
        }

        throw ValidationException::forField('target', 'Target wajib dipilih (seluruh kementerian, unit, atau satker).');
    }

    /**
     * @param array<string, mixed> $r
     */
    private function tokenTarget(array $r): string
    {
        return match ((int) $r['target']) {
            2       => (string) $r['id_unit_target'],
            3       => 'sat_' . $r['id_satker_target'],
            default => '0',
        };
    }

    /**
     * Penanda belum dibuka: berita terbit → pengguna aktif yang masuk target; selain itu penanda dihapus. Hanya untuk
     * berita ini (bukan global seperti legacy).
     */
    private function hitungUlangFlag(int $idNews): void
    {
        $this->db->table('news_flag')->where('id_news', $idNews)->delete();
        $r = $this->db->table('news')->where('id_news', $idNews)->get()->getRowArray();

        if (! is_array($r) || (int) $r['status'] !== self::TERBIT) {
            return;
        }

        $b = $this->db->table('pengguna')->select('id_pengguna')->where('status', '1');

        match ((int) $r['target']) {
            2       => $b->where('id_unit', (string) $r['id_unit_target']),
            3       => $b->where('id_satker', (string) $r['id_satker_target']),
            default => null,
        };

        $ids = array_map('intval', array_column($b->get()->getResultArray(), 'id_pengguna'));

        foreach (array_chunk($ids, self::BATCH) as $potong) {
            $this->db->table('news_flag')->insertBatch(array_map(static fn (int $u): array => ['id_user' => $u, 'id_news' => $idNews], $potong));
        }
    }

    private function sekarang(): string
    {
        return ($this->jam)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function iso(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private function angka(?string $v): ?int
    {
        return $v !== null && ctype_digit($v) && (int) $v > 0 ? (int) $v : null;
    }

    private function batalTransaksi(): void
    {
        if ($this->db->transDepth > 0) {
            $this->db->transRollback();
        }
    }
}
