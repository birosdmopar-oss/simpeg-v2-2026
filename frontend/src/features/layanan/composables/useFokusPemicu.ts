import { watch, type Ref } from 'vue'

/**
 * Mengembalikan fokus ke tombol ⋮ baris setelah dialog yang dibuka dari menu itu ditutup (CR-072 butir 8). Radix
 * mengembalikan fokus ke elemen yang aktif saat dialog dibuka, padahal item menu sudah lepas dari DOM, sehingga fokus
 * jatuh ke body. `pemicu` mengembalikan selector tombol ⋮ (testid `<fitur>-actions-<id>`) atau `null` bila tidak ada.
 * Watcher `post` agar fokus dipasang setelah Radix selesai melepas dialog.
 */
export function useFokusPemicu(terbuka: Ref<boolean>, pemicu: () => string | null): void {
  watch(
    terbuka,
    (buka, sebelumnya) => {
      if (!sebelumnya || buka) return
      const selector = pemicu()
      if (!selector) return
      setTimeout(() => document.querySelector<HTMLElement>(selector)?.focus(), 0)
    },
    { flush: 'post' },
  )
}
