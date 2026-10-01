/**
 * Layanan riwayat pegawai (B-07…B-18).
 *
 * SEMENTARA MEMAKAI DATA CONTOH di memori: tanda tangan fungsi adalah kontrak yang akan diisi pemanggilan `api`
 * dari `@/lib/axios` (ADR-022). Perubahan hanya hidup selama modul dimuat (hilang saat halaman di-refresh) dan
 * tidak pernah menyentuh backend. Setiap tambah/ubah membuat status verifikasi "Menunggu Verifikasi" (alur B-04).
 */
import { RIWAYAT_BY_KEY } from './riwayat.config'
import { type RiwayatRow, seedRows } from './riwayat.mock'

const store = new Map<string, RiwayatRow[]>()
let nextId = 1000

const slot = (nip: string, key: string): string => `${nip}::${key}`

function rowsOf(nip: string, key: string): RiwayatRow[] {
  const id = slot(nip, key)
  if (!store.has(id)) store.set(id, seedRows(key))
  return store.get(id) ?? []
}

function assertKey(key: string): void {
  if (!RIWAYAT_BY_KEY[key]) throw new Error(`Tab riwayat tidak dikenal: ${key}`)
}

export type RiwayatValues = Record<string, string>

export const riwayatService = {
  async list(nip: string, key: string): Promise<RiwayatRow[]> {
    assertKey(key)
    return rowsOf(nip, key).map((r) => ({ ...r }))
  },

  async create(nip: string, key: string, values: RiwayatValues): Promise<RiwayatRow> {
    assertKey(key)
    const row = { ...values, id: ++nextId, status_verifikasi: 'Menunggu Verifikasi' } as RiwayatRow
    rowsOf(nip, key).unshift(row)
    return { ...row }
  },

  async update(nip: string, key: string, id: number, values: RiwayatValues): Promise<RiwayatRow> {
    assertKey(key)
    const rows = rowsOf(nip, key)
    const index = rows.findIndex((r) => r.id === id)
    if (index === -1) throw new Error('Riwayat tidak ditemukan')
    rows[index] = { ...values, id, status_verifikasi: 'Menunggu Verifikasi' } as RiwayatRow
    return { ...rows[index] }
  },

  async remove(nip: string, key: string, id: number): Promise<void> {
    assertKey(key)
    const rows = rowsOf(nip, key)
    const index = rows.findIndex((r) => r.id === id)
    if (index !== -1) rows.splice(index, 1)
  },

  /** Tab "record" (Data Alamat): satu rekaman; belum ada → objek kosong. */
  async getRecord(nip: string, key: string): Promise<RiwayatValues> {
    assertKey(key)
    const first = rowsOf(nip, key)[0]
    if (!first) return {}
    const values: RiwayatValues = {}
    for (const [k, v] of Object.entries(first)) if (k !== 'id' && k !== 'status_verifikasi') values[k] = String(v)
    return values
  },

  async saveRecord(nip: string, key: string, values: RiwayatValues): Promise<void> {
    assertKey(key)
    store.set(slot(nip, key), [{ ...values, id: 1, status_verifikasi: 'Menunggu Verifikasi' } as RiwayatRow])
  },

  /** Hanya untuk test: kosongkan perubahan. */
  reset(): void {
    store.clear()
    nextId = 1000
  },
}
