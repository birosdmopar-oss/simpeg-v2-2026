<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use Closure;

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
     * @param list<string>                  $tanpaStripslashes kolom teks yang disimpan mentah di legacy (pengecualian DBV-011 §2.2 #3)
     * @param list<int|string>|null         $statusDomain      nilai `status` yang diimpor apa adanya; di luar itu → berkas keputusan. null = tanpa aturan status
     * @param array{kolom: string, lingkup: list<string>}|null $urutan [V2] kolom urutan yang tidak ada di sumber → 1..n per lingkup, urut PK (DBV-011 §2.4 #5)
     * @param list<string>                  $kolomTunda        FK ke tabel yang sama: ditulis NULL dulu, diisi sesudah semua baris tabel masuk
     * @param bool                          $bolehSeed         tujuan boleh sudah berisi baris seed migration: id + nama sama → dilewati, beda → gagal (GAP-3)
     * @param bool                          $opsional          tabel boleh tidak ada di sumber (dilaporkan, bukan galat)
     * @param (Closure(array<string, mixed>, Keputusan): (array<string, mixed>|string))|null $khusus aturan baris khusus; string = kode aturan baris dibuang
     * @param array<string, list<int|string>> $domain       domain kolom lain (CHECK v2); di luar itu → berkas keputusan
     * @param list<string>                  $kolomKode         kode/PK teks yang di-trim seperti aplikasi (kode wilayah, DBV-011 §2.2 #7)
     * @param list<string>                  $yatimKeNull       FK nullable yang rujukannya tidak ada di tujuan → NULL + dicatat (snapshot `id_riwayat_*`, B-01/B-02 §6 #6); FK lain yang yatim → gagal
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
    ) {
    }
}
