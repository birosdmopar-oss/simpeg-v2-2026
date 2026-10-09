<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat;

use App\Interfaces\Kepegawaian\SnapshotSyncInterface;
use App\Libraries\Auth\AuthContext;
use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\I18n\Time;
use Throwable;

/**
 * Sinkron snapshot `pegawai_*` dari tabel riwayat (WS-1 M1 MAKE-004; ADR-006, dok DBV-012 §5.1).
 *
 * Untuk setiap AturanSnapshot Definisi (multi-target, mis. KP → pegawai_kp/pegawai_cpns/pegawai_pns): baca baris riwayat
 * Disetujui milik NIP itu (+ kolom urutan tabel join), pilih satu lewat PemilihSnapshot (fungsi murni), lalu
 *  - ada baris terpilih → INSERT atau UPDATE snapshot (kolom terpetakan; dilewati bila tidak ada yang berubah);
 *  - tidak ada → DELETE snapshot (bila ada).
 * Setiap tulisan dicatat manual di audit_logs (raw builder tidak memicu Model Events): entity = tabel snapshot,
 * entity_id = nilai kunci (NIP), event create/update/delete dengan before/after.
 *
 * Dipanggil RiwayatEngine di SETIAP transisi masuk/keluar status Disetujui (dan saat baris Disetujui diubah) di dalam
 * transaksi engine. Bila dipanggil di luar transaksi, semua target dibungkus satu transaksi sendiri (atomik).
 */
final class SnapshotSync implements SnapshotSyncInterface
{
    /**
     * @var array<string, list<string>> tabel → kolom DDL (cache per proses)
     */
    private array $kolom = [];

    private ?AuditLogModel $audit = null;

    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    public function sinkronkan(RiwayatDefinisi $definisi, string $nip, ?AuthContext $pelaku = null): void
    {
        $aturan = $definisi->snapshot();

        if ($aturan === []) {
            return;
        }

        $db    = $this->db();
        $actor = $this->pelaku($pelaku);

        $db->transBegin();

        try {
            foreach ($aturan as $target) {
                $this->sinkronkanTarget($definisi, $target, $nip, $actor);
            }
        } catch (Throwable $e) {
            $db->transRollback();
            $db->resetTransStatus();

            throw $e;
        }

        $db->transCommit();
    }

    /**
     * @param array{id: int|null, nip: string|null} $actor
     */
    private function sinkronkanTarget(RiwayatDefinisi $definisi, AturanSnapshot $aturan, string $nilaiKunci, array $actor): void
    {
        $db       = $this->db();
        $terpilih = PemilihSnapshot::pilih(
            $aturan,
            $this->kandidat($definisi, $aturan, $nilaiKunci),
            $definisi->primaryKey(),
            $definisi->kolomStatus(),
            $definisi->pemetaanStatus(),
        );

        /** @var array<string, mixed>|null $before */
        $before = $db->table($aturan->tabel)->where($aturan->kunci, $nilaiKunci)->get()->getRowArray();

        if ($terpilih === null) {
            if ($before !== null) {
                $this->wajib($db->table($aturan->tabel)->where($aturan->kunci, $nilaiKunci)->delete());
                $this->catatAudit($aturan->tabel, $nilaiKunci, AuditLogModel::EVENT_DELETE, $before, null, $actor);
            }

            return;
        }

        $data = [$aturan->kunci => $nilaiKunci];

        foreach ($aturan->kolom as $dari => $ke) {
            $data[$ke] = $terpilih[is_int($dari) ? $ke : $dari] ?? null;
        }

        if ($before !== null && ! $this->berubah($before, $data)) {
            return;
        }

        if (in_array('updated_at', $this->kolomTabel($aturan->tabel), true) && ! array_key_exists('updated_at', $data)) {
            $data['updated_at'] = Time::now()->toDateTimeString();
        }

        if ($before === null) {
            $this->wajib($db->table($aturan->tabel)->insert($data));
            $event = AuditLogModel::EVENT_CREATE;
        } else {
            $this->wajib($db->table($aturan->tabel)->where($aturan->kunci, $nilaiKunci)->update($data));
            $event = AuditLogModel::EVENT_UPDATE;
        }

        /** @var array<string, mixed>|null $after */
        $after = $db->table($aturan->tabel)->where($aturan->kunci, $nilaiKunci)->get()->getRowArray();
        $this->catatAudit($aturan->tabel, $nilaiKunci, $event, $before, $after, $actor);
    }

    /**
     * Baris Disetujui milik $nilaiKunci, dengan kolom urutan tabel join tersedia sebagai key `tabel.kolom`.
     *
     * @return list<array<string, mixed>>
     */
    private function kandidat(RiwayatDefinisi $definisi, AturanSnapshot $aturan, string $nilaiKunci): array
    {
        $tabel   = $definisi->tabel();
        $builder = $this->db()->table($tabel)->select($tabel . '.*');
        $alias   = [];

        foreach ($aturan->join as $tabelJoin => $kondisi) {
            $builder->join($tabelJoin, $kondisi, 'left');
        }

        foreach (array_keys($aturan->urutan) as $i => $kolom) {
            if (str_contains($kolom, '.')) {
                $alias["urutan_snapshot_{$i}"] = $kolom;
                $builder->select("{$kolom} AS urutan_snapshot_{$i}");
            }
        }

        $nilaiDisetujui = array_search(StatusRiwayat::Disetujui, $definisi->pemetaanStatus(), true);

        /** @var list<array<string, mixed>> $rows */
        $rows = $builder
            ->where("{$tabel}.{$aturan->kunci}", $nilaiKunci)
            ->where("{$tabel}.{$definisi->kolomStatus()}", $nilaiDisetujui === false ? StatusRiwayat::Disetujui->value : $nilaiDisetujui)
            ->get()
            ->getResultArray();

        if ($alias === []) {
            return $rows;
        }

        return array_map(static function (array $row) use ($alias): array {
            foreach ($alias as $nama => $kolom) {
                $row[$kolom] = $row[$nama];
                unset($row[$nama]);
            }

            return $row;
        }, $rows);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $data
     */
    private function berubah(array $before, array $data): bool
    {
        foreach ($data as $kolom => $nilai) {
            $lama = $before[$kolom] ?? null;

            if (($lama === null) !== ($nilai === null) || (string) $lama !== (string) $nilai) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null             $before
     * @param array<string, mixed>|null             $after
     * @param array{id: int|null, nip: string|null} $actor
     */
    private function catatAudit(string $tabel, string $id, string $event, ?array $before, ?array $after, array $actor): void
    {
        try {
            ($this->audit ??= new AuditLogModel($this->db()))->record($tabel, $id, $event, $before, $after, $actor['id'], $actor['nip']);
        } catch (Throwable $e) {
            // Fail-open seperti BaseAuditableModel::writeAudit (F0-04): audit gagal tidak menggagalkan transaksi bisnis.
            log_message('error', '[audit_logs] gagal menulis audit snapshot {entity}#{id} ({event}): {msg}', [
                'entity' => $tabel,
                'id'     => $id,
                'event'  => $event,
                'msg'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{id: int|null, nip: string|null}
     */
    private function pelaku(?AuthContext $pelaku): array
    {
        try {
            $auth = $pelaku ?? service('authContext');

            return ['id' => $auth->idPengguna(), 'nip' => $auth->nip()];
        } catch (Throwable) {
            return ['id' => null, 'nip' => null];
        }
    }

    /**
     * @return list<string>
     */
    private function kolomTabel(string $tabel): array
    {
        return $this->kolom[$tabel] ??= array_values($this->db()->getFieldNames($tabel));
    }

    private function wajib(mixed $hasil): void
    {
        if ($hasil === false) {
            $error = $this->db()->error();

            throw new DatabaseException('Sinkron snapshot gagal: ' . $error['message'], (int) $error['code']);
        }
    }

    private function db(): BaseConnection
    {
        /** @var BaseConnection $db */
        $db = $this->db ?? db_connect();

        return $db;
    }
}
