<script setup lang="ts">
/**
 * Form tambah/edit master generik (Modul G) — Radix Vue Dialog + VeeValidate/Zod. Field dibangun dari metadata
 * master: kode (hanya saat tambah dan hanya master ber-kode manual; kode tidak bisa diubah), induk (dropdown
 * berjenjang), nama, kolom tambahan legacy (kd_area, kd_pos, status_pegawai, ...), urutan.
 * Error 422 backend (keunikan kode/nama, induk non-aktif) dipetakan ke field.
 * Field yang tidak dikirim di list admin (`listExclude`, mis. isi artikel FAQ bertipe html) dimuat dari detail
 * sebelum form edit bisa disimpan, agar nilainya tidak terkirim kosong.
 * CR-009: field `ref` = dropdown dari `{entity}/options` (berjenjang lewat `depends_on`: memilih induk mengosongkan &
 * memuat ulang turunannya; nilai tersimpan yang kini non-aktif tetap tampil sebagai pilihan bertanda), field
 * `boolean` = checkbox 1/0, field angka memakai batas min/max meta, dan urutan mode manual (nilai tetap, entri lain
 * tidak bergeser, dibatasi `order_max`).
 */
import { toTypedSchema } from '@vee-validate/zod'
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { type TypedSchema, useForm } from 'vee-validate'
import { computed, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'

import { resolveAncestorPath, useCascadeOptions } from '../composables/useCascadeOptions'
import { ancestorChain, buildMasterSchema, fieldsMissingFromRow, formatMasterNumber, isManualOrder } from '../schemas/master.schema'
import { masterService } from '../services/master.service'
import type { MasterFieldMeta, MasterFormValues, MasterMeta, MasterOption, MasterRow } from '../types'

const props = defineProps<{ open: boolean; meta: MasterMeta; allMeta: MasterMeta[]; row: MasterRow | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [row: MasterRow] }>()

const isEdit = computed(() => props.row !== null)
const submitting = ref(false)
const formError = ref('')

const chain = computed(() => ancestorChain(props.meta, props.allMeta))
const cascade = useCascadeOptions(chain)

/** Pilihan dropdown field ref per nama field (CR-009), dari `{entity}/options?parent=<nilai depends_on>`. */
const refState = ref<Record<string, { options: MasterOption[]; loading: boolean }>>({})
const refTokens: Record<string, number> = {}

const refFields = computed(() => props.meta.fields.filter((f) => f.type === 'ref'))

/** Field ref yang bergantung langsung pada field `name` (depends_on). */
function dependentsOf(name: string): MasterFieldMeta[] {
  return refFields.value.filter((f) => f.depends_on === name)
}

const schema = computed(
  () => toTypedSchema(buildMasterSchema(props.meta, isEdit.value)) as unknown as TypedSchema<MasterFormValues>,
)

const { values, handleSubmit, errors, resetForm, setFieldError, setFieldValue, submitCount } = useForm<MasterFormValues>({
  validationSchema: schema,
})

/**
 * Field di form ini tidak didaftarkan lewat useField, sehingga validasi "silent" vee-validate setelah resetForm tetap
 * mengisi `errors` untuk semua field (form baru langsung merah). Pesan hanya ditampilkan untuk field yang sudah
 * diisi/diubah pengguna, atau setelah form dikirim (termasuk error 422 dari backend).
 */
const interacted = ref(new Set<string>())

function fieldError(field: string): string | undefined {
  return submitCount.value > 0 || interacted.value.has(field) ? errors.value[field] : undefined
}

function updateField(field: string, value: string): void {
  interacted.value.add(field)
  setFieldValue(field, value)
}

const rowId = computed(() => (props.row ? String(props.row[props.meta.primary_key] ?? '') : ''))

/** Entri berstatus 10 (Dihapus) tidak punya urutan tampil (backend menolak 422); urutan diatur setelah dipulihkan. */
const showOrder = computed(() => props.meta.has_order && String(props.row?.status ?? '') !== '10')

/** Form lebar untuk master yang punya field HTML (textarea besar + pratinjau). */
const hasHtmlField = computed(() => props.meta.fields.some((f) => f.type === 'html'))

/** Field edit yang nilainya sedang dimuat dari detail (tidak ada di baris list). */
const pendingFields = ref(new Set<string>())
const detailFailed = ref(false)
let detailToken = 0

async function loadMissingFields(row: MasterRow, missing: MasterFieldMeta[], token: number): Promise<void> {
  try {
    const full = await masterService.get(props.meta.key, String(row[props.meta.primary_key] ?? ''))
    if (token !== detailToken) return
    for (const field of missing) setFieldValue(field.name, String(full[field.name] ?? ''), false)
  } catch (err) {
    if (token !== detailToken) return
    detailFailed.value = true
    formError.value = `Gagal memuat data lengkap (${missing.map((f) => f.label).join(', ')}). ${
      isApiError(err) ? err.message : 'Tutup lalu buka kembali form ini.'
    }`
  } finally {
    if (token === detailToken) pendingFields.value = new Set()
  }
}

watch(
  () => [props.open, props.row, props.meta.key] as const,
  async ([open, row]) => {
    detailToken++
    if (!open) return
    formError.value = ''
    const m = props.meta
    const initial: MasterFormValues = {
      [m.primary_key]: row ? String(row[m.primary_key] ?? '') : '',
      [m.name_field]: row ? String(row[m.name_field] ?? '') : '',
    }
    if (showOrder.value) initial.order = row ? String(row.order ?? '') : ''
    for (const field of m.fields) {
      const empty = field.type === 'boolean' ? '0' : ''
      initial[field.name] = row ? String(row[field.name] ?? empty) : empty
    }
    if (m.parent) initial[m.parent.field] = row ? String(row[m.parent.field] ?? '') : ''
    // errors: {} — schema baru (computed) memicu validasi ulang; form yang baru dibuka tidak boleh langsung merah.
    resetForm({ values: initial, errors: {} })
    interacted.value = new Set()

    const missing = fieldsMissingFromRow(m, row)
    pendingFields.value = new Set(missing.map((f) => f.name))
    detailFailed.value = false
    if (row && missing.length > 0) void loadMissingFields(row, missing, detailToken)
    void initRefOptions()

    if (m.parent) {
      const directParent = row ? String(row[m.parent.field] ?? '') : ''
      const path = directParent ? await resolveAncestorPath(chain.value, directParent) : []
      await cascade.init(path)
    }
  },
  { immediate: true },
)

/**
 * Muat pilihan satu field ref. Field ber-depends_on yang induknya belum dipilih tidak dimuat (kosong & terkunci). Nilai
 * tersimpan yang tidak ada di pilihan aktif (entri rujukan kini non-aktif/dihapus) tetap ditampilkan dengan tanda,
 * agar form edit tidak diam-diam mengosongkannya.
 */
async function loadRefOptions(field: MasterFieldMeta): Promise<void> {
  const token = (refTokens[field.name] = (refTokens[field.name] ?? 0) + 1)
  const entity = field.entity ?? ''
  const parent = field.depends_on ? String(values[field.depends_on] ?? '') : null
  if (!entity || parent === '') {
    refState.value[field.name] = { options: [], loading: false }
    return
  }
  refState.value[field.name] = { options: refState.value[field.name]?.options ?? [], loading: true }
  let options: MasterOption[] = []
  let loaded = true
  try {
    options = await masterService.options(entity, parent)
  } catch (err) {
    loaded = false
    formError.value = `Gagal memuat pilihan ${field.label}. ${isApiError(err) ? err.message : ''}`.trim()
  }
  const current = String(values[field.name] ?? '')
  if (current !== '' && !options.some((o) => o.id === current)) {
    // Pilihan gagal dimuat: nilai tersimpan tetap dipertahankan, tanpa tanda non-aktif (statusnya tidak diketahui).
    const kept = loaded ? await unavailableRefOption(entity, current) : { id: current, nama: current, parent: null }
    options = [...options, kept]
  }
  if (token !== refTokens[field.name]) return
  refState.value[field.name] = { options, loading: false }
}

async function unavailableRefOption(entity: string, id: string): Promise<MasterOption> {
  const refMeta = props.allMeta.find((m) => m.key === entity)
  try {
    const row = await masterService.get(entity, id)
    return { id, nama: `${refMeta ? String(row[refMeta.name_field] ?? id) : id} — tidak aktif`, parent: null }
  } catch {
    return { id, nama: `${id} — tidak ditemukan`, parent: null }
  }
}

/** Muat pilihan seluruh field ref: field tanpa depends_on lebih dulu, lalu turunannya berjenjang. */
async function initRefOptions(): Promise<void> {
  refState.value = {}
  const load = async (field: MasterFieldMeta): Promise<void> => {
    await loadRefOptions(field)
    for (const child of dependentsOf(field.name)) await load(child)
  }
  for (const root of refFields.value.filter((f) => !f.depends_on)) await load(root)
}

/** Ganti pilihan field ref: field yang bergantung padanya (berjenjang) dikosongkan lalu pilihannya dimuat ulang. */
async function onRefChange(field: MasterFieldMeta, value: string): Promise<void> {
  updateField(field.name, value)
  await resetDependents(field.name)
}

async function resetDependents(name: string): Promise<void> {
  for (const child of dependentsOf(name)) {
    setFieldValue(child.name, '', false)
    await loadRefOptions(child)
    await resetDependents(child.name)
  }
}

function refBlocked(field: MasterFieldMeta): boolean {
  return Boolean(field.depends_on) && String(values[field.depends_on ?? ''] ?? '') === ''
}

function refPlaceholder(field: MasterFieldMeta): string {
  if (refState.value[field.name]?.loading) return 'Memuat...'
  if (refBlocked(field)) {
    const parentLabel = props.meta.fields.find((f) => f.name === field.depends_on)?.label ?? 'induk'
    return `Pilih ${parentLabel} dulu`
  }
  return `Pilih ${field.label}`
}

function refSelectOptions(field: MasterFieldMeta): Array<{ value: string; label: string }> {
  return (refState.value[field.name]?.options ?? []).map((o) => ({ value: o.id, label: `${o.nama} (${o.id})` }))
}

async function onLevelChange(index: number, value: string): Promise<void> {
  await cascade.select(index, value)
  if (props.meta.parent) updateField(props.meta.parent.field, cascade.leafValue())
}

const onSubmit = handleSubmit(async (formValues) => {
  // Nilai field listExclude belum termuat: jangan kirim (akan terkirim kosong / menghapus isi).
  if (pendingFields.value.size > 0 || detailFailed.value) return
  submitting.value = true
  formError.value = ''
  const m = props.meta
  const payload: Record<string, string | number> = { [m.name_field]: String(formValues[m.name_field] ?? '').trim() }
  if (m.parent) payload[m.parent.field] = String(formValues[m.parent.field] ?? '')
  for (const field of m.fields) {
    const value = String(formValues[field.name] ?? '').trim()
    // Saat edit, kolom opsional yang dikosongkan tetap dikirim agar nilainya terhapus.
    if (value !== '' || field.required || isEdit.value) payload[field.name] = value
  }

  // Kirim urutan hanya kalau diubah: memindah posisi menggeser entri lain (tiap geseran teraudit).
  if (showOrder.value) {
    const order = String(formValues.order ?? '').trim()
    const originalOrder = props.row ? String(props.row.order ?? '') : ''
    if (order !== '' && order !== originalOrder) payload.order = Number(order)
  }

  try {
    let saved: MasterRow
    if (isEdit.value) {
      saved = await masterService.update(m.key, rowId.value, payload)
    } else {
      if (!m.auto_increment) payload[m.primary_key] = String(formValues[m.primary_key] ?? '').trim()
      saved = await masterService.create(m.key, payload)
    }
    emit('saved', saved)
    emit('update:open', false)
  } catch (err) {
    if (isApiError(err) && err.errors) {
      for (const [field, messages] of Object.entries(err.errors)) {
        setFieldError(field, messages[0])
      }
      formError.value = err.message
    } else if (isApiError(err)) {
      formError.value = err.message
    } else {
      formError.value = 'Terjadi kesalahan. Silakan coba lagi.'
    }
  } finally {
    submitting.value = false
  }
})

/** Pemetaan tipe field backend → tipe input FormField. */
function fieldInputType(field: MasterFieldMeta): 'text' | 'number' | 'select' | 'date' | 'textarea' | 'html' | 'checkbox' {
  // Desimal (mis. koordinat) memakai input teks: input number menolak sebagian ketikan tanda minus.
  if (field.type === 'int') return 'number'
  if (field.type === 'boolean') return 'checkbox'
  if (field.type === 'select' || field.type === 'ref') return 'select'
  if (field.type === 'date') return 'date'
  if (field.type === 'textarea') return 'textarea'
  if (field.type === 'html') return 'html'
  return 'text'
}

function fieldPlaceholder(field: MasterFieldMeta): string | undefined {
  if (pendingFields.value.has(field.name)) return 'Memuat...'
  if (field.type === 'select') return `Pilih ${field.label}`
  if (field.type === 'html') return '<p>Tulis isi dalam format HTML...</p>'
  return undefined
}

function fieldHint(field: MasterFieldMeta): string {
  if (field.hint) return field.hint
  if (field.type === 'int' || field.type === 'decimal') {
    const min = field.min ?? null
    const max = field.max ?? null
    if (min !== null && max !== null) return `Angka ${formatMasterNumber(min)} sampai ${formatMasterNumber(max)}.`
    if (min !== null) return `Minimal ${formatMasterNumber(min)}.`
    if (max !== null) return `Maksimal ${formatMasterNumber(max)}.`
  }
  if (field.type === 'html') {
    return 'Format HTML. Tag yang diizinkan: p, br, strong, em, u, s, sub, sup, ul, ol, li, a, img, h2-h4, blockquote, pre, code, hr, table, span. Script, style, dan atribut on* dibuang otomatis saat disimpan.'
  }
  return ''
}

/** Label field lingkup urutan (order_scope), mis. " per Jenis Diklat". */
const orderScopeText = computed(() => {
  const labels = (props.meta.order_scope ?? []).map((name) => props.meta.fields.find((f) => f.name === name)?.label ?? name)
  return labels.length > 0 ? ` per ${labels.join(' dan ')}` : ''
})

const orderLabel = computed(() => (isManualOrder(props.meta) ? 'Urutan (nilai tetap)' : 'Urutan tampil'))

const orderHint = computed(() => {
  if (isManualOrder(props.meta)) {
    const max = props.meta.order_max ?? null
    const limit = max !== null ? `, maksimal ${formatMasterNumber(max)}` : ''
    return `Nilai urutan (mis. level) disimpan apa adanya${limit}; entri lain tidak bergeser.${isEdit.value ? '' : ' Kosongkan = nilai terbesar + 1.'}`
  }
  return isEdit.value
    ? `Ubah untuk memindah posisi${orderScopeText.value}; entri lain ikut bergeser.`
    : `Kosongkan = ditaruh paling akhir${orderScopeText.value}.`
})

const codeHint = computed(() =>
  props.meta.id_digits !== null
    ? `Tepat ${props.meta.id_digits} digit angka, tanpa titik (kode wilayah legacy).`
    : `Maksimal ${props.meta.id_max_length} karakter, tanpa spasi.`,
)

const { levels } = cascade
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-lg bg-white p-6 shadow-xl focus:outline-none"
        :class="hasHtmlField ? 'max-w-3xl' : 'max-w-lg'"
      >
        <div class="mb-4 flex items-start justify-between">
          <div>
            <DialogTitle class="text-lg font-semibold text-slate-900">{{ isEdit ? `Edit ${meta.label}` : `Tambah ${meta.label}` }}</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">
              {{
                isEdit
                  ? `Kode ${rowId} (kode tidak dapat diubah)`
                  : meta.auto_increment
                    ? 'Kode dibuat otomatis oleh sistem.'
                    : 'Kode tidak dapat diubah setelah disimpan.'
              }}
            </DialogDescription>
          </div>
          <DialogClose class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <form class="space-y-4" novalidate data-testid="master-form" @submit="onSubmit">
          <p v-if="formError" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
            {{ formError }}
          </p>

          <FormField
            v-if="!isEdit && !meta.auto_increment"
            :model-value="values[meta.primary_key]"
            :name="meta.primary_key"
            label="Kode"
            required
            :hint="codeHint"
            :error="fieldError(meta.primary_key)"
            @update:model-value="updateField(meta.primary_key, $event)"
          />

          <template v-if="meta.parent">
            <FormField
              v-for="(level, index) in levels"
              :key="level.meta.key"
              :model-value="level.value"
              :name="index === levels.length - 1 ? meta.parent.field : `cascade_${level.meta.key}`"
              :label="level.meta.label"
              type="select"
              :required="index === levels.length - 1"
              :placeholder="level.loading ? 'Memuat...' : `Pilih ${level.meta.label}`"
              :disabled="level.loading || (index > 0 && !levels[index - 1]?.value)"
              :options="level.options.map((o) => ({ value: o.id, label: `${o.nama} (${o.id})` }))"
              :error="index === levels.length - 1 ? fieldError(meta.parent.field) : ''"
              @update:model-value="onLevelChange(index, $event)"
            />
          </template>

          <FormField
            :model-value="values[meta.name_field]"
            :name="meta.name_field"
            :label="meta.name_label"
            required
            :error="fieldError(meta.name_field)"
            @update:model-value="updateField(meta.name_field, $event)"
          />

          <template v-for="field in meta.fields" :key="field.name">
            <FormField
              v-if="field.type === 'ref'"
              :model-value="values[field.name]"
              :name="field.name"
              :label="field.label"
              type="select"
              :required="field.required"
              :allow-empty="!field.required"
              :options="refSelectOptions(field)"
              :placeholder="refPlaceholder(field)"
              :disabled="Boolean(refState[field.name]?.loading) || refBlocked(field)"
              :hint="field.hint ?? ''"
              :error="fieldError(field.name)"
              @update:model-value="onRefChange(field, $event)"
            />
            <FormField
              v-else
              :model-value="values[field.name]"
              :name="field.name"
              :label="field.label"
              :type="fieldInputType(field)"
              :required="field.required"
              :options="field.options ?? []"
              :placeholder="fieldPlaceholder(field)"
              :disabled="pendingFields.has(field.name)"
              :hint="fieldHint(field)"
              :error="fieldError(field.name)"
              @update:model-value="updateField(field.name, $event)"
            />
          </template>

          <FormField
            v-if="showOrder"
            :model-value="values.order"
            name="order"
            :label="orderLabel"
            type="number"
            :hint="orderHint"
            :error="fieldError('order')"
            @update:model-value="updateField('order', $event)"
          />

          <div class="flex justify-end gap-2 pt-2">
            <DialogClose class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Batal</DialogClose>
            <button
              type="submit"
              class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-white hover:bg-brand-primary/90 disabled:opacity-60"
              :disabled="submitting || pendingFields.size > 0 || detailFailed"
            >
              {{ submitting ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
