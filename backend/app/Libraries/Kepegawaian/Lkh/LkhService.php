<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lkh;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\LkhServiceInterface;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Libraries\Pdf\DokumenPdf;
use App\Libraries\Pdf\Pdf;
use App\Models\Kepegawaian\LkhModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use DateTimeImmutable;

/**
 * B-12b Laporan Kerja Harian (WS-2 M5 MAKE-013) — service di luar engine, tabel `riwayat_lckh`.
 *
 * Sumber [K]: `controllers/hr/rwy/Lkh.php`, `libraries/hr/rwy/L_lkh.php` (list :113, detail+PDF :194, insert :287,
 * update :457, delete :655, lkh_min_date :699, vp :783, sp :898, process :986), helper getAL()
 * (`helpers/function_helper.php:1479`), template `views/hr/employee/rwy/lkh/print/lkh_pdf.php`.
 *
 * Hak akses: Matriks v2 `hr/rwy/lkh/*` = 1, 2, 3, 6, 7. Pegawai mengelola LKH sendiri; role 1 (semua) dan role 3
 * (PegawaiScope) untuk pegawai lain (legacy add/edit/delete/index dengan NIP). Verifikasi = ATASAN LANGSUNG
 * (`nip_atasan` = NIP pemanggil; pengecualian keputusan #4, ikut legacy) — [V2] tidak dibatasi role 2 seperti legacy
 * userAuth(['2']) karena atasan bisa memegang akun role lain.
 *
 * Aturan legacy: tanggal laporan dalam [LkhAturan::tanggalMinimal, hari ini] dan unik per pegawai (status selain
 * 2/10); minimal satu kegiatan lengkap (kegiatan, output, jumlah, jam mulai, jam selesai) disimpan sebagai array JSON
 * per kolom; atasan langsung & unit/satker/nama diisi server (sp(), getAL()); ubah/hapus terkunci setelah masuk
 * perhitungan tukin (LkhAturan::batasUbah/batasHapus); pegawai tidak bisa mengubah/menghapus LKH Disetujui; proses
 * hanya status 0 (status 3 = Revisi menunggu perbaikan pegawai; ubah oleh pegawai → kembali 0).
 *
 * [V2]: tanpa pengecualian akun pengembang (USR_SADEV/USR_SPC); baris kegiatan setengah terisi ditolak 422 (legacy
 * membuangnya diam-diam); jam HH:MM; catatan wajib saat Tolak/Revisi; hapus = status 10; PDF dibangkitkan saat diminta
 * (legacy menyimpan berkas `file_lckh` saat disetujui) — QR = kode LKH legacy; kop tanpa logo (aset lokal menyusul).
 */
final class LkhService implements LkhServiceInterface
{
    public const MENUNGGU  = 0;
    public const DISETUJUI = 1;
    public const DITOLAK   = 2;
    public const REVISI    = 3;
    public const DIHAPUS   = 10;

    /** @var list<int> */
    public const ROLE_LKH = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK];

    /** @var list<string> kolom kegiatan (array JSON sejajar) */
    public const KOLOM_KEGIATAN = ['kegiatan', 'output', 'jumlah_diselesaikan', 'jam_mulai', 'jam_selesai'];

    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly ?PegawaiScopeInterface $scope = null,
        private readonly ?AtasanLangsung $atasan = null,
    ) {
    }

    public function daftar(AuthContext $auth, array $filter = []): array
    {
        $this->wajibRole($auth);
        $nip              = $this->nipSasaran($auth, $filter['nip'] ?? null, false);
        [$page, $perPage] = $this->halaman($filter);

        $builder = $this->db()->table('riwayat_lckh')->where('nip', $nip)->whereNotIn('status', [self::DITOLAK, self::DIHAPUS]);
        $total   = $builder->countAllResults(false);
        // Legacy list(): Revisi lalu Menunggu (tanggal naik), lalu Disetujui (tanggal turun).
        $rows = $builder
            ->orderBy('CASE status WHEN 3 THEN 0 WHEN 0 THEN 1 ELSE 2 END', 'ASC', false)
            ->orderBy('CASE WHEN status = 1 THEN NULL ELSE tgl_laporan END', 'ASC', false)
            ->orderBy('tgl_laporan', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'nip'        => $nip,
            'items'      => array_map(fn (array $r): array => $this->bentuk($r, $auth), $rows),
            'meta'       => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
            'can_create' => true,
        ];
    }

    public function verifikasi(AuthContext $auth, array $filter = []): array
    {
        $this->wajibRole($auth);
        $nipSaya          = (string) $auth->nip();
        [$page, $perPage] = $this->halaman($filter);

        $builder = $this->db()->table('riwayat_lckh')->where('nip_atasan', $nipSaya === '' ? '-' : $nipSaya)->where('status !=', self::DIHAPUS);
        $status  = (string) ($filter['status'] ?? (string) self::MENUNGGU);

        if (in_array($status, ['0', '1', '2', '3'], true)) {
            $builder->where('status', (int) $status);
        }

        foreach (['ds' => '>=', 'de' => '<='] as $k => $op) {
            if (is_string($filter[$k] ?? null) && TanggalBisnis::valid($filter[$k])) {
                $builder->where("tgl_laporan {$op}", $filter[$k]);
            }
        }

        $search = trim((string) ($filter['search'] ?? ''));

        if ($search !== '') {
            $builder->groupStart()->like('nama', $search)->orLike('nip', $search)->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('tgl_laporan', 'ASC')->orderBy('id_riwayat_lckh', 'ASC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'items' => array_map(fn (array $r): array => $this->bentuk($r, $auth), $rows),
            'meta'  => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    public function opsi(AuthContext $auth, ?string $nip = null): array
    {
        $this->wajibRole($auth);
        $nip     = $this->nipSasaran($auth, $nip, true);
        $hariIni = TanggalBisnis::hariIni();

        return [
            'nip'              => $nip,
            'tanggal_minimal'  => $this->tanggalMinimal($hariIni),
            'tanggal_maksimal' => $hariIni,
            'atasan'           => $this->atasan()->untuk($nip),
            'punya_jabatan'    => $this->db()->table('pegawai_mutasi_jabatan')->where('nip', $nip)->countAllResults() > 0,
        ];
    }

    public function detail(AuthContext $auth, int $id): array
    {
        return $this->bentuk($this->barisTerlihat($auth, $id), $auth, true);
    }

    public function tambah(AuthContext $auth, array $data): array
    {
        $this->wajibRole($auth);
        $nip     = $this->nipSasaran($auth, $data['nip'] ?? null, true);
        $tanggal = $this->validasiTanggal($data, $nip, null);
        $aktif   = $this->validasiKegiatan($data);
        $pegawai = $this->db()->table('pegawai p')
            ->select('p.glr_awal, p.nama, p.glr_akhir, pmj.id_unit, pmj.id_satker, pmj.unit, pmj.satker, pmj.nip AS ada_jabatan')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = p.nip', 'left')
            ->where('p.nip', $nip)->get()->getRowArray();

        if ($pegawai === null || $pegawai['ada_jabatan'] === null) {
            throw ValidationException::forField('nip', 'Data unit dan satker pegawai tidak terdaftar didalam sistem, silahkan periksa riwayat jabatan anda.');
        }

        $atasan = $this->atasan()->untuk($nip);

        if ($atasan === null || $atasan['nip'] === '') {
            throw ValidationException::forField('nip_atasan', 'Atasan langsung tidak ditemukan. Periksa riwayat jabatan (atasan) pegawai.');
        }

        $model = new LkhModel($this->db());
        $id    = (int) $model->withActor($auth, static fn () => $model->insert([
            'nip'         => $nip,
            'tgl_laporan' => $tanggal,
            'nama'        => StrukturService::namaGelar($pegawai['glr_awal'], (string) $pegawai['nama'], $pegawai['glr_akhir']),
            'id_unit'     => $pegawai['id_unit'],
            'id_satker'   => $pegawai['id_satker'],
            'unit'        => $pegawai['unit'],
            'satker'      => $pegawai['satker'],
            'nip_atasan'  => $atasan['nip'],
            'nama_atasan' => $atasan['nama'],
            'status'      => self::MENUNGGU,
            'updated_by'  => $auth->idPengguna(),
        ] + $aktif));

        return $this->detail($auth, $id);
    }

    public function ubah(AuthContext $auth, int $id, array $data): array
    {
        $lama = $this->barisTerlihat($auth, $id);
        $this->wajibPengelola($auth, $lama);
        $this->wajibTerbuka($auth, $lama, LkhAturan::batasUbah(TanggalBisnis::hariIni()), [self::MENUNGGU, self::DISETUJUI, self::REVISI], 'merubah');

        $param = $this->validasiKegiatan($data) + ['updated_by' => $auth->idPengguna()];

        if (array_key_exists('tgl_laporan', $data)) {
            $param['tgl_laporan'] = $this->validasiTanggal($data, (string) $lama['nip'], $lama);
        }

        // Legacy edit(): selain role 1 status kembali 0 (revisi dikirim ulang ke atasan).
        if ($auth->role() !== Role::SUPER_ADMIN) {
            $param['status'] = self::MENUNGGU;
        }

        $model = new LkhModel($this->db());
        $model->withActor($auth, static fn () => $model->update($id, $param));

        return $this->detail($auth, $id);
    }

    public function hapus(AuthContext $auth, int $id): array
    {
        $row = $this->barisTerlihat($auth, $id);
        $this->wajibPengelola($auth, $row);
        $this->wajibTerbuka($auth, $row, LkhAturan::batasHapus(TanggalBisnis::hariIni()), [self::MENUNGGU, self::DISETUJUI], 'menghapus', true);

        $model = new LkhModel($this->db());
        $model->withActor($auth, static fn () => $model->update($id, ['status' => self::DIHAPUS, 'updated_by' => $auth->idPengguna()]));

        return ['deleted' => true, 'id' => $id];
    }

    public function proses(AuthContext $auth, int $id, string $aksi, ?string $catatan): array
    {
        $this->wajibRole($auth);
        $row = $this->baris($id);

        if ($row === null || (string) $row['nip_atasan'] !== (string) $auth->nip() || (string) $auth->nip() === '') {
            throw new NotFoundException('Laporan kerja harian tidak ditemukan.');
        }

        $status  = ['setujui' => self::DISETUJUI, 'tolak' => self::DITOLAK, 'revisi' => self::REVISI][$aksi] ?? null;
        $catatan = trim((string) $catatan);
        $errors  = [];

        if ($status === null) {
            $errors['aksi'] = ['Silakan setujui, tolak, atau minta revisi laporan.'];
        } elseif ($status !== self::DISETUJUI && $catatan === '') {
            $errors['catatan'] = ['Catatan wajib diisi saat menolak atau meminta revisi.'];
        }

        if ((int) $row['status'] !== self::MENUNGGU) {
            $errors['status'] = [(int) $row['status'] === self::REVISI ? 'Laporan sedang menunggu revisi pegawai.' : 'Laporan kerja harian ini telah diproses.'];
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        $model = new LkhModel($this->db());
        $model->withActor($auth, static fn () => $model->update($id, [
            'status'      => $status,
            'catatan'     => $catatan === '' ? null : $catatan,
            'approved_by' => $auth->idPengguna(),
            'updated_by'  => $auth->idPengguna(),
            'show_notif'  => 1,
        ]));

        return $this->detail($auth, $id);
    }

    public function pdf(AuthContext $auth, int $id): array
    {
        $row = $this->barisTerlihat($auth, $id);
        $wc  = $this->webConfig();
        $uns = $this->db()->table('unit')->select('unit, alamat_pdf_header')->where('id_unit', $row['id_unit'])->get()->getRowArray();

        $kop = '<table width="100%" cellpadding="0"><tr><td style="text-align:center;font-size:12pt;"><b>'
            . esc(mb_strtoupper((string) ($wc['pdf_header_nama_kementerian'] ?? $wc['nama_kementerian'] ?? ''))) . '</b></td></tr>'
            . '<tr><td style="text-align:center;font-size:9pt;"><b>' . esc(mb_strtoupper((string) ($row['unit'] ?? ''))) . '</b></td></tr>'
            . '<tr><td style="text-align:center;font-size:8pt;">' . nl2br(esc((string) ($uns['alamat_pdf_header'] ?? $wc['pdf_header_alamat_kementerian'] ?? ''))) . '</td></tr></table>'
            . '<hr style="height:2px;" />';

        $kunci = $row['id_riwayat_lckh'] . date('y', (int) strtotime((string) $row['tgl_laporan'])) . '0' . strtotime((string) $row['created_at']);
        $html  = view('pdf/lkh', ['lkh' => $this->bentuk($row, $auth, true), 'qr' => Pdf::qrImg($kunci, 100)]);
        $judul = 'Laporan Kerja Harian ' . $row['nama'] . ' - ' . TanggalBisnis::formatIndonesia((string) $row['tgl_laporan']);
        $isi   = Pdf::renderer()->render(new DokumenPdf(
            $judul,
            $html,
            kopHtml: $kop,
            kakiHtml: 'SIMPEG v2, ' . Time::now('Asia/Jakarta')->format('d-m-Y H:i'),
            marginAtasKop: 42,
        ));

        return ['isi' => $isi, 'nama' => 'lkh_' . $row['nip'] . '_' . str_replace('-', '', (string) $row['tgl_laporan']) . '.pdf'];
    }

    // ------------------------------------------------------------------

    /**
     * NIP yang dikelola pemanggil: pegawai → selalu NIP sendiri (NIP lain → 403); role 1 → NIP apa pun yang ada;
     * role 3 → dalam lingkup PegawaiScope. Kosong → NIP sendiri.
     */
    private function nipSasaran(AuthContext $auth, mixed $nip, bool $tulis): string
    {
        $sendiri = (string) $auth->nip();
        $nip     = is_scalar($nip) ? trim((string) $nip) : '';

        if ($nip === '' || $nip === $sendiri) {
            if ($sendiri === '') {
                throw ValidationException::forField('nip', 'Silakan pilih pegawai.');
            }

            return $sendiri;
        }

        if (! in_array($auth->role(), [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true)) {
            throw new ForbiddenException('Anda hanya dapat mengelola laporan kerja harian sendiri.');
        }

        if ($this->db()->table('pegawai')->where('nip', $nip)->countAllResults() === 0) {
            throw new NotFoundException('Pegawai tidak ditemukan.');
        }

        $boleh = $tulis ? $this->scope()->bolehUbah($auth, $nip) : $this->scope()->bolehLihat($auth, $nip);

        if (! $boleh) {
            throw new ForbiddenException('Pegawai di luar lingkup akses Anda.');
        }

        return $nip;
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $lama
     */
    private function validasiTanggal(array $data, string $nip, ?array $lama): string
    {
        $tanggal = is_scalar($data['tgl_laporan'] ?? null) ? trim((string) $data['tgl_laporan']) : '';

        if ($tanggal === '') {
            throw ValidationException::forField('tgl_laporan', 'Tanggal laporan tidak boleh kosong.');
        }

        if (! TanggalBisnis::valid($tanggal)) {
            throw ValidationException::forField('tgl_laporan', 'Tanggal laporan tidak valid.');
        }

        $hariIni = TanggalBisnis::hariIni();
        $minimal = $this->tanggalMinimal($hariIni);

        if ($tanggal < $minimal || $tanggal > $hariIni) {
            throw ValidationException::forField('tgl_laporan', 'Periode tanggal laporan LKH yang diterima sistem adalah ' . TanggalBisnis::formatIndonesia($minimal)
                . ' s/d ' . TanggalBisnis::formatIndonesia($hariIni) . '. Silahkan gunakan tanggal laporan yang valid.');
        }

        $ada = $this->db()->table('riwayat_lckh')->where('nip', $nip)->where('tgl_laporan', $tanggal)->whereNotIn('status', [self::DITOLAK, self::DIHAPUS]);

        if ($lama !== null) {
            $ada->where('id_riwayat_lckh !=', (int) $lama['id_riwayat_lckh']);
        }

        if ($ada->countAllResults() > 0) {
            throw ValidationException::forField('tgl_laporan', 'Laporan kerja harian dengan tanggal ' . TanggalBisnis::formatIndonesia($tanggal)
                . ' sudah terdaftar pada riwayat LKH pegawai. Silahkan gunakan tanggal laporan yang berbeda.');
        }

        return $tanggal;
    }

    /**
     * `kegiatan` = list baris `{ kegiatan, output, jumlah_diselesaikan, jam_mulai, jam_selesai }`; baris kosong
     * dilewati, baris setengah terisi → 422 `kegiatan.<i>`; minimal satu → kolom JSON sejajar legacy (sp()).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function validasiKegiatan(array $data): array
    {
        $daftar = $data['kegiatan'] ?? [];

        if (is_string($daftar)) {
            $daftar = json_decode($daftar, true) ?? [];
        }

        $kolom  = array_fill_keys(self::KOLOM_KEGIATAN, []);
        $errors = [];

        foreach (is_array($daftar) ? array_values($daftar) : [] as $i => $baris) {
            $baris = is_array($baris) ? $baris : [];
            $isi   = [];

            foreach (self::KOLOM_KEGIATAN as $k) {
                $isi[$k] = trim(is_scalar($baris[$k] ?? null) ? (string) $baris[$k] : '');
            }

            if (implode('', $isi) === '') {
                continue;
            }

            if (in_array('', $isi, true)) {
                $errors["kegiatan.{$i}"] = ['Lengkapi kegiatan, output, jumlah diselesaikan, jam mulai, dan jam selesai.'];

                continue;
            }

            foreach (['jam_mulai', 'jam_selesai'] as $jam) {
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $isi[$jam]) !== 1) {
                    $errors["kegiatan.{$i}"] = ['Jam harus berformat JJ:MM.'];
                }
            }

            foreach ($isi as $k => $v) {
                $kolom[$k][] = $v;
            }
        }

        if ($errors === [] && $kolom['kegiatan'] === []) {
            $errors['kegiatan'] = ['Silahkan isi minimal satu kegiatan.'];
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        return array_map(static fn (array $v): string => (string) json_encode($v, JSON_UNESCAPED_UNICODE), $kolom);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function wajibPengelola(AuthContext $auth, array $row): void
    {
        $milik = (string) $row['nip'] === (string) $auth->nip();
        $admin = in_array($auth->role(), [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true) && ($auth->role() === Role::SUPER_ADMIN || $this->scope()->bolehUbah($auth, (string) $row['nip']));

        if (! $milik && ! $admin) {
            throw new ForbiddenException('Anda tidak dapat mengelola laporan kerja harian ini.');
        }

        if ($milik && ! $admin && (int) $row['status'] === self::DISETUJUI) {
            throw new ForbiddenException('Laporan kerja harian yang sudah disetujui tidak dapat diubah atau dihapus.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<int>            $status
     */
    private function wajibTerbuka(AuthContext $auth, array $row, string $batas, array $status, string $kata, bool $termasukBatas = false): void
    {
        $tanggal = (string) $row['tgl_laporan'];
        $kunci   = $termasukBatas ? $tanggal <= $batas : $tanggal < $batas;

        if ($kunci && in_array((int) $row['status'], $status, true)) {
            throw ValidationException::forField('tgl_laporan', "Tidak dapat {$kata} data LKH yang telah masuk kedalam perhitungan Tukin bulan sebelumnya.");
        }
    }

    /**
     * Baris (status ≠ 10) yang boleh dilihat: pemilik, atasan langsung, role 1, role 3 dalam lingkup. Selain itu 404.
     *
     * @return array<string, mixed>
     */
    private function barisTerlihat(AuthContext $auth, int $id): array
    {
        $this->wajibRole($auth);
        $row  = $this->baris($id);
        $saya = (string) $auth->nip();

        $boleh = $row !== null && (
            ($saya !== '' && ((string) $row['nip'] === $saya || (string) $row['nip_atasan'] === $saya))
            || $auth->role() === Role::SUPER_ADMIN
            || ($auth->role() === Role::ADMIN_SATKER && $this->scope()->bolehLihat($auth, (string) $row['nip']))
        );

        if (! $boleh) {
            throw new NotFoundException('Laporan kerja harian tidak ditemukan.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function baris(int $id): ?array
    {
        return $this->db()->table('riwayat_lckh')->where('id_riwayat_lckh', $id)->where('status !=', self::DIHAPUS)->get()->getRowArray();
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function bentuk(array $r, AuthContext $auth, bool $lengkap = false): array
    {
        $kolom = [];

        foreach (self::KOLOM_KEGIATAN as $k) {
            $kolom[$k] = json_decode((string) ($r[$k] ?? '[]'), true);
            $kolom[$k] = is_array($kolom[$k]) ? array_values($kolom[$k]) : [];
        }

        $kegiatan = [];

        foreach ($kolom['kegiatan'] as $i => $teks) {
            $kegiatan[] = ['kegiatan' => (string) $teks] + array_combine(
                array_slice(self::KOLOM_KEGIATAN, 1),
                array_map(static fn (string $k): string => (string) ($kolom[$k][$i] ?? ''), array_slice(self::KOLOM_KEGIATAN, 1)),
            );
        }

        $saya    = (string) $auth->nip();
        $milik   = $saya !== '' && (string) $r['nip'] === $saya;
        $hariIni = TanggalBisnis::hariIni();
        $status  = (int) $r['status'];
        $admin   = $auth->role() === Role::SUPER_ADMIN || ($auth->role() === Role::ADMIN_SATKER && ! $milik);

        $hasil = [
            'id'          => (int) $r['id_riwayat_lckh'],
            'nip'         => (string) $r['nip'],
            'nama'        => (string) $r['nama'],
            'unit'        => $r['unit'],
            'satker'      => $r['satker'],
            'tgl_laporan' => (string) $r['tgl_laporan'],
            'nip_atasan'  => $r['nip_atasan'],
            'nama_atasan' => (string) $r['nama_atasan'],
            'status'      => $status,
            'catatan'     => $r['catatan'],
            'jumlah'      => count($kegiatan),
            'aksi'        => [
                'ubah'   => ($milik && $status !== self::DISETUJUI || $admin) && ! ($r['tgl_laporan'] < LkhAturan::batasUbah($hariIni) && in_array($status, [0, 1, 3], true)),
                'hapus'  => ($milik && $status !== self::DISETUJUI || $admin) && ! ($r['tgl_laporan'] <= LkhAturan::batasHapus($hariIni) && in_array($status, [0, 1], true)),
                'proses' => $saya !== '' && (string) $r['nip_atasan'] === $saya && $status === self::MENUNGGU,
            ],
        ];

        if ($lengkap) {
            $hasil['kegiatan'] = $kegiatan;
        }

        return $hasil;
    }

    private function tanggalMinimal(string $hariIni): string
    {
        $dari  = (new DateTimeImmutable($hariIni))->modify('-2 month')->format('Y-m-d');
        $libur = array_fill_keys(service('hariLiburService')->tanggalLibur($dari, $hariIni), true);

        return LkhAturan::tanggalMinimal($hariIni, $libur);
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return array{0: int, 1: int}
     */
    private function halaman(array $filter): array
    {
        return [max(1, (int) ($filter['page'] ?? 1)), min(100, max(1, (int) ($filter['per_page'] ?? 20)))];
    }

    /**
     * @return array<string, string>
     */
    private function webConfig(): array
    {
        $db  = $this->db();
        $ada = $db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$db->prefixTable('web_config')],
        )->getRowArray() !== null;

        if (! $ada) {
            return [];
        }

        $hasil = [];

        foreach ($db->table('web_config')->select('config_name, config_value')->get()->getResultArray() as $row) {
            $hasil[(string) $row['config_name']] = (string) ($row['config_value'] ?? '');
        }

        return $hasil;
    }

    private function wajibRole(AuthContext $auth): void
    {
        if (! in_array($auth->role(), self::ROLE_LKH, true)) {
            throw new ForbiddenException('Anda tidak berhak mengakses laporan kerja harian.');
        }
    }

    private function atasan(): AtasanLangsung
    {
        return $this->atasan ?? new AtasanLangsung($this->db());
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
