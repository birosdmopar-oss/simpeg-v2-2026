<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\EsignGatewayInterface;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use App\Models\AuditLogModel;
use App\Models\Layanan\EsignHistModel;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Penerbitan surat cuti ber-TTE lewat gateway e-sign (mock di Fase 4) — PRD Fase 4 v1.3 §6.5, C-05. `POST cuti/{id}/esign`.
 *
 * Urutan (§6.5.3): role (filter route) → baris ada (404) → lingkup role 3 (403) → body (422) → status 1/4 dan belum
 * ber-TTE (422) → penanda tangan (422) → `checkUser` + `signPdf` → transaksi bisnis (kunci, cek ulang, simpan PDF ber-TTE,
 * audit) → `esign_hist`.
 *
 * Passphrase hanya dibaca dari body, diteruskan ke gateway, dan tidak pernah disimpan, dicatat, atau dikembalikan
 * (juga tidak di `esign_hist`, `audit_logs`, respons, maupun pesan galat). Percobaan GAGAL dicatat `esign_hist`
 * status '2' di luar transaksi bisnis agar tidak ikut rollback; `esign_hist` append-only, satu baris per percobaan.
 */
class EsignCutiService
{
    public const MODUL = 'Cuti Online';

    public const JENIS_AUDIT = 'cuti.esign_terbit';

    public const PESAN_STATUS = 'Surat cuti hanya dapat diterbitkan untuk cuti yang sudah disetujui atau ditangguhkan.';

    public const PESAN_SUDAH_TTE = 'Surat cuti sudah ditandatangani.';

    public const PESAN_PASSPHRASE = 'Passphrase tanda tangan digital tidak boleh kosong.';

    public const PESAN_PEJABAT = 'Data pejabat yang berwenang menyetujui cuti tidak terdaftar didalam sistem.';

    public const PESAN_NIK = 'NIK pejabat belum terdaftar.';

    private const ALASAN = 'Persetujuan cuti';

    private const LOKASI = 'Jakarta';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly CutiService $cuti,
        private readonly SuratCutiService $surat,
        private readonly EsignGatewayInterface $gateway,
        private readonly PegawaiScopeInterface $scope,
        private readonly ?CutiNotifikasiService $notifikasi = null,
    ) {
    }

    /**
     * @param array<string, mixed> $in `{passphrase}` (body JSON)
     *
     * @return array<string, mixed> item cuti terbaru
     */
    public function terbitkan(AuthContext $auth, int $id, array $in, ?string $ip, ?string $userAgent): array
    {
        $cuti = $this->cuti->ambil($id) ?? throw new NotFoundException();
        $role = (int) $auth->role();

        if (! in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true)) {
            throw new ForbiddenException('Anda tidak berwenang menerbitkan surat cuti.');
        }

        if ($role === Role::ADMIN_SATKER && ! $this->scope->bolehProses($auth, (string) $cuti['nip'])) {
            throw new ForbiddenException('Cuti pegawai ini di luar lingkup Anda.');
        }

        $passphrase = $in['passphrase'] ?? null;

        if (! is_string($passphrase) || trim($passphrase) === '') {
            throw ValidationException::forField('passphrase', self::PESAN_PASSPHRASE);
        }

        $nipPemohon = (string) $cuti['nip'];
        $this->periksaBisaDiterbitkan($cuti, $nipPemohon, $id);

        $nipTtd = $this->nipPenandaTangan($cuti) ?? throw ValidationException::forField('nip', self::PESAN_PEJABAT);
        $ttd    = $this->db->table('pegawai')->where('nip', $nipTtd)->get()->getRowArray();

        if ($ttd === null) {
            throw ValidationException::forField('nip', self::PESAN_PEJABAT);
        }

        $sumber = $this->surat->belumTte($nipPemohon, $id);

        if ($sumber === null) {
            // Surat belum TTE belum ada (mis. cuti final sebelum M4): buat dulu, lalu tanda tangani.
            $this->cuti->dalamTransaksi(function () use ($id): void {
                $this->surat->terbitkanBelumTte($this->cuti->ambil($id, true) ?? throw new NotFoundException());
            });
            $sumber = $this->surat->belumTte($nipPemohon, $id) ?? throw new \RuntimeException('Surat cuti belum TTE gagal dibuat.');
        }

        $catat = fn (string $status, string $keterangan, ?string $kode) => $this->catat($ttd, $id, (string) ($sumber['lampiran']['path'] ?? $sumber['lampiran']['basename'] ?? ''), $status, $keterangan, $kode, $ip, $userAgent);

        if (trim((string) ($ttd['nik'] ?? '')) === '') {
            $catat(EsignHistModel::STATUS_GAGAL, self::PESAN_NIK, null);

            throw ValidationException::forField('nip', self::PESAN_NIK);
        }

        $hasil = $this->tandatangani($nipTtd, $passphrase, $sumber['isi'], $cuti);

        if ($hasil['pdf'] === null) {
            $catat(EsignHistModel::STATUS_GAGAL, $hasil['pesan'], $hasil['kode']);

            throw ValidationException::forField($hasil['field'], $hasil['pesan']);
        }

        try {
            $this->cuti->dalamTransaksi(function () use ($auth, $id, $nipPemohon, $hasil, $nipTtd): void {
                $this->cuti->kunciPegawai($nipPemohon);
                $baru = $this->cuti->ambil($id, true) ?? throw new NotFoundException();
                $this->periksaBisaDiterbitkan($baru, $nipPemohon, $id);

                $berkas = $this->surat->simpanTte($nipPemohon, $id, (string) $hasil['pdf']);

                (new AuditLogModel())->record('riwayat_cuti', (string) $id, AuditLogModel::EVENT_UPDATE, ['status' => $baru['status']], [
                    'jenis_audit'   => self::JENIS_AUDIT,
                    'status'        => $baru['status'],
                    'nip_penanda'   => $nipTtd,
                    'id_attachment' => $berkas['id_attachment'] ?? null,
                ], $auth->idPengguna(), $auth->nip());
            });
        } catch (Throwable $e) {
            $catat(EsignHistModel::STATUS_GAGAL, 'Dokumen ditandatangani gateway tetapi gagal disimpan: ' . $this->bersihkan($e->getMessage(), $passphrase), $hasil['kode']);

            throw $e;
        }

        $catat(EsignHistModel::STATUS_BERHASIL, (string) $hasil['pesan'], $hasil['kode']);

        $this->notifikasi?->suratTerbit($this->cuti->ambil($id) ?? $cuti, $auth);

        return $this->cuti->itemDetail($auth, $this->cuti->ambil($id) ?? throw new NotFoundException());
    }

    /**
     * @param array<string, mixed> $cuti
     */
    private function periksaBisaDiterbitkan(array $cuti, string $nip, int $id): void
    {
        if (! in_array((int) $cuti['status'], StatusCuti::EFEK_FINAL, true)) {
            throw ValidationException::forField('status', self::PESAN_STATUS);
        }

        if ($this->surat->adaTte($nip, $id)) {
            throw ValidationException::forField('status', self::PESAN_SUDAH_TTE);
        }
    }

    /**
     * Penanda tangan = NIP PyB; langkah tunggal tanpa PyB → NIP AL; keduanya kosong (eselon I) → null.
     *
     * @param array<string, mixed> $cuti
     */
    private function nipPenandaTangan(array $cuti): ?string
    {
        foreach (['nip_yang_menyetujui', 'nip_atasan_langsung'] as $kolom) {
            $nip = trim((string) ($cuti[$kolom] ?? ''));

            if ($nip !== '') {
                return $nip;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $cuti
     *
     * @return array{pdf: string|null, pesan: string, kode: string|null, field: string}
     */
    private function tandatangani(string $nip, string $passphrase, string $pdf, array $cuti): array
    {
        try {
            $cek = $this->gateway->checkUser($nip);

            if ($cek['status_code'] !== '200') {
                return ['pdf' => null, 'pesan' => $this->bersihkan($cek['message'], $passphrase), 'kode' => $cek['status_code'], 'field' => 'nip'];
            }

            $r = $this->gateway->signPdf($pdf, $nip, $passphrase, [
                'tag_koordinat' => '##',
                'tampilan'      => 'visible',
                'reason'        => self::ALASAN,
                'location'      => self::LOKASI,
                'modul'         => self::MODUL,
                'id_rw'         => (int) $cuti['id_riwayat_cuti'],
            ]);
        } catch (Throwable $e) {
            return ['pdf' => null, 'pesan' => $this->bersihkan($e->getMessage(), $passphrase), 'kode' => null, 'field' => 'passphrase'];
        }

        $pesan = $this->bersihkan($r['message'], $passphrase);

        if ($r['status_code'] !== '200' || $r['signed_pdf'] === null || $r['signed_pdf'] === '') {
            return ['pdf' => null, 'pesan' => $pesan !== '' ? $pesan : 'Penandatanganan gagal.', 'kode' => $r['status_code'], 'field' => 'passphrase'];
        }

        return ['pdf' => $r['signed_pdf'], 'pesan' => $pesan, 'kode' => $r['status_code'], 'field' => 'passphrase'];
    }

    /**
     * Satu baris `esign_hist` per percobaan, di luar transaksi bisnis.
     *
     * @param array<string, mixed> $ttd baris `pegawai` penanda tangan
     */
    private function catat(array $ttd, int $idCuti, string $berkasSumber, string $status, string $keterangan, ?string $kode, ?string $ip, ?string $userAgent): void
    {
        $nama = trim(trim((string) ($ttd['glr_awal'] ?? '')) . ' ' . trim((string) ($ttd['nama'] ?? '')));

        if (trim((string) ($ttd['glr_akhir'] ?? '')) !== '') {
            $nama .= ', ' . trim((string) $ttd['glr_akhir']);
        }

        (new EsignHistModel())->insert([
            'nip'              => $ttd['nip'],
            'nik'              => (string) ($ttd['nik'] ?? ''),
            'nama_lengkap'     => mb_substr($nama, 0, 256),
            'modul'            => self::MODUL,
            'id_rw'            => $idCuti,
            'file_pdf_to_sign' => $berkasSumber !== '' ? mb_substr($berkasSumber, 0, 256) : '-',
            'status'           => $status,
            'status_code'      => $kode !== null && $kode !== '' ? mb_substr($kode, 0, 10) : null,
            'ip_address'       => $ip !== null ? mb_substr($ip, 0, 45) : null,
            'user_agent'       => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'keterangan'       => mb_substr($keterangan, 0, 255),
            'created_at'       => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Buang passphrase dari teks yang akan disimpan/dikembalikan (pesan gateway/exception) dan batasi panjangnya.
     */
    private function bersihkan(string $teks, string $passphrase): string
    {
        return $passphrase === '' ? $teks : str_replace($passphrase, '***', $teks);
    }
}
