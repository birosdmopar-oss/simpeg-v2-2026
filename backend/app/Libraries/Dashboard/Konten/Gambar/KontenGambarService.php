<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten\Gambar;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Dashboard\Konten\NamaBerkas;
use App\Libraries\Dashboard\Konten\Penargetan\PemekarTarget;
use App\Libraries\Dashboard\Konten\Penargetan\SinkronPenerima;
use App\Libraries\Dashboard\Konten\Penargetan\SpesifikasiTarget;
use App\Libraries\ListQuery;
use App\Models\BaseAuditableModel;
use App\Models\Konten\BannerModel;
use App\Models\Konten\PopupModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * F-04 Popup (MAKE-079) dan F-05 Banner (MAKE-080) — satu service karena bentuk data dan penargetannya sama (PRD Fase 7 v2.0
 * §5.3/5.4). Kelola = role 1 (filter route); tampil `aktif` = role ber-NIP yang masuk target (Q7-05).
 *
 * - Penerima = snapshot `user_popup`/`user_banner` saat simpan (Q7-15); ubah menyinkronkan SELISIH. Target kosong → 422.
 * - Periode: popup DATETIME WIB (`Y-m-d H:i`), banner DATE; `tgl_akhir >= tgl_mulai`. "Sekarang" WIB, bisa disuntik (test).
 * - Tampil tiap login selama periode (Q7-02): tanpa kolom/endpoint "dilihat" — penjaga sekali-per-sesi ada di FE.
 * - Gambar privat dengan kunci acak (Q7-14); tampil lewat `GET konten/{jenis}/{id}/gambar` terautentikasi (role 1 atau penerima). `gambar_url` = path relatif base API (`/konten/{jenis}/{id}/gambar`); FE mengambilnya sebagai blob (auth cookie httpOnly, bukan `<img src>`).
 * - Urutan `order`: baru = maksimum + 1; naik/turun menukar dengan tetangga.
 * - Audit lewat BaseAuditableModel (Q7-16); hapus = baris dan berkas dihapus permanen, penerima ikut (FK CASCADE).
 * ⚑ nama kolom mengikuti draf DBV-032 dan bisa berubah setelah D1.
 */
final class KontenGambarService
{
    private const PER_PAGE_DEFAULT = 20;

    private const PER_PAGE_MAX = 100;

    private const URL_MAKS = 500;

    private const KETERANGAN_MAKS = 65535;

    private const BATAS_PENERIMA = 200;

    public const MIME = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];

    private readonly BaseConnection $db;

    private readonly PemekarTarget $pemekar;

    private readonly SinkronPenerima $sinkron;

    private readonly PenyimpananGambar $gambar;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $jam;

    /**
     * @param (Closure(): DateTimeImmutable)|null $jam
     */
    public function __construct(
        private readonly JenisGambar $jenis,
        ?BaseConnection $db = null,
        ?PenyimpananGambar $gambar = null,
        ?Closure $jam = null,
    ) {
        $this->gambar  = $gambar ?? new PenyimpananGambar();
        $this->db      = $db ?? db_connect();
        $this->pemekar = new PemekarTarget($this->db);
        $this->sinkron = new SinkronPenerima($this->db);
        $this->jam     = $jam ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
    }

    // ------------------------------------------------------------------
    // Kelola (role 1)
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $query
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function daftar(array $query): array
    {
        $params  = ListQuery::from($query, ['page', 'per_page']);
        $page    = $params->page();
        $perPage = $params->perPage(self::PER_PAGE_DEFAULT, self::PER_PAGE_MAX);
        $t       = $this->jenis->value;
        $k       = $this->jenis->kolomId();
        $u       = $this->jenis->tabelPenerima()->value;

        $total = (int) $this->db->table($t)->countAllResults();
        // SQL mentah tidak diberi prefix otomatis oleh Query Builder (grup tests memakai DBPrefix t_) → prefixTable() eksplisit.
        $tp    = $this->db->prefixTable($t);
        $up    = $this->db->prefixTable($u);
        $baris = $this->db->table($t)
            ->select("{$tp}.*, (SELECT COUNT(*) FROM {$up} WHERE {$up}.{$k} = {$tp}.{$k}) AS jumlah_penerima", false)
            ->orderBy("{$t}.order", 'ASC')
            ->orderBy("{$t}.{$k}", 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return [
            'items'    => array_map(fn (array $r): array => $this->baris($r), $baris),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Target tersimpan + label "NIP - nama" untuk NIP yang dipilih/dikecualikan (agar form ubah menampilkan nama; pencarian
     * pegawai di form memakai `GET pegawai?search=`, bukan daftar semua pegawai).
     *
     * @return array{unit_satker: list<string>, pegawai: list<string>, kecuali: list<string>, label_pegawai: array<string, string>}
     */
    public function target(int $id): array
    {
        $r     = $this->ambil($id);
        $spes  = SpesifikasiTarget::dariKolom($r['target_uns'] ?? null, $r['target_peg'] ?? null, $r['excl_peg'] ?? null);
        $nip   = array_values(array_unique([...$spes->pegawai, ...$spes->kecuali]));
        $label = [];

        if ($nip !== []) {
            foreach ($this->db->table('pegawai')->select('nip, glr_awal, nama, glr_akhir')->whereIn('nip', $nip)->get()->getResultArray() as $p) {
                $label[(string) $p['nip']] = $p['nip'] . ' - ' . self::namaLengkap($p);
            }
        }

        return $spes->keArray() + ['label_pegawai' => $label];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{items: list<array{nip: string, nama: string}>, total: int, page: int, per_page: int}
     */
    public function penerima(int $id, array $query): array
    {
        $this->ambil($id);

        $params  = ListQuery::from($query, ['page', 'per_page']);
        $page    = $params->page();
        $perPage = $params->perPage(self::BATAS_PENERIMA, self::BATAS_PENERIMA);
        $u       = $this->jenis->tabelPenerima()->value;
        $k       = $this->jenis->kolomId();

        $total = (int) $this->db->table($u)->where($k, $id)->countAllResults();
        $rows  = $this->db->table("{$u} up")
            ->join('pegawai peg', 'peg.nip = up.nip', 'left')
            ->select('up.nip, peg.glr_awal, peg.nama, peg.glr_akhir')
            ->where("up.{$k}", $id)
            ->orderBy('up.nip', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return [
            'items'    => array_map(static fn (array $r): array => ['nip' => (string) $r['nip'], 'nama' => self::namaLengkap($r)], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function buat(array $input, ?UploadedFile $berkas, int $idPengguna): array
    {
        $nilai = $this->validasi($input, true);

        if (! self::adaBerkas($berkas)) {
            throw ValidationException::forField('berkas', 'Gambar wajib diunggah.');
        }

        $periksa = $this->gambar->periksa($berkas, $this->jenis);
        $kunci   = $this->gambar->simpan($this->jenis, $periksa['isi'], $periksa['ekstensi']);

        try {
            $this->db->transStart();
            $id = $this->simpanBaru($nilai, $kunci, $idPengguna);
            $this->db->transComplete();

            if (! $this->db->transStatus()) {
                throw new ApiException('Gagal menyimpan data.', 500);
            }
        } catch (Throwable $e) {
            $this->batalTransaksi();
            $this->hapusDiam($kunci);

            throw $e;
        }

        return $this->baris($this->ambil($id) + ['jumlah_penerima' => $this->hitungPenerima($id)]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function ubah(int $id, array $input, ?UploadedFile $berkas, int $idPengguna): array
    {
        $lama  = $this->ambil($id);
        $nilai = $this->validasi($input, false);
        $kunci = null;

        if (self::adaBerkas($berkas)) {
            $periksa = $this->gambar->periksa($berkas, $this->jenis);
            $kunci   = $this->gambar->simpan($this->jenis, $periksa['isi'], $periksa['ekstensi']);
        }

        try {
            $this->db->transStart();
            $data = $this->kolom($nilai) + ['updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => $idPengguna];

            if ($kunci !== null) {
                $data['file'] = $kunci;
            }

            $this->model()->update($id, $data);
            $this->sinkron->sinkron($this->jenis->tabelPenerima(), $id, $this->pemekar->pekarkan($nilai['target']));
            $this->db->transComplete();

            if (! $this->db->transStatus()) {
                throw new ApiException('Gagal menyimpan data.', 500);
            }
        } catch (Throwable $e) {
            $this->batalTransaksi();

            if ($kunci !== null) {
                $this->hapusDiam($kunci);
            }

            throw $e;
        }

        if ($kunci !== null) {
            $this->hapusDiam((string) $lama['file']);
        }

        return $this->baris($this->ambil($id) + ['jumlah_penerima' => $this->hitungPenerima($id)]);
    }

    public function hapus(int $id): void
    {
        $lama = $this->ambil($id);

        $this->db->transStart();
        $this->model()->delete($id);
        $this->db->transComplete();

        if (! $this->db->transStatus()) {
            throw new ApiException('Gagal menghapus data.', 500);
        }

        $this->hapusDiam((string) $lama['file']);
    }

    /**
     * Tukar `order` dengan tetangga (urut `order`, id). Sudah di ujung → tidak berubah (idempoten, 200).
     */
    public function geser(int $id, string $arah): void
    {
        if (! in_array($arah, ['naik', 'turun'], true)) {
            throw ValidationException::forField('arah', 'Arah harus naik atau turun.');
        }

        $t = $this->jenis->value;
        $k = $this->jenis->kolomId();
        $this->ambil($id);

        $urut = array_map(
            static fn (array $r): array => ['id' => (int) $r[$k], 'order' => (int) $r['order']],
            $this->db->table($t)->select("{$k}, `order`")->orderBy('order', 'ASC')->orderBy($k, 'ASC')->get()->getResultArray(),
        );

        $idx = array_search($id, array_column($urut, 'id'), true);
        $tgt = $arah === 'naik' ? $idx - 1 : $idx + 1;

        if ($idx === false || ! isset($urut[$tgt])) {
            return;
        }

        // Nomori ulang 1..n agar tukar selalu bermakna walau `order` ganda/0 (data impor), lalu tukar posisi.
        $ids                     = array_column($urut, 'id');
        [$ids[$idx], $ids[$tgt]] = [$ids[$tgt], $ids[$idx]];

        $this->db->transStart();

        foreach ($ids as $pos => $kid) {
            $this->model()->update($kid, ['order' => $pos + 1]);
        }

        $this->db->transComplete();
    }

    /**
     * Opsi target unit/satker untuk TargetPicker: `"<id_unit>"` dan `"sat_<id_satker>"` (yang aktif).
     *
     * @return list<array{value: string, label: string}>
     */
    public function opsiUnitSatker(): array
    {
        $opsi = [['value' => SpesifikasiTarget::SEMUA_KEMENTERIAN, 'label' => 'Seluruh kementerian']];

        foreach ($this->db->table('unit')->select('id_unit, unit')->where('status', 1)->orderBy('unit', 'ASC')->get()->getResultArray() as $r) {
            $opsi[] = ['value' => (string) $r['id_unit'], 'label' => (string) $r['unit']];
        }

        foreach ($this->db->table('satker')->select('id_satker, satker')->where('status', 1)->orderBy('satker', 'ASC')->get()->getResultArray() as $r) {
            $opsi[] = ['value' => 'sat_' . $r['id_satker'], 'label' => (string) $r['satker']];
        }

        return $opsi;
    }

    // ------------------------------------------------------------------
    // Tampil (role ber-NIP)
    // ------------------------------------------------------------------

    /**
     * Aktif untuk NIP aktor: periode berlaku sekarang (WIB) dan NIP ada di snapshot penerima, urut `order` (Q7-05).
     * Aktor tanpa NIP (role 1) → daftar kosong.
     *
     * @return list<array{id: int, url: ?string, gambar_url: string, keterangan: ?string}>
     */
    public function aktif(?string $nip): array
    {
        if ($nip === null || $nip === '') {
            return [];
        }

        $t   = $this->jenis->value;
        $k   = $this->jenis->kolomId();
        $u   = $this->jenis->tabelPenerima()->value;
        $now = ($this->jam)();
        // Popup membandingkan jam:menit WIB; banner tanggal saja.
        $sekarang = $this->jenis->periodeDenganJam() ? $now->format('Y-m-d H:i:s') : $now->format('Y-m-d');

        $rows = $this->db->table($t)
            ->select("{$t}.{$k}, {$t}.url, {$t}.keterangan")
            ->join($u, "{$u}.{$k} = {$t}.{$k}")
            ->where("{$u}.nip", $nip)
            ->where("{$t}.tgl_mulai <=", $sekarang)
            ->where("{$t}.tgl_akhir >=", $sekarang)
            ->orderBy("{$t}.order", 'ASC')
            ->orderBy("{$t}.{$k}", 'ASC')
            ->get()->getResultArray();

        return array_map(fn (array $r): array => [
            'id'         => (int) $r[$k],
            'url'        => $r['url'] !== null && $r['url'] !== '' ? (string) $r['url'] : null,
            'gambar_url' => $this->urlGambar((int) $r[$k]),
            'keterangan' => $r['keterangan'] !== null && $r['keterangan'] !== '' ? (string) $r['keterangan'] : null,
        ], $rows);
    }

    /**
     * Isi gambar untuk role 1 atau penerima (snapshot). Selain itu 404 (tidak membocorkan keberadaan).
     *
     * @return array{isi: string, mime: string, nama: string}
     */
    public function bacaGambar(int $id, ?int $role, ?string $nip): array
    {
        $r = $this->ambil($id);

        $boleh = $role === Role::SUPER_ADMIN;

        if (! $boleh && $nip !== null && $nip !== '') {
            $boleh = $this->db->table($this->jenis->tabelPenerima()->value)->where([$this->jenis->kolomId() => $id, 'nip' => $nip])->countAllResults() > 0;
        }

        $kunci = (string) $r['file'];

        if (! $boleh || ! $this->gambar->ada($kunci)) {
            throw new NotFoundException('Gambar tidak ditemukan.');
        }

        $ext = NamaBerkas::ekstensi($kunci);

        return ['isi' => $this->gambar->baca($kunci), 'mime' => self::MIME[$ext] ?? 'application/octet-stream', 'nama' => $this->jenis->value . '-' . $id . '.' . $ext];
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $nilai
     */
    private function simpanBaru(array $nilai, string $kunci, int $idPengguna): int
    {
        $t    = $this->jenis->value;
        $maks = (int) ($this->db->table($t)->selectMax('order', 'm')->get()->getRow()->m ?? 0);

        $id = $this->model()->insert($this->kolom($nilai) + [
            'file'       => $kunci,
            'order'      => $maks + 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'created_by' => $idPengguna,
            'updated_by' => $idPengguna,
        ], true);

        if (! is_int($id) && ! ctype_digit((string) $id)) {
            throw new ApiException('Gagal menyimpan data.', 500);
        }

        $this->sinkron->sinkron($this->jenis->tabelPenerima(), (int) $id, $this->pemekar->pekarkan($nilai['target']));

        return (int) $id;
    }

    /**
     * @param array{tgl_mulai: string, tgl_akhir: string, url: ?string, keterangan: ?string, target: SpesifikasiTarget} $nilai
     *
     * @return array<string, mixed>
     */
    private function kolom(array $nilai): array
    {
        return [
            'tgl_mulai'  => $nilai['tgl_mulai'],
            'tgl_akhir'  => $nilai['tgl_akhir'],
            'url'        => $nilai['url'],
            'keterangan' => $nilai['keterangan'],
        ] + $nilai['target']->keKolom();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{tgl_mulai: string, tgl_akhir: string, url: ?string, keterangan: ?string, target: SpesifikasiTarget}
     */
    private function validasi(array $input, bool $baru): array
    {
        $mulai = $this->periode('tgl_mulai', $input['tgl_mulai'] ?? null);
        $akhir = $this->periode('tgl_akhir', $input['tgl_akhir'] ?? null);

        if ($akhir < $mulai) {
            throw ValidationException::forField('tgl_akhir', 'Tanggal selesai tidak boleh sebelum tanggal mulai.');
        }

        $url = isset($input['url']) ? trim((string) $input['url']) : '';

        if ($url !== '' && (strlen($url) > self::URL_MAKS || preg_match('#^https?://[^\s]+$#i', $url) !== 1)) {
            throw ValidationException::forField('url', 'Tautan harus diawali http:// atau https:// (maks ' . self::URL_MAKS . ' karakter).');
        }

        $ket = isset($input['keterangan']) ? trim((string) $input['keterangan']) : '';

        if (strlen($ket) > self::KETERANGAN_MAKS) {
            throw ValidationException::forField('keterangan', 'Keterangan terlalu panjang.');
        }

        $target = $input['target'] ?? [];

        if (is_string($target)) {
            $target = json_decode($target, true);
        }

        try {
            $spes = SpesifikasiTarget::dariArray(is_array($target) ? $target : []);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::forField('target', $e->getMessage());
        }

        if ($spes->kosong()) {
            throw ValidationException::forField('target', 'Pilih minimal satu target (unit/satker atau pegawai).');
        }

        return ['tgl_mulai' => $mulai, 'tgl_akhir' => $akhir, 'url' => $url === '' ? null : $url, 'keterangan' => $ket === '' ? null : $ket, 'target' => $spes];
    }

    /**
     * Normalisasi periode: popup `Y-m-d H:i[:s]` → `Y-m-d H:i:s`; banner `Y-m-d`.
     */
    private function periode(string $field, mixed $nilai): string
    {
        $s     = is_string($nilai) ? trim($nilai) : '';
        $zona  = new DateTimeZone('Asia/Jakarta');
        $label = $field === 'tgl_mulai' ? 'Tanggal mulai' : 'Tanggal selesai';

        if ($this->jenis->periodeDenganJam()) {
            foreach (['!Y-m-d H:i:s', '!Y-m-d H:i'] as $f) {
                $d = DateTimeImmutable::createFromFormat($f, $s, $zona);

                if ($d !== false && $d->format(substr($f, 1)) === $s) {
                    return $d->format('Y-m-d H:i:s');
                }
            }

            throw ValidationException::forField($field, "{$label} wajib diisi dengan format YYYY-MM-DD HH:MM.");
        }

        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s, $zona);

        if ($d === false || $d->format('Y-m-d') !== $s) {
            throw ValidationException::forField($field, "{$label} wajib diisi dengan format YYYY-MM-DD.");
        }

        return $s;
    }

    /**
     * @return array<string, mixed>
     */
    private function ambil(int $id): array
    {
        $r = $this->db->table($this->jenis->value)->where($this->jenis->kolomId(), $id)->get()->getRowArray();

        if (! is_array($r)) {
            throw new NotFoundException($this->jenis->label() . ' tidak ditemukan.');
        }

        return $r;
    }

    private function hitungPenerima(int $id): int
    {
        return (int) $this->db->table($this->jenis->tabelPenerima()->value)->where($this->jenis->kolomId(), $id)->countAllResults();
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function baris(array $r): array
    {
        $k = $this->jenis->kolomId();

        return [
            'id'              => (int) $r[$k],
            'keterangan'      => $r['keterangan'] !== null ? (string) $r['keterangan'] : null,
            'url'             => $r['url'] !== null ? (string) $r['url'] : null,
            'gambar_url'      => $this->urlGambar((int) $r[$k]),
            'tgl_mulai'       => (string) $r['tgl_mulai'],
            'tgl_akhir'       => (string) $r['tgl_akhir'],
            'urutan'          => (int) $r['order'],
            'jumlah_penerima' => (int) ($r['jumlah_penerima'] ?? 0),
        ];
    }

    private function urlGambar(int $id): string
    {
        return '/konten/' . $this->jenis->value . '/' . $id . '/gambar';
    }

    private function model(): BaseAuditableModel
    {
        return $this->jenis === JenisGambar::Popup ? new PopupModel() : new BannerModel();
    }

    /**
     * Ada kiriman berkas (kolom tidak kosong). Keabsahan (galat unggah, ukuran, isi) diputuskan {@see PenyimpananGambar::periksa()}.
     *
     * @phpstan-assert-if-true UploadedFile $berkas
     */
    private static function adaBerkas(?UploadedFile $berkas): bool
    {
        return $berkas !== null && $berkas->getError() !== UPLOAD_ERR_NO_FILE;
    }

    private function batalTransaksi(): void
    {
        if ($this->db->transDepth > 0) {
            $this->db->transRollback();
        }
    }

    private function hapusDiam(string $kunci): void
    {
        try {
            $this->gambar->hapus($kunci);
        } catch (Throwable) {
            // Berkas yatim tidak boleh menggagalkan permintaan; dibersihkan manual.
        }
    }

    /**
     * @param array<string, mixed> $r
     */
    private static function namaLengkap(array $r): string
    {
        return trim(implode(' ', array_filter([(string) ($r['glr_awal'] ?? ''), (string) ($r['nama'] ?? ''), (string) ($r['glr_akhir'] ?? '')], static fn (string $s): bool => $s !== '')));
    }
}
