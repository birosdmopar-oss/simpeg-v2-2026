<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Libraries\Kepegawaian\Kalkulasi\HasilKalkulasi;
use App\Libraries\MasterData\HariLiburService;
use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * MAKE-022 M3 (PRD Fase 4 v1.3 §6.3) — satu pintu DB untuk lama cuti, kuota, potong, dan refund. Membungkus library
 * murni ({@see HitungLamaCuti}, {@see PorsiTahunCuti}, {@see KuotaCuti}, {@see SnapshotSaldoCuti}); TIDAK ada
 * algoritma potong/refund kedua. Perubahan saldo hanya di dalam transaksi pemanggil dengan baris `pegawai_sisa_cuti`
 * dikunci `FOR UPDATE`, dan setiap perubahan ditulis `audit_logs` (update, before/after).
 */
class KuotaCutiService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly AturanCuti $aturan,
        private readonly HariLiburService $libur,
    ) {
    }

    public function aturan(): AturanCuti
    {
        return $this->aturan;
    }

    /**
     * Lama cuti dan tanggal akhir efektif (satu fungsi untuk kalkulator dan simpan, AC-03.6). Tanggal harus sudah lolos
     * {@see ValidasiTanggalCuti::periksa()}.
     *
     * @return array{lama_cuti: int, satuan_lama_cuti: string, tgl_akhir: string, hari_libur: list<string>}
     */
    public function hitungLama(int $jenis, string $mulai, string $akhir): array
    {
        $libur = $this->libur->tanggalLibur($mulai, $akhir);

        return [
            'lama_cuti'        => HitungLamaCuti::lama($jenis, $mulai, $akhir, $libur, $this->aturan),
            'satuan_lama_cuti' => 'Hari',
            'tgl_akhir'        => $akhir,
            'hari_libur'       => HitungLamaCuti::liburTerpakai($mulai, $akhir, $libur),
        ];
    }

    /**
     * @return array{tahun_ini: int, tahun_depan: int}
     */
    public function porsi(int $jenis, string $mulai, string $akhir, int $tahunAcuan): array
    {
        $dari  = min($mulai, sprintf('%04d-01-01', $tahunAcuan));
        $libur = $this->libur->tanggalLibur($dari, max($akhir, $mulai));

        return PorsiTahunCuti::pecah($jenis, $mulai, $akhir, $tahunAcuan, $libur, $this->aturan);
    }

    /**
     * Tahun acuan dari `created_at` (UTC) dalam zona waktu aplikasi (PRD §6.3.3).
     */
    public function tahunAcuan(string $createdAtUtc): int
    {
        $waktu = new \DateTimeImmutable($createdAtUtc, new \DateTimeZone('UTC'));

        return (int) $waktu->setTimezone(new \DateTimeZone(\App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis::ZONA))->format('Y');
    }

    public function saldo(string $nip, int $tahun, bool $kunci = false): ?SaldoCuti
    {
        $baris = $this->barisSaldo($nip, $tahun, $kunci);

        return $baris === null ? null : SaldoCuti::dariBaris($baris);
    }

    /**
     * Jumlah porsi cuti Tahunan status {0,3} milik NIP (dihitung ulang dari tanggal, A-10), tanpa baris `$kecuali`.
     *
     * @return array{tahun_ini: int, tahun_depan: int}
     */
    public function menunggu(string $nip, int $tahunAcuan, ?int $kecuali = null): array
    {
        $b = $this->db->table('riwayat_cuti')
            ->select('id_riwayat_cuti, tgl_mulai, tgl_akhir')
            ->where('nip', $nip)
            ->where('id_jenis_cuti', AturanCuti::JENIS_TAHUNAN)
            ->whereIn('status', StatusCuti::MENUNGGU);

        if ($kecuali !== null) {
            $b->where('id_riwayat_cuti !=', $kecuali);
        }

        $ini = $depan = 0;

        foreach ($b->get()->getResultArray() as $baris) {
            $p = $this->porsi(AturanCuti::JENIS_TAHUNAN, (string) $baris['tgl_mulai'], (string) $baris['tgl_akhir'], $tahunAcuan);
            $ini += $p['tahun_ini'];
            $depan += $p['tahun_depan'];
        }

        return ['tahun_ini' => $ini, 'tahun_depan' => $depan];
    }

    /**
     * Validasi kuota jenis 1 (§6.3.4) — dipakai ajukan/ubah, kalkulator, dan diulang saat final (dengan `$kunci`).
     */
    public function periksa(string $nip, string $mulai, string $akhir, int $tahunAcuan, ?int $kecuali = null, bool $kunci = false): HasilKalkulasi
    {
        $porsi    = $this->porsi(AturanCuti::JENIS_TAHUNAN, $mulai, $akhir, $tahunAcuan);
        $menunggu = $this->menunggu($nip, $tahunAcuan, $kecuali);

        return KuotaCuti::periksa(
            $this->saldo($nip, $tahunAcuan, $kunci),
            $porsi['tahun_ini'],
            $porsi['tahun_depan'],
            $menunggu['tahun_ini'],
            $menunggu['tahun_depan'],
            $tahunAcuan,
            $this->aturan,
        );
    }

    /**
     * Potong saldo pada transisi final (1 atau 4). Harus dipanggil di dalam transaksi pemanggil. Kuota kurang →
     * `HasilKalkulasi` gagal dan saldo tidak berubah (pemanggil membatalkan transaksi, T12/A-07).
     *
     * @param array<string, mixed> $cuti baris `riwayat_cuti`
     */
    public function potong(array $cuti, int $statusFinal): HasilKalkulasi
    {
        $nip   = (string) $cuti['nip'];
        $tahun = $this->tahunAcuan((string) $cuti['created_at']);
        $baris = $this->barisSaldo($nip, $tahun, true);

        if ($baris === null) {
            return HasilKalkulasi::gagal(KuotaCuti::KODE_SALDO_TIDAK_TERDAFTAR, KuotaCuti::PESAN_SALDO_TIDAK_TERDAFTAR, ['tahun' => $tahun]);
        }

        $porsi = $this->porsi(AturanCuti::JENIS_TAHUNAN, (string) $cuti['tgl_mulai'], (string) $cuti['tgl_akhir'], $tahun);
        $hasil = KuotaCuti::potong(SaldoCuti::dariBaris($baris), $porsi['tahun_ini'], $porsi['tahun_depan'], $statusFinal, $this->aturan);

        if (! $hasil->valid) {
            return $hasil;
        }

        $this->tulisSaldo($baris, $hasil->detail['saldo']);

        return $hasil;
    }

    /**
     * Refund saat hapus cuti final jenis 1 (D6, §6.3.5): SATU algoritma {@see KuotaCuti::kembalikan()} dengan snapshot
     * saldo baris yang dihapus. Tahun acuan = tahun `created_at` baris itu. Tidak ada baris saldo → tidak ada yang
     * dikembalikan (mengembalikan false).
     *
     * @param array<string, mixed> $cuti baris `riwayat_cuti` sebelum dihapus
     */
    public function kembalikan(array $cuti): bool
    {
        $tahun = $this->tahunAcuan((string) $cuti['created_at']);
        $baris = $this->barisSaldo((string) $cuti['nip'], $tahun, true);

        if ($baris === null) {
            return false;
        }

        $porsi = $this->porsi(AturanCuti::JENIS_TAHUNAN, (string) $cuti['tgl_mulai'], (string) $cuti['tgl_akhir'], $tahun);
        $baru  = KuotaCuti::kembalikan(
            SaldoCuti::dariBaris($baris),
            $porsi['tahun_ini'],
            $porsi['tahun_depan'],
            (int) $cuti['status'],
            $this->aturan,
            SnapshotSaldoCuti::dariBaris($cuti),
        );

        $this->tulisSaldo($baris, $baru);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function barisSaldo(string $nip, int $tahun, bool $kunci): ?array
    {
        $b = $this->db->table('pegawai_sisa_cuti')->where('nip', $nip)->where('tahun', $tahun);

        if ($kunci) {
            // CI4 tidak punya FOR UPDATE di query builder; kunci baris lewat SELECT mentah di dalam transaksi pemanggil.
            $sql = $b->limit(1)->getCompiledSelect() . ' FOR UPDATE';
            $r   = $this->db->query($sql);

            if ($r === false) {
                throw new RuntimeException('Gagal mengunci saldo cuti.');
            }

            return $r->getRowArray();
        }

        return $b->get()->getRowArray();
    }

    /**
     * @param array<string, mixed> $sebelum baris `pegawai_sisa_cuti` terkunci
     */
    private function tulisSaldo(array $sebelum, SaldoCuti $baru): void
    {
        $this->db->table('pegawai_sisa_cuti')
            ->where('id_pegawai_sisa_cuti', $sebelum['id_pegawai_sisa_cuti'])
            ->update($baru->keBaris());

        (new AuditLogModel())->record(
            'pegawai_sisa_cuti',
            (string) $sebelum['id_pegawai_sisa_cuti'],
            AuditLogModel::EVENT_UPDATE,
            SaldoCuti::dariBaris($sebelum)->keBaris(),
            $baru->keBaris(),
            service('authContext')->idPengguna(),
            service('authContext')->nip(),
        );
    }
}
