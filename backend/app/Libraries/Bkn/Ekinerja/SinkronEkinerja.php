<?php

declare(strict_types=1);

namespace App\Libraries\Bkn\Ekinerja;

use CodeIgniter\Database\BaseConnection;

/**
 * Penulis hasil pemetaan e-Kinerja ke DB (PRD Fase 6 §7.8, F6-15, AC-10.1/AC-10.2) — dipanggil SETELAH panggilan API
 * selesai (BP-4: tidak ada panggilan eksternal di dalam transaksi).
 *
 * - Periode [K `EB:9-55`]: hanya INSERT id yang belum ada (periode lama tidak diperbarui).
 * - SKP periodik [K `EB:239-352`]: kunci `id_pns + periode_id + skp_id`; kunci baru → INSERT; kunci ada dan `hasil_akhir`
 *   baru terisi & berbeda → UPDATE seluruh kolom (+ `status = 1`); selain itu tidak berubah. Idempoten: jalankan ulang
 *   dengan respons sama = tanpa perubahan (UNIQUE DBV-027 sebagai pengaman).
 * - AC-10.2: baris yang NIP-nya ada di `pegawai` tetapi `id_pns_siasn` kosong dihitung "tidak terpasang"; NIP yang tidak
 *   ada di `pegawai` dihitung "tidak dikenal". Keduanya tetap disimpan seperti legacy (tabel tanpa FK `nip`) — hanya
 *   jumlah yang dilaporkan, tanpa NIP.
 */
final class SinkronEkinerja
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<array<string, int|string|null>> $baris hasil {@see PemetaEkinerja::periode()}
     *
     * @return array{baru: int, sudah_ada: int}
     */
    public function simpanPeriode(array $baris): array
    {
        if ($baris === []) {
            return ['baru' => 0, 'sudah_ada' => 0];
        }

        $ada = array_fill_keys(array_column(
            $this->db->table('bkn_periode_ekinper')->select('id')->whereIn('id', array_column($baris, 'id'))->get()->getResultArray(),
            'id',
        ), true);

        $baru = [];

        foreach ($baris as $b) {
            if (! isset($ada[(string) $b['id']])) {
                $baru[]                 = $b + ['created_at' => gmdate('Y-m-d H:i:s')];
                $ada[(string) $b['id']] = true;
            }
        }

        if ($baru !== []) {
            $this->db->table('bkn_periode_ekinper')->insertBatch($baru);
        }

        return ['baru' => count($baru), 'sudah_ada' => count($baris) - count($baru)];
    }

    /**
     * @param list<array<string, string|null>> $baris hasil {@see PemetaEkinerja::skpPeriodik()}
     *
     * @return array{baru: int, diperbarui: int, tetap: int, tidak_terpasang: int, tidak_dikenal: int}
     */
    public function simpanSkpPeriodik(array $baris): array
    {
        $hasil = ['baru' => 0, 'diperbarui' => 0, 'tetap' => 0, 'tidak_terpasang' => 0, 'tidak_dikenal' => 0];

        if ($baris === []) {
            return $hasil;
        }

        $ada = [];

        foreach ($this->db->table('riwayat_skp_periodik')
            ->select('id_riwayat_skp_periodik, id_pns, periode_id, skp_id, hasil_akhir')
            ->whereIn('periode_id', array_values(array_unique(array_column($baris, 'periode_id'))))
            ->get()->getResultArray() as $r) {
            $ada[PemetaEkinerja::kunci($r)] = $r;
        }

        $sekarang = gmdate('Y-m-d H:i:s');
        $sisip    = [];

        $this->db->transStart();

        foreach ($baris as $b) {
            $kunci = PemetaEkinerja::kunci($b);

            if (! isset($ada[$kunci])) {
                $sisip[]     = $b + ['created_at' => $sekarang];
                $ada[$kunci] = ['id_riwayat_skp_periodik' => null, 'hasil_akhir' => $b['hasil_akhir']];
                $hasil['baru']++;

                continue;
            }

            $lama = $ada[$kunci];

            if ($b['hasil_akhir'] !== null && (string) $lama['hasil_akhir'] !== $b['hasil_akhir'] && $lama['id_riwayat_skp_periodik'] !== null) {
                $this->db->table('riwayat_skp_periodik')->where('id_riwayat_skp_periodik', $lama['id_riwayat_skp_periodik'])->update($b);
                $ada[$kunci]['hasil_akhir'] = $b['hasil_akhir'];
                $hasil['diperbarui']++;

                continue;
            }

            $hasil['tetap']++;
        }

        if ($sisip !== []) {
            $this->db->table('riwayat_skp_periodik')->insertBatch($sisip);
        }

        $this->db->transComplete();

        return [...$hasil, ...$this->pemasangan(array_values(array_unique(array_column($baris, 'nip'))))];
    }

    /**
     * @param list<string|null> $nip
     *
     * @return array{tidak_terpasang: int, tidak_dikenal: int}
     */
    private function pemasangan(array $nip): array
    {
        $nip = array_values(array_filter($nip, static fn (?string $n): bool => $n !== null));

        if ($nip === []) {
            return ['tidak_terpasang' => 0, 'tidak_dikenal' => 0];
        }

        $pegawai = $this->db->table('pegawai')->select('nip, id_pns_siasn')->whereIn('nip', $nip)->get()->getResultArray();
        $tanpaId = count(array_filter($pegawai, static fn (array $p): bool => trim((string) ($p['id_pns_siasn'] ?? '')) === ''));

        return ['tidak_terpasang' => $tanpaId, 'tidak_dikenal' => count($nip) - count($pegawai)];
    }
}
