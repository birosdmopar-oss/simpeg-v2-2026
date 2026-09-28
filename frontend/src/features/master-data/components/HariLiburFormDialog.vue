<script setup lang="ts">
/**
 * Form tambah/ubah Hari Libur (G-08, DBV-003/CR-010) — Radix Vue Dialog + VeeValidate/Zod. Jenis libur dari dropdown
 * master `jenis-libur` (hanya yang aktif; jenis tersimpan yang kini non-aktif tetap tampil bertanda). Error 422 backend
 * (overlap dengan hari libur lain berstatus apa pun, jenis non-aktif, rentang) dipetakan ke field; 409 (penulisan lain
 * sedang berjalan) tampil sebagai pesan di atas form.
 */
import { toTypedSchema } from '@vee-validate/zod'
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { type TypedSchema, useForm } from 'vee-validate'
import { computed, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'

import type { HariLiburFormValues, HariLiburPayload, HariLiburRow } from '../hariLibur.types'
import { hariLiburSchema, jumlahHari } from '../schemas/hariLibur.schema'
import { hariLiburService } from '../services/hariLibur.service'
import type { MasterOption, MasterSettableStatus } from '../types'

const props = defineProps<{ open: boolean; row: HariLiburRow | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [row: HariLiburRow] }>()

const isEdit = computed(() => props.row !== null)
const submitting = ref(false)
const formError = ref('')
const jenisOptions = ref<MasterOption[]>([])
const jenisLoading = ref(false)

/**
 * Tombol Simpan dikunci selama `isSubmitting` vee-validate (validasi skema + permintaan simpan), bukan hanya selama
 * `submitting` (permintaan simpan saja, dipakai untuk label). Validasi skema di-debounce 5 ms sebelum handler jalan;
 * klik kedua sebelum validasi itu selesai memulai submit kedua yang ikut lolos (create/update terkirim dua kali).
 */
const { values, handleSubmit, errors, resetForm, setFieldError, setFieldValue, submitCount, isSubmitting } = useForm<HariLiburFormValues>({
  validationSchema: toTypedSchema(hariLiburSchema) as unknown as TypedSchema<HariLiburFormValues>,
})

const interacted = ref(new Set<string>())

function fieldError(field: keyof HariLiburFormValues): string | undefined {
  return submitCount.value > 0 || interacted.value.has(field) ? errors.value[field] : undefined
}

function updateField(field: keyof HariLiburFormValues, value: string): void {
  interacted.value.add(field)
  setFieldValue(field, value)
}

async function loadJenis(row: HariLiburRow | null): Promise<void> {
  jenisLoading.value = true
  let options: MasterOption[] = []
  try {
    options = await hariLiburService.jenisOptions()
  } catch (err) {
    formError.value = `Gagal memuat pilihan jenis libur. ${isApiError(err) ? err.message : ''}`.trim()
  }
  const current = row?.id_jenis_libur != null ? String(row.id_jenis_libur) : ''
  if (current !== '' && !options.some((o) => o.id === current)) {
    options = [...options, { id: current, nama: `${row?.jenis_libur ?? current} — tidak aktif`, parent: null }]
  }
  jenisOptions.value = options
  jenisLoading.value = false
}

watch(
  () => [props.open, props.row] as const,
  ([open, row]) => {
    if (!open) return
    formError.value = ''
    resetForm({
      values: {
        tgl_mulai: row?.tgl_mulai ?? '',
        tgl_akhir: row?.tgl_akhir ?? '',
        id_jenis_libur: row?.id_jenis_libur != null ? String(row.id_jenis_libur) : '',
        nama_libur: row?.nama_libur ?? '',
        keterangan: row?.keterangan ?? '',
        status: row ? '' : '1',
      },
      errors: {},
    })
    interacted.value = new Set()
    void loadJenis(row)
  },
  { immediate: true },
)

/**
 * Tanggal selesai mengikuti tanggal mulai (libur satu hari) selama pengguna belum menyentuhnya dan nilainya masih kosong
 * atau sama dengan tanggal mulai sebelumnya. Input tanggal Chromium memancarkan `input` per digit tahun yang diketik
 * (0002-08-17 → 0020-… → 0202-… → 2026-08-17), jadi mengisi "hanya bila kosong" akan membekukan tahun setengah jadi.
 * Rentang yang sudah ada (selesai ≠ mulai) tidak ditimpa.
 */
function onMulaiChange(value: string): void {
  const mulaiSebelumnya = String(values.tgl_mulai ?? '')
  const akhir = String(values.tgl_akhir ?? '')
  updateField('tgl_mulai', value)
  if (!interacted.value.has('tgl_akhir') && (akhir === '' || akhir === mulaiSebelumnya)) setFieldValue('tgl_akhir', value, false)
}

const durasi = computed(() => jumlahHari(String(values.tgl_mulai ?? ''), String(values.tgl_akhir ?? '')))

const onSubmit = handleSubmit(async (form) => {
  submitting.value = true
  formError.value = ''
  const payload: HariLiburPayload = {
    tgl_mulai: form.tgl_mulai.trim(),
    tgl_akhir: form.tgl_akhir.trim(),
    id_jenis_libur: form.id_jenis_libur,
    nama_libur: form.nama_libur.replace(/\s+/g, ' ').trim(),
  }
  const keterangan = String(form.keterangan ?? '').trim()
  // Saat ubah, keterangan yang dikosongkan tetap dikirim agar terhapus.
  if (keterangan !== '' || isEdit.value) payload.keterangan = keterangan

  try {
    let saved: HariLiburRow
    if (props.row) {
      saved = await hariLiburService.update(String(props.row.id_libur), payload)
    } else {
      payload.status = (form.status === '2' ? '2' : '1') as MasterSettableStatus
      saved = await hariLiburService.create(payload)
    }
    emit('saved', saved)
    emit('update:open', false)
  } catch (err) {
    if (isApiError(err) && err.errors) {
      for (const [field, messages] of Object.entries(err.errors)) setFieldError(field as keyof HariLiburFormValues, messages[0])
    }
    formError.value = isApiError(err) ? err.message : 'Terjadi kesalahan. Silakan coba lagi.'
  } finally {
    submitting.value = false
  }
})
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-lg bg-white p-6 shadow-xl focus:outline-none"
      >
        <div class="mb-4 flex items-start justify-between">
          <div>
            <DialogTitle class="text-lg font-semibold text-slate-900">{{ isEdit ? 'Ubah Hari Libur' : 'Tambah Hari Libur' }}</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">
              Rentang tanggal tidak boleh bentrok dengan hari libur lain, termasuk yang tidak aktif atau dihapus.
            </DialogDescription>
          </div>
          <DialogClose class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <form class="space-y-4" novalidate data-testid="hari-libur-form" @submit="onSubmit">
          <p v-if="formError" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</p>

          <div class="grid gap-4 sm:grid-cols-2">
            <FormField
              :model-value="values.tgl_mulai"
              name="tgl_mulai"
              label="Tanggal Mulai"
              type="date"
              required
              :error="fieldError('tgl_mulai')"
              @update:model-value="onMulaiChange"
            />
            <FormField
              :model-value="values.tgl_akhir"
              name="tgl_akhir"
              label="Tanggal Selesai"
              type="date"
              required
              :hint="durasi > 0 ? `${durasi} hari` : ''"
              :error="fieldError('tgl_akhir')"
              @update:model-value="updateField('tgl_akhir', $event)"
            />
          </div>

          <FormField
            :model-value="values.id_jenis_libur"
            name="id_jenis_libur"
            label="Jenis Libur"
            type="select"
            required
            :placeholder="jenisLoading ? 'Memuat...' : 'Pilih Jenis Libur'"
            :disabled="jenisLoading"
            :options="jenisOptions.map((o) => ({ value: o.id, label: o.nama }))"
            :error="fieldError('id_jenis_libur')"
            @update:model-value="updateField('id_jenis_libur', $event)"
          />

          <FormField
            :model-value="values.nama_libur"
            name="nama_libur"
            label="Nama Libur"
            required
            hint="Maksimal 100 karakter; nama boleh sama dengan tahun lain."
            :error="fieldError('nama_libur')"
            @update:model-value="updateField('nama_libur', $event)"
          />

          <FormField
            :model-value="values.keterangan"
            name="keterangan"
            label="Keterangan"
            type="textarea"
            :error="fieldError('keterangan')"
            @update:model-value="updateField('keterangan', $event)"
          />

          <FormField
            v-if="!isEdit"
            :model-value="values.status"
            name="status"
            label="Status"
            type="select"
            required
            hint="Hanya hari libur Aktif yang dihitung sebagai hari libur (presensi, tukin, cuti)."
            :options="[
              { value: '1', label: 'Aktif' },
              { value: '2', label: 'Tidak Aktif' },
            ]"
            :error="fieldError('status')"
            @update:model-value="updateField('status', $event)"
          />

          <div class="flex justify-end gap-2 pt-2">
            <DialogClose class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Batal</DialogClose>
            <button
              type="submit"
              class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-white hover:bg-brand-primary/90 disabled:opacity-60"
              :disabled="isSubmitting"
            >
              {{ submitting ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
