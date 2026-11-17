/**
 * Badge menu Halo Simpeg (AC-E08.12, ✔ Horii D8 (a)): angka = `Ringkasan.badge` (distinct belum dibaca ∪ `perlu_tindak`),
 * bukan `total` (angka lonceng). Fungsi murni dipakai computed `groups` di `RedesignShell.vue`; `buildNav` tidak diubah.
 * Sumber angka (`GET tiket/ringkasan`) + gerbang polling menyusul saat README MAKE-071 beku.
 */
import type { NavGroup } from '@/shared/layouts/nav.config'

export const KUNCI_MENU_HALO = 'halo'

/**
 * Salinan `groups` dengan `badge` di item `halo`. `badge` ≤ 0 / bukan angka → badge dihapus; item tidak ada (role/fase
 * tidak melihatnya) → `groups` dikembalikan apa adanya.
 */
export function sisipBadgeHalo(groups: NavGroup[], badge: number | null | undefined): NavGroup[] {
  const ada = groups.some((g) => g.items.some((i) => i.key === KUNCI_MENU_HALO))
  if (!ada) return groups
  const angka = typeof badge === 'number' && Number.isFinite(badge) && badge > 0 ? Math.floor(badge) : undefined
  return groups.map((g) =>
    g.items.some((i) => i.key === KUNCI_MENU_HALO)
      ? {
          ...g,
          items: g.items.map((i) => {
            if (i.key !== KUNCI_MENU_HALO) return i
            const baru = { ...i, badge: angka }
            if (angka === undefined) delete baru.badge
            return baru
          }),
        }
      : g,
  )
}
