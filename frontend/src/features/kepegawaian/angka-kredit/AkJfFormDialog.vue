<script setup lang="ts">
/**
 * Form tambah/ubah master Angka Kredit JF (MAKE-021; legacy `views/hr/master/jabatan/akjf/form.php`). Tambah: Group
 * Jabatan (JF) → Sub Group → Jenjang JF → Jabatan (dimuat dari server) + Gol. Ruang + Angka Kredit. Ubah: jabatan & gol.
 * ruang terkunci (tampil saja), hanya Angka Kredit (legacy :128-144). Galat 422 server dipetakan ke isian.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import UiButton from '@/shared/ui/UiButton.vue'

import { akJfService, type AkJfJabatan, type AkJfOpsi, type AkJfRow } from './akJf.service'

const props = defineProps<{ open: boolean; row: AkJfRow | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [row: AkJfRow] }>()

const isEdit = computed(() => props.row !== null)
const opsi = ref<AkJfOpsi | null>(null)
const daftarJabatan = ref<AkJfJabatan[]>([])
/** Jabatan untuk pilihan group/sub group/jenjang sekarang sudah dimuat (kosong → keterangan, CR-052 Q4P-I2). */
const jabatanDimuat = ref(false)
const form = reactive({ id_group_jabatan: '', id_sub_group_jabatan: '', id_jenjang_jf: '', id_jabatan: '', id_pangkat: '', ak: '' })
const errors = reactive<Record<string, string>>({})
const formError = ref('')
const submitting = ref(false)

const subGroup = computed(() => (opsi.value?.sub_group_jabatan ?? []).filter((s) => String(s.id_group_jabatan) === form.id_group_jabatan))

function reset(): void {
  Object.assign(form, { id_group_jabatan: '', id_sub_group_jabatan: '', id_jenjang_jf: '', id_jabatan: '', id_pangkat: '', ak: props.row ? String(props.row.ak) : '' })
  for (const k of Object.keys(errors)) delete errors[k]
  formError.value = ''
  daftarJabatan.value = []
  jabatanDimuat.value = false
}

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    reset()
    if (!isEdit.value && opsi.value === null) {
      try {
        opsi.value = await akJfService.opsi()
      } catch (err) {
        formError.value = isApiError(err) ? err.message : 'Gagal memuat pilihan.'
      }
    }
  },
  { immediate: true },
)

/** Token muat jabatan: respons lama tidak menimpa pilihan yang lebih baru. */
let token = 0
async function muatJabatan(): Promise<void> {
  form.id_jabatan = ''
  daftarJabatan.value = []
  jabatanDimuat.value = false
  if (!form.id_group_jabatan || !form.id_sub_group_jabatan || !form.id_jenjang_jf) return
  const t = ++token
  try {
    const hasil = await akJfService.jabatan({ id_group_jabatan: form.id_group_jabatan, id_sub_group_jabatan: form.id_sub_group_jabatan, id_jenjang_jf: form.id_jenjang_jf })
    if (t === token) {
      daftarJabatan.value = hasil
      jabatanDimuat.value = true
    }
  } catch {
    if (t === token) daftarJabatan.value = []
  }
}

function ubahIsian(nama: keyof typeof form, nilai: unknown): void {
  form[nama] = nilai === null || nilai === undefined ? '' : String(nilai)
  delete errors[nama]
  // Galat pasangan kembar (422 `id_pangkat`) melekat ke pasangan jabatan × gol.: hilang bila jabatan atau rantai
  // pilihannya diganti (CR-052).
  if (nama !== 'ak') delete errors.id_pangkat
  if (nama === 'id_group_jabatan') form.id_sub_group_jabatan = ''
  if (nama === 'id_group_jabatan' || nama === 'id_sub_group_jabatan' || nama === 'id_jenjang_jf') void muatJabatan()
}

function validasiKlien(): boolean {
  // Galat server kiriman sebelumnya dibuang: validasi dihitung ulang dari isian sekarang.
  for (const k of Object.keys(errors)) delete errors[k]
  if (!isEdit.value) {
    if (!form.id_jabatan) errors.id_jabatan = 'Silahkan pilih jabatan.'
    if (!form.id_pangkat) errors.id_pangkat = 'Silahkan pilih gol. ruang.'
  }
  const ak = form.ak.trim().replace(',', '.')
  if (ak === '') errors.ak = 'Angka kredit tidak boleh kosong.'
  else if (!/^\d{1,7}(\.\d{1,3})?$/.test(ak)) errors.ak = 'Angka kredit harus berupa angka ≥ 0 dengan paling banyak 3 angka desimal.'
  return Object.keys(errors).length === 0
}

async function onSubmit(event: Event): Promise<void> {
  event.preventDefault()
  if (submitting.value || !validasiKlien()) return
  submitting.value = true
  formError.value = ''
  const ak = form.ak.trim().replace(',', '.')
  try {
    const row = props.row
      ? await akJfService.update(props.row.id_jabatan, props.row.id_pangkat, ak)
      : await akJfService.create({ id_jabatan: form.id_jabatan, id_pangkat: form.id_pangkat, ak })
    emit('saved', row)
    emit('update:open', false)
  } catch (err) {
    if (isApiError(err) && err.status === 422 && err.errors) {
      for (const [k, v] of Object.entries(err.errors)) errors[k] = v[0] ?? ''
      if (!Object.keys(err.errors).some((k) => k in form)) formError.value = err.message
    } else {
      formError.value = isApiError(err) ? err.message : 'Gagal menyimpan.'
    }
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
        data-testid="ak-jf-dialog"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ isEdit ? 'Edit' : 'Tambah' }} Angka Kredit JF</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">
              {{ isEdit ? 'Jabatan dan gol. ruang tidak dapat diubah.' : 'Satu angka kredit untuk setiap pasangan jabatan dan gol. ruang.' }}
            </DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <form :class="FORM_GRID_CLASS" novalidate data-testid="ak-jf-form" @submit="onSubmit">
          <p v-if="formError" :class="FORM_ALERT_CLASS" role="alert" data-testid="ak-jf-form-error">{{ formError }}</p>

          <template v-if="isEdit && row">
            <FormField :model-value="row.jabatan" name="jabatan" label="Jabatan" disabled :class="FORM_WIDE_CLASS" />
            <FormField :model-value="`${row.pangkat} (${row.gol_ruang})`" name="gol_ruang" label="Gol. Ruang" disabled />
          </template>
          <template v-else>
            <FormField
              :model-value="form.id_group_jabatan"
              name="id_group_jabatan"
              label="Group Jabatan"
              type="select"
              required
              placeholder="Pilih Group Jabatan"
              :options="(opsi?.group_jabatan ?? []).map((g) => ({ value: String(g.id_group_jabatan), label: g.group_jabatan }))"
              :error="errors.id_group_jabatan"
              @update:model-value="ubahIsian('id_group_jabatan', $event)"
            />
            <FormField
              :model-value="form.id_sub_group_jabatan"
              name="id_sub_group_jabatan"
              label="Sub Group Jabatan"
              type="select"
              required
              placeholder="Pilih Sub Group Jabatan"
              :disabled="!form.id_group_jabatan"
              :options="subGroup.map((s) => ({ value: String(s.id_sub_group_jabatan), label: s.sub_group_jabatan }))"
              :error="errors.id_sub_group_jabatan"
              @update:model-value="ubahIsian('id_sub_group_jabatan', $event)"
            />
            <FormField
              :model-value="form.id_jenjang_jf"
              name="id_jenjang_jf"
              label="Jenjang JF"
              type="select"
              required
              placeholder="Pilih Jenjang JF"
              :options="(opsi?.jenjang_jf ?? []).map((j) => ({ value: String(j.id_jenjang_jf), label: `${j.kategori_jf} — ${j.jenjang_jf}` }))"
              :error="errors.id_jenjang_jf"
              @update:model-value="ubahIsian('id_jenjang_jf', $event)"
            />
            <FormField
              :model-value="form.id_jabatan"
              name="id_jabatan"
              label="Jabatan"
              type="select"
              required
              placeholder="Pilih Jabatan"
              :disabled="daftarJabatan.length === 0"
              :options="daftarJabatan.map((j) => ({ value: String(j.id_jabatan), label: j.jabatan }))"
              :hint="jabatanDimuat && daftarJabatan.length === 0 ? 'Tidak ada jabatan untuk pilihan ini.' : undefined"
              :error="errors.id_jabatan"
              @update:model-value="ubahIsian('id_jabatan', $event)"
            />
            <FormField
              :model-value="form.id_pangkat"
              name="id_pangkat"
              label="Gol. Ruang"
              type="select"
              required
              placeholder="Pilih Gol. Ruang"
              :options="(opsi?.pangkat ?? []).map((p) => ({ value: String(p.id_pangkat), label: `${p.gol_ruang} — ${p.pangkat}` }))"
              :error="errors.id_pangkat"
              @update:model-value="ubahIsian('id_pangkat', $event)"
            />
          </template>

          <FormField
            :model-value="form.ak"
            name="ak"
            label="Angka Kredit"
            required
            inputmode="decimal"
            hint="Angka, paling banyak 3 angka desimal (mis. 12,5)."
            :error="errors.ak"
            @update:model-value="ubahIsian('ak', $event)"
          />

          <div :class="FORM_ACTIONS_CLASS">
            <DialogClose as-child>
              <UiButton variant="secondary" appearance="soft">Batal</UiButton>
            </DialogClose>
            <UiButton type="submit" :disabled="submitting" data-testid="ak-jf-save">{{ submitting ? 'Menyimpan...' : 'Simpan' }}</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
