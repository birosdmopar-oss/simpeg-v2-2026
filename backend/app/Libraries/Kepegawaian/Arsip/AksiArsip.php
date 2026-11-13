<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Arsip;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\ArsipDataUmum;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\StatusRiwayat;
use App\Models\Kepegawaian\DocumentAttachmentModel;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

/**
 * Aksi tulis halaman Arsip F-10 Tahap 2 (PRD F-10 §6.1–§6.2, F10-09, F10-28, F10-31, F10-38; MAKE-092): Ubah Nama,
 * Hubungkan, Hapus arsip yatim, dan calon tujuan Hubungkan (`opsi-hubungkan`).
 *
 * Aturan legacy [K] dengan pola keamanan v2: non-GET, `tag` disanitasi, cek role + lingkup ubah + kepemilikan baris di
 * setiap aksi (anti-IDOR `id_attachment = ? AND NIP = ?`), perubahan baris lewat `DocumentAttachmentModel` (audit
 * `update`/`delete` otomatis). Tanpa cek antrean SIASN (risiko diterima §6.6): `siasn_upload_arsip` tidak dibaca/ditulis.
 *
 * Urutan galat (F10-38 (1)):
 *  - Hubungkan: 403 role/lingkup → 404 NIP → 404 baris arsip → 422 bukan kode 0 (`errors.id_attachment`, pesan legacy) /
 *    jenis di luar F10-28 (`errors.id_riwayat`) / data riwayat kosong (`errors.id_entri`) → 404 riwayat tujuan (bukan
 *    milik NIP, bukan Disetujui, tidak ada) → 403 kunci (UL_PEGAWAI + Definisi `kunciBarisDisetujui()`).
 *  - Ubah Nama: 403 role/lingkup → 404 NIP → 404 baris → 403 `ArsipDataUmum::bolehTulis()` (kode 37) → 422 `errors.tag`.
 *  - Hapus: 403 role/lingkup → 404 NIP → 404 baris → 422 bukan kode 0.
 * Di dalam transaksi, urutan kunci sama dengan B-18: (a) `pegawai` {nip} `FOR UPDATE`, (b) `document_attachment` {id}
 * `FOR UPDATE`, (c) baris riwayat tujuan `FOR UPDATE` (Hubungkan); pemeriksaan kode 0 / Disetujui / kunci diulang.
 * Hapus = baris DB saja, berkas fisik TERTINGGAL [K] (bukan `AttachmentService::hapus()`).
 */
final class AksiArsip
{
    public const PESAN_SUDAH_TERHUBUNG = 'Data arsip ini telah terhubung ke data riwayat pegawai. Silahkan hubungkan data arsip lain yang belum terhubung ke data riwayat.';

    public const TAG_MAKS = 255;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly OtorisasiArsip $otorisasi,
    ) {
    }

    /**
     * `PATCH pegawai/{nip}/arsip/berkas/{id}` `{ tag }`.
     *
     * @param array<string, mixed> $input
     */
    public function ubahNama(AuthContext $auth, string $nip, int $id, array $input): void
    {
        $this->otorisasi->bolehTulis($auth, $nip);
        $row = $this->baris($nip, $id, false);
        $this->bolehUbahNama($auth, $nip, $row);
        $tag = self::tagBersih($input['tag'] ?? null);

        $this->dalamTransaksi(function () use ($auth, $nip, $id, $tag): void {
            $this->kunciPegawai($nip);
            $this->bolehUbahNama($auth, $nip, $this->baris($nip, $id, true));
            $this->tulis($id, ['tag' => $tag]);
        });
    }

    /**
     * `POST pegawai/{nip}/arsip/berkas/{id}/hubungkan` `{ id_riwayat, id_entri? }`.
     *
     * @param array<string, mixed> $input
     */
    public function hubungkan(AuthContext $auth, string $nip, int $id, array $input): void
    {
        $this->otorisasi->bolehTulis($auth, $nip);
        self::wajibYatim($this->baris($nip, $id, false));
        $kode    = self::kodeTujuan($input['id_riwayat'] ?? null);
        $idEntri = $kode === ArsipDataUmum::KODE_ARSIP ? null : self::idEntri($input['id_entri'] ?? null);

        $definisi = $this->otorisasi->definisi($kode);
        $this->tujuanDisetujui($definisi, $kode, $nip, $idEntri, false);
        $this->bukanTerkunci($auth, $kode);

        $label = (string) ($this->db->table('jenis_rwy')->select('jenis_rwy')->where('id_jenis_rwy', $kode)->get()->getRowArray()['jenis_rwy'] ?? '');

        $this->dalamTransaksi(function () use ($auth, $nip, $id, $kode, $idEntri, $definisi, $label): void {
            $this->kunciPegawai($nip);
            self::wajibYatim($this->baris($nip, $id, true));
            $this->tujuanDisetujui($definisi, $kode, $nip, $idEntri, true);
            $this->bukanTerkunci($auth, $kode);
            $this->tulis($id, ['id_riwayat' => $kode, 'nama_riwayat' => $label === '' ? null : $label, 'id_entri' => $idEntri]);
        });
    }

    /**
     * `DELETE pegawai/{nip}/arsip/berkas/{id}` — baris kode 0 saja; berkas fisik tertinggal [K].
     *
     * @return array{id_attachment: int}
     */
    public function hapus(AuthContext $auth, string $nip, int $id): array
    {
        $this->otorisasi->bolehTulis($auth, $nip);
        self::wajibYatim($this->baris($nip, $id, false));

        $this->dalamTransaksi(function () use ($nip, $id): void {
            $this->kunciPegawai($nip);
            self::wajibYatim($this->baris($nip, $id, true));

            if ((new DocumentAttachmentModel($this->db))->delete($id) === false) {
                throw new RuntimeException('Arsip gagal dihapus.');
            }
        });

        return ['id_attachment' => $id];
    }

    /**
     * `GET pegawai/{nip}/arsip/opsi-hubungkan?id_riwayat=` — calon data riwayat Disetujui milik NIP, urut PK menurun [K
     * `list_arsip_rwy_data`]; kode 38 = tanpa data (`items` kosong). Kode terkunci bagi UL_PEGAWAI → 403.
     *
     * @return array{id_riwayat: int, butuh_data: bool, items: list<array{id_entri: string, ringkasan_riwayat: string}>}
     */
    public function opsiHubungkan(AuthContext $auth, string $nip, mixed $idRiwayat): array
    {
        $this->otorisasi->bolehTulis($auth, $nip);
        $kode = self::kodeTujuan($idRiwayat);
        $this->bukanTerkunci($auth, $kode);
        $definisi = $this->otorisasi->definisi($kode);

        if ($definisi === null) {
            return ['id_riwayat' => $kode, 'butuh_data' => false, 'items' => []];
        }

        $ids       = $this->entriDisetujui($definisi, $kode, $nip);
        $ringkasan = (new RingkasanRiwayat($this->db))->untukEntri($kode, $nip, $ids);

        return [
            'id_riwayat' => $kode,
            'butuh_data' => true,
            'items'      => array_map(static fn (string $id): array => ['id_entri' => $id, 'ringkasan_riwayat' => $ringkasan[$id] ?? RingkasanRiwayat::KOSONG], $ids),
        ];
    }

    /**
     * `tag` (AC2-08): tag HTML dan byte kontrol dibuang, spasi tepi dipangkas; 1–255 karakter, selain itu 422 `errors.tag`.
     */
    public static function tagBersih(mixed $tag): string
    {
        $teks = is_string($tag) || is_int($tag) ? (string) $tag : '';

        if (! mb_check_encoding($teks, 'UTF-8')) {
            throw ValidationException::forField('tag', 'Nama arsip tidak valid.');
        }

        $bersih = trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', '', strip_tags($teks)));

        if ($bersih === '') {
            throw ValidationException::forField('tag', 'Nama arsip wajib diisi.');
        }

        if (mb_strlen($bersih) > self::TAG_MAKS) {
            throw ValidationException::forField('tag', 'Nama arsip maksimal ' . self::TAG_MAKS . ' karakter.');
        }

        return $bersih;
    }

    /**
     * Kode tujuan Hubungkan: bilangan bulat di F10-28, selain itu 422 `errors.id_riwayat` [V2].
     */
    private static function kodeTujuan(mixed $nilai): int
    {
        $teks = is_int($nilai) || is_string($nilai) ? trim((string) $nilai) : '';

        if ($teks === '' || ! ctype_digit($teks) || strlen($teks) > 9 || ! in_array((int) $teks, OtorisasiArsip::KODE_HUBUNGKAN, true)) {
            throw ValidationException::forField('id_riwayat', 'Jenis riwayat tujuan tidak valid.');
        }

        return (int) $teks;
    }

    private static function idEntri(mixed $nilai): string
    {
        $teks = is_int($nilai) || is_string($nilai) ? trim((string) $nilai) : '';

        if ($teks === '' || strlen($teks) > 100) {
            throw ValidationException::forField('id_entri', 'Data riwayat wajib dipilih.');
        }

        return $teks;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function wajibYatim(array $row): void
    {
        if ($row['id_riwayat'] === null || (int) $row['id_riwayat'] !== 0) {
            throw ValidationException::forField('id_attachment', self::PESAN_SUDAH_TERHUBUNG);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function bolehUbahNama(AuthContext $auth, string $nip, array $row): void
    {
        if ($row['id_riwayat'] !== null && (int) $row['id_riwayat'] === ArsipDataUmum::KODE_STATUS
            && ! ArsipDataUmum::bolehTulis($auth, ArsipDataUmum::KODE_STATUS, $nip)) {
            throw new ForbiddenException(OtorisasiArsip::PESAN_DATA_UMUM);
        }
    }

    private function bukanTerkunci(AuthContext $auth, int $kode): void
    {
        if ($this->otorisasi->kodeTerkunci($auth, $kode)) {
            throw new ForbiddenException(OtorisasiArsip::PESAN_KUNCI);
        }
    }

    /**
     * Baris `document_attachment` {id} milik {nip}; 404 bila tidak ada / milik NIP lain.
     *
     * @return array<string, mixed>
     */
    private function baris(string $nip, int $id, bool $kunci): array
    {
        $t   = $this->db->protectIdentifiers('document_attachment', true);
        $row = $this->db->query(
            'SELECT ' . $this->db->escapeIdentifiers('id_attachment') . ', ' . $this->db->escapeIdentifiers('id_riwayat') . ' FROM ' . $t
            . ' WHERE ' . $this->db->escapeIdentifiers('id_attachment') . ' = ? AND ' . $this->db->escapeIdentifiers('NIP') . ' = ?' . ($kunci ? ' FOR UPDATE' : ''),
            [$id, $nip],
        )->getRowArray();

        if (! is_array($row)) {
            throw new NotFoundException('Arsip tidak ditemukan.');
        }

        return $row;
    }

    /**
     * Baris riwayat tujuan milik {nip} berstatus Disetujui (pola `OtorisasiLampiran::entriMilik`; kode 3 lewat induk);
     * 404 bila tidak. Kode tanpa Definisi (38) tidak punya baris tujuan.
     */
    private function tujuanDisetujui(?RiwayatDefinisi $definisi, int $kode, string $nip, ?string $idEntri, bool $kunci): void
    {
        if ($definisi === null || $idEntri === null) {
            return;
        }

        $db    = $this->db;
        $anak  = $definisi->entriLampiran($kode);
        $induk = $db->protectIdentifiers($definisi->tabel(), true);

        if ($anak === null) {
            $sql = 'SELECT ' . $db->protectIdentifiers($definisi->kolomStatus()) . ' AS status_baris FROM ' . $induk
                . ' WHERE ' . $db->protectIdentifiers($definisi->primaryKey()) . ' = ? AND ' . $db->protectIdentifiers($definisi->kolomNip()) . ' = ?';
        } else {
            $sql = 'SELECT i.' . $db->protectIdentifiers($definisi->kolomStatus()) . ' AS status_baris FROM ' . $db->protectIdentifiers($anak['tabel'], true) . ' a'
                . ' JOIN ' . $induk . ' i ON i.' . $db->protectIdentifiers($definisi->primaryKey()) . ' = a.' . $db->protectIdentifiers($anak['induk'])
                . ' WHERE a.' . $db->protectIdentifiers($anak['pk']) . ' = ? AND i.' . $db->protectIdentifiers($definisi->kolomNip()) . ' = ?';
        }

        $row    = $db->query($sql . ($kunci ? ' FOR UPDATE' : ''), [$idEntri, $nip])->getRowArray();
        $status = is_array($row) && $row['status_baris'] !== null ? ($definisi->pemetaanStatus()[(int) $row['status_baris']] ?? null) : null;

        if ($status !== StatusRiwayat::Disetujui) {
            throw new NotFoundException('Data riwayat tidak ditemukan.');
        }
    }

    /**
     * PK baris riwayat Disetujui milik {nip} untuk kode $kode, urut PK menurun.
     *
     * @return list<string>
     */
    private function entriDisetujui(RiwayatDefinisi $definisi, int $kode, string $nip): array
    {
        $mentah = array_keys(array_filter($definisi->pemetaanStatus(), static fn (StatusRiwayat $s): bool => $s === StatusRiwayat::Disetujui));

        if ($mentah === []) {
            return [];
        }

        $anak = $definisi->entriLampiran($kode);
        $pk   = $definisi->primaryKey();

        if ($anak === null) {
            $rows = $this->db->table($definisi->tabel())->select($pk . ' AS id')
                ->where($definisi->kolomNip(), $nip)->whereIn($definisi->kolomStatus(), $mentah)
                ->orderBy($pk, 'DESC')->get()->getResultArray();
        } else {
            $rows = $this->db->table($anak['tabel'] . ' a')->select('a.' . $anak['pk'] . ' AS id')
                ->join($definisi->tabel() . ' i', 'i.' . $pk . ' = a.' . $anak['induk'], 'inner')
                ->where('i.' . $definisi->kolomNip(), $nip)->whereIn('i.' . $definisi->kolomStatus(), $mentah)
                ->orderBy('a.' . $anak['pk'], 'DESC')->get()->getResultArray();
        }

        return array_map(static fn (array $r): string => (string) $r['id'], $rows);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function tulis(int $id, array $data): void
    {
        if ((new DocumentAttachmentModel($this->db))->update($id, $data) === false) {
            throw new RuntimeException('Arsip gagal diubah.');
        }
    }

    private function kunciPegawai(string $nip): void
    {
        $this->db->query('SELECT ' . $this->db->escapeIdentifiers('nip') . ' FROM ' . $this->db->protectIdentifiers('pegawai', true) . ' WHERE ' . $this->db->escapeIdentifiers('nip') . ' = ? FOR UPDATE', [$nip]);
    }

    /**
     * @param callable(): void $kerja
     */
    private function dalamTransaksi(callable $kerja): void
    {
        $this->db->transBegin();

        try {
            $kerja();

            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Transaksi arsip gagal.');
            }

            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        } finally {
            $this->db->resetTransStatus();
        }
    }
}
