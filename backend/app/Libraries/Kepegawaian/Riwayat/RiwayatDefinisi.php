<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\MasterData\MasterField;
use CodeIgniter\Database\BaseBuilder;

/**
 * Definisi satu jenis riwayat sebagai DATA (kontrak beku S0-A MAKE-002; pemilik WS-1). RiwayatEngine/SnapshotSync
 * (WS-1 M1) membaca definisi ini; tidak ada kode khusus per jenis di luar hook opsional di bawah.
 *
 * Satu berkas per jenis di `app/Libraries/Kepegawaian/Riwayat/Definisi/<Nama>.php` (namespace
 * `App\Libraries\Kepegawaian\Riwayat\Definisi`, nama kelas = nama berkas). RiwayatRegistry menemukannya lewat
 * auto-discovery — tidak ada daftar terpusat yang harus diedit.
 *
 * Hak akses per aksi per role disimpan di izin() mengikuti Matriks v2 (`docs/fase3/MATRIKS_ROLE_MODUL_B.md`), sehingga
 * perubahan hak cukup satu baris di definisi jenisnya. Lingkup pegawai (unit/satker/milik sendiri) BUKAN urusan
 * definisi — itu PegawaiScopeInterface.
 */
abstract class RiwayatDefinisi
{
    /**
     * Slug URL `{jenis}` — wajib salah satu JenisRiwayat::SLUG.
     */
    abstract public function jenis(): string;

    /**
     * Label tab/halaman, mis. 'Riwayat Pendidikan'.
     */
    abstract public function label(): string;

    /**
     * Tabel riwayat, mis. 'riwayat_pendidikan'.
     */
    abstract public function tabel(): string;

    /**
     * Kolom PK tabel riwayat, mis. 'id_riwayat_pendidikan'.
     */
    abstract public function primaryKey(): string;

    /**
     * Field yang boleh diisi lewat API (nama = kolom DDL, snake_case). Kolom sistem (PK, nip, status, created_at,
     * updated_at, updated_by, approved_by, reason_note) tidak ditulis di sini — dikelola engine.
     *
     * @return list<MasterField>
     */
    abstract public function fields(): array;

    /**
     * Izin per aksi: key AksiRiwayat::value ('lihat', 'tambah', 'ubah', 'hapus', 'proses') => daftar role
     * (App\Constants\Role). Aksi yang tidak ditulis = tidak ada role yang boleh.
     *
     * @return array<string, list<int>>
     */
    abstract public function izin(): array;

    abstract public function alur(): AlurRiwayat;

    public function kolomNip(): string
    {
        return 'nip';
    }

    public function kolomStatus(): string
    {
        return 'status';
    }

    /**
     * Pemetaan domain status: nilai kolom status => StatusRiwayat. Default = nilai StatusRiwayat yang berlaku
     * (0/1/2/10, plus 3 bila flag status Diproses aktif). Override bila tabel legacy memakai nilai lain.
     *
     * WAJIB override tanpa 3 untuk tabel yang CHECK status-nya `IN (0, 1, 2, 10)` di DDL main: `riwayat_ak`,
     * `riwayat_ak_siasn`, `riwayat_alamat`, `riwayat_karpeg`, `riwayat_kariskarsu`, `riwayat_skp_periodik` — kalau
     * tidak, flag status Diproses aktif membuat tulis status 3 ditolak DB.
     *
     * @return array<int, StatusRiwayat>
     */
    public function pemetaanStatus(): array
    {
        $peta = [];

        foreach (StatusRiwayat::berlaku() as $status) {
            $peta[$status->value] = $status;
        }

        return $peta;
    }

    /**
     * Baris berstatus Disetujui terkunci untuk UL_PEGAWAI (role 2/6/7): pegawai tidak bisa mengubah/menghapus baris
     * yang sudah disetujui; admin yang berizin tetap bisa. Override false bila jenis ini membolehkannya.
     */
    public function kunciBarisDisetujui(): bool
    {
        return true;
    }

    /**
     * Role selain UL_PEGAWAI yang juga tidak boleh MENGHAPUS baris berstatus Disetujui (aditif MAKE-004). Default role 3
     * ikut legacy: Admin Satker ditolak menghapus data yang sudah disetujui (`controllers/hr/rwy/Kp.php:379-387`,
     * `Pendidikan.php:476-484`); role 1 boleh.
     *
     * @return list<int>
     */
    public function roleKunciHapusDisetujui(): array
    {
        return [Role::ADMIN_SATKER];
    }

    /**
     * Status awal baris yang ditambahkan role $role (aditif MAKE-004). Default ikut legacy (`controllers/hr/rwy/
     * Pendidikan.php:146`): UL_PEGAWAI pada alur self-service/usulan → 0 Menunggu; selain itu → 1 Disetujui.
     */
    public function statusAwal(int $role): StatusRiwayat
    {
        return $this->alur() !== AlurRiwayat::Admin && in_array($role, Role::UL_PEGAWAI, true)
            ? StatusRiwayat::Menunggu
            : StatusRiwayat::Disetujui;
    }

    /**
     * Status baris setelah diubah role $role (aditif MAKE-004). Default ikut legacy (`controllers/hr/rwy/
     * Pendidikan.php:255-260`, `Kp.php:196-198`): UL_PEGAWAI pada alur self-service/usulan → 0 (diajukan ulang);
     * role 3 → 1; role lain (1) → status tidak berubah.
     *
     * $lama null = baris lama berstatus NULL (data impor, mis. `riwayat_pendidikan.status` INT NULL) atau nilai yang
     * tidak dikenal: diperlakukan seperti 0 Menunggu, jadi UL_PEGAWAI → 0 dan role 3 → 1; role 1 tetap tidak mengubah
     * status (tetap NULL, sama dengan legacy yang tidak mengirim kolom status). Hasil null = status tidak ditulis.
     */
    public function statusSetelahUbah(int $role, ?StatusRiwayat $lama): ?StatusRiwayat
    {
        if (in_array($role, Role::UL_PEGAWAI, true)) {
            return $this->alur() === AlurRiwayat::Admin ? $lama : StatusRiwayat::Menunggu;
        }

        return $role === Role::ADMIN_SATKER ? StatusRiwayat::Disetujui : $lama;
    }

    /**
     * Izin aksi yang bergantung pada pegawai sasaran (aditif MAKE-005, engine-v1). Dipanggil engine SESUDAH izin role
     * (`izin()`), PegawaiScope, dan keberadaan NIP; false → 403. Descriptor tab memakainya juga (`can_*`; `can_view`
     * false = tab tidak tampil). Default true. Contoh: Pendidikan — role 2 hanya boleh tambah/ubah/hapus bila pegawai
     * masih CPNS (legacy `controllers/hr/rwy/Pendidikan.php`).
     */
    public function bolehAksiUntuk(AuthContext $auth, AksiRiwayat $aksi, string $nip): bool
    {
        return true;
    }

    /**
     * Role yang boleh MEMILIH status baris saat ubah lewat field `status` payload (aditif MAKE-005). Default kosong:
     * status saat ubah dihitung statusSetelahUbah() dan `status` dari klien diabaikan. Untuk role di daftar ini, `status`
     * yang dikirim (0, 1, 2, atau 3 bila flag Diproses aktif; bukan 10) menggantikan statusSetelahUbah(); tidak dikirim =
     * aturan bawaan. Transisi yang terjadi diperlakukan sama (snapshot, penanda notifikasi, afterApprove). Memilih 2
     * Ditolak mewajibkan `reason_note` (maks 255 byte, sama dengan tolak di proses) → 422 `errors.reason_note`.
     *
     * @return list<int>
     */
    public function rolePilihStatusSaatUbah(): array
    {
        return [];
    }

    /**
     * Urutan daftar riwayat (aditif MAKE-004): kolom tabel riwayat => ASC|DESC, berurutan prioritas. Default PK terbaru
     * = [V2], bukan perilaku legacy (legacy KP: `ORDER BY status ASC, tmtsk ASC`, `libraries/hr/rwy/L_kp.php:27`);
     * Definisi jenis nyata meng-override sesuai legacy jenisnya (M2 dst.).
     *
     * @return array<string, string>
     */
    public function urutanDaftar(): array
    {
        return [$this->primaryKey() => 'DESC'];
    }

    /**
     * Kunci payload MENTAH (aditif MAKE-019, engine-v1): diteruskan engine APA ADANYA (tanpa ValidasiRiwayat; string,
     * array, atau null sesuai kiriman — JSON array, multipart `kolom[]` berulang, atau multipart `kolom=''`) ke
     * `validate()` dan `beforeSave()` saat tambah maupun ubah, HANYA bila klien mengirim kuncinya. Engine tidak pernah
     * menulis kunci ini ke tabel: Definisi memindahkan/menormalkannya ke kolom nyata di beforeSave (mis. jabatan:
     * `id_subrumpun_jabatan` daftar ID → teks nama `subrumpun_jabatan` dipisah `***`); sisa kunci mentah dibuang sebelum
     * insert/update. Nama tidak boleh sama dengan field `fields()`, kolom berkas `kolomBerkas()`, kolom NIP/status, atau PK
     * (RiwayatRegistry menolak), dan SEBAIKNYA bukan kolom DDL tabel sama sekali — kolom bernama sama tidak pernah ditulis
     * engine dari kunci ini (dibuang sesudah beforeSave), sehingga mudah disalahpahami.
     *
     * @return list<string>
     */
    public function isianMentah(): array
    {
        return [];
    }

    /**
     * Saring tambahan daftar riwayat (aditif MAKE-008, engine-v1): dipanggil engine di `GET riwayat/{jenis}` setelah
     * filter NIP & status != 10, sebelum urutanDaftar(). Bawaan tidak menyaring. Contoh: SKP periodik hanya menampilkan
     * baris status 1 (legacy `L_skp.php` periodic() :779). Detail/ubah per baris tidak ikut disaring.
     */
    public function saringDaftar(BaseBuilder $builder): void
    {
    }

    /**
     * Aturan snapshot `pegawai_*` (boleh lebih dari satu target; kosong = jenis tanpa snapshot).
     *
     * @return list<AturanSnapshot>
     */
    public function snapshot(): array
    {
        return [];
    }

    /**
     * Aturan lampiran, SATU entri per kode `jenis_rwy` (kolom `document_attachment.id_riwayat`); [] = jenis tanpa
     * lampiran. Satu tabel riwayat bisa memakai beberapa kode, mis. `riwayat_pendidikan` = 14 pendidikan,
     * 39 pencantuman_gelar, 40 transkrip_nilai; `riwayat_mutasi_jabatan` = 9 jabatan, 36 jabatan_pjft,
     * 41 perjanjian_kerja (seed `jenis_rwy` di main). Satu kode hanya boleh dimiliki satu Definisi (RiwayatRegistry
     * menolak kode ganda).
     *
     * @return list<AturanLampiran>
     */
    public function lampiran(): array
    {
        return [];
    }

    /**
     * Lampiran $aturan wajib untuk baris bernilai akhir $nilai (aditif MAKE-007, engine-v1). Bawaan = `$aturan->wajib`.
     * Override bila wajibnya bergantung isi baris, mis. KTP alamat tidak wajib untuk alamat kantor (legacy
     * `L_alamat.php` vp()). Dipanggil engine saat tambah/ubah sebelum transaksi.
     *
     * @param array<string, mixed> $nilai data tervalidasi (+ baris lama saat ubah)
     */
    public function lampiranWajib(AturanLampiran $aturan, array $nilai): bool
    {
        return $aturan->wajib;
    }

    /**
     * Berkas yang disimpan langsung di kolom tabel riwayat (aditif MAKE-007, engine-v1), mis. `riwayat_karpeg.file_1..5`:
     * kolom => aturan (batas MB, ekstensi; `idRiwayat` tidak dipakai, isi 0). Dikirim sebagai `berkas[<kolom>]`
     * (multipart), ditulis engine lewat KolomBerkas (MAKE-009) di transaksi tambah/ubah; berkas lama yang diganti dihapus
     * setelah commit. Galat 422 `errors["berkas.<kolom>"]`. Kolom ini BUKAN field Definisi (tidak bisa diisi teks).
     *
     * @return array<string, AturanLampiran>
     */
    public function kolomBerkas(): array
    {
        return [];
    }

    /**
     * Kolom berkas $kolom wajib untuk baris bernilai akhir $nilai (aditif MAKE-007): wajib = harus ada di request atau
     * sudah terisi di baris. Bawaan = `$aturan->wajib`.
     *
     * @param array<string, mixed> $nilai data tervalidasi (+ baris lama saat ubah)
     */
    public function kolomBerkasWajib(string $kolom, AturanLampiran $aturan, array $nilai): bool
    {
        return $aturan->wajib;
    }

    /**
     * Entri lampiran kode $kode yang BUKAN baris tabel riwayat ini, melainkan baris tabel anak (aditif MAKE-007,
     * engine-v1), mis. akte lahir (kode 3) per `detail_anak` milik `riwayat_keluarga`. Null (bawaan) = `id_entri` lampiran
     * kode itu adalah PK baris `tabel()`.
     *
     * Bila diisi: `tabel` = tabel anak, `pk` = PK-nya (`id_entri`), `induk` = kolom FK ke PK `tabel()`. Pemilik & status
     * entri dibaca dari baris induk (`kolomNip()`, `kolomStatus()`). Engine tidak mewajibkan/menerima berkas kode ini di
     * `POST/PUT riwayat` (berkas diunggah lewat endpoint tabel anak atau `POST lampiran`).
     *
     * @return array{tabel: string, pk: string, induk: string}|null
     */
    public function entriLampiran(int $kode): ?array
    {
        return null;
    }

    /**
     * Urutan tab di Detail Pegawai (kecil lebih dulu; seri diurutkan slug).
     */
    public function urutanTab(): int
    {
        return 100;
    }

    /**
     * Hook validasi tambahan setelah validasi field. $lama = baris sebelum diubah (null saat tambah). $data = field yang
     * dikirim (ternormalisasi; saat ubah hanya yang dikirim) ditambah kolom NIP pemilik (aditif MAKE-005), sehingga
     * aturan per pegawai (mis. duplikat tanggal) bisa diperiksa juga saat tambah. Gabungkan `$data + ($lama ?? [])` untuk
     * nilai akhir baris.
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $lama
     *
     * @return array<string, list<string>> error per field (kosong = lolos) → 422
     */
    public function validate(array $data, ?array $lama, AuthContext $auth): array
    {
        return [];
    }

    /**
     * Hook sebelum simpan (mis. mengisi kolom teks turunan dari master). Dipanggil di dalam transaksi engine. Saat tambah
     * $data juga berisi kolom NIP pemilik (aditif MAKE-008; saat ubah NIP ada di $lama).
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $lama
     *
     * @return array<string, mixed>
     */
    public function beforeSave(array $data, ?array $lama, AuthContext $auth): array
    {
        return $data;
    }

    /**
     * Hook setelah baris disetujui (approval final), di dalam transaksi engine, setelah SnapshotSync. Dipanggil pada
     * transisi MASUK status Disetujui: proses `setujui`, tambah langsung oleh admin, atau ubah oleh role 3.
     *
     * @param array<string, mixed> $baris
     */
    public function afterApprove(array $baris, AuthContext $auth): void
    {
    }

    /**
     * Hook setelah baris DIPROSES lewat `POST …/process` (aditif MAKE-008, engine-v1): dipanggil untuk $aksi `setujui`
     * maupun `tolak`, di dalam transaksi engine, sesudah status ditulis (dan sesudah SnapshotSync/afterApprove saat
     * setujui). Default no-op. Contoh: AK mengisi `appr_at`/`appr_by` untuk setujui dan tolak (legacy `L_ak.php`
     * process_ak()). Tidak dipanggil untuk perubahan status lewat tambah/ubah.
     *
     * @param array<string, mixed> $baris baris sesudah diproses
     */
    public function afterProses(string $aksi, array $baris, AuthContext $auth): void
    {
    }

    /**
     * Pratinjau isian yang akan diisi server saat TAMBAH (aditif MAKE-017, engine-v1): `GET
     * pegawai/{nip}/riwayat/{jenis}/pratinjau?<query>` dengan izin & lingkup aksi tambah. Null (bawaan) = jenis tanpa
     * pratinjau → 404. Logika wajib dipakai bersama dengan beforeSave (jangan diduplikasi), mis. AK: jabatan, pangkat,
     * TMT, masa kerja, AK kumulatif, AK lama.
     *
     * @param array<string, mixed> $query parameter query request
     *
     * @return array<string, mixed>|null
     */
    public function pratinjauTambah(string $nip, array $query): ?array
    {
        return null;
    }

    /**
     * Role $role punya izin $aksi pada jenis ini (tanpa memeriksa lingkup pegawai).
     */
    final public function boleh(AksiRiwayat $aksi, ?int $role): bool
    {
        if ($role === null || ! Role::isValid($role)) {
            return false;
        }

        return in_array($role, $this->izin()[$aksi->value] ?? [], true);
    }
}
