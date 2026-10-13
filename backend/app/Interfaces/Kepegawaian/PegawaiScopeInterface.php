<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

use App\Libraries\Auth\AuthContext;
use CodeIgniter\Database\BaseBuilder;

/**
 * Lingkup akses data pegawai (kontrak beku S0-A MAKE-002; implementasi nyata milik WS-2).
 *
 * Semantik: di luar lingkup → pemanggil (controller/service) mengembalikan 403; filter daftar memakai terapkanKeQuery()
 * sehingga baris di luar lingkup tidak pernah terbaca. Implementasi wajib fail-closed: konteks tanpa sesi/role yang
 * dikenal → tidak boleh apa pun.
 */
interface PegawaiScopeInterface
{
    /**
     * Pemanggil boleh melihat data pegawai ber-NIP $nip.
     */
    public function bolehLihat(AuthContext $auth, string $nip): bool;

    /**
     * Pemanggil boleh mengubah (tambah/ubah/hapus) data pegawai ber-NIP $nip. Hak aksi per jenis riwayat tetap
     * ditentukan Definisi riwayat × role; method ini hanya lingkup pegawainya.
     */
    public function bolehUbah(AuthContext $auth, string $nip): bool;

    /**
     * Pemanggil boleh memproses (Setujui/Tolak) pengajuan riwayat pegawai ber-NIP $nip — jalur proses dan antrean
     * approval (aditif CR-045). Untuk role selain Admin Satker (3) sama dengan bolehUbah(). Admin Satker tanpa satker
     * memakai pembagian jalur legacy yang bisa lebih luas dari bolehUbah() (README `Libraries/Kepegawaian`, bagian
     * Scope); pemanggil jalur proses memakai method ini, BUKAN bolehUbah().
     */
    public function bolehProses(AuthContext $auth, string $nip): bool;

    /**
     * Saring query daftar ke pegawai dalam lingkup pemanggil (jalur daftar; untuk Admin Satker tanpa satker bisa lebih
     * luas dari bolehLihat(), ikut legacy). $kolomNip = kolom NIP pada builder (boleh beralias, mis. 'p.nip').
     */
    public function terapkanKeQuery(BaseBuilder $builder, AuthContext $auth, string $kolomNip = 'nip'): void;
}
