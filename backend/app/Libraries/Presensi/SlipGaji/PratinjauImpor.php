<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\SlipGaji;

use App\Constants\Role;
use InvalidArgumentException;

/**
 * CR-027 (K-CR027-10) — pratinjau impor slip gaji tanpa I/O: urai sheet mentah, gabungkan GPP + TK per kunci
 * NIP + periode, beri status `valid`/`duplikat`/`galat`, dan rencana commit (I-1..I-12, M-1..M-6).
 *
 * Data DB (peta pegawai, kunci yang sudah ada) diterima sebagai argumen; service D-10 yang membacanya, menulis log
 * ketidakcocokan nama satu kali per pratinjau, dan menyimpan pratinjau di server untuk commit. [K] alur
 * `libraries/hr/Lsl_gaji.php:740-1016`.
 */
final class PratinjauImpor
{
    public const STATUS_VALID = 'valid';

    public const STATUS_DUPLIKAT = 'duplikat';

    public const STATUS_GALAT = 'galat';

    public const RENCANA_SISIP = 'sisip';

    public const RENCANA_PERBARUI = 'perbarui';

    public const RENCANA_LEWATI = 'lewati';

    public const RENCANA_GAGAL = 'gagal';

    private function __construct()
    {
    }

    /**
     * Urai satu sheet mentah. `$baris` = nomor baris berkas => nilai mentah sel urut kolom; elemen pertama = header
     * (nomor 1), data pertama bernomor 2 (keluaran pembaca D-10, `formatData = false`).
     *
     * Gagal: `sheet_kosong` (tanpa baris data yang berisi), atau kegagalan header dari {@see KolomGaji::petakanHeader()}.
     * Baris yang seluruh selnya kosong dilewati tanpa info; baris tanpa NIP yang berisi nilai lain dilewati dan dicatat di
     * `baris_tanpa_nip` (I-4, PERBAIKI: legacy melewatinya diam-diam).
     *
     * `nilai` = `array{baris: list<BarisSheet>, baris_tanpa_nip: list<int>, kolom_diabaikan: list<string>}`.
     *
     * @param array<int, array<int, mixed>> $baris
     */
    public static function uraiSheet(array $baris, string $sheet): HasilSlip
    {
        KolomGaji::sumber($sheet);

        $kosong = HasilSlip::gagal(
            'sheet_kosong',
            "Sheet {$sheet} kosong atau tidak memiliki baris data.",
            ['sheet' => $sheet],
        );

        if ($baris === []) {
            return $kosong;
        }

        $nomorHeader = array_key_first($baris);
        $peta        = KolomGaji::petakanHeader($baris[$nomorHeader], $sheet);

        if (! $peta->valid) {
            return $peta;
        }

        /** @var array{kolom: array<string, int>, nama: ?int, diabaikan: list<string>} $p */
        $p = $peta->nilai;

        $hasil    = [];
        $tanpaNip = [];
        $adaIsi   = false;

        foreach ($baris as $nomor => $sel) {
            if ($nomor === $nomorHeader) {
                continue;
            }

            if (array_filter($sel, static fn (mixed $nilai): bool => ! NilaiSel::kosong($nilai)) === []) {
                continue;
            }

            $adaIsi = true;
            $nilai  = [];

            foreach ($p['kolom'] as $nama => $indeks) {
                $nilai[$nama] = $sel[$indeks] ?? null;
            }

            if ($p['nama'] !== null) {
                $nilai[KolomGaji::KOLOM_NAMA] = $sel[$p['nama']] ?? null;
            }

            $uraian = BarisImpor::urai($nilai, $sheet, $nomor);

            if ($uraian === null) {
                $tanpaNip[] = $nomor;
            } else {
                $hasil[] = $uraian;
            }
        }

        if (! $adaIsi) {
            return $kosong;
        }

        $info = [];

        if ($tanpaNip !== []) {
            $info[] = 'baris tanpa NIP dilewati: ' . implode(', ', $tanpaNip);
        }

        if ($p['diabaikan'] !== []) {
            $info[] = 'kolom tidak dikenal diabaikan: ' . implode(', ', $p['diabaikan']);
        }

        return HasilSlip::berhasil(
            ['baris' => $hasil, 'baris_tanpa_nip' => $tanpaNip, 'kolom_diabaikan' => $p['diabaikan']],
            [],
            $tanpaNip !== [] ? 'baris_tanpa_nip' : ($p['diabaikan'] !== [] ? 'kolom_diabaikan' : null),
            $info === [] ? null : "Sheet {$sheet}: " . implode('; ', $info) . '.',
        );
    }

    /**
     * Filter periode opsional dari formulir impor (I-10). Keduanya kosong → tanpa filter (`nilai = null`); hanya satu
     * yang diisi → `filter_periode_tidak_lengkap` (legacy mengabaikannya diam-diam); nilai tidak valid (aturan P-3/P-4)
     * → `periode_tidak_valid`. `nilai` = {@see PeriodeSlip} atau `null`.
     */
    public static function filterDariMasukan(mixed $bulan, mixed $tahun): HasilSlip
    {
        $bulanKosong = NilaiSel::kosong($bulan);
        $tahunKosong = NilaiSel::kosong($tahun);

        if ($bulanKosong && $tahunKosong) {
            return HasilSlip::berhasil(null);
        }

        if ($bulanKosong || $tahunKosong) {
            return HasilSlip::gagal(
                'filter_periode_tidak_lengkap',
                'Filter periode harus diisi bulan dan tahun sekaligus, atau dikosongkan keduanya.',
            );
        }

        $hasilBulan = BarisImpor::bulan($bulan);
        $hasilTahun = BarisImpor::tahun($tahun);

        if (! $hasilBulan->valid || ! $hasilTahun->valid) {
            return HasilSlip::gagal(
                'periode_tidak_valid',
                'Filter periode tidak valid. ' . ($hasilBulan->valid ? '' : $hasilBulan->pesan . ' ') . ($hasilTahun->valid ? '' : $hasilTahun->pesan),
            );
        }

        return HasilSlip::berhasil(PeriodeSlip::buat((int) $hasilTahun->nilai, (int) $hasilBulan->nilai));
    }

    /**
     * Kunci NIP + periode (`199001012020011001|2026-10`); bulan `8` dan `Agustus` menghasilkan kunci yang sama (I-8).
     */
    public static function kunci(string $nip, PeriodeSlip $periode): string
    {
        return $nip . '|' . $periode->kunci();
    }

    /**
     * Gabungkan baris GPP dan TK menjadi pratinjau per kunci (I-8..I-11, I-13, M-1..M-6).
     *
     * - Kunci ganda dalam satu sheet: baris pertama dipertahankan dan diberi galat `baris_ganda` yang menyebut kedua
     *   nomor baris; baris berikutnya dibuang (I-8).
     * - Setiap kunci wajib ada di kedua sheet (`tidak_ada_di_gpp`/`tidak_ada_di_tk`, I-9).
     * - NIP wajib terdaftar (`nip_tidak_terdaftar`) dan, bila `$cakupanSatker` diisi (admin role 3, I-13 [V2] PRD SG-2),
     *   satkernya sama menurut {@see AksesSlip::dalamCakupan()} (`nip_di_luar_cakupan`, fail-closed; nama pegawai di luar
     *   cakupan tidak pernah dibandingkan atau dikutip).
     * - Setiap sheet yang punya kolom `nama` dicek sendiri (M-6, PERBAIKI): kosong → `nama_kosong`; tidak cocok →
     *   `nama_tidak_cocok` + entri `nama_tidak_cocok` untuk log (M-5).
     * - Status: galat > duplikat (kunci sudah ada di DB) > valid (I-11). Urut tahun → bulan → NIP numerik. Baris tanpa
     *   kunci (NIP/periode tidak valid) tidak dipasangkan tetapi tetap dilaporkan sebagai galat: di akhir kelompok
     *   periodenya, atau paling akhir bila periodenya tidak valid.
     * - Filter periode: baris dengan periode lain dibuang sebelum validasi; tidak ada sisa → `periode_tidak_ada_di_berkas`.
     *   Baris yang periodenya tidak terbaca tidak bisa dipastikan berada di luar filter, jadi tetap dilaporkan sebagai
     *   galat (legacy membuangnya diam-diam).
     *
     * `nilai` = `array{baris: list<array{kunci: ?string, sheet: string, nomor_baris: int, nip: ?string,
     * periode: ?PeriodeSlip, status: string, galat: list<array{kode: string, pesan: string}>, nominal: array<string, int>}>,
     * jumlah_valid: int, jumlah_duplikat: int, jumlah_galat: int, nama_tidak_cocok: list<array{nip: string, sheet: string,
     * nomor_baris: int, nama_berkas: string, nama_db: string}>}`.
     *
     * @param list<BarisSheet>                                       $gpp
     * @param list<BarisSheet>                                       $tk
     * @param array<int|string, array{nama: string, id_satker: ?string}> $pegawai       per NIP (kunci NIP numerik menjadi int di array PHP)
     * @param array<string, true>                                    $kunciSudahAda kunci {@see self::kunci()} di DB
     * @param ?string                                                $cakupanSatker null = semua pegawai, **hanya** untuk role 1; role 3 wajib mengoper satker akunnya (`''` bila kosong → semua ditolak), jangan pernah null
     */
    public static function susun(
        array $gpp,
        array $tk,
        array $pegawai,
        array $kunciSudahAda,
        ?PeriodeSlip $filter = null,
        ?string $cakupanSatker = null,
    ): HasilSlip {
        /** @var array<string, array{GPP?: BarisSheet, TK?: BarisSheet, galat: list<array{kode: string, pesan: string}>}> $grup */
        $grup       = [];
        $tanpaKunci = [];

        foreach ([KolomGaji::SHEET_GPP => $gpp, KolomGaji::SHEET_TK => $tk] as $sheet => $daftar) {
            foreach ($daftar as $baris) {
                if ($baris->sheet !== $sheet) {
                    throw new InvalidArgumentException("Baris sheet {$baris->sheet} dikirim sebagai sheet {$sheet}");
                }

                if ($filter !== null && $baris->periode !== null && ! $baris->periode->sama($filter)) {
                    continue;
                }

                $kunci = $baris->kunci();

                if ($kunci === null) {
                    $tanpaKunci[] = $baris;

                    continue;
                }

                $grup[$kunci] ??= ['galat' => []];

                if (isset($grup[$kunci][$sheet])) {
                    $pertama = $grup[$kunci][$sheet];

                    $grup[$kunci]['galat'][] = [
                        'kode'  => 'baris_ganda',
                        'pesan' => sprintf(
                            'Baris ganda: NIP %s periode %s muncul lebih dari 1 kali di sheet %s (baris %d dan baris %d).',
                            $baris->nip,
                            $baris->periode?->label(),
                            $sheet,
                            $pertama->nomorBaris,
                            $baris->nomorBaris,
                        ),
                    ];

                    continue;
                }

                $grup[$kunci][$sheet] = $baris;
            }
        }

        if ($filter !== null && $grup === [] && $tanpaKunci === []) {
            return HasilSlip::gagal(
                'periode_tidak_ada_di_berkas',
                "Tidak ada baris data untuk periode {$filter->label()} pada berkas ini.",
                ['periode' => $filter->kunci()],
            );
        }

        $hasil          = [];
        $namaTidakCocok = [];

        foreach ($grup as $kunci => $isi) {
            $barisGpp = $isi[KolomGaji::SHEET_GPP] ?? null;
            $barisTk  = $isi[KolomGaji::SHEET_TK] ?? null;
            $acuan    = $barisGpp ?? $barisTk;

            if ($acuan === null || $acuan->nip === null || $acuan->periode === null) {
                continue; // Tidak terjadi: grup selalu dibuat dari baris berkunci.
            }

            $nip     = $acuan->nip;
            $periode = $acuan->periode;
            $galat   = [...($barisGpp->galat ?? []), ...($barisTk->galat ?? []), ...$isi['galat']];
            $data    = $pegawai[$nip] ?? null;

            if ($data === null) {
                $galat[] = ['kode' => 'nip_tidak_terdaftar', 'pesan' => "NIP {$nip} tidak terdaftar di data pegawai."];
            } elseif ($cakupanSatker !== null && ! AksesSlip::dalamCakupan(Role::ADMIN_SATKER, $cakupanSatker, $data['id_satker'])) {
                $galat[] = ['kode' => 'nip_di_luar_cakupan', 'pesan' => "NIP {$nip} berada di luar satker Anda."];
            } else {
                foreach ([$barisGpp, $barisTk] as $baris) {
                    if ($baris === null || $baris->nama === null) {
                        continue;
                    }

                    if ($baris->nama === '') {
                        $galat[] = [
                            'kode'  => 'nama_kosong',
                            'pesan' => "Sheet {$baris->sheet} baris {$baris->nomorBaris}: kolom nama kosong untuk NIP {$nip} (wajib diisi bila kolom nama ada di berkas).",
                        ];
                    } elseif (! PencocokanNama::cocok($baris->nama, $data['nama'])) {
                        $galat[] = [
                            'kode'  => 'nama_tidak_cocok',
                            'pesan' => "Sheet {$baris->sheet} baris {$baris->nomorBaris}: nama pada berkas ('{$baris->nama}') tidak cocok dengan nama NIP {$nip} di data pegawai ('{$data['nama']}').",
                        ];
                        $namaTidakCocok[] = [
                            'nip'         => $nip,
                            'sheet'       => $baris->sheet,
                            'nomor_baris' => $baris->nomorBaris,
                            'nama_berkas' => $baris->nama,
                            'nama_db'     => $data['nama'],
                        ];
                    }
                }
            }

            if ($barisGpp === null) {
                $galat[] = ['kode' => 'tidak_ada_di_gpp', 'pesan' => "Data periode {$periode->label()} untuk NIP {$nip} tidak ditemukan di sheet GPP."];
            }

            if ($barisTk === null) {
                $galat[] = ['kode' => 'tidak_ada_di_tk', 'pesan' => "Data periode {$periode->label()} untuk NIP {$nip} tidak ditemukan di sheet TK."];
            }

            $status = match (true) {
                $galat !== []                 => self::STATUS_GALAT,
                isset($kunciSudahAda[$kunci]) => self::STATUS_DUPLIKAT,
                default                       => self::STATUS_VALID,
            };

            $hasil[] = [
                'kunci'       => $kunci,
                'sheet'       => $acuan->sheet,
                'nomor_baris' => $acuan->nomorBaris,
                'nip'         => $nip,
                'periode'     => $periode,
                'status'      => $status,
                'galat'       => $galat,
                'nominal'     => self::nominalGabungan($barisGpp, $barisTk),
            ];
        }

        foreach ($tanpaKunci as $baris) {
            $hasil[] = [
                'kunci'       => null,
                'sheet'       => $baris->sheet,
                'nomor_baris' => $baris->nomorBaris,
                'nip'         => $baris->nip,
                'periode'     => $baris->periode,
                'status'      => self::STATUS_GALAT,
                'galat'       => $baris->galat,
                'nominal'     => self::nominalGabungan($baris->sheet === KolomGaji::SHEET_GPP ? $baris : null, $baris->sheet === KolomGaji::SHEET_TK ? $baris : null),
            ];
        }

        usort($hasil, static fn (array $a, array $b): int => self::kunciUrut($a) <=> self::kunciUrut($b));

        $jumlah = array_count_values(array_column($hasil, 'status'));

        return HasilSlip::berhasil([
            'baris'            => $hasil,
            'jumlah_valid'     => $jumlah[self::STATUS_VALID] ?? 0,
            'jumlah_duplikat'  => $jumlah[self::STATUS_DUPLIKAT] ?? 0,
            'jumlah_galat'     => $jumlah[self::STATUS_GALAT] ?? 0,
            'nama_tidak_cocok' => $namaTidakCocok,
        ]);
    }

    /** Opsi timpa impor bawaan tercentang [K] `views/hr/employee/sl_gaji/admin_import.php:54` (I-12, U-6). */
    public const TIMPA_BAWAAN = true;

    /**
     * Rencana commit per kunci (I-12): galat → `gagal`; duplikat → `perbarui` bila timpa, selain itu `lewati`; valid →
     * `sisip`. Commit parsial per kunci; `perbarui` hanya mengganti 28 kolom nominal, tidak menyentuh status dibuka atau
     * token (S-6 [K]: legacy mengizinkan timpa slip yang sudah dibuka, `Lsl_gaji.php:1041-1071`). Nilai bawaan opsi timpa
     * = `TIMPA_BAWAAN` (tercentang).
     */
    public static function rencanaCommit(string $status, bool $timpa): string
    {
        return match ($status) {
            self::STATUS_GALAT    => self::RENCANA_GAGAL,
            self::STATUS_DUPLIKAT => $timpa ? self::RENCANA_PERBARUI : self::RENCANA_LEWATI,
            self::STATUS_VALID    => self::RENCANA_SISIP,
            default               => throw new InvalidArgumentException("Status pratinjau tidak dikenal: {$status}"),
        };
    }

    /**
     * 28 field bernilai 0, ditimpa nominal GPP lalu TK (I-11).
     *
     * @return array<string, int>
     */
    private static function nominalGabungan(?BarisSheet $gpp, ?BarisSheet $tk): array
    {
        return array_merge(array_fill_keys(KolomGaji::semuaField(), 0), $gpp->nominal ?? [], $tk->nominal ?? []);
    }

    /**
     * Kunci urut: baris berperiode dulu (tahun → bulan), lalu NIP numerik (panjang lalu leksikografis; NIP tidak valid di
     * akhir), lalu sheet dan nomor baris.
     *
     * @param array{nip: ?string, periode: ?PeriodeSlip, sheet: string, nomor_baris: int} $baris
     *
     * @return list<int|string>
     */
    private static function kunciUrut(array $baris): array
    {
        $nip = $baris['nip'];

        return [
            $baris['periode'] === null ? 1 : 0,
            $baris['periode']?->indeks() ?? 0,
            $nip === null ? 1 : 0,
            strlen($nip ?? ''),
            $nip ?? '',
            $baris['sheet'],
            $baris['nomor_baris'],
        ];
    }
}
