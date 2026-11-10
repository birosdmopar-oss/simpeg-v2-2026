/**
 * Konten admin Fase 7 — klien tampil untuk beranda (PRD Fase 7 v2.0 §5.3-5.5, §7). Semua pemanggilan lewat `api`
 * terpusat. Penargetan/role ditegakkan backend: daftar kosong berarti "tidak ada yang tampil".
 */
import { api } from '@/lib/axios'

import type {
  BannerAktif,
  BannerAktifApi,
  BeritaListApi,
  BeritaRingkas,
  BeritaRingkasApi,
  PopupAktif,
  PopupAktifApi,
} from '../types'

const toId = (id: number | string): string => String(id)

const toGambar = (row: PopupAktifApi | BannerAktifApi): PopupAktif => ({
  id: toId(row.id),
  gambar_url: row.gambar_url,
  url: row.url ?? null,
  keterangan: row.keterangan ?? null,
})

export function mapBeritaRingkas(row: BeritaRingkasApi): BeritaRingkas {
  return {
    id: toId(row.id),
    judul: row.judul,
    ringkasan: row.ringkasan ?? '',
    kategori: row.kategori ?? '',
    tanggal: row.tanggal ?? null,
    foto_url: row.foto_url ?? null,
  }
}

export const kontenService = {
  /** Popup aktif milik aktor, urut `order` (GET /konten/popup/aktif). */
  async popupAktif(): Promise<PopupAktif[]> {
    const { data } = await api.get<PopupAktifApi[]>('/konten/popup/aktif')
    return (data ?? []).map(toGambar)
  },

  /** Banner aktif milik aktor, urut `order` (GET /konten/banner/aktif). */
  async bannerAktif(): Promise<BannerAktif[]> {
    const { data } = await api.get<BannerAktifApi[]>('/konten/banner/aktif')
    return (data ?? []).map(toGambar)
  },

  /** Berita terbaru yang boleh dilihat aktor (GET /konten/berita?per_page=N&terbaru=1). */
  async beritaTerbaru(limit: number): Promise<BeritaRingkas[]> {
    const { data } = await api.get<BeritaListApi>('/konten/berita', { params: { per_page: limit, terbaru: 1 } })
    return (data?.items ?? []).map(mapBeritaRingkas)
  },
}
