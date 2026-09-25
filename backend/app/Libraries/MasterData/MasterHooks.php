<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

/**
 * Hook tulis per master (opsi definisi `hooks` di Config\MasterData). Dipanggil MasterService pada create & update
 * SETELAH validasi (bentuk + aturan bisnis) dan SEBELUM baris ditulis, di dalam transaksi yang sama — sehingga
 * exception dari hook (mis. ValidationException → 422) ikut membatalkan seluruh penulisan.
 *
 * Dipakai untuk kolom turunan yang dihitung server dan tidak boleh berasal dari input (mis. sanitasi HTML
 * `faq_article.content` + `content_stripped`, lihat FaqArticleHooks).
 */
interface MasterHooks
{
    /**
     * Kolom turunan yang ditulis hook (masuk allowedFields Model, tidak pernah diterima dari input).
     *
     * @return list<string>
     */
    public function derivedColumns(): array;

    /**
     * @param array<string, mixed>      $row      create: baris lengkap yang akan di-insert; update: kolom yang berubah
     * @param array<string, mixed>|null $existing baris saat ini (null saat create)
     *
     * @return array<string, mixed> baris/perubahan final yang ditulis
     */
    public function beforeWrite(array $row, ?array $existing): array;
}
