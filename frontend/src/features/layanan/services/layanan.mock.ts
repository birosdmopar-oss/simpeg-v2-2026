/**
 * DATA CONTOH (MOCK) Status Layanan — endpoint hr/rwy/layanan belum ada. Nama diambil dari data contoh pegawai
 * (semuanya fiktif). Deterministik. Hanya dipakai layanan.service.ts.
 */
import { PEGAWAI_ROWS } from '@/features/kepegawaian/services/pegawai.mock'

import { ALUR_LAYANAN, JENIS_LAYANAN, type LangkahState, type LayananRow } from '../types'

function buildRow(i: number): LayananRow {
  const p = PEGAWAI_ROWS[(i * 5 + 2) % PEGAWAI_ROWS.length]
  const jenis = JENIS_LAYANAN[(i * 2 + (i % 3)) % JENIS_LAYANAN.length]
  const alur = ALUR_LAYANAN[jenis]
  // Posisi langkah aktif: 0..alur.length (alur.length = semua selesai); bervariasi tetapi deterministik.
  const progress = (i * 7 + 1) % (alur.length + 1)
  const langkah = alur.map((label, k) => ({
    label,
    state: (k < progress ? 'done' : k === progress ? 'current' : 'todo') as LangkahState,
  }))
  return {
    id: i + 1,
    nip: p.nip,
    nama: [p.gelar_awal, p.nama].filter(Boolean).join(' '),
    foto_seed: p.foto_seed ?? `layanan-${i}`,
    jenis,
    satuan_kerja: p.satuan_kerja,
    langkah,
    status: progress >= alur.length ? 'Selesai' : 'Berjalan',
  }
}

export const LAYANAN_ROWS: LayananRow[] = Array.from({ length: 50 }, (_, i) => buildRow(i))
