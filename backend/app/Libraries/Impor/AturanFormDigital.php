<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use App\Exceptions\ValidationException;
use App\Libraries\Dashboard\Interaksi\FormDigital\SkemaForm;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;

/**
 * MAKE-089 M2 — impor form digital legacy (formBuilder) ke skema v2 Jalur B (Q7-18 (b), keputusan ReadOne 11-11-2026).
 *
 * - `form_digital.form_digital` legacy = `addslashes(JSON formBuilder)` (`L_announcement.php:2621`) → sesudah `stripslashes`
 *   bawaan mesin dikonversi ke `{"versi":1,"pertanyaan":[…]}` dan WAJIB lolos {@see SkemaForm::normalisasi()}.
 * - `filled_form_digital.response` legacy = `json_encode($_POST)` TANPA addslashes (`Announcement.php:509-566`) → dibaca MENTAH
 *   (`tanpaStripslashes`; stripslashes merusak `\uXXXX` diam-diam), fallback `stripslashes` hanya bila mentah tidak valid.
 *   Kunci jawaban = `name` formBuilder → id v2 hasil konversi; berkas `response.file[<name>]` → `{berkas, nama, ukuran: null}`
 *   dengan kunci `form-digital/<id_form_digital>/<hex32>.<ext>` (syarat Jalur B; id form v2 = id legacy).
 * - Form/jawaban yang tidak bisa dikonversi = AMBIGU, pola F-02: `form_digital.f7` / `filled_form_digital.f7` = `"__buang"`;
 *   tanpa putusan → gagal dengan jumlah per kode. Anak form yang dibuang ikut dibuang.
 * - Isi jawaban tidak pernah masuk pesan/laporan; laporan hanya kode + jumlah.
 */
final class AturanFormDigital
{
    public const A_SKEMA = 'MAKE-089 form tidak bisa dikonversi ke SkemaForm v2';

    public const A_RESPONS = 'MAKE-089 response tidak valid';

    public const A_BERKAS_JAWABAN = 'MAKE-089 berkas jawaban tidak sah (path/ekstensi)';

    public const KOREKSI_STATIS = 'MAKE-089 elemen statis formBuilder tidak dibawa';

    public const KOREKSI_DI_LUAR_OPSI = 'MAKE-089 jawaban di luar opsi (disimpan apa adanya)';

    public const KOREKSI_KUNCI_LAIN = 'MAKE-089 kunci jawaban tanpa pertanyaan (tidak dibawa)';

    private const STATIS = ['header', 'paragraph', 'hidden', 'button'];

    /** Ekstensi berkas jawaban v2 (SkemaForm::TIPE_BERKAS); `jpeg` legacy dinormalkan ke `jpg`. */
    private const EKSTENSI_JAWABAN = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];

    /**
     * Hasil konversi per id form (sumber): skema kanonik + peta nama legacy → id v2 + peta nilai opsi.
     *
     * @var array<string, array{skema: array{versi: int, pertanyaan: list<array<string, mixed>>}, nama: array<string, string>, opsi: array<string, array<string, string>>, statis: int}|string>
     */
    private array $cache = [];

    /** @var array<string, string>|null id form → kode buang (sesudah putusan) */
    private ?array $formBuang = null;

    // ------------------------------------------------------------------ konverter murni

    /**
     * formBuilder legacy (array) → skema v2 kanonik. Melempar {@see KonversiGagal} bila tidak bisa dikonversi.
     *
     * @param mixed $legacy array field formBuilder
     *
     * @return array{skema: array{versi: int, pertanyaan: list<array<string, mixed>>}, nama: array<string, string>, opsi: array<string, array<string, string>>, statis: int}
     */
    public static function konversiSkema(mixed $legacy): array
    {
        if (! is_array($legacy) || ! array_is_list($legacy)) {
            throw new KonversiGagal('bukan daftar field formBuilder');
        }

        $pertanyaan = [];
        $nama       = [];
        $opsiPeta   = [];
        $statis     = 0;
        $idDipakai  = [];

        foreach ($legacy as $i => $f) {
            if (! is_array($f)) {
                throw new KonversiGagal('field bukan objek');
            }

            $tipeLegacy = is_string($f['type'] ?? null) ? $f['type'] : '';

            if (in_array($tipeLegacy, self::STATIS, true)) {
                $statis++;

                continue;
            }

            $namaLegacy = is_string($f['name'] ?? null) ? $f['name'] : '';
            $id         = self::idUnik(self::normalisasiId($namaLegacy, $i + 1), $idDipakai);
            $label      = self::polos($f['label'] ?? '');
            $label      = $label === '' ? ($namaLegacy !== '' ? $namaLegacy : $id) : $label;
            $q          = ['id' => $id, 'label' => mb_substr($label, 0, 500), 'wajib' => self::benar($f['required'] ?? false)];
            $bantuan    = self::polos($f['description'] ?? '');

            if ($bantuan !== '') {
                $q['bantuan'] = mb_substr($bantuan, 0, 500);
            }

            switch ($tipeLegacy) {
                case 'text':
                    $maks = self::bulat($f['maxlength'] ?? null);

                    if ($maks !== null && $maks > 255) {
                        $q += ['tipe' => 'paragraf', 'maks' => min($maks, 5000)];
                    } else {
                        $q += ['tipe' => 'teks', 'maks' => $maks !== null && $maks >= 1 ? $maks : 255];
                    }

                    break;

                case 'textarea':
                    $maks = self::bulat($f['maxlength'] ?? null);
                    $q += ['tipe' => 'paragraf', 'maks' => $maks !== null && $maks >= 1 ? min($maks, 5000) : 5000];

                    break;

                case 'radio-group':
                case 'select':
                case 'checkbox-group':
                    $ganda         = $tipeLegacy === 'checkbox-group' || ($tipeLegacy === 'select' && self::benar($f['multiple'] ?? false));
                    [$opsi, $peta] = self::opsi($f['values'] ?? null);
                    $q += ['tipe' => $ganda ? 'pilihan_ganda' : 'pilihan_tunggal', 'opsi' => $opsi];
                    $opsiPeta[$id] = $peta;

                    break;

                case 'date':
                    $q['tipe'] = 'tanggal';

                    break;

                case 'number':
                    $q['tipe'] = 'angka';
                    $min       = self::angka($f['min'] ?? null);
                    $maks      = self::angka($f['max'] ?? null);

                    if ($min !== null && ($maks === null || $min <= $maks)) {
                        $q['min'] = $min;
                    }

                    if ($maks !== null && ($min === null || $min <= $maks)) {
                        $q['maks_angka'] = $maks;
                    }

                    break;

                case 'file':
                    $q += ['tipe' => 'berkas', 'berkas' => ['tipe' => SkemaForm::TIPE_BERKAS, 'maks_mb' => SkemaForm::MAKS_MB_BERKAS]];

                    break;

                default:
                    throw new KonversiGagal('tipe formBuilder tidak dikenal');
            }

            $pertanyaan[] = $q;

            if ($namaLegacy !== '') {
                $nama[$namaLegacy] = $id;
            }
        }

        try {
            $skema = SkemaForm::normalisasi(['versi' => SkemaForm::VERSI, 'pertanyaan' => $pertanyaan]);
        } catch (ValidationException) {
            throw new KonversiGagal('skema tidak lolos SkemaForm::normalisasi');
        }

        return ['skema' => $skema, 'nama' => $nama, 'opsi' => $opsiPeta, 'statis' => $statis];
    }

    /**
     * Jawaban legacy → JSON jawaban v2. Melempar {@see KonversiGagal} bila response/berkas tidak sah.
     *
     * @param array{skema: array{versi: int, pertanyaan: list<array<string, mixed>>}, nama: array<string, string>, opsi: array<string, array<string, string>>, statis: int} $konversi
     *
     * @return array{jawaban: array<string, mixed>, koreksi: list<string>}
     */
    public static function konversiJawaban(string $responsMentah, array $konversi, int|string $idForm, string $nip): array
    {
        $respons = json_decode($responsMentah, true);

        if (! is_array($respons)) {
            $respons = json_decode(Transformasi::stripslashes($responsMentah), true);
        }

        if (! is_array($respons) || ($respons !== [] && array_is_list($respons))) {
            throw new KonversiGagal('response bukan objek JSON');
        }

        $berkas = is_array($respons['file'] ?? null) ? $respons['file'] : [];
        unset($respons['file']);

        $perId   = array_column($konversi['skema']['pertanyaan'], null, 'id');
        $jawaban = [];
        $koreksi = [];

        foreach ($respons as $namaLegacy => $nilai) {
            $id = $konversi['nama'][(string) $namaLegacy] ?? null;

            if ($id === null || ! isset($perId[$id]) || $perId[$id]['tipe'] === 'berkas') {
                $koreksi[] = self::KOREKSI_KUNCI_LAIN;

                continue;
            }

            $p     = $perId[$id];
            $nilai = self::nilaiJawaban($p, $nilai, $konversi['opsi'][$id] ?? [], $koreksi);

            if ($nilai !== null && $nilai !== '' && $nilai !== []) {
                $jawaban[$id] = $nilai;
            }
        }

        foreach ($berkas as $namaLegacy => $path) {
            $id = $konversi['nama'][(string) $namaLegacy] ?? null;

            if ($id === null || ($perId[$id]['tipe'] ?? null) !== 'berkas') {
                $koreksi[] = self::KOREKSI_KUNCI_LAIN;

                continue;
            }

            $kunci = self::kunciBerkasJawaban($idForm, $nip, $id, (string) $path)
                ?? throw new KonversiGagal('berkas jawaban tidak sah');
            $jawaban[$id] = ['berkas' => $kunci, 'nama' => basename((string) $path), 'ukuran' => null];
        }

        return ['jawaban' => $jawaban, 'koreksi' => $koreksi];
    }

    /**
     * Pasangan [path legacy, kunci v2 | null] untuk setiap berkas jawaban (dipakai `impor:berkas-konten`, Q-089-1 b). Kunci
     * sama dengan {@see self::konversiJawaban()}; response tidak valid → daftar kosong.
     *
     * @param array{skema: array{versi: int, pertanyaan: list<array<string, mixed>>}, nama: array<string, string>, opsi: array<string, array<string, string>>, statis: int} $konversi
     *
     * @return list<array{0: string, 1: string|null}>
     */
    public static function berkasJawaban(string $responsMentah, array $konversi, int|string $idForm, string $nip): array
    {
        $respons = json_decode($responsMentah, true);

        if (! is_array($respons)) {
            $respons = json_decode(Transformasi::stripslashes($responsMentah), true);
        }

        $berkas = is_array($respons) && is_array($respons['file'] ?? null) ? $respons['file'] : [];
        $tipe   = array_column($konversi['skema']['pertanyaan'], 'tipe', 'id');
        $hasil  = [];

        foreach ($berkas as $namaLegacy => $path) {
            $id      = $konversi['nama'][(string) $namaLegacy] ?? null;
            $id      = $id !== null && ($tipe[$id] ?? null) === 'berkas' ? $id : null;
            $path    = is_scalar($path) ? (string) $path : '';
            $hasil[] = [$path, $id === null ? null : self::kunciBerkasJawaban($idForm, $nip, $id, $path)];
        }

        return $hasil;
    }

    /**
     * Kunci berkas jawaban `form-digital/<id_form>/<hex32>.<ext>` (syarat Jalur B), deterministik per path legacy.
     */
    public static function kunciBerkasJawaban(int|string $idForm, string $nip, string $idPertanyaan, string $path): ?string
    {
        if (! AturanKonten::pathSah($path)) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($ext, self::EKSTENSI_JAWABAN, true)) {
            return null;
        }

        $ext = $ext === 'jpeg' ? 'jpg' : $ext;

        return 'form-digital/' . (int) $idForm . '/' . substr(hash('sha256', "MAKE-089|filled_form_digital|{$idForm}|{$nip}|{$idPertanyaan}|{$path}"), 0, 32) . '.' . $ext;
    }

    // ------------------------------------------------------------------ praPindai / khusus

    /**
     * praPindai `form_digital`: form yang tidak bisa dikonversi = ambigu (putusan `form_digital.f7`).
     *
     * @return array<string, string>
     */
    public function praPindaiForm(BaseConnection $db, string $namaSumber, Keputusan $k): array
    {
        return $this->formDibuang($db, $namaSumber, $k);
    }

    /**
     * praPindai anak form (`user_form_digital`, turunan filled): baris yang formnya dibuang ikut dibuang. Kunci baris =
     * `<id_form_digital>\x1F<nip>` (kunciUrut).
     *
     * @return Closure(BaseConnection, string, Keputusan): array<int|string, string>
     */
    public function praPindaiAnak(): Closure
    {
        return function (BaseConnection $db, string $namaSumber, Keputusan $k): array {
            $buang = $this->formDibuang($db, self::namaTabel($db, 'form_digital'), $k);

            return $buang === [] ? [] : $this->anakDariForm($db, $namaSumber, array_keys($buang));
        };
    }

    /**
     * praPindai `filled_form_digital`: form dibuang → ikut dibuang; response/berkas tidak sah = ambigu (putusan per
     * `<id_form>\x1F<nip>` di `filled_form_digital.f7`).
     *
     * @return array<string, string>
     */
    public function praPindaiJawaban(BaseConnection $db, string $namaSumber, Keputusan $k): array
    {
        $buangForm = $this->formDibuang($db, self::namaTabel($db, 'form_digital'), $k);
        $hasil     = [];
        $ambigu    = [];

        foreach (self::bacaJawaban($db, $namaSumber) as $r) {
            $kunci = $r['id_form_digital'] . "\x1F" . $r['nip'];

            if (isset($buangForm[(string) $r['id_form_digital']])) {
                $hasil[$kunci] = AturanKonten::IKUT_INDUK;

                continue;
            }

            $konversi = $this->cache[(string) $r['id_form_digital']] ?? null;

            if (! is_array($konversi)) {
                continue; // form tidak ada di sumber → rujukan yatim ditangani mesin (gagal)
            }

            try {
                self::konversiJawaban((string) $r['response'], $konversi, (string) $r['id_form_digital'], (string) $r['nip']);
            } catch (KonversiGagal $e) {
                $ambigu[$kunci] = str_contains($e->getMessage(), 'berkas') ? self::A_BERKAS_JAWABAN : self::A_RESPONS;
            }
        }

        return $hasil + self::putus($ambigu, 'filled_form_digital', $k);
    }

    /**
     * khusus `form_digital`: kolom skema diganti skema v2 kanonik (JSON).
     *
     * @return Closure(array<string, mixed>, Keputusan): array<string, mixed>
     */
    public function khususForm(): Closure
    {
        return function (array $baris, Keputusan $k): array {
            $konversi = $this->cache[(string) $baris['id_form_digital']] ?? null;

            if (! is_array($konversi)) {
                $konversi = self::konversiSkema(json_decode((string) $baris['form_digital'], true));
            }

            $baris['form_digital'] = (string) json_encode($konversi['skema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            for ($i = 0; $i < $konversi['statis']; $i++) {
                $baris['__koreksi'][] = self::KOREKSI_STATIS;
            }

            foreach (['target_uns', 'target_peg', 'excl_peg'] as $kolom) {
                if (array_key_exists($kolom, $baris) && is_string($baris[$kolom]) && trim($baris[$kolom]) === '') {
                    $baris[$kolom] = null;
                }
            }

            return $baris;
        };
    }

    /**
     * khusus `filled_form_digital`: response → JSON jawaban v2.
     *
     * @return Closure(array<string, mixed>, Keputusan): array<string, mixed>
     */
    public function khususJawaban(): Closure
    {
        return function (array $baris, Keputusan $k): array {
            $konversi = $this->cache[(string) $baris['id_form_digital']] ?? null;

            if (! is_array($konversi)) {
                throw new ImporGagal('filled_form_digital: skema form belum dikonversi (bug alat impor).');
            }

            $hasil             = self::konversiJawaban((string) $baris['response'], $konversi, (string) $baris['id_form_digital'], (string) $baris['nip']);
            $baris['response'] = (string) json_encode($hasil['jawaban'] === [] ? new \stdClass() : $hasil['jawaban'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            foreach ($hasil['koreksi'] as $kode) {
                $baris['__koreksi'][] = $kode;
            }

            return $baris;
        };
    }

    // ------------------------------------------------------------------ internal

    /**
     * Konversi semua form sumber (cache) lalu terapkan putusan. Hasil: id form → kode buang.
     *
     * @return array<string, string>
     */
    private function formDibuang(BaseConnection $db, string $namaSumber, Keputusan $k): array
    {
        if ($this->formBuang !== null) {
            return $this->formBuang;
        }

        $ambigu = [];
        $hasil  = self::kueri($db, 'SELECT ' . $db->escapeIdentifiers('id_form_digital') . ' AS i, ' . $db->escapeIdentifiers('form_digital') . ' AS f FROM ' . $db->escapeIdentifiers($namaSumber));

        foreach ($hasil as $r) {
            $id = (string) $r['i'];

            try {
                $this->cache[$id] = self::konversiSkema(json_decode(Transformasi::stripslashes((string) $r['f']), true));
            } catch (KonversiGagal) {
                $this->cache[$id] = self::A_SKEMA;
                $ambigu[$id]      = self::A_SKEMA;
            }
        }

        return $this->formBuang = self::putus($ambigu, 'form_digital', $k);
    }

    /**
     * @param list<int|string> $idForm
     *
     * @return array<string, string>
     */
    private function anakDariForm(BaseConnection $db, string $namaSumber, array $idForm): array
    {
        $buang = [];

        foreach (array_chunk($idForm, 500) as $potong) {
            $sql = 'SELECT ' . $db->escapeIdentifiers('id_form_digital') . ' AS i, ' . $db->escapeIdentifiers('nip') . ' AS n FROM ' . $db->escapeIdentifiers($namaSumber)
                . ' WHERE ' . $db->escapeIdentifiers('id_form_digital') . ' IN (' . implode(', ', array_map('intval', $potong)) . ')';

            foreach (self::kueri($db, $sql) as $r) {
                $buang[$r['i'] . "\x1F" . $r['n']] = AturanKonten::IKUT_INDUK;
            }
        }

        return $buang;
    }

    /**
     * @param array<string, mixed>  $p
     * @param array<string, string> $peta     nilai legacy → label opsi v2
     * @param list<string>          $koreksi
     */
    private static function nilaiJawaban(array $p, mixed $nilai, array $peta, array &$koreksi): mixed
    {
        switch ($p['tipe']) {
            case 'pilihan_tunggal':
            case 'pilihan_ganda':
                $daftar = is_array($nilai) ? array_values($nilai) : [$nilai];
                $hasil  = [];

                foreach ($daftar as $v) {
                    if (! is_scalar($v) || (string) $v === '') {
                        continue;
                    }

                    $label = $peta[(string) $v] ?? null;

                    if ($label === null) {
                        $koreksi[] = self::KOREKSI_DI_LUAR_OPSI;
                        $label     = (string) $v;
                    }

                    $hasil[] = $label;
                }

                $hasil = array_values(array_unique($hasil));

                return $p['tipe'] === 'pilihan_tunggal' ? ($hasil[0] ?? null) : $hasil;

            case 'angka':
                if (is_string($nilai) && is_numeric(trim($nilai))) {
                    return trim($nilai) + 0;
                }

                return is_int($nilai) || is_float($nilai) ? $nilai : (is_scalar($nilai) ? trim((string) $nilai) : null);

            default: // teks, paragraf, tanggal
                if (is_array($nilai)) {
                    $nilai = implode(', ', array_map('strval', array_filter($nilai, 'is_scalar')));
                }

                return is_scalar($nilai) ? trim((string) $nilai) : null;
        }
    }

    /**
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private static function opsi(mixed $values): array
    {
        if (! is_array($values)) {
            throw new KonversiGagal('pilihan tanpa opsi');
        }

        $opsi = [];
        $peta = [];

        foreach ($values as $v) {
            $label = is_array($v) ? self::polos($v['label'] ?? '') : self::polos($v);
            $nilai = is_array($v) && is_scalar($v['value'] ?? null) ? (string) $v['value'] : $label;
            $label = mb_substr($label, 0, 200);

            if ($label === '') {
                continue;
            }

            if (! in_array($label, $opsi, true)) {
                $opsi[] = $label;
            }

            $peta[$nilai] = $label;
            $peta[$label] = $label;
        }

        if ($opsi === [] || count($opsi) > SkemaForm::MAKS_OPSI) {
            throw new KonversiGagal('opsi kosong atau terlalu banyak');
        }

        return [$opsi, $peta];
    }

    private static function normalisasiId(string $nama, int $urut): string
    {
        $id = strtolower((string) preg_replace('/[^a-z0-9_]+/i', '_', $nama));
        $id = trim((string) preg_replace('/_+/', '_', $id), '_');

        return $id === '' ? 'q' . $urut : substr($id, 0, 40);
    }

    /**
     * @param array<string, true> $dipakai
     */
    private static function idUnik(string $id, array &$dipakai): string
    {
        $calon = $id;

        for ($n = 2; isset($dipakai[$calon]); $n++) {
            $akhiran = '_' . $n;
            $calon   = substr($id, 0, 40 - strlen($akhiran)) . $akhiran;
        }

        $dipakai[$calon] = true;

        return $calon;
    }

    private static function polos(mixed $v): string
    {
        if (! is_scalar($v)) {
            return '';
        }

        $teks = html_entity_decode(strip_tags((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $teks));
    }

    private static function benar(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }

    private static function bulat(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    private static function angka(mixed $v): int|float|null
    {
        return is_numeric($v) ? $v + 0 : null;
    }

    /**
     * @param array<string, string> $ambigu
     *
     * @return array<string, string>
     */
    private static function putus(array $ambigu, string $tabel, Keputusan $k): array
    {
        $buang = [];
        $belum = [];

        foreach ($ambigu as $id => $kode) {
            $p = $k->baris($tabel, AturanKonten::KOLOM_KEPUTUSAN, (string) $id);

            if (! $p['ada']) {
                $belum[$kode] = ($belum[$kode] ?? 0) + 1;
            } elseif ($p['nilai'] === AturanKonten::BUANG) {
                $buang[(string) $id] = AturanKonten::DIBUANG_KEPUTUSAN;
            } else {
                throw new ImporGagal("{$tabel}: keputusan {$tabel}." . AturanKonten::KOLOM_KEPUTUSAN . ' hanya boleh "' . AturanKonten::BUANG . '" (Q-089-4).');
            }
        }

        if ($belum !== []) {
            ksort($belum);
            $rincian = implode(', ', array_map(static fn (string $kode, int $n): string => "{$kode}: {$n}", array_keys($belum), $belum));

            throw new ImporGagal("{$tabel}: " . array_sum($belum) . " baris ambigu belum diputuskan ({$rincian}); putuskan per id di berkas keputusan bagian baris {$tabel}." . AturanKonten::KOLOM_KEPUTUSAN . ' (jangan ditebak).');
        }

        return $buang;
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private static function bacaJawaban(BaseConnection $db, string $namaSumber): iterable
    {
        $terakhir = null;
        $kolom    = implode(', ', array_map(static fn (string $k): string => $db->escapeIdentifiers($k), ['id_form_digital', 'nip', 'response']));

        do {
            $sql = "SELECT {$kolom} FROM " . $db->escapeIdentifiers($namaSumber)
                . ($terakhir === null ? '' : ' WHERE (' . $db->escapeIdentifiers('id_form_digital') . ', ' . $db->escapeIdentifiers('nip') . ') > (?, ?)')
                . ' ORDER BY ' . $db->escapeIdentifiers('id_form_digital') . ', ' . $db->escapeIdentifiers('nip') . ' LIMIT 1000';
            $baris = self::kueri($db, $sql, $terakhir);

            foreach ($baris as $r) {
                $terakhir = [$r['id_form_digital'], $r['nip']];

                yield $r;
            }
        } while (count($baris) === 1000);
    }

    private static function namaTabel(BaseConnection $db, string $nama): string
    {
        $rows = self::kueri($db, 'SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND LOWER(TABLE_NAME) = LOWER(?)', [$db->getDatabase(), $nama]);

        if (count($rows) !== 1) {
            throw new ImporGagal("Tabel sumber {$nama} tidak ada.");
        }

        return (string) $rows[0]['n'];
    }

    /**
     * @param list<mixed>|null $bind
     *
     * @return list<array<string, mixed>>
     */
    private static function kueri(BaseConnection $db, string $sql, ?array $bind = null): array
    {
        $hasil = $db->query($sql, $bind);

        if (! $hasil instanceof ResultInterface) {
            throw new ImporGagal('Galat DB saat pra-pindai form digital (kode ' . (string) ($db->error()['code'] ?? '?') . ').');
        }

        /** @var list<array<string, mixed>> */
        return $hasil->getResultArray();
    }
}
