<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg;

use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\PenjagaBerkas;
use App\Libraries\Kepegawaian\Lampiran\ValidasiBerkas;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Libraries\ListQuery;
use App\Models\HaloSimpeg\DisiplinTicketModel;
use App\Models\HaloSimpeg\DisiplinTicketMsgModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Throttle\ThrottlerInterface;
use Config\HaloSimpeg;
use Config\Services;
use Throwable;

/**
 * Tiket Halo Simpeg (E-03..E-05, MAKE-071; PRD Fase 8 v1.3 §6.1b, §6.2, §7.3–§7.5, F8-08..F8-11, F8-17, H8-16 (a1), D7 (a)).
 * Kontrak beku: `Controllers/Api/HaloSimpeg/README.md` bagian tiket.
 *
 * - Kewenangan objek dan flag `bisa_*` satu sumber di {@see AksesTiket}; tiket orang lain = 404 (BP8-2).
 * - BP8-1: hanya `disiplin_ticket(_msg)`; tidak pernah `inbox`/`inbox_msg`/`NotifikasiService`.
 * - Penanda baca §6.2: buka/baca pemilik → `is_read = 1` saja [K]; petugas aktif non-pemilik → `is_read_off = 1` [K]; balasan
 *   petugas → `is_read_off = 1` [K] + `is_read = 2` [I]; balasan pemilik → `is_read = 1` + `is_read_off = 2` [I] (H8-10).
 * - GET tidak mengubah data (AC-E04.8).
 * - Lampiran baru `halo-simpeg/{YYYY}/{MM}/{32 hex}.{ext}` (K-A6) dicatat `PenjagaBerkas`; lampiran lama (path legacy impor) dibaca
 *   lewat adapter yang sama; hapus tiket mencatat setiap path lalu `selesaikan()` sesudah commit (Q-A0-6 (a')).
 */
final class TiketService extends MasterHaloSimpeg
{
    public const PESAN_PEGAWAI = 'Data pegawai tidak ditemukan didalam sistem.';

    public const PESAN_THROTTLE_TIKET = 'Terlalu banyak pertanyaan baru. Coba lagi dalam 10 menit.';

    public const PESAN_THROTTLE_PESAN = 'Terlalu banyak pesan. Coba lagi dalam satu menit.';

    public const PESAN_LAMPIRAN_404 = 'Lampiran tidak ditemukan.';

    public const PESAN_PENGINGAT = 'Pengingat 24 jam hanya dapat diatur petugas.';

    public const ADMIN = 'Admin';

    /** Tipe MIME per ekstensi lampiran (unduh + `lampiran.tipe`). */
    public const MIME = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** Ekstensi yang ditampilkan inline di peramban; lainnya attachment (F8-17). */
    public const INLINE = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];

    private readonly StorageAdapterInterface $storage;

    private readonly ThrottlerInterface $throttler;

    public function __construct(?BaseConnection $db = null, ?HaloSimpeg $config = null, ?StorageAdapterInterface $storage = null, ?ThrottlerInterface $throttler = null)
    {
        parent::__construct($db, $config);
        $this->storage   = $storage ?? service('storageAdapter');
        $this->throttler = $throttler ?? Services::throttler();
    }

    // ================================================================================================== baca

    /**
     * `GET tiket`: `Halaman<TiketBaris>`.
     *
     * @param array<string, mixed> $query
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function daftar(AuthContext $auth, array $query): array
    {
        $akses   = $this->akses($auth);
        $p       = $this->listQuery($query, ['peran', 'status', 'kategori', 'q', 'baca', 'perlu_tindak']);
        $peran   = $p->string('peran') === '' ? 'saya' : $p->string('peran');
        $status  = $p->string('status');
        $kat     = $p->string('kategori');
        $baca    = $p->string('baca') === '' ? 'semua' : $p->string('baca');
        $tindak  = $p->string('perlu_tindak');
        $q       = $p->string('q');
        $errors  = [];
        $page    = $p->page();
        $perPage = $this->perHalaman($p);

        if (! in_array($peran, ['saya', 'petugas'], true)) {
            $errors['peran'] = 'Peran tidak valid.';
        }

        if (! in_array($status, ['', 'open', 'closed'], true)) {
            $errors['status'] = self::PESAN_STATUS;
        }

        if ($kat !== '' && preg_match('/^[1-9][0-9]{0,9}\z/', $kat) !== 1) {
            $errors['kategori'] = 'Kategori tidak valid.';
        }

        if (! in_array($baca, ['semua', 'belum', 'sudah'], true)) {
            $errors['baca'] = 'Filter baca tidak valid.';
        }

        if (! in_array($tindak, ['', '0', '1'], true)) {
            $errors['perlu_tindak'] = 'Filter perlu ditindak tidak valid.';
        } elseif ($tindak === '1' && $peran !== 'petugas') {
            $errors['perlu_tindak'] = 'Filter perlu ditindak hanya untuk peran petugas.';
        }

        self::lemparBilaGalat($errors);

        $petugas  = $peran === 'petugas';
        $kategori = $akses->kategoriPetugas();

        if ($petugas && $kategori === []) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }

        $saring = function (BaseBuilder $b) use ($akses, $petugas, $kategori, $status, $kat, $baca, $tindak, $q): BaseBuilder {
            if ($petugas) {
                // Sisi petugas: kategori dipegang, bukan milik sendiri (sama dengan RingkasanTiket).
                $b->whereIn('t.id_question_category', $kategori)->where('t.sender !=', $akses->idPengguna);
            } else {
                $b->where('t.sender', $akses->idPengguna);
            }

            if ($status !== '') {
                $b->where('t.status', $status === 'open' ? 0 : 1);
            }

            if ($kat !== '') {
                $b->where('t.id_question_category', (int) $kat);
            }

            if ($baca !== 'semua') {
                $b->where($petugas ? 't.is_read_off' : 't.is_read', $baca === 'belum' ? 2 : 1);
            }

            if ($tindak === '1') {
                $b->where('t.status', 0)->where(RingkasanTiket::sqlPerluTindak($this->db, 't'), null, false);
            }

            if ($q !== '') {
                $like = ListQuery::likeLiteral($this->db, $q);
                $msg  = $this->db->prefixTable('disiplin_ticket_msg');
                $b->groupStart()->like('t.subject', $like, 'both', null, true)
                    ->orWhere("EXISTS (SELECT 1 FROM {$msg} mq WHERE mq.id_inbox = t.id_inbox AND mq.msg LIKE " . $this->db->escape('%' . $like . '%') . " ESCAPE '!')", null, false)
                    ->groupEnd();
            }

            return $b;
        };

        $total = (int) $saring($this->db->table('disiplin_ticket t'))->countAllResults();
        $rows  = $saring($this->kueriBaris())
            ->orderBy('lm.created_at IS NULL', 'ASC', false)->orderBy('lm.created_at', 'DESC')->orderBy('t.id_inbox', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['items' => array_map(fn (array $r): array => $this->baris($r, $akses, $petugas), $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * `GET tiket/{id}`: `TiketDetail`, tanpa efek samping.
     *
     * @return array<string, mixed>
     */
    public function detail(AuthContext $auth, int $id): array
    {
        $akses = $this->akses($auth);
        $t     = $akses->wajibLihat($this->ambilBaris($id));

        return $this->susunDetail($t, $akses);
    }

    /**
     * `GET tiket/ringkasan` (§6.2).
     *
     * @return array{belum_dibaca_saya: int, belum_dibaca_petugas: int, perlu_tindak: int, badge: int, total: int}
     */
    public function ringkasan(AuthContext $auth): array
    {
        $akses = $this->akses($auth);

        return (new RingkasanTiket($this->db))->hitung($akses->idPengguna, $akses->kategoriPetugas());
    }

    /**
     * `GET tiket/{id}/pesan/{idPesan}/lampiran`: berkas lama dan baru lewat cek akses BP8-2 (AC-E04.7).
     *
     * @return array{isi: string, nama: string, mime: string, inline: bool}
     */
    public function lampiran(AuthContext $auth, int $id, int $idPesan): array
    {
        $this->akses($auth)->wajibLihat($this->ambilTiket($id));
        $m = $this->db->table('disiplin_ticket_msg')->select('id_inbox_msg, attachments')->where('id_inbox_msg', $idPesan)->where('id_inbox', $id)->get()->getRowArray();

        if (! is_array($m) || (string) ($m['attachments'] ?? '') === '') {
            throw new NotFoundException(self::PESAN_LAMPIRAN_404);
        }

        $path = (string) $m['attachments'];

        try {
            $isi = $this->storage->baca($path);
        } catch (Throwable) {
            // Path tidak sah menurut adapter (InvalidArgumentException) atau berkas tidak ada/tak terbaca (RuntimeException) = 404.
            throw new NotFoundException(self::PESAN_LAMPIRAN_404);
        }

        $ext = self::ekstensi($path);

        return [
            'isi'    => $isi,
            'nama'   => self::namaLampiran($id, $idPesan, $ext),
            'mime'   => self::MIME[$ext] ?? 'application/octet-stream',
            'inline' => in_array($ext, self::INLINE, true),
        ];
    }

    // ================================================================================================== tulis

    /**
     * `POST tiket` (role 2/6/7): 201 `TiketDetail`. Throttle dicek sebelum unggah; berkas disimpan sebelum transaksi (urutan
     * legacy) dan dikompensasi `PenjagaBerkas` bila transaksi gagal (AC-E03.6).
     *
     * @param array<string, mixed> $in `id_question_category`, `subject`, `msg`
     *
     * @return array<string, mixed>
     */
    public function buat(AuthContext $auth, array $in, ?UploadedFile $arsip): array
    {
        $akses = $this->akses($auth);
        $this->wajibThrottle('hs_tiket_' . $akses->idPengguna, $this->config->throttleTiketKapasitas, $this->config->throttleTiketDetik, self::PESAN_THROTTLE_TIKET);

        $pegawai = $this->pegawai($auth->nip());

        if ($pegawai === null) {
            throw new ValidationException(self::PESAN_PEGAWAI);
        }

        $errors   = [];
        $kategori = self::teksDari($in, 'id_question_category', $errors);
        $subjek   = self::teksDari($in, 'subject', $errors);
        $pesan    = self::teksDari($in, 'msg', $errors);

        if (! isset($errors['id_question_category'])) {
            if ($kategori === '') {
                $errors['id_question_category'] = 'Kategori wajib dipilih.';
            } elseif (preg_match('/^[1-9][0-9]{0,9}\z/', $kategori) !== 1
                || $this->db->table('question_category')->where('id_question_category', (int) $kategori)->where('status', self::STATUS_AKTIF)->countAllResults() === 0) {
                $errors['id_question_category'] = 'Kategori tidak tersedia.';
            }
        }

        if (! isset($errors['subject'])) {
            if ($subjek === '') {
                $errors['subject'] = 'Subjek wajib diisi.';
            } elseif (mb_strlen($subjek) > $this->config->maksSubjek) {
                $errors['subject'] = "Subjek maksimal {$this->config->maksSubjek} karakter.";
            }
        }

        $this->periksaPesan($pesan, $errors);
        self::lemparBilaGalat($errors);

        $berkas  = $this->periksaBerkas($arsip);
        $penjaga = new PenjagaBerkas($this->storage, $this->db);
        $path    = $berkas === null ? null : $this->simpanBerkas($berkas, $penjaga);
        $now     = self::sekarang();

        try {
            $id = (int) $this->dalamTransaksi(function () use ($akses, $pegawai, $kategori, $subjek, $pesan, $path, $now): int {
                $id = (int) (new DisiplinTicketModel($this->db))->insert([
                    'id_question_category' => (int) $kategori,
                    'subject'              => $subjek,
                    'sender'               => $akses->idPengguna,
                    'sender_name'          => $pegawai['nama'],
                    'nip'                  => $pegawai['nip'],
                    'jabatan'              => $pegawai['jabatan'],
                    'satker'               => $pegawai['satker'],
                    'receiver'             => 0,
                    'receiver_name'        => null,
                    'allow_reply'          => 1,
                    'status'               => 0,
                    'is_read'              => 1,
                    'is_read_off'          => 2,
                    'notify_pic'           => 2,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ], true);
                (new DisiplinTicketMsgModel($this->db))->insert([
                    'id_inbox'          => $id,
                    'msg_sender'        => $akses->idPengguna,
                    'msg_sender_name'   => $pegawai['nama'],
                    'msg_receiver'      => 0,
                    'msg_receiver_name' => null,
                    'msg'               => $pesan,
                    'attachments'       => $path,
                    'created_at'        => $now,
                ]);

                return $id;
            });
        } finally {
            $penjaga->selesaikan();
        }

        return $this->susunDetail($this->ambilBaris($id) ?? throw new NotFoundException(AksesTiket::PESAN_404), $akses);
    }

    /**
     * `POST tiket/{id}/pesan`: 201 `{pesan: Pesan, tiket: TiketBaris}`. Cek boleh balas di dalam transaksi sesudah tiket dikunci
     * (balapan dengan tutup).
     *
     * @param array<string, mixed> $in `msg`, `ingatkan_24_jam?`
     *
     * @return array{pesan: array<string, mixed>, tiket: array<string, mixed>}
     */
    public function balas(AuthContext $auth, int $id, array $in, ?UploadedFile $arsip): array
    {
        $akses = $this->akses($auth);
        $akses->wajibBalas($akses->wajibLihat($this->ambilTiket($id)));
        $this->wajibThrottle('hs_pesan_' . $akses->idPengguna, $this->config->throttlePesanKapasitas, $this->config->throttlePesanDetik, self::PESAN_THROTTLE_PESAN);

        $errors   = [];
        $pesan    = self::teksDari($in, 'msg', $errors);
        $ingat    = array_key_exists('ingatkan_24_jam', $in) && $in['ingatkan_24_jam'] !== null && $in['ingatkan_24_jam'] !== '' ? $in['ingatkan_24_jam'] : '0';
        $ingatkan = in_array($ingat, [0, 1, '0', '1'], true) ? (int) $ingat : null;
        $this->periksaPesan($pesan, $errors);

        if ($ingatkan === null) {
            $errors['ingatkan_24_jam'] = 'Nilai pengingat tidak valid.';
        }

        $t       = $this->ambilTiket($id) ?? throw new NotFoundException(AksesTiket::PESAN_404);
        $petugas = ! $akses->pemilik($t) && $akses->petugasTiket($t);

        if ($ingatkan === 1 && ! $petugas) {
            $errors['ingatkan_24_jam'] = self::PESAN_PENGINGAT;
        }

        self::lemparBilaGalat($errors);

        $berkas  = $this->periksaBerkas($arsip);
        $penjaga = new PenjagaBerkas($this->storage, $this->db);
        $path    = $berkas === null ? null : $this->simpanBerkas($berkas, $penjaga);
        $now     = self::sekarang();
        $nama    = $this->namaAkun($akses->idPengguna);

        try {
            $idPesan = (int) $this->dalamTransaksi(function () use ($akses, $id, $pesan, $ingatkan, $path, $now, $nama): int {
                $this->kunci('disiplin_ticket', 'id_inbox', [$id]);
                $t = $akses->wajibLihat($this->ambilTiket($id));
                $akses->wajibBalas($t);
                $pemilik = $akses->pemilik($t);

                $idPesan = (int) (new DisiplinTicketMsgModel($this->db))->insert([
                    'id_inbox'          => $id,
                    'msg_sender'        => $akses->idPengguna,
                    'msg_sender_name'   => $nama,
                    'msg_receiver'      => $pemilik ? (int) $t['receiver'] : (int) $t['sender'],
                    'msg_receiver_name' => $pemilik ? ($t['receiver_name'] ?? null) : $t['sender_name'],
                    'msg'               => $pesan,
                    'attachments'       => $path,
                    'created_at'        => $now,
                ], true);

                $ubah = $pemilik
                    ? ['is_read' => 1, 'is_read_off' => 2]
                    : ['is_read_off' => 1, 'is_read' => 2, 'notify_pic' => $ingatkan === 1 ? 1 : 2, 'notify_at' => $ingatkan === 1 ? gmdate('Y-m-d H:i:s', time() + 86400) : null];
                (new DisiplinTicketModel($this->db))->update($id, $ubah + ['updated_at' => $now]);

                return $idPesan;
            });
        } finally {
            $penjaga->selesaikan();
        }

        $t         = $this->ambilBaris($id) ?? throw new NotFoundException(AksesTiket::PESAN_404);
        $pesanList = $this->susunPesan($t, $akses);
        $satu      = array_values(array_filter($pesanList, static fn (array $p): bool => $p['id_inbox_msg'] === $idPesan));

        return ['pesan' => $satu[0], 'tiket' => $this->baris($t, $akses, $this->sisiPetugas($t, $akses))];
    }

    /**
     * `POST tiket/{id}/baca` (§6.2 E7): pemilik → `is_read = 1` saja; petugas aktif non-pemilik → `is_read_off = 1`; idempoten.
     *
     * @return array{belum_dibaca_saya: int, belum_dibaca_petugas: int, perlu_tindak: int, badge: int, total: int}
     */
    public function baca(AuthContext $auth, int $id): array
    {
        $akses = $this->akses($auth);
        $t     = $akses->wajibLihat($this->ambilTiket($id));

        if ($akses->pemilik($t)) {
            if ((int) $t['is_read'] !== 1) {
                (new DisiplinTicketModel($this->db))->update($id, ['is_read' => 1]);
            }
        } elseif ((int) $t['is_read_off'] !== 1) {
            (new DisiplinTicketModel($this->db))->update($id, ['is_read_off' => 1]);
        }

        return $this->ringkasan($auth);
    }

    /**
     * `POST tiket/{id}/tutup` (F8-08): `status = 1`, `allow_reply = 0`, `is_read = 1`, `is_read_off = 1` [K `LS:1444-1449`] +
     * `closed_by` [V2]. 200 `TiketBaris`.
     *
     * @return array<string, mixed>
     */
    public function tutup(AuthContext $auth, int $id): array
    {
        $akses = $this->akses($auth);
        $akses->wajibTutup($akses->wajibLihat($this->ambilTiket($id)));

        $this->dalamTransaksi(function () use ($akses, $id): void {
            $this->kunci('disiplin_ticket', 'id_inbox', [$id]);
            $akses->wajibTutup($akses->wajibLihat($this->ambilTiket($id)));
            (new DisiplinTicketModel($this->db))->update($id, [
                'status' => 1, 'allow_reply' => 0, 'is_read' => 1, 'is_read_off' => 1, 'closed_by' => $akses->idPengguna, 'updated_at' => self::sekarang(),
            ]);
        });

        $t = $this->ambilBaris($id) ?? throw new NotFoundException(AksesTiket::PESAN_404);

        return $this->baris($t, $akses, $this->sisiPetugas($t, $akses));
    }

    /**
     * `DELETE tiket/{id}` (F8-09): hanya petugas tiket. Pesan lalu tiket dihapus dalam satu transaksi (FK RESTRICT); setiap path
     * lampiran dicatat `PenjagaBerkas` lalu `selesaikan()` sesudah commit — berkas dihapus hanya bila tidak dirujuk baris lain, path
     * legacy yang ditolak adapter tidak dihapus dan dicatat tanpa path (Q-A0-6 (a')). 200 `{id}`.
     *
     * @return array{id: int}
     */
    public function hapus(AuthContext $auth, int $id): array
    {
        $akses = $this->akses($auth);
        $akses->wajibHapus($akses->wajibLihat($this->ambilTiket($id)));
        $penjaga = new PenjagaBerkas($this->storage, $this->db);

        try {
            $this->dalamTransaksi(function () use ($akses, $id, $penjaga): void {
                $this->kunci('disiplin_ticket', 'id_inbox', [$id]);
                $akses->wajibHapus($akses->wajibLihat($this->ambilTiket($id)));
                $pesan = $this->db->table('disiplin_ticket_msg')->select('id_inbox_msg, attachments')->where('id_inbox', $id)->get()->getResultArray();

                foreach ($pesan as $m) {
                    if ((string) ($m['attachments'] ?? '') !== '') {
                        $penjaga->catat((string) $m['attachments'], 'disiplin_ticket_msg', 'attachments');
                    }
                }

                if ($pesan !== []) {
                    (new DisiplinTicketMsgModel($this->db))->delete(array_map(static fn (array $m): int => (int) $m['id_inbox_msg'], $pesan));
                }

                (new DisiplinTicketModel($this->db))->delete($id);
            });
        } finally {
            $penjaga->selesaikan();
        }

        return ['id' => $id];
    }

    // ================================================================================================== bentuk respons

    /**
     * `TiketBaris`.
     *
     * @param array<string, mixed> $r baris {@see kueriBaris()}
     *
     * @return array<string, mixed>
     */
    private function baris(array $r, AksesTiket $akses, bool $sisiPetugas): array
    {
        $pesan = null;

        if ($r['lm_id'] !== null) {
            $lampiran = (string) ($r['lm_lampiran'] ?? '');
            $pesan    = [
                'id_inbox_msg' => (int) $r['lm_id'],
                'waktu'        => self::iso($r['lm_waktu']),
                'potongan'     => mb_substr((string) $r['lm_msg'], 0, $this->config->panjangPotongan),
                // D7 (a) daftar: nama hanya bila pengirim = pemilik; selain itu "Admin" bagi semua peninjau [K `VI:110-117`].
                'pengirim_label' => (int) $r['lm_sender'] === (int) $r['sender'] ? (string) $r['lm_sender_name'] : self::ADMIN,
                'lampiran'       => $lampiran === '' ? null : ['nama' => self::namaLampiran((int) $r['id_inbox'], (int) $r['lm_id'], self::ekstensi($lampiran)), 'tipe' => self::MIME[self::ekstensi($lampiran)] ?? 'application/octet-stream'],
            ];
        }

        return [
            'id'              => (int) $r['id_inbox'],
            'subjek'          => (string) $r['subject'],
            'kategori'        => $r['id_question_category'] === null ? null : ['id' => (int) $r['id_question_category'], 'nama' => (string) ($r['nama_kategori'] ?? '')],
            'status'          => (int) $r['status'] === 0 ? 'open' : 'closed',
            'dibuat_pada'     => self::iso($r['created_at']),
            'pemilik_nama'    => (string) $r['sender_name'],
            'sebagai_petugas' => $sisiPetugas,
            'belum_dibaca'    => $sisiPetugas ? (int) $r['is_read_off'] === 2 : (int) $r['is_read'] === 2,
            'perlu_tindak'    => $sisiPetugas && (int) $r['perlu_tindak_sql'] === 1,
            'pesan_terakhir'  => $pesan,
        ] + $akses->flag($r);
    }

    /**
     * `TiketDetail`.
     *
     * @param array<string, mixed> $t baris {@see kueriBaris()}
     *
     * @return array<string, mixed>
     */
    private function susunDetail(array $t, AksesTiket $akses): array
    {
        $sisiPetugas = $this->sisiPetugas($t, $akses);

        return $this->baris($t, $akses, $sisiPetugas) + [
            'allow_reply' => (int) $t['allow_reply'] === 1,
            // [K `VD:21-26`]: hanya petugas tiket (termasuk role 1 yang petugas); null bagi pemilik.
            'snapshot'           => $sisiPetugas ? ['nip' => (string) $t['nip'], 'jabatan' => $t['jabatan'] ?? null, 'satker' => $t['satker'] ?? null] : null,
            'ditutup_oleh_label' => $this->ditutupOleh($t, $akses),
            'pengingat'          => $sisiPetugas ? ['aktif' => (int) $t['notify_pic'] === 1, 'jatuh_tempo' => self::iso($t['notify_at'] ?? null)] : null,
            'pesan'              => $this->susunPesan($t, $akses),
        ];
    }

    /**
     * `Pesan[]` urut waktu naik, id naik. D7 (a) detail: nama bila pengirim = pemilik atau peninjau role 1; selain itu "Admin"
     * [K `VD:48-55`]. `urutan_pengirim` 0 = pemilik, petugas 1..n menurut kemunculan pertama (B-23).
     *
     * @param array<string, mixed> $t
     *
     * @return list<array<string, mixed>>
     */
    private function susunPesan(array $t, AksesTiket $akses): array
    {
        $rows = $this->db->table('disiplin_ticket_msg')->select('id_inbox_msg, msg_sender, msg_sender_name, msg, attachments, created_at')
            ->where('id_inbox', (int) $t['id_inbox'])->orderBy('created_at', 'ASC')->orderBy('id_inbox_msg', 'ASC')->get()->getResultArray();
        $urutan = [];
        $hasil  = [];

        foreach ($rows as $m) {
            $pengirim    = (int) $m['msg_sender'];
            $dariPemilik = $pengirim === (int) $t['sender'];

            if (! $dariPemilik && ! isset($urutan[$pengirim])) {
                $urutan[$pengirim] = count($urutan) + 1;
            }

            $lampiran = (string) ($m['attachments'] ?? '');
            $ext      = self::ekstensi($lampiran);
            $hasil[]  = [
                'id_inbox_msg'    => (int) $m['id_inbox_msg'],
                'waktu'           => self::iso($m['created_at']),
                'isi'             => (string) $m['msg'],
                'pengirim_label'  => $dariPemilik || $akses->role === 1 ? (string) $m['msg_sender_name'] : self::ADMIN,
                'milik_saya'      => $pengirim === $akses->idPengguna,
                'dari_pemilik'    => $dariPemilik,
                'urutan_pengirim' => $dariPemilik ? 0 : $urutan[$pengirim],
                'lampiran'        => $lampiran === '' ? null : ['nama' => self::namaLampiran((int) $t['id_inbox'], (int) $m['id_inbox_msg'], $ext), 'tipe' => self::MIME[$ext] ?? 'application/octet-stream', 'ukuran' => null],
            ];
        }

        return $hasil;
    }

    /**
     * `ditutup_oleh_label` [V2] aturan detail D7 (a): null bila Open / `closed_by` kosong; nama bila penutup = pemilik atau peninjau
     * role 1; selain itu "Admin".
     *
     * @param array<string, mixed> $t
     */
    private function ditutupOleh(array $t, AksesTiket $akses): ?string
    {
        if ((int) $t['status'] !== 1 || $t['closed_by'] === null) {
            return null;
        }

        if ((int) $t['closed_by'] === (int) $t['sender']) {
            return (string) $t['sender_name'];
        }

        return $akses->role === 1 ? $this->namaAkun((int) $t['closed_by']) : self::ADMIN;
    }

    // ================================================================================================== bantu

    private function akses(AuthContext $auth): AksesTiket
    {
        return new AksesTiket($this->db, (int) $auth->idPengguna(), (int) $auth->role());
    }

    /**
     * Sisi tiket bagi peninjau: petugas bila ia petugas tiket dan bukan pemilik (§6.2: tiket milik sendiri dihitung di sisi pemilik).
     *
     * @param array<string, mixed> $t
     */
    private function sisiPetugas(array $t, AksesTiket $akses): bool
    {
        return ! $akses->pemilik($t) && $akses->petugasTiket($t);
    }

    /**
     * Baris tiket + kategori + pesan terakhir (satu subkueri `ORDER BY created_at DESC, id_inbox_msg DESC LIMIT 1`, tanpa N+1) +
     * ekspresi `perlu_tindak` yang sama dengan RingkasanTiket.
     */
    private function kueriBaris(): BaseBuilder
    {
        $msg = $this->db->prefixTable('disiplin_ticket_msg');

        return $this->db->table('disiplin_ticket t')
            ->select('t.id_inbox, t.id_question_category, t.subject, t.sender, t.sender_name, t.nip, t.jabatan, t.satker, t.receiver, t.receiver_name, '
                . 't.allow_reply, t.status, t.is_read, t.is_read_off, t.notify_pic, t.notify_at, t.created_at, t.closed_by, qc.question_category AS nama_kategori, '
                . 'lm.id_inbox_msg AS lm_id, lm.created_at AS lm_waktu, lm.msg AS lm_msg, lm.msg_sender AS lm_sender, lm.msg_sender_name AS lm_sender_name, lm.attachments AS lm_lampiran')
            ->select('(t.status = 0 AND ' . RingkasanTiket::sqlPerluTindak($this->db, 't') . ') AS perlu_tindak_sql', false)
            ->join('question_category qc', 'qc.id_question_category = t.id_question_category', 'left')
            ->join('disiplin_ticket_msg lm', "lm.id_inbox_msg = (SELECT m.id_inbox_msg FROM {$msg} m WHERE m.id_inbox = t.id_inbox ORDER BY m.created_at DESC, m.id_inbox_msg DESC LIMIT 1)", 'left', false);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ambilBaris(int $id): ?array
    {
        $r = $this->kueriBaris()->where('t.id_inbox', $id)->get()->getRowArray();

        return is_array($r) ? $r : null;
    }

    /**
     * Baris tiket mentah (untuk cek akses/tulis).
     *
     * @return array<string, mixed>|null
     */
    private function ambilTiket(int $id): ?array
    {
        $r = $this->db->table('disiplin_ticket')->where('id_inbox', $id)->get()->getRowArray();

        return is_array($r) ? $r : null;
    }

    /**
     * Pelapor: pegawai ber-NIP + snapshot jabatan aktif [K `LS:1538-1547`, `SC:612-617`].
     *
     * @return array{nip: string, nama: string, jabatan: string|null, satker: string|null}|null
     */
    private function pegawai(?string $nip): ?array
    {
        if ($nip === null || $nip === '') {
            return null;
        }

        $p = $this->db->table('pegawai p')->select('p.nip, p.glr_awal, p.nama, p.glr_akhir, pmj.jabatan, pmj.satker')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = p.nip', 'left')->where('p.nip', $nip)->get()->getRowArray();

        if (! is_array($p)) {
            return null;
        }

        return [
            'nip'     => (string) $p['nip'],
            'nama'    => StrukturService::namaGelar($p['glr_awal'], (string) $p['nama'], $p['glr_akhir']),
            'jabatan' => $p['jabatan'] === null || $p['jabatan'] === '' ? null : (string) $p['jabatan'],
            'satker'  => $p['satker'] === null || $p['satker'] === '' ? null : (string) $p['satker'],
        ];
    }

    /** Nama akun untuk pesan/penutup: nama bergelar bila akun ber-NIP terdaftar di pegawai (B-28), selain itu nama akun/username. */
    private function namaAkun(int $idPengguna): string
    {
        $u = $this->db->table('pengguna u')->select('u.name, u.username, p.glr_awal, p.nama, p.glr_akhir')
            ->join('pegawai p', 'p.nip = u.nip', 'left')->where('u.id_pengguna', $idPengguna)->get()->getRowArray();

        if (! is_array($u)) {
            return self::ADMIN;
        }

        if ($u['nama'] !== null) {
            return StrukturService::namaGelar($u['glr_awal'], (string) $u['nama'], $u['glr_akhir']);
        }

        return trim((string) ($u['name'] ?? '')) !== '' ? trim((string) $u['name']) : (string) $u['username'];
    }

    /**
     * @param array<string, string> $errors
     */
    private function periksaPesan(string $pesan, array &$errors): void
    {
        if (isset($errors['msg'])) {
            return;
        }

        if ($pesan === '') {
            $errors['msg'] = 'Pesan wajib diisi.';
        } elseif (mb_strlen($pesan) > $this->config->maksPesan) {
            $errors['msg'] = 'Pesan maksimal ' . number_format($this->config->maksPesan, 0, ',', '.') . ' karakter.';
        }
    }

    /**
     * @return array{isi: string, ekstensi: string}|null
     */
    private function periksaBerkas(?UploadedFile $arsip): ?array
    {
        if ($arsip === null || $arsip->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $hasil = ValidasiBerkas::periksa($arsip, new AturanLampiran(0, false, $this->config->lampiranMaksMb, $this->config->lampiranEkstensi), 'arsip');

        return ['isi' => $hasil['isi'], 'ekstensi' => $hasil['ekstensi']];
    }

    /**
     * Simpan berkas baru dengan kunci K-A6 lalu catat di PenjagaBerkas (dihapus bila baris tidak jadi tertulis).
     *
     * @param array{isi: string, ekstensi: string} $berkas
     */
    private function simpanBerkas(array $berkas, PenjagaBerkas $penjaga): string
    {
        $path = 'halo-simpeg/' . gmdate('Y') . '/' . gmdate('m') . '/' . bin2hex(random_bytes(16)) . '.' . $berkas['ekstensi'];
        $this->storage->simpan($path, $berkas['isi']);
        $penjaga->catat($path, 'disiplin_ticket_msg', 'attachments');

        return $path;
    }

    private function wajibThrottle(string $kunci, int $kapasitas, int $detik, string $pesan): void
    {
        if (! $this->throttler->check($kunci, $kapasitas, $detik, 1)) {
            throw new ApiException($pesan, 429, null, 'throttle');
        }
    }

    private static function ekstensi(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    public static function namaLampiran(int $idTiket, int $idPesan, string $ext): string
    {
        return "lampiran-{$idTiket}-{$idPesan}" . ($ext === '' ? '' : ".{$ext}");
    }
}
