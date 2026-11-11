<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use App\Libraries\Dashboard\Konten\Berita\PenyimpananLampiran;
use App\Libraries\Dashboard\Konten\Dibo\PenyimpananDibo;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * MAKE-089 M3 (Q-089-1 b) — salin berkas legacy Fase 7 ke penyimpanan privat v2 dengan kunci yang SAMA dengan hasil tier 14.
 *
 * - BACA-SAJA terhadap DB sumber legacy; tidak menulis DB mana pun. Kunci dihitung ulang dengan fungsi yang sama dengan
 *   tier 14 ({@see AturanKonten::kunciBerkas()}, {@see AturanFormDigital::berkasJawaban()}).
 * - Berkas asal dibaca dari folder salinan aplikasi legacy (`--dasar`, berisi `assets/upload/…`) lewat
 *   {@see LocalStorageAdapter} (path legacy dinormalkan, `realpath` wajib di dalam akar → traversal/symlink ditolak).
 * - Idempoten: kunci sudah ada dengan isi sama → `sudah_ada`; isi berbeda → `beda` (TIDAK ditimpa).
 * - Dilewati: popup video (`type ≠ 1`, Q9) dan baris yang diputus `"__buang"` di berkas keputusan (`<tabel>.f7`).
 * - Laporan hanya jumlah per jenis; tidak ada nama berkas (path jawaban form memuat NIP).
 */
final class BerkasKonten
{
    public const STATUS = ['disalin', 'sudah_ada', 'beda', 'hilang', 'tidak_sah', 'dilewati'];

    public function __construct(
        private readonly BaseConnection $sumber,
        private readonly LocalStorageAdapter $asal,
        private readonly LocalStorageAdapter $tujuan,
        private readonly Keputusan $keputusan,
    ) {
    }

    /**
     * @return array<string, array<string, int>> jenis → status → jumlah
     */
    public function jalankan(bool $dryRun): array
    {
        $laporan = [];

        foreach ($this->entri() as [$jenis, $path, $kunci, $lewat]) {
            $laporan[$jenis] ??= array_fill_keys(self::STATUS, 0);
            $status = $lewat ? 'dilewati' : $this->salin($path, $kunci, $dryRun);
            $laporan[$jenis][$status]++;
        }

        ksort($laporan);

        return $laporan;
    }

    private function salin(string $path, ?string $kunci, bool $dryRun): string
    {
        if ($kunci === null) {
            return 'tidak_sah';
        }

        try {
            if (! $this->asal->ada($path)) {
                return 'hilang';
            }

            $isi = $this->asal->baca($path);
        } catch (InvalidArgumentException|RuntimeException) {
            return 'tidak_sah';
        }

        if ($this->tujuan->ada($kunci)) {
            return hash_equals(hash('sha256', $this->tujuan->baca($kunci)), hash('sha256', $isi)) ? 'sudah_ada' : 'beda';
        }

        if (! $dryRun) {
            $this->tujuan->simpan($kunci, $isi);
        }

        return 'disalin';
    }

    /**
     * Daftar berkas: [jenis, path legacy, kunci v2 | null, dilewati].
     *
     * @return iterable<array{0: string, 1: string, 2: string|null, 3: bool}>
     */
    private function entri(): iterable
    {
        foreach (['popup' => 'id_popup', 'banner' => 'id_banner'] as $tabel => $pk) {
            $video = $tabel === 'popup' && $this->adaKolom($tabel, 'type');

            foreach ($this->baris($tabel, $pk, ['file', ...($video ? ['type'] : [])]) as $r) {
                $path  = Transformasi::stripslashes((string) $r['file']);
                $lewat = ($video && (int) $r['type'] !== 1) || $this->dibuang($tabel, (string) $r[$pk]);

                yield [$tabel, $path, AturanKonten::kunciBerkas($tabel, $tabel, 'file', (string) $r[$pk], $path, AturanKonten::EKSTENSI_GAMBAR), $lewat];
            }
        }

        foreach ($this->baris('news', 'id_news', ['lampiran']) as $r) {
            $path = $r['lampiran'] === null ? '' : Transformasi::stripslashes((string) $r['lampiran']);

            if (trim($path) !== '') {
                yield ['berita', $path, AturanKonten::kunciBerkas('berita', 'news', 'lampiran', (string) $r['id_news'], $path, PenyimpananLampiran::EKSTENSI), $this->dibuang('news', (string) $r['id_news'])];
            }
        }

        foreach ($this->baris('dibo', 'id_dibo', ['file_1', 'file_2', 'file_3']) as $r) {
            foreach (['file_1', 'file_2', 'file_3'] as $kolom) {
                $path = $r[$kolom] === null ? '' : Transformasi::stripslashes((string) $r[$kolom]);

                if (trim($path) !== '') {
                    yield ['dibo', $path, AturanKonten::kunciBerkas('dibo', 'dibo', $kolom, (string) $r['id_dibo'], $path, PenyimpananDibo::EKSTENSI), $this->dibuang('dibo', (string) $r['id_dibo'])];
                }
            }
        }

        $form = [];

        foreach ($this->baris('form_digital', 'id_form_digital', ['form_digital']) as $r) {
            try {
                $form[(string) $r['id_form_digital']] = AturanFormDigital::konversiSkema(json_decode(Transformasi::stripslashes((string) $r['form_digital']), true));
            } catch (KonversiGagal) {
                $form[(string) $r['id_form_digital']] = null;
            }
        }

        foreach ($this->baris('filled_form_digital', 'id_form_digital', ['nip', 'response'], ['id_form_digital', 'nip']) as $r) {
            $idForm = (string) $r['id_form_digital'];
            // Form tak terkonversi/tak ada → semua berkasnya tanpa kunci (tidak_sah), kecuali dibuang (dilewati).
            $konversi = $form[$idForm] ?? ['skema' => ['versi' => 1, 'pertanyaan' => []], 'nama' => [], 'opsi' => [], 'statis' => 0];
            $lewat    = $this->dibuang('form_digital', $idForm) || $this->dibuang('filled_form_digital', $idForm . "\x1F" . $r['nip']);

            foreach (AturanFormDigital::berkasJawaban((string) $r['response'], $konversi, $idForm, (string) $r['nip']) as [$path, $kunci]) {
                yield ['form-digital', $path, $kunci, $lewat];
            }
        }
    }

    private function dibuang(string $tabel, string $id): bool
    {
        return $this->keputusan->baris($tabel, AturanKonten::KOLOM_KEPUTUSAN, $id)['nilai'] === AturanKonten::BUANG;
    }

    private function adaKolom(string $tabel, string $kolom): bool
    {
        return $this->kueri('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND LOWER(TABLE_NAME) = LOWER(?) AND COLUMN_NAME = ?', [$this->sumber->getDatabase(), $tabel, $kolom]) !== [];
    }

    /**
     * Baca berurut kunci per batch; tabel tidak ada di sumber → kosong.
     *
     * @param list<string> $kolom
     * @param list<string> $kunci kunci urut (bawaan [$pk])
     *
     * @return iterable<array<string, mixed>>
     */
    private function baris(string $tabel, string $pk, array $kolom, array $kunci = []): iterable
    {
        $nama = $this->kueri('SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND LOWER(TABLE_NAME) = LOWER(?)', [$this->sumber->getDatabase(), $tabel])[0]['n'] ?? null;

        if ($nama === null) {
            return;
        }

        $db       = $this->sumber;
        $kunci    = $kunci === [] ? [$pk] : $kunci;
        $pilih    = implode(', ', array_map(static fn (string $k): string => $db->escapeIdentifiers($k), array_values(array_unique([...$kunci, $pk, ...$kolom]))));
        $urut     = implode(', ', array_map(static fn (string $k): string => $db->escapeIdentifiers($k), $kunci));
        $terakhir = null;

        do {
            $sql = "SELECT {$pilih} FROM " . $db->escapeIdentifiers((string) $nama)
                . ($terakhir === null ? '' : " WHERE ({$urut}) > (" . implode(', ', array_fill(0, count($kunci), '?')) . ')')
                . " ORDER BY {$urut} LIMIT 1000";
            $rows = $this->kueri($sql, $terakhir);

            foreach ($rows as $r) {
                $terakhir = array_map(static fn (string $k): mixed => $r[$k], $kunci);

                yield $r;
            }
        } while (count($rows) === 1000);
    }

    /**
     * @param list<mixed>|null $bind
     *
     * @return list<array<string, mixed>>
     */
    private function kueri(string $sql, ?array $bind = null): array
    {
        $hasil = $this->sumber->query($sql, $bind);

        if (! $hasil instanceof ResultInterface) {
            throw new ImporGagal('Galat DB saat membaca sumber berkas (kode ' . (string) ($this->sumber->error()['code'] ?? '?') . ').');
        }

        /** @var list<array<string, mixed>> */
        return $hasil->getResultArray();
    }
}
