<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi;

use App\Constants\AkunSistem;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use RuntimeException;
use Throwable;

/**
 * Akun sistem integrasi (DBV-027 §4, keputusan DBV Z-1 opsi c) — dibuat oleh command `integrasi:siapkan-akun-sistem`,
 * BUKAN seed migration: di cutover migration jalan di DB kosong, lalu impor `pengguna` menyalin PK legacy apa adanya, jadi
 * akun yang di-seed lebih dulu akan bentrok PK. Command dijalankan SESUDAH impor `pengguna` dan SEBELUM cron integrasi aktif.
 *
 * - {@see siapkan()}: transaksi tunggal, idempoten. Belum ada → dibuat (`sistem.integrasi`, level 1 ikut legacy DBV-Q10,
 *   status '0', nip/email/password/password_legacy NULL, must_change_password 0, stempel UTC). Sudah ada dan sesuai → tanpa
 *   perubahan. Sudah ada tetapi menyimpang (status ≠ '0', ada password/hash legacy, nip terisi, level ≠ 1, terhapus) →
 *   {@see AkunSistemMenyimpang} dengan NAMA kolom saja (tanpa nilai), tidak diperbaiki otomatis.
 * - {@see id()}: dipakai penulis H-03/H-10 di awal job untuk pelaku `updated_by`/`approved_by`; akun tidak ada/menyimpang →
 *   {@see AkunSistemTidakSiap} (job gagal eksplisit, tidak pernah menulis pelaku NULL/0).
 */
final class AkunSistemIntegrasi
{
    public const LEVEL = 1;

    public const HASIL_DIBUAT = 'dibuat';

    public const HASIL_SUDAH_ADA = 'sudah_ada';

    private const ER_DUP_ENTRY = 1062;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return self::HASIL_*
     *
     * @throws AkunSistemMenyimpang
     */
    public function siapkan(): string
    {
        for ($percobaan = 0; ; $percobaan++) {
            $this->db->transBegin();

            try {
                $sql   = $this->db->table('pengguna')->where('username', AkunSistem::USERNAME)->getCompiledSelect() . ' FOR UPDATE';
                $baris = $this->db->query($sql)->getRowArray();

                if ($baris !== null) {
                    $this->pastikanSesuai($baris);
                    $this->db->transCommit();

                    return self::HASIL_SUDAH_ADA;
                }

                $sekarang = gmdate('Y-m-d H:i:s');
                $this->db->table('pengguna')->insert([
                    'nip'                  => null,
                    'username'             => AkunSistem::USERNAME,
                    'name'                 => 'Sistem Integrasi',
                    'email'                => null,
                    'password'             => null,
                    'password_legacy'      => null,
                    'user_level'           => self::LEVEL,
                    'status'               => '0',
                    'must_change_password' => 0,
                    'created_at'           => $sekarang,
                    'updated_at'           => $sekarang,
                ]);

                if ($this->db->transStatus() === false) {
                    throw new RuntimeException('Transaksi akun sistem integrasi gagal.');
                }

                $this->db->transCommit();

                return self::HASIL_DIBUAT;
            } catch (DatabaseException $e) {
                $this->db->transRollback();

                // Balapan dua proses: yang kalah melihat baris pemenang pada percobaan kedua.
                if ((int) $e->getCode() !== self::ER_DUP_ENTRY || $percobaan >= 1) {
                    throw $e;
                }
            } catch (Throwable $e) {
                $this->db->transRollback();

                throw $e;
            }
        }
    }

    /**
     * Id akun sistem untuk pelaku penulisan job integrasi.
     *
     * @throws AkunSistemTidakSiap
     */
    public function id(): int
    {
        $baris = $this->db->table('pengguna')->where('username', AkunSistem::USERNAME)->get(1)->getRowArray();

        if ($baris === null) {
            throw new AkunSistemTidakSiap('Akun sistem integrasi belum ada; jalankan php spark integrasi:siapkan-akun-sistem.');
        }

        try {
            $this->pastikanSesuai($baris);
        } catch (AkunSistemMenyimpang $e) {
            throw new AkunSistemTidakSiap($e->getMessage(), 0, $e);
        }

        return (int) $baris['id_pengguna'];
    }

    /**
     * @param array<string, mixed> $baris
     *
     * @throws AkunSistemMenyimpang
     */
    private function pastikanSesuai(array $baris): void
    {
        $menyimpang = array_keys(array_filter([
            'status'          => (string) $baris['status'] !== '0',
            'password'        => $baris['password'] !== null,
            'password_legacy' => $baris['password_legacy'] !== null,
            'nip'             => $baris['nip'] !== null,
            'user_level'      => (int) $baris['user_level'] !== self::LEVEL,
            'deleted_at'      => ($baris['deleted_at'] ?? null) !== null,
        ]));

        if ($menyimpang !== []) {
            throw new AkunSistemMenyimpang($menyimpang);
        }
    }
}
