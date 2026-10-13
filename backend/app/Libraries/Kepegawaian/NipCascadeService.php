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
use App\Libraries\Auth\PasswordVerifier;
use App\Libraries\Kepegawaian\Nip\FormatNip;
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
 *  3. arahkan ulang setiap kolom registry `TabelAnakNip::sampaiFase(3)` (FK legacy, `pengguna.nip`, `faq_rate.nip`,
 *     `jabatan_koordinasi.nip` keputusan #10, …) dengan `UPDATE … WHERE kolom = NIP lama`;
 *  4. hapus baris `pegawai` lama — FK RESTRICT menjadi jaring pengaman: tabel anak yang tidak terdaftar membuat DELETE
 *     gagal sehingga seluruh koreksi di-rollback, bukan meninggalkan baris yatim;
 *  5. audit `audit_logs` (entity `pegawai`, event `update`, before/after NIP + jumlah baris per tabel).
 *
 * Perlakuan khusus:
 *  - Akun `pengguna` [K] ikut legacy penuh (:589-599, keputusan user 13-10-2026, menutup pertanyaan CR-036 #1):
 *    `username` akun pegawai itu SELALU diganti NIP baru dan password direset ke NIP baru — di-hash Argon2id lewat
 *    PasswordVerifier (bukan md5; `password_decode` tidak ada di v2), `password_legacy` dikosongkan,
 *    `password_changed_at` NULL seperti akun baru AccountProvisioner. Penanda "wajib ganti password" belum ada di skema
 *    v2 (kebutuhan skema, dicatat di laporan MAKE-010).
 *  - Sesi [V2]: refresh token menyimpan claims (termasuk `nip`) saat terbit, jadi seluruh sesi akun pegawai itu dicabut
 *    (`JwtService::revokeAllForUser`, baris token dihapus) di transaksi yang sama; pemilik login ulang dengan NIP baru.
 *    Kolom jejak `token.nip` (A-01 D-7) karena itu tidak diarahkan ulang.
 *  - Tabel registry yang belum ada di skema (mis. `user_lokasi_presensi`, G-03 memakai `dm_user_lokasi_presensi`)
 *    dilewati dan dilaporkan di `dilewati`; test B-21 mengunci daftarnya.
 *  - `gaji_pegawai.nip` dan `riwayat_cuti.*` (Fase 4/5) belum masuk cakupan: registry menambahkannya per fase tanpa
 *    mengubah kelas ini.
 */
final class NipCascadeService implements NipCascadeInterface
{
    /**
     * Cakupan registry pada fase berjalan (Fase 3).
     */
    public const FASE = 3;

    /**
     * Kolom registry yang tidak diarahkan ulang dengan UPDATE (tabel.kolom huruf kecil => alasan).
     */
    public const TIDAK_DIARAHKAN = [
        'token.nip'         => 'jejak NIP pemilik token (A-01 D-7); sesi akun dicabut',
        'pengguna.username' => 'ditangani khusus: username akun = NIP baru + reset password (legacy :589-599)',
    ];

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
        $this->wajibBerhasil($db, $db->table('pegawai')->insert($salinan), 'pegawai (salin)');

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

            $this->wajibBerhasil($db, $db->table($rujukan->tabel)->where($rujukan->kolomDiV2(), $lama)->update([$rujukan->kolomDiV2() => $baru]), $kunci);
            $tabel[$kunci] = $db->affectedRows();
        }

        // Akun pegawai: username = NIP baru + password = NIP baru [K] (L_employee.php:589-599, keputusan user 13-10-2026).
        $akunDiganti = $db->table('pengguna')->where('nip', $baru)->update([
            'username'            => $baru,
            'password'            => $this->passwords()->hash($baru),
            'password_legacy'     => null,
            'password_changed_at' => null,
            'updated_at'          => $sekarang,
        ]);
        $this->wajibBerhasil($db, $akunDiganti, 'pengguna.username');
        $tabel['pengguna.username'] = $db->affectedRows();

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
