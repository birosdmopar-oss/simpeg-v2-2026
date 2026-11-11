/**
 * Gerbang konten Jalur A di beranda (MAKE-087 M3, pola sama dengan lonceng `FASE_LONCENG` Jalur C dan
 * `InteraksiPopupHost` Jalur B): selama `ACTIVE_PHASE < FASE_KONTEN` banner, popup aktif, dan berita terbaru TIDAK dipasang,
 * jadi tidak ada request `konten/*` dari beranda. Komponen milik Jalur A sendiri tidak diubah; gerbangnya di titik pemasangan.
 * Nilainya satu sumber dengan `FASE_INTERAKSI` host interaksi shell Jalur B (form digital/SKL), jadi semua dialog/konten
 * beranda Fase 7 terbuka bersamaan.
 */
import { FASE_INTERAKSI } from '@/features/form-digital/roles'
import { ACTIVE_PHASE } from '@/shared/layouts/nav.config'

export const FASE_KONTEN = FASE_INTERAKSI

export const KONTEN_BERANDA_AKTIF = ACTIVE_PHASE >= FASE_KONTEN
