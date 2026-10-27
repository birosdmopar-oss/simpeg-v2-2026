<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\NipCascadeInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Auth\JwtService;
use App\Libraries\Auth\PasswordExpiry;
use App\Libraries\Auth\PasswordVerifier;
use App\Libraries\Kepegawaian\Nip\FormatNip;
use App\Libraries\Kepegawaian\Nip\JenisRujukanNip;
use App\Libraries\Kepegawaian\Nip\RujukanNip;
use App\Libraries\Kepegawaian\Nip\TabelAnakNip;
use App\Models\AuditLogModel;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Throwable;

/**
 * B-06 Koreksi NIP (MAKE-010) — implementasi nyata NipCascadeInterface (service `nipCascade`).
 *
 * Legacy `update_nip` (`libraries/hr/L_employee.php:550-619`) cukup menjalankan `UPDATE pegawai SET nip` karena semua
 * FK-nya `ON UPDATE CASCADE`, lalu mengganti akun `pengguna`. FK v2 `ON UPDATE RESTRICT` (K1), jadi koreksi dijalankan
 * aplikasi dalam SATU transaksi (keputusan #10):
 *
 *  1. kunci baris `pegawai` lama (`FOR UPDATE`), cek NIP baru belum dipakai pegawai lain [K] (:569-575) atau akun lain
 *     (username/NIP, ISSUE-023);
 *  2. salin baris `pegawai` ke NIP baru;
 *  3. arahkan ulang setiap kolom registry `TabelAnakNip::sampaiFase(self::FASE)` (FK legacy, `pengguna.nip`, `faq_rate.nip`,
 *     `jabatan_koordinasi.nip` keputusan #10, …) dengan `UPDATE … WHERE kolom = NIP lama`;
 *  4. hapus baris `pegawai` lama — FK RESTRICT menjadi jaring pengaman: tabel anak yang tidak terdaftar membuat DELETE
 *     gagal sehingga seluruh koreksi di-rollback, bukan meninggalkan baris yatim;
 *  5. audit `audit_logs` (entity `pegawai`, event `update`, before/after NIP + jumlah baris per tabel).
 *
 * Perlakuan khusus:
 *  - Akun `pengguna` [K] ikut legacy penuh (:589-599, keputusan user 13-10-2026, menutup pertanyaan CR-036 #1):
 *    `username` akun pegawai itu SELALU diganti NIP baru dan password direset ke NIP baru — di-hash Argon2id lewat
 *    PasswordVerifier (bukan md5; `password_decode` tidak ada di v2), `password_legacy` dikosongkan,
 *    `password_changed_at` NULL seperti akun baru AccountProvisioner, dan `must_change_password` = 1 (MAKE-020 [V2],
 *    kolom DBV-020): pemilik wajib mengganti password saat login berikutnya.
 *  - Sesi [V2]: refresh token menyimpan claims (termasuk `nip`) saat terbit, jadi seluruh sesi akun pegawai itu dicabut
 *    (`JwtService::revokeAllForUser`, baris token dihapus) di transaksi yang sama; pemilik login ulang dengan NIP baru.
 *    Kolom jejak `token.nip` (A-01 D-7) karena itu tidak diarahkan ulang.
 *  - Kolom JSON array NIP (`JenisRujukanNip::JsonArrayNip`: `dm_user_lokasi_presensi.target_peg`/`excl_peg`, pengganti
 *    `user_lokasi_presensi` yang tidak dibuat di v2) — elemen NIP lama diganti, begitu pula awalan "NIP - " di
 *    `*_desc` (keputusan user 13-10-2026, MAKE-011).
 *  - Kolom daftar NIP berpemisah (`JenisRujukanNip::DaftarNipBerpemisah`: `riwayat_cuti.ketua_tim`, NIP dipisah `***`,
 *    DBV-022 DQ-15) — elemen yang sama persis dengan NIP lama diganti; elemen lain dan urutan dipertahankan.
 *  - Tabel registry yang belum ada di skema dilewati dan dilaporkan di `dilewati`; test B-21 mengunci daftarnya.
 *  - Audit: `pegawai` (NIP + jumlah baris per tabel) dan setiap akun `pengguna` yang direset (password dimasking).
 *  - Balapan NIP baru didaftarkan proses lain (1062 pada salinan `pegawai`) → 422 `nip_baru`.
 *  - Cakupan per fase: `FASE` = 4 sejak DBV-022 M2 (tabel Modul C Layanan); `gaji_pegawai.nip` (Fase 5) menyusul dengan
 *    menaikkan `FASE` tanpa mengubah logika kelas ini.
 */
final class NipCascadeService implements NipCascadeInterface
{
    /**
     * Cakupan registry pada fase berjalan (Fase 4, DBV-022 M2).
     */
    public const FASE = 4;

    /**
     * Kolom registry yang tidak diarahkan ulang dengan UPDATE (tabel.kolom huruf kecil => alasan).
     */
    public const TIDAK_DIARAHKAN = [
        'token.nip'         => 'jejak NIP pemilik token (A-01 D-7); sesi akun dicabut',
        'pengguna.username' => 'ditangani khusus: username akun = NIP baru + reset password (legacy :589-599)',
    ];

    /**
     * PK tabel yang punya kolom JSON array NIP (`JenisRujukanNip::JsonArrayNip`).
     */
    private const PK_JSON = ['dm_user_lokasi_presensi' => 'id_dm_user_lokasi_presensi'];

    /**
     * PK tabel yang punya kolom daftar NIP berpemisah (`JenisRujukanNip::DaftarNipBerpemisah`).
     */
    private const PK_DAFTAR = ['riwayat_cuti' => 'id_riwayat_cuti'];

    /** Pemisah elemen `riwayat_cuti.ketua_tim` [K `L_cuti.php:1785-1800`]. */
    public const PEMISAH_DAFTAR_NIP = '***';

    /**
     * @var list<RujukanNip>
     */
    private readonly array $registry;

    /**
     * @param list<RujukanNip>|null $registry null = TabelAnakNip::sampaiFase(self::FASE); disuntik test untuk menguji
     *                                        rollback
     */
    public function __construct(
        private readonly ?ConnectionInterface $db = null,
        ?array $registry = null,
        private readonly ?JwtService $jwt = null,
        private readonly ?PasswordVerifier $passwords = null,
    ) {
        $this->registry = $registry ?? TabelAnakNip::sampaiFase(self::FASE);
    }

    public function koreksi(AuthContext $auth, string $nipLama, mixed $nipBaru): array
    {
        if (! $auth->isAuthenticated() || $auth->role() !== Role::SUPER_ADMIN) {
            throw new ForbiddenException('Koreksi NIP hanya untuk Super Admin.');
        }

        $periksa = FormatNip::periksaKoreksi($nipLama, $nipBaru);

        if (! $periksa->valid) {
            $periksa->lemparBilaGagal($periksa->kode === 'nip_lama_wajib' ? 'nip_lama' : 'nip_baru');
        }

        $lama = (string) $periksa->detail['nip_lama'];
        $baru = (string) $periksa->detail['nip_baru'];
        $db   = $this->koneksi();

        if ($db->table('pegawai')->where('nip', $lama)->countAllResults() === 0) {
            throw new NotFoundException('Pegawai tidak ditemukan.');
        }

        $db->transBegin();

        try {
            $hasil = $this->jalankan($db, $auth, $lama, $baru);

            if ($db->transStatus() === false) {
                throw new \RuntimeException('Transaksi koreksi NIP gagal.');
            }

            $db->transCommit();

            return $hasil;
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        } finally {
            $db->resetTransStatus();
        }
    }

    /**
     * @return array{nip_lama: string, nip_baru: string, tabel: array<string, int>, dilewati: list<string>, sesi_dicabut: int}
     */
    private function jalankan(BaseConnection $db, AuthContext $auth, string $lama, string $baru): array
    {
        $pegawai = $db->query('SELECT * FROM ' . $db->escapeIdentifiers($db->prefixTable('pegawai')) . ' WHERE nip = ? FOR UPDATE', [$lama])->getRowArray();

        if (! is_array($pegawai)) {
            throw new NotFoundException('Pegawai tidak ditemukan.');
        }

        $this->pastikanNipBaruBebas($db, $lama, $baru);

        $sekarang              = gmdate('Y-m-d H:i:s');
        $salinan               = $pegawai;
        $salinan['nip']        = $baru;
        $salinan['updated_at'] = $sekarang;
        $salinan['updated_by'] = $auth->idPengguna();
        try {
            $this->wajibBerhasil($db, $db->table('pegawai')->insert($salinan), 'pegawai (salin)');
        } catch (DatabaseException $e) {
            // Balapan: NIP baru didaftarkan proses lain sesudah cek keunikan → 422, bukan 500.
            if ((int) $e->getCode() === 1062) {
                throw ValidationException::forField('nip_baru', 'NIP baru sudah terdaftar; gunakan NIP lain.');
            }

            throw $e;
        }

        $tabel    = [];
        $dilewati = [];
        $ada      = $this->tabelAda($db);

        foreach ($this->registry as $rujukan) {
            $kunci = $rujukan->tabel . '.' . $rujukan->kolomDiV2();

            if (! isset($ada[strtolower($db->prefixTable($rujukan->tabel))])) {
                $dilewati[] = $kunci;

                continue;
            }

            if (isset(self::TIDAK_DIARAHKAN[$rujukan->kunci()])) {
                continue;
            }

            if ($rujukan->jenis === JenisRujukanNip::JsonArrayNip) {
                $tabel[$kunci] = $this->arahkanJson($db, $rujukan, $lama, $baru);

                continue;
            }

            if ($rujukan->jenis === JenisRujukanNip::DaftarNipBerpemisah) {
                $tabel[$kunci] = $this->arahkanDaftar($db, $rujukan, $lama, $baru);

                continue;
            }

            $this->wajibBerhasil($db, $db->table($rujukan->tabel)->where($rujukan->kolomDiV2(), $lama)->update([$rujukan->kolomDiV2() => $baru]), $kunci);
            $tabel[$kunci] = $db->affectedRows();
        }

        // Akun pegawai: username = NIP baru + password = NIP baru [K] (L_employee.php:589-599, keputusan user 13-10-2026).
        $akunLama    = $db->table('pengguna')->select('id_pengguna, username, nip')->where('nip', $baru)->get()->getResultArray();
        $akunDiganti = $db->table('pengguna')->where('nip', $baru)->update([
            'username'            => $baru,
            'password'            => $this->passwords()->hash($baru),
            'password_legacy'     => null,
            'password_changed_at' => null,
            // MAKE-020 [V2]: password = NIP baru diatur sistem → wajib ganti saat login berikutnya.
            'must_change_password' => 1,
            'updated_at'           => $sekarang,
        ]);
        $this->wajibBerhasil($db, $akunDiganti, 'pengguna.username');
        $tabel['pengguna.username'] = $db->affectedRows();

        // MAKE-028 [K]: password berubah → umur password +3 bulan, hanya untuk akun aktif (trigger legacy pengguna_beUpd).
        $this->wajibBerhasil(
            $db,
            $db->table('pengguna')->where('nip', $baru)->where('status', PenggunaModel::STATUS_ACTIVE)->update(PasswordExpiry::forPasswordChange(PenggunaModel::STATUS_ACTIVE, time())),
            'pengguna.expired_at',
        );

        // Audit akun (reset password karena koreksi NIP): `pengguna` tidak punya kolom updated_by, pelaku dicatat di audit.
        foreach ($akunLama as $akun) {
            (new AuditLogModel($db))->record(
                'pengguna',
                (int) $akun['id_pengguna'],
                AuditLogModel::EVENT_UPDATE,
                ['nip' => $lama, 'username' => $akun['username']],
                ['nip' => $baru, 'username' => $baru, 'password' => '***', 'alasan' => 'koreksi NIP (reset password = NIP baru)'],
                $auth->idPengguna(),
                $auth->nip(),
            );
        }

        $sesi = 0;

        foreach ($db->table('pengguna')->select('id_pengguna')->where('nip', $baru)->get()->getResultArray() as $akun) {
            $sesi += $this->jwt()->revokeAllForUser((int) $akun['id_pengguna']);
        }

        // FK RESTRICT: tabel anak di luar registry yang masih menunjuk NIP lama menggagalkan DELETE → rollback penuh.
        $this->wajibBerhasil($db, $db->table('pegawai')->where('nip', $lama)->delete(), 'pegawai (hapus lama)');

        (new AuditLogModel($db))->record(
            'pegawai',
            $baru,
            AuditLogModel::EVENT_UPDATE,
            ['nip' => $lama],
            ['nip' => $baru, 'koreksi_nip' => ['tabel' => $tabel, 'dilewati' => $dilewati, 'sesi_dicabut' => $sesi]],
            $auth->idPengguna(),
            $auth->nip(),
        );

        return ['nip_lama' => $lama, 'nip_baru' => $baru, 'tabel' => $tabel, 'dilewati' => $dilewati, 'sesi_dicabut' => $sesi];
    }

    /**
     * NIP baru belum dipakai pegawai lain [K] dan bukan username/NIP akun lain (akun pegawai lama sendiri dikecualikan).
     */
    private function pastikanNipBaruBebas(BaseConnection $db, string $lama, string $baru): void
    {
        $lain = $db->table('pegawai')->select('nip, nama, glr_awal, glr_akhir')->where('nip', $baru)->get()->getRowArray();

        if (is_array($lain)) {
            $nama = trim(implode(' ', array_filter([(string) $lain['glr_awal'], (string) $lain['nama']])))
                . ((string) $lain['glr_akhir'] !== '' ? ', ' . $lain['glr_akhir'] : '');

            throw ValidationException::forField('nip_baru', "NIP baru sudah terdaftar atas nama {$nama}; gunakan NIP lain.");
        }

        // Akun pegawai lama sendiri dikecualikan (username-nya diganti NIP baru).
        $akunLain = $db->table('pengguna')
            ->groupStart()->where('username', $baru)->orWhere('nip', $baru)->groupEnd()
            ->groupStart()->where('nip !=', $lama)->orWhere('nip', null)->groupEnd()
            ->countAllResults();

        if ($akunLain > 0) {
            throw ValidationException::forField('nip_baru', 'NIP baru sudah dipakai akun lain (username/NIP); gunakan NIP lain.');
        }
    }

    /**
     * Kolom JSON array NIP (`JsonArrayNip`): ganti elemen = NIP lama di `<kolom>` dan awalan "NIP lama - " di pasangan
     * `<kolom>_desc` (format legacy `Lm_lokasi.php`: "NIP - Nama"). Baris dikunci `FOR UPDATE`; JSON ditulis ulang dengan
     * format hook G-03 (UNESCAPED_UNICODE|UNESCAPED_SLASHES). Nilai yang bukan JSON array dibiarkan (data rusak tidak
     * diperbaiki di sini). Mengembalikan jumlah baris yang berubah.
     */
    private function arahkanJson(BaseConnection $db, RujukanNip $rujukan, string $lama, string $baru): int
    {
        $kolom = $rujukan->kolomDiV2();
        $desc  = $kolom . '_desc';
        $pk    = self::PK_JSON[$rujukan->tabel] ?? throw new \LogicException("PK tabel JSON NIP {$rujukan->tabel} belum didaftarkan.");
        $t     = $db->escapeIdentifiers($db->prefixTable($rujukan->tabel));
        $rows  = $db->query(
            "SELECT {$db->escapeIdentifiers($pk)} AS pk, {$db->escapeIdentifiers($kolom)} AS isi, {$db->escapeIdentifiers($desc)} AS deskripsi FROM {$t} WHERE {$db->escapeIdentifiers($kolom)} LIKE ? FOR UPDATE",
            ['%' . $db->escapeLikeString($lama) . '%'],
        )->getResultArray();
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $ubah  = 0;

        foreach ($rows as $row) {
            $isi = json_decode((string) $row['isi'], true);

            if (! is_array($isi) || ! in_array($lama, array_map('strval', $isi), true)) {
                continue;
            }

            $baris = [$kolom => (string) json_encode(array_map(static fn ($n): string => (string) $n === $lama ? $baru : (string) $n, array_values($isi)), $flags)];
            $d     = json_decode((string) $row['deskripsi'], true);

            if (is_array($d)) {
                $awalan       = $lama . ' - ';
                $baris[$desc] = (string) json_encode(array_map(
                    static fn ($v): string => str_starts_with((string) $v, $awalan) ? $baru . ' - ' . substr((string) $v, strlen($awalan)) : (string) $v,
                    array_values($d),
                ), $flags);
            }

            $this->wajibBerhasil($db, $db->table($rujukan->tabel)->where($pk, $row['pk'])->update($baris), $rujukan->tabel . '.' . $kolom);
            $ubah++;
        }

        return $ubah;
    }

    /**
     * Kolom daftar NIP berpemisah (`DaftarNipBerpemisah`): ganti elemen = NIP lama (setelah trim) dengan NIP baru, elemen lain
     * dan urutan tetap, lalu tulis ulang dengan pemisah `***`. Baris dikunci `FOR UPDATE`; `LIKE` hanya penyaring awal,
     * kecocokan ditentukan per elemen (NIP lama sebagai bagian NIP lain tidak ikut diganti). Mengembalikan jumlah baris.
     */
    private function arahkanDaftar(BaseConnection $db, RujukanNip $rujukan, string $lama, string $baru): int
    {
        $kolom = $rujukan->kolomDiV2();
        $pk    = self::PK_DAFTAR[$rujukan->tabel] ?? throw new \LogicException("PK tabel daftar NIP {$rujukan->tabel} belum didaftarkan.");
        $t     = $db->escapeIdentifiers($db->prefixTable($rujukan->tabel));
        $rows  = $db->query(
            "SELECT {$db->escapeIdentifiers($pk)} AS pk, {$db->escapeIdentifiers($kolom)} AS isi FROM {$t} WHERE {$db->escapeIdentifiers($kolom)} LIKE ? FOR UPDATE",
            ['%' . $db->escapeLikeString($lama) . '%'],
        )->getResultArray();
        $ubah = 0;

        foreach ($rows as $row) {
            $elemen = explode(self::PEMISAH_DAFTAR_NIP, (string) $row['isi']);

            if (! in_array($lama, array_map('trim', $elemen), true)) {
                continue;
            }

            $isi = implode(self::PEMISAH_DAFTAR_NIP, array_map(static fn (string $e): string => trim($e) === $lama ? $baru : $e, $elemen));
            $this->wajibBerhasil($db, $db->table($rujukan->tabel)->where($pk, $row['pk'])->update([$kolom => $isi]), $rujukan->tabel . '.' . $kolom);
            $ubah++;
        }

        return $ubah;
    }

    /**
     * Nama tabel (ber-prefix, huruf kecil) yang ada di skema aktif, dibaca langsung dari information_schema. Tidak memakai
     * `tableExists()`: daftar tabel CI4 di-cache per koneksi, sehingga cache yang terisi sebelum migrate (mis. proses test
     * CI) membuat semua tabel dianggap tidak ada.
     *
     * @return array<string, true>
     */
    private function tabelAda(BaseConnection $db): array
    {
        $rows = $db->query('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->getResultArray();

        return array_fill_keys(array_map(static fn (array $r): string => strtolower((string) $r['t']), $rows), true);
    }

    /**
     * Query builder mengembalikan false (tanpa exception) saat DBDebug = false: jangan biarkan satu langkah gagal diam-diam.
     */
    private function wajibBerhasil(BaseConnection $db, mixed $hasil, string $langkah): void
    {
        if ($hasil === false) {
            $error = $db->error();

            throw new DatabaseException("Koreksi NIP gagal di {$langkah}: " . $error['message'], (int) $error['code']);
        }
    }

    private function passwords(): PasswordVerifier
    {
        return $this->passwords ?? new PasswordVerifier(new PenggunaModel());
    }

    private function jwt(): JwtService
    {
        return $this->jwt ?? service('jwt');
    }

    private function koneksi(): BaseConnection
    {
        $db = $this->db ?? db_connect();

        if (! $db instanceof BaseConnection) {
            throw new \RuntimeException('Koneksi database tidak didukung.');
        }

        return $db;
    }
}
