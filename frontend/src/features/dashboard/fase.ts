/**
 * Gerbang konten Jalur A di beranda (MAKE-087 M3, pola sama dengan lonceng `FASE_LONCENG` Jalur C dan
 * `InteraksiPopupHost` Jalur B): selama `ACTIVE_PHASE < FASE_KONTEN` banner, popup aktif, dan berita terbaru TIDAK dipasang,
 * jadi tidak ada request `konten/*` dari beranda. Komponen milik Jalur A sendiri tidak diubah; gerbangnya di titik pemasangan.
 */
import { ACTIVE_PHASE } from '@/shared/layouts/nav.config'

export const FASE_KONTEN = 7

export const KONTEN_BERANDA_AKTIF = ACTIVE_PHASE >= FASE_KONTEN
