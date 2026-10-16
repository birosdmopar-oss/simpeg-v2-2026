/**
 * Bayangan pemisah kolom Aksi sticky kanan (CR-038 F-UI-2; dipakai bersama sejak CR-053). Kolom Aksi menempel di kanan
 * area tabel (`sticky right-0`) supaya tombol ⋮ selalu terlihat di layar sempit; bayangan (`shadow-sticky-end`) hanya
 * tampil selama masih ada kolom yang tersembunyi di bawahnya (tabel bisa digeser dan belum di ujung kanan).
 *
 * Pakai: `const tableScroller = ref<HTMLElement | null>(null)` (ref template milik view) lalu
 * `const { actionsShadow, updateActionsShadow } = useStickyActionsShadow(tableScroller, [items, loading])`, dengan
 * `<div ref="tableScroller" class="overflow-x-auto" @scroll.passive="updateActionsShadow">` dan
 * `:class="{ 'shadow-sticky-end': actionsShadow }"` di header & sel Aksi. Sumber `pemicu` dihitung ulang sesudah render
 * (isi tabel berubah); ukuran wadah/tabel dipantau ResizeObserver dan `resize` jendela.
 */
import { nextTick, onBeforeUnmount, onMounted, ref, watch, type Ref, type WatchSource } from 'vue'

export function useStickyActionsShadow(
  tableScroller: Ref<HTMLElement | null>,
  pemicu?: WatchSource | WatchSource[],
): {
  actionsShadow: Ref<boolean>
  updateActionsShadow: () => void
} {
  const actionsShadow = ref(false)
  let tableResizeObserver: ResizeObserver | null = null

  function updateActionsShadow(): void {
    const el = tableScroller.value
    actionsShadow.value = el !== null && el.scrollWidth - el.clientWidth - el.scrollLeft > 1
  }

  watch(tableScroller, (el, previous) => {
    if (tableResizeObserver && previous) tableResizeObserver.disconnect()
    if (el && typeof ResizeObserver !== 'undefined') {
      tableResizeObserver ??= new ResizeObserver(updateActionsShadow)
      tableResizeObserver.observe(el)
      if (el.firstElementChild) tableResizeObserver.observe(el.firstElementChild)
    }
    updateActionsShadow()
  })

  if (pemicu !== undefined) watch(pemicu, () => void nextTick(updateActionsShadow))

  onMounted(() => window.addEventListener('resize', updateActionsShadow))
  onBeforeUnmount(() => {
    window.removeEventListener('resize', updateActionsShadow)
    tableResizeObserver?.disconnect()
  })

  return { actionsShadow, updateActionsShadow }
}
