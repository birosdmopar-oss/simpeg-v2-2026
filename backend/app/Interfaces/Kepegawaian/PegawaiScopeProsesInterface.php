<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseBuilder;

/**
 * Filter query jalur PROSES PegawaiScope (MAKE-011, milik WS-2) — antrean approval (B-04 biodata, dan antrean lain
 * berikutnya) memakai predikat `bolehProses`, bukan predikat daftar `terapkanKeQuery` (CR-045: daftar admin unit 20
 * memuat UPT, antrean prosesnya tidak).
 *
 * Interface terpisah (aditif), BUKAN method baru di PegawaiScopeInterface: implementasi lain kontrak beku itu (fake
 * test, kelas anonim di test WS-1) tidak dipaksa berubah. Pemanggil yang menerima scope tanpa interface ini menyaring
 * hasil dengan `bolehProses()` per baris.
 */
interface PegawaiScopeProsesInterface
{
    /**
     * Saring query ke pegawai yang boleh DIPROSES pemanggil (= `bolehProses`): role 1 semua; role 3 jalur proses
     * (satker admin, atau unit 20 / unit 21 + satker BPO / unit lain bila admin tanpa satker); role lain tidak ada.
     */
    public function terapkanKeQueryProses(BaseBuilder $builder, AuthContext $auth, string $kolomNip = 'nip'): void;
}
