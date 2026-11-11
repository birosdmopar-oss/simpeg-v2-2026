<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Interaksi\FormDigital;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Pegawai\EksporTabel;
use App\Libraries\ListQuery;
use App\Libraries\Waktu\StempelUtc;
use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * F-06 rekap jawaban form digital (MAKE-083, role 1) — padanan legacy `L_announcement.php:2822-2828` + view rekap, dengan
 * perbaikan [V2]:
 * - kolom pertanyaan diambil dari SKEMA form (legacy: dari kunci jawaban pertama, kolom hilang bila jawaban pertama tidak
 *   lengkap);
 * - unit/satker dari snapshot jabatan aktif `pegawai_mutasi_jabatan` (satu baris per NIP; legacy join semua mutasi →
 *   baris ganda);
 * - teks dikirim apa adanya, FE merender sebagai teks (menutup XSS legacy);
 * - XLSX memuat SEMUA baris (bukan per halaman); berkas ditulis sebagai nama berkas (tanpa URL); audit `dokumen_unduhan`
 *   `entity_id = form_digital_<id>` (F7-27);
 * - berkas jawaban diunduh lewat endpoint terautentikasi role 1 (Q7-14), bukan URL publik.
 */
final class RekapRespons
{
    public const ENTITY_AUDIT = 'dokumen_unduhan';

    private const PER_PAGE_MAX = 100;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly StorageAdapterInterface $storage,
        private readonly ?AuditLogModel $audit = null,
    ) {
    }

    /**
     * @param array<string, mixed> $query `q`, `page`, `per_page`
     *
     * @return array{kolom: list<array{id: string, label: string, tipe: string}>, items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function halaman(AuthContext $auth, int $idForm, array $query): array
    {
        $this->wajibAdmin($auth);
        $skema   = $this->skema($idForm);
        $p       = ListQuery::from($query, ['q', 'page', 'per_page']);
        $page    = $p->page();
        $perPage = $p->perPage(20, self::PER_PAGE_MAX);
        $b       = $this->kueri($idForm, $p->filled('q') ? $p->string('q') : '');
        $total   = $b->countAllResults(false);
        $rows    = $b->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'kolom'    => self::kolom($skema),
            'items'    => array_map(fn (array $r): array => $this->baris($r, $skema), $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array{nama: string, mime: string, isi: string}
     */
    public function xlsx(AuthContext $auth, int $idForm): array
    {
        $this->wajibAdmin($auth);
        $skema = $this->skema($idForm);
        $kolom = self::kolom($skema);
        $rows  = $this->kueri($idForm, '')->get()->getResultArray();
        $baris = [];

        foreach ($rows as $r) {
            $b       = $this->baris($r, $skema);
            $baris[] = [
                (string) $b['unit'], (string) $b['satker'], (string) $b['nama'], (string) $b['nip'],
                ...array_map(static fn (array $k): string => (string) ($b['jawaban'][$k['id']] ?? ''), $kolom),
                (string) $b['diisi_at'],
            ];
        }

        $header = ['Unit', 'Satker', 'Nama', 'NIP', ...array_column($kolom, 'label'), 'Waktu Isi (WIB)'];
        $isi    = EksporTabel::xlsx($header, $baris, 'Respons');

        ($this->audit ?? new AuditLogModel())->record(self::ENTITY_AUDIT, 'form_digital_' . $idForm, 'create', null, [
            'jenis'        => 'form_digital_respons',
            'format'       => 'xlsx',
            'id'           => $idForm,
            'jumlah_baris' => count($baris),
        ], $auth->idPengguna(), $auth->nip());

        return ['nama' => 'respons-form-digital-' . $idForm . '.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'isi' => $isi];
    }

    /**
     * Berkas satu jawaban (Q7-14).
     *
     * @return array{nama: string, isi: string}
     */
    public function berkas(AuthContext $auth, int $idForm, int $idJawaban, string $idPertanyaan): array
    {
        $this->wajibAdmin($auth);
        $row = $this->db->table('filled_form_digital')->select('response')
            ->where('id_filled_form_digital', $idJawaban)->where('id_form_digital', $idForm)
            ->get()->getRowArray();
        $d     = is_array($row) ? json_decode((string) $row['response'], true) : null;
        $entri = is_array($d) ? ($d['jawaban'][$idPertanyaan] ?? null) : null;

        if (! is_array($entri) || ! is_string($entri['berkas'] ?? null) || ! str_starts_with($entri['berkas'], 'form-digital/' . $idForm . '/') || ! $this->storage->ada($entri['berkas'])) {
            throw new NotFoundException('Berkas tidak ditemukan.');
        }

        return ['nama' => (string) ($entri['nama'] ?? basename($entri['berkas'])), 'isi' => $this->storage->baca($entri['berkas'])];
    }

    /**
     * @return array{versi: int, pertanyaan: list<array<string, mixed>>}
     */
    private function skema(int $idForm): array
    {
        $row = $this->db->table('form_digital')->select('form_digital')->where('id_form_digital', $idForm)->get()->getRowArray();

        if (! is_array($row)) {
            throw new NotFoundException('Form tidak ditemukan.');
        }

        return SkemaForm::dariJson((string) $row['form_digital']);
    }

    private function kueri(int $idForm, string $q): BaseBuilder
    {
        $b = $this->db->table('filled_form_digital j')
            ->select('j.id_filled_form_digital, j.nip, j.response, j.created_at, peg.glr_awal, peg.nama, peg.glr_akhir, pmj.unit, pmj.satker')
            ->join('pegawai peg', 'peg.nip = j.nip', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = j.nip', 'left')
            ->where('j.id_form_digital', $idForm);

        if ($q !== '') {
            $like = ListQuery::likeLiteral($this->db, $q);
            $b->groupStart()->like('peg.nama', $like, 'both', null, true)->orLike('j.nip', $like, 'both', null, true)->groupEnd();
        }

        return $b->orderBy('j.created_at', 'ASC')->orderBy('j.id_filled_form_digital', 'ASC');
    }

    /**
     * @param array{versi: int, pertanyaan: list<array<string, mixed>>} $skema
     *
     * @return list<array{id: string, label: string, tipe: string}>
     */
    private static function kolom(array $skema): array
    {
        return array_map(static fn (array $p): array => ['id' => (string) $p['id'], 'label' => (string) $p['label'], 'tipe' => (string) $p['tipe']], $skema['pertanyaan']);
    }

    /**
     * @param array<string, mixed>                                    $r
     * @param array{versi: int, pertanyaan: list<array<string, mixed>>} $skema
     *
     * @return array<string, mixed>
     */
    private function baris(array $r, array $skema): array
    {
        $d       = json_decode((string) $r['response'], true);
        $isi     = is_array($d) && is_array($d['jawaban'] ?? null) ? $d['jawaban'] : [];
        $jawaban = [];
        $berkas  = [];

        foreach ($skema['pertanyaan'] as $p) {
            $id = (string) $p['id'];
            $v  = $isi[$id] ?? null;

            if (is_array($v) && isset($v['berkas'])) {
                $jawaban[$id] = (string) ($v['nama'] ?? '');
                $berkas[]     = $id;
            } elseif (is_array($v)) {
                $jawaban[$id] = implode(', ', array_map('strval', $v));
            } else {
                $jawaban[$id] = $v === null ? '' : (string) $v;
            }
        }

        $nama = trim(implode(' ', array_filter([(string) ($r['glr_awal'] ?? ''), (string) ($r['nama'] ?? ''), (string) ($r['glr_akhir'] ?? '')], static fn (string $s): bool => $s !== '')));

        return [
            'id'       => (int) $r['id_filled_form_digital'],
            'nip'      => (string) $r['nip'],
            'nama'     => $nama,
            'unit'     => (string) ($r['unit'] ?? ''),
            'satker'   => (string) ($r['satker'] ?? ''),
            'jawaban'  => $jawaban,
            'berkas'   => $berkas,
            'diisi_at' => (string) StempelUtc::keWib((string) $r['created_at']),
        ];
    }

    private function wajibAdmin(AuthContext $auth): void
    {
        if ((int) $auth->role() !== Role::SUPER_ADMIN) {
            throw new ApiException('Anda tidak berhak melihat rekap form digital.', 403);
        }
    }
}
