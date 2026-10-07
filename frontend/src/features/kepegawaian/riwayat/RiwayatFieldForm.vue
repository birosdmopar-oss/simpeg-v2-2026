<script setup lang="ts">
/**
 * Form generik riwayat: dirender dari FieldDef (dua kolom, label di atas, tanda (*) merah), divalidasi skema Zod
 * dinamis lewat VeeValidate (ADR-026). Nama isian = kolom DDL. Field `ref` mengambil opsi master
 * (`GET master/{entity}/options`) lewat re-export `@/shared/services/masterOptions`.
 *
 * Lampiran: satu isian berkas per kode `jenis_rwy` (`berkas[<id_riwayat>]`). Saat TAMBAH, lampiran ber-`wajib` harus
 * dipilih; saat UBAH berkas bersifat tambahan (server yang memastikan baris tetap punya lampiran wajib).
 * Galat server 422 (`errors.<kolom>`, `errors["berkas.<id>"]`) diteruskan lewat prop `serverErrors`.
 */
import { toTypedSchema } from '@vee-validate/zod'
import { useForm } from 'vee-validate'
import { onMounted, reactive, type Ref, watch } from 'vue'

import FormField from '@/shared/components/FormField.vue'
import { masterOptions } from '@/shared/services/masterOptions'
import { UiButton, UiFileField, UiSelect, UiTextField } from '@/shared/ui'

import type { RiwayatValidationErrors } from '../services/apiErrors'

import type { FieldDef, FieldOption, LampiranDef } from './riwayat.config'
import { buildRiwayatSchema } from './riwayat.schema'
import type { BerkasMap } from './riwayat.service'

const props = withDefaults(
  defineProps<{
    fields: FieldDef[]
    lampiran?: LampiranDef[]
    mode?: 'create' | 'edit'
    initial?: Record<string, string>
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
    serverErrors: null,
    busy: false,
    submitLabel: 'Simpan',
    cancelLabel: 'Batal',
    testid: 'riwayat',
  },
)

const emit = defineEmits<{ submit: [values: Record<string, string>, berkas: BerkasMap]; cancel: [] }>()

const blank = (): Record<string, string> => Object.fromEntries(props.fields.map((f) => [f.name, props.initial[f.name] ?? '']))

const { errors, handleSubmit, resetForm, defineField } = useForm<Record<string, string>>({
  validationSchema: toTypedSchema(buildRiwayatSchema(props.fields)),
  initialValues: blank(),
})

const models: Record<string, Ref<string>> = {}
for (const f of props.fields) models[f.name] = defineField(f.name)[0] as Ref<string>

const berkas = reactive<BerkasMap>({})
const berkasError = reactive<Record<number, string>>({})

watch(
  () => props.initial,
  () => {
    resetForm({ values: blank() })
    for (const key of Object.keys(berkas)) delete berkas[Number(key)]
  },
)

// Opsi field rujukan master. Gagal memuat → daftar kosong (form tetap bisa dibuka; server memvalidasi).
const refOptions = reactive<Record<string, FieldOption[]>>({})
onMounted(async () => {
  await Promise.all(
    props.fields
      .filter((f) => f.type === 'ref' && f.entity)
      .map(async (f) => {
        try {
          const options = await masterOptions(f.entity as string)
          refOptions[f.name] = options.map((o) => ({ value: String(o.id), label: o.nama }))
        } catch {
          refOptions[f.name] = []
        }
      }),
  )
})

function onFile(def: LampiranDef, file: File | null): void {
  delete berkasError[def.id_riwayat]
  if (file) berkas[def.id_riwayat] = file
  else delete berkas[def.id_riwayat]
}

function missingWajib(): boolean {
  let missing = false
  if (props.mode !== 'create') return false
  for (const def of props.lampiran) {
    if (def.wajib && !berkas[def.id_riwayat]) {
      berkasError[def.id_riwayat] = `${def.label} wajib diunggah.`
      missing = true
    }
  }
  return missing
}

const submit = handleSubmit(
  (values) => {
    if (missingWajib()) return
    emit('submit', values, { ...berkas })
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
const fileRequired = (def: LampiranDef): boolean => def.wajib && props.mode === 'create'
</script>

<template>
  <form novalidate :data-testid="`${testid}-form`" @submit.prevent="submit">
    <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
      <div v-for="f in fields" :key="f.name" :class="f.wide ? 'md:col-span-2' : ''">
        <FormField
          v-if="f.type === 'textarea'"
          v-model="models[f.name].value"
          type="textarea"
          :name="f.name"
          :label="f.label"
          :required="f.required"
          :disabled="busy"
          :error="fieldError(f.name)"
          :placeholder="f.placeholder"
        />
        <UiSelect
          v-else-if="f.type === 'select' || f.type === 'ref'"
          v-model="models[f.name].value"
          :label="f.label"
          :required="f.required"
          :disabled="busy"
          :options="f.type === 'ref' ? (refOptions[f.name] ?? []) : (f.options ?? [])"
          :placeholder="`Pilih ${f.label.toLowerCase()}...`"
          :clearable="!f.required"
          :state="fieldError(f.name) ? 'error' : 'default'"
          :help-text="fieldError(f.name)"
          :data-field="f.name"
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
          :help-text="fieldError(f.name)"
          :data-field="f.name"
        />
      </div>
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
