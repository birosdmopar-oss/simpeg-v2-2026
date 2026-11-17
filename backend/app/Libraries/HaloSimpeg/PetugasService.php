<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Libraries\ListQuery;
use App\Models\HaloSimpeg\OfficerCategoryModel;
use App\Models\HaloSimpeg\SupportOfficerModel;
use CodeIgniter\Database\BaseBuilder;

/**
 * Master petugas Halo Simpeg (E-02, MAKE-070; PRD Fase 8 v1.3 §6.1, §6.1b `Petugas`/`PetugasKandidat`/`PetugasSaya`, §7.2;
 * [K `LS:437-813`, `SC:315-438`]).
 *
 * - **Label akun** (§7.2): akun role 1 → nama akun; akun lain ber-pegawai → "NIP - nama bergelar - jabatan" [K `LS:470-491`];
 *   tanpa data pegawai → nama akun. Dipakai daftar petugas dan kandidat.
 * - **Kandidat** (F8-07): akun role 1 aktif + akun aktif milik pegawai aktif di satker kepegawaian
 *   (`WebConfigService::value('id_satker_kepegawaian')`, bukan literal `'101'` [K `LS:476-484`]). Satker belum diatur → hanya
 *   akun role 1 + `peringatan` (pola `PetugasLayananService::PERINGATAN_SATKER_KOSONG`). Kandidat = syarat `id_user` saat tambah
 *   atau saat `id_user` diganti (petugas lama tetap bisa diubah walau tidak lagi memenuhi syarat).
 * - **K-A4 (1')** (Horii 16-11-2026): hapus → 422 `petugas_terakhir` bila petugas itu petugas **terakhir** (aktif atau nonaktif)
 *   sebuah kategori yang masih punya tiket Open; Nonaktifkan petugas **aktif** terakhir boleh → 200 + `peringatan`.
 *   `bisa_hapus`/`alasan_tidak_bisa_hapus` dihitung dari kueri yang sama dengan penegakan.
 * - **Petugas aktif** (F8-06) = `support_officer.status = 1` dan akun `pengguna` aktif.
 * - Set kategori diganti dalam satu transaksi: baris yang dilepas dihapus, yang baru disisip (setara hapus + sisip [K `LS:627-629`],
 *   audit per baris yang berubah). Urutan daftar `created_at` naik [K `LS:441`] + `id` naik (DBV-028 N3); kategori per petugas urut
 *   `id` [K `LS:449`].
 */
final class PetugasService extends MasterHaloSimpeg
{
    public const PESAN_DUPLIKAT = 'Akun ini sudah terdaftar sebagai petugas.';

    public const PERINGATAN_SATKER_KOSONG = 'Satker kepegawaian belum diatur di Web Config; hanya akun Super Admin yang dapat dipilih.';

    private const KOLOM = 'so.id_support_officer, so.id_user, so.email, so.no_hp, so.status, so.created_at, so.updated_at, '
        . 'u.user_level, u.name AS nama_akun, u.username, u.nip, p.glr_awal, p.nama, p.glr_akhir, pmj.jabatan';

    /**
     * @param array<string, mixed> $query `status` (0/1), `q` (nama/NIP/email), `page`, `per_page`
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function daftar(array $query): array
    {
        $p       = $this->listQuery($query, ['status', 'q']);
        $status  = $this->filterStatus($p);
        $q       = $p->string('q');
        $page    = $p->page();
        $perPage = $this->perHalaman($p);

        $saring = function (BaseBuilder $b) use ($status, $q): BaseBuilder {
            if ($status !== null) {
                $b->where('so.status', $status);
            }

            if ($q !== '') {
                $like = ListQuery::likeLiteral($this->db, $q);
                $b->groupStart()->like('u.name', $like, 'both', null, true)->orLike('u.username', $like, 'both', null, true)
                    ->orLike('p.nama', $like, 'both', null, true)->orLike('u.nip', $like, 'both', null, true)
                    ->orLike('so.email', $like, 'both', null, true)->groupEnd();
            }

            return $b;
        };

        $total = (int) $saring($this->kueri(false))->countAllResults();
        $rows  = $saring($this->kueri())
            ->orderBy('so.created_at', 'ASC')->orderBy('so.id_support_officer', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['items' => $this->susun($rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * `GET petugas/kandidat?search=` (role 1, F8-07): maks `kandidatMaks` akun, akun role 1 lebih dulu (urut nama) lalu pegawai
     * (urut nama, NIP).
     *
     * @param array<string, mixed> $query
     *
     * @return array{items: list<array{id_user: int, label: string}>, peringatan: string|null}
     */
    public function kandidat(array $query): array
    {
        $cari = self::teks(ListQuery::from($query, ['search'])->string('search'));

        if (mb_strlen($cari) < $this->config->kandidatCariMin) {
            throw ValidationException::forField('search', "Kata kunci minimal {$this->config->kandidatCariMin} karakter.");
        }

        if (mb_strlen($cari) > $this->config->kandidatCariMaks) {
            throw ValidationException::forField('search', "Kata kunci maksimal {$this->config->kandidatCariMaks} karakter.");
        }

        $like  = ListQuery::likeLiteral($this->db, $cari);
        $maks  = $this->config->kandidatMaks;
        $admin = $this->kueriAdmin()->groupStart()->like('u.name', $like, 'both', null, true)->orLike('u.username', $like, 'both', null, true)->groupEnd()
            ->orderBy('u.name', 'ASC')->orderBy('u.id_pengguna', 'ASC')->limit($maks)->get()->getResultArray();

        $satker  = $this->satkerKepegawaian();
        $pegawai = [];

        if ($satker !== null) {
            $pegawai = $this->kueriPegawai($satker)->groupStart()->like('p.nama', $like, 'both', null, true)->orLike('p.nip', $like, 'both', null, true)->groupEnd()
                ->orderBy('p.nama', 'ASC')->orderBy('p.nip', 'ASC')->limit($maks)->get()->getResultArray();
        }

        $items = [];

        foreach ([...$admin, ...$pegawai] as $r) {
            $id = (int) $r['id_pengguna'];

            if (! isset($items[$id]) && count($items) < $maks) {
                $items[$id] = ['id_user' => $id, 'label' => self::label($r)];
            }
        }

        return ['items' => array_values($items), 'peringatan' => $satker === null ? self::PERINGATAN_SATKER_KOSONG : null];
    }

    /**
     * `GET petugas/saya` (role 1–7): selalu 200; `id_support_officer` tidak dikirim; `bisa_ekspor` = petugas aktif (F8-06).
     *
     * @return array{petugas: bool, aktif: bool, kategori: list<array{id: int, nama: string, status: int}>, bisa_ekspor: bool}
     */
    public function saya(?int $idPengguna): array
    {
        $so = $idPengguna === null ? null : $this->db->table('support_officer so')
            ->select('so.id_support_officer, so.status, u.status AS status_akun, u.deleted_at')
            ->join('pengguna u', 'u.id_pengguna = so.id_user', 'inner')
            ->where('so.id_user', $idPengguna)->get()->getRowArray();

        if (! is_array($so)) {
            return ['petugas' => false, 'aktif' => false, 'kategori' => [], 'bisa_ekspor' => false];
        }

        $aktif = (int) $so['status'] === self::STATUS_AKTIF && (string) $so['status_akun'] === '1' && $so['deleted_at'] === null;

        return [
            'petugas'     => true,
            'aktif'       => $aktif,
            'kategori'    => $this->kategoriPetugas([(int) $so['id_support_officer']])[(int) $so['id_support_officer']] ?? [],
            'bisa_ekspor' => $aktif,
        ];
    }

    /**
     * @param array<string, mixed> $in `id_user`, `category[]`, `email`, `no_hp?`, `status?` (bawaan 1)
     *
     * @return array<string, mixed>
     */
    public function buat(array $in): array
    {
        [$data, $kategori] = $this->validasi($in, null);
        $now               = self::sekarang();

        $id = (int) $this->dalamTransaksi(function () use ($data, $kategori, $now): int {
            $id = (int) (new SupportOfficerModel($this->db))->insert($data + ['created_at' => $now, 'updated_at' => $now], true);
            $this->tulisKategori($id, $kategori, $now);

            return $id;
        }, static fn () => self::galatKode('petugas_duplikat', self::PESAN_DUPLIKAT, 'id_user'));

        return $this->detail($id);
    }

    /**
     * @param array<string, mixed> $in `id_user`, `category[]`, `email`, `no_hp?`, `status?` (bawaan nilai lama)
     *
     * @return array<string, mixed>
     */
    public function ubah(int $id, array $in): array
    {
        [$data, $kategori] = $this->validasi($in, $this->ambil($id));
        $now               = self::sekarang();

        $this->dalamTransaksi(function () use ($id, $data, $kategori, $now): void {
            (new SupportOfficerModel($this->db))->update($id, $data + ['updated_at' => $now]);
            $this->tulisKategori($id, $kategori, $now);
        }, static fn () => self::galatKode('petugas_duplikat', self::PESAN_DUPLIKAT, 'id_user'));

        return $this->detail($id);
    }

    /**
     * `PATCH petugas/{id}/status` (K-A1): status sama → tanpa tulis/audit. Menonaktifkan petugas aktif terakhir sebuah kategori
     * ber-tiket Open tetap boleh → `peringatan` berisi nama kategorinya (K-A4 (1')).
     *
     * @return array<string, mixed>
     */
    public function setStatus(int $id, mixed $status): array
    {
        $lama       = $this->ambil($id);
        $baru       = self::statusDari($status) ?? throw self::galatStatus();
        $peringatan = null;

        if ($baru !== (int) $lama['status']) {
            if ($baru === self::STATUS_NONAKTIF) {
                $kategori   = $this->kategoriTerakhir([$id], true)[$id] ?? [];
                $peringatan = $kategori === [] ? null : 'Petugas ini adalah petugas aktif terakhir pada kategori ' . implode(', ', $kategori)
                    . '. Tiket terbuka pada kategori tersebut tidak memiliki penjawab sampai petugas lain diaktifkan.';
            }

            (new SupportOfficerModel($this->db))->update($id, ['status' => $baru, 'updated_at' => self::sekarang()]);
        }

        return $this->detail($id) + ['peringatan' => $peringatan];
    }

    /**
     * Hapus fisik [K `LS:709-711`]: `officer_category` lalu `support_officer` dalam satu transaksi (snapshot `before` di audit).
     *
     * @return array{id: int}
     */
    public function hapus(int $id): array
    {
        $petugas = $this->detail($id);

        if (! $petugas['bisa_hapus']) {
            throw self::galatKode('petugas_terakhir', (string) $petugas['alasan_tidak_bisa_hapus']);
        }

        $this->dalamTransaksi(function () use ($id): void {
            $relasi = array_map('intval', array_column($this->db->table('officer_category')->select('id')->where('id_support_officer', $id)->get()->getResultArray(), 'id'));

            if ($relasi !== []) {
                (new OfficerCategoryModel($this->db))->delete($relasi);
            }

            (new SupportOfficerModel($this->db))->delete($id);
        });

        return ['id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(int $id): array
    {
        return $this->susun([$this->ambil($id)])[0];
    }

    /** Teks 422 `petugas_terakhir` (juga `alasan_tidak_bisa_hapus`). */
    public static function pesanTerakhir(array $kategori): string
    {
        return 'Petugas ini petugas terakhir pada kategori ' . implode(', ', $kategori)
            . ' yang masih memiliki tiket terbuka. Tambahkan petugas lain lebih dulu.';
    }

    // ------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed>      $in
     * @param array<string, mixed>|null $lama
     *
     * @return array{0: array{id_user: int, email: string, no_hp: string|null, status: int}, 1: list<int>}
     */
    private function validasi(array $in, ?array $lama): array
    {
        $errors   = [];
        $idUser   = self::teks($in['id_user'] ?? null);
        $email    = self::teks($in['email'] ?? null);
        $noHp     = self::teks($in['no_hp'] ?? null);
        $status   = array_key_exists('status', $in) ? self::statusDari($in['status']) : ($lama === null ? self::STATUS_AKTIF : (int) $lama['status']);
        $kategori = $this->kategoriMasukan($in['category'] ?? null, $errors);

        if (preg_match('/^[1-9][0-9]{0,9}\z/', $idUser) !== 1) {
            $errors['id_user'] = 'Akun petugas wajib dipilih.';
        } elseif (($lama === null || (int) $idUser !== (int) $lama['id_user']) && ! $this->kandidatAda((int) $idUser)) {
            $errors['id_user'] = 'Akun tidak memenuhi syarat petugas.';
        }

        if ($email === '') {
            $errors['email'] = 'Email wajib diisi.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Format email tidak valid.';
        } elseif (mb_strlen($email) > $this->config->maksEmail) {
            $errors['email'] = "Email maksimal {$this->config->maksEmail} karakter.";
        }

        if (mb_strlen($noHp) > $this->config->maksNoHp) {
            $errors['no_hp'] = "Nomor HP maksimal {$this->config->maksNoHp} karakter.";
        }

        if ($status === null) {
            $errors['status'] = self::PESAN_STATUS;
        }

        self::lemparBilaGalat($errors);

        $dup = $this->db->table('support_officer')->where('id_user', (int) $idUser);

        if ($lama !== null) {
            $dup->where('id_support_officer !=', (int) $lama['id_support_officer']);
        }

        if ($dup->countAllResults() > 0) {
            throw self::galatKode('petugas_duplikat', self::PESAN_DUPLIKAT, 'id_user');
        }

        return [['id_user' => (int) $idUser, 'email' => $email, 'no_hp' => $noHp === '' ? null : $noHp, 'status' => (int) $status], $kategori];
    }

    /**
     * `category[]`: daftar id kategori, minimal satu, semuanya ada [K `LS:752-755`].
     *
     * @param array<string, string> $errors
     *
     * @return list<int>
     */
    private function kategoriMasukan(mixed $v, array &$errors): array
    {
        $daftar = is_array($v) ? array_values($v) : ($v === null || $v === '' ? [] : [$v]);
        $id     = [];

        foreach ($daftar as $x) {
            $s = is_scalar($x) ? trim((string) $x) : '';

            if (preg_match('/^[1-9][0-9]{0,9}\z/', $s) !== 1) {
                $errors['category'] = 'Kategori tidak tersedia.';

                return [];
            }

            $id[(int) $s] = (int) $s;
        }

        if ($id === []) {
            $errors['category'] = 'Minimal satu kategori.';

            return [];
        }

        if ($this->db->table('question_category')->whereIn('id_question_category', array_values($id))->countAllResults() !== count($id)) {
            $errors['category'] = 'Kategori tidak tersedia.';

            return [];
        }

        ksort($id);

        return array_values($id);
    }

    /**
     * Samakan set kategori petugas: lepas yang tidak dipilih, sisip yang baru (audit per baris).
     *
     * @param list<int> $kategori
     */
    private function tulisKategori(int $id, array $kategori, string $now): void
    {
        $model = new OfficerCategoryModel($this->db);
        $ada   = [];

        foreach ($this->db->table('officer_category')->select('id, id_question_category')->where('id_support_officer', $id)->get()->getResultArray() as $r) {
            $ada[(int) $r['id_question_category']] = (int) $r['id'];
        }

        $lepas = array_values(array_diff_key($ada, array_flip($kategori)));

        if ($lepas !== []) {
            $model->delete($lepas);
        }

        foreach (array_diff($kategori, array_keys($ada)) as $kat) {
            $model->insert(['id_support_officer' => $id, 'id_question_category' => $kat, 'created_at' => $now]);
        }
    }

    /**
     * Kategori yang ditinggal tanpa petugas lain bila petugas `$id` dihapus/dinonaktifkan, dan masih punya tiket Open.
     * `$aktifSaja` = false: petugas lain apa pun statusnya (hapus, K-A4 (1')); true: hanya petugas lain yang aktif (status 1 dan
     * akun aktif, F8-06) — untuk peringatan Nonaktifkan.
     *
     * @param list<int> $id
     *
     * @return array<int, list<string>> id petugas => nama kategori (urut id kategori)
     */
    private function kategoriTerakhir(array $id, bool $aktifSaja): array
    {
        if ($id === []) {
            return [];
        }

        $oc    = $this->db->prefixTable('officer_category');
        $so    = $this->db->prefixTable('support_officer');
        $u     = $this->db->prefixTable('pengguna');
        $tiket = $this->db->prefixTable('disiplin_ticket');
        $lain  = $aktifSaja
            ? "SELECT 1 FROM {$oc} o2 JOIN {$so} s2 ON s2.id_support_officer = o2.id_support_officer AND s2.status = 1"
                . " JOIN {$u} u2 ON u2.id_pengguna = s2.id_user AND u2.status = '1' AND u2.deleted_at IS NULL"
                . ' WHERE o2.id_question_category = oc.id_question_category AND o2.id_support_officer <> oc.id_support_officer'
            : "SELECT 1 FROM {$oc} o2 WHERE o2.id_question_category = oc.id_question_category AND o2.id_support_officer <> oc.id_support_officer";

        $rows = $this->db->table('officer_category oc')->select('oc.id_support_officer, qc.question_category')
            ->join('question_category qc', 'qc.id_question_category = oc.id_question_category', 'inner')
            ->whereIn('oc.id_support_officer', $id)
            ->where("EXISTS (SELECT 1 FROM {$tiket} t WHERE t.id_question_category = oc.id_question_category AND t.status = 0)", null, false)
            ->where("NOT EXISTS ({$lain})", null, false)
            ->orderBy('qc.id_question_category', 'ASC')->get()->getResultArray();

        $hasil = [];

        foreach ($rows as $r) {
            $hasil[(int) $r['id_support_officer']][] = (string) $r['question_category'];
        }

        return $hasil;
    }

    /**
     * @param list<int> $id
     *
     * @return array<int, list<array{id: int, nama: string, status: int}>>
     */
    private function kategoriPetugas(array $id): array
    {
        if ($id === []) {
            return [];
        }

        $rows = $this->db->table('officer_category oc')->select('oc.id_support_officer, qc.id_question_category, qc.question_category, qc.status')
            ->join('question_category qc', 'qc.id_question_category = oc.id_question_category', 'inner')
            ->whereIn('oc.id_support_officer', $id)->orderBy('qc.id_question_category', 'ASC')->get()->getResultArray();

        $hasil = [];

        foreach ($rows as $r) {
            $hasil[(int) $r['id_support_officer']][] = ['id' => (int) $r['id_question_category'], 'nama' => (string) $r['question_category'], 'status' => (int) $r['status']];
        }

        return $hasil;
    }

    /**
     * Baris → bentuk `Petugas`; kategori dan aturan hapus dimuat sekaligus untuk semua baris (tanpa N+1).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function susun(array $rows): array
    {
        $id       = array_map(static fn (array $r): int => (int) $r['id_support_officer'], $rows);
        $kategori = $this->kategoriPetugas($id);
        $terakhir = $this->kategoriTerakhir($id, false);

        return array_map(static function (array $r) use ($kategori, $terakhir): array {
            $i    = (int) $r['id_support_officer'];
            $blok = $terakhir[$i] ?? [];

            return [
                'id'                      => $i,
                'id_user'                 => (int) $r['id_user'],
                'label'                   => self::label($r),
                'email'                   => (string) $r['email'],
                'no_hp'                   => $r['no_hp'] === null || $r['no_hp'] === '' ? null : (string) $r['no_hp'],
                'status'                  => (int) $r['status'],
                'kategori'                => $kategori[$i] ?? [],
                'bisa_hapus'              => $blok === [],
                'alasan_tidak_bisa_hapus' => $blok === [] ? null : self::pesanTerakhir($blok),
            ];
        }, $rows);
    }

    /**
     * Label akun (§7.2): role 1 → nama akun; lain ber-pegawai → "NIP - nama bergelar - jabatan" [K `LS:485-490`]; selain itu
     * nama akun (atau username).
     *
     * @param array<string, mixed> $r kolom `user_level`, `nama_akun`, `username`, `nip`, `glr_awal`, `nama`, `glr_akhir`, `jabatan`
     */
    private static function label(array $r): string
    {
        $akun = trim((string) ($r['nama_akun'] ?? '')) !== '' ? trim((string) $r['nama_akun']) : (string) ($r['username'] ?? '');

        if ((int) ($r['user_level'] ?? 0) === Role::SUPER_ADMIN || ($r['nama'] ?? null) === null) {
            return $akun;
        }

        $bagian = [(string) $r['nip'], StrukturService::namaGelar($r['glr_awal'], (string) $r['nama'], $r['glr_akhir'])];

        if (trim((string) ($r['jabatan'] ?? '')) !== '') {
            $bagian[] = trim((string) $r['jabatan']);
        }

        return implode(' - ', $bagian);
    }

    /**
     * @return array<string, mixed>
     */
    private function ambil(int $id): array
    {
        $r = $this->kueri()->where('so.id_support_officer', $id)->get()->getRowArray();

        if (! is_array($r)) {
            throw self::tidakDitemukan();
        }

        return $r;
    }

    private function kueri(bool $kolom = true): BaseBuilder
    {
        $b = $this->db->table('support_officer so')
            ->join('pengguna u', 'u.id_pengguna = so.id_user', 'inner')
            ->join('pegawai p', 'p.nip = u.nip', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = u.nip', 'left');

        return $kolom ? $b->select(self::KOLOM) : $b;
    }

    /** Akun kandidat `$idUser` (satu sumber dengan daftar kandidat). */
    private function kandidatAda(int $idUser): bool
    {
        if ($this->kueriAdmin()->where('u.id_pengguna', $idUser)->countAllResults() > 0) {
            return true;
        }

        $satker = $this->satkerKepegawaian();

        return $satker !== null && $this->kueriPegawai($satker)->where('u.id_pengguna', $idUser)->countAllResults() > 0;
    }

    /** Akun role 1 aktif [K `LS:470`: `UserLevel=1 AND status=1`]. */
    private function kueriAdmin(): BaseBuilder
    {
        return $this->db->table('pengguna u')
            ->select('u.id_pengguna, u.user_level, u.name AS nama_akun, u.username, u.nip, NULL AS glr_awal, NULL AS nama, NULL AS glr_akhir, NULL AS jabatan', false)
            ->where('u.user_level', Role::SUPER_ADMIN)->where('u.status', '1')->where('u.deleted_at', null);
    }

    /** Akun aktif milik pegawai aktif di satker kepegawaian [K `LS:476-484`; akun aktif = V2 F8-07]. */
    private function kueriPegawai(int $satker): BaseBuilder
    {
        return $this->db->table('pegawai p')
            ->select('u.id_pengguna, u.user_level, u.name AS nama_akun, u.username, p.nip, p.glr_awal, p.nama, p.glr_akhir, pmj.jabatan')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = p.nip', 'inner')
            ->join('pengguna u', 'u.nip = p.nip', 'inner')
            ->where('p.status', 1)->where('pmj.id_satker', $satker)
            ->where('u.status', '1')->where('u.deleted_at', null);
    }

    private function satkerKepegawaian(): ?int
    {
        $satker = service('webConfigService')->value('id_satker_kepegawaian');

        return is_scalar($satker) && ctype_digit((string) $satker) ? (int) $satker : null;
    }
}
