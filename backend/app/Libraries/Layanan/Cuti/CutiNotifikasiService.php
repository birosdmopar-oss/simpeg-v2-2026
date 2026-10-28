<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Interfaces\Layanan\NotifikasiLayananInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Layanan\Notifikasi\PenerimaNotifikasi;
use App\Libraries\Layanan\Notifikasi\TemplatNotifikasi;
use Throwable;

/**
 * Titik kirim notifikasi cuti (PRD Fase 4 v1.3 §6.6.2, C-06): memetakan kejadian state machine ke penerima dan templat
 * lalu menulis lewat {@see NotifikasiLayananInterface} (`inbox`/`inbox_msg` v2, satu arah).
 *
 * Pola: metode kejadian (`diajukan`, `diubah`, `setelahProses`) dipanggil di DALAM transaksi bisnis dan mengembalikan id
 * `inbox_msg`; pemanggil memanggil {@see self::antrekan()} SESUDAH commit. `suratTerbit` dipanggil sesudah commit dan
 * mengantrekan sendiri; kegagalannya hanya dicatat (surat sudah ber-TTE, tidak boleh membatalkan respons).
 */
class CutiNotifikasiService
{
    public function __construct(
        private readonly NotifikasiLayananInterface $notif,
        private readonly ApproverTerjangkau $terjangkau,
        private readonly PenerimaNotifikasi $penerima,
    ) {
    }

    /**
     * Pengajuan baru (T1/T2) — atau, bila admin langsung menyimpan status final, keputusan final.
     *
     * @param array<string, mixed> $cuti baris `riwayat_cuti` sesudah disimpan
     *
     * @return list<int>
     */
    public function diajukan(array $cuti): array
    {
        $status = (int) $cuti['status'];

        if ($status !== StatusCuti::PENGAJUAN) {
            return $this->keputusanFinal($cuti, $status, null);
        }

        $ids    = $this->ke($cuti, TemplatNotifikasi::TIPE_DIAJUKAN, $this->approverAktif($cuti), TemplatNotifikasi::diajukan($cuti), null);
        $ids    = array_merge($ids, $this->laporAdminBilaTakTerjangkau($cuti));
        $tembus = TemplatNotifikasi::tembusanDiajukan($cuti);

        return array_merge($ids, $this->ke($cuti, TemplatNotifikasi::TIPE_TEMBUSAN, $this->penerima->ketuaTim((int) $cuti['id_riwayat_cuti']), $tembus, null));
    }

    /**
     * Pengajuan diubah pemohon/admin (T12): ke approver tahap aktif.
     *
     * @param array<string, mixed> $cuti
     *
     * @return list<int>
     */
    public function diubah(array $cuti): array
    {
        return $this->ke($cuti, TemplatNotifikasi::TIPE_PERUBAHAN, $this->approverAktif($cuti), TemplatNotifikasi::diubah($cuti), null);
    }

    /**
     * Sesudah keputusan satu tahap (T4–T10, T14). `$cuti` = baris SESUDAH update (status baru).
     *
     * @param array<string, mixed> $cuti
     * @param string               $tahap {@see TransisiCuti::TAHAP_AL} | {@see TransisiCuti::TAHAP_PYB}, tahap yang baru memutuskan
     *
     * @return list<int>
     */
    public function setelahProses(array $cuti, string $tahap, ?string $reasonNote): array
    {
        $status = (int) $cuti['status'];

        if ($status !== StatusCuti::DISETUJUI_AL) {
            return $this->keputusanFinal($cuti, $status, $reasonNote, $tahap);
        }

        $nipPemohon = [(string) $cuti['nip']];
        $ids        = $this->ke($cuti, TemplatNotifikasi::TIPE_KEPUTUSAN, $nipPemohon, TemplatNotifikasi::sedangDiproses($cuti), null);
        $ids        = array_merge($ids, $this->ke($cuti, TemplatNotifikasi::TIPE_DIAJUKAN, [$this->plain($cuti['nip_yang_menyetujui'] ?? null)], TemplatNotifikasi::diajukan($cuti), null));
        $ids        = array_merge($ids, $this->laporAdminBilaTakTerjangkau($cuti));

        return array_merge($ids, $this->ke($cuti, TemplatNotifikasi::TIPE_TEMBUSAN, $this->penerima->ketuaTim((int) $cuti['id_riwayat_cuti']), TemplatNotifikasi::tembusanStatus($cuti, $status, ''), null));
    }

    /**
     * Surat ber-TTE terbit: ke pemohon. Dipanggil sesudah commit; gagal kirim hanya dicatat.
     *
     * @param array<string, mixed> $cuti
     */
    public function suratTerbit(array $cuti, AuthContext $auth): void
    {
        unset($auth); // pelaku dibaca NotifikasiService dari authContext yang sama

        try {
            $t   = TemplatNotifikasi::suratTerbit($cuti);
            $ids = $this->ke($cuti, TemplatNotifikasi::TIPE_KEPUTUSAN, [(string) $cuti['nip']], $t, null);
            $this->antrekan($ids);
        } catch (Throwable $e) {
            log_message('error', '[CutiNotifikasiService] notifikasi surat terbit cuti={id} gagal: {pesan}', ['id' => $cuti['id_riwayat_cuti'] ?? '?', 'pesan' => $e->getMessage()]);
        }
    }

    /**
     * @param list<int> $ids
     */
    public function antrekan(array $ids): void
    {
        if ($ids !== []) {
            $this->notif->enqueuePush($ids);
        }
    }

    /**
     * @param array<string, mixed> $cuti
     *
     * @return list<int>
     */
    private function keputusanFinal(array $cuti, int $status, ?string $reasonNote, ?string $tahap = null): array
    {
        $namaApprover = $this->namaApprover($cuti, $tahap);
        $reason       = $status === StatusCuti::DITOLAK ? ($reasonNote ?? $this->plain($cuti['reason_note'] ?? null)) : null;

        $ids = $this->ke($cuti, TemplatNotifikasi::TIPE_KEPUTUSAN, [(string) $cuti['nip']], TemplatNotifikasi::keputusan($cuti, $status, $namaApprover, $reason), $reason);

        return array_merge($ids, $this->ke($cuti, TemplatNotifikasi::TIPE_TEMBUSAN, $this->penerima->ketuaTim((int) $cuti['id_riwayat_cuti']), TemplatNotifikasi::tembusanStatus($cuti, $status, $namaApprover), null));
    }

    /**
     * Approver tahap aktif tak terjangkau (eselon I / tanpa akun / nonaktif / dihapus / role di luar route cuti, T14): kabari
     * semua admin role 1 aktif agar memproses atas nama.
     *
     * @param array<string, mixed> $cuti
     *
     * @return list<int>
     */
    private function laporAdminBilaTakTerjangkau(array $cuti): array
    {
        $nip = $this->nipApproverAktif($cuti);

        if ($this->terjangkau->alasanTakTerjangkau($nip) === null) {
            return [];
        }

        return $this->ke($cuti, TemplatNotifikasi::TIPE_DIAJUKAN, $this->penerima->adminAktif(), TemplatNotifikasi::perluAdmin($cuti, $this->namaApproverAktif($cuti)), null);
    }

    /**
     * @param array<string, mixed> $cuti
     * @param list<string|null>    $nips
     * @param array{judul: string, isi: string} $t
     *
     * @return list<int>
     */
    private function ke(array $cuti, int $tipe, array $nips, array $t, ?string $reasonNote): array
    {
        $nips = array_values(array_filter(array_map(static fn (?string $n): string => trim((string) $n), $nips), static fn (string $n): bool => $n !== ''));

        if ($nips === []) {
            return [];
        }

        $id = (int) $cuti['id_riwayat_cuti'];

        return $this->notif->kirim('cuti', $id, $tipe, $nips, $t['judul'], $t['isi'], $reasonNote, (int) $cuti['status'], "/layanan/cuti/{$id}");
    }

    /**
     * @param array<string, mixed> $cuti
     *
     * @return list<string|null>
     */
    private function approverAktif(array $cuti): array
    {
        return [$this->nipApproverAktif($cuti)];
    }

    /**
     * @param array<string, mixed> $cuti
     */
    private function nipApproverAktif(array $cuti): ?string
    {
        $nipAl  = $this->plain($cuti['nip_atasan_langsung'] ?? null);
        $nipPyb = $this->plain($cuti['nip_yang_menyetujui'] ?? null);

        return TransisiCuti::nipPelaku((int) $cuti['status'], $nipAl, $nipPyb);
    }

    /**
     * @param array<string, mixed> $cuti
     */
    private function namaApproverAktif(array $cuti): string
    {
        $tunggal = TransisiCuti::langkahTunggal($this->plain($cuti['nip_atasan_langsung'] ?? null), $this->plain($cuti['nip_yang_menyetujui'] ?? null));
        $tahap   = TransisiCuti::tahapAktif((int) $cuti['status'], $tunggal);

        return $this->namaTahap($cuti, $tahap);
    }

    /**
     * Nama approver yang memutuskan: tahap yang baru memutuskan, atau (admin langsung final) PyB lalu AL; kosong → Menteri.
     *
     * @param array<string, mixed> $cuti
     */
    private function namaApprover(array $cuti, ?string $tahap): string
    {
        $nama = $this->namaTahap($cuti, $tahap ?? TransisiCuti::TAHAP_PYB);

        if ($nama === '' && $tahap === null) {
            $nama = $this->namaTahap($cuti, TransisiCuti::TAHAP_AL);
        }

        return $nama === '' ? 'Menteri' : $nama;
    }

    /**
     * @param array<string, mixed> $cuti
     */
    private function namaTahap(array $cuti, ?string $tahap): string
    {
        $kolom = $tahap === TransisiCuti::TAHAP_AL ? 'nama_atasan_langsung' : 'nama_yang_menyetujui';

        return trim((string) ($cuti[$kolom] ?? ''));
    }

    private function plain(mixed $nilai): ?string
    {
        return $nilai === null ? null : (string) $nilai;
    }
}
