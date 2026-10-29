<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use JsonException;

/**
 * Berkas keputusan DBV (log 2.8, GAP-6): pemetaan nilai di luar domain yang TIDAK diputuskan coder. Hanya kode/nilai,
 * tanpa NIP/nama. Bentuk (contoh lengkap `docs/up1/keputusan-impor.contoh.json`):
 *
 *   {"status": {"<tabel>": {"<nilai legacy>": <nilai v2>, "null": 1}, "<tabel>.<kolom>": {…}},
 *    "nilai":  {"<tabel>.<kolom>": {"<nilai legacy>": <nilai v2 | null>}},
 *    "tanggal_nol": {"<tabel>.<kolom>": "YYYY-MM-DD HH:MM:SS"}}
 *
 * Nilai yang tidak dipetakan → {@see ImporGagal} (gagal-cepat), dengan nama tabel/kolom dan nilai kode saja.
 */
final class Keputusan
{
    /**
     * @param array<string, array<string, array<string, mixed>|string>> $isi
     */
    private function __construct(private readonly array $isi)
    {
    }

    public static function kosong(): self
    {
        return new self([]);
    }

    /**
     * @param array<string, mixed> $isi
     */
    public static function dariArray(array $isi): self
    {
        foreach (array_keys($isi) as $bagian) {
            if (! in_array($bagian, ['status', 'nilai', 'tanggal_nol', 'catatan'], true)) {
                throw new ImporGagal("Bagian berkas keputusan tidak dikenal: {$bagian}.");
            }
        }

        /** @var array<string, array<string, array<string, mixed>|string>> $isi */
        return new self($isi);
    }

    public static function dariBerkas(string $path): self
    {
        $teks = @file_get_contents($path);

        if ($teks === false) {
            throw new ImporGagal('Berkas keputusan tidak bisa dibaca.');
        }

        try {
            $isi = json_decode($teks, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ImporGagal('Berkas keputusan bukan JSON yang sah.');
        }

        if (! is_array($isi)) {
            throw new ImporGagal('Berkas keputusan harus objek JSON.');
        }

        /** @var array<string, mixed> $isi */
        return self::dariArray($isi);
    }

    /**
     * Status v2 untuk nilai legacy di luar domain. `$domain` = nilai yang diimpor apa adanya.
     *
     * @param list<int|string> $domain
     */
    public function status(string $tabel, mixed $nilai, array $domain): int|string
    {
        return $this->domain($tabel, 'status', $nilai, $domain);
    }

    /**
     * Nilai v2 untuk kolom ber-domain (CHECK v2) di luar domain: kolom `status` dipetakan di `status.<tabel>`, kolom
     * lain di `status.<tabel>.<kolom>` (mis. `pegawai.jenis_kelamin`, B-01/B-02 §6 #3).
     *
     * @param list<int|string> $domain
     */
    public function domain(string $tabel, string $kolom, mixed $nilai, array $domain): int|string
    {
        $kunci = Transformasi::kunciStatus($nilai);
        $label = $kolom === 'status' ? $tabel : "{$tabel}.{$kolom}";

        foreach ($domain as $d) {
            if ((string) $d === $kunci) {
                return $d;
            }
        }

        $peta = $this->isi['status'][$label] ?? [];

        if (is_array($peta) && array_key_exists($kunci, $peta)) {
            $hasil = $peta[$kunci];

            if (is_int($hasil) || is_string($hasil)) {
                return $hasil;
            }
        }

        throw new ImporGagal(($kolom === 'status' ? 'Status' : 'Nilai') . " {$label} '{$kunci}' di luar domain dan belum dipetakan di berkas keputusan.");
    }

    /**
     * Pemetaan nilai kolom tertentu (mis. `hari_libur.id_jenis_libur` NULL). `$ada` = false bila tidak ada pemetaan.
     *
     * @return array{ada: bool, nilai: mixed}
     */
    public function nilai(string $tabel, string $kolom, mixed $nilai): array
    {
        $peta  = $this->isi['nilai']["{$tabel}.{$kolom}"] ?? null;
        $kunci = Transformasi::kunciStatus($nilai);

        if (! is_array($peta) || ! array_key_exists($kunci, $peta)) {
            return ['ada' => false, 'nilai' => $nilai];
        }

        return ['ada' => true, 'nilai' => $peta[$kunci]];
    }

    /**
     * Pengganti tanggal nol untuk kolom NOT NULL (R3), atau null bila belum diputuskan.
     */
    public function tanggalNol(string $tabel, string $kolom): ?string
    {
        $v = $this->isi['tanggal_nol']["{$tabel}.{$kolom}"] ?? null;

        return is_string($v) ? $v : null;
    }
}
