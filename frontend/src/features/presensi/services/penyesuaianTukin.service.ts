/**
 * Klien Penyesuaian Tukin per NIP (5B MAKE-045, PRD 5B §6.1, §7.3.10; PRD 5C §7.4; role 1 saja) lewat `api`
 * (`@/lib/axios`, ADR-022):
 *   GET    presensi/tukin/penyesuaian?nip=&bulan=&status=&page=&per_page=   daftar (status 1/2; bawaan 1 dan 2)
 *   POST   presensi/tukin/penyesuaian                                       tambah (201)
 *   PUT    presensi/tukin/penyesuaian/{id}                                  ubah rentang, persen, alasan, status 1/2
 *   DELETE presensi/tukin/penyesuaian/{id}                                  hapus = status 10 (pola master)
 * Interceptor `@/lib/axios` sudah membuka amplop {status, data}: `response.data` = payload (CR-059). Angka & boolean
 * (MySQLi → string) dinormalkan di sini; `persen` tetap string desimal server (mis. "-20.00"). Validasi & tumpang tindih
 * ditentukan server (422 per field, 409 `konflik_data`).
 */
import { api } from '@/lib/axios'

export type StatusPenyesuaian = 1 | 2 | 10

/** Satu baris penyesuaian (README Controllers/Api/Presensi, MAKE-045). */
export interface PenyesuaianTukin {
  id: number
  nip: string
  nama: string
  /** `YYYY-MM`. */
  bulan_mulai: string
  /** `YYYY-MM`. */
  bulan_akhir: string
  /** Persen desimal string server, negatif = pengurangan (mis. "-20.00"). */
  persen: string
  alasan: string
  status: StatusPenyesuaian
  created_at: string | null
  updated_at: string | null
}

export interface DaftarPenyesuaian {
  items: PenyesuaianTukin[]
  total: number
  page: number
  per_page: number
}

export interface QueryPenyesuaian {
  nip?: string
  bulan?: string
  status?: StatusPenyesuaian
  page: number
  per_page: number
}

/** Isian tambah/ubah. `status` hanya dikirim saat ubah (bawaan server 1 saat tambah). */
export interface IsianPenyesuaian {
  nip: string
  bulan_mulai: string
  bulan_akhir: string
  persen: string
  alasan: string
  status?: 1 | 2
}

const angka = (v: unknown): number => (v === null || v === undefined || v === '' ? 0 : Number(v))
const teks = (v: unknown): string | null => (v === null || v === undefined || v === '' ? null : String(v))
const status = (v: unknown): StatusPenyesuaian => (Number(v) === 2 ? 2 : Number(v) === 10 ? 10 : 1)

function baris(b: PenyesuaianTukin): PenyesuaianTukin {
  return {
    id: angka(b.id),
    nip: String(b.nip),
    nama: String(b.nama ?? ''),
    bulan_mulai: String(b.bulan_mulai),
    bulan_akhir: String(b.bulan_akhir),
    persen: String(b.persen),
    alasan: String(b.alasan ?? ''),
    status: status(b.status),
    created_at: teks(b.created_at),
    updated_at: teks(b.updated_at),
  }
}

/** Persen server (titik desimal, negatif = pengurangan) → "-20,00%" (koma desimal Indonesia, AC-FE5C angka). */
export function formatPersen(persen: string): string {
  const n = Number(persen)
  if (!Number.isFinite(n)) return persen
  return `${n.toFixed(2).replace('.', ',')}%`
}

export const penyesuaianTukinService = {
  async daftar(q: QueryPenyesuaian): Promise<DaftarPenyesuaian> {
    const params: Record<string, string | number> = { page: q.page, per_page: q.per_page }
    if (q.nip) params.nip = q.nip
    if (q.bulan) params.bulan = q.bulan
    if (q.status) params.status = q.status
    const { data } = await api.get<DaftarPenyesuaian>('/presensi/tukin/penyesuaian', { params })
    return {
      items: (data.items ?? []).map(baris),
      total: angka(data.total),
      page: angka(data.page) || 1,
      per_page: angka(data.per_page) || q.per_page,
    }
  },

  async tambah(isian: IsianPenyesuaian): Promise<PenyesuaianTukin> {
    const { data } = await api.post<PenyesuaianTukin>('/presensi/tukin/penyesuaian', isian)
    return baris(data)
  },

  async ubah(id: number, isian: IsianPenyesuaian): Promise<PenyesuaianTukin> {
    const { data } = await api.put<PenyesuaianTukin>(`/presensi/tukin/penyesuaian/${id}`, isian)
    return baris(data)
  },

  /**
   * Aktifkan/Nonaktifkan lewat ⋮ (AGENTS §1): PUT yang sama dengan Edit. Server (`PenyesuaianTukinService::isian`)
   * mewajibkan body lengkap, jadi nilai baris dikirim ulang dan hanya `status` yang berubah.
   */
  async ubahStatus(b: PenyesuaianTukin, status: 1 | 2): Promise<PenyesuaianTukin> {
    return this.ubah(b.id, {
      nip: b.nip,
      bulan_mulai: b.bulan_mulai,
      bulan_akhir: b.bulan_akhir,
      persen: b.persen,
      alasan: b.alasan,
      status,
    })
  },

  async hapus(id: number): Promise<void> {
    await api.delete(`/presensi/tukin/penyesuaian/${id}`)
  },
}
