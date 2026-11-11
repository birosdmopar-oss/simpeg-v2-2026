<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Interaksi\Survei;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Pegawai\EksporTabel;
use App\Libraries\ListQuery;
use App\Libraries\Waktu\StempelUtc;
use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * F-11 rekap penilaian SKL (MAKE-084, role 1) — padanan legacy `controllers/hr/Survey.php` + `libraries/hr/L_survey.php:22-29`
 * + `views/hr/survey/layanan/list.php:39-66`. Kolom legacy: Jenis Layanan, NIP, Nama, Unit Es.I, Unit Es.II, Kritik & Saran,
 * Tgl. Penilaian, Rating. Perbaikan [V2]:
 * - unit/satker dari snapshot jabatan aktif `pegawai_mutasi_jabatan` (satu baris per NIP; legacy join semua mutasi → baris
 *   ganda);
 * - filter jenis, periode penilaian WIB (`dari`/`sampai`), rating, cari nama/NIP; berhalaman (legacy: semua baris, ekspor di
 *   klien);
 * - teks kritik/saran dikirim apa adanya, FE merender sebagai teks (menutup XSS legacy);
 * - XLSX dengan filter yang sama + audit `dokumen_unduhan` `entity_id = survei_layanan` (F7-27). Isi kritik/saran tidak
 *   masuk audit (data pribadi).
 */
final class RekapSkl
{
    public const ENTITY_AUDIT = 'dokumen_unduhan';

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
    public function halaman(AuthContext $auth, array $query): array
    {
        $this->wajibAdmin($auth);
        $p       = ListQuery::from($query, ['jenis_rwy', 'dari', 'sampai', 'rate', 'q', 'page', 'per_page']);
        $page    = $p->page();
        $perPage = $p->perPage(20, self::PER_PAGE_MAX);
        $label   = JenisLayananSkl::label($this->db); // sebelum builder ber-alias: kueri lain mereset alias koneksi
        $b       = $this->kueri($query);
        $total   = $b->countAllResults(false);
        $rows    = $b->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['items' => array_map(static fn (array $r): array => self::baris($r, $label), $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{nama: string, mime: string, isi: string}
     */
    public function xlsx(AuthContext $auth, array $query): array
    {
        $this->wajibAdmin($auth);
        $label = JenisLayananSkl::label($this->db);
        $rows  = $this->kueri($query)->get()->getResultArray();
        $baris = array_map(static function (array $r) use ($label): array {
            $b = self::baris($r, $label);

            return [$b['jenis_layanan'], $b['nip'], $b['nama'], $b['unit'], $b['satker'], (string) ($b['kritik_saran'] ?? ''), $b['dinilai_at'], (string) $b['rate']];
        }, $rows);

        $isi = EksporTabel::xlsx(['Jenis Layanan', 'NIP', 'Nama', 'Unit Es.I', 'Unit Es.II', 'Kritik & Saran', 'Tgl. Penilaian (WIB)', 'Rating'], $baris, 'Survei Layanan');

        ($this->audit ?? new AuditLogModel())->record(self::ENTITY_AUDIT, 'survei_layanan', 'create', null, [
            'jenis'        => 'survei_layanan',
            'format'       => 'xlsx',
            'parameter'    => array_intersect_key($query, array_flip(['jenis_rwy', 'dari', 'sampai', 'rate'])),
            'jumlah_baris' => count($baris),
        ], $auth->idPengguna(), $auth->nip());

        return ['nama' => 'survei-layanan.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'isi' => $isi];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function kueri(array $query): BaseBuilder
    {
        $p = ListQuery::from($query, ['jenis_rwy', 'dari', 'sampai', 'rate', 'q', 'page', 'per_page', 'format']);
        $b = $this->db->table('skl s')
            ->select('s.id_skl, s.nip, s.jenis_rwy, s.rate, s.kritik_saran, s.created_at, peg.glr_awal, peg.nama, peg.glr_akhir, pmj.unit, pmj.satker')
            ->join('pegawai peg', 'peg.nip = s.nip', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = s.nip', 'left');

        if ($p->filled('jenis_rwy')) {
            $b->where('s.jenis_rwy', JenisLayananSkl::kode($p->string('jenis_rwy')));
        }

        if ($p->filled('rate')) {
            $rate = $p->string('rate');

            if (! in_array($rate, ['1', '2', '3', '4', '5'], true)) {
                throw ValidationException::forField('rate', 'Rating harus 1-5.');
            }

            $b->where('s.rate', (int) $rate);
        }

        if ($p->filled('dari') || $p->filled('sampai')) {
            [$dari, $sampai] = RingkasanSkl::periode([
                'dari'   => $p->filled('dari') ? $p->string('dari') : '1900-01-01',
                'sampai' => $p->filled('sampai') ? $p->string('sampai') : '2100-12-31',
            ]);
            $b->where('s.created_at >=', StempelUtc::awalHari($dari))->where('s.created_at <', StempelUtc::awalHariBerikut($sampai));
        }

        if ($p->filled('q')) {
            $like = ListQuery::likeLiteral($this->db, $p->string('q'));
            $b->groupStart()->like('peg.nama', $like, 'both', null, true)->orLike('s.nip', $like, 'both', null, true)->groupEnd();
        }

        return $b->orderBy('s.created_at', 'DESC')->orderBy('s.id_skl', 'DESC');
    }

    /**
     * @param array<string, mixed> $r
     * @param array<int, string>   $label
     *
     * @return array<string, mixed>
     */
    private static function baris(array $r, array $label): array
    {
        return [
            'id'            => (int) $r['id_skl'],
            'jenis_rwy'     => (int) $r['jenis_rwy'],
            'jenis_layanan' => $label[(int) $r['jenis_rwy']] ?? '',
            'nip'           => (string) $r['nip'],
            'nama'          => trim(implode(' ', array_filter([(string) ($r['glr_awal'] ?? ''), (string) ($r['nama'] ?? ''), (string) ($r['glr_akhir'] ?? '')], static fn (string $s): bool => $s !== ''))),
            'unit'          => (string) ($r['unit'] ?? ''),
            'satker'        => (string) ($r['satker'] ?? ''),
            'kritik_saran'  => $r['kritik_saran'] === null ? null : (string) $r['kritik_saran'],
            'dinilai_at'    => (string) StempelUtc::keWib((string) $r['created_at']),
            'rate'          => (int) $r['rate'],
        ];
    }

    private function wajibAdmin(AuthContext $auth): void
    {
        if ((int) $auth->role() !== Role::SUPER_ADMIN) {
            throw new ApiException('Anda tidak berhak melihat rekap survei layanan.', 403);
        }
    }
}
