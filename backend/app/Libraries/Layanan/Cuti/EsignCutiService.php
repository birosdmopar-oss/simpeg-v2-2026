<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Exceptions\ApiException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\EsignGatewayInterface;
use App\Interfaces\Layanan\TautanSuratCutiInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\CacheService;
use App\Models\AuditLogModel;
use App\Models\Layanan\EsignHistModel;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Penandatanganan surat cuti ber-TTE lewat gateway e-sign — PRD Fase 4 v1.3 §6.5 (C-05), diselaraskan PRD Fase 6 F6-18
 * (keputusan Horii 03-11-2026, kartu Catatan Teknis): `POST cuti/{id}/esign`.
 *
 * - K1 (dua langkah, deviasi dari PRD Fase 4 Q-09 dialog admin): passphrase diketik **penanda tangan sendiri** (PyB; langkah
 *   tunggal tanpa PyB → AL) di halaman tugasnya SESUDAH persetujuan tersimpan. Hanya pemilik tugas (NIP akun = NIP penanda
 *   tangan) yang boleh menandatangani — bukan berdasarkan role. Gagal → persetujuan tetap tersimpan, surat tanpa TTE, dan
 *   penanda tangan boleh mengulang (legacy `Cuti::process_esign` membatalkan persetujuan — deviasi dicatat).
 * - K2 [V2]: maksimal 5 percobaan gagal per penanda tangan per cuti dalam 15 menit (pola LockoutService, dihitung dari
 *   `esign_hist` status 2) → 429 `esign_terkunci`.
 * - K3b: `imageTTD` = QR berisi URL publik PDF ber-TTE ({@see TautanSuratCutiInterface}); belum tersedia → tanpa gambar.
 * - Cek sertifikat sebelum meminta passphrase ({@see self::statusSertifikat()}, ikut legacy `Cuti.php:687-705`
 *   `checkStatus` saat membuka halaman proses; putusan reviewer 03-11-2026): tidak aktif → passphrase tidak diminta, surat
 *   tanpa TTE; gateway mati → galat, boleh diulang, TIDAK dihitung pembatas K2.
 *
 * Rujukan PRD Fase 6 v1.0f (CR-097): Q6-19 (K1), Q6-20 (K2), Q6-21 (Tolak tanpa passphrase), Q6-22 (QR TTE), AC-02.6.
 *
 * Pengecualian Q6-40 (ISSUE-055 opsi d, MAKE-075): `atas_nama` = Super Admin menandatangani "atas nama" penanda tangan
 * dengan passphrase pejabat itu, hanya bila keputusan final cuti diproses atas nama ({@see TteAtasNama}). Gateway tetap
 * memakai identitas penanda tangan (NIK PyB/AL), `esign_hist.nip` = penanda tangan, pembatas K2 per penanda tangan + cuti
 * (percobaan admin ikut dihitung dan mengunci pejabat juga), audit `cuti.esign_terbit` mencatat pelaku = admin +
 * `atas_nama: true` + `nip_penanda`.
 *
 * Urutan: baris ada & boleh dilihat (404) → pemilik tugas (403) → kunci per cuti (409, {@see KunciSuratCuti}) → batas
 * percobaan (429) → body (422) → status 1/4 dan belum ber-TTE (422)
 * → penanda tangan (422) → `checkUser` + `signPdf` → transaksi bisnis (kunci, cek ulang, simpan PDF ber-TTE, audit) →
 * `esign_hist`.
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

    public const PESAN_BUKAN_PENANDA = 'Hanya pejabat penanda tangan surat cuti ini yang dapat menandatangani.';

    public const BATAS_PERCOBAAN = 5;

    public const JENDELA_MENIT = 15;

    public const KODE_TERKUNCI = 'esign_terkunci';

    public const SERTIFIKAT_AKTIF = 'aktif';

    public const SERTIFIKAT_TIDAK_AKTIF = 'tidak_aktif';

    public const SERTIFIKAT_GALAT = 'galat';

    /** Detik cache hasil cek sertifikat per penanda tangan (kunci = hash NIP; NIK tidak disimpan). */
    public const CACHE_SERTIFIKAT_DETIK = 300;

    /** Kode gateway mati/timeout dari adapter. */
    private const KODE_GATEWAY_MATI = ['timeout', 'koneksi'];

    /** `esign_hist.status_code` galat gateway (5xx, balasan bukan PDF, exception adapter) — review B4a P2. */
    public const KODE_GALAT_GATEWAY = 'gateway';

    /** `esign_hist.status_code` galat internal (PDF ber-TTE gagal disimpan) — review B4a P2. */
    public const KODE_GALAT_INTERNAL = 'internal';

    /** `esign_hist.status_code` NIK penanda tangan belum terdaftar (data, bukan percobaan passphrase). */
    public const KODE_NIK_KOSONG = 'nik_kosong';

    /**
     * Kode `esign_hist` yang BUKAN percobaan passphrase sehingga tidak dihitung pembatas K2 (Q6-20). Galat gateway
     * diklasifikasikan satu tempat oleh {@see self::galatGateway()}, juga dipakai {@see self::statusSertifikat()}.
     */
    public const KODE_BUKAN_PERCOBAAN = ['timeout', 'koneksi', self::KODE_GALAT_GATEWAY, self::KODE_GALAT_INTERNAL, self::KODE_NIK_KOSONG];

    public const PESAN_GALAT_GATEWAY = 'Layanan tanda tangan elektronik tidak dapat dihubungi. Coba lagi.';

    public const PESAN_GALAT_INTERNAL = 'Surat cuti ber-TTE gagal disimpan. Coba lagi.';

    private const ALASAN = 'Dokumen ini ditandatangani secara elektronik menggunakan Aplikasi SIMPEG Kemenpar';

    private const LOKASI = 'Jakarta';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly CutiService $cuti,
        private readonly SuratCutiService $surat,
        private readonly EsignGatewayInterface $gateway,
        private readonly ?CutiNotifikasiService $notifikasi = null,
        private readonly ?TautanSuratCutiInterface $tautan = null,
        private readonly ?CacheService $cache = null,
    ) {
    }

    /**
     * `GET cuti/{id}/esign/status` — cek sertifikat penanda tangan (pemilik tugas saja) sebelum dialog passphrase. Hasil
     * aktif/tidak aktif di-cache {@see self::CACHE_SERTIFIKAT_DETIK} detik per NIP (tanpa NIK); galat gateway tidak di-cache.
     *
     * @return array{status: string, pesan: string}
     */
    public function statusSertifikat(AuthContext $auth, int $id, bool $atasNama = false): array
    {
        $cuti   = $this->cutiPenanda($auth, $id, $atasNama);
        $nipTtd = (string) self::penandaTangan($cuti);

        $this->periksaBisaDiterbitkan($cuti, (string) $cuti['nip'], $id);

        $kunci     = 'esign_sertifikat_' . hash('sha256', $nipTtd);
        $tersimpan = $this->cache?->get($kunci);

        if (is_array($tersimpan) && is_string($tersimpan['status'] ?? null) && is_string($tersimpan['pesan'] ?? null)) {
            return ['status' => $tersimpan['status'], 'pesan' => $tersimpan['pesan']];
        }

        try {
            $cek = $this->gateway->checkUser($nipTtd);
        } catch (Throwable) {
            return ['status' => self::SERTIFIKAT_GALAT, 'pesan' => self::PESAN_GALAT_GATEWAY];
        }

        // S3 review B4a: 401/403/429 = kredensial aplikasi / pembatasan gateway, bukan status sertifikat → galat, tidak di-cache.
        if (self::galatGateway($cek['status_code']) || in_array($cek['status_code'], ['401', '403', '429'], true)) {
            return ['status' => self::SERTIFIKAT_GALAT, 'pesan' => self::PESAN_GALAT_GATEWAY];
        }

        $hasil = $cek['status_code'] === '200'
            ? ['status' => self::SERTIFIKAT_AKTIF, 'pesan' => 'Sertifikat elektronik aktif.']
            : ['status' => self::SERTIFIKAT_TIDAK_AKTIF, 'pesan' => 'Sertifikat elektronik Anda tidak aktif; surat cuti diterbitkan tanpa tanda tangan elektronik.'];

        $this->cache?->set($kunci, $hasil, self::CACHE_SERTIFIKAT_DETIK);

        return $hasil;
    }

    /**
     * Galat gateway (bukan jawaban atas passphrase): kode kosong, timeout/koneksi, atau HTTP 5xx (termasuk `502` balasan
     * sukses yang bukan PDF). Satu-satunya klasifikasi untuk pembatas K2 dan cek sertifikat (review B4a P2).
     */
    public static function galatGateway(?string $kode): bool
    {
        return $kode === null || $kode === '' || in_array($kode, self::KODE_GATEWAY_MATI, true) || preg_match('/^5\d\d$/', $kode) === 1;
    }

    /**
     * Cuti yang boleh ditandatangani akun ini: tidak terlihat → 404 seragam (S4 review B4a, pola `bolehLihat`, tanpa oracle
     * id); terlihat tetapi bukan penanda tangan → 403. `$atasNama` (Q6-40): Super Admin pada cuti yang final-nya diproses
     * atas nama ({@see TteAtasNama}), selain itu 403.
     *
     * @return array<string, mixed>
     */
    private function cutiPenanda(AuthContext $auth, int $id, bool $atasNama = false): array
    {
        $cuti = $this->cuti->ambil($id);

        if ($cuti === null || ! $this->cuti->bolehLihat($auth, $cuti)) {
            throw new NotFoundException();
        }

        if ($atasNama) {
            if (! TteAtasNama::boleh($this->db, $auth, $cuti)) {
                throw new ForbiddenException(TteAtasNama::PESAN_DITOLAK);
            }

            return $cuti;
        }

        $nipTtd = self::penandaTangan($cuti);

        if ($nipTtd === null || $auth->nip() === null || $auth->nip() !== $nipTtd) {
            throw new ForbiddenException(self::PESAN_BUKAN_PENANDA);
        }

        return $cuti;
    }

    /**
     * Penanda tangan = NIP PyB; langkah tunggal tanpa PyB → NIP AL; keduanya kosong (eselon I) → null.
     *
     * @param array<string, mixed> $cuti
     */
    public static function penandaTangan(array $cuti): ?string
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
     * @param array<string, mixed> $in `{passphrase, atas_nama?}` (body JSON)
     *
     * @return array<string, mixed> item cuti terbaru
     */
    public function terbitkan(AuthContext $auth, int $id, #[\SensitiveParameter] array $in, ?string $ip, ?string $userAgent): array
    {
        $atasNama = ($in['atas_nama'] ?? false) === true || in_array($in['atas_nama'] ?? null, ['true', '1', 1], true);
        $cuti     = $this->cutiPenanda($auth, $id, $atasNama);
        $nipTtd   = (string) self::penandaTangan($cuti);

        // P1 review B4a: satu percobaan per cuti pada satu waktu — pembatas dihitung SEBELUM gateway dan baris gagal ditulis
        // SESUDAHNYA, sehingga tanpa kunci permintaan serentak sama-sama lolos pembatas.
        return KunciSuratCuti::dengan($this->db, $id, fn (): array => $this->terbitkanTerkunci($auth, $id, $cuti, $nipTtd, $in, $ip, $userAgent, $atasNama));
    }

    /**
     * @param array<string, mixed> $cuti
     * @param array<string, mixed> $in
     *
     * @return array<string, mixed>
     */
    private function terbitkanTerkunci(AuthContext $auth, int $id, array $cuti, string $nipTtd, #[\SensitiveParameter] array $in, ?string $ip, ?string $userAgent, bool $atasNama = false): array
    {
        $this->periksaBatasPercobaan($nipTtd, $id);

        $passphrase = $in['passphrase'] ?? null;

        if (! is_string($passphrase) || trim($passphrase) === '') {
            throw ValidationException::forField('passphrase', self::PESAN_PASSPHRASE);
        }

        $nipPemohon = (string) $cuti['nip'];
        $this->periksaBisaDiterbitkan($cuti, $nipPemohon, $id);

        $ttd = $this->db->table('pegawai')->where('nip', $nipTtd)->get()->getRowArray();

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
            $catat(EsignHistModel::STATUS_GAGAL, self::PESAN_NIK, self::KODE_NIK_KOSONG);

            throw ValidationException::forField('nip', self::PESAN_NIK);
        }

        $hasil = $this->tandatangani($nipTtd, $passphrase, $sumber['isi'], $cuti);

        if ($hasil['pdf'] === null) {
            $catat(EsignHistModel::STATUS_GAGAL, $hasil['pesan'], $hasil['kode']);

            throw ValidationException::forField($hasil['field'], $hasil['pesan']);
        }

        try {
            $this->cuti->dalamTransaksi(function () use ($auth, $id, $nipPemohon, $hasil, $nipTtd, $atasNama): void {
                $this->cuti->kunciPegawai($nipPemohon);
                $baru = $this->cuti->ambil($id, true) ?? throw new NotFoundException();
                $this->periksaBisaDiterbitkan($baru, $nipPemohon, $id);

                // Q6-40: syarat atas nama dicek ulang di baris terkunci (keputusan final bisa berubah sejak cek awal).
                if ($atasNama && ! TteAtasNama::boleh($this->db, $auth, $baru)) {
                    throw new ForbiddenException(TteAtasNama::PESAN_DITOLAK);
                }

                $berkas = $this->surat->simpanTte($nipPemohon, $id, (string) $hasil['pdf']);

                (new AuditLogModel())->record('riwayat_cuti', (string) $id, AuditLogModel::EVENT_UPDATE, ['status' => $baru['status']], [
                    'jenis_audit'   => self::JENIS_AUDIT,
                    'status'        => $baru['status'],
                    'nip_penanda'   => $nipTtd,
                    'id_attachment' => $berkas['id_attachment'] ?? null,
                ] + ($atasNama ? ['atas_nama' => true] : []), $auth->idPengguna(), $auth->nip());
            });
        } catch (Throwable $e) {
            log_message('error', 'E-sign cuti {id}: PDF ber-TTE gagal disimpan: {galat}', ['id' => $id, 'galat' => mb_substr($this->bersihkan($e::class . ': ' . $e->getMessage(), $passphrase), 0, 500)]);
            $catat(EsignHistModel::STATUS_GAGAL, 'Dokumen ditandatangani gateway tetapi gagal disimpan.', self::KODE_GALAT_INTERNAL);

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
     * K2 [V2] (Q6-20): {@see self::BATAS_PERCOBAAN} percobaan gagal (esign_hist status 2, kecuali
     * {@see self::KODE_BUKAN_PERCOBAAN}) dalam {@see self::JENDELA_MENIT} menit untuk penanda tangan + cuti yang sama → 429
     * sampai percobaan tertua keluar jendela. Dipanggil di dalam {@see KunciSuratCuti} (P1).
     */
    private function periksaBatasPercobaan(string $nip, int $id): void
    {
        $batas = gmdate('Y-m-d H:i:s', time() - self::JENDELA_MENIT * 60);
        $gagal = $this->db->table('esign_hist')->select('created_at')
            ->where(['modul' => self::MODUL, 'id_rw' => $id, 'nip' => $nip, 'status' => EsignHistModel::STATUS_GAGAL])
            ->groupStart()->where('status_code', null)->orWhereNotIn('status_code', self::KODE_BUKAN_PERCOBAAN)->groupEnd()
            ->where('created_at >=', $batas)->orderBy('created_at', 'DESC')->get(self::BATAS_PERCOBAAN)->getResultArray();

        if (count($gagal) < self::BATAS_PERCOBAAN) {
            return;
        }

        $tertua = strtotime($gagal[self::BATAS_PERCOBAAN - 1]['created_at'] . ' UTC');
        $menit  = max(1, (int) ceil(($tertua + self::JENDELA_MENIT * 60 - time()) / 60));

        throw new ApiException("Terlalu banyak percobaan tanda tangan. Coba lagi dalam {$menit} menit.", 429, null, self::KODE_TERKUNCI);
    }

    /**
     * @param array<string, mixed> $cuti
     *
     * @return array{pdf: string|null, pesan: string, kode: string|null, field: string}
     */
    private function tandatangani(string $nip, #[\SensitiveParameter] string $passphrase, string $pdf, array $cuti): array
    {
        try {
            $cek = $this->gateway->checkUser($nip);

            if (self::galatGateway($cek['status_code'])) {
                return $this->galatLayanan('checkUser', $cek['status_code'], $cek['message'], $passphrase, 'nip');
            }

            if ($cek['status_code'] !== '200') {
                return ['pdf' => null, 'pesan' => $this->bersihkan($cek['message'], $passphrase), 'kode' => $cek['status_code'], 'field' => 'nip'];
            }

            $opsi = [
                'tag_koordinat' => '##',
                'tampilan'      => 'visible',
                'width'         => '50',
                'height'        => '50',
                'reason'        => self::ALASAN,
                'location'      => self::LOKASI,
                'modul'         => self::MODUL,
                'id_rw'         => (int) $cuti['id_riwayat_cuti'],
            ];
            $url = $this->tautan?->urlPdfTte((int) $cuti['id_riwayat_cuti']);
            $qr  = $url === null ? null : QrTtd::png($url);

            if ($qr !== null) {
                $opsi['imageTTD'] = $qr;
            }

            $r = $this->gateway->signPdf($pdf, $nip, $passphrase, $opsi);
        } catch (Throwable $e) {
            // S1 review B4a: exception tak terduga → pesan generik ke pengguna & esign_hist; rincian hanya di log, teredaksi.
            return $this->galatLayanan('exception', null, $e::class . ': ' . $e->getMessage(), $passphrase, 'passphrase');
        }

        if (self::galatGateway($r['status_code'])) {
            return $this->galatLayanan('signPdf', $r['status_code'], $r['message'], $passphrase, 'passphrase');
        }

        $pesan = $this->bersihkan($r['message'], $passphrase);

        if ($r['status_code'] !== '200' || $r['signed_pdf'] === null || $r['signed_pdf'] === '') {
            return ['pdf' => null, 'pesan' => $pesan !== '' ? $pesan : 'Penandatanganan gagal.', 'kode' => $r['status_code'], 'field' => 'passphrase'];
        }

        return ['pdf' => $r['signed_pdf'], 'pesan' => $pesan, 'kode' => $r['status_code'], 'field' => 'passphrase'];
    }

    /**
     * Galat gateway → pesan generik dan `status_code` {@see self::KODE_GALAT_GATEWAY} (tidak dihitung pembatas); kode asli
     * dan pesan gateway hanya di log, teredaksi.
     *
     * @return array{pdf: null, pesan: string, kode: string, field: string}
     */
    private function galatLayanan(string $tahap, ?string $kode, string $rincian, #[\SensitiveParameter] string $passphrase, string $field): array
    {
        log_message('warning', 'E-sign cuti: galat gateway pada {tahap} (kode {kode}): {rincian}', [
            'tahap'   => $tahap,
            'kode'    => $kode ?? '-',
            'rincian' => mb_substr($this->bersihkan($rincian, $passphrase), 0, 500),
        ]);

        // timeout/koneksi tetap tercatat apa adanya (sudah dikecualikan pembatas); galat gateway lain → satu kode khusus.
        $kode = in_array($kode, self::KODE_GATEWAY_MATI, true) ? (string) $kode : self::KODE_GALAT_GATEWAY;

        return ['pdf' => null, 'pesan' => self::PESAN_GALAT_GATEWAY, 'kode' => $kode, 'field' => $field];
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
    private function bersihkan(string $teks, #[\SensitiveParameter] string $passphrase): string
    {
        return $passphrase === '' ? $teks : str_replace($passphrase, '***', $teks);
    }
}
