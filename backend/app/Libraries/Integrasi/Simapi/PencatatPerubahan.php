<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Simapi;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * MAKE-060 (F6-06) — KERANGKA penulis `simapi_ch` (pengganti trigger DB legacy AFTER INSERT/UPDATE/DELETE pada `pegawai`,
 * `pengguna`, `detail_anak`, dan 13 `riwayat_*`). Dipanggil SESUDAH commit oleh pelanggan event {@see EVENT}; tulisan log tidak
 * boleh menggagalkan operasi pemilik data (galat hanya dicatat).
 *
 * ⚑ {@see SPESIFIKASI} SENGAJA KOSONG: per tabel, `id_simapi_jd`, isi `id_rwy`, nilai `action`, serta daftar & urutan kolom
 * `field_name`/`field_value` (pemisah `|`) wajib persis trigger legacy — sumbernya hanya DDL D1 (ISSUE-056 P14), seed `simapi_jd`
 * (P6) juga dari D1. Dilarang menebak (keputusan reviewer 06-11-2026). Selama kosong, setiap event diabaikan (no-op) dan tidak ada
 * pemancar event yang dipasang di layanan pemilik (MAKE-060 tahap A5b).
 *
 * Bentuk entri saat diisi (A5b):
 *   'riwayat_kp' => ['jenis_data' => '<simapi_jd.jenis_data>', 'id' => '<kolom id_rwy>', 'kolom' => ['<kolom1>', '<kolom2>', …]]
 * `id_simapi_jd` dicari lewat `jenis_data` (UNIQUE) sehingga tidak bergantung angka id. Baris akun sistem integrasi
 * (`pengguna` tanpa NIP, `sistem.integrasi`) tidak dicatat (PRD §7.5, CR-093).
 */
final class PencatatPerubahan
{
    public const EVENT = 'simapiPerubahan';

    public const AKSI_SIMPAN = 1; // INSERT / UPDATE [K AE:98]
    public const AKSI_HAPUS  = 2; // DELETE

    /** @var array<string, array{jenis_data: string, id: string, kolom: list<string>}> ⚑ menunggu D1 (ISSUE-056 P14) */
    public const SPESIFIKASI = [];

    /**
     * @param array<string, array{jenis_data: string, id: string, kolom: list<string>}> $spesifikasi
     */
    public function __construct(private readonly BaseConnection $db, private readonly array $spesifikasi = self::SPESIFIKASI)
    {
    }

    /**
     * Pelanggan event: `['tabel' => string, 'aksi' => 1|2, 'baris' => array<string, mixed>]`.
     *
     * @param array{tabel?: mixed, aksi?: mixed, baris?: mixed} $payload
     */
    public static function dariEvent(array $payload): void
    {
        try {
            (new self(db_connect()))->catat((string) ($payload['tabel'] ?? ''), (int) ($payload['aksi'] ?? 0), is_array($payload['baris'] ?? null) ? $payload['baris'] : []);
        } catch (Throwable $e) {
            log_message('error', 'SIMAPI pencatat perubahan gagal: ' . (new \ReflectionClass($e))->getShortName() . ' kode ' . $e->getCode() . '.');
        }
    }

    /**
     * @param array<string, mixed> $baris nilai SESUDAH perubahan (INSERT/UPDATE) atau SEBELUM (DELETE)
     *
     * @return bool true bila satu baris `simapi_ch` ditulis
     */
    public function catat(string $tabel, int $aksi, array $baris): bool
    {
        $spek = $this->spesifikasi[$tabel] ?? null;

        if ($spek === null || ! in_array($aksi, [self::AKSI_SIMPAN, self::AKSI_HAPUS], true)) {
            return false;
        }

        if ($tabel === 'pengguna' && Nilai::teks($baris['nip'] ?? null) === '') {
            return false; // akun sistem/non-pegawai tidak dicatat (CR-093)
        }

        $jd = $this->db->table('simapi_jd')->select('id_simapi_jd')->where('jenis_data', $spek['jenis_data'])->get()->getRowArray();

        if ($jd === null) {
            return false; // seed simapi_jd belum ada (DBV-026 §13.2 A7)
        }

        return (bool) $this->db->table('simapi_ch')->insert([
            'id_simapi_jd' => (int) $jd['id_simapi_jd'],
            'id_rwy'       => Nilai::mentah($baris[$spek['id']] ?? null),
            'nip'          => Nilai::mentah($baris['nip'] ?? null),
            'action'       => $aksi,
            'action_at'    => gmdate('Y-m-d H:i:s'),
            'field_name'   => implode('|', $spek['kolom']),
            'field_value'  => implode('|', array_map(static fn (string $k): string => Nilai::teks($baris[$k] ?? null), $spek['kolom'])),
        ]);
    }
}
