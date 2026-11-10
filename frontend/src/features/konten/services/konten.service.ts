/**
 * Konten admin Fase 7 — klien tampil untuk beranda (PRD Fase 7 v2.0 §5.3-5.5, §7). Semua pemanggilan lewat `api`
 * terpusat. Penargetan/role ditegakkan backend: daftar kosong berarti "tidak ada yang tampil".
 */
import { api } from '@/lib/axios'

import type {
  BannerAktif,
  BannerAktifApi,
  BeritaDetail,
  BeritaDetailApi,
  BeritaKategoriOpsi,
  BeritaListApi,
  DiboBacaRow,
  DiboBacaRowApi,
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

/** Portal berita: daftar (cari, filter kategori, paginasi), detail, kategori (PRD §5.5). */
export const beritaPortalService = {
  async list(params: { page: number; per_page: number; q?: string; kategori?: string }): Promise<{ items: BeritaRingkas[]; total: number }> {
    const { data } = await api.get<BeritaListApi>('/konten/berita', { params })
    return { items: (data?.items ?? []).map(mapBeritaRingkas), total: data?.total ?? 0 }
  },
  async detail(id: string): Promise<BeritaDetail> {
    const { data } = await api.get<BeritaDetailApi>(`/konten/berita/${id}`)
    return {
      id: toId(data.id),
      judul: data.judul,
      kategori: data.kategori ?? '',
      penulis: data.penulis ?? '',
      tanggal: data.tanggal ?? null,
      foto_url: data.foto_url ?? null,
      isi: data.isi ?? '',
    }
  },
  async kategori(): Promise<BeritaKategoriOpsi[]> {
    const { data } = await api.get<Array<{ id: number | string; nama: string }>>('/konten/berita/kategori')
    return (data ?? []).map((k) => ({ id: toId(k.id), nama: k.nama }))
  },
  /** Mereset penanda berita baru (`news_flag`) saat portal dibuka (Q7-09). */
  async dibuka(): Promise<void> {
    await api.post('/konten/berita/dibuka')
  },
}

const dibacaNama = (header: unknown, cadangan: string): string => {
  const h = typeof header === 'string' ? header : ''
  const nama = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(h)?.[1]
  try {
    return nama ? decodeURIComponent(nama) : cadangan
  } catch {
    return nama ?? cadangan
  }
}

/** DIBO baca (semua role): daftar bersaring, jenis, dan unduh berkas terautentikasi (PRD §5.6, Q7-12/13/14). */
export const diboBacaService = {
  async list(params: { page: number; per_page: number; q?: string; jenis?: string; tahun?: string }): Promise<{ items: DiboBacaRow[]; total: number }> {
    const { data } = await api.get<{ items?: DiboBacaRowApi[]; total?: number }>('/konten/dibo', { params })
    return {
      items: (data?.items ?? []).map((r) => ({
        id: toId(r.id),
        nomor: r.nomor,
        tahun: String(r.tahun ?? ''),
        tentang: r.tentang,
        keterangan: r.keterangan ?? '',
        jenis: r.jenis ?? '',
        berkas: r.berkas ?? [],
      })),
      total: data?.total ?? 0,
    }
  },
  async jenis(): Promise<Array<{ id: string; nama: string }>> {
    const { data } = await api.get<Array<{ id: KontenApiId; nama: string }>>('/konten/dibo-jenis')
    return (data ?? []).map((j) => ({ id: toId(j.id), nama: j.nama }))
  },
  /** Mengunduh berkas ke-`no` lewat tautan sementara; nama dari Content-Disposition. */
  async unduh(id: string, no: number, cadangan: string): Promise<void> {
    const res = await api.get<Blob>(`/konten/dibo/${id}/berkas/${no}`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = dibacaNama(res.headers?.['content-disposition'], cadangan)
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  },
}
