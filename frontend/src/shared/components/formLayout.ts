/**
 * Tata letak form admin redesign (CR-028 / MIG-001b, ISSUE-007): label di atas field (FormField → UiTextField/UiSelect)
 * dan dua field per baris pada layar lebar (>= md), satu kolom di layar sempit. Dipakai bersama oleh dialog form
 * Master Data, Hari Libur, dan Manajemen Akun supaya semua form admin seragam.
 *
 * - FORM_GRID_CLASS dipasang pada <form>; setiap FormField menjadi satu sel grid.
 * - FORM_WIDE_CLASS untuk elemen yang memakai dua kolom penuh (textarea/html, banner error, baris tombol).
 */
export const FORM_GRID_CLASS = 'grid grid-cols-1 items-start gap-x-5 gap-y-4 md:grid-cols-2'

export const FORM_WIDE_CLASS = 'md:col-span-2'

/** Banner error di atas form (pesan 4xx/5xx backend), dua kolom penuh. */
export const FORM_ALERT_CLASS = `${FORM_WIDE_CLASS} rounded-xl border border-danger/30 bg-danger-soft px-3 py-2 text-body2 text-[#a52b2c]`

/** Baris tombol Batal/Simpan di kanan bawah, dua kolom penuh. */
export const FORM_ACTIONS_CLASS = `${FORM_WIDE_CLASS} flex justify-end gap-2 pt-2`
