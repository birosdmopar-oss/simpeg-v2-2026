<script setup lang="ts">
/**
 * Form Tambah/Edit petugas Halo Simpeg (B3, E-02; role 1). Edit memakai baris daftar (S9).
 * - Akun: kolom ketik + saran kandidat (`UiTextField suggestions` = UiCombobox) dari `GET petugas/kandidat?search=` ber-jeda
 *   300 ms, hanya bila 2..50 karakter (README); `peringatan` kandidat (satker kepegawaian kosong, S1) → `UiNotice`. Review B3 R8:
 *   hasil server tidak disaring ulang (`saring-lokal=false`, kandidat yang cocok lewat username/NIP tetap tampil); pilihan
 *   dipetakan lewat indeks → `id_user` (label kembar aman); mengetik ulang mengosongkan pilihan ("Akun petugas wajib dipilih.").
 * - Kategori: `UiPilihBanyak` (opsi `kategori/opsi` aktif + kategori lama yang Tidak Aktif berbadge, tetap terpilih).
 * - Email (wajib), No HP (opsional), Status (hanya saat Edit). Validasi klien = `petugasFormSchema` (teks BE).
 * - 422 per kolom (`id_user`, `category`, `email`, `no_hp`) dan `petugas_duplikat` tampil di kolom; PUT yang mengembalikan
 *   `peringatan` (S8) diteruskan ke pemanggil (`saved`).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import { type OpsiPilihBanyak, UiButton, UiNotice, UiPilihBanyak, UiRadioGroup, UiTextField } from '@/shared/ui'
import { galatPerKolom } from '@/shared/utils/galatKolom'

import { cariKandidatSchema, petugasFormSchema } from '../../schemas'
import { kategoriHaloService, petugasHaloService } from '../../services/master.service'
import type { KategoriOpsi, Petugas, PetugasKandidat, StatusMaster } from '../../types'

const props = defineProps<{ open: boolean; row: Petugas | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [peringatan: string | null] }>()

const STATUS = [
  { value: '1', label: 'Aktif' },
  { value: '0', label: 'Tidak Aktif' },
]

const isEdit = computed(() => props.row !== null)
const form = reactive({ akun: '', id_user: null as number | null, kategori: [] as number[], email: '', no_hp: '', status: '1' })
const kandidat = ref<PetugasKandidat[]>([])
/** S6: saran kandidat milik teks lama (disembunyikan sampai hasil kueri teks terbaru tiba). */
const saranBasi = ref(false)
const peringatanKandidat = ref('')
const galatKandidat = ref('')
const opsiKategori = ref<KategoriOpsi[]>([])
const submitting = ref(false)
const submitted = ref(false)
const formError = ref('')
const serverErrors = ref<Record<string, string>>({})
let jeda: ReturnType<typeof setTimeout> | null = null
let urutanCari = 0

watch(
  () => [props.open, props.row] as const,
  async ([open, row]) => {
    // R8 (c): timer & urutan pencarian kandidat diatur ulang setiap dialog dibuka/ditutup.
    if (jeda) clearTimeout(jeda)
    jeda = null
    urutanCari += 1
    if (!open) return
    submitted.value = false
    formError.value = ''
    serverErrors.value = {}
    peringatanKandidat.value = ''
    galatKandidat.value = ''
    saranBasi.value = false
    kandidat.value = row ? [{ id_user: row.id_user, label: row.label }] : []
    form.akun = row?.label ?? ''
    form.id_user = row?.id_user ?? null
    form.kategori = row?.kategori.map((k) => k.id) ?? []
    form.email = row?.email ?? ''
    form.no_hp = row?.no_hp ?? ''
    form.status = row?.status === 0 ? '0' : '1'
    try {
      opsiKategori.value = await kategoriHaloService.opsi()
    } catch (e) {
      formError.value = isApiError(e) ? e.message : 'Pilihan kategori tidak dapat dimuat.'
    }
  },
  { immediate: true },
)

/** Kategori aktif + kategori lama baris ini yang Tidak Aktif (berbadge, tetap terpilih sampai dilepas). */
const opsiPilih = computed<OpsiPilihBanyak[]>(() => {
  const opsi: OpsiPilihBanyak[] = opsiKategori.value.map((k) => ({ id: k.id, label: k.nama }))
  for (const k of props.row?.kategori ?? []) {
    if (!opsi.some((o) => o.id === k.id)) opsi.push({ id: k.id, label: k.nama, badge: k.status === 1 ? undefined : { teks: 'Tidak Aktif', status: 0 } })
  }
  return opsi
})

const saran = computed(() => (saranBasi.value ? [] : kandidat.value.map((k) => k.label)))

async function cari(teks: string): Promise<void> {
  const n = ++urutanCari
  galatKandidat.value = ''
  if (!cariKandidatSchema.safeParse(teks).success) return
  try {
    const hasil = await petugasHaloService.kandidat(teks)
    if (n !== urutanCari) return
    kandidat.value = hasil.items
    saranBasi.value = false
    peringatanKandidat.value = hasil.peringatan ?? ''
  } catch (e) {
    if (n !== urutanCari) return
    galatKandidat.value = isApiError(e) ? e.message : 'Kandidat petugas tidak dapat dimuat.'
  }
}

function ubahAkun(v: string): void {
  form.akun = v
  // Teks diubah → pilihan lama gugur; akun baru dipilih lewat `pilihAkun` (indeks saran), bukan pencocokan label.
  if (form.id_user !== null && v === (kandidat.value.find((k) => k.id_user === form.id_user)?.label ?? null)) return
  form.id_user = null
  delete serverErrors.value.id_user
  if (jeda) clearTimeout(jeda)
  // Review S6: tanpa saring lokal, saran teks lama jangan tampil apa adanya — disembunyikan (basi) saat teks di luar 2..50
  // karakter dan selama jeda kueri baru; urutan dinaikkan agar respons kueri lama yang tiba belakangan diabaikan. Daftar
  // `kandidat` tidak dikosongkan karena UiCombobox memancarkan `update:modelValue` tepat sebelum `pilih` (indeks).
  saranBasi.value = true
  peringatanKandidat.value = ''
  urutanCari += 1
  jeda = setTimeout(() => void cari(v), 300)
}

function pilihAkun(indeks: number): void {
  const k = kandidat.value[indeks]
  if (!k) return
  if (jeda) clearTimeout(jeda)
  jeda = null
  urutanCari += 1
  form.akun = k.label
  form.id_user = k.id_user
  saranBasi.value = false
  delete serverErrors.value.id_user
}

onBeforeUnmount(() => {
  if (jeda) clearTimeout(jeda)
})

const isian = computed(() => ({
  id_user: form.id_user,
  kategori: form.kategori,
  email: form.email,
  no_hp: form.no_hp,
  status: Number(form.status) as StatusMaster,
}))

const galatKlien = computed<Record<string, string>>(() => {
  const r = petugasFormSchema.safeParse(isian.value)
  if (r.success) return {}
  const e: Record<string, string> = {}
  for (const i of r.error.issues) e[String(i.path[0] ?? '')] ??= i.message
  return e
})

const PETA: Record<string, string> = { id_user: 'id_user', category: 'kategori', email: 'email', no_hp: 'no_hp', status: 'status' }
const err = (f: string): string | undefined => serverErrors.value[f] ?? (submitted.value ? galatKlien.value[f] : undefined)

async function onSubmit(): Promise<void> {
  submitted.value = true
  formError.value = ''
  serverErrors.value = {}
  if (Object.keys(galatKlien.value).length > 0 || submitting.value) return
  submitting.value = true
  try {
    let peringatan: string | null = null
    if (props.row === null) await petugasHaloService.tambah(isian.value)
    else peringatan = (await petugasHaloService.ubah(props.row.id, isian.value)).peringatan
    emit('saved', peringatan)
    emit('update:open', false)
  } catch (e) {
    serverErrors.value = galatPerKolom(e, (k) => PETA[k.split('.')[0] ?? k] ?? k)
    const pesan = isApiError(e) ? e.message : 'Terjadi kesalahan. Silakan coba lagi.'
    formError.value = Object.values(serverErrors.value).includes(pesan) ? '' : pesan
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="halo-petugas-dialog"
      >
        <div class="mb-4 flex items-start justify-between">
          <div>
            <DialogTitle class="text-h5 font-semibold text-slate-900">{{ isEdit ? 'Edit' : 'Tambah' }} Petugas</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">Petugas menjawab tiket pada kategori yang ditugaskan.</DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <form :class="FORM_GRID_CLASS" novalidate data-testid="halo-petugas-form" @submit.prevent="onSubmit">
          <p v-if="formError" :class="FORM_ALERT_CLASS" role="alert" data-testid="halo-petugas-form-galat">{{ formError }}</p>
          <div :class="FORM_WIDE_CLASS">
            <UiTextField
              :model-value="form.akun"
              name="akun"
              label="Akun Petugas"
              type="text"
              required
              placeholder="Ketik minimal 2 huruf nama, NIP, atau username"
              :suggestions="saran"
              :saring-lokal="false"
              :state="err('id_user') ? 'error' : 'default'"
              :help-text="err('id_user') ?? galatKandidat"
              @update:model-value="ubahAkun"
              @pilih="pilihAkun"
            />
            <UiNotice v-if="peringatanKandidat" tone="warning" class="mt-2" data-testid="halo-kandidat-peringatan">{{ peringatanKandidat }}</UiNotice>
          </div>
          <div :class="FORM_WIDE_CLASS">
            <UiPilihBanyak
              v-model="form.kategori"
              :opsi="opsiPilih"
              legend="Kategori"
              label-cari="Cari kategori"
              teks-kosong="Tidak ada kategori yang cocok."
              testid="halo-petugas-kategori"
              required
              :disabled="submitting"
              :error="err('kategori')"
            />
          </div>
          <FormField v-model="form.email" name="email" label="Email" required :error="err('email')" />
          <FormField v-model="form.no_hp" name="no_hp" label="No HP" :error="err('no_hp')" />
          <div v-if="isEdit" :class="FORM_WIDE_CLASS">
            <UiRadioGroup v-model="form.status" name="status" label="Status" :options="STATUS" />
          </div>
          <div :class="FORM_ACTIONS_CLASS">
            <DialogClose as-child>
              <UiButton variant="secondary" appearance="soft">Batal</UiButton>
            </DialogClose>
            <UiButton type="submit" :disabled="submitting" data-testid="halo-petugas-simpan">{{ submitting ? 'Menyimpan...' : 'Simpan' }}</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
