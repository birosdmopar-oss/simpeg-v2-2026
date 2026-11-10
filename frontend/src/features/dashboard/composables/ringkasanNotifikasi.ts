/**
 * Adapter SEMENTARA kontrak lonceng Jalur C (PRD Fase 7 v2.0 §7 C → D) untuk kartu "Butuh Approval" beranda.
 *
 * Bentuk & konstanta sama persis dengan ekspor `@/features/notifikasi` milik Jalur C (branch `fase7/make-085-pusat-notifikasi`,
 * belum di `main`): `useRingkasanNotifikasi()` → `{ ringkasan (null saat belum/gagal dimuat), … }`, `BUTUH_APPROVAL_ROLES` =
 * [1, 2, 3], route `{ name: 'notifikasi', query: { tab: 'butuh-approval' } }`. Sampai ekspor itu masuk `main`, `ringkasan`
 * selalu `null` (kartu menampilkan "—"). Begitu tersedia, berkas ini DIHAPUS dan impor diganti ke `@/features/notifikasi`.
 */
import { readonly, ref, type Ref } from 'vue'

import { Role, type RoleCode } from '@/features/auth/types'

export interface RingkasanNotifikasi {
  notifikasi_belum_dibaca: number
  butuh_approval: number
  berita_belum_dibaca: number | null
  tiket_belum_dibaca: number | null
  diperbarui_at: string
}

/** Role yang angka "butuh approval"-nya berlaku (Q7-29 ikut legacy; koreksi Jalur C: tanpa role 7). */
export const BUTUH_APPROVAL_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.PEGAWAI, Role.ADMIN_SATKER]

/** Halaman Butuh Approval milik Jalur C (nama route; route belum terdaftar sampai F-08 di `main`). */
export const BUTUH_APPROVAL_ROUTE = { name: 'notifikasi', query: { tab: 'butuh-approval' } } as const

const ringkasan = ref<RingkasanNotifikasi | null>(null)

export function useRingkasanNotifikasi(): { ringkasan: Readonly<Ref<RingkasanNotifikasi | null>> } {
  return { ringkasan: readonly(ringkasan) }
}
