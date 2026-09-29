<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\Auth\ForgotAttemptModel;
use App\Models\Auth\PenggunaModel;
use Closure;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * CRUD akun pengguna dengan scoping per satker (A-08, ADR-005: scoping di Service, bukan Filter).
 *
 * - Role 1 (Super Admin): seluruh akun.
 * - Role 3 (Admin Satker): hanya akun dengan id_satker = id_satker miliknya (dari claims JWT).
 *   Akun di luar satker → 403 (tidak muncul di daftar, tidak bisa dibaca/diubah/dihapus).
 *   Di dalam satkernya pun hanya akun Pegawai/PTT/PPPK (Role::UL_PEGAWAI) ditambah akunnya sendiri; akun role
 *   1/3/4/5/8 lain → 403 dan tidak muncul di daftar (CR-017, legacy User::edit/delete menolak target selain level
 *   pegawai; tanpa ini Admin Satker bisa mengganti password / menonaktifkan Super Admin satu satker). Level 7 (PPPK)
 *   ikut boleh dikelola agar konsisten dengan aturan buat akun (legacy edit/delete hanya 2/6, tidak konsisten).
 *   Admin Satker hanya boleh membuat akun / memberi role Pegawai/PTT/PPPK (Role::UL_PEGAWAI, legacy L_user
 *   validate_param: "Pegawai admin hanya bisa menambah user dengan level pegawai atau PTT") dan tidak boleh mengubah
 *   role akunnya sendiri (mencegah eskalasi hak ke role lintas satker 1/4/5/8).
 * - Role lain: 403 di semua operasi (sudah dicegat RoleFilter; dicek ulang di sini sebagai lapis kedua).
 *
 * Akun non-pegawai (DBV-010/CR-013, K2, mengikuti legacy L_user):
 * - Role 2/6/7 (Role::UL_PEGAWAI) wajib NIP; role 1/3/4/5/8 boleh tanpa NIP (legacy memaksa NULL, v2 membolehkan
 *   NIP terisi [V2]).
 * - NIP: angka saja, maksimal 18 digit (NIK 16 digit Non-PNS diterima). Akun tanpa NIP wajib punya nama.
 * - Username default = NIP; wajib diisi bila NIP kosong; maks. PenggunaModel::USERNAME_MAX; unik (collation
 *   unicode_ci: 'strasse' = 'straße').
 * - Ubah akun: NIP hanya boleh DIISI untuk akun yang belum punya NIP (menautkan akun ke pegawai); mengubah/menghapus
 *   NIP yang sudah ada adalah ranah fitur ganti NIP (B-06).
 * - Identitas akun = id_pengguna: pencegahan hapus/nonaktifkan akun sendiri dan pencabutan sesi memakai id_pengguna.
 * - nip/name/email/username/user_level/status/id_unit/id_satker/password harus teks atau angka bulat (array/objek/
 *   boolean → 422, bukan 500; ISSUE-023, lapis pertama UserController). Username baru/diubah tidak boleh memuat karakter
 *   kontrol/tak terlihat (\p{C}, mis. zero-width space yang diabaikan collation unicode_ci); pembatasan ASCII ditunda
 *   sampai audit data produksi (keputusan B). Username lama hasil impor tidak dinilai ulang selama tidak diubah.
 * - Balapan cek-lalu-tulis (dua admin menautkan NIP/username yang sama): pelanggaran UNIQUE (1062) diterjemahkan ulang
 *   ke 422 per field lewat cek ulang.
 * - Username diubah → token reset password yang masih tertunda untuk username lama dibatalkan (token dipetakan ke akun
 *   lewat username; tanpa ini token bisa "berpindah" ke akun lain yang kemudian memakai username tersebut).
 */
class UserService
{
    private const PER_PAGE_MAX = 100;

    public function __construct(
        private PenggunaModel $pengguna,
        private PasswordVerifier $passwords,
        private JwtService $jwt,
        private ?ForgotAttemptModel $forgotAttempts = null,
    ) {
        $this->forgotAttempts ??= new ForgotAttemptModel();
    }

    /**
     * @param array<string, mixed> $filters search, user_level, status, id_satker, sort, order, page, per_page
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(AuthContext $actor, array $filters = []): array
    {
        $this->assertAdmin($actor);

        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(self::PER_PAGE_MAX, max(1, (int) ($filters['per_page'] ?? 20)));

        $builder = $this->pengguna->builder()->where('deleted_at', null);

        if ($actor->role() === Role::ADMIN_SATKER) {
            $builder->where('id_satker', $actor->idSatker())
                ->groupStart()
                ->whereIn('user_level', Role::UL_PEGAWAI)
                ->orWhere('id_pengguna', $actor->idPengguna())
                ->groupEnd();
        } elseif (! empty($filters['id_satker'])) {
            $builder->where('id_satker', (string) $filters['id_satker']);
        }

        if (! empty($filters['search'])) {
            $s = (string) $filters['search'];
            $builder->groupStart()->like('username', $s)->orLike('nip', $s)->orLike('name', $s)->groupEnd();
        }

        if (isset($filters['user_level']) && $filters['user_level'] !== '') {
            $builder->where('user_level', (int) $filters['user_level']);
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $builder->where('status', (string) $filters['status']);
        }

        $total = (clone $builder)->countAllResults();

        $sortable = ['username', 'nip', 'name', 'user_level', 'status', 'created_at', 'last_login_at'];
        $sort     = in_array($filters['sort'] ?? '', $sortable, true) ? (string) $filters['sort'] : 'username';
        $order    = strtolower((string) ($filters['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        /** @var list<array<string, mixed>> $rows */
        $rows = $builder->orderBy($sort, $order)->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'items'    => array_map([PenggunaModel::class, 'toPublic'], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(AuthContext $actor, int $id): array
    {
        $this->assertAdmin($actor);

        return PenggunaModel::toPublic($this->findInScope($actor, $id));
    }

    /**
     * @param array<string, mixed> $data nip?, name?, email?, username?, password, user_level, id_unit?, id_satker?, status?
     *
     * @return array<string, mixed>
     */
    public function create(AuthContext $actor, array $data): array
    {
        $this->assertAdmin($actor);

        $errors   = [];
        $nip      = self::textInput($data, 'nip', $errors);
        $name     = self::textInput($data, 'name', $errors);
        $email    = self::textInput($data, 'email', $errors);
        $username = self::textInput($data, 'username', $errors) ?? $nip ?? '';
        $level    = (int) ($data['user_level'] ?? 0);

        // Field yang di-cast langsung di bawah (ISSUE-023, lapis kedua setelah UserController::validateTextOrFail).
        self::rejectNonTextFields($data, $errors);

        if ($errors !== []) {
            // Isian bukan teks: aturan lain (wajib nama/NIP) tidak bermakna untuk nilai yang dibuang.
            throw new ValidationException('Validasi gagal.', $errors);
        }

        self::validateNip($nip, $errors);
        self::validateName($name, $errors);
        self::validateEmail($email, $errors);

        if (self::validateUsername($username, $errors)) {
            self::validateUsernameChars($username, $errors);
        }

        if (! Role::isValid($level)) {
            $errors['user_level'][] = 'Role tidak valid (1-8).';
        } else {
            self::validateAccountInvariant($level, $nip, $name, $errors);
        }

        $password = (string) ($data['password'] ?? '');

        foreach ($this->passwords->policyErrors($password) as $msg) {
            $errors['password'][] = $msg;
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        // Scoping Admin Satker: akun baru wajib di satkernya sendiri, dan hanya role Pegawai/PTT/PPPK (legacy).
        if ($actor->role() === Role::ADMIN_SATKER) {
            if (! empty($data['id_satker']) && (string) $data['id_satker'] !== $actor->idSatker()) {
                throw new ForbiddenException('Admin Satker hanya dapat membuat akun di satkernya sendiri.');
            }
            $data['id_satker'] = $actor->idSatker();
            $data['id_unit']   = $data['id_unit'] ?? $actor->idUnit();

            if (! in_array($level, Role::UL_PEGAWAI, true)) {
                throw new ForbiddenException('Admin Satker hanya dapat membuat akun Pegawai/PTT/PPPK.');
            }
        }

        if ($nip !== null && $this->pengguna->withDeleted()->where('nip', $nip)->countAllResults() > 0) {
            throw new ValidationException('Validasi gagal.', ['nip' => ['NIP sudah memiliki akun.']]);
        }

        if ($this->pengguna->withDeleted()->where('username', $username)->countAllResults() > 0) {
            throw new ValidationException('Validasi gagal.', ['username' => ['Username sudah dipakai.']]);
        }

        $id = $this->translateDuplicate(fn () => $this->pengguna->insert([
            'nip'                 => $nip,
            'username'            => $username,
            'name'                => $name,
            'email'               => $email,
            'password'            => $this->passwords->hash($password),
            'password_legacy'     => null,
            'user_level'          => $level,
            'id_unit'             => $data['id_unit'] ?? null,
            'id_satker'           => $data['id_satker'] ?? null,
            'status'              => (string) ($data['status'] ?? PenggunaModel::STATUS_ACTIVE) === PenggunaModel::STATUS_INACTIVE ? PenggunaModel::STATUS_INACTIVE : PenggunaModel::STATUS_ACTIVE,
            'password_changed_at' => date('Y-m-d H:i:s'),
        ]), $nip, $username, null);

        return PenggunaModel::toPublic($this->pengguna->find((int) $id));
    }

    /**
     * @param array<string, mixed> $data nip? (hanya untuk akun tanpa NIP), name?, email?, username?, user_level?,
     *                                   id_unit?, id_satker?, status?, password?
     *
     * @return array<string, mixed>
     */
    public function update(AuthContext $actor, int $id, array $data): array
    {
        $this->assertAdmin($actor);
        $row    = $this->findInScope($actor, $id);
        $update = [];
        $errors = [];

        // Field yang di-cast langsung di bawah: tolak lebih dulu, sebelum cast memicu 500 (ISSUE-023).
        self::rejectNonTextFields($data, $errors);

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        if (array_key_exists('nip', $data)) {
            $nip     = self::textInput($data, 'nip', $errors);
            $current = self::nullableString($row['nip'] ?? null);

            if (isset($errors['nip'])) {
                // Bukan teks: pesan sudah dicatat textInput().
            } elseif ($current !== null) {
                if ($nip !== $current) {
                    $errors['nip'][] = 'NIP tidak dapat diubah di sini; gunakan fitur ganti NIP (B-06).';
                }
            } elseif ($nip !== null) {
                // Menautkan akun tanpa NIP ke pegawai.
                if (self::validateNip($nip, $errors)) {
                    if ($this->pengguna->withDeleted()->where('nip', $nip)->countAllResults() > 0) {
                        $errors['nip'][] = 'NIP sudah memiliki akun.';
                    } else {
                        $update['nip'] = $nip;
                    }
                }
            }
        }

        if (array_key_exists('name', $data)) {
            $name = self::textInput($data, 'name', $errors);

            if (! isset($errors['name']) && self::validateName($name, $errors)) {
                $update['name'] = $name;
            }
        }

        if (array_key_exists('email', $data)) {
            $email = self::textInput($data, 'email', $errors);

            if (! isset($errors['email']) && self::validateEmail($email, $errors)) {
                $update['email'] = $email;
            }
        }

        if (array_key_exists('username', $data)) {
            $username = self::textInput($data, 'username', $errors) ?? '';
            $changed  = $username !== (string) $row['username'];

            if (! isset($errors['username']) && self::validateUsername($username, $errors) && (! $changed || self::validateUsernameChars($username, $errors))) {
                if ($this->pengguna->withDeleted()->where('username', $username)->where('id_pengguna !=', $id)->countAllResults() > 0) {
                    $errors['username'][] = 'Username sudah dipakai.';
                } else {
                    $update['username'] = $username;
                }
            }
        }

        if (array_key_exists('user_level', $data)) {
            $level = (int) $data['user_level'];

            if (! Role::isValid($level)) {
                $errors['user_level'][] = 'Role tidak valid (1-8).';
            } elseif ($level === (int) $row['user_level']) {
                // Tidak berubah (form edit selalu mengirim role).
            } elseif ($actor->role() === Role::ADMIN_SATKER && $id === $actor->idPengguna()) {
                throw new ForbiddenException('Admin Satker tidak dapat mengubah role akunnya sendiri.');
            } elseif ($actor->role() === Role::ADMIN_SATKER && ! in_array($level, Role::UL_PEGAWAI, true)) {
                throw new ForbiddenException('Admin Satker hanya dapat memberikan role Pegawai/PTT/PPPK.');
            } else {
                $update['user_level'] = $level;
            }
        }

        if (array_key_exists('status', $data)) {
            $update['status'] = (string) $data['status'] === PenggunaModel::STATUS_INACTIVE ? PenggunaModel::STATUS_INACTIVE : PenggunaModel::STATUS_ACTIVE;

            if ($update['status'] === PenggunaModel::STATUS_INACTIVE && $id === $actor->idPengguna()) {
                $errors['status'][] = 'Tidak dapat menonaktifkan akun sendiri.';
            }
        }

        if (array_key_exists('id_unit', $data)) {
            $update['id_unit'] = $data['id_unit'] === '' ? null : $data['id_unit'];
        }

        if (array_key_exists('id_satker', $data)) {
            if ($actor->role() === Role::ADMIN_SATKER && (string) $data['id_satker'] !== $actor->idSatker()) {
                throw new ForbiddenException('Admin Satker tidak dapat memindahkan akun ke satker lain.');
            }
            $update['id_satker'] = $data['id_satker'] === '' ? null : $data['id_satker'];
        }

        if (! empty($data['password'])) {
            $password = (string) $data['password'];

            foreach ($this->passwords->policyErrors($password) as $msg) {
                $errors['password'][] = $msg;
            }

            if (! isset($errors['password'])) {
                $update['password']            = $this->passwords->hash($password);
                $update['password_legacy']     = null;
                $update['password_changed_at'] = date('Y-m-d H:i:s');
            }
        }

        // Invarian akun dicek pada hasil akhir bila role/NIP/nama ikut berubah (akun hasil impor yang belum memenuhi
        // aturan tetap bisa dinonaktifkan/diubah field lain).
        if ($errors === [] && array_intersect_key($update, ['user_level' => 1, 'nip' => 1, 'name' => 1]) !== []) {
            $final = array_merge($row, $update);
            self::validateAccountInvariant(
                (int) $final['user_level'],
                self::nullableString($final['nip'] ?? null),
                self::nullableString($final['name'] ?? null),
                $errors,
            );
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        if ($update !== []) {
            $this->translateDuplicate(
                fn () => $this->pengguna->update($id, $update),
                isset($update['nip']) ? (string) $update['nip'] : null,
                isset($update['username']) ? (string) $update['username'] : null,
                $id,
            );
        }

        // Token reset dipetakan ke akun lewat username: rename membatalkan token yang masih tertunda untuk username lama.
        if (isset($update['username']) && $update['username'] !== (string) $row['username']) {
            $this->forgotAttempts?->invalidatePendingTokens((string) $row['username'], time());
        }

        // Perubahan role/status/password/satker/NIP langsung berlaku: cabut sesi lama akun tsb (MTC-004). NIP ikut
        // karena claims sesi memuat `nip`.
        if (isset($update['user_level']) || isset($update['status']) || isset($update['password']) || isset($update['nip']) || array_key_exists('id_satker', $update)) {
            $this->jwt->revokeAllForUser($id);
        }

        return PenggunaModel::toPublic($this->pengguna->find($id));
    }

    /**
     * Nonaktifkan (status 0) — akun tidak bisa login lagi, sesi dicabut.
     *
     * @return array<string, mixed>
     */
    public function setStatus(AuthContext $actor, int $id, bool $active): array
    {
        return $this->update($actor, $id, ['status' => $active ? PenggunaModel::STATUS_ACTIVE : PenggunaModel::STATUS_INACTIVE]);
    }

    /**
     * Hapus akun (soft delete) + cabut seluruh sesi.
     */
    public function delete(AuthContext $actor, int $id): void
    {
        $this->assertAdmin($actor);
        $this->findInScope($actor, $id);

        if ($id === $actor->idPengguna()) {
            throw new ValidationException('Tidak dapat menghapus akun sendiri.', ['id' => ['Tidak dapat menghapus akun sendiri.']]);
        }

        $this->pengguna->delete($id);
        $this->jwt->revokeAllForUser($id);
    }

    // ------------------------------------------------------------------

    private function assertAdmin(AuthContext $actor): void
    {
        if (! in_array($actor->role(), [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true)) {
            throw new ForbiddenException();
        }

        if ($actor->role() === Role::ADMIN_SATKER && ($actor->idSatker() === null || $actor->idSatker() === '')) {
            throw new ForbiddenException('Admin Satker tanpa id_satker tidak dapat mengelola akun.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function findInScope(AuthContext $actor, int $id): array
    {
        $row = $this->pengguna->find($id);

        if ($row === null) {
            throw new NotFoundException('Akun tidak ditemukan.');
        }

        if ($actor->role() === Role::ADMIN_SATKER && (string) $row['id_satker'] !== $actor->idSatker()) {
            // Di luar scope satker → 403 (bukan 404) sesuai DoD A-08.
            throw new ForbiddenException('Akun berada di luar satker Anda.');
        }

        if (
            $actor->role() === Role::ADMIN_SATKER
            && $id !== $actor->idPengguna()
            && ! in_array((int) $row['user_level'], Role::UL_PEGAWAI, true)
        ) {
            // CR-017: akun role 1/3/4/5/8 lain di satker yang sama (legacy User::edit/delete).
            throw new ForbiddenException('Admin Satker hanya dapat mengelola akun Pegawai/PTT/PPPK dan akunnya sendiri.');
        }

        return $row;
    }

    /**
     * Pelanggaran UNIQUE (1062) saat dua permintaan balapan lolos cek aplikasi → cek ulang lalu 422 per field
     * (pola FaqService::rate / MasterService::translateDuplicate). Error DB lain tetap dilempar (500).
     */
    private function translateDuplicate(Closure $work, ?string $nip, ?string $username, ?int $exceptId): mixed
    {
        try {
            return $work();
        } catch (DatabaseException $e) {
            if ($e->getCode() !== 1062) {
                throw $e;
            }

            $errors = [];

            if ($nip !== null && $this->countOthers('nip', $nip, $exceptId) > 0) {
                $errors['nip'][] = 'NIP sudah memiliki akun.';
            }

            if ($username !== null && $this->countOthers('username', $username, $exceptId) > 0) {
                $errors['username'][] = 'Username sudah dipakai.';
            }

            if ($errors === []) {
                throw $e;
            }

            throw new ValidationException('Validasi gagal.', $errors);
        }
    }

    /**
     * Cek ulang keunikan langsung lewat query builder baru (termasuk akun soft-deleted), bukan lewat state model.
     */
    private function countOthers(string $column, string $value, ?int $exceptId): int
    {
        $builder = db_connect()->table('pengguna')->where($column, $value);

        if ($exceptId !== null) {
            $builder->where('id_pengguna !=', $exceptId);
        }

        return $builder->countAllResults();
    }

    /**
     * Isian teks opsional dari payload: array/objek/boolean → error field (422), bukan "Array to string conversion"
     * (500). Angka (JSON number) diterima sebagai teks.
     *
     * @param array<string, mixed>        $data
     * @param array<string, list<string>> $errors
     */
    private static function textInput(array $data, string $field, array &$errors): ?string
    {
        $value = $data[$field] ?? null;

        if ($value !== null && ! is_string($value) && ! is_int($value)) {
            $errors[$field][] = 'Isian harus berupa teks.';

            return null;
        }

        return self::nullableString($value);
    }

    /**
     * Pola textInput() untuk field yang nilainya dipakai/di-cast langsung (tanpa trim): `user_level`, `status`,
     * `id_unit`, `id_satker`, `password`. Array/objek/boolean → error field (422) alih-alih "Array to string
     * conversion" (500) atau cast diam-diam — `(int) ['5']` = 1 akan menjadi role Super Admin (ISSUE-023).
     *
     * @param array<string, mixed>        $data
     * @param array<string, list<string>> $errors
     */
    private static function rejectNonTextFields(array $data, array &$errors): void
    {
        foreach (['user_level', 'status', 'id_unit', 'id_satker', 'password'] as $field) {
            self::textInput($data, $field, $errors);
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * NIP ikut legacy: angka saja, maksimal 18 digit (kolom VARCHAR(30)).
     *
     * @param array<string, list<string>> $errors
     */
    private static function validateNip(?string $nip, array &$errors): bool
    {
        if ($nip !== null && preg_match('/^\d{1,' . PenggunaModel::NIP_MAX_DIGITS . '}$/', $nip) !== 1) {
            $errors['nip'][] = 'NIP harus berupa angka, maksimal ' . PenggunaModel::NIP_MAX_DIGITS . ' digit.';

            return false;
        }

        return true;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private static function validateName(?string $name, array &$errors): bool
    {
        if ($name !== null && mb_strlen($name) > PenggunaModel::NAME_MAX) {
            $errors['name'][] = 'Nama maksimal ' . PenggunaModel::NAME_MAX . ' karakter.';

            return false;
        }

        return true;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private static function validateEmail(?string $email, array &$errors): bool
    {
        if ($email !== null && (mb_strlen($email) > PenggunaModel::EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['email'][] = 'Email tidak valid (maks. ' . PenggunaModel::EMAIL_MAX . ' karakter).';

            return false;
        }

        return true;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private static function validateUsername(string $username, array &$errors): bool
    {
        if ($username === '' || mb_strlen($username) > PenggunaModel::USERNAME_MAX) {
            $errors['username'][] = 'Username wajib diisi (maks. ' . PenggunaModel::USERNAME_MAX . ' karakter).';

            return false;
        }

        return true;
    }

    /**
     * Username baru/diubah: tanpa karakter kontrol/format/tak terlihat (\p{C}: kontrol, zero-width, soft hyphen,
     * private use, belum ditetapkan). unicode_ci mengabaikan sebagian karakter ini saat membandingkan, sehingga
     * 'ad<U+200B>min' dianggap sama dengan 'admin' dan tampil sama di daftar akun/audit.
     *
     * @param array<string, list<string>> $errors
     */
    private static function validateUsernameChars(string $username, array &$errors): bool
    {
        if (preg_match('/\p{C}/u', $username) === 1) {
            $errors['username'][] = 'Username tidak boleh berisi karakter kontrol atau karakter tak terlihat.';

            return false;
        }

        return true;
    }

    /**
     * Role 2/6/7 wajib NIP; akun tanpa NIP wajib punya nama (legacy L_user: name wajib untuk role 1/3/4/5/8).
     *
     * @param array<string, list<string>> $errors
     */
    private static function validateAccountInvariant(int $level, ?string $nip, ?string $name, array &$errors): void
    {
        if ($nip === null && Role::wajibNip($level)) {
            $errors['nip'][] = 'NIP wajib diisi untuk role Pegawai/PTT/PPPK.';
        }

        if ($nip === null && $name === null) {
            $errors['name'][] = 'Nama wajib diisi untuk akun tanpa NIP.';
        }
    }
}
