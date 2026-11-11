<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten\Dibo;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Dashboard\Konten\NamaBerkas;
use App\Libraries\ListQuery;
use App\Models\Konten\DiboModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use Throwable;

/**
 * F-12 DIBO / Kamus Digital Kepegawaian (MAKE-082; PRD Fase 7 v2.0 §5.6, Q7-11..14; legacy `hr/Dibo`, `L_dibo.php`).
 *
 * - Baca (semua role 1–8): HANYA `status = 1` DAN jenis tidak nonaktif (perbaikan anomali legacy: filter status dikomentari,
 *   `L_dibo.php:12-27,62-66`, sehingga semua status tampil ke semua role; jenis nonaktif: CR-116). Filter jenis, tahun, cari
 *   nomor/tentang; paginasi.
 * - Kelola (role 1): status 1 aktif / 2 dicabut [K] / 10 dihapus [V2, hapus lunak, bisa dipulihkan]. Wajib: jenis aktif, nomor, tahun,
 *   tentang; `file_1` wajib saat tambah; `file_1..3` PDF/XLS/XLSX/DOC/DOCX ≤ 5 MB. Ubah: berkas yang tidak dikirim dipertahankan,
 *   `hapus_file_2` / `hapus_file_3` = 1 menghapus berkas itu (legacy `deleted_file[]`); `file_1` hanya bisa diganti.
 * - Berkas privat, kunci acak; unduh lewat `bacaBerkas()` (terautentikasi; non-admin hanya status 1 dan jenis tidak nonaktif). Berkas lama dihapus SETELAH
 *   commit; hapus lunak mempertahankan berkas (agar dapat dipulihkan).
 * ⚑ nama kolom mengikuti draf DBV-032.
 */
final class DiboService
{
    public const AKTIF = 1;

    public const DICABUT = 2;

    public const DIHAPUS = 10;

    private const PER_PAGE_DEFAULT = 10;

    private const PER_PAGE_MAX = 100;

    private const NOMOR_MAKS = 50;

    private const TAHUN_MIN = 1900;

    private const TAHUN_MAKS = 2100;

    private readonly BaseConnection $db;

    private readonly PenyimpananDibo $berkas;

    public function __construct(?BaseConnection $db = null, ?PenyimpananDibo $berkas = null)
    {
        $this->db     = $db ?? db_connect();
        $this->berkas = $berkas ?? new PenyimpananDibo();
    }

    // ------------------------------------------------------------------
    // Baca
    // ------------------------------------------------------------------

    /**
     * Daftar baca semua role: hanya status 1.
     *
     * @param array<string, mixed> $query q, jenis, tahun, page, per_page
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function baca(array $query): array
    {
        $p       = ListQuery::from($query, ['q', 'jenis', 'tahun', 'page', 'per_page']);
        $page    = $p->page();
        $perPage = $p->perPage(self::PER_PAGE_DEFAULT, self::PER_PAGE_MAX);
        $q       = trim($p->string('q'));
        $jenis   = $p->string('jenis');
        $tahun   = $p->string('tahun');

        $saring = function (BaseBuilder $b) use ($q, $jenis, $tahun): void {
            $b->where('dibo.status', self::AKTIF);
            $this->hanyaJenisTidakNonaktif($b);

            if ($jenis !== '' && ctype_digit($jenis)) {
                $b->where('dibo.id_jenis_dibo', (int) $jenis);
            }

            if ($tahun !== '' && ctype_digit($tahun)) {
                $b->where('dibo.tahun', (int) $tahun);
            }

            if ($q !== '') {
                $like = ListQuery::likeLiteral($this->db, $q);
                $b->groupStart()->like('dibo.nomor', $like)->orLike('dibo.tentang', $like)->groupEnd();
            }
        };

        return $this->halaman($saring, $page, $perPage, fn (array $r): array => $this->barisBaca($r));
    }

    /**
     * Daftar kelola role 1: bawaan tanpa status 10; `?status=1|2|10` menyaring.
     *
     * @param array<string, mixed> $query status, jenis, q, page, per_page
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function kelola(AuthContext $auth, array $query): array
    {
        $this->wajibAdmin($auth);

        $p       = ListQuery::from($query, ['status', 'jenis', 'q', 'page', 'per_page']);
        $page    = $p->page();
        $perPage = $p->perPage(20, self::PER_PAGE_MAX);
        $status  = $p->string('status');
        $jenis   = $p->string('jenis');
        $q       = trim($p->string('q'));

        $saring = function (BaseBuilder $b) use ($status, $jenis, $q): void {
            in_array($status, ['1', '2', '10'], true) ? $b->where('dibo.status', (int) $status) : $b->where('dibo.status !=', self::DIHAPUS);

            if ($jenis !== '' && ctype_digit($jenis)) {
                $b->where('dibo.id_jenis_dibo', (int) $jenis);
            }

            if ($q !== '') {
                $like = ListQuery::likeLiteral($this->db, $q);
                $b->groupStart()->like('dibo.nomor', $like)->orLike('dibo.tentang', $like)->groupEnd();
            }
        };

        return $this->halaman($saring, $page, $perPage, fn (array $r): array => $this->barisKelola($r));
    }

    /**
     * Isi berkas ke-`$no` (1..3). Role 1 semua status; role lain hanya status 1. Selain itu 404.
     *
     * @return array{isi: string, mime: string, nama: string}
     */
    public function bacaBerkas(AuthContext $auth, int $id, int $no): array
    {
        $r = $this->db->table('dibo')->where('id_dibo', $id)->get()->getRowArray();

        if (! is_array($r) || $no < 1 || $no > 3) {
            throw new NotFoundException('Berkas tidak ditemukan.');
        }

        if ((int) $auth->role() !== Role::SUPER_ADMIN && ((int) $r['status'] !== self::AKTIF || ! $this->jenisBolehDibaca($r['id_jenis_dibo']))) {
            throw new NotFoundException('Berkas tidak ditemukan.');
        }

        $kunci = (string) ($r['file_' . $no] ?? '');

        if ($kunci === '' || ! $this->berkas->ada($kunci, 'dibo', $id)) {
            throw new NotFoundException('Berkas tidak ditemukan.');
        }

        $ext = NamaBerkas::ekstensi($kunci);

        return [
            'isi'  => $this->berkas->baca($kunci),
            'mime' => PenyimpananDibo::MIME[$ext] ?? 'application/octet-stream',
            'nama' => 'dibo-' . $id . '-' . $no . '.' . $ext,
        ];
    }

    // ------------------------------------------------------------------
    // Kelola (role 1)
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>        $input nomor, tahun, tentang, keterangan, id_jenis
     * @param array<int, UploadedFile|null> $file  [1 => berkas, 2 => berkas, 3 => berkas]
     *
     * @return array<string, mixed>
     */
    public function buat(AuthContext $auth, array $input, array $file): array
    {
        $this->wajibAdmin($auth);
        $nilai = $this->validasi($input);

        if (! self::ada($file[1] ?? null)) {
            throw ValidationException::forField('file_1', 'Berkas 1 wajib diunggah.');
        }

        $kunci = $this->simpanBerkas($file);
        $aktor = (int) $auth->idPengguna();

        $this->db->transStart();

        try {
            $id = (new DiboModel())->insert($nilai + $kunci + [
                'status'     => self::AKTIF,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'created_by' => $aktor,
                'updated_by' => $aktor,
            ], true);
            $this->db->transComplete();
        } catch (Throwable $e) {
            $this->batalTransaksi();
            $this->hapusDiam(array_values($kunci));

            throw $e;
        }

        if (! $this->db->transStatus()) {
            $this->hapusDiam(array_values($kunci));

            throw new ApiException('Gagal menyimpan DIBO.', 500);
        }

        return $this->barisKelola($this->ambil((int) $id) + ['jenis' => $this->namaJenis($nilai['id_jenis_dibo'])]);
    }

    /**
     * @param array<string, mixed>        $input incl. hapus_file_2 / hapus_file_3 (= 1 untuk menghapus berkas itu)
     * @param array<int, UploadedFile|null> $file
     *
     * @return array<string, mixed>
     */
    public function ubah(AuthContext $auth, int $id, array $input, array $file): array
    {
        $this->wajibAdmin($auth);
        $lama = $this->ambil($id);

        if ((int) $lama['status'] === self::DIHAPUS) {
            throw new ApiException('Data yang dihapus harus dipulihkan dulu sebelum diubah.', 409);
        }

        $nilai = $this->validasi($input);
        $baru  = $this->simpanBerkas($file);
        $data  = $nilai + $baru + ['updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => (int) $auth->idPengguna()];
        $lepas = [];

        foreach ([2, 3] as $no) {
            if (! isset($baru['file_' . $no]) && in_array($input['hapus_file_' . $no] ?? null, [1, '1', true], true)) {
                $data['file_' . $no] = null;
            }
        }

        foreach ([1, 2, 3] as $no) {
            $k = 'file_' . $no;

            if (array_key_exists($k, $data) && (string) ($lama[$k] ?? '') !== '' && $data[$k] !== $lama[$k]) {
                $lepas[] = (string) $lama[$k];
            }
        }

        $this->db->transStart();

        try {
            (new DiboModel())->update($id, $data);
            $this->db->transComplete();
        } catch (Throwable $e) {
            $this->batalTransaksi();
            $this->hapusDiam(array_values($baru));

            throw $e;
        }

        if (! $this->db->transStatus()) {
            $this->hapusDiam(array_values($baru));

            throw new ApiException('Gagal menyimpan DIBO.', 500);
        }

        $this->hapusDiam($lepas); // berkas lama dihapus SETELAH commit (legacy L_dibo.php:271-)

        return $this->barisKelola($this->ambil($id) + ['jenis' => $this->namaJenis($nilai['id_jenis_dibo'])]);
    }

    /**
     * Aktifkan (1), cabut (2), atau pulihkan (1 dari 10).
     */
    public function setStatus(AuthContext $auth, int $id, mixed $status): void
    {
        $this->wajibAdmin($auth);

        if (! in_array((string) $status, ['1', '2'], true)) {
            throw ValidationException::forField('status', 'Status harus 1 (aktif) atau 2 (dicabut).');
        }

        $this->ambil($id);
        (new DiboModel())->update($id, ['status' => (int) $status, 'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => (int) $auth->idPengguna()]);
    }

    /**
     * Hapus lunak (status 10 [V2]); berkas dipertahankan agar dapat dipulihkan.
     */
    public function hapus(AuthContext $auth, int $id): void
    {
        $this->wajibAdmin($auth);
        $this->ambil($id);
        (new DiboModel())->update($id, ['status' => self::DIHAPUS, 'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => (int) $auth->idPengguna()]);
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * @param callable(BaseBuilder): void $saring
     * @param callable(array<string, mixed>): array<string, mixed> $baris
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    private function halaman(callable $saring, int $page, int $perPage, callable $baris): array
    {
        // Total dari builder TERPISAH tanpa join (countAllResults pada builder beralias mengosongkan pelacakan alias CI4).
        $hitung = $this->db->table('dibo');
        $saring($hitung);
        $total = (int) $hitung->countAllResults();

        $b = $this->db->table('dibo')->join('jenis_dibo jd', 'jd.id_jenis_dibo = dibo.id_jenis_dibo', 'left')->select('dibo.*, jd.jenis_dibo AS jenis');
        $saring($b);
        $rows = $b->orderBy('dibo.tahun', 'DESC')->orderBy('dibo.id_dibo', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['items' => array_map($baris, $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Pembaca non-admin tidak melihat DIBO yang jenisnya NONAKTIF (PRD v2.1 §5.6 "status=1 + jenis aktif", CR-116; [V2] lanjutan
     * Q7-13 — legacy punya filter `jdb.status='1'` tetapi dikomentari, `L_dibo.php:12-27,62-66`). Baris legacy tanpa jenis (NULL,
     * kolom NULL-able [K]) tetap terbaca: "tanpa jenis" bukan "jenis nonaktif".
     */
    private function hanyaJenisTidakNonaktif(BaseBuilder $b): void
    {
        $b->groupStart()
            ->where('dibo.id_jenis_dibo', null)
            ->orWhereIn('dibo.id_jenis_dibo', static fn (BaseBuilder $sub) => $sub->select('id_jenis_dibo')->from('jenis_dibo')->where('status', 1))
            ->groupEnd();
    }

    private function jenisBolehDibaca(mixed $idJenis): bool
    {
        if ($idJenis === null || $idJenis === '') {
            return true;
        }

        return $this->db->table('jenis_dibo')->where('id_jenis_dibo', (int) $idJenis)->where('status', 1)->countAllResults() > 0;
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function barisBaca(array $r): array
    {
        return [
            'id'         => (int) $r['id_dibo'],
            'nomor'      => (string) ($r['nomor'] ?? ''),
            'tahun'      => $r['tahun'] !== null ? (int) $r['tahun'] : null,
            'tentang'    => (string) $r['tentang'],
            'keterangan' => $r['keterangan'] !== null ? (string) $r['keterangan'] : null,
            'jenis'      => (string) ($r['jenis'] ?? ''),
            'berkas'     => $this->daftarBerkas($r),
        ];
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function barisKelola(array $r): array
    {
        return $this->barisBaca($r) + ['id_jenis' => $r['id_jenis_dibo'] !== null ? (int) $r['id_jenis_dibo'] : null, 'status' => (int) $r['status']];
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return list<int>
     */
    private function daftarBerkas(array $r): array
    {
        return array_values(array_filter([1, 2, 3], static fn (int $n): bool => (string) ($r['file_' . $n] ?? '') !== ''));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{id_jenis_dibo: int, nomor: string, tahun: int, tentang: string, keterangan: ?string}
     */
    private function validasi(array $input): array
    {
        $idJenis = isset($input['id_jenis']) && ctype_digit((string) $input['id_jenis']) ? (int) $input['id_jenis'] : 0;
        $jenis   = $idJenis > 0 ? $this->db->table('jenis_dibo')->where('id_jenis_dibo', $idJenis)->get()->getRowArray() : null;

        if (! is_array($jenis) || (int) $jenis['status'] !== 1) {
            throw ValidationException::forField('id_jenis', 'Jenis wajib dipilih dari jenis yang aktif.');
        }

        $nomor = isset($input['nomor']) ? trim((string) $input['nomor']) : '';

        if ($nomor === '' || mb_strlen($nomor) > self::NOMOR_MAKS) {
            throw ValidationException::forField('nomor', 'Nomor wajib diisi (maks ' . self::NOMOR_MAKS . ' karakter).');
        }

        $tahun = isset($input['tahun']) ? trim((string) $input['tahun']) : '';

        if (preg_match('/^[0-9]{4}\z/', $tahun) !== 1 || (int) $tahun < self::TAHUN_MIN || (int) $tahun > self::TAHUN_MAKS) {
            throw ValidationException::forField('tahun', 'Tahun harus 4 digit antara ' . self::TAHUN_MIN . ' dan ' . self::TAHUN_MAKS . '.');
        }

        $tentang = isset($input['tentang']) ? trim((string) $input['tentang']) : '';

        if ($tentang === '' || mb_strlen($tentang) > 65535) {
            throw ValidationException::forField('tentang', 'Tentang wajib diisi.');
        }

        $ket = isset($input['keterangan']) ? trim((string) $input['keterangan']) : '';

        if (mb_strlen($ket) > 65535) {
            throw ValidationException::forField('keterangan', 'Keterangan terlalu panjang.');
        }

        return ['id_jenis_dibo' => $idJenis, 'nomor' => $nomor, 'tahun' => (int) $tahun, 'tentang' => $tentang, 'keterangan' => $ket === '' ? null : $ket];
    }

    /**
     * Validasi SEMUA berkas dulu (agar satu berkas buruk tidak meninggalkan berkas lain), baru simpan.
     *
     * @param array<int, UploadedFile|null> $file
     *
     * @return array<string, string> `file_N` => kunci
     */
    private function simpanBerkas(array $file): array
    {
        $periksa = [];

        foreach ([1, 2, 3] as $no) {
            $f = $file[$no] ?? null;

            if (self::ada($f)) {
                $periksa[$no] = $this->berkas->periksa($f, 'file_' . $no);
            }
        }

        $hasil = [];

        foreach ($periksa as $no => $p) {
            $hasil['file_' . $no] = $this->berkas->simpan($p['isi'], $p['ekstensi']);
        }

        return $hasil;
    }

    /**
     * @phpstan-assert-if-true UploadedFile $f
     */
    private static function ada(?UploadedFile $f): bool
    {
        return $f !== null && $f->getError() !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * @return array<string, mixed>
     */
    private function ambil(int $id): array
    {
        $r = $this->db->table('dibo')->where('id_dibo', $id)->get()->getRowArray();

        if (! is_array($r)) {
            throw new NotFoundException('DIBO tidak ditemukan.');
        }

        return $r;
    }

    private function namaJenis(int $id): string
    {
        $r = $this->db->table('jenis_dibo')->select('jenis_dibo')->where('id_jenis_dibo', $id)->get()->getRowArray();

        return is_array($r) ? (string) $r['jenis_dibo'] : '';
    }

    private function wajibAdmin(AuthContext $auth): void
    {
        if ((int) $auth->role() !== Role::SUPER_ADMIN) {
            throw new ApiException('Anda tidak berhak mengelola DIBO.', 403);
        }
    }

    /**
     * @param list<string> $kunci
     */
    private function hapusDiam(array $kunci): void
    {
        foreach ($kunci as $k) {
            try {
                $this->berkas->hapus($k);
            } catch (Throwable) {
                // Berkas yatim tidak boleh menggagalkan permintaan; dibersihkan manual.
            }
        }
    }

    private function batalTransaksi(): void
    {
        if ($this->db->transDepth > 0) {
            $this->db->transRollback();
        }
    }
}
