<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\ValidasiBerkas;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Libraries\ListQuery;
use App\Libraries\MasterData\WebConfigService;
use App\Models\Layanan\RiwayatCutiModel;
use Closure;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Pengajuan cuti online (PRD Fase 4 v1.3 §6.2, C-02): daftar, detail, opsi, kalkulator, tambah, ubah, hapus.
 * Proses persetujuan ada di {@see CutiApprovalService}. Hak akses D5 ditegakkan di sini (route hanya menyaring role
 * 1, 2, 3, 6, 7): 403 untuk role/pemilik yang tidak berhak, 404 untuk baris yang tidak boleh dilihat.
 *
 * Snapshot pemohon, AL/PyB, dan saldo diisi SERVER; `lama_cuti` tidak diterima dari klien. Semua galat validasi
 * dikumpulkan (A-09). Notifikasi bukan bagian M3 (M4).
 */
class CutiService
{
    public const MODE_SAYA = 'saya';

    public const MODE_PERSETUJUAN = 'persetujuan';

    public const MODE_ADMIN = 'admin';

    /** Kode `jenis_rwy` lampiran pengajuan cuti (§6.1.1 #21). */
    public const ID_LAMPIRAN = 43;

    public const ID_SURAT_BELUM_TTE = 44;

    public const ID_SURAT_TTE = 45;

    private const ROLE_AJUKAN = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::PPPK];

    private const ROLE_ROUTE = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly AturanCuti $aturan,
        private readonly KuotaCutiService $kuota,
        private readonly EfekFinalCutiService $efek,
        private readonly AtasanLangsungCuti $atasan,
        private readonly PejabatBerwenangCuti $pejabat,
        private readonly ApproverTerjangkau $terjangkau,
        private readonly PegawaiScopeInterface $scope,
        private readonly AttachmentServiceInterface $lampiran,
        private readonly ?WebConfigService $web = null,
        private readonly ?Closure $sekarang = null,
    ) {
    }

    // ------------------------------------------------------------------
    // Waktu
    // ------------------------------------------------------------------

    public function sekarang(): DateTimeImmutable
    {
        return $this->sekarang !== null ? ($this->sekarang)() : new DateTimeImmutable('now', new DateTimeZone(config(\Config\App::class)->appTimezone));
    }

    public function hariIni(): string
    {
        return $this->sekarang()->format('Y-m-d');
    }

    // ------------------------------------------------------------------
    // Opsi & kalkulator
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function opsi(AuthContext $auth, ?string $nipQuery): array
    {
        $this->wajibRoleRoute($auth);

        if (! in_array((int) $auth->role(), self::ROLE_AJUKAN, true)) {
            return ['jenis_cuti' => [], 'atasan_langsung' => null, 'pyb' => [], 'langkah_tunggal' => false, 'eselon_i' => false, 'saldo' => null, 'tgl_akhir_maks' => null, 'ketua_tim' => [], 'can_create' => false];
        }

        $nip    = $this->nipPemohon($auth, $nipQuery, 'nip');
        $profil = $this->profil($nip) ?? throw ValidationException::forField('nip', 'Pegawai tidak ditemukan atau tidak aktif.');
        $hari   = $this->hariIni();
        $tahun  = (int) substr($hari, 0, 4);

        $al  = $this->atasan->untuk($nip, $hari);
        $pyb = $this->pejabat->daftar($profil, $hari);

        $eselonI = (int) ($profil['id_sub_group_jabatan'] ?? 0) === 1;
        $alItem  = $al === null || $eselonI || $al['nip'] === null ? null : [
            'nip' => $al['nip'], 'nama' => $al['nama'], 'jabatan' => $al['jabatan'], 'bisa_memproses' => $this->terjangkau->periksa($al['nip']),
        ];
        $pybItem = array_map(fn (array $p): array => $p + ['bisa_memproses' => $this->terjangkau->periksa($p['nip'])], $pyb);

        $saldo = $this->kuota->saldo($nip, $tahun);

        return [
            'jenis_cuti'      => $this->daftarJenis($profil, $auth),
            'atasan_langsung' => $alItem,
            'pyb'             => $pybItem,
            'langkah_tunggal' => $eselonI || TransisiCuti::langkahTunggal($alItem['nip'] ?? null, count($pybItem) === 1 ? $pybItem[0]['nip'] : null),
            'eselon_i'        => $eselonI,
            'saldo'           => $saldo === null ? null : ['tahun' => $tahun] + $saldo->keBaris() + ['saldo_tahun_ini' => $saldo->total(), 'saldo_tahun_depan' => $saldo->saldoTahunDepan($this->aturan)],
            'tgl_akhir_maks'  => sprintf('%04d-12-31', $tahun + 1),
            'ketua_tim'       => $this->daftarKetuaTim(),
            'can_create'      => $this->daftarJenis($profil, $auth) !== [],
        ];
    }

    /**
     * @return list<array{nip: string, nama: string}>
     */
    private function daftarKetuaTim(): array
    {
        $rows = $this->db->table('pegawai')->select('nip, nama, glr_awal, glr_akhir')->where('status', 1)->orderBy('nama', 'ASC')->get()->getResultArray();

        return array_map(
            static fn (array $p): array => ['nip' => (string) $p['nip'], 'nama' => StrukturService::namaGelar($p['glr_awal'], (string) $p['nama'], $p['glr_akhir'])],
            $rows,
        );
    }

    /**
     * Kalkulator real-time (§6.3.7): tanpa efek tulis; jenis 2 mengembalikan `tgl_akhir` dari `durasi`.
     *
     * @param array<string, mixed> $in
     *
     * @return array<string, mixed>
     */
    public function hitungLama(AuthContext $auth, array $in): array
    {
        $jenis   = $this->angka($in['id_jenis_cuti'] ?? null);
        $nip     = $this->nipUntukKuota($auth, $in['nip'] ?? null);
        $tahun   = (int) substr($this->hariIni(), 0, 4);
        $kecuali = $this->angka($in['id_riwayat_cuti'] ?? null);

        if ($kecuali !== null) {
            $lama = $this->db->table('riwayat_cuti')->select('nip, created_at')->where('id_riwayat_cuti', $kecuali)->get()->getRowArray();

            if ($lama !== null) {
                $tahun = $this->kuota->tahunAcuan((string) $lama['created_at']);
            }
        }

        $cek = ValidasiTanggalCuti::periksa(
            $jenis ?? 0,
            $this->teks($in['tgl_mulai'] ?? null),
            $this->teks($in['tgl_akhir'] ?? null),
            $this->angka($in['durasi'] ?? null),
            $this->hariIni(),
            $tahun,
            $this->aturan,
        );
        $errors = $cek['errors'];

        if ($jenis === null || ! $this->aturan->jenisDikenal($jenis)) {
            $errors['id_jenis_cuti'] = 'Silahkan pilih jenis cuti.';
        }

        if ($errors !== [] || $cek['tgl_akhir'] === null) {
            throw new ValidationException('Validasi gagal.', $this->keDaftar($errors));
        }

        /** @var int $jenis */
        $mulai = (string) $in['tgl_mulai'];
        $hasil = $this->kuota->hitungLama($jenis, $mulai, $cek['tgl_akhir']);

        if (($pesan = ValidasiTanggalCuti::periksaLama($hasil['lama_cuti'])) !== null) {
            throw new ValidationException('Validasi gagal.', ['tgl_mulai' => [$pesan]]);
        }

        $kuota = null;

        if ($jenis === AturanCuti::JENIS_TAHUNAN && $nip !== null) {
            $h     = $this->kuota->periksa($nip, $mulai, $cek['tgl_akhir'], $tahun, $kecuali);
            $kuota = $h->detail + ['cukup' => $h->valid, 'pesan' => $h->pesan];
        }

        return [
            'lama_cuti'        => $hasil['lama_cuti'],
            'satuan_lama_cuti' => $hasil['satuan_lama_cuti'],
            'tgl_akhir'        => $hasil['tgl_akhir'],
            'hari_libur'       => $this->keteranganLibur($hasil['hari_libur']),
            'kuota'            => $kuota,
        ];
    }

    // ------------------------------------------------------------------
    // Daftar & detail
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function daftar(AuthContext $auth, array $query): array
    {
        $this->wajibRoleRoute($auth);

        $q    = ListQuery::from($query, ['mode', 'status', 'id_jenis_cuti', 'tahun', 'search', 'id_unit', 'id_satker', 'page', 'per_page']);
        $role = (int) $auth->role();
        $mode = $q->filled('mode') ? $q->string('mode') : (in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true) ? self::MODE_ADMIN : self::MODE_SAYA);

        if (! in_array($mode, [self::MODE_SAYA, self::MODE_PERSETUJUAN, self::MODE_ADMIN], true)) {
            throw ValidationException::forField('mode', 'Mode tidak dikenal.');
        }

        if ($mode === self::MODE_ADMIN && ! in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true)) {
            throw new ForbiddenException('Anda tidak berhak melihat daftar admin cuti.');
        }

        $nip = $auth->nip();

        $bangun = function () use ($q, $mode, $nip, $auth): BaseBuilder {
            $b = $this->db->table('riwayat_cuti rc');

            if ($mode === self::MODE_SAYA) {
                $nip === null ? $b->where('1 = 0', null, false) : $b->where('rc.nip', $nip);
                $b->orderBy('rc.tgl_mulai', 'DESC');
            } elseif ($mode === self::MODE_PERSETUJUAN) {
                if ($nip === null) {
                    $b->where('1 = 0', null, false);
                } else {
                    $b->groupStart()
                        ->groupStart()->where('rc.status', StatusCuti::PENGAJUAN)->where('rc.nip_atasan_langsung', $nip)->where('rc.nip_yang_menyetujui !=', $nip)->where("COALESCE(rc.nip_atasan_langsung, '') != ''", null, false)->where("COALESCE(rc.nip_yang_menyetujui, '') != ''", null, false)->groupEnd()
                        ->orGroupStart()->where('rc.status', StatusCuti::DISETUJUI_AL)->where('rc.nip_yang_menyetujui', $nip)->groupEnd()
                        ->orGroupStart()->where('rc.status', StatusCuti::PENGAJUAN)->where('rc.nip_yang_menyetujui', $nip)->groupStart()->where("COALESCE(rc.nip_atasan_langsung, '') = ''", null, false)->orWhere('rc.nip_atasan_langsung = rc.nip_yang_menyetujui', null, false)->groupEnd()->groupEnd()
                        ->orGroupStart()->where('rc.status', StatusCuti::PENGAJUAN)->where("COALESCE(rc.nip_yang_menyetujui, '') = ''", null, false)->where('rc.nip_atasan_langsung', $nip)->groupEnd()
                        ->groupEnd();
                }

                $b->orderBy('rc.tgl_mulai', 'ASC');
            } else {
                $tahun = $q->filled('tahun') ? (int) $q->string('tahun') : (int) substr($this->hariIni(), 0, 4);
                $b->where('YEAR(rc.tgl_mulai) >=', $tahun - 2, false);
                $this->scope->terapkanKeQuery($b, $auth, 'rc.nip');
                $b->orderBy('rc.tgl_mulai', 'DESC');
            }

            if ($q->filled('status') && ctype_digit($q->string('status'))) {
                $b->where('rc.status', (int) $q->string('status'));
            }

            if ($q->filled('id_jenis_cuti') && ctype_digit($q->string('id_jenis_cuti'))) {
                $b->where('rc.id_jenis_cuti', (int) $q->string('id_jenis_cuti'));
            }

            if ($mode !== self::MODE_ADMIN && $q->filled('tahun') && ctype_digit($q->string('tahun'))) {
                $b->where('YEAR(rc.tgl_mulai)', (int) $q->string('tahun'), false);
            }

            if ($q->filled('search')) {
                $like = ListQuery::likeLiteral($this->db, $q->string('search'));
                $b->groupStart()->like('rc.nama', $like, 'both', null, true)->orLike('rc.nip', $like, 'both', null, true)->groupEnd();
            }

            if ($mode === self::MODE_ADMIN && ($q->filled('id_unit') || $q->filled('id_satker'))) {
                $b->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = rc.nip', 'left');

                if ($q->filled('id_unit') && ctype_digit($q->string('id_unit'))) {
                    $b->where('pmj.id_unit', (int) $q->string('id_unit'));
                }

                if ($q->filled('id_satker') && ctype_digit($q->string('id_satker'))) {
                    $b->where('pmj.id_satker', (int) $q->string('id_satker'));
                }
            }

            return $b;
        };

        $total   = $bangun()->countAllResults();
        $page    = $q->page();
        $perPage = $q->perPage(20, 100);
        $baris   = $bangun()->select('rc.*')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        $adaFile = $this->adaBerkas(array_map(static fn (array $r): int => (int) $r['id_riwayat_cuti'], $baris));

        $items = array_map(fn (array $r): array => $this->item($auth, $r, $adaFile[(int) $r['id_riwayat_cuti']] ?? ['lampiran' => false, 'surat' => false], $mode === self::MODE_ADMIN), $baris);

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'mode'       => $mode,
            'can_create' => in_array($role, self::ROLE_AJUKAN, true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(AuthContext $auth, int $id): array
    {
        $this->wajibRoleRoute($auth);

        $cuti = $this->ambil($id) ?? throw new NotFoundException();

        if (! $this->bolehLihat($auth, $cuti)) {
            throw new NotFoundException();
        }

        // Ketua tim yang membuka detail → tandai sudah dibaca (status 1).
        if ($auth->nip() !== null) {
            $this->db->table('riwayat_cuti_notif_kt')->where('id_riwayat_cuti', $id)->where('nip_kt', $auth->nip())->where('status', 0)->update(['status' => 1]);
        }

        return $this->itemDetail($auth, $cuti);
    }

    // ------------------------------------------------------------------
    // Tambah / ubah / hapus
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $in
     *
     * @return array<string, mixed>
     */
    public function tambah(AuthContext $auth, array $in, ?UploadedFile $berkas): array
    {
        $this->wajibBolehKelola($auth);

        $nip    = $this->nipPemohon($auth, $this->teks($in['nip'] ?? null), 'nip');
        $status = (int) $auth->role() === Role::SUPER_ADMIN ? $this->statusAdmin($in['status'] ?? null) : StatusCuti::PENGAJUAN;
        $hari   = $this->hariIni();
        $tahun  = (int) substr($hari, 0, 4);

        return $this->simpan($auth, $in, $berkas, $nip, $status, $tahun, null);
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array<string, mixed>
     */
    public function ubah(AuthContext $auth, int $id, array $in, ?UploadedFile $berkas): array
    {
        $this->wajibBolehKelola($auth);

        $lama = $this->ambil($id) ?? throw new NotFoundException();

        if (! $this->bolehLihat($auth, $lama)) {
            throw new NotFoundException();
        }

        $role = (int) $auth->role();

        if ($role !== Role::SUPER_ADMIN && (string) $lama['nip'] !== $auth->nip()) {
            throw new ForbiddenException('Anda tidak berhak mengubah pengajuan cuti ini.');
        }

        if ((int) $lama['status'] !== StatusCuti::PENGAJUAN) {
            throw ValidationException::forField('status', 'Pengajuan cuti hanya dapat diubah saat berstatus pengajuan.');
        }

        if ($role !== Role::SUPER_ADMIN && (string) $lama['tgl_mulai'] < $this->hariIni()) {
            throw ValidationException::forField('tgl_mulai', 'Pengajuan cuti yang tanggal mulainya sudah lewat tidak dapat diubah.');
        }

        $nip = $role === Role::SUPER_ADMIN ? ($this->teks($in['nip'] ?? null) ?? (string) $lama['nip']) : (string) $lama['nip'];

        return $this->simpan($auth, $in, $berkas, $this->nipPemohon($auth, $nip, 'nip'), StatusCuti::PENGAJUAN, $this->kuota->tahunAcuan((string) $lama['created_at']), $lama);
    }

    /**
     * Hapus fisik (D6, §6.2.6) — satu transaksi, refund saldo, pulihkan CLTN, audit `before` lengkap.
     *
     * @return array{deleted: bool, soft_delete: bool, id: int}
     */
    public function hapus(AuthContext $auth, int $id): array
    {
        $this->wajibRoleRoute($auth);

        $cuti = $this->ambil($id) ?? throw new NotFoundException();

        if (! $this->bolehLihat($auth, $cuti)) {
            throw new NotFoundException();
        }

        $role  = (int) $auth->role();
        $milik = (string) $cuti['nip'] === $auth->nip();

        if ($role === Role::SUPER_ADMIN) {
            // semua status
        } elseif (in_array($role, [Role::PEGAWAI, Role::PPPK], true) && $milik) {
            if (! in_array((int) $cuti['status'], [StatusCuti::PENGAJUAN, StatusCuti::DITOLAK], true)) {
                throw new ForbiddenException('Pengajuan cuti yang sudah diproses tidak dapat dihapus.');
            }
        } else {
            throw new ForbiddenException('Anda tidak berhak menghapus pengajuan cuti ini.');
        }

        $this->dalamTransaksi(function () use ($id, &$cuti): void {
            $this->kunciPegawai((string) $cuti['nip']);
            $cuti = $this->ambil($id, true) ?? throw new NotFoundException();

            if ((int) $cuti['id_jenis_cuti'] === AturanCuti::JENIS_TAHUNAN && in_array((int) $cuti['status'], StatusCuti::EFEK_FINAL, true)) {
                $this->kuota->kembalikan($cuti);
            }

            if ((int) $cuti['id_jenis_cuti'] === AturanCuti::JENIS_CLTN && (int) $cuti['status'] === StatusCuti::DISETUJUI
                && EfekFinalCuti::cltnAktif(AturanCuti::JENIS_CLTN, StatusCuti::DISETUJUI, (string) $cuti['tgl_mulai'], (string) $cuti['tgl_akhir'], $this->hariIni())) {
                $this->efek->pulihkanCltn((string) $cuti['nip'], $id);
            }

            foreach ([self::ID_LAMPIRAN, self::ID_SURAT_BELUM_TTE, self::ID_SURAT_TTE] as $kode) {
                foreach ($this->lampiran->daftar((string) $cuti['nip'], $kode, $id) as $l) {
                    $this->lampiran->hapus((string) $cuti['nip'], (int) $l['id_attachment']);
                }
            }

            $this->db->table('riwayat_cuti_notif_kt')->where('id_riwayat_cuti', $id)->delete();

            if (! $this->model()->delete($id)) {
                throw new \RuntimeException('Pengajuan cuti gagal dihapus.');
            }
        });

        return ['deleted' => true, 'soft_delete' => false, 'id' => $id];
    }

    /**
     * Unduh lampiran bukti atau surat terakhir (ber-TTE bila ada, kalau tidak versi belum TTE; hanya status 1/4).
     *
     * @return array{isi: string, nama: string, mime: string}
     */
    public function unduh(AuthContext $auth, int $id, string $jenis): array
    {
        $this->wajibRoleRoute($auth);

        $cuti = $this->ambil($id) ?? throw new NotFoundException();

        if (! $this->bolehLihat($auth, $cuti)) {
            throw new NotFoundException();
        }

        $nip = (string) $cuti['nip'];

        if ($jenis === 'surat') {
            if (! in_array((int) $cuti['status'], StatusCuti::EFEK_FINAL, true)) {
                throw new NotFoundException('Surat cuti belum tersedia.');
            }

            $daftar = $this->lampiran->daftar($nip, self::ID_SURAT_TTE, $id) ?: $this->lampiran->daftar($nip, self::ID_SURAT_BELUM_TTE, $id);
        } else {
            $daftar = $this->lampiran->daftar($nip, self::ID_LAMPIRAN, $id);
        }

        if ($daftar === []) {
            throw new NotFoundException('Berkas tidak ditemukan.');
        }

        $hasil = $this->lampiran->ambil($nip, (int) end($daftar)['id_attachment']);
        $mime  = (string) ($hasil['lampiran']['file_type'] ?? '');

        return ['isi' => (string) $hasil['isi'], 'nama' => (string) ($hasil['lampiran']['display_name'] ?? 'berkas'), 'mime' => $mime !== '' ? $mime : 'application/octet-stream'];
    }

    // ------------------------------------------------------------------
    // Akses & aksi
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $cuti
     */
    public function bolehLihat(AuthContext $auth, array $cuti): bool
    {
        $role = (int) $auth->role();
        $nip  = $auth->nip();

        if ($role === Role::SUPER_ADMIN) {
            return true;
        }

        if ($nip !== null) {
            if ($nip === (string) $cuti['nip'] || $nip === (string) ($cuti['nip_atasan_langsung'] ?? '') || $nip === (string) ($cuti['nip_yang_menyetujui'] ?? '')) {
                return true;
            }

            $kt = $this->db->table('riwayat_cuti_notif_kt')->where('id_riwayat_cuti', (int) $cuti['id_riwayat_cuti'])->where('nip_kt', $nip)->countAllResults();

            if ($kt > 0) {
                return true;
            }
        }

        return $role === Role::ADMIN_SATKER && $this->scope->bolehLihat($auth, (string) $cuti['nip']);
    }

    /**
     * Penanda `aksi {}` per item (§6.4.4), dihitung server.
     *
     * @param array<string, mixed> $cuti
     *
     * @return array<string, bool>
     */
    public function aksi(AuthContext $auth, array $cuti, bool $adaSuratTte, bool $adaSurat): array
    {
        $role   = (int) $auth->role();
        $nip    = $auth->nip();
        $status = (int) $cuti['status'];
        $milik  = $nip !== null && $nip === (string) $cuti['nip'];

        $ubah = $role === Role::SUPER_ADMIN
            ? $status === StatusCuti::PENGAJUAN
            : (in_array($role, [Role::PEGAWAI, Role::PPPK], true) && $milik && $status === StatusCuti::PENGAJUAN && (string) $cuti['tgl_mulai'] >= $this->hariIni());

        $hapus = $role === Role::SUPER_ADMIN
            || (in_array($role, [Role::PEGAWAI, Role::PPPK], true) && $milik && in_array($status, [StatusCuti::PENGAJUAN, StatusCuti::DITOLAK], true));

        $tunggu   = StatusCuti::menunggu($status);
        $nipAl    = $this->tekstuPlain($cuti['nip_atasan_langsung'] ?? null);
        $nipPyb   = $this->tekstuPlain($cuti['nip_yang_menyetujui'] ?? null);
        $tahap    = TransisiCuti::tahapAktif($status, TransisiCuti::langkahTunggal($nipAl, $nipPyb));
        $pelaku   = TransisiCuti::nipPelaku($status, $nipAl, $nipPyb);
        $proses   = $tunggu && $nip !== null && $pelaku !== null && $pelaku !== '' && $pelaku === $nip;
        $flag     = $tahap === null ? null : PengingatCuti::flagTahap($tahap);
        $tunda    = $proses && $flag !== null && (int) ($cuti[$flag] ?? 0) === 0 && PengingatCuti::bolehTunda($this->sekarang(), (string) $cuti['tgl_mulai'], $this->aturan);
        $atasNama = $role === Role::SUPER_ADMIN && $tunggu && $this->tahapTakTerjangkau($cuti) !== null;

        return [
            'ubah'       => $ubah,
            'hapus'      => $hapus,
            'proses'     => $proses,
            'tangguhkan' => $proses && (int) $cuti['id_jenis_cuti'] === AturanCuti::JENIS_TAHUNAN,
            'tunda'      => $tunda,
            'esign'      => in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true) && in_array($status, StatusCuti::EFEK_FINAL, true) && ! $adaSuratTte
                && ($role === Role::SUPER_ADMIN || $this->scope->bolehProses($auth, (string) $cuti['nip'])),
            'unduh_surat'      => in_array($status, StatusCuti::EFEK_FINAL, true) && $adaSurat,
            'proses_atas_nama' => $atasNama,
        ];
    }

    /**
     * Alasan approver tahap aktif tak terjangkau (null = terjangkau atau tidak menunggu).
     *
     * @param array<string, mixed> $cuti
     */
    public function tahapTakTerjangkau(array $cuti): ?string
    {
        $status = (int) $cuti['status'];

        if (! StatusCuti::menunggu($status)) {
            return null;
        }

        $nipAl  = $this->tekstuPlain($cuti['nip_atasan_langsung'] ?? null);
        $nipPyb = $this->tekstuPlain($cuti['nip_yang_menyetujui'] ?? null);

        return $this->terjangkau->alasanTakTerjangkau(TransisiCuti::nipPelaku($status, $nipAl, $nipPyb));
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>      $in
     * @param array<string, mixed>|null $lama
     *
     * @return array<string, mixed>
     */
    private function simpan(AuthContext $auth, array $in, ?UploadedFile $berkas, string $nip, int $status, int $tahunAcuan, ?array $lama): array
    {
        $profil = $this->profil($nip) ?? throw ValidationException::forField('nip', 'Pegawai tidak ditemukan atau tidak aktif.');
        $siap   = $this->validasi($auth, $in, $berkas, $profil, $tahunAcuan, $lama === null ? null : (int) $lama['id_riwayat_cuti']);

        $hari = $this->hariIni();
        $data = $siap['data'] + $this->snapshotPemohon($profil) + $this->snapshotApprover($profil, $siap['data'], $hari);

        $data['status']     = $status;
        $data['status_al']  = $status === StatusCuti::DISETUJUI ? StatusCuti::DISETUJUI : 0;
        $data['status_pyb'] = $status === StatusCuti::DISETUJUI ? StatusCuti::DISETUJUI : 0;
        $data['updated_by'] = $auth->idPengguna() ?? 0;

        if ($status === StatusCuti::DISETUJUI) {
            $data['show_pejabat'] = 1;
        }

        $ketua  = $siap['ketua_tim'];
        $idBaru = 0;

        $this->dalamTransaksi(function () use ($nip, $data, $ketua, $berkas, $lama, $status, $hari, $tahunAcuan, &$idBaru): void {
            $this->kunciPegawai($nip);

            // Saldo pemohon saat ini → snapshot baris (L:1795-1808).
            $saldo = $this->kuota->saldo($nip, $tahunAcuan, true);
            $data += $saldo === null ? ['sisa_cuti' => null, 'sisa_cuti_n1' => null, 'sisa_cuti_n2' => null, 'utang_cuti' => null] : [
                'sisa_cuti' => $saldo->n, 'sisa_cuti_n1' => $saldo->n1, 'sisa_cuti_n2' => $saldo->n2, 'utang_cuti' => $saldo->utang,
            ];

            if ($lama === null) {
                $data['created_at'] = gmdate('Y-m-d H:i:s');

                $idBaru = (int) $this->model()->insert($data);

                if ($idBaru === 0) {
                    throw new \RuntimeException('Pengajuan cuti gagal disimpan.');
                }
            } else {
                $idBaru = (int) $lama['id_riwayat_cuti'];

                if (! $this->model()->update($idBaru, $data)) {
                    throw new \RuntimeException('Pengajuan cuti gagal diperbarui.');
                }
            }

            $this->sinkronKetuaTim($idBaru, $ketua);

            if ($berkas !== null) {
                foreach ($this->lampiran->daftar($nip, self::ID_LAMPIRAN, $idBaru) as $l) {
                    $this->lampiran->hapus($nip, (int) $l['id_attachment']);
                }

                $baris = $this->lampiran->simpan($nip, self::ID_LAMPIRAN, $idBaru, $berkas, $this->aturanLampiran());
                $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $idBaru)->update(['file_bukti' => (string) ($baris['basename'] ?? '')]);
            }

            if ($status === StatusCuti::DISETUJUI) {
                $cuti  = $this->ambil($idBaru, true) ?? throw new NotFoundException();
                $hasil = $this->efek->terapkan($cuti, StatusCuti::DISETUJUI, $hari);

                if (! $hasil->valid) {
                    throw new ValidationException('Validasi gagal.', ['lama_cuti' => [(string) $hasil->pesan]]);
                }
            }
        });

        return $this->itemDetail($auth, $this->ambil($idBaru) ?? throw new NotFoundException());
    }

    /**
     * @param array<string, mixed>      $in
     * @param array<string, mixed>      $profil
     *
     * @return array{data: array<string, mixed>, ketua_tim: list<string>}
     */
    private function validasi(AuthContext $auth, array $in, ?UploadedFile $berkas, array $profil, int $tahunAcuan, ?int $kecuali): array
    {
        $errors = [];
        $nip    = (string) $profil['nip'];
        $hari   = $this->hariIni();
        $jenis  = $this->angka($in['id_jenis_cuti'] ?? null);

        if ($jenis === null || $jenis === 0) {
            $errors['id_jenis_cuti'] = 'Silahkan pilih jenis cuti.';
        } elseif (! in_array($jenis, $this->daftarJenisId($profil, $auth), true)) {
            $errors['id_jenis_cuti'] = AturanJenisCuti::PESAN_JENIS_TIDAK_TERSEDIA;
        }

        $jenisValid = $jenis !== null && ! isset($errors['id_jenis_cuti']);
        $cek        = ValidasiTanggalCuti::periksa(
            $jenisValid ? $jenis : AturanCuti::JENIS_TAHUNAN,
            $this->teks($in['tgl_mulai'] ?? null),
            $this->teks($in['tgl_akhir'] ?? null),
            $this->angka($in['durasi'] ?? null),
            $hari,
            $tahunAcuan,
            $this->aturan,
        );
        $errors += $cek['errors'];

        $lama  = 0;
        $akhir = $cek['tgl_akhir'];
        $mulai = $this->teks($in['tgl_mulai'] ?? null);

        if ($jenisValid && $akhir !== null && $mulai !== null && ! isset($errors['tgl_mulai'])) {
            $hitung = $this->kuota->hitungLama($jenis, $mulai, $akhir);
            $lama   = $hitung['lama_cuti'];

            if (($pesan = ValidasiTanggalCuti::periksaLama($lama)) !== null) {
                $errors['tgl_mulai'] = $pesan;
            }
        }

        if ($jenisValid && $akhir !== null && $mulai !== null && ! isset($errors['tgl_mulai'])) {
            $this->validasiBisnis($errors, $nip, $jenis, $mulai, $akhir, $tahunAcuan, $kecuali, $profil);
        }

        $telp = $this->teks($in['no_telp_saat_cuti'] ?? null);

        if ($telp === null || $telp === '') {
            $errors['no_telp_saat_cuti'] = 'No. telepon selama cuti wajib diisi.';
        } elseif (mb_strlen($telp) > 50) {
            $errors['no_telp_saat_cuti'] = 'No. telepon selama cuti maksimal 50 karakter.';
        }

        $alamat = $this->teks($in['alamat_saat_cuti'] ?? null);

        if ($alamat === null || $alamat === '') {
            $errors['alamat_saat_cuti'] = 'Alamat selama cuti wajib diisi.';
        }

        $ket = $this->teks($in['keterangan'] ?? null);

        if ($ket === null || mb_strlen($ket) < 10) {
            $errors['keterangan'] = 'Keterangan minimal 10 karakter.';
        }

        $eselonI = (int) ($profil['id_sub_group_jabatan'] ?? 0) === 1;
        $nipAl   = $this->teks($in['nip_atasan_langsung'] ?? null);
        $nipPyb  = $this->teks($in['nip_yang_menyetujui'] ?? null);

        if (! $eselonI) {
            foreach ([['nip_atasan_langsung', $nipAl, 'Silahkan pilih pejabat atasan langsung.'], ['nip_yang_menyetujui', $nipPyb, 'Silahkan pilih pejabat yang berwenang menyetujui cuti.']] as [$f, $v, $pesan]) {
                if ($v === null || $v === '') {
                    $errors[$f] = $pesan;
                } elseif ($this->db->table('pegawai')->where('nip', $v)->countAllResults() === 0) {
                    $errors[$f] = 'Pegawai tidak ditemukan.';
                }
            }
        }

        $ketua = $this->ketuaTim($in['ketua_tim'] ?? null, $errors);

        if ($berkas !== null) {
            try {
                ValidasiBerkas::periksa($berkas, $this->aturanLampiran(), 'file_bukti');
            } catch (ValidationException $e) {
                foreach (($e->getErrors() ?? []) as $f => $pesan) {
                    $errors[$f] = is_array($pesan) ? (string) ($pesan[0] ?? '') : (string) $pesan;
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $this->keDaftar($errors));
        }

        /** @var int $jenis */
        $data = [
            'id_jenis_cuti'       => $jenis,
            'nama_jenis_cuti'     => (string) ($this->db->table('jenis_cuti')->select('nama_jenis_cuti')->where('id_jenis_cuti', $jenis)->get()->getRowArray()['nama_jenis_cuti'] ?? ''),
            'tgl_mulai'           => $mulai,
            'tgl_akhir'           => $akhir,
            'lama_cuti'           => $lama,
            'satuan_lama_cuti'    => 'Hari',
            'no_telp_saat_cuti'   => $telp,
            'alamat_saat_cuti'    => $alamat,
            'keterangan'          => $ket,
            'nip_atasan_langsung' => $eselonI ? null : $nipAl,
            'nip_yang_menyetujui' => $eselonI ? null : $nipPyb,
            'ketua_tim'           => $ketua === [] ? null : implode('***', $ketua),
        ];

        return ['data' => $data, 'ketua_tim' => $ketua];
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $profil
     */
    private function validasiBisnis(array &$errors, string $nip, int $jenis, string $mulai, string $akhir, int $tahunAcuan, ?int $kecuali, array $profil): void
    {
        $kelompok = AturanJenisCuti::kelompokTumpangTindih($jenis);
        $b        = $this->db->table('riwayat_cuti')->where('nip', $nip)->where('tgl_mulai <=', $akhir)->where('tgl_akhir >=', $mulai)->whereIn('status', $kelompok['status']);

        if ($kelompok['jenis'] !== null) {
            $b->whereIn('id_jenis_cuti', $kelompok['jenis']);
        }

        if ($kecuali !== null) {
            $b->where('id_riwayat_cuti !=', $kecuali);
        }

        if ($b->countAllResults() > 0) {
            $errors['tgl_mulai'] = AturanJenisCuti::pesanTumpangTindih();

            return;
        }

        if (in_array($jenis, [AturanCuti::JENIS_BESAR, AturanCuti::JENIS_CLTN], true) && (int) ($profil['mker_th'] ?? 0) < AturanJenisCuti::MKER_BESAR_MINIMAL) {
            $errors['id_jenis_cuti'] = AturanJenisCuti::PESAN_MKER_BESAR;

            return;
        }

        if ($jenis !== AturanCuti::JENIS_TAHUNAN) {
            return;
        }

        $besar = $this->db->table('riwayat_cuti')->where('nip', $nip)->where('id_jenis_cuti', AturanCuti::JENIS_BESAR)->where('status', StatusCuti::DISETUJUI)
            ->where('YEAR(tgl_akhir)', (int) substr($mulai, 0, 4), false)->where('tgl_akhir <', $mulai)->countAllResults();

        if ($besar > 0) {
            $errors['id_jenis_cuti'] = AturanJenisCuti::PESAN_KEHILANGAN_TAHUNAN;

            return;
        }

        $h = $this->kuota->periksa($nip, $mulai, $akhir, $tahunAcuan, $kecuali);

        if (! $h->valid) {
            $errors['lama_cuti'] = (string) $h->pesan;
        }
    }

    /**
     * @param mixed                 $nilai
     * @param array<string, string> $errors
     *
     * @return list<string>
     */
    private function ketuaTim(mixed $nilai, array &$errors): array
    {
        if ($nilai === null || $nilai === '') {
            return [];
        }

        if (! is_array($nilai)) {
            $errors['ketua_tim'] = 'Ketua tim harus berupa daftar NIP.';

            return [];
        }

        $daftar = [];

        foreach ($nilai as $n) {
            if (! is_string($n) && ! is_int($n)) {
                $errors['ketua_tim'] = 'Ketua tim harus berupa daftar NIP.';

                return [];
            }

            $daftar[(string) $n] = true;
        }

        $nips = array_map('strval', array_keys($daftar));

        if ($nips === []) {
            return [];
        }

        $ada = array_column($this->db->table('pegawai')->select('nip')->whereIn('nip', $nips)->where('status', 1)->get()->getResultArray(), 'nip');

        if (count($ada) !== count($nips)) {
            $errors['ketua_tim'] = 'Ketua tim harus pegawai aktif.';
        }

        return $nips;
    }

    /**
     * @param list<string> $baru
     */
    private function sinkronKetuaTim(int $id, array $baru): void
    {
        $ada = array_column($this->db->table('riwayat_cuti_notif_kt')->select('nip_kt')->where('id_riwayat_cuti', $id)->get()->getResultArray(), 'nip_kt');

        $buang = array_diff($ada, $baru);

        if ($buang !== []) {
            $this->db->table('riwayat_cuti_notif_kt')->where('id_riwayat_cuti', $id)->whereIn('nip_kt', array_values($buang))->delete();
        }

        foreach (array_diff($baru, $ada) as $nip) {
            $this->db->table('riwayat_cuti_notif_kt')->insert(['id_riwayat_cuti' => $id, 'nip_kt' => $nip, 'status' => 0, 'created_at' => gmdate('Y-m-d H:i:s')]);
        }
    }

    /**
     * @param array<string, mixed> $profil
     *
     * @return array<string, mixed>
     */
    private function snapshotPemohon(array $profil): array
    {
        return [
            'nip'                  => (string) $profil['nip'],
            'nama'                 => StrukturService::namaGelar($profil['glr_awal'] ?? null, (string) $profil['nama'], $profil['glr_akhir'] ?? null),
            'id_sub_group_jabatan' => $profil['id_sub_group_jabatan'] === null ? null : (int) $profil['id_sub_group_jabatan'],
            'pangkat'              => $profil['pangkat'] ?? null,
            'gol_ruang'            => $profil['gol_ruang'] ?? null,
            'jabatan'              => $profil['jabatan'] ?? null,
            'satker'               => $profil['satker'] ?? null,
            'mker_th'              => (int) ($profil['mker_th'] ?? 0),
            'mker_bl'              => (int) ($profil['mker_bl'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $profil
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function snapshotApprover(array $profil, array $data, string $hari): array
    {
        if ((int) ($profil['id_sub_group_jabatan'] ?? 0) === 1) {
            $menteri = $this->nilaiWeb('nama_menteri');
            $jabatan = $this->nilaiWeb('jabatan_menteri_full');

            return [
                'nama_atasan_langsung' => $menteri, 'jabatan_atasan_langsung' => $jabatan, 'id_jabatan_al' => null,
                'nama_yang_menyetujui' => $menteri, 'jabatan_yang_menyetujui' => $jabatan, 'id_jabatan_pyb' => null,
            ];
        }

        $hasil = [];

        foreach ([['atasan_langsung', 'nip_atasan_langsung', 'al'], ['yang_menyetujui', 'nip_yang_menyetujui', 'pyb']] as [$akhiran, $kolomNip, $kode]) {
            $nip = $data[$kolomNip] ?? null;
            $row = $nip === null ? null : $this->db->table('pegawai p')
                ->select('p.glr_awal, p.nama, p.glr_akhir, pmj.jabatan, pmj.id_jabatan')
                ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = p.nip', 'left')
                ->where('p.nip', $nip)->get()->getRowArray();

            $hasil["nama_{$akhiran}"]    = $row === null ? null : StrukturService::namaGelar($row['glr_awal'], (string) $row['nama'], $row['glr_akhir']);
            $hasil["jabatan_{$akhiran}"] = $row['jabatan'] ?? null;
            $hasil["id_jabatan_{$kode}"] = isset($row['id_jabatan']) ? (int) $row['id_jabatan'] : null;
        }

        return $hasil;
    }

    private function nilaiWeb(string $key): ?string
    {
        try {
            $v = ($this->web ?? service('webConfigService'))->value($key);
        } catch (Throwable) {
            return null;
        }

        $v = is_string($v) ? trim($v) : '';

        return $v === '' ? null : $v;
    }

    /**
     * Profil pemohon: data pegawai aktif + pangkat/masa kerja terakhir + jabatan. Null = tidak ada/tidak aktif.
     *
     * @return array<string, mixed>|null
     */
    private function profil(string $nip): ?array
    {
        $r = $this->db->table('pegawai p')
            ->select('p.nip, p.nama, p.glr_awal, p.glr_akhir, p.jenis_kelamin, p.id_jenis_pegawai, kp.pangkat, kp.gol, kp.ruang, kp.gol_ruang, kp.mker_th, kp.mker_bl, kp.id_jenis_kp, '
                . 'pmj.jabatan, pmj.satker, pmj.id_group_jabatan, pmj.id_sub_group_jabatan, pmj.id_unit, pmj.id_satker, pmj.gol_pppk')
            ->join('pegawai_kp kp', 'kp.nip = p.nip', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = p.nip', 'left')
            ->where('p.nip', $nip)->where('p.status', 1)
            ->get()->getRowArray();

        return $r;
    }

    /**
     * @param array<string, mixed> $profil
     *
     * @return list<int>
     */
    private function daftarJenisId(array $profil, AuthContext $auth): array
    {
        $nip   = (string) $profil['nip'];
        $aktif = array_map('intval', array_column($this->db->table('jenis_cuti')->select('id_jenis_cuti')->where('status', 1)->get()->getResultArray(), 'id_jenis_cuti'));

        $tahunMutasi = null;

        if ((int) $profil['id_jenis_pegawai'] === AturanJenisCuti::ID_PPPK) {
            $rmj = $this->db->table('riwayat_mutasi_jabatan')->select('tmtsk')->where('nip', $nip)->where('status', 1)->orderBy('tmtsk', 'ASC')->limit(1)->get()->getRowArray();

            if ($rmj !== null) {
                $tahunMutasi = (int) (new DateTimeImmutable((string) $rmj['tmtsk']))->diff(new DateTimeImmutable($this->hariIni()))->y;
            }
        }

        $besarTahunIni = $this->db->table('riwayat_cuti')->where('nip', $nip)->where('id_jenis_cuti', AturanCuti::JENIS_BESAR)->where('status', StatusCuti::DISETUJUI)
            ->where('YEAR(tgl_mulai)', (int) substr($this->hariIni(), 0, 4), false)->countAllResults() > 0;

        return AturanJenisCuti::jenisBoleh(
            $aktif,
            (int) $profil['id_jenis_pegawai'],
            $profil['id_jenis_kp'] === null ? null : (int) $profil['id_jenis_kp'],
            (int) ($profil['mker_th'] ?? 0),
            $profil['jenis_kelamin'] === null ? null : (int) $profil['jenis_kelamin'],
            $tahunMutasi,
            $besarTahunIni,
            (int) $auth->role() === Role::SUPER_ADMIN,
        );
    }

    /**
     * @param array<string, mixed> $profil
     *
     * @return list<array{id_jenis_cuti: int, nama_jenis_cuti: string}>
     */
    private function daftarJenis(array $profil, AuthContext $auth): array
    {
        $ids = $this->daftarJenisId($profil, $auth);

        if ($ids === []) {
            return [];
        }

        $rows = $this->db->table('jenis_cuti')->select('id_jenis_cuti, nama_jenis_cuti')->whereIn('id_jenis_cuti', $ids)->orderBy('order', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $r): array => ['id_jenis_cuti' => (int) $r['id_jenis_cuti'], 'nama_jenis_cuti' => (string) $r['nama_jenis_cuti']], $rows);
    }

    /**
     * @param array<string, mixed> $cuti
     * @param array{lampiran: bool, surat: bool} $ada
     *
     * @return array<string, mixed>
     */
    private function item(AuthContext $auth, array $cuti, array $ada, bool $tandaiAtasNama = false): array
    {
        $nipAl   = $this->tekstuPlain($cuti['nip_atasan_langsung'] ?? null);
        $nipPyb  = $this->tekstuPlain($cuti['nip_yang_menyetujui'] ?? null);
        $tunggal = TransisiCuti::langkahTunggal($nipAl, $nipPyb);
        $eselonI = (int) ($cuti['id_sub_group_jabatan'] ?? 0) === 1;
        $aksi    = $this->aksi($auth, $cuti, false, $ada['surat']);
        $ekstra  = ['ada_lampiran' => $ada['lampiran'], 'ada_surat' => $ada['surat']];

        if ($tandaiAtasNama) {
            $ekstra['perlu_atas_nama'] = $this->tahapTakTerjangkau($cuti) !== null;
        }

        return CutiPenyaji::item($cuti, $tunggal, $eselonI, $aksi, $ekstra);
    }

    /**
     * @param array<string, mixed> $cuti
     *
     * @return array<string, mixed>
     */
    private function itemDetail(AuthContext $auth, array $cuti): array
    {
        $id     = (int) $cuti['id_riwayat_cuti'];
        $nip    = (string) $cuti['nip'];
        $lamp   = $this->lampiran->daftar($nip, self::ID_LAMPIRAN, $id);
        $belum  = $this->lampiran->daftar($nip, self::ID_SURAT_BELUM_TTE, $id);
        $tte    = $this->lampiran->daftar($nip, self::ID_SURAT_TTE, $id);
        $nipAl  = $this->tekstuPlain($cuti['nip_atasan_langsung'] ?? null);
        $nipPyb = $this->tekstuPlain($cuti['nip_yang_menyetujui'] ?? null);

        $nipKt  = array_column($this->db->table('riwayat_cuti_notif_kt')->select('nip_kt')->where('id_riwayat_cuti', $id)->get()->getResultArray(), 'nip_kt');
        $detail = $nipKt === [] ? [] : array_map(
            static fn (array $p): array => ['nip' => (string) $p['nip'], 'nama' => StrukturService::namaGelar($p['glr_awal'], (string) $p['nama'], $p['glr_akhir'])],
            $this->db->table('pegawai')->select('nip, nama, glr_awal, glr_akhir')->whereIn('nip', $nipKt)->get()->getResultArray(),
        );

        $surat = array_merge(
            array_map(static fn (array $s): array => ['id_attachment' => (int) $s['id_attachment'], 'jenis' => 'belum_tte', 'display_name' => $s['display_name'] ?? null, 'created_at' => $s['created_at'] ?? null], $belum),
            array_map(static fn (array $s): array => ['id_attachment' => (int) $s['id_attachment'], 'jenis' => 'tte', 'display_name' => $s['display_name'] ?? null, 'created_at' => $s['created_at'] ?? null], $tte),
        );

        $aksi = $this->aksi($auth, $cuti, $tte !== [], $surat !== []);

        return CutiPenyaji::item(
            $cuti,
            TransisiCuti::langkahTunggal($nipAl, $nipPyb),
            (int) ($cuti['id_sub_group_jabatan'] ?? 0) === 1,
            $aksi,
            [
                'ada_lampiran'     => $lamp !== [],
                'ada_surat'        => $surat !== [],
                'ketua_tim_detail' => $detail,
                'lampiran'         => $lamp,
                'surat'            => $surat,
                'riwayat_approval' => [
                    'keterangan_petugas_approval' => $cuti['keterangan_petugas_approval'] ?? null,
                    'reason_note_al'              => $cuti['reason_note_al'] ?? null,
                    'reason_note_pyb'             => $cuti['reason_note_pyb'] ?? null,
                ],
            ],
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{lampiran: bool, surat: bool}>
     */
    private function adaBerkas(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $hasil = array_fill_keys($ids, ['lampiran' => false, 'surat' => false]);
        $rows  = $this->db->table('document_attachment')->select('id_entri, id_riwayat')
            ->whereIn('id_riwayat', [self::ID_LAMPIRAN, self::ID_SURAT_BELUM_TTE, self::ID_SURAT_TTE])->whereIn('id_entri', array_map('strval', $ids))
            ->get()->getResultArray();

        foreach ($rows as $r) {
            $kunci = $hasil[(int) $r['id_entri']] ?? null;

            if ($kunci === null) {
                continue;
            }

            $hasil[(int) $r['id_entri']][(int) $r['id_riwayat'] === self::ID_LAMPIRAN ? 'lampiran' : 'surat'] = true;
        }

        return $hasil;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ambil(int $id, bool $kunci = false): ?array
    {
        $b = $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id);

        if ($kunci) {
            $r = $this->db->query($b->limit(1)->getCompiledSelect() . ' FOR UPDATE');

            return $r === false ? null : $r->getRowArray();
        }

        return $b->get()->getRowArray();
    }

    private function kunciPegawai(string $nip): void
    {
        $this->db->query('SELECT nip FROM ' . $this->db->prefixTable('pegawai') . ' WHERE nip = ? FOR UPDATE', [$nip]);
    }

    private function dalamTransaksi(Closure $kerja): void
    {
        $this->db->transBegin();

        try {
            $kerja();

            if (! $this->db->transStatus()) {
                throw new \RuntimeException('Transaksi cuti gagal.');
            }

            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        }
    }

    private function model(): RiwayatCutiModel
    {
        return new RiwayatCutiModel();
    }

    private function aturanLampiran(): AturanLampiran
    {
        return new AturanLampiran(self::ID_LAMPIRAN, false, 5, ['jpg', 'jpeg', 'png', 'gif', 'pdf']);
    }

    private function wajibRoleRoute(AuthContext $auth): void
    {
        if (! in_array((int) $auth->role(), self::ROLE_ROUTE, true)) {
            throw new ForbiddenException();
        }
    }

    private function wajibBolehKelola(AuthContext $auth): void
    {
        if (! in_array((int) $auth->role(), self::ROLE_AJUKAN, true)) {
            throw new ForbiddenException('Anda tidak berhak mengelola pengajuan cuti.');
        }
    }

    /**
     * Role 1: NIP dari input (wajib, pegawai aktif); role lain: NIP sesi.
     */
    private function nipPemohon(AuthContext $auth, ?string $input, string $field): string
    {
        if ((int) $auth->role() === Role::SUPER_ADMIN) {
            if ($input === null || $input === '') {
                throw ValidationException::forField($field, 'NIP pegawai wajib diisi.');
            }

            return $input;
        }

        return $auth->nip() ?? throw new ForbiddenException('Akun tidak memiliki NIP pegawai.');
    }

    private function nipUntukKuota(AuthContext $auth, mixed $input): ?string
    {
        if ((int) $auth->role() === Role::SUPER_ADMIN) {
            return is_string($input) && $input !== '' ? $input : null;
        }

        return $auth->nip();
    }

    private function statusAdmin(mixed $nilai): int
    {
        if ($nilai === null || $nilai === '') {
            return StatusCuti::PENGAJUAN;
        }

        if (! in_array((string) $nilai, ['0', '1'], true)) {
            throw ValidationException::forField('status', 'Status harus 0 atau 1.');
        }

        return (int) $nilai;
    }

    /**
     * @param list<string> $tanggal
     *
     * @return list<array{tanggal: string, keterangan: string|null}>
     */
    private function keteranganLibur(array $tanggal): array
    {
        if ($tanggal === []) {
            return [];
        }

        $rows = $this->db->table('hari_libur')->select('tgl_mulai, tgl_akhir, nama_libur')->where('status', 1)
            ->where('tgl_mulai <=', max($tanggal))->where('tgl_akhir >=', min($tanggal))->get()->getResultArray();

        return array_map(static function (string $t) use ($rows): array {
            foreach ($rows as $r) {
                if ((string) $r['tgl_mulai'] <= $t && (string) $r['tgl_akhir'] >= $t) {
                    return ['tanggal' => $t, 'keterangan' => (string) $r['nama_libur']];
                }
            }

            return ['tanggal' => $t, 'keterangan' => null];
        }, $tanggal);
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, list<string>>
     */
    private function keDaftar(array $errors): array
    {
        return array_map(static fn (string $p): array => [$p], $errors);
    }

    private function teks(mixed $nilai): ?string
    {
        if ($nilai === null || is_array($nilai) || is_bool($nilai)) {
            return null;
        }

        $t = trim((string) $nilai);

        return $t === '' ? null : $t;
    }

    private function tekstuPlain(mixed $nilai): ?string
    {
        return $nilai === null ? null : (string) $nilai;
    }

    private function angka(mixed $nilai): ?int
    {
        if (is_int($nilai)) {
            return $nilai;
        }

        return is_string($nilai) && ctype_digit(trim($nilai)) ? (int) trim($nilai) : null;
    }
}
