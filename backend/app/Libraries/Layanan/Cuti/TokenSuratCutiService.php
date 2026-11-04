<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
use App\Interfaces\Layanan\TautanSuratCutiInterface;
use App\Interfaces\Layanan\TokenSuratCutiInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\SlipGaji\TokenQr;
use App\Models\AuditLogModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Encryption\EncrypterInterface;
use CodeIgniter\Encryption\Exceptions\EncryptionException;
use Throwable;

/**
 * Token publik surat cuti (PRD Fase 6 v1.0f Q6-22/Q6-23; tabel `surat_cuti_token` DBV-030, disetujui DBV @227805a9):
 *
 * - Satu token per cuti (DBV-030-Q3). Terbit saat pertama dibutuhkan (surat belum TTE dirender / QR tanda tangan dibuat):
 *   `random_bytes(32)` → 64 heks ({@see TokenQr}); DB hanya menyimpan `hash('sha256')` dan salinan terenkripsi Encrypter CI4
 *   untuk mencetak ulang QR (pola `TokenSlipService`, ADR-032). Token mentah tidak pernah disimpan, dicatat, atau dikirim ke
 *   klien selain di dalam QR/URL.
 * - Token yang SAMA dipakai URL verifikasi (QR kaki surat, Q6-23) dan URL PDF ber-TTE (QR tanda tangan, Q6-22).
 * - Rotasi (token baru, URL lama mati) HANYA sebelum surat ber-TTE (keputusan DBV-030 Q3); sesudah TTE hanya nonaktif
 *   (status 2 → semua URL 404) / aktif kembali. Rotasi, nonaktif, aktif tercatat di `audit_logs` (tanpa nilai token),
 *   berjalan di dalam {@see KunciSuratCuti} + transaksi (review B4a P3). Jalur operator: `php spark surat-cuti:token`.
 * - Penghapusan cuti: baris token dihapus `CutiService::hapus` di transaksi yang sama (DBV-030 Q2, FK RESTRICT).
 */
final class TokenSuratCutiService implements TokenSuratCutiInterface, TautanSuratCutiInterface
{
    public const TABEL = 'surat_cuti_token';

    public const AKTIF = 1;

    public const NONAKTIF = 2;

    public const PESAN_ROTASI_SESUDAH_TTE = 'Token surat cuti yang sudah ditandatangani tidak dapat diganti; nonaktifkan bila perlu dicabut.';

    public const PESAN_TANPA_TOKEN = 'Surat cuti ini belum memiliki token.';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly string $urlVerifikasi,
        private readonly string $urlPdf,
        private readonly ?EncrypterInterface $encrypter = null,
        private readonly ?AttachmentServiceInterface $lampiran = null,
    ) {
    }

    public function idCutiAktif(string $tokenMentah): ?int
    {
        if (! TokenQr::valid($tokenMentah)) {
            return null;
        }

        $baris = $this->db->table(self::TABEL)->select('id_riwayat_cuti')
            ->where(['token_hash' => hash('sha256', $tokenMentah), 'status' => self::AKTIF])->get(1)->getRowArray();

        return $baris === null ? null : (int) $baris['id_riwayat_cuti'];
    }

    /**
     * Token mentah cuti ini (terbit bila belum ada). Null bila token ada tetapi tidak dapat didekripsi (QR dicetak tanpa
     * tautan, pola slip).
     */
    public function untukCuti(int $idCuti, ?int $idPengguna = null): ?string
    {
        $baris = $this->baris($idCuti);

        if ($baris === null) {
            try {
                $baru = $this->buat();
            } catch (EncryptionException) {
                // Tanpa `encryption.key` token tidak bisa disimpan (salinan terenkripsi wajib): surat tetap terbit, QR tanpa tautan.
                log_message('warning', 'Token surat cuti tidak diterbitkan: encryption.key belum diisi.');

                return null;
            }

            try {
                $this->db->table(self::TABEL)->insert([
                    'id_riwayat_cuti' => $idCuti,
                    'token_hash'      => $baru['hash'],
                    'token_enkripsi'  => $baru['enkripsi'],
                    'status'          => self::AKTIF,
                    'created_at'      => gmdate('Y-m-d H:i:s'),
                    'created_by'      => $idPengguna,
                ]);
            } catch (Throwable $e) {
                // Balapan dua penerbitan untuk cuti yang sama (UNIQUE per cuti): pakai baris pemenang.
                $baris = $this->baris($idCuti) ?? throw $e;

                return $this->mentah((string) $baris['token_enkripsi']);
            }

            return $baru['mentah'];
        }

        return $this->mentah((string) $baris['token_enkripsi']);
    }

    public function urlVerifikasi(string $tokenMentah): string
    {
        return $this->urlVerifikasi . $tokenMentah;
    }

    /**
     * URL PDF ber-TTE untuk QR tanda tangan (dipasang sebelum ditandatangani).
     */
    public function urlPdfTte(int $idCuti): ?string
    {
        $mentah = $this->untukCuti($idCuti);

        return $mentah === null ? null : $this->urlPdf . $mentah . '/pdf';
    }

    /**
     * Ganti token (URL lama mati). Hanya sebelum surat ber-TTE (DBV-030 Q3): status TTE dibaca di sini dari lampiran
     * {@see CutiService::ID_SURAT_TTE}, di dalam {@see KunciSuratCuti} (tidak bersaing dengan e-sign yang sedang membuat QR
     * ber-token) dan transaksi dengan baris pegawai pemohon terkunci (review B4a P3). `$auth` null = operator CLI.
     */
    public function rotasi(?AuthContext $auth, int $idCuti): string
    {
        return $this->terkunci($idCuti, function (string $nipPemohon) use ($auth, $idCuti): string {
            if ($this->sudahTte($nipPemohon, $idCuti)) {
                throw ValidationException::forField('token', self::PESAN_ROTASI_SESUDAH_TTE);
            }

            $baris = $this->baris($idCuti);
            $baru  = $this->buat();

            if ($baris === null) {
                $this->db->table(self::TABEL)->insert([
                    'id_riwayat_cuti' => $idCuti,
                    'token_hash'      => $baru['hash'],
                    'token_enkripsi'  => $baru['enkripsi'],
                    'status'          => self::AKTIF,
                    'created_at'      => gmdate('Y-m-d H:i:s'),
                    'created_by'      => $auth?->idPengguna(),
                ]);
                $this->audit($auth, $idCuti, null, ['aksi' => 'rotasi', 'status' => self::AKTIF]);

                return $baru['mentah'];
            }

            $this->db->table(self::TABEL)->where('id_surat_cuti_token', $baris['id_surat_cuti_token'])
                ->update(['token_hash' => $baru['hash'], 'token_enkripsi' => $baru['enkripsi'], 'status' => self::AKTIF, 'nonaktif_at' => null]);
            $this->audit($auth, $idCuti, ['status' => (int) $baris['status']], ['aksi' => 'rotasi', 'status' => self::AKTIF]);

            return $baru['mentah'];
        });
    }

    /**
     * Cabut akses publik (verifikasi + PDF → 404) tanpa mengganti token. Mengembalikan status sesudahnya.
     */
    public function nonaktifkan(?AuthContext $auth, int $idCuti): int
    {
        return $this->ubahStatus($auth, $idCuti, self::NONAKTIF);
    }

    public function aktifkan(?AuthContext $auth, int $idCuti): int
    {
        return $this->ubahStatus($auth, $idCuti, self::AKTIF);
    }

    /**
     * Status token cuti (1 aktif / 2 nonaktif), null bila belum ada.
     */
    public function status(int $idCuti): ?int
    {
        $baris = $this->baris($idCuti);

        return $baris === null ? null : (int) $baris['status'];
    }

    private function ubahStatus(?AuthContext $auth, int $idCuti, int $status): int
    {
        return $this->terkunci($idCuti, function () use ($auth, $idCuti, $status): int {
            $baris = $this->baris($idCuti) ?? throw ValidationException::forField('token', self::PESAN_TANPA_TOKEN);

            if ((int) $baris['status'] === $status) {
                return $status;
            }

            $this->db->table(self::TABEL)->where('id_surat_cuti_token', $baris['id_surat_cuti_token'])
                ->update(['status' => $status, 'nonaktif_at' => $status === self::NONAKTIF ? gmdate('Y-m-d H:i:s') : null]);
            $this->audit($auth, $idCuti, ['status' => (int) $baris['status']], ['aksi' => $status === self::NONAKTIF ? 'nonaktif' : 'aktif', 'status' => $status]);

            return $status;
        });
    }

    /**
     * Kunci per cuti → transaksi (pola proyek: transBegin → cek status → commit/rollback → resetTransStatus) → baris pegawai
     * pemohon `FOR UPDATE` (urutan kunci sama dengan transaksi e-sign) → kerja. Cuti tidak ada → 404.
     *
     * @template T
     *
     * @param Closure(string): T $kerja menerima NIP pemohon
     *
     * @return T
     */
    private function terkunci(int $idCuti, Closure $kerja): mixed
    {
        return KunciSuratCuti::dengan($this->db, $idCuti, function () use ($idCuti, $kerja): mixed {
            $cuti = $this->db->table('riwayat_cuti')->select('nip')->where('id_riwayat_cuti', $idCuti)->get(1)->getRowArray()
                ?? throw new NotFoundException();
            $nip = (string) $cuti['nip'];

            $this->db->transBegin();

            try {
                $this->db->query('SELECT nip FROM ' . $this->db->prefixTable('pegawai') . ' WHERE nip = ? FOR UPDATE', [$nip]);
                $hasil = $kerja($nip);

                if (! $this->db->transStatus()) {
                    throw new DatabaseException('Transaksi token surat cuti gagal.');
                }

                $this->db->transCommit();
                $this->db->resetTransStatus();

                return $hasil;
            } catch (Throwable $e) {
                $this->db->transRollback();
                $this->db->resetTransStatus();

                throw $e;
            }
        });
    }

    private function sudahTte(string $nipPemohon, int $idCuti): bool
    {
        return $this->lampiran()->daftar($nipPemohon, CutiService::ID_SURAT_TTE, $idCuti) !== [];
    }

    /**
     * @param array<string, mixed>|null $sebelum
     * @param array<string, mixed>      $sesudah
     */
    private function audit(?AuthContext $auth, int $idCuti, ?array $sebelum, array $sesudah): void
    {
        $sesudah = ['jenis_audit' => 'cuti.token_surat'] + $sesudah + ['sumber' => $auth === null ? 'cli' : 'api'];

        (new AuditLogModel($this->db))->record(self::TABEL, (string) $idCuti, AuditLogModel::EVENT_UPDATE, $sebelum, $sesudah, $auth?->idPengguna(), $auth?->nip());
    }

    private function lampiran(): AttachmentServiceInterface
    {
        return $this->lampiran ?? service('attachmentService');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function baris(int $idCuti): ?array
    {
        return $this->db->table(self::TABEL)->where('id_riwayat_cuti', $idCuti)->get(1)->getRowArray();
    }

    /**
     * @return array{mentah: string, hash: string, enkripsi: string}
     */
    private function buat(): array
    {
        $mentah = TokenQr::dariByteAcak(random_bytes(TokenQr::PANJANG_BYTE));

        return ['mentah' => $mentah, 'hash' => hash('sha256', $mentah), 'enkripsi' => $this->encrypter()->encrypt($mentah)];
    }

    private function mentah(string $enkripsi): ?string
    {
        if ($enkripsi === '') {
            return null;
        }

        try {
            $mentah = $this->encrypter()->decrypt($enkripsi);
        } catch (Throwable) {
            log_message('warning', 'Token surat cuti terenkripsi tidak dapat didekripsi; QR dicetak tanpa tautan.');

            return null;
        }

        return TokenQr::valid($mentah) ? $mentah : null;
    }

    private function encrypter(): EncrypterInterface
    {
        return $this->encrypter ?? service('encrypter');
    }
}
