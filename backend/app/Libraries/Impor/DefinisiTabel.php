<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use Closure;
use CodeIgniter\Database\BaseConnection;

/**
 * Definisi impor satu tabel. Kolom yang disalin = irisan kolom sumber [K] dan kolom v2 (nama legacy dipertahankan);
 * aturan umum per kolom diterapkan {@see PengimporLegacy}, aturan khusus tabel lewat properti di bawah.
 */
final class DefinisiTabel
{
    /**
     * @param string                        $tabel             nama tabel (tanpa prefix) di sumber & tujuan
     * @param string                        $pk                kolom PK tunggal; id legacy disalin apa adanya
     * @param string                        $sumberAturan      rujukan dokumen aturan (dicetak di laporan)
     * @param list<string>                  $kolomNama         dinormalisasi seperti aplikasi (DBV-011 §2.2 #2); kolom pertama = pembanding seed (GAP-3)
     * @param list<string>                  $tanpaStripslashes kolom teks yang disimpan mentah di legacy (pengecualian DBV-011 §2.2 #3); `['*']` = semua kolom (GAP-8)
     * @param list<int|string>|null         $statusDomain      nilai `status` yang diimpor apa adanya; di luar itu → berkas keputusan. null = tanpa aturan status
     * @param array{kolom: string, lingkup: list<string>}|null $urutan [V2] kolom urutan yang tidak ada di sumber → 1..n per lingkup, urut PK (DBV-011 §2.4 #5)
     * @param list<string>                  $kolomTunda        FK ke tabel yang sama: ditulis NULL dulu, diisi sesudah semua baris tabel masuk
     * @param bool                          $bolehSeed         tujuan boleh sudah berisi baris seed migration: id + nama sama → dilewati, beda → gagal (GAP-3)
     * @param bool                          $opsional          tabel boleh tidak ada di sumber (dilaporkan, bukan galat)
     * @param (Closure(array<string, mixed>, Keputusan): (array<string, mixed>|string))|null $khusus aturan baris khusus; string = kode aturan baris dibuang
     * @param array<string, list<int|string>> $domain       domain kolom lain (CHECK v2); di luar itu → berkas keputusan
     * @param list<string>                  $kolomKode         kode/PK teks yang di-trim seperti aplikasi (kode wilayah, DBV-011 §2.2 #7)
     * @param list<string>                  $yatimKeNull       FK nullable yang rujukannya tidak ada di tujuan → NULL + dicatat (snapshot `id_riwayat_*`, B-01/B-02 §6 #6); FK lain yang yatim → gagal
     * @param list<string>                  $kunciUrut         kolom PK komposit untuk baca per batch (bawaan `[$pk]`)
     * @param list<string>                  $waktuBisnis       kolom DATETIME berisi waktu bisnis (mis. rentang izin), bukan stempel → tidak dikonversi R1a
     * @param string|null                   $sumber            nama tabel sumber bila berbeda dari tujuan (dicari tanpa beda huruf besar/kecil, `lower_case_table_names`)
     * @param bool                          $skemaSiasn        dibaca dari skema `--sumber-siasn` (salinan `siasn_simpeg`); tanpa opsi itu → dilewati + dilaporkan (DBV-027 K-9)
     * @param array<string, string>         $petaKolom         kolom tujuan → kolom sumber bila namanya berbeda (mis. `id_siasn` ← `ID`)
     * @param array<string, int|string|null> $konstanta        kolom tujuan yang diisi nilai tetap (mis. `siasn_referensi.jenis`; NULL = tidak diambil dari sumber walau namanya sama)
     * @param (Closure(BaseConnection, string, Keputusan): array<int|string, string>)|null $praPindai lintasan baca pertama atas seluruh sumber (koneksi, nama tabel sumber); hasil = PK sumber → kode aturan baris yang tidak diimpor. Boleh melempar {@see ImporGagal} (juga saat dry-run)
     * @param (Closure(BaseConnection): array<string, int>)|null $pascaImpor hitung INFO di tujuan sesudah tabel ditulis (bukan dry-run): kode → jumlah
     * @param string|null                   $saringSumber      syarat SQL baris sumber yang diimpor (mis. `pegawai_hist.flag_update = 1`); jumlah sumber = sesudah saringan
     * @param list<string>                  $kolomDilarang     kolom sumber yang tidak pernah di-SELECT (rahasia, mis. `pengguna.password_decode`)
     * @param array{kolom: string, nama: string, lingkup: list<string>}|null $isiUrutan [V2] kolom urutan yang tidak ada di sumber → 1..n per lingkup, urut nama (G-01 #4, G-04/05 P8)
     * @param array<string, array{0: string, 1: string}> $rujukan rujukan wajib tanpa FK di tujuan (FK masih ditahan): kolom → [tabel induk, kolom induk]; yatim → gagal
     * @param bool                          $hanyaSeed         semua id sumber wajib ada di seed migration (`bolehSeed`); id lain → gagal (mis. `jenis_rwy`)
     * @param bool                          $pkSintetis        [MAKE-089] PK tujuan `$pk` = AUTO_INCREMENT sintetis yang TIDAK ada di sumber (tabel anak berpenerima,
     *                                                         `news_flag`). Baris dikenali lewat `kunciUrut` (wajib); kunci baca ganda → baris
     *                                                         berikutnya dibuang + dicatat (setara INSERT IGNORE, jumlah dari SQL
     *                                                         `COUNT(*) - COUNT(DISTINCT …)`); pra-cek UNIQUE PHP dilewati untuk indeks UNIQUE yang
     *                                                         kolomnya = himpunan `kunciUrut` (dijamin kunci baca, Q-089-6: tanpa memuat jutaan
     *                                                         kunci ke memori). `bolehSeed`/`kolomTunda`/`isiUrutan` tidak didukung bersama opsi ini.
     */
    public function __construct(
        public readonly string $tabel,
        public readonly string $pk,
        public readonly string $sumberAturan,
        public readonly array $kolomNama = [],
        public readonly array $tanpaStripslashes = [],
        public readonly ?array $statusDomain = [1, 2, 10],
        public readonly ?array $urutan = null,
        public readonly array $kolomTunda = [],
        public readonly bool $bolehSeed = false,
        public readonly bool $opsional = false,
        public readonly ?Closure $khusus = null,
        public readonly array $domain = [],
        public readonly array $kolomKode = [],
        public readonly array $yatimKeNull = [],
        public readonly array $kunciUrut = [],
        public readonly array $waktuBisnis = [],
        public readonly ?string $sumber = null,
        public readonly bool $skemaSiasn = false,
        public readonly array $petaKolom = [],
        public readonly array $konstanta = [],
        public readonly ?Closure $praPindai = null,
        public readonly ?Closure $pascaImpor = null,
        public readonly ?string $saringSumber = null,
        public readonly array $kolomDilarang = [],
        public readonly ?array $isiUrutan = null,
        public readonly array $rujukan = [],
        public readonly bool $hanyaSeed = false,
        public readonly bool $pkSintetis = false,
    ) {
        if ($pkSintetis && ($kunciUrut === [] || $bolehSeed || $kolomTunda !== [] || $isiUrutan !== null)) {
            throw new ImporGagal("Definisi {$tabel}: pkSintetis wajib kunciUrut dan tidak bisa digabung bolehSeed/kolomTunda/isiUrutan.");
        }
    }

    /**
     * Kode baris ganda kunci baca (pkSintetis) yang dibuang.
     */
    public const KODE_GANDA = 'MAKE-089 kunci ganda (setara INSERT IGNORE)';

    /**
     * Kunci laporan: nama tabel tujuan, ditambah tabel sumber bila beberapa sumber mengisi satu tujuan.
     */
    public function kunci(): string
    {
        return $this->sumber === null ? $this->tabel : "{$this->tabel}<{$this->sumber}";
    }
}
