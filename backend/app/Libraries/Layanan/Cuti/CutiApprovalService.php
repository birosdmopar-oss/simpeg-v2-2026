<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Models\AuditLogModel;
use App\Models\Layanan\RiwayatCutiModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Persetujuan berjenjang cuti (PRD Fase 4 v1.3 §6.4, C-04): `process` (T4–T10, T14), `tunda` (T11), pengingat.
 *
 * Urutan pemeriksaan `process` (§6.4.3): route role (filter) → baris ada dan terlihat (404) → pemanggil terkait approver
 * baris itu, atau role 1 dengan `atas_nama` (403) → validasi body (422) → transaksi: kunci `pegawai` pemohon `FOR UPDATE`,
 * baca ulang baris terkunci → cek tahap (422) → kuota diulang (422) → tulis → efek final → commit. Keputusan tahap
 * memakai {@see TransisiCuti::proses()} (satu sumber state machine). Efek final hanya ke status 1/4
 * ({@see EfekFinalCutiService}). Notifikasi (§6.6.2) lewat {@see CutiNotifikasiService} bila terpasang (M4, MAKE-023).
 */
class CutiApprovalService
{
    public const PESAN_MASIH_TERJANGKAU = 'Pejabat tahap ini masih dapat memproses permohonan.';

    public const PESAN_ALASAN_ATAS_NAMA = 'Alasan tindakan atas nama wajib diisi.';

    public const JENIS_AUDIT_ATAS_NAMA = 'cuti.process_atas_nama';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly AturanCuti $aturan,
        private readonly CutiService $cuti,
        private readonly KuotaCutiService $kuota,
        private readonly EfekFinalCutiService $efek,
        private readonly ApproverTerjangkau $terjangkau,
        private readonly ?CutiNotifikasiService $notifikasi = null,
    ) {
    }

    /**
     * @param array<string, mixed> $in `{aksi, reason_note?, atas_nama?}`
     *
     * @return array<string, mixed> item cuti terbaru
     */
    public function process(AuthContext $auth, int $id, array $in): array
    {
        $cuti = $this->cuti->ambil($id) ?? throw new NotFoundException();

        if (! $this->cuti->bolehLihat($auth, $cuti)) {
            throw new NotFoundException();
        }

        $role     = (int) $auth->role();
        $nip      = $auth->nip();
        $atasNama = ($in['atas_nama'] ?? false) === true || ($in['atas_nama'] ?? null) === 'true' || ($in['atas_nama'] ?? null) === '1' || ($in['atas_nama'] ?? null) === 1;

        if ($atasNama && $role !== Role::SUPER_ADMIN) {
            throw new ForbiddenException('Hanya Super Admin yang dapat memproses atas nama.');
        }

        if (! $atasNama && ! $this->terkaitApprover($cuti, $nip)) {
            throw new ForbiddenException('Anda bukan pejabat yang memproses permohonan ini.');
        }

        $aksi   = is_string($in['aksi'] ?? null) ? trim($in['aksi']) : '';
        $reason = $this->teks($in['reason_note'] ?? null);

        if ($atasNama && ($reason === null || trim($reason) === '') && in_array($aksi, TransisiCuti::AKSI, true)) {
            throw new ValidationException('Validasi gagal.', ['reason_note' => [self::PESAN_ALASAN_ATAS_NAMA]]);
        }

        $idNotif = [];

        $this->cuti->dalamTransaksi(function () use ($auth, $id, $aksi, $reason, $atasNama, $nip, &$idNotif): void {
            $awal = $this->cuti->ambil($id) ?? throw new NotFoundException();
            $this->cuti->kunciPegawai((string) $awal['nip']);
            $cuti = $this->cuti->ambil($id, true) ?? throw new NotFoundException();

            if ((string) $cuti['nip'] !== (string) $awal['nip']) {
                // NIP pemohon berubah antara baca awal dan lock: kunci pemohon yang benar, lalu baca ulang.
                $this->cuti->kunciPegawai((string) $cuti['nip']);
                $cuti = $this->cuti->ambil($id, true) ?? throw new NotFoundException();
            }

            $status     = (int) $cuti['status'];
            $nipAl      = $this->plain($cuti['nip_atasan_langsung'] ?? null);
            $nipPyb     = $this->plain($cuti['nip_yang_menyetujui'] ?? null);
            $tunggal    = TransisiCuti::langkahTunggal($nipAl, $nipPyb);
            $tahapAktif = TransisiCuti::tahapAktif($status, $tunggal);
            $pelaku     = TransisiCuti::nipPelaku($status, $nipAl, $nipPyb);

            $alasanTak = null;

            if ($atasNama) {
                $alasanTak = $this->terjangkau->alasanTakTerjangkau($pelaku);

                if ($tahapAktif !== null && $alasanTak === null) {
                    throw ValidationException::forField('status', self::PESAN_MASIH_TERJANGKAU);
                }

                $tahapPemanggil = $tahapAktif ?? TransisiCuti::TAHAP_PYB;
            } else {
                $tahapPemanggil = ($pelaku !== null && $pelaku !== '' && $pelaku === $nip)
                    ? ($tahapAktif ?? TransisiCuti::TAHAP_PYB)
                    : ($nip === $nipPyb ? TransisiCuti::TAHAP_PYB : TransisiCuti::TAHAP_AL);
            }

            $hasil = TransisiCuti::proses($status, $tunggal, $tahapPemanggil, $aksi, (int) $cuti['id_jenis_cuti'], $reason);

            if (! $hasil->valid) {
                throw ValidationException::forField((string) $hasil->kode, (string) $hasil->pesan);
            }

            /** @var array{status: int, status_al: int|null, status_pyb: int|null, final: bool, efek_final: bool, tahap: string, flag_tampil: string, reason_note: string|null} $d */
            $d = $hasil->detail;

            if ($d['efek_final'] && (int) $cuti['id_jenis_cuti'] === AturanCuti::JENIS_TAHUNAN) {
                $this->ulangiKuota($cuti);
            }

            $data           = $this->kolomTulis($cuti, $d, $aksi, $atasNama, $auth);
            $data['status'] = $d['status'];

            $sebelum = array_intersect_key($cuti, array_flip(['status', 'status_al', 'status_pyb']));

            if (! (new RiwayatCutiModel())->update($id, $data)) {
                throw new \RuntimeException('Persetujuan cuti gagal disimpan.');
            }

            if ($d['efek_final']) {
                $baru = $this->cuti->ambil($id, true) ?? throw new NotFoundException();
                $efek = $this->efek->terapkan($baru, $d['status'], $this->cuti->hariIni());

                if (! $efek->valid) {
                    throw new ValidationException('Validasi gagal.', ['lama_cuti' => [(string) $efek->pesan]]);
                }
            }

            if ($atasNama) {
                (new AuditLogModel())->record('riwayat_cuti', (string) $id, AuditLogModel::EVENT_UPDATE, $sebelum, [
                    'jenis_audit'           => self::JENIS_AUDIT_ATAS_NAMA,
                    'status'                => $d['status'],
                    'aksi'                  => $aksi,
                    'tahap'                 => $d['tahap'],
                    'nip_approver'          => $pelaku === '' ? null : $pelaku,
                    'nama_approver'         => $d['tahap'] === TransisiCuti::TAHAP_AL ? ($cuti['nama_atasan_langsung'] ?? null) : ($cuti['nama_yang_menyetujui'] ?? null),
                    'alasan_tak_terjangkau' => $alasanTak,
                    'reason_note'           => $reason,
                ], $auth->idPengguna(), $auth->nip());
            }

            // Notifikasi (§6.6.2) di transaksi yang sama; push diantrekan sesudah commit.
            if ($this->notifikasi !== null) {
                $idNotif = $this->notifikasi->setelahProses($this->muat($id), $d['tahap'], $d['reason_note']);
            }
        });

        $this->notifikasi?->antrekan($idNotif);

        return $this->cuti->itemDetail($auth, $this->muat($id));
    }

    /**
     * Tunda pengingat (T11, §6.4.7): hanya approver tahap aktif, sebelum `tgl_mulai` 12:00; tidak mengubah status.
     *
     * @return array<string, mixed>
     */
    public function tunda(AuthContext $auth, int $id): array
    {
        $cuti = $this->cuti->ambil($id) ?? throw new NotFoundException();

        if (! $this->cuti->bolehLihat($auth, $cuti)) {
            throw new NotFoundException();
        }

        $nip = $auth->nip();

        if (! $this->terkaitApprover($cuti, $nip)) {
            throw new ForbiddenException('Anda bukan pejabat yang memproses permohonan ini.');
        }

        $status = (int) $cuti['status'];
        $nipAl  = $this->plain($cuti['nip_atasan_langsung'] ?? null);
        $nipPyb = $this->plain($cuti['nip_yang_menyetujui'] ?? null);
        $tahap  = TransisiCuti::tahapAktif($status, TransisiCuti::langkahTunggal($nipAl, $nipPyb));
        $pelaku = TransisiCuti::nipPelaku($status, $nipAl, $nipPyb);

        if ($tahap === null || $pelaku === null || $pelaku === '' || $pelaku !== $nip) {
            throw ValidationException::forField('status', TransisiCuti::PESAN_TAHAP);
        }

        if (! PengingatCuti::bolehTunda($this->cuti->sekarang(), (string) $cuti['tgl_mulai'], $this->aturan)) {
            throw ValidationException::forField('status', PengingatCuti::pesanTolak((string) $cuti['tgl_mulai'], $this->aturan));
        }

        // State tampilan: tanpa audit (PRD §6.4.7), tidak menyentuh status.
        $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->whereIn('status', StatusCuti::MENUNGGU)->update([PengingatCuti::flagTahap($tahap) => 1]);

        return $this->cuti->itemDetail($auth, $this->muat($id));
    }

    /**
     * Validasi kuota diulang saat keputusan final jenis 1 di dalam transaksi terkunci (§6.3.4).
     *
     * @param array<string, mixed> $cuti
     */
    private function ulangiKuota(array $cuti): void
    {
        $tahun = $this->kuota->tahunAcuan((string) $cuti['created_at']);
        $h     = $this->kuota->periksa((string) $cuti['nip'], (string) $cuti['tgl_mulai'], (string) $cuti['tgl_akhir'], $tahun, (int) $cuti['id_riwayat_cuti'], true);

        if (! $h->valid) {
            throw new ValidationException('Validasi gagal.', ['lama_cuti' => [(string) $h->pesan]]);
        }
    }

    /**
     * @param array<string, mixed> $cuti
     * @param array<string, mixed> $d    detail {@see TransisiCuti::proses()}
     *
     * @return array<string, mixed>
     */
    private function kolomTulis(array $cuti, array $d, string $aksi, bool $atasNama, AuthContext $auth): array
    {
        $tahap = (string) $d['tahap'];
        $data  = [];

        if ($d['status_al'] !== null) {
            $data['status_al'] = $d['status_al'];
        }

        if ($d['status_pyb'] !== null) {
            $data['status_pyb'] = $d['status_pyb'];
        }

        $data[(string) $d['flag_tampil']] = 1;
        $data['updated_by']               = $auth->idPengguna() ?? 0;

        // Alasan: setujui menulis NULL (T4 `reason_note_al = NULL`); atas nama selain tolak menyimpan alasan HANYA di audit
        // (`jenis_audit`, §6.4.10); selain itu alasan per tahap. `reason_note` umum hanya untuk tolak (legacy).
        $alasan = ($aksi === TransisiCuti::AKSI_SETUJUI || ($atasNama && $aksi !== TransisiCuti::AKSI_TOLAK)) ? null : $d['reason_note'];

        if ($tahap === TransisiCuti::TAHAP_AL) {
            $data['reason_note_al'] = $alasan;
        } else {
            $data['reason_note_pyb'] = $alasan;
        }

        if ($aksi === TransisiCuti::AKSI_TOLAK) {
            $data['reason_note'] = $d['reason_note'];
        }

        if ($d['final']) {
            $data['approved_by'] = $auth->idPengguna();
        }

        $nama    = (string) ($tahap === TransisiCuti::TAHAP_AL ? ($cuti['nama_atasan_langsung'] ?? '') : ($cuti['nama_yang_menyetujui'] ?? ''));
        $jabatan = (string) ($tahap === TransisiCuti::TAHAP_AL ? ($cuti['jabatan_atasan_langsung'] ?? '') : ($cuti['jabatan_yang_menyetujui'] ?? ''));
        $baris   = $atasNama
            ? $this->barisAtasNama($aksi, $nama, $jabatan, $auth)
            : TransisiCuti::barisKeterangan($aksi, $nama, $jabatan);

        $data['keterangan_petugas_approval'] = TransisiCuti::keteranganPetugas($cuti['keterangan_petugas_approval'] ?? null, $baris, $tahap);

        return $data;
    }

    private function barisAtasNama(string $aksi, string $nama, string $jabatan, AuthContext $auth): string
    {
        $akun  = $auth->idPengguna() === null ? null : $this->db->table('pengguna')->select('username')->where('id_pengguna', $auth->idPengguna())->get()->getRowArray();
        $user  = (string) ($akun['username'] ?? '-');
        $label = ['setujui' => 'Disetujui', 'tolak' => 'Ditolak', 'tangguhkan' => 'Ditangguhkan'][$aksi] ?? $aksi;
        $siapa = $nama !== '' ? $nama : ($jabatan !== '' ? $jabatan : 'Menteri');

        return "- {$label} atas nama {$siapa} oleh admin ({$user})";
    }

    /**
     * Pemanggil adalah AL atau PyB tercatat di baris itu.
     *
     * @param array<string, mixed> $cuti
     */
    private function terkaitApprover(array $cuti, ?string $nip): bool
    {
        return $nip !== null && $nip !== '' && ($nip === (string) ($cuti['nip_atasan_langsung'] ?? '') || $nip === (string) ($cuti['nip_yang_menyetujui'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function muat(int $id): array
    {
        return $this->cuti->ambil($id) ?? throw new NotFoundException();
    }

    private function teks(mixed $nilai): ?string
    {
        return is_string($nilai) || is_int($nilai) ? (string) $nilai : null;
    }

    private function plain(mixed $nilai): ?string
    {
        return $nilai === null ? null : (string) $nilai;
    }
}
