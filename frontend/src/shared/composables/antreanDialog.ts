/**
 * Antrean dialog setelah login (Fase 7 MAKE-083, kesepakatan Jalur A/B/D + keputusan Horii 10-11): satu dialog otomatis
 * tampil pada satu waktu, mengikuti urutan legacy `views/template/modal_test.php:182-209` — form digital → pengingat
 * persetujuan cuti → popup konten → survei kepuasan layanan (SKL).
 *
 * Kontrak:
 * - `minta(nama)` mendaftarkan dialog dan selesai (resolve) saat dialog itu boleh tampil; hasilnya fungsi `selesai()` yang
 *   WAJIB dipanggil ketika dialog ditutup/tuntas (idempoten).
 * - Dialog yang sedang tampil tidak pernah disela. Begitu `selesai()` dipanggil, permintaan menunggu dengan prioritas
 *   tertinggi yang mendapat giliran berikutnya (bukan urutan kedatangan).
 * - Komponen yang tidak punya isi cukup tidak memanggil `minta`.
 * - Bila komponen pemanggil dilepas (unmount) sebelum `selesai()`, giliran/permintaannya dibebaskan otomatis, jadi antrean
 *   tidak macet.
 *
 * State dibagi seluruh aplikasi (satu shell). `resetAntreanDialog()` hanya untuk test.
 */
import { getCurrentScope, onScopeDispose, readonly, ref, type Ref } from 'vue'

export type NamaDialog = 'form-digital' | 'pengingat-cuti' | 'popup-konten' | 'skl'

/** Urutan prioritas (indeks kecil = lebih dulu) [K `modal_test.php:182-209`]. */
export const PRIORITAS_DIALOG: readonly NamaDialog[] = ['form-digital', 'pengingat-cuti', 'popup-konten', 'skl']

interface Permintaan {
  id: number
  nama: NamaDialog
  beri: () => void
}

const aktif = ref<NamaDialog | null>(null)
let idAktif: number | null = null
let menunggu: Permintaan[] = []
let urutan = 0

function prioritas(nama: NamaDialog): number {
  return PRIORITAS_DIALOG.indexOf(nama)
}

function jalankan(): void {
  if (idAktif !== null || menunggu.length === 0) return
  const [berikut] = [...menunggu].sort((a, b) => prioritas(a.nama) - prioritas(b.nama) || a.id - b.id)
  if (berikut === undefined) return
  menunggu = menunggu.filter((p) => p.id !== berikut.id)
  idAktif = berikut.id
  aktif.value = berikut.nama
  berikut.beri()
}

function lepas(id: number): void {
  menunggu = menunggu.filter((p) => p.id !== id)
  if (idAktif === id) {
    idAktif = null
    aktif.value = null
    jalankan()
  }
}

export interface AntreanDialog {
  /** Nama dialog yang sedang tampil, atau null. */
  aktif: Readonly<Ref<NamaDialog | null>>
  minta: (nama: NamaDialog) => Promise<() => void>
}

export function useAntreanDialog(): AntreanDialog {
  const milik = new Set<number>()

  if (getCurrentScope()) {
    onScopeDispose(() => {
      for (const id of milik) lepas(id)
      milik.clear()
    })
  }

  function minta(nama: NamaDialog): Promise<() => void> {
    const id = ++urutan
    milik.add(id)
    return new Promise((resolve) => {
      const selesai = (): void => {
        milik.delete(id)
        lepas(id)
      }
      menunggu.push({ id, nama, beri: () => resolve(selesai) })
      // Ditunda satu microtask: permintaan yang datang bersamaan (mis. beberapa dialog di-mount pada tick yang sama)
      // dibandingkan prioritasnya lebih dulu.
      queueMicrotask(jalankan)
    })
  }

  return { aktif: readonly(aktif), minta }
}

/** Kosongkan antrean (hanya untuk test). */
export function resetAntreanDialog(): void {
  menunggu = []
  idAktif = null
  aktif.value = null
  urutan = 0
}
