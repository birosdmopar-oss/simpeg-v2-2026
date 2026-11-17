<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg;

use App\Exceptions\ApiException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Pegawai\EksporTabel;
use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\HaloSimpeg;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use RuntimeException;

/**
 * Ekspor rekap petugas Halo Simpeg (E-09, MAKE-074; PRD Fase 8 v1.4 §7.9, F8-20, §7.3a, AC-E09.1..AC-E09.8) [K `LS:1600-1830`,
 * `SC:751-786`]. Kontrak beku: `Controllers/Api/HaloSimpeg/README.md` bagian ekspor.
 *
 * - Izin = `bisa_ekspor` (`PetugasService::saya`, petugas **aktif**, S12) — satu sumber dengan flag; selain itu 403 `bukan_petugas`.
 * - Cakupan = tiket di kategori yang dipegang petugas (AksesTiket::kategoriAktif), tanggal tiket dalam `[ds 00:00 WIB, de+1 00:00
 *   WIB)` dibandingkan langsung dengan kolom UTC (tanpa `DATE()`); tiket berkategori NULL tidak ikut (H8-16 (a1)). Semua pesan
 *   tiket itu, urut tiket lalu pesan (B-17 diperluas).
 * - Pengaman sebelum membangun berkas: `COUNT` ≤ `eksporMaksBaris` dan `SUM(CHAR_LENGTH(msg))` ≤ `eksporMaksKarakter` → selain
 *   itu 422 `ekspor_terlalu_besar`; baris dan karakter dicek ulang sesudah data diambil (tulis bersamaan di antara dua
 *   kueri).
 * - Sel > 32.767 karakter dipotong dengan penanda "… [dipotong]" [V2] (B-27). Semua sel teks (EksporTabel), NIP tetap teks.
 * - Periode kosong → hasil `kosong` (200 JSON, tanpa audit). Berkas terkirim → audit `dokumen_unduhan` (siapa, periode, jumlah
 *   baris; tanpa isi pesan, nama atau NIP penanya, isi berkas) (AC-E09.7). Baca saja; tidak menulis tabel helpdesk (BP8-1).
 */
final class EksporTiketService extends MasterHaloSimpeg
{
    public const FORMAT = 'xlsx';

    public const ENTITY_AUDIT = 'dokumen_unduhan';

    public const JENIS_AUDIT = 'rekap_halo_simpeg';

    public const ZONA = 'Asia/Jakarta';

    /** Batas sel Excel (karakter UTF-16) dan penanda potong [V2] (B-27). */
    public const MAKS_SEL = 32767;

    public const PENANDA_POTONG = '… [dipotong]';

    public const PESAN_BUKAN_PETUGAS = 'Fitur ini hanya untuk petugas Halo Simpeg yang aktif.';

    public const PESAN_TERLALU_BESAR = 'Data pada periode ini terlalu besar untuk diekspor. Persempit periode.';

    public const PESAN_TANGGAL_WAJIB = 'Tanggal awal dan akhir wajib diisi.';

    public const PESAN_TANGGAL_URUT = 'Tanggal awal tidak boleh melebihi tanggal akhir.';

    public const PESAN_TANGGAL_FORMAT = 'Tanggal harus berformat YYYY-MM-DD dan ada di kalender.';

    public const PESAN_FORMAT = 'Format ekspor tidak didukung.';

    public const PESAN_KOSONG = 'Tidak ada data Halo Simpeg pada periode ini.';

    public const HEADER = ['NO', 'ID PERTANYAAN', 'KATEGORI', 'NAMA PENANYA', 'NIP PENANYA', 'PERTANYAAN / JAWABAN', 'TIMESTAMP'];

    public const JUDUL_SHEET = 'Rekap Halo SIMPEG';

    private readonly AuditLogModel $audit;

    public function __construct(?BaseConnection $db = null, ?HaloSimpeg $config = null, ?AuditLogModel $audit = null)
    {
        parent::__construct($db, $config);
        $this->audit = $audit ?? new AuditLogModel($this->db);
    }

    /**
     * @param array<string, mixed> $query `ds`, `de`, `format`
     *
     * @return array{kosong: true, jumlah: 0, message: string}|array{kosong: false, isi: string, nama: string, mime: string, jumlah: int}
     */
    public function ekspor(AuthContext $auth, array $query): array
    {
        $idPengguna = $auth->idPengguna();

        if ($idPengguna === null || ! (new PetugasService($this->db, $this->config))->saya($idPengguna)['bisa_ekspor']) {
            throw new ApiException(self::PESAN_BUKAN_PETUGAS, 403, null, 'bukan_petugas');
        }

        [$ds, $de]   = $this->periode($query);
        [$dari, $ke] = self::rentangUtc($ds, $de);
        $kategori    = AksesTiket::kategoriAktif($this->db, $idPengguna);

        $agregat = $kategori === [] ? ['jumlah' => 0, 'karakter' => 0] : $this->cakupan($kategori, $dari, $ke)
            ->select('COUNT(*) AS jumlah, COALESCE(SUM(CHAR_LENGTH(m.msg)), 0) AS karakter', false)->get()->getRowArray();
        $jumlah = (int) ($agregat['jumlah'] ?? 0);

        if ($jumlah === 0) {
            return ['kosong' => true, 'jumlah' => 0, 'message' => self::PESAN_KOSONG];
        }

        if ($jumlah > $this->config->eksporMaksBaris || (int) ($agregat['karakter'] ?? 0) > $this->config->eksporMaksKarakter) {
            throw self::terlaluBesar();
        }

        $rows = $this->cakupan($kategori, $dari, $ke)
            ->select('t.id_inbox, t.sender, qc.question_category AS kategori, m.msg_sender, m.msg_sender_name, u.nip AS nip_pengirim, m.msg, m.created_at')
            ->orderBy('t.id_inbox', 'ASC')->orderBy('m.id_inbox_msg', 'ASC')
            ->limit($this->config->eksporMaksBaris + 1)->get()->getResultArray();

        $zona     = new DateTimeZone(self::ZONA);
        $utc      = new DateTimeZone('UTC');
        $baris    = [];
        $karakter = 0;
        $terpilih = $this->barisTerpilih($rows, $idPengguna, $auth->role());
        unset($rows); // generator memegang satu-satunya salinan dan melepasnya per baris

        foreach ($terpilih as [$r, $pengirim]) {
            $isi = (string) ($r['msg'] ?? '');
            $karakter += mb_strlen($isi);
            $baris[] = array_map(self::potongSel(...), [
                (string) (count($baris) + 1),
                (string) $r['id_inbox'],
                (string) ($r['kategori'] ?? ''),
                $pengirim[0],
                $pengirim[1],
                $isi,
                self::waktuWib((string) $r['created_at'], $utc, $zona),
            ]);
        }

        // V12: cek ulang baris DAN karakter sesudah data diambil (pesan yang masuk di antara kueri agregat dan kueri data).
        if (count($baris) > $this->config->eksporMaksBaris || $karakter > $this->config->eksporMaksKarakter) {
            throw self::terlaluBesar();
        }

        if ($baris === []) {
            return ['kosong' => true, 'jumlah' => 0, 'message' => self::PESAN_KOSONG];
        }

        $jumlah = count($baris);
        $nama   = (string) ($this->db->table('pengguna')->select('name')->where('id_pengguna', $idPengguna)->get()->getRowArray()['name'] ?? '');
        $isi    = EksporTabel::xlsx(self::HEADER, $baris, self::JUDUL_SHEET, [
            ['teks' => trim('REKAP PIC HALO SIMPEG ' . $nama), 'gaya' => 'judul'],
            ['teks' => 'Periode: ' . TanggalBisnis::formatIndonesia($ds) . ' - ' . TanggalBisnis::formatIndonesia($de)],
            ['teks' => ''],
        ]);
        unset($baris);

        $id = $this->audit->record(self::ENTITY_AUDIT, self::JENIS_AUDIT, 'create', null, [
            'jenis'        => self::JENIS_AUDIT,
            'format'       => self::FORMAT,
            'parameter'    => ['ds' => $ds, 'de' => $de],
            'jumlah_baris' => $jumlah,
        ], $idPengguna, $auth->nip());

        if ($id <= 0) {
            throw new RuntimeException('Catatan unduhan ekspor Halo Simpeg gagal ditulis.');
        }

        return [
            'kosong' => false,
            'isi'    => $isi,
            'nama'   => "Rekap_Halo_Simpeg_{$ds}_{$de}.xlsx",
            'mime'   => EksporTabel::MIME[EksporTabel::XLSX],
            'jumlah' => $jumlah,
        ];
    }

    /**
     * Tanggal kalender WIB `[ds, de]` → rentang UTC setengah terbuka `[ds 00:00 WIB, de+1 00:00 WIB)` (F8-20, pola F8-42).
     *
     * @return array{0: string, 1: string}
     */
    public static function rentangUtc(string $ds, string $de): array
    {
        $zona = new DateTimeZone(self::ZONA);
        $utc  = new DateTimeZone('UTC');

        return [
            (new DateTimeImmutable($ds . ' 00:00:00', $zona))->setTimezone($utc)->format('Y-m-d H:i:s'),
            (new DateTimeImmutable($de . ' 00:00:00', $zona))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Potong nilai sel yang melebihi batas sel Excel (dihitung dalam unit UTF-16, seperti Excel) dan beri penanda [V2] (B-27).
     */
    public static function potongSel(string $nilai): string
    {
        if (self::unitUtf16($nilai) <= self::MAKS_SEL) {
            return $nilai;
        }

        $sisa = self::MAKS_SEL - self::unitUtf16(self::PENANDA_POTONG);
        $isi  = mb_substr($nilai, 0, $sisa);

        // Karakter di luar BMP (mis. emoji) memakai dua unit UTF-16: kurangi sampai muat.
        while (($lebih = self::unitUtf16($isi) - $sisa) > 0) {
            $isi = mb_substr($isi, 0, max(0, mb_strlen($isi) - (int) ceil($lebih / 2)));
        }

        return $isi . self::PENANDA_POTONG;
    }

    /**
     * SATU titik keputusan cakupan + label baris ekspor (V1/V2, menunggu Horii): pesan yang ikut berkas di-yield bersama [nama, NIP]
     * untuk kolom NAMA PENANYA / NIP PENANYA; pesan yang tidak ikut cukup dilewati. Baris sumber dilepas satu per satu (memori).
     * Saat ini (usulan, belum beku): semua pesan tiket ikut; nama/NIP = pengirim pesan baris itu [K `LS:1797-1799`]. Kolom yang
     * tersedia untuk opsi (a)/(b): `sender` (pemilik tiket), `msg_sender`, `$pengekspor`, `$role` pengekspor.
     *
     * @param list<array<string, mixed>> $rows baris kueri data
     *
     * @return Generator<int, array{0: array<string, mixed>, 1: array{0: string, 1: string}}>
     */
    private function barisTerpilih(array $rows, int $pengekspor, ?int $role): Generator
    {
        foreach ($rows as $i => $r) {
            unset($rows[$i]);

            yield [$r, [(string) ($r['msg_sender_name'] ?? ''), (string) ($r['nip_pengirim'] ?? '')]];
        }
    }

    private static function unitUtf16(string $s): int
    {
        return intdiv(strlen((string) mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
    }

    private static function waktuWib(string $waktu, DateTimeZone $utc, DateTimeZone $zona): string
    {
        if ($waktu === '' || str_starts_with($waktu, '0000')) {
            return '';
        }

        return (new DateTimeImmutable($waktu, $utc))->setTimezone($zona)->format('d/m/Y H:i');
    }

    private static function terlaluBesar(): ApiException
    {
        return new ApiException(self::PESAN_TERLALU_BESAR, 422, null, 'ekspor_terlalu_besar');
    }

    /**
     * Pesan pada tiket berkategori milik petugas yang tanggal tiketnya di dalam rentang UTC `[dari, ke)`.
     *
     * @param list<int> $kategori
     */
    private function cakupan(array $kategori, string $dari, string $ke): BaseBuilder
    {
        return $this->db->table('disiplin_ticket t')
            ->join('disiplin_ticket_msg m', 'm.id_inbox = t.id_inbox', 'inner')
            ->join('question_category qc', 'qc.id_question_category = t.id_question_category', 'left')
            ->join('pengguna u', 'u.id_pengguna = m.msg_sender', 'left')
            ->whereIn('t.id_question_category', $kategori)
            ->where('t.created_at >=', $dari)->where('t.created_at <', $ke);
    }

    /**
     * Validasi `ds`, `de`, `format` (§7.3a, AC-E09.6); parameter berbentuk array → 422 (ListQuery).
     *
     * @param array<string, mixed> $query
     *
     * @return array{0: string, 1: string}
     */
    private function periode(array $query): array
    {
        $q      = $this->listQuery($query, ['ds', 'de', 'format']);
        $ds     = $q->string('ds');
        $de     = $q->string('de');
        $format = $q->string('format');
        $errors = [];

        foreach (['ds' => $ds, 'de' => $de] as $kolom => $v) {
            if ($v === '') {
                $errors[$kolom] = self::PESAN_TANGGAL_WAJIB;
            } elseif (! TanggalBisnis::valid($v)) {
                $errors[$kolom] = self::PESAN_TANGGAL_FORMAT;
            }
        }

        if (! isset($errors['ds']) && ! isset($errors['de']) && $ds > $de) {
            $errors['ds'] = self::PESAN_TANGGAL_URUT;
        }

        // `format` kosong = xlsx (satu-satunya format, AC-E09.6); nilai lain → 422.
        if ($format !== '' && $format !== self::FORMAT) {
            $errors['format'] = self::PESAN_FORMAT;
        }

        self::lemparBilaGalat($errors);

        return [$ds, $de];
    }
}
