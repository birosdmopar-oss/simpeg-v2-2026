/**
 * Kalkulator lama cuti real-time (PRD §6.3.7, §6.11.3): memanggil `POST cuti/hitung-lama` setiap isian berubah
 * (debounce ±300 ms) dan hanya MENAMPILKAN hasilnya. FE tidak menghitung hari kerja/kuota/tanggal akhir. Respons
 * yang basi (isian sudah berubah lagi) dibuang; galat 422 kalkulator menjadi pesan per kolom, bukan penghalang Simpan.
 */
import { describeApiError, splitValidationErrors } from '@/features/kepegawaian/services/apiErrors'
import { isApiError } from '@/lib/axios'
import { onBeforeUnmount, ref, watch, type Ref } from 'vue'

import { cutiService } from '../services/cuti.service'
import type { HitungLamaCuti, HitungLamaPayload } from '../types'

export interface IsianHitung {
  id_jenis_cuti: string
  tgl_mulai: string
  tgl_akhir: string
  durasi: string
}

export const JEDA_HITUNG_MS = 300

export function useHitungLama(isian: IsianHitung, opsi: { aktif: Ref<boolean>; pakaiDurasi: Ref<boolean>; nip: Ref<string>; idRiwayatCuti: Ref<number | null> }) {
  const hasil = ref<HitungLamaCuti | null>(null)
  const memuat = ref(false)
  const galat = ref<Record<string, string>>({})
  const galatUmum = ref('')
  let timer: ReturnType<typeof setTimeout> | undefined
  let token = 0

  function bersihkan(): void {
    hasil.value = null
    galat.value = {}
    galatUmum.value = ''
  }

  function payload(): HitungLamaPayload | null {
    const jenis = Number(isian.id_jenis_cuti)
    if (!jenis || !isian.tgl_mulai) return null
    const p: HitungLamaPayload = { id_jenis_cuti: jenis, tgl_mulai: isian.tgl_mulai }
    if (opsi.pakaiDurasi.value) {
      if (!isian.durasi) return null
      p.durasi = Number(isian.durasi)
    } else {
      if (!isian.tgl_akhir) return null
      p.tgl_akhir = isian.tgl_akhir
    }
    if (opsi.nip.value) p.nip = opsi.nip.value
    if (opsi.idRiwayatCuti.value !== null) p.id_riwayat_cuti = opsi.idRiwayatCuti.value
    return p
  }

  async function hitung(): Promise<void> {
    const saya = ++token
    const body = payload()
    if (!opsi.aktif.value || body === null) {
      bersihkan()
      memuat.value = false
      return
    }
    memuat.value = true
    try {
      const h = await cutiService.hitungLama(body)
      if (saya !== token) return
      hasil.value = h
      galat.value = {}
      galatUmum.value = ''
    } catch (e) {
      if (saya !== token) return
      hasil.value = null
      if (isApiError(e) && e.status === 422 && e.errors) {
        const v = splitValidationErrors(e, ['id_jenis_cuti', 'tgl_mulai', 'tgl_akhir', 'durasi', 'lama_cuti'])
        galat.value = v.fields
        galatUmum.value = v.general.join(' ')
      } else {
        galat.value = {}
        galatUmum.value = describeApiError(e).message
      }
    } finally {
      if (saya === token) memuat.value = false
    }
  }

  function jadwalkan(): void {
    clearTimeout(timer)
    timer = setTimeout(() => void hitung(), JEDA_HITUNG_MS)
  }

  watch(() => [isian.id_jenis_cuti, isian.tgl_mulai, isian.tgl_akhir, isian.durasi, opsi.aktif.value, opsi.pakaiDurasi.value, opsi.nip.value, opsi.idRiwayatCuti.value], jadwalkan)
  onBeforeUnmount(() => {
    clearTimeout(timer)
    token++
  })

  return { hasil, memuat, galat, galatUmum, hitungSekarang: hitung }
}
