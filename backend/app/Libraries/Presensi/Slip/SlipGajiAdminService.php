<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\ListQuery;
use App\Libraries\Presensi\SlipGaji\AksesSlip;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use App\Libraries\Presensi\SlipGaji\NominalGaji;
use App\Libraries\Presensi\SlipGaji\PencocokanNama;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use App\Libraries\Presensi\SlipGaji\StatusSlip;
use App\Models\AuditLogModel;
use App\Models\Presensi\GajiPegawaiModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Presensi;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * D-11 jalur admin Slip Gaji (PRD 5A §6.2, §7.3.4–7.3.6; F5-8 fitur legacy saja) — role 1 semua pegawai, role 3 hanya
 * pegawai yang `id_satker` jabatan saat ini = satker akun (`AksesSlip::dalamCakupan`, SG-2; tanpa satker → 403 seluruh
 * jalur; di luar cakupan → 403 bukan 404). Tidak memakai `PegawaiScope`. Tanpa gerbang rilis; melihat/mencetak/mengoreksi
 * tidak mengubah status dibuka. Admin tidak bisa membuka atas nama pegawai (P5-32).
 */
final class SlipGajiAdminService
{
    public const STATUS_VIEW = ['semua', 'dibuka', 'belum'];

    private readonly Presensi $config;

    /** @var Closure(): DateTimeInterface */
    private readonly Closure $jam;

    private readonly TokenSlipService $token;

    /**
     * @param (Closure(): DateTimeInterface)|null $jam instan sekarang (bawaan jam server); disuntik test
     */
    public function __construct(
        private readonly ?BaseConnection $db = null,
        ?Presensi $config = null,
        ?Closure $jam = null,
        ?TokenSlipService $token = null,
        private readonly ?PdfSlipGaji $pdf = null,
    ) {
        $this->config = $config ?? config(Presensi::class);
        $this->jam    = $jam ?? static fn (): DateTimeInterface => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->token  = $token ?? new TokenSlipService();
    }

    /**
     * `GET slip-gaji?periode=&unit_satker=&status_view=&search=&page=&per_page=` (monitoring legacy `admin`/`admin_data`).
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function daftar(AuthContext $auth, array $query): array
    {
        $satkerAdmin = $this->akses($auth);
        $q           = ListQuery::from($query, ['periode', 'unit_satker', 'status_view', 'search', 'page', 'per_page']);
        $hasil       = PeriodeSlip::dariTeks($q->string('periode'));

        if (! $hasil->valid) {
            throw new ApiException((string) $hasil->pesan, 422, ['periode' => [(string) $hasil->pesan]], kode: $hasil->kode);
        }

        /** @var PeriodeSlip $periode */
        $periode = $hasil->nilai;
        $status  = $q->filled('status_view') ? $q->string('status_view') : 'semua';

        if (! in_array($status, self::STATUS_VIEW, true)) {
            throw ValidationException::forField('status_view', 'Status tidak valid (semua, dibuka, belum).');
        }

        $satker = $satkerAdmin;

        if ($satker === null && $q->filled('unit_satker') && $q->string('unit_satker') !== '0') {
            if (! ctype_digit($q->string('unit_satker'))) {
                throw ValidationException::forField('unit_satker', 'Satker tidak valid.');
            }

            $satker = $q->string('unit_satker');
        }

        $db    = $this->koneksi();
        $dasar = function () use ($db, $periode, $satker, $q) {
            $b = $db->table('gaji_pegawai g')
                ->join('pegawai p', 'p.nip = g.nip', 'left')
                ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = g.nip', 'left')
                ->where('g.bulan', $periode->bulan)->where('g.tahun', $periode->tahun);

            if ($satker !== null) {
                $b->where('pmj.id_satker', (int) $satker);
            }

            if ($q->filled('search')) {
                $cari = ListQuery::likeLiteral($db, $q->string('search'));
                $b->groupStart()->like('g.nip', $cari, 'both', null, true)->orLike('p.nama', $cari, 'both', null, true)->groupEnd();
            }

            return $b;
        };

        $statistik = $dasar()->select('COUNT(*) AS jumlah, SUM(g.viewed_at IS NOT NULL) AS dibuka', false)->get()->getRowArray();
        $daftar    = $dasar();

        if ($status === 'dibuka') {
            $daftar->where('g.viewed_at IS NOT NULL', null, false);
        } elseif ($status === 'belum') {
            $daftar->where('g.viewed_at', null);
        }

        $page    = $q->page();
        $perPage = $q->perPage(20, 100);
        $total   = (clone $daftar)->countAllResults(false);
        $rows    = $daftar->select('g.id, g.nip, g.viewed_at, g.viewed_ip, p.nama, p.glr_awal, p.glr_akhir, pmj.unit, pmj.satker')
            ->orderBy('p.nama')->orderBy('g.nip')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        $periodeAda = $db->table('gaji_pegawai')->select('tahun, bulan')->distinct()->orderBy('tahun', 'DESC')->orderBy('bulan', 'DESC')->get()->getResultArray();

        return [
            'items' => array_map(static fn (array $r): array => [
                'id'          => (int) $r['id'],
                'nip'         => $r['nip'],
                'nama'        => $r['nama'] === null ? null : PencocokanNama::denganGelar($r['glr_awal'] ?? null, (string) $r['nama'], $r['glr_akhir'] ?? null),
                'unit_satker' => trim(implode(' - ', array_filter([(string) ($r['unit'] ?? ''), (string) ($r['satker'] ?? '')], static fn (string $s): bool => trim($s) !== ''))),
                'periode'     => $periode->kunci(),
                'dibuka'      => $r['viewed_at'] !== null,
                ...SlipGajiData::waktuDibuka($r),
                'viewed_ip' => $r['viewed_ip'],
                'aksi'      => ['lihat' => true, 'cetak' => true, 'koreksi' => true, 'buka_ulang' => $r['viewed_at'] !== null],
            ], $rows),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'ringkasan' => [
                'jumlah'       => (int) ($statistik['jumlah'] ?? 0),
                'dibuka'       => (int) ($statistik['dibuka'] ?? 0),
                'belum_dibuka' => (int) ($statistik['jumlah'] ?? 0) - (int) ($statistik['dibuka'] ?? 0),
            ],
            'periode_tersedia' => array_map(static fn (array $r): string => PeriodeSlip::buat((int) $r['tahun'], (int) $r['bulan'])->kunci(), $periodeAda),
        ];
    }

    /**
     * `GET slip-gaji/{id}` — rincian + ringkasan + uang makan + status + aksi; tidak mengubah status.
     *
     * @return array<string, mixed>
     */
    public function detail(AuthContext $auth, int $id): array
    {
        $row = $this->baris($auth, $id);

        return $this->bentuk($row);
    }

    /**
     * `PUT slip-gaji/{id}` — koreksi 28 kolom wajib (S-6: status, token, uang makan tidak berubah); audit lewat model.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function koreksi(AuthContext $auth, int $id, array $body): array
    {
        $row    = $this->baris($auth, $id);
        $errors = [];
        $ubah   = [];
        $kode   = null;

        foreach (KolomGaji::semuaField() as $f) {
            if (! array_key_exists($f, $body) || $body[$f] === null || $body[$f] === '') {
                $errors[$f][] = 'Nilai wajib diisi.';

                continue;
            }

            $hasil = NominalGaji::dariInputApi($body[$f]);

            if ($hasil->valid) {
                $hasil = NominalGaji::periksaNonNegatif((int) $hasil->nilai) ?? $hasil;
            }

            if (! $hasil->valid) {
                $kode ??= $hasil->kode;
                $errors[$f][] = $hasil->kode === KodeGalatSlip::NOMINAL_NEGATIF ? 'Nilai harus berupa angka non-negatif.' : (string) $hasil->pesan;

                continue;
            }

            $ubah[$f] = NominalGaji::keDesimal((int) $hasil->nilai);
        }

        if ($errors !== []) {
            throw new ApiException('Validasi gagal.', 422, $errors, kode: $kode);
        }

        $ubah = array_filter($ubah, static fn (string $v, string $f): bool => $v !== (string) $row[$f], ARRAY_FILTER_USE_BOTH);

        if ($ubah !== []) {
            $ubah['updated_at'] = gmdate('Y-m-d H:i:s', (($this->jam)())->getTimestamp());
            $ubah['updated_by'] = $auth->idPengguna();

            if (! (new GajiPegawaiModel($this->koneksi()))->update($id, $ubah)) {
                throw new RuntimeException('Koreksi slip gagal disimpan.');
            }
        }

        return $this->bentuk($this->baris($auth, $id));
    }

    /**
     * `POST slip-gaji/{id}/buka-ulang` — reset status & token aktif; riwayat token dan snapshot uang makan dipertahankan.
     *
     * @return array<string, mixed>
     */
    public function bukaUlang(AuthContext $auth, int $id): array
    {
        $row   = $this->baris($auth, $id);
        $reset = StatusSlip::periksaReset($row['viewed_at']);

        if (! $reset->valid) {
            throw new ApiException((string) $reset->pesan, 422, ['status' => [(string) $reset->pesan]], kode: $reset->kode);
        }

        $db = $this->koneksi();
        $db->transBegin();

        try {
            $db->table('gaji_pegawai')->where('id', $id)->where('viewed_at IS NOT NULL', null, false)
                ->update(['viewed_at' => null, 'viewed_ip' => null, 'qr_token' => null, 'qr_token_enkripsi' => null, 'updated_at' => gmdate('Y-m-d H:i:s', (($this->jam)())->getTimestamp()), 'updated_by' => $auth->idPengguna()]);

            if ($db->affectedRows() !== 1) {
                throw new SlipSudahDibuka();
            }

            $audit = (new AuditLogModel($db))->record('gaji_pegawai', (string) $id, AuditLogModel::EVENT_UPDATE, ['viewed_at' => $row['viewed_at']], ['viewed_at' => null, 'buka_ulang' => true], $auth->idPengguna(), $auth->nip());

            if ($audit <= 0 || $db->transStatus() === false) {
                throw new RuntimeException('Buka ulang slip gagal.');
            }

            $db->transCommit();
        } catch (SlipSudahDibuka) {
            $db->transRollback();
            $db->resetTransStatus();

            throw new ApiException('Slip gaji ini belum pernah dibuka, tidak perlu direset.', 422, ['status' => ['Slip gaji ini belum pernah dibuka, tidak perlu direset.']], kode: KodeGalatSlip::SLIP_BELUM_DIBUKA);
        } catch (Throwable $e) {
            $db->transRollback();
            $db->resetTransStatus();

            throw $e;
        }

        return $this->bentuk($this->baris($auth, $id));
    }

    /**
     * `GET slip-gaji/{id}/pdf` — kapan saja, tanpa gerbang, tanpa mengubah status; tanpa QR bila tidak ada token aktif.
     *
     * @return array{isi: string, nama: string}
     */
    public function pdf(AuthContext $auth, int $id): array
    {
        return $this->data()->pdf($this->baris($auth, $id), ($this->jam)());
    }

    /**
     * Baris slip dalam cakupan pemanggil: tidak ada → 404; di luar cakupan role 3 → 403.
     *
     * @return array<string, mixed>
     */
    private function baris(AuthContext $auth, int $id): array
    {
        $satkerAdmin = $this->akses($auth);
        $row         = $this->data()->barisId($id) ?? throw new NotFoundException('Slip gaji tidak ditemukan.');

        if ($satkerAdmin !== null) {
            $pegawai = $this->data()->pegawai((string) $row['nip']);

            if (! AksesSlip::dalamCakupan(Role::ADMIN_SATKER, $satkerAdmin, $pegawai['id_satker'] ?? null)) {
                throw new ForbiddenException('Slip gaji pegawai ini di luar satker Anda.');
            }
        }

        return $row;
    }

    /**
     * Role 1 → null (semua); role 3 → satker akun (tanpa satker → 403); lainnya → 403.
     */
    private function akses(AuthContext $auth): ?string
    {
        $role = $auth->role();

        if ($role === null || ! AksesSlip::bolehMengelola($role)) {
            throw new ForbiddenException('Anda tidak berhak mengelola slip gaji.');
        }

        if ($role === Role::SUPER_ADMIN) {
            return null;
        }

        $satker = $auth->idSatker();

        if ($satker === null || $satker === '') {
            throw new ForbiddenException('Akun admin satker belum memiliki satker.');
        }

        return $satker;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function bentuk(array $row): array
    {
        $periode = PeriodeSlip::buat((int) $row['tahun'], (int) $row['bulan']);
        $pegawai = $this->data()->pegawai((string) $row['nip']);
        $dibuka  = $row['viewed_at'] !== null;

        return [
            'id'      => (int) $row['id'],
            'nip'     => $row['nip'],
            'nama'    => $pegawai['nama_bergelar'] ?? null,
            'periode' => $periode->kunci(),
            'label'   => $periode->label(),
            'dibuka'  => $dibuka,
            ...SlipGajiData::waktuDibuka($row),
            'viewed_ip' => $row['viewed_ip'],
            ...SlipGajiData::nominal($row),
            'aksi' => ['lihat' => true, 'cetak' => true, 'koreksi' => true, 'buka_ulang' => $dibuka],
            // Identitas modal rincian/koreksi admin [K `admin_list.php:456-472`] (MAKE-034, aditif).
            'pegawai' => [
                'nip'     => (string) $row['nip'],
                'nama'    => $pegawai['nama_bergelar'] ?? null,
                'pangkat' => $pegawai['pangkat'] ?? '-',
                'jabatan' => $pegawai['jabatan'] ?? '-',
                'unit'    => $pegawai['unit'] ?? '-',
                'satker'  => $pegawai['satker'] ?? '-',
            ],
        ];
    }

    private function data(): SlipGajiData
    {
        return new SlipGajiData($this->koneksi(), $this->config, $this->token, $this->pdf);
    }

    private function koneksi(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
