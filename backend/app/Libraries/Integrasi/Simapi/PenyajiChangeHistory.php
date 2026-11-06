<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Simapi;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * MAKE-060 (H-06, F6-06) — `simapi/v1/a_employee/change_history` [K `A_employee.php:20-118`], ack-on-read (AC-06.3):
 *  - filter jenis data = `simapi_auth_jd` klien (kosong → SEMUA jenis data, [K] `:65-68`);
 *  - baris `id_simapi_ch > MAX(simapi_auth_ch milik klien)` urut ASC, maksimum 100 [K `:73-89`];
 *  - setiap baris yang dikirim ditandai di `simapi_auth_ch` [K `:105-108`];
 *  - field per baris: `jenis_data, id_rwy, nip, action, action_at, field_name, field_value` (`id_simapi_ch` dibuang, `action`
 *    1 → "INSERT / UPDATE", lainnya "DELETE") [K `:84`, `:97-98`]; `action_at` disimpan UTC (DBV-026-Q5) dan dikirim WIB
 *    `Y-m-d H:i:s` (= `NOW()` server legacy) [V2 konversi].
 *
 * Tahan balapan (DBV-026 §13.2 A5): baca + tandai dalam SATU transaksi yang mengunci baris klien (`SELECT … FOR UPDATE` pada
 * `klien_layanan`), jadi dua panggilan serentak klien yang sama berurutan dan panggilan kedua tidak mengulang baris; penanda
 * ditulis `INSERT IGNORE` sebagai sabuk pengaman (PK `(id_klien, id_simapi_ch)`).
 */
final class PenyajiChangeHistory
{
    public const BATAS = 100;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return list<array<string, string|null>>
     */
    public function ambilDanTandai(int $idKlien): array
    {
        if ($this->db->transBegin() === false) {
            $this->db->resetTransStatus();

            throw new RuntimeException('Transaksi change_history tidak dapat dimulai.');
        }

        try {
            $kunci = $this->db->table('klien_layanan')->select('id_klien')->where('id_klien', $idKlien)->getCompiledSelect() . ' FOR UPDATE';
            if ($this->db->query($kunci) === false) {
                // Koneksi tanpa DBDebug: galat (mis. 1205 menunggu kunci klien) dikembalikan, bukan dilempar.
                throw new RuntimeException('Kunci klien change_history gagal.', (int) ($this->db->error()['code'] ?? 0));
            }

            $jd  = array_map(static fn (array $r): int => (int) $r['id_simapi_jd'], $this->db->table('simapi_auth_jd')->select('id_simapi_jd')->where('id_klien', $idKlien)->orderBy('id_simapi_jd', 'ASC')->get()->getResultArray());
            $max = (int) ($this->db->table('simapi_auth_ch')->selectMax('id_simapi_ch', 'maks')->where('id_klien', $idKlien)->get()->getRowArray()['maks'] ?? 0);

            $q = $this->db->table('simapi_ch sch')
                ->select('sjd.jenis_data, sch.id_simapi_ch, sch.id_rwy, sch.nip, sch.action, sch.action_at, sch.field_name, sch.field_value')
                ->join('simapi_jd sjd', 'sjd.id_simapi_jd = sch.id_simapi_jd', 'left')
                ->where('sch.id_simapi_ch >', $max);

            if ($jd !== []) {
                $q->whereIn('sch.id_simapi_jd', $jd);
            }

            $rows = $q->orderBy('sch.id_simapi_ch', 'ASC')->limit(self::BATAS)->get()->getResultArray();

            if ($rows !== []) {
                $sekarang = gmdate('Y-m-d H:i:s');
                $this->db->table('simapi_auth_ch')->ignore(true)->insertBatch(array_map(static fn (array $r): array => [
                    'id_klien'     => $idKlien,
                    'id_simapi_ch' => (int) $r['id_simapi_ch'],
                    'created_at'   => $sekarang,
                ], $rows));
            }

            if ($this->db->transStatus() === false || $this->db->transCommit() === false) {
                throw new RuntimeException('change_history gagal ditandai (transaksi).');
            }

            $this->db->resetTransStatus();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        }

        return array_map(static fn (array $r): array => [
            'jenis_data'  => Nilai::mentah($r['jenis_data']),
            'id_rwy'      => Nilai::mentah($r['id_rwy']),
            'nip'         => Nilai::mentah($r['nip']),
            'action'      => (string) $r['action'] === '1' ? 'INSERT / UPDATE' : 'DELETE',
            'action_at'   => self::keWib($r['action_at']),
            'field_name'  => Nilai::mentah($r['field_name']),
            'field_value' => Nilai::mentah($r['field_value']),
        ], $rows);
    }

    private static function keWib(mixed $utc): ?string
    {
        if ($utc === null || (string) $utc === '') {
            return null;
        }

        return (new DateTimeImmutable((string) $utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s');
    }
}
