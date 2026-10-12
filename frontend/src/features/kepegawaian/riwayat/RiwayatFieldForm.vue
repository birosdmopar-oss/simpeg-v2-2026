<script setup lang="ts">
/**
 * Form generik riwayat: dirender dari FieldDef (dua kolom, label di atas, tanda (*) merah), divalidasi skema Zod
 * dinamis lewat VeeValidate (ADR-026). Nama isian = kolom DDL.
 *
 * - Isian `ref` memuat opsi master lewat `useCascadeOptions` (riwayatCascade.ts): isian ber-`dependsOn` berjenjang —
 *   mengganti induk mengosongkan dan memuat ulang turunannya.
 * - Isian ber-`showWhen` hanya tampil (dan hanya wajib) bila syaratnya terpenuhi; saat kirim, isian tersembunyi
 *   dikirim kosong (= NULL).
 * - Isian ber-`hint` (isian hitung) menampilkan petunjuk, mis. "dikosongkan = dihitung otomatis"; isian ber-`resetOn`
 *   dikosongkan saat pengguna mengubah isian pemicunya (dihitung ulang server kecuali diisi lagi).
 *
 * Lampiran: satu isian berkas per kode `jenis_rwy` (`berkas[<id_riwayat>]`). Lampiran ber-`wajib` harus dipilih saat
 * TAMBAH, dan saat UBAH bila baris itu belum punya lampiran kode tersebut (`lampiranTersimpan[<kode>] === 0`, ikut legacy
 * `vp()`); selain itu berkas saat ubah bersifat tambahan. Server tetap yang menegakkan (422 `berkas.<id>`).
 * Galat server 422 (`errors.<kolom>`, `errors["berkas.<id>"]`) diteruskan lewat prop `serverErrors`.
 */
import { toTypedSchema } from '@vee-validate/zod'
import { useForm } from 'vee-validate'
import { onMounted, reactive, type Ref, watch } from 'vue'

import FormField from '@/shared/components/FormField.vue'
import { UiButton, UiFileField, UiSelect, UiTextField } from '@/shared/ui'

import type { RiwayatValidationErrors } from '../services/apiErrors'

import { fieldVisible, type FieldDef, type LampiranDef } from './riwayat.config'
import { buildRiwayatSchema } from './riwayat.schema'
import type { BerkasMap } from './riwayat.service'
import { useRiwayatRefOptions } from './riwayatCascade'

const props = withDefaults(
  defineProps<{
    fields: FieldDef[]
    lampiran?: LampiranDef[]
    mode?: 'create' | 'edit'
    initial?: Record<string, string>
    /** Jumlah lampiran yang sudah tersimpan per kode (mode edit); kode tanpa entri = tidak diketahui. */
    lampiranTersimpan?: Record<number, number>
    serverErrors?: RiwayatValidationErrors | null
    busy?: boolean
    submitLabel?: string
    cancelLabel?: string
    /** Awalan data-testid agar beberapa form di satu halaman tidak bentrok. */
    testid?: string
  }>(),
  {
    lampiran: () => [],
    mode: 'create',
    initial: () => ({}),
    lampiranTersimpan: () => ({}),
    serverErrors: null,
    busy: false,
    submitLabel: 'Simpan',
    cancelLabel: 'Batal',
    testid: 'riwayat',
  },
)

const emit = defineEmits<{ submit: [values: Record<string, string>, berkas: BerkasMap]; cancel: [] }>()

const blank = (): Record<string, string> => Object.fromEntries(props.fields.map((f) => [f.name, props.initial[f.name] ?? '']))

const { errors, values, handleSubmit, resetForm, defineField } = useForm<Record<string, string>>({
  validationSchema: toTypedSchema(buildRiwayatSchema(props.fields)),
  initialValues: blank(),
})

const models: Record<string, Ref<string>> = {}
for (const f of props.fields) models[f.name] = defineField(f.name)[0] as Ref<string>

const berkas = reactive<BerkasMap>({})
const berkasError = reactive<Record<number, string>>({})

const refs = useRiwayatRefOptions(props.fields)
onMounted(() => void refs.init(blank()))

watch(
  () => props.initial,
  () => {
    resetForm({ values: blank() })
    for (const key of Object.keys(berkas)) delete berkas[Number(key)]
    for (const key of Object.keys(berkasError)) delete berkasError[Number(key)]
    void refs.init(blank())
  },
)

/** Isian hitung yang `resetOn`-nya memuat isian yang baru diubah pengguna dikosongkan (dihitung ulang server). */
function onEdited(name: string): void {
  for (const g of props.fields) {
    const model = models[g.name]
    if (model && g.resetOn?.includes(name)) model.value = ''
  }
}

async function onRefChange(f: FieldDef, value: string): Promise<void> {
  const model = models[f.name]
  if (model) model.value = value
  onEdited(f.name)
  for (const name of await refs.select(f.name, value)) {
    const turunan = models[name]
    if (turunan) turunan.value = ''
    onEdited(name)
  }
}

const visible = (f: FieldDef): boolean => fieldVisible(f, values as Record<string, string | undefined>)

function onFile(def: LampiranDef, file: File | null): void {
  delete berkasError[def.id_riwayat]
  if (file) berkas[def.id_riwayat] = file
  else delete berkas[def.id_riwayat]
}

const fileRequired = (def: LampiranDef): boolean => def.wajib && (props.mode === 'create' || props.lampiranTersimpan[def.id_riwayat] === 0)

function missingWajib(): boolean {
  let missing = false
  for (const def of props.lampiran) {
    if (fileRequired(def) && !berkas[def.id_riwayat]) {
      berkasError[def.id_riwayat] = `${def.label} wajib diunggah.`
      missing = true
    }
  }
  return missing
}

const submit = handleSubmit(
  (submitted) => {
    if (missingWajib()) return
    // Isian bersyarat yang tersembunyi dikirim kosong (= NULL), bukan nilai lama yang tak terlihat.
    const out = Object.fromEntries(props.fields.map((f) => [f.name, visible(f) ? (submitted[f.name] ?? '') : '']))
    emit('submit', out, { ...berkas })
  },
  () => {
    missingWajib()
  },
)

function cancel(): void {
  resetForm({ values: blank() })
  emit('cancel')
}

const fieldError = (name: string): string => errors.value[name] ?? props.serverErrors?.fields[name] ?? ''
const fileError = (id: number): string => berkasError[id] ?? props.serverErrors?.berkas[id] ?? ''
const helpText = (f: FieldDef): string => fieldError(f.name) || f.hint || ''
</script>

<template>
  <form novalidate :data-testid="`${testid}-form`" @submit.prevent="submit">
    <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
      <template v-for="f in fields" :key="f.name">
        <div v-if="visible(f)" :class="f.wide ? 'md:col-span-2' : ''">
          <FormField
            v-if="f.type === 'textarea'"
            v-model="models[f.name].value"
            type="textarea"
            :name="f.name"
            :label="f.label"
            :required="f.required"
            :disabled="busy"
            :error="fieldError(f.name)"
            :hint="f.hint"
            :placeholder="f.placeholder"
            :data-field="f.name"
          />
          <UiSelect
            v-else-if="f.type === 'ref'"
            :model-value="models[f.name].value"
            :label="f.label"
            :required="f.required"
            :disabled="busy"
            :options="refs.options(f.name)"
            :placeholder="`Pilih ${f.label.toLowerCase()}...`"
            :clearable="!f.required"
            :state="fieldError(f.name) ? 'error' : 'default'"
            :help-text="helpText(f)"
            :data-field="f.name"
            @update:model-value="(v) => onRefChange(f, v)"
          />
          <UiSelect
            v-else-if="f.type === 'select'"
            v-model="models[f.name].value"
            :label="f.label"
            :required="f.required"
            :disabled="busy"
            :options="f.options ?? []"
            :placeholder="`Pilih ${f.label.toLowerCase()}...`"
            :clearable="!f.required"
            :state="fieldError(f.name) ? 'error' : 'default'"
            :help-text="helpText(f)"
            :data-field="f.name"
            @update:model-value="onEdited(f.name)"
          />
          <UiTextField
            v-else
            v-model="models[f.name].value"
            :label="f.label"
            :required="f.required"
            :disabled="busy"
            :type="f.type === 'date' ? 'date' : 'text'"
            :inputmode="f.type === 'number' ? 'numeric' : undefined"
            :placeholder="f.placeholder"
            :state="fieldError(f.name) ? 'error' : 'default'"
            :help-text="helpText(f)"
            :data-field="f.name"
            @update:model-value="onEdited(f.name)"
          />
        </div>
      </template>
    </div>

    <fieldset v-if="lampiran.length" class="mt-6 border-t border-slate-200 pt-5">
      <legend class="mb-3 text-body1 font-semibold text-slate-900">Lampiran</legend>
      <p v-if="mode === 'edit'" class="mb-3 text-body2 text-slate-500">Berkas yang dipilih ditambahkan ke lampiran data ini.</p>
      <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
        <div v-for="def in lampiran" :key="def.id_riwayat" :data-testid="`${testid}-berkas-${def.id_riwayat}`">
          <UiFileField
            :label="fileRequired(def) ? `${def.label} *` : def.label"
            :allowed="def.ekstensi"
            :max-mb="def.batas_mb"
            :disabled="busy"
            @change="(file) => onFile(def, file)"
          />
          <p v-if="fileError(def.id_riwayat)" role="alert" class="mt-1 text-caption text-danger" :data-testid="`${testid}-berkas-error-${def.id_riwayat}`">
            {{ fileError(def.id_riwayat) }}
          </p>
        </div>
      </div>
    </fieldset>

    <ul v-if="serverErrors?.general.length" role="alert" class="mt-5 space-y-1 rounded-lg bg-danger-soft px-3 py-2 text-body2 text-danger" :data-testid="`${testid}-general-error`">
      <li v-for="message in serverErrors.general" :key="message">{{ message }}</li>
    </ul>

    <div class="mt-6 flex flex-wrap items-center justify-end gap-3">
      <UiButton v-if="cancelLabel" variant="secondary" appearance="soft" :disabled="busy" :data-testid="`${testid}-cancel`" @click="cancel">{{ cancelLabel }}</UiButton>
      <UiButton type="submit" :loading="busy" :data-testid="`${testid}-save`">{{ submitLabel }}</UiButton>
    </div>
  </form>
</template>
