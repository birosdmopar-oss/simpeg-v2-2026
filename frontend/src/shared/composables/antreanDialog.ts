/**
 * Antrean dialog setelah login (Fase 7 MAKE-083, kesepakatan Jalur A/B/D + keputusan Horii 10-11): satu dialog otomatis
 * tampil pada satu waktu, mengikuti urutan legacy `views/template/modal_test.php:182-209` — form digital → pengingat
 * persetujuan cuti → popup konten → survei kepuasan layanan (SKL).
 *
 * Kontrak:
 * - CR-118: `daftar(nama)` dipanggil sinkron di setup (status "memuat"), lalu `minta()` bila ada isi atau `batal()` bila
 *   kosong/403/galat. Peserta siap hanya diberi giliran bila tidak ada peserta berprioritas lebih tinggi yang masih memuat,
 *   jadi urutan tidak bergantung balapan network. Batas aman {@link BATAS_MEMUAT_MS}.
 * - `minta(nama)` (pintas) mendaftarkan dialog dan selesai (resolve) saat dialog itu boleh tampil; hasilnya fungsi `selesai()` yang
 *   WAJIB dipanggil ketika dialog ditutup/tuntas (idempoten).
 * - Dialog yang sedang tampil tidak pernah disela. Begitu `selesai()` dipanggil, permintaan menunggu dengan prioritas
 *   tertinggi yang mendapat giliran berikutnya (bukan urutan kedatangan).
 * - Komponen yang tidak punya isi memanggil `batal()` (atau, tanpa `daftar`, cukup tidak memanggil `minta`).
 * - Bila komponen pemanggil dilepas (unmount) sebelum `selesai()`, giliran/permintaannya dibebaskan otomatis, jadi antrean
 *   tidak macet.
 *
 * State dibagi seluruh aplikasi (satu shell). `resetAntreanDialog()` hanya untuk test.
 */
import { getCurrentScope, onScopeDispose, readonly, ref, type Ref } from 'vue'

export type NamaDialog = 'form-digital' | 'pengingat-cuti' | 'popup-konten' | 'skl'

/** Urutan prioritas (indeks kecil = lebih dulu) [K `modal_test.php:182-209`]. */
export const PRIORITAS_DIALOG: readonly NamaDialog[] = ['form-digital', 'pengingat-cuti', 'popup-konten', 'skl']

interface Peserta {
  id: number
  nama: NamaDialog
  /** null = masih memuat data; fungsi = siap tampil (memberi giliran). */
  beri: (() => void) | null
  batas: ReturnType<typeof setTimeout> | null
}

/**
 * Batas aman peserta berstatus "memuat" (ms). Lewat batas → dianggap batal supaya antrean tidak macet bila fetch tidak
 * pernah selesai (galat/403/timeout biasa sudah ditangani pemanggil lewat `batal()`).
 */
export const BATAS_MEMUAT_MS = 20_000

const aktif = ref<NamaDialog | null>(null)
let idAktif: number | null = null
let peserta: Peserta[] = []
let urutan = 0

function prioritas(nama: NamaDialog): number {
  return PRIORITAS_DIALOG.indexOf(nama)
}

/**
 * Beri giliran ke peserta SIAP berprioritas tertinggi, hanya bila tidak ada peserta berprioritas lebih tinggi yang masih
 * memuat (deterministik, tidak bergantung urutan selesainya fetch).
 */
function jalankan(): void {
  if (idAktif !== null) return
  const urut = [...peserta].sort((a, b) => prioritas(a.nama) - prioritas(b.nama) || a.id - b.id)
  const berikut = urut.find((p) => p.beri !== null)
  if (berikut === undefined) return
  if (urut.some((p) => p.beri === null && prioritas(p.nama) < prioritas(berikut.nama))) return
  hapus(berikut.id)
  idAktif = berikut.id
  aktif.value = berikut.nama
  berikut.beri!()
}

function hapus(id: number): void {
  const p = peserta.find((x) => x.id === id)
  if (p?.batas) clearTimeout(p.batas)
  peserta = peserta.filter((x) => x.id !== id)
}

function lepas(id: number): void {
  hapus(id)
  if (idAktif === id) {
    idAktif = null
    aktif.value = null
  }
  queueMicrotask(jalankan)
}

/** Pendaftaran satu dialog: daftar sinkron sebelum fetch, lalu `minta()` (ada isi) atau `batal()` (kosong/galat). */
export interface PesertaDialog {
  /** Data siap: selesai (resolve) saat dialog boleh tampil; hasilnya `selesai()` yang WAJIB dipanggil saat dialog tuntas. */
  minta: () => Promise<() => void>
  /** Tidak ada isi / galat: bebaskan pendaftaran (idempoten). */
  batal: () => void
}

export interface AntreanDialog {
  /** Nama dialog yang sedang tampil, atau null. */
  aktif: Readonly<Ref<NamaDialog | null>>
  /**
   * Daftarkan dialog berstatus "memuat" — panggil SINKRON di setup, sebelum fetch, supaya dialog berprioritas lebih rendah
   * menunggu sampai data dialog ini selesai dimuat.
   */
  daftar: (nama: NamaDialog) => PesertaDialog
  /** Pintas `daftar(nama).minta()` (data sudah siap saat mendaftar). */
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

  function daftar(nama: NamaDialog): PesertaDialog {
    const id = ++urutan
    milik.add(id)
    const p: Peserta = { id, nama, beri: null, batas: null }
    p.batas = setTimeout(() => {
      if (p.beri === null) batal()
    }, BATAS_MEMUAT_MS)
    peserta.push(p)

    function batal(): void {
      if (idAktif === id) return
      milik.delete(id)
      if (peserta.some((x) => x.id === id)) lepas(id)
    }

    function minta(): Promise<() => void> {
      return new Promise((resolve) => {
        const selesai = (): void => {
          milik.delete(id)
          lepas(id)
        }
        if (!peserta.some((x) => x.id === id)) {
          // Lewat batas memuat / sudah dilepas: daftar ulang sebagai peserta siap.
          if (!milik.has(id)) milik.add(id)
          peserta.push({ id, nama, beri: null, batas: null })
        }
        const x = peserta.find((y) => y.id === id)!
        if (x.batas) clearTimeout(x.batas)
        x.batas = null
        x.beri = () => resolve(selesai)
        // Ditunda satu microtask: peserta yang siap bersamaan dibandingkan prioritasnya lebih dulu.
        queueMicrotask(jalankan)
      })
    }

    return { minta, batal }
  }

  return { aktif: readonly(aktif), daftar, minta: (nama) => daftar(nama).minta() }
}

/** Kosongkan antrean (hanya untuk test). */
export function resetAntreanDialog(): void {
  for (const p of peserta) if (p.batas) clearTimeout(p.batas)
  peserta = []
  idAktif = null
  aktif.value = null
  urutan = 0
}
