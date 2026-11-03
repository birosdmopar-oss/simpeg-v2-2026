<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use App\Libraries\Html\HtmlSanitizer;
use App\Libraries\MasterData\AturanLokasiPresensiHooks;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;

/**
 * Aturan khusus tabel impor cutover tahap 2 (MAKE-069, DBV-029, rencana `docs/migrasi/MAKE-069-rencana.md`). Pesan dan
 * kode laporan hanya memuat jumlah, tanpa NIP/nama/isi.
 */
final class AturanTahap2
{
    /**
     * Kolom target JSON aturan lokasi presensi (G-03 §3 #9).
     */
    public const TARGET_LOKASI = ['target_lp', 'target_uns', 'target_jp', 'target_peg', 'excl_peg'];

    private const OLD_ID_MAKS = 2_147_483_647;

    private function __construct()
    {
    }

    /**
     * K-M9b — satu pengajuan per NIP: `id_pegawai_hist` terbesar di antara `flag_update = 1`.
     *
     * @return array<int|string, string>
     */
    public static function pegawaiHistTerbaru(BaseConnection $db, string $tabel, Keputusan $keputusan): array
    {
        $per = [];

        foreach (self::semua($db, "SELECT id_pegawai_hist AS id, nip FROM `{$tabel}` WHERE flag_update = 1") as $r) {
            $per[(string) Transformasi::kode($r['nip'] === null ? null : (string) $r['nip'])][] = (int) $r['id'];
        }

        $buang = [];

        foreach ($per as $id) {
            rsort($id);

            foreach (array_slice($id, 1) as $kalah) {
                $buang[$kalah] = 'DBV-029 K-M9b bukan pengajuan terbaru';
            }
        }

        return $buang;
    }

    /**
     * INFO K-M9b: `pegawai.flag_update = 1` tanpa pengajuan pending, dan pengajuan pending tanpa `pegawai.flag_update = 1`.
     *
     * @return array<string, int>
     */
    public static function rekonsiliasiPegawaiHist(BaseConnection $tujuan): array
    {
        $peg  = $tujuan->prefixTable('pegawai');
        $hist = $tujuan->prefixTable('pegawai_hist');

        return [
            'INFO pegawai.flag_update=1 tanpa pegawai_hist pending' => self::hitung($tujuan, "SELECT COUNT(*) AS n FROM `{$peg}` p WHERE p.flag_update = 1 AND NOT EXISTS (SELECT 1 FROM `{$hist}` h WHERE h.nip = p.nip AND h.flag_update = 1)"),
            'INFO pegawai_hist pending tanpa pegawai.flag_update=1' => self::hitung($tujuan, "SELECT COUNT(*) AS n FROM `{$hist}` h JOIN `{$peg}` p ON p.nip = h.nip WHERE h.flag_update = 1 AND p.flag_update <> 1"),
        ];
    }

    /**
     * T8 `pengguna` — validasi sebelum tulis (A-01, DBV-010 §9.5): akun role 2/6/7 wajib NIP; akun tanpa NIP wajib nama.
     * Sumber `password_decode` tidak dibaca (kolom terlarang di definisi).
     *
     * @return array<int|string, string>
     */
    public static function penggunaValid(BaseConnection $db, string $tabel, Keputusan $keputusan): array
    {
        $tanpaNip  = 0;
        $tanpaNama = 0;

        foreach (self::semua($db, "SELECT `UserLevel` AS r, `id_pegawai` AS nip, `name` AS nama FROM `{$tabel}`") as $r) {
            $nip = Transformasi::kode($r['nip'] === null ? null : (string) $r['nip']);

            if ($nip === null || $nip === '') {
                if (in_array((int) $r['r'], [2, 6, 7], true)) {
                    $tanpaNip++;
                } elseif (trim((string) $r['nama']) === '') {
                    $tanpaNama++;
                }
            }
        }

        if ($tanpaNip > 0 || $tanpaNama > 0) {
            throw new ImporGagal("pengguna: {$tanpaNip} akun role 2/6/7 tanpa NIP, {$tanpaNama} akun tanpa NIP dan tanpa nama (DBV-010 §9.5).");
        }

        return [];
    }

    /**
     * T8 — `must_change_password` (K-M1/K-M2): role 1 selalu; role 2/6 bila hash legacy = md5(NIP), padanan popup
     * paksa ganti legacy (`MY_Controller.php:209-238`). Hanya hash yang dibandingkan.
     *
     * @param array<string, mixed> $b
     *
     * @return array<string, mixed>
     */
    public static function pengguna(array $b): array
    {
        $role = (int) ($b['user_level'] ?? 0);
        $nip  = $b['nip'] ?? null;
        $md5  = strtolower((string) ($b['password_legacy'] ?? ''));

        if ($nip === '') {
            $b['nip'] = null;
            $nip      = null;
        }

        $wajib = $role === 1 || (in_array($role, [2, 6], true) && $nip !== null && $md5 !== '' && hash_equals($md5, md5((string) $nip)));

        $b['must_change_password'] = $wajib ? 1 : 0;

        if ($wajib) {
            $b['__koreksi'] = [$role === 1 ? 'K-M2 role 1 wajib ganti password' : 'K-M1 password = NIP wajib ganti'];
        }

        return $b;
    }

    /**
     * K-M9a — `jenis_konket.old_id`: bilangan bulat positif sah disalin; selain itu wajib diputus di bagian `baris` berkas
     * keputusan (nilai pengganti atau `__buang`), kalau tidak gagal dengan jumlah.
     *
     * @return array<int|string, string>
     */
    public static function oldIdTidakSah(BaseConnection $db, string $tabel, Keputusan $keputusan): array
    {
        $tidakSah = 0;
        $buang    = [];

        foreach (self::semua($db, "SELECT id_jenis_konket AS id, old_id FROM `{$tabel}`") as $r) {
            $putus = $keputusan->baris('jenis_konket', 'old_id', (string) $r['id']);

            if ($putus['ada']) {
                if ($putus['nilai'] === '__buang') {
                    $buang[(int) $r['id']] = 'DBV-029 K-M9a keputusan: tidak diimpor';
                }

                continue;
            }

            if (self::oldId($r['old_id']) === null) {
                $tidakSah++;
            }
        }

        if ($tidakSah > 0) {
            throw new ImporGagal("jenis_konket: {$tidakSah} old_id NULL/kosong/bukan angka; putuskan per id di bagian \"baris\" berkas keputusan (DBV-029 K-M9a).");
        }

        return $buang;
    }

    /**
     * @param array<string, mixed> $b
     *
     * @return array<string, mixed>
     */
    public static function jenisKonket(array $b, Keputusan $keputusan): array
    {
        $putus = $keputusan->baris('jenis_konket', 'old_id', (string) $b['id_jenis_konket']);

        if ($putus['ada']) {
            $b['old_id']    = $putus['nilai'];
            $b['__koreksi'] = ['DBV-029 K-M9a old_id dari berkas keputusan'];

            return $b;
        }

        $b['old_id'] = self::oldId($b['old_id']);

        return $b;
    }

    /**
     * Audit WAJIB_0 sesudah T7 (K-M9a): `absen_ijin.kategori` (diimpor MAKE-056) tanpa pasangan `jenis_konket.old_id`.
     *
     * @return array<string, int>
     */
    public static function auditKategoriKonket(BaseConnection $tujuan): array
    {
        $izin = $tujuan->prefixTable('absen_ijin');
        $jk   = $tujuan->prefixTable('jenis_konket');

        return ['WAJIB_0 absen_ijin.kategori tanpa jenis_konket.old_id' => self::hitung(
            $tujuan,
            "SELECT COUNT(*) AS n FROM `{$izin}` a WHERE a.kategori IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `{$jk}` j WHERE j.old_id = a.kategori)",
        )];
    }

    /**
     * K-M9c — FAQ: (stripslashes bawaan) → `HtmlSanitizer` → `content_stripped` dihitung ulang (rumus legacy
     * `L_faq.php:720`, sama dengan `FaqArticleHooks`). Nilai `content_stripped` legacy tidak dipakai.
     *
     * @param array<string, mixed> $b
     *
     * @return array<string, mixed>
     */
    public static function faqArticle(array $b): array
    {
        $asli   = (string) ($b['content'] ?? '');
        $bersih = service('htmlSanitizer')->sanitize($asli);

        $b['content']          = $bersih;
        $b['content_stripped'] = HtmlSanitizer::strip($bersih);

        if ($bersih !== $asli) {
            $b['__koreksi'] = ['DBV-029 K-M9c konten diubah sanitizer'];
        }

        return $b;
    }

    /**
     * K-M6 — JSON target aturan lokasi yang tidak bisa diurai sesudah `stripslashes` → gagal dengan jumlah (resolver akan
     * mengabaikannya diam-diam).
     *
     * @return array<int|string, string>
     */
    public static function targetLokasiSah(BaseConnection $db, string $tabel, Keputusan $keputusan): array
    {
        $kolom = implode(', ', array_map(static fn (string $k): string => "`{$k}`", self::TARGET_LOKASI));
        $rusak = 0;

        foreach (self::semua($db, "SELECT {$kolom} FROM `{$tabel}`") as $r) {
            foreach (self::TARGET_LOKASI as $k) {
                $v = Transformasi::stripslashes($r[$k] === null ? null : (string) $r[$k]);

                if ($v !== null && trim($v) !== '' && ! is_array(json_decode($v, true))) {
                    $rusak++;

                    break;
                }
            }
        }

        if ($rusak > 0) {
            throw new ImporGagal("dm_user_lokasi_presensi: {$rusak} aturan dengan JSON target_* tidak terurai sesudah stripslashes (DBV-029 K-M6).");
        }

        return [];
    }

    /**
     * INFO K-M6: id lokasi di `target_lp` yang tidak ada di `lokasi_presensi`, NIP di `target_peg`/`excl_peg` yang tidak ada
     * di `pegawai` (tanpa FK, disimpan; resolver menyaring sendiri).
     *
     * @return array<string, int>
     */
    public static function rujukanLokasi(BaseConnection $tujuan): array
    {
        $lokasi = array_flip(array_map('strval', array_column(self::semua($tujuan, 'SELECT id_lokasi_presensi AS id FROM `' . $tujuan->prefixTable('lokasi_presensi') . '`'), 'id')));
        $nip    = array_flip(array_map('strval', array_column(self::semua($tujuan, 'SELECT nip AS id FROM `' . $tujuan->prefixTable('pegawai') . '`'), 'id')));
        $lp     = 0;
        $peg    = 0;

        foreach (self::semua($tujuan, 'SELECT target_lp, target_peg, excl_peg FROM `' . $tujuan->prefixTable('dm_user_lokasi_presensi') . '`') as $r) {
            $lp += count(array_diff_key(array_flip(AturanLokasiPresensiHooks::storedIds($r['target_lp'])), $lokasi));

            foreach (['target_peg', 'excl_peg'] as $k) {
                $peg += count(array_diff_key(array_flip(AturanLokasiPresensiHooks::storedIds($r[$k])), $nip));
            }
        }

        return ['INFO target_lp tanpa lokasi_presensi' => $lp, 'INFO target_peg/excl_peg tanpa pegawai' => $peg];
    }

    /**
     * ID yang di-hard-code aturan v2 wajib ada di sumber (B-01/B-02 §6 #10, rencana MAKE-069 T7); hilang → gagal menyebut id.
     *
     * @param list<int> $id
     *
     * @return \Closure(BaseConnection, string, Keputusan): array<int|string, string>
     */
    public static function wajibAda(string $pk, array $id): \Closure
    {
        return static function (BaseConnection $db, string $tabel, Keputusan $keputusan) use ($pk, $id): array {
            // Tabel sumber kosong (lingkungan uji/dev) tidak diperiksa; salinan produksi selalu berisi master ini.
            if ((int) (self::semua($db, "SELECT COUNT(*) AS n FROM `{$tabel}`")[0]['n'] ?? 0) === 0) {
                return [];
            }

            $ada    = array_map('intval', array_column(self::semua($db, "SELECT `{$pk}` AS i FROM `{$tabel}` WHERE `{$pk}` IN (" . implode(',', $id) . ')'), 'i'));
            $hilang = array_values(array_diff($id, $ada));

            if ($hilang !== []) {
                throw new ImporGagal("{$tabel}: id hard-code aturan v2 tidak ada di sumber: " . implode(', ', $hilang) . ' (B-01/B-02 §6 #10).');
            }

            return [];
        };
    }

    private static function oldId(mixed $nilai): ?int
    {
        $v = trim((string) $nilai);

        if ($nilai === null || preg_match('/^[1-9][0-9]*$/', $v) !== 1 || (int) $v > self::OLD_ID_MAKS || strlen($v) > 10) {
            return null;
        }

        return (int) $v;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function semua(BaseConnection $db, string $sql): array
    {
        $hasil = $db->query($sql);

        if (! $hasil instanceof ResultInterface) {
            throw new ImporGagal('Galat DB saat aturan tahap 2 (kode ' . (string) ($db->error()['code'] ?? '?') . ').');
        }

        return $hasil->getResultArray();
    }

    private static function hitung(BaseConnection $db, string $sql): int
    {
        return (int) (self::semua($db, $sql)[0]['n'] ?? 0);
    }
}
