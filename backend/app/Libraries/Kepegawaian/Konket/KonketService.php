<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Konket;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\KonketServiceInterface;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Lampiran\KolomBerkas;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Models\Kepegawaian\KonketModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\I18n\Time;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * B-13 Konfirmasi Ketidakhadiran / Konket (WS-2 M5 MAKE-013) — service tipis di luar engine riwayat (WS2 §3.4.3),
 * tabel `absen_ijin` status W/V/X/10.
 *
 * Sumber [K]: `controllers/hr/rwy/Konket.php`, `libraries/hr/rwy/L_konket.php` (list :26, list_ad :43, form/form_ad,
 * detail :195, insert :244, update :382, delete :890, konket_min_date :981, validate_param :1527, set_param :1734,
 * process :1800), views `views/hr/employee/rwy/konket/*`.
 *
 * Hak akses (keputusan user 13-10-2026; tulis/proses ikut legacy):
 * - pegawai (2/6/7): mengajukan untuk diri sendiri (add, status W), mengubah miliknya selama W (edit), menghapus miliknya
 *   (delete legacy tanpa syarat status), melihat miliknya;
 * - role 1: semua pegawai (add_ad, edit_ad, delete, process); role 3: pegawai dalam lingkup (PegawaiScope: lihat/ubah
 *   = daftar, proses = jalur proses CR-045, sama dengan legacy process() UNIT_DESTINASI + BPO); peserta dinas
 *   gabungan yang diisi role 3 wajib dalam lingkupnya (legacy form_ad.php:152-171 hanya menawarkan pegawai satker/unit);
 * - [K] role 4/5 LIHAT saja (legacy list_ad ['1','3','4'] + detail 1–7; Matriks v2 menulis 1, 2, 3, 6, 7 — keputusan
 *   user: ikut legacy): antrean semua pegawai + detail, tanpa tulis/proses.
 *
 * Aturan legacy validate_param(): tanggal mulai/akhir wajib, akhir ≥ mulai, keduanya ≤ hari ini, mulai ≥ tanggal minimal
 * (KonketAturan; [V2] tanpa pengecualian akun pengembang USR_SADEV/USR_SPC), jenis & alasan wajib, berkas bukti wajib
 * saat tambah (dan saat ubah bila belum ada; 5 MB, jpg/jpeg/png/gif/pdf/xls/xlsx/doc/docx), tidak beririsan dengan
 * pengajuan W/V pegawai itu. Konket sakit (kategori 10) saat tambah, selain PTT (jenis pegawai 2): maksimal 1 hari kerja,
 * dan hari kerja sebelumnya tidak boleh juga konket sakit (legacy memeriksa "sesudah" dengan arah yang sama — bug,
 * efeknya hanya hari sebelumnya; v2 mengikuti efek itu). Dinas Luar (kategori 8) gabungan: baris peserta (`id_parent`)
 * berisi salinan isian, dibuat/diselaraskan/dihapus bersama induk; diproses dan dihapus bersama induk.
 *
 * [V2]: jenis konket dibatasi per role di server (legacy hanya di form: pegawai & role 3 tanpa id 2/4/5/6, role 1
 * tanpa 4/5); hapus = status 10 (legacy hapus keras + arsip trigger); alasan wajib saat tolak; baris peserta tidak bisa
 * diubah sendiri (ubah lewat induk; legacy edit_ad mengalihkan ke induk).
 */
final class KonketService implements KonketServiceInterface
{
    public const W     = 'W';
    public const V     = 'V';
    public const X     = 'X';
    public const HAPUS = '10';

    public const KATEGORI_DINAS    = 8;
    public const KATEGORI_SAKIT    = 10;
    public const JENIS_PEGAWAI_PTT = 2;

    /** @var list<int> */
    public const ROLE_LIHAT = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK];

    /** @var list<int> tulis (tambah/ubah/hapus) — legacy add/edit/delete */
    public const ROLE_TULIS = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK];

    /** @var list<int> pengamat antrean (legacy list_ad/detail) — lihat saja */
    public const ROLE_PENGAMAT = [Role::ADMIN_VIEW_ESELON1, Role::MENTERI];

    /** @var list<int> */
    public const ROLE_ADMIN = [Role::SUPER_ADMIN, Role::ADMIN_SATKER];

    /** @var list<string> */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'xls', 'xlsx', 'doc', 'docx'];

    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly ?PegawaiScopeInterface $scope = null,
        private readonly ?KolomBerkas $berkas = null,
    ) {
    }

    public static function aturanBerkas(): AturanLampiran
    {
        return new AturanLampiran(0, true, 5, self::EKSTENSI);
    }

    public function daftar(AuthContext $auth, array $filter = []): array
    {
        $this->wajibRole($auth, self::ROLE_LIHAT);
        $page    = max(1, (int) ($filter['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filter['per_page'] ?? 20)));
        $admin   = $this->adalahAdmin($auth) || $this->pengamat($auth);
        $builder = $this->query();

        if (! $admin) {
            $builder->where('a.nip', (string) $auth->nip());
            $urut = 'a.date_start';
        } else {
            [$ds, $de] = $this->rentang($filter);
            $builder->where('p.status', 1)->where('a.date_start >=', "{$ds} 00:00:00")->where('a.date_start <=', "{$de} 23:59:59");
            $this->scope()->terapkanKeQuery($builder, $auth, 'a.nip');
            $urut = 'a.updated_at';

            $search = trim((string) ($filter['search'] ?? ''));

            if ($search !== '') {
                $builder->groupStart()->like('p.nama', $search)->orLike('a.nip', $search)->groupEnd();
            }
        }

        if (in_array((string) ($filter['status'] ?? ''), [self::W, self::V, self::X], true)) {
            $builder->where('a.status', (string) $filter['status']);
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy($urut, 'DESC')->orderBy('a.id', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'mode'       => $admin ? 'admin' : 'pegawai',
            'items'      => array_map(fn (array $r): array => $this->bentuk($r) + ['aksi' => $this->aksi($auth, $r)], $rows),
            'meta'       => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
            'can_create' => ! $this->pengamat($auth),
            'rentang'    => $admin ? array_combine(['ds', 'de'], $this->rentang($filter)) : null,
        ];
    }

    public function opsi(AuthContext $auth): array
    {
        $this->wajibRole($auth, self::ROLE_LIHAT);

        $rows = $this->db()->table('jenis_konket')->select('id_jenis_konket, old_id, jenis_konket, affect_tukin')
            ->where('status', 1)->whereNotIn('id_jenis_konket', $this->jenisTerlarang($auth))
            ->orderBy('order', 'ASC')->orderBy('id_jenis_konket', 'ASC')->get()->getResultArray();

        return [
            'mode'            => $this->adalahAdmin($auth) || $this->pengamat($auth) ? 'admin' : 'pegawai',
            'can_create'      => ! $this->pengamat($auth),
            'tanggal_minimal' => KonketAturan::tanggalMinimal(TanggalBisnis::hariIni()),
            'hari_ini'        => TanggalBisnis::hariIni(),
            'kategori_dinas'  => self::KATEGORI_DINAS,
            'kategori_sakit'  => self::KATEGORI_SAKIT,
            'jenis'           => array_map(static fn (array $r): array => [
                'kategori'     => (int) $r['old_id'],
                'jenis_konket' => (string) $r['jenis_konket'],
                'affect_tukin' => (int) $r['affect_tukin'],
            ], $rows),
        ];
    }

    public function detail(AuthContext $auth, int $id): array
    {
        $row = $this->barisTerlihat($auth, $id);

        return $this->bentuk($row) + [
            'peserta' => $this->peserta($row),
            'aksi'    => $this->aksi($auth, $row),
        ];
    }

    public function tambah(AuthContext $auth, array $data, ?UploadedFile $berkas): array
    {
        $this->wajibRole($auth, self::ROLE_TULIS);
        $admin = $this->adalahAdmin($auth);
        $nip   = $admin ? trim((string) ($data['nip'] ?? '')) : (string) $auth->nip();

        if ($nip === '') {
            throw ValidationException::forField('nip', 'Silakan pilih pegawai.');
        }

        $pegawai = $this->pegawai($nip);

        if ($pegawai === null) {
            throw $admin ? ValidationException::forField('nip', 'Pegawai tidak ditemukan.') : new ForbiddenException('Akun tidak tertaut ke data pegawai.');
        }

        if ($admin && ! $this->scope()->bolehUbah($auth, $nip)) {
            throw new ForbiddenException('Pegawai di luar lingkup akses Anda.');
        }

        $param = $this->validasi($auth, $data, $berkas, $pegawai, null);
        $param += ['nip' => $nip, 'status' => self::W];

        // Pegawai tidak mengisi pengaruh tukin (legacy form.php; kolom default 1); admin boleh (form_ad.php).
        $param['affect_tukin'] ??= 1;

        $id = $this->tulis($auth, null, $param, $berkas, $this->daftarPeserta($auth, $param, $data, $nip));

        return $this->detail($auth, $id);
    }

    public function ubah(AuthContext $auth, int $id, array $data, ?UploadedFile $berkas): array
    {
        $lama = $this->barisTerlihat($auth, $id);

        if ($lama['id_parent'] !== null) {
            throw ValidationException::forField('id_parent', 'Data peserta dinas gabungan diubah melalui pengajuan induknya.');
        }

        if (! $this->aksi($auth, $lama)['ubah']) {
            throw new ForbiddenException('Data konfirmasi ketidakhadiran ini tidak dapat diubah.');
        }

        $pegawai = $this->pegawai((string) $lama['nip']) ?? [];
        $param   = $this->validasi($auth, $data, $berkas, $pegawai, $lama);

        if (! $this->adalahAdmin($auth)) {
            // Legacy edit(): pengajuan pegawai selalu kembali W.
            $param['status'] = self::W;
        }

        $this->tulis($auth, $id, $param, $berkas, $this->daftarPeserta($auth, $param, $data, (string) $lama['nip']), $lama);

        return $this->detail($auth, $id);
    }

    public function hapus(AuthContext $auth, int $id): array
    {
        $row = $this->barisTerlihat($auth, $id);

        if (! $this->aksi($auth, $row)['hapus']) {
            throw new ForbiddenException('Data konfirmasi ketidakhadiran ini tidak dapat dihapus.');
        }

        // Dinas gabungan: induk + semua peserta dihapus bersama (legacy delete :897-925).
        $idInduk = $row['id_parent'] !== null ? (int) $row['id_parent'] : $id;
        $ids     = $this->dinasGabungan($row)
            ? array_merge([$idInduk], array_map('intval', array_column($this->db()->table('absen_ijin')->select('id')->where('id_parent', $idInduk)->where('status !=', self::HAPUS)->get()->getResultArray(), 'id')))
            : [$id];

        $model = new KonketModel($this->db());
        $this->transaksi(function () use ($model, $auth, $ids): void {
            foreach ($ids as $idHapus) {
                $model->withActor($auth, static fn () => $model->update($idHapus, ['status' => self::HAPUS, 'updated_by' => $auth->idPengguna()]));
            }
        });

        return ['deleted' => true, 'id' => $id, 'ids' => $ids];
    }

    public function proses(AuthContext $auth, int $id, string $aksi, ?string $alasan): array
    {
        $this->wajibRole($auth, self::ROLE_ADMIN);
        $row = $this->baris($id);

        if ($row === null) {
            throw new NotFoundException('Data konfirmasi ketidakhadiran tidak ditemukan.');
        }

        if (! $this->scope()->bolehProses($auth, (string) $row['nip'])) {
            throw new ForbiddenException('Pegawai di luar lingkup proses Anda.');
        }

        $errors = [];

        if (! in_array($aksi, ['setujui', 'tolak'], true)) {
            $errors['aksi'] = ['Silakan setujui atau tolak permohonan.'];
        }

        $alasan = trim((string) $alasan);

        if ($aksi === 'tolak' && $alasan === '') {
            $errors['reason_note'] = ['Alasan penolakan wajib diisi.'];
        }

        if ($row['status'] !== self::W) {
            $errors['status'] = ['Data konfirmasi ketidakhadiran ini telah diproses.'];
        }

        if ($row['id_parent'] !== null) {
            $errors['id_parent'] = ['Data peserta dinas gabungan diproses melalui pengajuan induknya.'];
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        $param = [
            'status'      => $aksi === 'setujui' ? self::V : self::X,
            'reason_note' => $alasan === '' ? null : $alasan,
            'updated_by'  => $auth->idPengguna(),
            'approved_by' => $auth->idPengguna(),
            'show_notif'  => 1,
            'notif_date'  => Time::now('UTC')->toDateTimeString(),
        ];

        // Penanda riwayat pemroses (legacy set_param_process :2010-2023).
        if ($auth->role() === Role::SUPER_ADMIN) {
            $param['show_ua_biro'] = 1;
        } elseif ((string) ($auth->idSatker() ?? '') !== '') {
            $param['show_ua_upt'] = 1;
        } else {
            $param['show_ua_deputi'] = 1;
        }

        $ids = [$id];

        if ($this->dinasGabungan($row)) {
            $ids = array_merge($ids, array_map('intval', array_column($this->db()->table('absen_ijin')->select('id')->where('id_parent', $id)->where('status !=', self::HAPUS)->get()->getResultArray(), 'id')));
        }

        $model = new KonketModel($this->db());
        $this->transaksi(function () use ($model, $auth, $ids, $param): void {
            foreach ($ids as $idProses) {
                $model->withActor($auth, static fn () => $model->update($idProses, $param));
            }
        });

        return $this->detail($auth, $id);
    }

    public function berkas(AuthContext $auth, int $id): array
    {
        $row   = $this->barisTerlihat($auth, $id);
        $path  = (string) ($row['file_bukti'] ?? '');
        $kolom = $this->berkas ?? new KolomBerkas(null, $this->db());

        // Path impor legacy (arsip remote) belum ada di storage v2 → 404 sampai arsip dipindahkan saat cutover.
        if ($path === '' || ! $kolom->ada($path)) {
            throw new NotFoundException('Berkas bukti tidak ditemukan.');
        }

        return ['isi' => $kolom->baca($path), 'nama' => "konket_{$id}." . pathinfo($path, PATHINFO_EXTENSION)];
    }

    // ------------------------------------------------------------------

    /**
     * Validasi legacy validate_param() + set_param(); mengembalikan kolom yang ditulis.
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>      $pegawai
     * @param array<string, mixed>|null $lama
     *
     * @return array<string, mixed>
     */
    private function validasi(AuthContext $auth, array $data, ?UploadedFile $berkas, array $pegawai, ?array $lama): array
    {
        $v       = static fn (string $k): string => trim(is_scalar($data[$k] ?? null) ? (string) $data[$k] : '');
        $errors  = [];
        $hariIni = TanggalBisnis::hariIni();
        $mulai   = substr($v('date_start'), 0, 10);
        $akhir   = substr($v('date_end'), 0, 10);

        foreach (['date_start' => [$mulai, 'Tanggal mulai'], 'date_end' => [$akhir, 'Tanggal akhir']] as $kolom => [$nilai, $label]) {
            if ($nilai === '') {
                $errors[$kolom] = ["{$label} tidak boleh kosong."];
            } elseif (! TanggalBisnis::valid($nilai)) {
                $errors[$kolom] = ["{$label} tidak valid."];
            } elseif ($nilai > $hariIni) {
                $errors[$kolom] = ["{$label} harus lebih kecil atau sama dengan tanggal sekarang."];
            }
        }

        if (! isset($errors['date_start']) && ! isset($errors['date_end'])) {
            if ($akhir < $mulai) {
                $errors['date_end'] = ['Tanggal mulai harus lebih kecil atau sama dengan tanggal akhir.'];
            }

            $minimal = KonketAturan::tanggalMinimal($hariIni);

            if ($mulai < $minimal) {
                $errors['date_start'] = ['Tanggal minimal konfirmasi ketidakhadiran adalah ' . TanggalBisnis::formatIndonesia($minimal) . '. Data sebelum tanggal minimal tidak akan diterima oleh sistem.'];
            }
        }

        $kategori = $v('kategori');
        $jenis    = null;

        if ($kategori === '') {
            $errors['kategori'] = ['Silakan pilih jenis ketidakhadiran.'];
        } else {
            $jenis = preg_match('/^[1-9][0-9]{0,9}$/', $kategori) === 1
                ? $this->db()->table('jenis_konket')->where('old_id', (int) $kategori)->where('status', 1)->get()->getRowArray()
                : null;

            if ($jenis === null || in_array((int) $jenis['id_jenis_konket'], $this->jenisTerlarang($auth), true)) {
                $errors['kategori'] = ['Jenis ketidakhadiran tidak tersedia.'];
            }
        }

        if ($v('alasan') === '') {
            $errors['alasan'] = ['Alasan tidak boleh kosong.'];
        }

        if ($berkas === null && ($lama === null || (string) ($lama['file_bukti'] ?? '') === '')) {
            $errors['file_bukti'] = ['File bukti ketidakhadiran tidak boleh kosong.'];
        }

        $jenisDinas = null;

        if ((int) $kategori === self::KATEGORI_DINAS) {
            $jenisDinas = $v('jenis_dinas') === '' ? 0 : (int) $v('jenis_dinas');

            if (! in_array($jenisDinas, [0, 1], true)) {
                $errors['jenis_dinas'] = ['Jenis dinas tidak valid.'];
            }
        }

        $affect = null;

        if ($this->adalahAdmin($auth) && $v('affect_tukin') !== '') {
            $affect = (int) $v('affect_tukin');

            if (! in_array($affect, [1, 2], true)) {
                $errors['affect_tukin'] = ['Pengaruh ke tukin tidak valid.'];
            }
        }

        $nip = (string) ($lama['nip'] ?? $pegawai['nip'] ?? '');

        if (! isset($errors['date_start']) && ! isset($errors['date_end'])) {
            $irisan = $this->db()->table('absen_ijin')->where('nip', $nip)->whereIn('status', [self::W, self::V])
                ->where('date_start <=', "{$akhir} 23:59:59")->where('date_end >=', "{$mulai} 00:00:00");

            if ($lama !== null) {
                $irisan->where('id !=', (int) $lama['id']);
            }

            if ($irisan->countAllResults() > 0) {
                $errors['date_start'] = ['Tanggal konfirmasi ketidakhadiran sudah terdaftar didalam sistem, silahkan gunakan tanggal konfirmasi ketidakhadiran yang berbeda.'];
            } elseif ($lama === null && (int) $kategori === self::KATEGORI_SAKIT && (int) ($pegawai['id_jenis_pegawai'] ?? 0) !== self::JENIS_PEGAWAI_PTT) {
                $errors += $this->periksaSakit($nip, $mulai, $akhir);
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        $param = [
            'date_start'   => "{$mulai} 00:00:00",
            'date_end'     => "{$akhir} 00:00:00",
            'kategori'     => (int) $kategori,
            'jenis_konket' => $jenis['jenis_konket'] ?? null,
            'jenis_dinas'  => $jenisDinas,
            'alasan'       => $v('alasan'),
            'updated_by'   => $auth->idPengguna(),
        ];

        if ($affect !== null) {
            $param['affect_tukin'] = $affect;
        }

        return $param;
    }

    /**
     * Legacy validate_param() :1600-1660: konket sakit selain PTT maksimal 1 hari kerja dan hari kerja sebelumnya bukan
     * konket sakit.
     *
     * @return array<string, list<string>>
     */
    private function periksaSakit(string $nip, string $mulai, string $akhir): array
    {
        $dari  = (new DateTimeImmutable($mulai))->modify('-31 days')->format('Y-m-d');
        $libur = array_fill_keys(service('hariLiburService')->tanggalLibur($dari, $akhir), true);
        $hari  = KonketAturan::hariKerja($mulai, $akhir, $libur);

        if (count($hari) > 1) {
            return ['kategori' => ['Pengajuan izin sakit lebih dari 1 hari harus melalui Cuti Sakit.']];
        }

        if (count($hari) === 1) {
            $sebelum = KonketAturan::hariKerjaSebelum($hari[0], $libur);
            $ada     = $this->db()->table('absen_ijin')->where('nip', $nip)->whereIn('status', [self::W, self::V])
                ->where('kategori', self::KATEGORI_SAKIT)
                ->where('date_start <=', "{$sebelum} 23:59:59")->where('date_end >=', "{$sebelum} 00:00:00")
                ->get()->getRowArray();

            if ($ada !== null) {
                return ['kategori' => ['Pengajuan izin sakit tidak boleh dihari yang berturut-turut. Anda telah memiliki pengajuan izin sakit pada tanggal '
                    . TanggalBisnis::formatIndonesia(substr((string) $ada['date_start'], 0, 10)) . '. Silahkan Pengajuan Melalui Cuti Sakit Online.']];
            }
        }

        return [];
    }

    /**
     * Daftar NIP peserta dinas gabungan (kategori 8, jenis_dinas 1): wajib minimal satu selain pemohon, pegawai aktif.
     *
     * @param array<string, mixed> $param
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function daftarPeserta(AuthContext $auth, array $param, array $data, string $nipPemohon): array
    {
        if ((int) $param['kategori'] !== self::KATEGORI_DINAS || (int) $param['jenis_dinas'] !== 1) {
            return [];
        }

        $mentah = $data['peserta'] ?? [];
        $mentah = is_array($mentah) ? $mentah : ($mentah === '' ? [] : [$mentah]);
        $nip    = [];

        foreach ($mentah as $n) {
            $n = trim(is_scalar($n) ? (string) $n : '');

            if ($n !== '' && $n !== $nipPemohon) {
                $nip[$n] = $n;
            }
        }

        if ($nip === []) {
            throw ValidationException::forField('peserta', 'Silakan pilih peserta dinas gabungan.');
        }

        $aktif = array_column($this->db()->table('pegawai')->select('nip')->whereIn('nip', array_values($nip))->where('status', 1)->get()->getResultArray(), 'nip');

        if (count($aktif) !== count($nip)) {
            throw ValidationException::forField('peserta', 'Peserta tidak ditemukan atau tidak aktif: ' . implode(', ', array_diff(array_values($nip), $aktif)) . '.');
        }

        // M1 review MAKE-013: role 3 hanya boleh memilih peserta dalam lingkupnya (legacy form_ad.php:152-171).
        if ($auth->role() === Role::ADMIN_SATKER) {
            $luar = array_values(array_filter($nip, fn (string $n): bool => ! $this->scope()->bolehUbah($auth, $n)));

            if ($luar !== []) {
                throw ValidationException::forField('peserta', 'Peserta di luar lingkup akses Anda: ' . implode(', ', $luar) . '.');
            }
        }

        return array_values($nip);
    }

    /**
     * Tulis induk (+ berkas) dan selaraskan peserta dalam satu transaksi (legacy insert/update :330-560).
     *
     * @param array<string, mixed>      $param
     * @param list<string>              $peserta
     * @param array<string, mixed>|null $lama
     */
    private function tulis(AuthContext $auth, ?int $id, array $param, ?UploadedFile $berkas, array $peserta, ?array $lama = null): int
    {
        $db    = $this->db();
        $kolom = $this->berkas ?? new KolomBerkas(null, $db);
        $model = new KonketModel($db);
        $db->transBegin();

        try {
            if ($berkas !== null) {
                $param['file_bukti'] = $kolom->simpan($berkas, self::aturanBerkas(), 'absen_ijin', 'file_bukti', 'file_bukti');
                $kolom->hapus($lama['file_bukti'] ?? null, 'absen_ijin', 'file_bukti');
            }

            $id = $model->withActor($auth, static function () use ($model, $id, $param): int {
                if ($id === null) {
                    return (int) $model->insert($param);
                }

                $model->update($id, $param);

                return $id;
            });

            $akhir = (array) $model->find($id);
            $this->selaraskanPeserta($auth, $model, $akhir, $peserta);

            if ($db->transStatus() === false) {
                throw new RuntimeException('Transaksi konket gagal.');
            }

            $db->transCommit();

            return $id;
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        } finally {
            $db->resetTransStatus();
            $kolom->selesaikan();
        }
    }

    /**
     * Baris peserta = salinan isian induk (+ `id_parent`); peserta lama yang tidak dipilih lagi → status 10 [V2].
     *
     * @param array<string, mixed> $induk
     * @param list<string>         $peserta
     */
    private function selaraskanPeserta(AuthContext $auth, KonketModel $model, array $induk, array $peserta): void
    {
        $lama = [];

        foreach ($this->db()->table('absen_ijin')->where('id_parent', (int) $induk['id'])->where('status !=', self::HAPUS)->get()->getResultArray() as $r) {
            $lama[(string) $r['nip']] = (int) $r['id'];
        }

        $salinan = array_intersect_key($induk, array_flip([
            'date_start', 'date_end', 'kategori', 'jenis_konket', 'jenis_dinas', 'affect_tukin', 'alasan', 'file_bukti', 'status', 'updated_by',
        ])) + ['id_parent' => (int) $induk['id']];

        foreach ($peserta as $nip) {
            $baris = $salinan + ['nip' => $nip];
            $model->withActor($auth, static fn () => isset($lama[$nip]) ? $model->update($lama[$nip], $baris) : $model->insert($baris));
            unset($lama[$nip]);
        }

        foreach ($lama as $idLama) {
            $model->withActor($auth, static fn () => $model->update($idLama, ['status' => self::HAPUS, 'updated_by' => $auth->idPengguna()]));
        }
    }

    /**
     * @param callable(): void $kerja
     */
    private function transaksi(callable $kerja): void
    {
        $db = $this->db();
        $db->transBegin();

        try {
            $kerja();

            if ($db->transStatus() === false) {
                throw new RuntimeException('Transaksi konket gagal.');
            }

            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        } finally {
            $db->resetTransStatus();
        }
    }

    /**
     * Baris (status ≠ 10) yang boleh dilihat pemanggil; tidak terlihat → 404 (tanpa membocorkan keberadaan).
     *
     * @return array<string, mixed>
     */
    private function barisTerlihat(AuthContext $auth, int $id): array
    {
        $this->wajibRole($auth, self::ROLE_LIHAT);
        $row = $this->baris($id);

        $boleh = $row !== null && ($this->adalahAdmin($auth) || $this->pengamat($auth)
            ? $this->scope()->bolehLihat($auth, (string) $row['nip'])
            : (string) $row['nip'] === (string) $auth->nip());

        if (! $boleh) {
            throw new NotFoundException('Data konfirmasi ketidakhadiran tidak ditemukan.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function baris(int $id): ?array
    {
        return $this->query()->where('a.id', $id)->get()->getRowArray();
    }

    private function query(): BaseBuilder
    {
        return $this->db()->table('absen_ijin a')
            ->select('a.*, p.glr_awal, p.nama, p.glr_akhir, pmj.unit, pmj.satker, pmj.sub_group_jabatan')
            ->join('pegawai p', 'p.nip = a.nip', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = a.nip', 'left')
            ->where('a.status !=', self::HAPUS);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{ubah: bool, hapus: bool, proses: bool}
     */
    private function aksi(AuthContext $auth, array $row): array
    {
        $nip   = (string) $row['nip'];
        $induk = $row['id_parent'] === null;

        if ($this->pengamat($auth)) {
            return ['ubah' => false, 'hapus' => false, 'proses' => false];
        }

        if (! $this->adalahAdmin($auth)) {
            $milik = $nip === (string) $auth->nip();

            return ['ubah' => $milik && $induk && $row['status'] === self::W, 'hapus' => $milik, 'proses' => false];
        }

        $ubah = $this->scope()->bolehUbah($auth, $nip);

        return [
            'ubah'   => $ubah && $induk,
            'hapus'  => $ubah,
            'proses' => $induk && $row['status'] === self::W && $this->scope()->bolehProses($auth, $nip),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array{nip: string, nama_lengkap: string, pemohon: bool}>
     */
    private function peserta(array $row): array
    {
        if (! $this->dinasGabungan($row)) {
            return [];
        }

        $idInduk = $row['id_parent'] !== null ? (int) $row['id_parent'] : (int) $row['id'];
        $induk   = $this->baris($idInduk);
        $hasil   = [];

        if ($induk !== null) {
            $hasil[] = ['nip' => (string) $induk['nip'], 'nama_lengkap' => $this->nama($induk), 'pemohon' => true];
        }

        foreach ($this->query()->where('a.id_parent', $idInduk)->orderBy('a.id', 'ASC')->get()->getResultArray() as $r) {
            $hasil[] = ['nip' => (string) $r['nip'], 'nama_lengkap' => $this->nama($r), 'pemohon' => false];
        }

        return $hasil;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function dinasGabungan(array $row): bool
    {
        return (int) $row['kategori'] === self::KATEGORI_DINAS && (int) ($row['jenis_dinas'] ?? 0) === 1;
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function bentuk(array $r): array
    {
        $r['id']           = (int) $r['id'];
        $r['id_parent']    = $r['id_parent'] === null ? null : (int) $r['id_parent'];
        $r['kategori']     = (int) $r['kategori'];
        $r['affect_tukin'] = (int) $r['affect_tukin'];
        $r['jenis_dinas']  = $r['jenis_dinas'] === null ? null : (int) $r['jenis_dinas'];
        $r['date_start']   = substr((string) $r['date_start'], 0, 10);
        $r['date_end']     = substr((string) $r['date_end'], 0, 10);
        $r['nama_lengkap'] = $this->nama($r);
        $r['ada_berkas']   = (string) ($r['file_bukti'] ?? '') !== '';

        foreach (['file_bukti', 'file_bukti_2', 'file_bukti_3', 'file_bukti_4', 'file_bukti_5', 'glr_awal', 'glr_akhir'] as $k) {
            unset($r[$k]);
        }

        return $r;
    }

    /**
     * @param array<string, mixed> $r
     */
    private function nama(array $r): string
    {
        return ($r['nama'] ?? null) === null ? '' : StrukturService::namaGelar($r['glr_awal'] ?? null, (string) $r['nama'], $r['glr_akhir'] ?? null);
    }

    /**
     * Rentang list_ad: `ds`/`de` (Y-m-d); bawaan awal–akhir bulan berjalan (WIB).
     *
     * @param array<string, mixed> $filter
     *
     * @return array{0: string, 1: string}
     */
    private function rentang(array $filter): array
    {
        $hariIni = TanggalBisnis::hariIni();
        $ds      = is_string($filter['ds'] ?? null) && TanggalBisnis::valid($filter['ds']) ? $filter['ds'] : TanggalBisnis::awalBulan($hariIni);
        $de      = is_string($filter['de'] ?? null) && TanggalBisnis::valid($filter['de']) ? $filter['de'] : (new DateTimeImmutable($hariIni))->format('Y-m-t');

        return $de < $ds ? [$de, $ds] : [$ds, $de];
    }

    /**
     * id_jenis_konket yang tidak boleh dipilih role pemanggil (legacy form/form_ad).
     *
     * @return list<int>
     */
    private function jenisTerlarang(AuthContext $auth): array
    {
        return $auth->role() === Role::SUPER_ADMIN ? [4, 5] : [2, 4, 5, 6];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pegawai(string $nip): ?array
    {
        return $this->db()->table('pegawai')->select('nip, id_jenis_pegawai, status')->where('nip', $nip)->get()->getRowArray();
    }

    private function pengamat(AuthContext $auth): bool
    {
        return in_array($auth->role(), self::ROLE_PENGAMAT, true);
    }

    private function adalahAdmin(AuthContext $auth): bool
    {
        return in_array($auth->role(), self::ROLE_ADMIN, true);
    }

    /**
     * @param list<int> $role
     */
    private function wajibRole(AuthContext $auth, array $role): void
    {
        if (! in_array($auth->role(), $role, true)) {
            throw new ForbiddenException('Anda tidak berhak mengakses konfirmasi ketidakhadiran.');
        }
    }

    private function scope(): PegawaiScopeInterface
    {
        return $this->scope ?? service('pegawaiScope');
    }

    private function db(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
