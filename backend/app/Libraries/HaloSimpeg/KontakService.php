<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg;

use App\Exceptions\ApiException;
use App\Libraries\ListQuery;
use App\Models\HaloSimpeg\HelpDeskContactModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Master kontak helpdesk Halo Simpeg (E-07, MAKE-070; PRD Fase 8 v1.3 §6.1, §6.1b `Kontak`, §7.2, F8-18; [K `LS:14-210`,
 * `SC:11-160`]).
 *
 * - Dua path baca (Q-A0-7): `kontak/aktif` (role 1–7, array status 1 tanpa status/stempel) dan `kontak` (role 1, semua status,
 *   `Halaman<Kontak>`, filter `status`, `q`). Urutan `created_at` naik [K `LS:16`], pemutus seri `id` naik (DBV-028 N3).
 * - `name` wajib ≤ 150, `contact` wajib ≤ 20 dan unik (cek aplikasi [K `LS:176-197`] + UNIQUE DBV-028; 1062 → 422 yang sama),
 *   `email` opsional ≤ 150 berformat email (form legacy tanpa validasi, `help_desk/form.php:52-56`).
 * - Hapus fisik [K `LS:136`].
 */
final class KontakService extends MasterHaloSimpeg
{
    public const PESAN_DUPLIKAT = 'Nomor kontak sudah terdaftar.';

    private const KOLOM = 'id_help_desk_contact, name, contact, email, status, created_at, updated_at';

    /**
     * @param array<string, mixed> $query
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function daftar(array $query): array
    {
        $p       = $this->listQuery($query, ['status', 'q']);
        $status  = $this->filterStatus($p);
        $q       = $p->string('q');
        $page    = $p->page();
        $perPage = $this->perHalaman($p);

        $saring = function (BaseBuilder $b) use ($status, $q): BaseBuilder {
            if ($status !== null) {
                $b->where('status', $status);
            }

            if ($q !== '') {
                $like = ListQuery::likeLiteral($this->db, $q);
                $b->groupStart()->like('name', $like, 'both', null, true)->orLike('contact', $like, 'both', null, true)
                    ->orLike('email', $like, 'both', null, true)->groupEnd();
            }

            return $b;
        };

        $total = (int) $saring($this->db->table('help_desk_contact'))->countAllResults();
        $rows  = $saring($this->db->table('help_desk_contact')->select(self::KOLOM))
            ->orderBy('created_at', 'ASC')->orderBy('id_help_desk_contact', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['items' => array_map(static fn (array $r): array => self::baris($r), $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * `GET kontak/aktif` (role 1–7): kontak status 1, tanpa status/stempel.
     *
     * @return list<array{id: int, nama: string, kontak: string, email: string|null}>
     */
    public function aktif(): array
    {
        $rows = $this->db->table('help_desk_contact')->select(self::KOLOM)->where('status', self::STATUS_AKTIF)
            ->orderBy('created_at', 'ASC')->orderBy('id_help_desk_contact', 'ASC')->get()->getResultArray();

        return array_map(static function (array $r): array {
            $b = self::baris($r);

            return ['id' => $b['id'], 'nama' => $b['nama'], 'kontak' => $b['kontak'], 'email' => $b['email']];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $in `name`, `contact`, `email?`, `status?` (bawaan 1)
     *
     * @return array<string, mixed>
     */
    public function buat(array $in): array
    {
        $data = $this->validasi($in, null);
        $now  = self::sekarang();
        $id   = (int) $this->dalamTransaksi(
            fn () => (new HelpDeskContactModel($this->db))->insert($data + ['created_at' => $now, 'updated_at' => $now], true),
            static fn (DatabaseException $e): ?ApiException => self::duplikatKunci($e, 'uk_help_desk_contact_contact') ? self::galatKode('kontak_duplikat', self::PESAN_DUPLIKAT, 'contact') : null,
        );

        return $this->detail($id);
    }

    /**
     * @param array<string, mixed> $in `name`, `contact`, `email?`, `status?` (bawaan nilai lama)
     *
     * @return array<string, mixed>
     */
    public function ubah(int $id, array $in): array
    {
        $data = $this->validasi($in, $this->ambil($id));
        $this->dalamTransaksi(
            fn () => (new HelpDeskContactModel($this->db))->update($id, $data + ['updated_at' => self::sekarang()]),
            static fn (DatabaseException $e): ?ApiException => self::duplikatKunci($e, 'uk_help_desk_contact_contact') ? self::galatKode('kontak_duplikat', self::PESAN_DUPLIKAT, 'contact') : null,
        );

        return $this->detail($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function setStatus(int $id, mixed $status): array
    {
        $lama = $this->ambil($id);
        $baru = self::statusDari($status) ?? throw self::galatStatus();

        if ($baru !== (int) $lama['status']) {
            (new HelpDeskContactModel($this->db))->update($id, ['status' => $baru, 'updated_at' => self::sekarang()]);
        }

        return $this->detail($id) + ['peringatan' => null];
    }

    /**
     * @return array{id: int}
     */
    public function hapus(int $id): array
    {
        $this->ambil($id);
        (new HelpDeskContactModel($this->db))->delete($id);

        return ['id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(int $id): array
    {
        return self::baris($this->ambil($id));
    }

    // ------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed>      $in
     * @param array<string, mixed>|null $lama
     *
     * @return array{name: string, contact: string, email: string|null, status: int}
     */
    private function validasi(array $in, ?array $lama): array
    {
        $errors = [];
        $nama   = self::teksDari($in, 'name', $errors);
        $kontak = self::teksDari($in, 'contact', $errors);
        $email  = self::teksDari($in, 'email', $errors);
        $status = array_key_exists('status', $in) ? self::statusDari($in['status']) : ($lama === null ? self::STATUS_AKTIF : (int) $lama['status']);

        if (isset($errors['name'])) {
            // Bukan teks (M4).
        } elseif ($nama === '') {
            $errors['name'] = 'Nama wajib diisi.';
        } elseif (mb_strlen($nama) > $this->config->maksNamaKontak) {
            $errors['name'] = "Nama maksimal {$this->config->maksNamaKontak} karakter.";
        }

        if (isset($errors['contact'])) {
            // Bukan teks (M4).
        } elseif ($kontak === '') {
            $errors['contact'] = 'Nomor kontak wajib diisi.';
        } elseif (mb_strlen($kontak) > $this->config->maksNomorKontak) {
            $errors['contact'] = "Nomor kontak maksimal {$this->config->maksNomorKontak} karakter.";
        }

        if (isset($errors['email'])) {
            // Bukan teks (M4).
        } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Format email tidak valid.';
        } elseif (mb_strlen($email) > $this->config->maksEmail) {
            $errors['email'] = "Email maksimal {$this->config->maksEmail} karakter.";
        }

        if ($status === null) {
            $errors['status'] = self::PESAN_STATUS;
        }

        self::lemparBilaGalat($errors);

        $dup = $this->db->table('help_desk_contact')->where('contact', $kontak);

        if ($lama !== null) {
            $dup->where('id_help_desk_contact !=', (int) $lama['id_help_desk_contact']);
        }

        if ($dup->countAllResults() > 0) {
            throw self::galatKode('kontak_duplikat', self::PESAN_DUPLIKAT, 'contact');
        }

        return ['name' => $nama, 'contact' => $kontak, 'email' => $email === '' ? null : $email, 'status' => (int) $status];
    }

    /**
     * @return array<string, mixed>
     */
    private function ambil(int $id): array
    {
        $r = $this->db->table('help_desk_contact')->select(self::KOLOM)->where('id_help_desk_contact', $id)->get()->getRowArray();

        if (! is_array($r)) {
            throw self::tidakDitemukan();
        }

        return $r;
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array{id: int, nama: string, kontak: string, email: string|null, status: int, dibuat_pada: string|null, diubah_pada: string|null}
     */
    private static function baris(array $r): array
    {
        return [
            'id'          => (int) $r['id_help_desk_contact'],
            'nama'        => (string) $r['name'],
            'kontak'      => (string) $r['contact'],
            'email'       => $r['email'] === null || $r['email'] === '' ? null : (string) $r['email'],
            'status'      => (int) $r['status'],
            'dibuat_pada' => self::iso($r['created_at']),
            'diubah_pada' => self::iso($r['updated_at']),
        ];
    }
}
