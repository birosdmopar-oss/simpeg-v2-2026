/**
 * Aksi baris cuti yang dipakai halaman daftar dan detail: proses (Setujui/Tolak/Tangguhkan, termasuk atas nama), Tunda,
 * Hapus, dan Unduh surat. Memegang keadaan dialog/busy/notice; `onSelesai` dipanggil setelah aksi sukses (muat ulang).
 * Hak aksi TIDAK diputuskan di sini — pemanggil hanya meneruskan kunci menu yang sudah disaring `aksi {}` server.
 */
import { describeApiError, splitValidationErrors } from '@/features/kepegawaian/services/apiErrors'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import { type ApiError, isApiError } from '@/lib/axios'
import { ref } from 'vue'

import { cutiService } from '../services/cuti.service'
import type { AksiProsesCuti, CutiItem } from '../types'

export interface NoticeCuti {
  tone: 'danger' | 'success'
  text: string
}

/**
 * F6-18 K1 (keputusan Horii 03-11-2026): dua langkah di halaman tugas yang sama — sesudah Setujui/Tangguhkan tersimpan,
 * bila server mengizinkan `aksi.esign` (penanda tangan pemilik tugas), dialog tanda tangan langsung dibuka. Tolak tidak
 * pernah meminta passphrase (K3a). Tanda tangan gagal → persetujuan tetap tersimpan; ulangi lewat item ⋮ "Tanda tangani".
 * Sebelum dialog dibuka, sertifikat penanda tangan dicek (`esignStatus`, ikut legacy `Cuti.php:687-705`): tidak aktif →
 * pesan tanpa meminta passphrase (surat tanpa TTE); layanan tidak terhubung → pesan galat, bisa dicoba lagi.
 *
 * Q6-40 (MAKE-075): sesudah Setujui/Tangguhkan ATAS NAMA yang final, bila server mengizinkan `aksi.esign_atas_nama`, dialog
 * yang sama dibuka dalam mode atas nama (passphrase pejabat, TTE atas identitas pejabat). Menutup dialog = surat tanpa TTE;
 * ulangi lewat item ⋮ "Tanda tangani atas nama".
 */
const PESAN_PROSES: Record<AksiProsesCuti, string> = {
  setujui: 'Pengajuan cuti disetujui.',
  tolak: 'Pengajuan cuti ditolak.',
  tangguhkan: 'Pengajuan cuti ditangguhkan.',
}

export function useCutiAksi(onSelesai: (aksi: 'proses' | 'tunda' | 'hapus' | 'esign', id: number) => void | Promise<void>) {
  const notice = ref<NoticeCuti | null>(null)
  const busyId = ref<number | null>(null)

  const prosesOpen = ref(false)
  const prosesAksi = ref<AksiProsesCuti>('setujui')
  const prosesAtasNama = ref(false)
  const prosesRow = ref<CutiItem | null>(null)
  const prosesBusy = ref(false)
  const prosesError = ref('')

  const esignOpen = ref(false)
  const esignRow = ref<CutiItem | null>(null)
  /** Dialog TTE dibuka dalam mode atas nama (Q6-40). */
  const esignAtasNama = ref(false)
  const esignBusy = ref(false)
  const esignErrors = ref<Record<string, string>>({})
  const esignUmum = ref('')

  const hapusOpen = ref(false)
  const hapusRow = ref<CutiItem | null>(null)

  /** Kunci menu → dialog/aksi. `detail` ditangani pemanggil (navigasi). */
  function pilih(key: string, row: CutiItem): void {
    if (key === 'setujui' || key === 'tolak' || key === 'tangguhkan') {
      bukaProses(row, key, false)
    } else if (key === 'setujui-atas-nama' || key === 'tolak-atas-nama' || key === 'tangguhkan-atas-nama') {
      bukaProses(row, key.replace('-atas-nama', '') as AksiProsesCuti, true)
    } else if (key === 'esign') {
      void bukaEsign(row)
    } else if (key === 'esign-atas-nama') {
      void bukaEsign(row, true)
    } else if (key === 'tunda') {
      void tunda(row)
    } else if (key === 'surat') {
      void unduhSurat(row)
    } else if (key === 'hapus') {
      hapusRow.value = row
      hapusOpen.value = true
    }
  }

  async function bukaEsign(row: CutiItem, atasNama = false): Promise<void> {
    busyId.value = row.id
    try {
      const cek = await cutiService.esignStatus(row.id, atasNama)
      if (cek.status !== 'aktif') {
        notice.value = { tone: 'danger', text: cek.pesan || 'Sertifikat elektronik tidak dapat diperiksa.' }
        return
      }
      esignRow.value = row
      esignAtasNama.value = atasNama
      esignErrors.value = {}
      esignUmum.value = ''
      esignOpen.value = true
    } catch (e) {
      notice.value = { tone: 'danger', text: describeApiError(e).message }
    } finally {
      busyId.value = null
    }
  }

  function bukaProses(row: CutiItem, aksi: AksiProsesCuti, atasNama: boolean): void {
    prosesRow.value = row
    prosesAksi.value = aksi
    prosesAtasNama.value = atasNama
    prosesError.value = ''
    prosesOpen.value = true
  }

  async function proses(payload: { aksi: AksiProsesCuti; reason_note?: string; atas_nama?: boolean }): Promise<void> {
    const row = prosesRow.value
    if (!row) return
    prosesBusy.value = true
    prosesError.value = ''
    try {
      const hasil = await cutiService.process(row.id, payload)
      prosesOpen.value = false
      const atasNama = payload.atas_nama === true && hasil.aksi?.esign_atas_nama === true
      const lanjutTtd = payload.aksi !== 'tolak' && (hasil.aksi?.esign === true || atasNama)
      notice.value = { tone: 'success', text: lanjutTtd ? `${PESAN_PROSES[payload.aksi]} Lanjutkan tanda tangan elektronik.` : PESAN_PROSES[payload.aksi] }
      await onSelesai('proses', row.id)
      if (lanjutTtd) await bukaEsign(hasil, atasNama && hasil.aksi?.esign !== true)
    } catch (e) {
      prosesError.value = describeApiError(e).message
    } finally {
      prosesBusy.value = false
    }
  }

  /** Passphrase hanya diteruskan ke request; tidak disimpan di state composable ini. */
  async function esign(passphrase: string): Promise<void> {
    const row = esignRow.value
    if (!row) return
    esignBusy.value = true
    esignErrors.value = {}
    esignUmum.value = ''
    try {
      await cutiService.esign(row.id, passphrase, esignAtasNama.value)
      esignOpen.value = false
      notice.value = { tone: 'success', text: 'Surat cuti ditandatangani secara elektronik.' }
      await onSelesai('esign', row.id)
    } catch (e) {
      const v = splitValidationErrors(e, ['passphrase', 'nip', 'status'])
      esignErrors.value = v.fields
      // 429 batas percobaan (F6-18 K2): pesan server memuat sisa waktu tunggu.
      const terkunci = isApiError(e) && e.status === 429 && e.message !== ''
      esignUmum.value = terkunci
        ? (e as ApiError).message
        : v.general.length
          ? v.general.join(' ')
          : Object.keys(v.fields).length
            ? ''
            : describeApiError(e).message
    } finally {
      esignBusy.value = false
    }
  }

  async function tunda(row: CutiItem): Promise<void> {
    busyId.value = row.id
    try {
      await cutiService.tunda(row.id)
      notice.value = { tone: 'success', text: 'Pengingat ditunda.' }
      await onSelesai('tunda', row.id)
    } catch (e) {
      notice.value = { tone: 'danger', text: describeApiError(e).message }
    } finally {
      busyId.value = null
    }
  }

  async function hapus(): Promise<void> {
    const row = hapusRow.value
    hapusOpen.value = false
    if (!row) return
    busyId.value = row.id
    try {
      await cutiService.remove(row.id)
      notice.value = { tone: 'success', text: 'Pengajuan cuti dihapus.' }
      await onSelesai('hapus', row.id)
    } catch (e) {
      notice.value = { tone: 'danger', text: describeApiError(e).message }
    } finally {
      busyId.value = null
      hapusRow.value = null
    }
  }

  async function unduhSurat(row: CutiItem): Promise<void> {
    busyId.value = row.id
    try {
      simpanBerkas(await cutiService.surat(row.id))
    } catch (e) {
      notice.value = { tone: 'danger', text: describeApiError(e).message }
    } finally {
      busyId.value = null
    }
  }

  return {
    notice,
    busyId,
    prosesOpen,
    prosesAksi,
    prosesAtasNama,
    prosesRow,
    prosesBusy,
    prosesError,
    esignOpen,
    esignRow,
    esignAtasNama,
    esignBusy,
    esignErrors,
    esignUmum,
    hapusOpen,
    hapusRow,
    pilih,
    proses,
    esign,
    hapus,
    tunda,
    unduhSurat,
  }
}
