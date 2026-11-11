<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Interaksi\FormDigital;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Dashboard\Konten\Penargetan\LabelTarget;
use App\Libraries\Dashboard\Konten\Penargetan\PemekarTarget;
use App\Libraries\Dashboard\Konten\Penargetan\SinkronPenerima;
use App\Libraries\Dashboard\Konten\Penargetan\SpesifikasiTarget;
use App\Libraries\Dashboard\Konten\Penargetan\TabelPenerima;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\ListQuery;
use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use Throwable;

/**
 * F-06 kelola form digital (MAKE-083, PRD Fase 7 §5.7, role 1) — padanan legacy `libraries/hr/L_announcement.php:2343-2832`
 * (`sp_fd`, `set_fd`, `del_fd`):
 *
 * - daftar berhalaman (status 10 disembunyikan kecuali `?status=10`) dengan hitungan target/sudah/belum dan penanda terkunci;
 * - buat/ubah: judul, periode (akhir ≥ mulai), urutan, keterangan, skema ({@see SkemaForm}), target
 *   ({@see SpesifikasiTarget}, penargetan milik Jalur A). Penerima disnapshot ke `user_form_digital` dalam transaksi yang
 *   sama; ubah = sinkron SELISIH dan pegawai yang sudah mengisi dipertahankan walau keluar target (Q7-20);
 * - form yang sudah punya jawaban tidak boleh diubah pertanyaannya: 409 `form_terkunci` (Q7-25);
 * - status 1 ↔ 2 (menu ⋮), hapus = lunak status 10 + `deleted_at`, jawaban tetap (Q7-19);
 * - audit create/update/delete di `audit_logs` (entity `form_digital`).
 */
final class KelolaFormDigital
{
    public const ENTITY = 'form_digital';

    public const KODE_TERKUNCI = 'form_terkunci';

    private const AKTIF = 1;

    private const DIHAPUS = 10;

    private const PER_PAGE_MAX = 100;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly ?AuditLogModel $audit = null,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function daftar(AuthContext $auth, array $query): array
    {
        $this->wajibAdmin($auth);
        $p       = ListQuery::from($query, ['q', 'status', 'page', 'per_page']);
        $page    = $p->page();
        $perPage = $p->perPage(20, self::PER_PAGE_MAX);

        $b  = $this->db->table('form_digital');
        $fd = $this->db->prefixTable('form_digital');

        if ($p->filled('status')) {
            $status = $p->string('status');

            if (! in_array($status, ['1', '2', '10'], true)) {
                throw ValidationException::forField('status', 'Status harus 1, 2, atau 10.');
            }

            $b->where('form_digital.status', (int) $status);
        } else {
            $b->where('form_digital.status !=', self::DIHAPUS);
        }

        if ($p->filled('q')) {
            $b->like('form_digital.judul_form', ListQuery::likeLiteral($this->db, $p->string('q')), 'both', null, true);
        }

        $total = $b->countAllResults(false);
        $ufd   = $this->db->prefixTable('user_form_digital');
        $ffd   = $this->db->prefixTable('filled_form_digital');
        $rows  = $b->select("{$fd}.id_form_digital, {$fd}.judul_form, {$fd}.tgl_mulai, {$fd}.tgl_akhir, {$fd}.`order`, {$fd}.status, {$fd}.keterangan,
                (SELECT COUNT(*) FROM {$ufd} u WHERE u.id_form_digital = {$fd}.id_form_digital) AS jumlah_target,
                (SELECT COUNT(*) FROM {$ffd} j WHERE j.id_form_digital = {$fd}.id_form_digital) AS jumlah_jawaban,
                (SELECT COUNT(*) FROM {$ffd} j JOIN {$ufd} u ON u.id_form_digital = j.id_form_digital AND u.nip = j.nip
                    WHERE j.id_form_digital = {$fd}.id_form_digital) AS sudah_mengisi", false)
            ->orderBy('form_digital.order', 'ASC')->orderBy('form_digital.id_form_digital', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['items' => array_map(fn (array $r): array => $this->baris($r), $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(AuthContext $auth, int $id): array
    {
        $this->wajibAdmin($auth);

        return $this->lengkap($this->ambil($id));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function buat(AuthContext $auth, array $input): array
    {
        $this->wajibAdmin($auth);
        [$nilai, $target, $nip] = $this->validasi($input, true);
        $aktor                  = (int) $auth->idPengguna();
        $sekarang               = gmdate('Y-m-d H:i:s');

        $id = $this->dalamTransaksi(function () use ($nilai, $target, $nip, $aktor, $sekarang): int {
            $this->db->table('form_digital')->insert($nilai + $target->keKolom() + [
                'status'     => self::AKTIF,
                'created_at' => $sekarang,
                'created_by' => $aktor,
                'updated_by' => $aktor,
            ]);
            $id = (int) $this->db->insertID();
            (new SinkronPenerima($this->db))->sinkron(TabelPenerima::UserFormDigital, $id, $nip);

            return $id;
        });

        $hasil = $this->lengkap($this->ambil($id));
        $this->catat($auth, $id, 'create', null, $this->jejak($hasil));

        return $hasil;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function ubah(AuthContext $auth, int $id, array $input): array
    {
        $this->wajibAdmin($auth);
        $lama = $this->ambil($id);

        if ((int) $lama['status'] === self::DIHAPUS) {
            throw new ApiException('Form yang sudah dihapus tidak dapat diubah.', 409);
        }

        [$nilai, $target, $nip] = $this->validasi($input, false);
        $jawaban                = $this->nipSudahMengisi($id);

        if ($jawaban !== [] && ! SkemaForm::sama(SkemaForm::dariJson((string) $lama['form_digital']), SkemaForm::dariJson((string) $nilai['form_digital']))) {
            throw new ApiException('Pertanyaan form yang sudah memiliki jawaban tidak dapat diubah.', 409, ['form' => ['Form terkunci karena sudah dijawab.']], self::KODE_TERKUNCI);
        }

        $aktor = (int) $auth->idPengguna();

        $this->dalamTransaksi(function () use ($id, $nilai, $target, $nip, $jawaban, $aktor): int {
            $this->db->table('form_digital')->where('id_form_digital', $id)->update($nilai + $target->keKolom() + [
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'updated_by' => $aktor,
            ]);
            (new SinkronPenerima($this->db))->sinkron(TabelPenerima::UserFormDigital, $id, $nip, static fn (string $n): bool => ! isset($jawaban[$n]));

            return $id;
        });

        $hasil = $this->lengkap($this->ambil($id));
        $this->catat($auth, $id, 'update', $this->jejak($this->lengkap($lama)), $this->jejak($hasil));

        return $hasil;
    }

    public function setStatus(AuthContext $auth, int $id, mixed $status): void
    {
        $this->wajibAdmin($auth);

        if (! in_array((string) $status, ['1', '2'], true)) {
            throw ValidationException::forField('status', 'Status harus 1 (aktif) atau 2 (nonaktif).');
        }

        $lama = $this->ambil($id);

        if ((int) $lama['status'] === self::DIHAPUS) {
            throw new ApiException('Form yang sudah dihapus tidak dapat diubah statusnya.', 409);
        }

        $this->db->table('form_digital')->where('id_form_digital', $id)->update(['status' => (int) $status, 'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => (int) $auth->idPengguna()]);
        $this->catat($auth, $id, 'update', ['status' => (int) $lama['status']], ['status' => (int) $status]);
    }

    public function hapus(AuthContext $auth, int $id): void
    {
        $this->wajibAdmin($auth);
        $lama = $this->ambil($id);

        if ((int) $lama['status'] === self::DIHAPUS) {
            return;
        }

        $sekarang = gmdate('Y-m-d H:i:s');
        $this->db->table('form_digital')->where('id_form_digital', $id)->update(['status' => self::DIHAPUS, 'deleted_at' => $sekarang, 'updated_at' => $sekarang, 'updated_by' => (int) $auth->idPengguna()]);
        $this->catat($auth, $id, 'delete', ['status' => (int) $lama['status']], ['status' => self::DIHAPUS]);
    }

    /**
     * Penerima form + status sudah mengisi (Q7-20: belum = target − sudah).
     *
     * @param array<string, mixed> $query `isi` = sudah|belum, `q`, `page`, `per_page`
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function target(AuthContext $auth, int $id, array $query): array
    {
        $this->wajibAdmin($auth);
        $this->ambil($id);
        $p       = ListQuery::from($query, ['isi', 'q', 'page', 'per_page']);
        $page    = $p->page();
        $perPage = $p->perPage(20, self::PER_PAGE_MAX);

        $b = $this->db->table('user_form_digital u')
            ->join('pegawai peg', 'peg.nip = u.nip')
            ->join('filled_form_digital j', 'j.id_form_digital = u.id_form_digital AND j.nip = u.nip', 'left')
            ->where('u.id_form_digital', $id);

        if ($p->filled('isi')) {
            $isi = $p->string('isi');

            if (! in_array($isi, ['sudah', 'belum'], true)) {
                throw ValidationException::forField('isi', 'Filter isi harus sudah atau belum.');
            }

            $b->where($isi === 'sudah' ? 'j.id_filled_form_digital IS NOT NULL' : 'j.id_filled_form_digital IS NULL', null, false);
        }

        if ($p->filled('q')) {
            $like = ListQuery::likeLiteral($this->db, $p->string('q'));
            $b->groupStart()->like('peg.nama', $like, 'both', null, true)->orLike('peg.nip', $like, 'both', null, true)->groupEnd();
        }

        $total = $b->countAllResults(false);
        $rows  = $b->select('u.nip, peg.nama, j.created_at AS diisi_at')
            ->orderBy('peg.nama', 'ASC')->orderBy('u.nip', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'items' => array_map(static fn (array $r): array => [
                'nip'           => (string) $r['nip'],
                'nama'          => (string) $r['nama'],
                'sudah_mengisi' => $r['diisi_at'] !== null,
                'diisi_at'      => $r['diisi_at'] === null ? null : (string) $r['diisi_at'],
            ], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     *
     * @return array{0: array<string, mixed>, 1: SpesifikasiTarget, 2: list<string>}
     */
    private function validasi(array $input, bool $baru): array
    {
        $galat = [];
        $judul = is_string($input['judul_form'] ?? null) ? trim($input['judul_form']) : '';

        if ($judul === '' || mb_strlen($judul) > 255) {
            $galat['judul_form'] = ['Judul wajib diisi, maksimal 255 karakter.'];
        }

        $mulai = is_string($input['tgl_mulai'] ?? null) ? $input['tgl_mulai'] : '';
        $akhir = is_string($input['tgl_akhir'] ?? null) ? $input['tgl_akhir'] : '';

        if (! TanggalBisnis::valid($mulai)) {
            $galat['tgl_mulai'] = ['Tanggal mulai tidak valid.'];
        }

        if (! TanggalBisnis::valid($akhir)) {
            $galat['tgl_akhir'] = ['Tanggal akhir tidak valid.'];
        } elseif (TanggalBisnis::valid($mulai) && $akhir < $mulai) {
            $galat['tgl_akhir'] = ['Tanggal akhir tidak boleh sebelum tanggal mulai.'];
        }

        $order = $input['order'] ?? 1;

        if (is_string($order) && ctype_digit($order)) {
            $order = (int) $order;
        }

        if (! is_int($order) || $order < 0 || $order > 9999) {
            $galat['order'] = ['Urutan harus angka 0-9999.'];
        }

        $ket = $input['keterangan'] ?? null;

        if ($ket !== null && (! is_string($ket) || mb_strlen($ket) > 1000)) {
            $galat['keterangan'] = ['Keterangan maksimal 1000 karakter.'];
        }

        try {
            $skema = SkemaForm::normalisasi($input['form'] ?? null);
        } catch (ValidationException $e) {
            $galat += (array) $e->getErrors();
            $skema = null;
        }

        $target = null;
        $nip    = [];

        try {
            $t      = $input['target'] ?? null;
            $target = SpesifikasiTarget::dariArray(is_array($t) ? $t : []);

            if ($target->kosong()) {
                $galat['target'] = ['Target wajib dipilih (unit/satker atau pegawai).'];
            } else {
                $nip = (new PemekarTarget($this->db))->pekarkan($target);

                if ($nip === [] && $baru) {
                    $galat['target'] = ['Target tidak menjangkau pegawai aktif.'];
                }
            }
        } catch (InvalidArgumentException $e) {
            $galat['target'] = [$e->getMessage()];
        }

        if ($galat !== [] || $skema === null || $target === null) {
            throw new ValidationException('Data form tidak valid.', $galat);
        }

        $ket = is_string($ket) ? trim($ket) : null;

        return [[
            'judul_form'   => $judul,
            'tgl_mulai'    => $mulai,
            'tgl_akhir'    => $akhir,
            'order'        => $order,
            'keterangan'   => $ket === '' ? null : $ket,
            'form_digital' => SkemaForm::keJson($skema),
        ], $target, $nip];
    }

    /**
     * @return array<string, true> NIP => true
     */
    private function nipSudahMengisi(int $id): array
    {
        $hasil = [];

        foreach ($this->db->table('filled_form_digital')->select('nip')->where('id_form_digital', $id)->get()->getResultArray() as $r) {
            $hasil[(string) $r['nip']] = true;
        }

        return $hasil;
    }

    /**
     * @return array<string, mixed>
     */
    private function ambil(int $id): array
    {
        $row = $this->db->table('form_digital')->where('id_form_digital', $id)->get()->getRowArray();

        if (! is_array($row)) {
            throw new NotFoundException('Form tidak ditemukan.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function lengkap(array $row): array
    {
        $id      = (int) $row['id_form_digital'];
        $target  = $this->db->table('user_form_digital')->where('id_form_digital', $id)->countAllResults();
        $jawaban = $this->db->table('filled_form_digital')->where('id_form_digital', $id)->countAllResults();
        $sudah   = $this->db->table('filled_form_digital j')->join('user_form_digital u', 'u.id_form_digital = j.id_form_digital AND u.nip = j.nip')->where('j.id_form_digital', $id)->countAllResults();

        return $this->baris($row + ['jumlah_target' => $target, 'jumlah_jawaban' => $jawaban, 'sudah_mengisi' => $sudah]) + [
            'form'   => SkemaForm::dariJson((string) $row['form_digital']),
            'target' => LabelTarget::untuk($this->db, SpesifikasiTarget::dariKolom(self::str($row['target_uns'] ?? null), self::str($row['target_peg'] ?? null), self::str($row['excl_peg'] ?? null))),
        ];
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function baris(array $r): array
    {
        $target = (int) $r['jumlah_target'];
        $sudah  = (int) $r['sudah_mengisi'];

        return [
            'id'             => (int) $r['id_form_digital'],
            'judul_form'     => (string) $r['judul_form'],
            'tgl_mulai'      => (string) $r['tgl_mulai'],
            'tgl_akhir'      => (string) $r['tgl_akhir'],
            'order'          => (int) $r['order'],
            'keterangan'     => $r['keterangan'] === null ? null : (string) $r['keterangan'],
            'status'         => (int) $r['status'],
            'jumlah_target'  => $target,
            'sudah_mengisi'  => $sudah,
            'belum_mengisi'  => max(0, $target - $sudah),
            'jumlah_jawaban' => (int) $r['jumlah_jawaban'],
            'terkunci'       => (int) $r['jumlah_jawaban'] > 0,
        ];
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    private function jejak(array $d): array
    {
        return array_intersect_key($d, array_flip(['judul_form', 'tgl_mulai', 'tgl_akhir', 'order', 'keterangan', 'status', 'jumlah_target', 'form', 'target']));
    }

    /**
     * @param callable(): int $kerja
     */
    private function dalamTransaksi(callable $kerja): int
    {
        $this->db->transException(true)->transStart();

        try {
            $hasil = $kerja();
            $this->db->transComplete();
        } catch (Throwable $e) {
            if ($this->db->transDepth > 0) {
                $this->db->transRollback();
            }

            throw $e;
        } finally {
            $this->db->transException(false);
        }

        return $hasil;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function catat(AuthContext $auth, int $id, string $event, ?array $before, ?array $after): void
    {
        ($this->audit ?? new AuditLogModel())->record(self::ENTITY, $id, $event, $before, $after, $auth->idPengguna(), $auth->nip());
    }

    private function wajibAdmin(AuthContext $auth): void
    {
        if ((int) $auth->role() !== Role::SUPER_ADMIN) {
            throw new ApiException('Anda tidak berhak mengelola form digital.', 403);
        }
    }

    private static function str(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }
}
