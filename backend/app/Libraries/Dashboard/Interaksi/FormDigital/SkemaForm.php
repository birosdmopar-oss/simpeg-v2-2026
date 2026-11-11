<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Interaksi\FormDigital;

use App\Exceptions\ValidationException;

/**
 * Skema form digital v2 (F-06, PRD Fase 7 §5.7, Q7-18) yang disimpan di `form_digital.form_digital`:
 *
 *     {"versi":1,"pertanyaan":[{"id":"q1","tipe":"teks","label":"…","wajib":true,"bantuan":"…", …}]}
 *
 * Tipe: teks (`maks` ≤ 255), paragraf (`maks` ≤ 5000), pilihan_tunggal / pilihan_ganda (`opsi` 1..50 butir unik ≤ 200
 * karakter), tanggal, angka (`min`/`maks_angka` opsional), berkas (`berkas.tipe` ⊆ daftar, `berkas.maks_mb` 1..5).
 * Label/bantuan/opsi = teks polos (tanpa tag HTML; FE merender sebagai teks — menutup XSS formBuilder legacy).
 * `id` pertanyaan dibuat server bila kosong dan dipertahankan saat edit (jawaban merujuknya).
 *
 * {@see self::normalisasi()} memvalidasi masukan klien (422 per field `form.pertanyaan.<i>.<kunci>`) dan mengembalikan bentuk
 * kanonik; {@see self::sama()} membandingkan dua skema kanonik (kunci Q7-25).
 */
final class SkemaForm
{
    public const VERSI = 1;

    public const TIPE = ['teks', 'paragraf', 'pilihan_tunggal', 'pilihan_ganda', 'tanggal', 'angka', 'berkas'];

    public const TIPE_BERKAS = ['pdf', 'jpg', 'png', 'doc', 'docx', 'xls', 'xlsx'];

    public const MAKS_PERTANYAAN = 100;

    public const MAKS_OPSI = 50;

    public const MAKS_MB_BERKAS = 5;

    private const MAKS_TEKS = ['teks' => 255, 'paragraf' => 5000];

    private function __construct()
    {
    }

    /**
     * @param mixed $masukan array pertanyaan mentah (atau objek {versi, pertanyaan})
     *
     * @return array{versi: int, pertanyaan: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public static function normalisasi(mixed $masukan): array
    {
        if (is_array($masukan) && array_key_exists('pertanyaan', $masukan)) {
            $masukan = $masukan['pertanyaan'];
        }

        if (! is_array($masukan) || ! array_is_list($masukan) || $masukan === []) {
            throw ValidationException::forField('form.pertanyaan', 'Form wajib memiliki minimal 1 pertanyaan.');
        }

        if (count($masukan) > self::MAKS_PERTANYAAN) {
            throw ValidationException::forField('form.pertanyaan', 'Form maksimal ' . self::MAKS_PERTANYAAN . ' pertanyaan.');
        }

        $galat     = [];
        $hasil     = [];
        $idDipakai = [];

        foreach ($masukan as $i => $p) {
            $f = "form.pertanyaan.{$i}";

            if (! is_array($p)) {
                $galat[$f] = ['Pertanyaan tidak valid.'];

                continue;
            }

            $tipe = is_string($p['tipe'] ?? null) ? $p['tipe'] : '';

            if (! in_array($tipe, self::TIPE, true)) {
                $galat["{$f}.tipe"] = ['Tipe pertanyaan tidak dikenal.'];

                continue;
            }

            $label = self::teks($p['label'] ?? null);

            if ($label === '' || mb_strlen($label) > 500) {
                $galat["{$f}.label"] = ['Label wajib diisi, maksimal 500 karakter.'];
            } elseif (self::berTag($label)) {
                $galat["{$f}.label"] = ['Label harus teks polos (tanpa HTML).'];
            }

            $bantuan = self::teks($p['bantuan'] ?? null);

            if (mb_strlen($bantuan) > 500 || self::berTag($bantuan)) {
                $galat["{$f}.bantuan"] = ['Bantuan maksimal 500 karakter, teks polos.'];
            }

            $id = is_string($p['id'] ?? null) ? trim($p['id']) : '';

            if ($id !== '' && preg_match('/^[a-z0-9_]{1,40}\z/', $id) !== 1) {
                $galat["{$f}.id"] = ['Id pertanyaan hanya huruf kecil, angka, garis bawah (1-40).'];
            } elseif ($id !== '' && isset($idDipakai[$id])) {
                $galat["{$f}.id"] = ['Id pertanyaan ganda.'];
            }

            $q = ['id' => $id, 'tipe' => $tipe, 'label' => $label, 'wajib' => ($p['wajib'] ?? false) === true];

            if ($bantuan !== '') {
                $q['bantuan'] = $bantuan;
            }

            $q += self::aturanTipe($tipe, $p, $f, $galat);

            if ($id !== '') {
                $idDipakai[$id] = true;
            }

            $hasil[] = $q;
        }

        if ($galat !== []) {
            throw new ValidationException('Skema form tidak valid.', $galat);
        }

        // Id kosong → q<n> pertama yang belum dipakai (stabil: id yang sudah ada tidak berubah).
        $n = 1;

        foreach ($hasil as &$q) {
            if ($q['id'] === '') {
                while (isset($idDipakai['q' . $n])) {
                    $n++;
                }

                $q['id']             = 'q' . $n;
                $idDipakai['q' . $n] = true;
            }
        }
        unset($q);

        return ['versi' => self::VERSI, 'pertanyaan' => $hasil];
    }

    /**
     * Skema tersimpan (JSON) → array kanonik. JSON rusak atau bentuk lain → skema kosong (tidak dilempar: data lama).
     *
     * @return array{versi: int, pertanyaan: list<array<string, mixed>>}
     */
    public static function dariJson(string $json): array
    {
        $d = json_decode($json, true);

        if (! is_array($d) || ! isset($d['pertanyaan']) || ! is_array($d['pertanyaan']) || ! array_is_list($d['pertanyaan'])) {
            return ['versi' => self::VERSI, 'pertanyaan' => []];
        }

        /** @var list<array<string, mixed>> $p */
        $p = array_values(array_filter($d['pertanyaan'], 'is_array'));

        return ['versi' => (int) ($d['versi'] ?? self::VERSI), 'pertanyaan' => $p];
    }

    /**
     * @param array{versi: int, pertanyaan: list<array<string, mixed>>} $skema
     */
    public static function keJson(array $skema): string
    {
        return (string) json_encode($skema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Dua skema kanonik sama (Q7-25: form yang sudah dijawab tidak boleh berubah pertanyaannya).
     *
     * @param array{versi: int, pertanyaan: list<array<string, mixed>>} $a
     * @param array{versi: int, pertanyaan: list<array<string, mixed>>} $b
     */
    public static function sama(array $a, array $b): bool
    {
        return self::keJson(self::urutkanKunci($a)) === self::keJson(self::urutkanKunci($b));
    }

    /**
     * @param array<string, mixed>       $p
     * @param array<string, list<string>> $galat
     *
     * @return array<string, mixed>
     */
    private static function aturanTipe(string $tipe, array $p, string $f, array &$galat): array
    {
        switch ($tipe) {
            case 'teks':
            case 'paragraf':
                $batas = self::MAKS_TEKS[$tipe];
                $maks  = $p['maks'] ?? $batas;

                if (! is_int($maks) || $maks < 1 || $maks > $batas) {
                    $galat["{$f}.maks"] = ["Panjang maksimal 1-{$batas}."];

                    return [];
                }

                return ['maks' => $maks];

            case 'pilihan_tunggal':
            case 'pilihan_ganda':
                $opsi = $p['opsi'] ?? null;

                if (! is_array($opsi) || ! array_is_list($opsi) || $opsi === [] || count($opsi) > self::MAKS_OPSI) {
                    $galat["{$f}.opsi"] = ['Opsi wajib 1-' . self::MAKS_OPSI . ' butir.'];

                    return [];
                }

                $bersih = [];

                foreach ($opsi as $o) {
                    $o = self::teks($o);

                    if ($o === '' || mb_strlen($o) > 200 || self::berTag($o)) {
                        $galat["{$f}.opsi"] = ['Setiap opsi wajib diisi, maksimal 200 karakter, teks polos.'];

                        return [];
                    }

                    $bersih[] = $o;
                }

                if (count(array_unique($bersih)) !== count($bersih)) {
                    $galat["{$f}.opsi"] = ['Opsi tidak boleh ganda.'];

                    return [];
                }

                return ['opsi' => $bersih];

            case 'angka':
                $hasil = [];

                foreach (['min', 'maks_angka'] as $k) {
                    if (! array_key_exists($k, $p) || $p[$k] === null) {
                        continue;
                    }

                    if (! is_int($p[$k]) && ! is_float($p[$k])) {
                        $galat["{$f}.{$k}"] = ['Batas angka harus berupa angka.'];

                        return [];
                    }

                    $hasil[$k] = $p[$k];
                }

                if (isset($hasil['min'], $hasil['maks_angka']) && $hasil['min'] > $hasil['maks_angka']) {
                    $galat["{$f}.maks_angka"] = ['Batas atas harus ≥ batas bawah.'];

                    return [];
                }

                return $hasil;

            case 'berkas':
                $b    = is_array($p['berkas'] ?? null) ? $p['berkas'] : [];
                $jens = $b['tipe'] ?? self::TIPE_BERKAS;
                $mb   = $b['maks_mb'] ?? self::MAKS_MB_BERKAS;

                if (! is_array($jens) || ! array_is_list($jens) || $jens === [] || array_diff($jens, self::TIPE_BERKAS) !== []) {
                    $galat["{$f}.berkas.tipe"] = ['Jenis berkas hanya: ' . implode(', ', self::TIPE_BERKAS) . '.'];

                    return [];
                }

                if (! is_int($mb) || $mb < 1 || $mb > self::MAKS_MB_BERKAS) {
                    $galat["{$f}.berkas.maks_mb"] = ['Ukuran maksimal 1-' . self::MAKS_MB_BERKAS . ' MB.'];

                    return [];
                }

                $jens = array_values(array_unique($jens));
                sort($jens);

                return ['berkas' => ['tipe' => $jens, 'maks_mb' => $mb]];

            default: // tanggal
                return [];
        }
    }

    private static function teks(mixed $v): string
    {
        return is_string($v) ? trim($v) : '';
    }

    private static function berTag(string $v): bool
    {
        return $v !== strip_tags($v);
    }

    /**
     * @param array<array-key, mixed> $v
     *
     * @return array<array-key, mixed>
     */
    private static function urutkanKunci(array $v): array
    {
        if (! array_is_list($v)) {
            ksort($v);
        }

        foreach ($v as $k => $isi) {
            if (is_array($isi)) {
                $v[$k] = self::urutkanKunci($isi);
            }
        }

        return $v;
    }
}
