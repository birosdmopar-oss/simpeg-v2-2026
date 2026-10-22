<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Statistik;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\CacheService;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Pegawai\EksporTabel;
use App\Libraries\ListQuery;
use App\Libraries\MasterData\WebConfigService;
use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;
use Throwable;

/**
 * Widget Statistik Kepegawaian F-03 (PRD Fase 7 §7.1, MAKE-036): orkestrasi lingkup, scope, cache, dan ekspor XLSX.
 * Padanan legacy `controllers/hr/Chart.php` + `libraries/hr/L_chart.php`.
 *
 * Hak: role 1, 3, 4, 5, 8 (Matriks v2 `hr/chart/*`); role 3 terbatas satker/unit akun lewat `PegawaiScope` (F7-3) dan
 * hanya boleh memilih lingkup miliknya (selain itu 403). Pabrik statis: tidak ada entri di `Config\Services`
 * (aturan berkas bersama PRD §3.2).
 */
final class StatistikService
{
    public const UNIT_KERJA = 'unit-kerja';

    public const JENIS_KELAMIN = 'jenis-kelamin';

    public const JABATAN = 'jabatan';

    public const ESELON = 'eselon';

    public const PENDIDIKAN = 'pendidikan';

    public const USIA = 'usia';

    public const GOLONGAN = 'golongan';

    /**
     * Jenis statistik → judul (F7-1).
     *
     * @var array<string, string>
     */
    public const JENIS = [
        self::UNIT_KERJA    => 'Formasi Unit Kerja',
        self::JENIS_KELAMIN => 'Formasi Berdasarkan Jenis Kelamin',
        self::JABATAN       => 'Formasi Jabatan',
        self::ESELON        => 'Formasi Jabatan Struktural',
        self::PENDIDIKAN    => 'Formasi Strata Pendidikan',
        self::USIA          => 'Formasi Usia',
        self::GOLONGAN      => 'Formasi Golongan',
    ];

    /**
     * @var list<int>
     */
    public const ROLE_LIHAT = [Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN];

    public const ENTITY_AUDIT = 'dokumen_unduhan';

    private ?KueriStatistik $kueri = null;

    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly ?PegawaiScopeInterface $scope = null,
        private readonly ?CacheService $cache = null,
        private readonly ?WebConfigService $webConfig = null,
        private readonly ?AuditLogModel $audit = null,
    ) {
    }

    /**
     * Lingkup yang boleh dipilih akun (F7-2/F7-3): `kementerian` (null bila tidak boleh) + daftar unit beserta satker.
     * `pilih_unit` = unit itu sendiri sah sebagai lingkup; false untuk Admin Satker ber-satker (unit hanya pembungkus
     * satker miliknya, memilihnya pasti 403 — CR-064 T6; server tetap menolak).
     *
     * @return array{kementerian: array{nama: string}|null, unit: list<array{id: int, nama: string, pilih_unit: bool, satker: list<array{id: int, nama: string}>}>}
     */
    public function lingkupTersedia(AuthContext $auth): array
    {
        $this->wajibRole($auth);

        $db        = $this->koneksi();
        $unitRows  = $db->table('unit')->select('id_unit, unit')->where('status', 1)->orderBy('order', 'ASC')->orderBy('id_unit', 'ASC');
        $satRows   = $db->table('satker')->select('id_satker, id_unit, satker')->where('status', 1)->orderBy('order', 'ASC')->orderBy('id_satker', 'ASC');
        $kemen     = ['nama' => $this->namaKementerian()];
        $pilihUnit = true;

        if ($auth->role() === Role::ADMIN_SATKER) {
            $akun = $this->lingkupAkun($auth);

            if ($akun === null) {
                throw new ForbiddenException('Lingkup akun tidak valid.');
            }

            $kemen = null;

            if (isset($akun['satker'])) {
                $pilihUnit = false;
                $satRows->where('id_satker', $akun['satker']);
                $idUnit = $db->table('satker')->select('id_unit')->where('id_satker', $akun['satker'])->get()->getRow('id_unit');
                $unitRows->where('id_unit', $idUnit === null ? -1 : (int) $idUnit);
            } else {
                $satRows->where('id_unit', $akun['unit']);
                $unitRows->where('id_unit', $akun['unit']);
            }
        }

        $satkerPerUnit = [];

        foreach ($satRows->get()->getResultArray() as $s) {
            $satkerPerUnit[(string) $s['id_unit']][] = ['id' => (int) $s['id_satker'], 'nama' => (string) $s['satker']];
        }

        $unit = [];

        foreach ($unitRows->get()->getResultArray() as $u) {
            $unit[] = ['id' => (int) $u['id_unit'], 'nama' => (string) $u['unit'], 'pilih_unit' => $pilihUnit, 'satker' => $satkerPerUnit[(string) $u['id_unit']] ?? []];
        }

        return ['kementerian' => $kemen, 'unit' => $unit];
    }

    /**
     * Statistik satu jenis untuk lingkup dari query (`lingkup`, `id`), lewat cache (F7-12).
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function statistik(AuthContext $auth, string $jenis, array $query): array
    {
        $this->wajibRole($auth);
        $this->wajibJenis($jenis);

        $lingkup = $this->lingkup($auth, ListQuery::from($query, ['lingkup', 'id']));
        $key     = StatistikCache::key($jenis, $lingkup, $auth);
        $cache   = $this->cacheService();
        $hit     = false;
        $hasil   = null;

        try {
            $hit   = $cache->has($key);
            $hasil = $cache->remember($key, StatistikCache::TTL, fn (): array => $this->hitung($auth, $jenis, $lingkup));
        } catch (Throwable $e) {
            log_message('warning', '[dashboard] cache statistik {key} gagal: {msg}', ['key' => $key, 'msg' => $e->getMessage()]);
        }

        if (! is_array($hasil)) {
            $hit   = false;
            $hasil = $this->hitung($auth, $jenis, $lingkup);
        }

        $hasil['cache'] = ['hit' => $hit, 'ttl' => StatistikCache::TTL];

        return $hasil;
    }

    /**
     * Ekspor XLSX satu statistik (F7-11) + audit unduhan (F7-27).
     *
     * @param array<string, mixed> $query
     *
     * @return array{isi: string, nama: string, mime: string}
     */
    public function xlsx(AuthContext $auth, string $jenis, array $query): array
    {
        $laporan = $this->statistik($auth, $jenis, $query);
        $periode = date('d M Y', strtotime((string) $laporan['periode']) ?: time());
        $header  = [(string) $laporan['judul']];
        $baris   = [
            [(string) $laporan['lingkup']['nama']],
            ['Periode: ' . $periode],
            [],
            ['NO', mb_strtoupper((string) $laporan['label_kategori']), ...array_map(static fn (array $s): string => mb_strtoupper($s['label']), $laporan['seri']), 'TOTAL'],
        ];

        foreach ($laporan['baris'] as $i => $b) {
            $baris[] = [(string) ($i + 1), (string) $b['label'], ...array_map('strval', $b['nilai']), (string) $b['total']];
        }

        $baris[] = ['', 'SUBTOTAL', ...array_map('strval', $laporan['subtotal']), (string) $laporan['total']];

        $nama = sprintf('statistik_%s_%s%s_%s.xlsx', $jenis, $laporan['lingkup']['tipe'], $laporan['lingkup']['id'] ?? '', date('YmdHis'));
        $isi  = EksporTabel::xlsx($header, $baris, mb_substr((string) $laporan['judul'], 0, 31));

        $this->catatUnduhan($auth, 'statistik_' . $jenis, EksporTabel::XLSX, $laporan, count($laporan['baris']));

        return ['isi' => $isi, 'nama' => $nama, 'mime' => EksporTabel::MIME[EksporTabel::XLSX]];
    }

    // ------------------------------------------------------------------
    // Lingkup
    // ------------------------------------------------------------------

    /**
     * Lingkup dari query: validasi bentuk (422), keberadaan unit/satker status 1 (404), hak akun (403). Tanpa `lingkup`
     * → kementerian (role 1/4/5/8) atau satker/unit akun (role 3).
     */
    public function lingkup(AuthContext $auth, ListQuery $query): LingkupStatistik
    {
        $tipe = $query->string('lingkup');
        $akun = $auth->role() === Role::ADMIN_SATKER ? $this->lingkupAkun($auth) : null;

        if ($auth->role() === Role::ADMIN_SATKER && $akun === null) {
            throw new ForbiddenException('Lingkup akun tidak valid.');
        }

        if ($tipe === '') {
            if ($akun === null) {
                return new LingkupStatistik(LingkupStatistik::KEMENTERIAN, null, $this->namaKementerian());
            }

            return isset($akun['satker'])
                ? $this->lingkupSatker($akun['satker'])
                : $this->lingkupUnit($akun['unit']);
        }

        if (! in_array($tipe, LingkupStatistik::TIPE, true)) {
            throw ValidationException::forField('lingkup', 'Lingkup harus kementerian, unit, atau satker.');
        }

        if ($tipe === LingkupStatistik::KEMENTERIAN) {
            if ($akun !== null) {
                throw new ForbiddenException('Lingkup di luar hak akses.');
            }

            return new LingkupStatistik(LingkupStatistik::KEMENTERIAN, null, $this->namaKementerian());
        }

        $id = $query->string('id');

        if (preg_match('/^[1-9][0-9]{0,9}$/', $id) !== 1) {
            throw ValidationException::forField('id', 'Id unit/satker wajib berupa angka.');
        }

        $lingkup = $tipe === LingkupStatistik::UNIT ? $this->lingkupUnit((int) $id) : $this->lingkupSatker((int) $id);

        if ($akun !== null && ! $this->bolehLingkup($akun, $lingkup)) {
            throw new ForbiddenException('Lingkup di luar hak akses.');
        }

        return $lingkup;
    }

    private function lingkupUnit(int $id): LingkupStatistik
    {
        $row = $this->koneksi()->table('unit')->select('unit')->where('id_unit', $id)->where('status', 1)->get()->getRowArray();

        if (! is_array($row)) {
            throw new NotFoundException('Unit tidak ditemukan.');
        }

        return new LingkupStatistik(LingkupStatistik::UNIT, $id, (string) $row['unit']);
    }

    private function lingkupSatker(int $id): LingkupStatistik
    {
        $row = $this->koneksi()->table('satker')->select('satker')->where('id_satker', $id)->where('status', 1)->get()->getRowArray();

        if (! is_array($row)) {
            throw new NotFoundException('Satker tidak ditemukan.');
        }

        return new LingkupStatistik(LingkupStatistik::SATKER, $id, (string) $row['satker']);
    }

    /**
     * Admin Satker: satker akun → hanya satker itu; unit akun → unit itu atau satker di bawahnya.
     *
     * @param array{satker: int}|array{unit: int} $akun
     */
    private function bolehLingkup(array $akun, LingkupStatistik $lingkup): bool
    {
        if (isset($akun['satker'])) {
            return $lingkup->tipe === LingkupStatistik::SATKER && $lingkup->id === $akun['satker'];
        }

        if ($lingkup->tipe === LingkupStatistik::UNIT) {
            return $lingkup->id === $akun['unit'];
        }

        $idUnit = $this->koneksi()->table('satker')->select('id_unit')->where('id_satker', $lingkup->id)->get()->getRow('id_unit');

        return $idUnit !== null && (int) $idUnit === $akun['unit'];
    }

    /**
     * Lingkup akun Admin Satker dari claims, aturan yang sama dengan `PegawaiScope` (satker menang; satker ada tetapi bukan
     * angka kanonik → fail-closed).
     *
     * @return array{satker: int}|array{unit: int}|null
     */
    private function lingkupAkun(AuthContext $auth): ?array
    {
        $satker = self::keInt($auth->idSatker());

        if ($satker !== null) {
            return ['satker' => $satker];
        }

        if ($auth->idSatker() !== null && $auth->idSatker() !== '') {
            return null;
        }

        $unit = self::keInt($auth->idUnit());

        return $unit === null ? null : ['unit' => $unit];
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function hitung(AuthContext $auth, string $jenis, LingkupStatistik $lingkup): array
    {
        $hariIni = TanggalBisnis::hariIni();
        $kueri   = $this->kueri();
        $hasil   = $kueri->hitung($auth, $jenis, $lingkup, $hariIni);

        return BentukLaporan::susun($jenis, self::JENIS[$jenis], $lingkup, $kueri->kategori($lingkup), $hasil['seri'], $hasil['matriks'], $hariIni);
    }

    /**
     * @param array<string, mixed> $laporan
     */
    private function catatUnduhan(AuthContext $auth, string $jenis, string $format, array $laporan, int $jumlahBaris): void
    {
        ($this->audit ?? new AuditLogModel())->record(self::ENTITY_AUDIT, $jenis, 'create', null, [
            'jenis'        => $jenis,
            'format'       => $format,
            'lingkup'      => $laporan['lingkup']['tipe'],
            'id'           => $laporan['lingkup']['id'],
            'parameter'    => ['periode' => $laporan['periode']],
            'jumlah_baris' => $jumlahBaris,
        ], $auth->idPengguna(), $auth->nip());
    }

    private function wajibRole(AuthContext $auth): void
    {
        if (! $auth->isAuthenticated() || ! in_array($auth->role(), self::ROLE_LIHAT, true)) {
            throw new ForbiddenException('Anda tidak berhak melihat statistik kepegawaian.');
        }
    }

    private function wajibJenis(string $jenis): void
    {
        if (! isset(self::JENIS[$jenis])) {
            throw new NotFoundException('Jenis statistik tidak dikenal.');
        }
    }

    private function namaKementerian(): string
    {
        try {
            $nama = ($this->webConfig ?? Services::webConfigService())->value('nama_kementerian');
        } catch (Throwable) {
            $nama = null;
        }

        return is_string($nama) && trim($nama) !== '' ? trim($nama) : 'Kementerian';
    }

    private function kueri(): KueriStatistik
    {
        return $this->kueri ??= new KueriStatistik($this->koneksi(), $this->scope ?? Services::pegawaiScope());
    }

    private function koneksi(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }

    private function cacheService(): CacheService
    {
        return $this->cache ?? Services::cacheService();
    }

    private static function keInt(?string $nilai): ?int
    {
        return $nilai !== null && preg_match('/^(0|[1-9][0-9]*)$/', $nilai) === 1 ? (int) $nilai : null;
    }
}
