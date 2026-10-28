/**
 * Geolokasi perangkat untuk Presensi Saya (PRD Fase 5A §7.8.3): `navigator.geolocation.getCurrentPosition`
 * (`enableHighAccuracy: true`, `timeout: 10000`, `maximumAge: 0`) diulang tiap 10 detik selama komponen aktif.
 * Hanya membaca posisi; penilaian dalam/luar radius tetap dari server (`POST presensi/cek-lokasi`, ADR-025).
 * Butuh konteks aman (HTTPS) di produksi (◻ Q5A-22).
 */
import { onBeforeUnmount, ref } from 'vue'

export type StatusGeolokasi = 'memuat' | 'diizinkan' | 'ditolak' | 'tidak-tersedia' | 'galat'

export interface PosisiPerangkat {
  latitude: number
  longitude: number
  akurasi: number | null
  /** `Date.now()` saat posisi dibaca (untuk "diperbarui … detik lalu"). */
  waktu: number
}

export const INTERVAL_GEOLOKASI_MS = 10_000

export function useGeolokasi(intervalMs = INTERVAL_GEOLOKASI_MS) {
  const status = ref<StatusGeolokasi>('memuat')
  const posisi = ref<PosisiPerangkat | null>(null)
  const pesan = ref('')
  let timer: ReturnType<typeof setInterval> | undefined
  let aktif = false

  function baca(): void {
    const geo = typeof navigator !== 'undefined' ? navigator.geolocation : undefined
    if (!geo) {
      status.value = 'tidak-tersedia'
      pesan.value = 'Perangkat atau browser ini tidak mendukung pembacaan lokasi.'
      return
    }
    geo.getCurrentPosition(
      (p) => {
        if (!aktif) return
        status.value = 'diizinkan'
        pesan.value = ''
        posisi.value = {
          latitude: p.coords.latitude,
          longitude: p.coords.longitude,
          akurasi: Number.isFinite(p.coords.accuracy) ? p.coords.accuracy : null,
          waktu: Date.now(),
        }
      },
      (e) => {
        if (!aktif) return
        if (e.code === 1) {
          status.value = 'ditolak'
          pesan.value = 'Akses lokasi ditolak. Izinkan akses lokasi untuk situs ini di pengaturan browser, lalu muat ulang halaman.'
        } else if (e.code === 2) {
          status.value = 'tidak-tersedia'
          pesan.value = 'Lokasi perangkat tidak tersedia. Aktifkan GPS/layanan lokasi.'
        } else {
          status.value = posisi.value ? 'diizinkan' : 'galat'
          pesan.value = 'Pembacaan lokasi melebihi batas waktu. Mencoba lagi…'
        }
      },
      { enableHighAccuracy: true, timeout: 10_000, maximumAge: 0 },
    )
  }

  function mulai(): void {
    if (aktif) return
    aktif = true
    baca()
    timer = setInterval(() => {
      // Izin ditolak tidak akan berubah tanpa interaksi pengguna; tidak perlu meminta berulang.
      if (status.value !== 'ditolak') baca()
    }, intervalMs)
  }

  function berhenti(): void {
    aktif = false
    clearInterval(timer)
    timer = undefined
  }

  onBeforeUnmount(berhenti)

  return { status, posisi, pesan, mulai, berhenti, bacaSekarang: baca }
}
