/** F-06 Form Digital (MAKE-083) — kontrak API `form-digital*` (backend `Libraries/Dashboard/Interaksi/FormDigital`). */

export type TipePertanyaan = 'teks' | 'paragraf' | 'pilihan_tunggal' | 'pilihan_ganda' | 'tanggal' | 'angka' | 'berkas'

export const TIPE_PERTANYAAN: { value: TipePertanyaan; label: string }[] = [
  { value: 'teks', label: 'Teks singkat' },
  { value: 'paragraf', label: 'Paragraf' },
  { value: 'pilihan_tunggal', label: 'Pilihan tunggal' },
  { value: 'pilihan_ganda', label: 'Pilihan ganda' },
  { value: 'tanggal', label: 'Tanggal' },
  { value: 'angka', label: 'Angka' },
  { value: 'berkas', label: 'Unggah berkas' },
]

export const TIPE_BERKAS = ['pdf', 'jpg', 'png', 'doc', 'docx', 'xls', 'xlsx'] as const

export interface Pertanyaan {
  id: string
  tipe: TipePertanyaan
  label: string
  wajib: boolean
  bantuan?: string
  maks?: number
  opsi?: string[]
  min?: number
  maks_angka?: number
  berkas?: { tipe: string[]; maks_mb: number }
}

export interface SkemaForm {
  versi: number
  pertanyaan: Pertanyaan[]
}

/** `GET form-digital/saya`. */
export interface FormSaya {
  id: number
  judul_form: string
  keterangan: string | null
  tgl_mulai: string
  tgl_akhir: string
  form: SkemaForm
}

export type NilaiJawaban = string | string[] | number | null

export interface TargetForm {
  unit_satker: string[]
  pegawai: string[]
  kecuali: string[]
  /** NIP → "NIP - nama" (menyusul helper LabelTarget Jalur A). */
  label_pegawai?: Record<string, string>
}

export interface FormRingkas {
  id: number
  judul_form: string
  tgl_mulai: string
  tgl_akhir: string
  order: number
  keterangan: string | null
  status: 1 | 2 | 10
  jumlah_target: number
  sudah_mengisi: number
  belum_mengisi: number
  jumlah_jawaban: number
  terkunci: boolean
}

export interface FormDetail extends FormRingkas {
  form: SkemaForm
  target: TargetForm
}

export interface FormInput {
  judul_form: string
  tgl_mulai: string
  tgl_akhir: string
  order: number
  keterangan: string | null
  form: Pertanyaan[]
  target: TargetForm
}

export interface Halaman<T> {
  items: T[]
  total: number
  page: number
  per_page: number
}

export interface KolomRespons {
  id: string
  label: string
  tipe: TipePertanyaan
}

export interface BarisRespons {
  id: number
  nip: string
  nama: string
  unit: string
  satker: string
  jawaban: Record<string, string>
  berkas: string[]
  diisi_at: string
}

export interface RekapRespons extends Halaman<BarisRespons> {
  kolom: KolomRespons[]
}

export interface BarisTarget {
  nip: string
  nama: string
  sudah_mengisi: boolean
  diisi_at: string | null
}
