<script setup lang="ts">
/**
 * Form generik riwayat: dirender dari daftar FieldDef (dua kolom, label di atas, tanda (*) merah), divalidasi dengan
 * skema Zod dinamis lewat VeeValidate (ADR-026). Dipakai dialog tambah/ubah riwayat dan tab "record" (Data Alamat).
 * Semua field didaftarkan lewat defineField agar pesan error muncul (lihat DataUmumForm).
 */
import { toTypedSchema } from '@vee-validate/zod'
import { useForm } from 'vee-validate'
import { type Ref, watch } from 'vue'

import FormField from '@/shared/components/FormField.vue'
import { UiButton, UiSelect, UiTextField } from '@/shared/ui'

import type { FieldDef } from './riwayat.config'
import { buildRiwayatSchema } from './riwayat.schema'

const props = withDefaults(
  defineProps<{
    fields: FieldDef[]
    initial?: Record<string, string>
    readonly?: boolean
    submitLabel?: string
    /** Tombol "Batal" (dialog) atau "Batalkan Perubahan" (inline) — kosong = tidak ditampilkan. */
    cancelLabel?: string
    /** Awalan data-testid agar beberapa form di satu halaman tidak bentrok. */
    testid?: string
  }>(),
  { initial: () => ({}), readonly: false, submitLabel: 'Simpan', cancelLabel: '', testid: 'riwayat' },
)

const emit = defineEmits<{ submit: [values: Record<string, string>]; cancel: [] }>()

const blank = (): Record<string, string> => Object.fromEntries(props.fields.map((f) => [f.name, props.initial[f.name] ?? '']))

const { errors, handleSubmit, resetForm, defineField } = useForm<Record<string, string>>({
  validationSchema: toTypedSchema(buildRiwayatSchema(props.fields)),
  initialValues: blank(),
})

const models: Record<string, Ref<string>> = {}
for (const f of props.fields) models[f.name] = defineField(f.name)[0] as Ref<string>

watch(
  () => props.initial,
  () => resetForm({ values: blank() }),
)

const submit = handleSubmit((values) => emit('submit', values))

function cancel(): void {
  resetForm({ values: blank() })
  emit('cancel')
}

const error = (name: string): string => errors.value[name] ?? ''
</script>

<template>
  <form novalidate :data-testid="`${testid}-form`" @submit.prevent="submit">
    <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
      <div v-for="f in fields" :key="f.name" :class="f.wide ? 'md:col-span-2' : ''">
        <FormField
          v-if="f.type === 'textarea'"
          v-model="models[f.name].value"
          type="textarea"
          :label="f.label"
          :required="f.required"
          :disabled="readonly"
          :error="error(f.name)"
          :placeholder="f.placeholder"
        />
        <UiSelect
          v-else-if="f.type === 'select'"
          v-model="models[f.name].value"
          :label="f.label"
          :required="f.required"
          :disabled="readonly"
          :options="(f.options ?? []).map((o) => ({ value: o, label: o }))"
          :placeholder="`Pilih ${f.label.toLowerCase()}...`"
          :clearable="!f.required"
          :state="error(f.name) ? 'error' : 'default'"
          :help-text="error(f.name)"
        />
        <UiTextField
          v-else
          v-model="models[f.name].value"
          :label="f.label"
          :required="f.required"
          :disabled="readonly"
          :type="f.type === 'date' ? 'date' : 'text'"
          :inputmode="f.type === 'number' ? 'numeric' : undefined"
          :placeholder="f.placeholder"
          :state="error(f.name) ? 'error' : 'default'"
          :help-text="error(f.name)"
        />
      </div>
    </div>

    <div v-if="!readonly" class="mt-6 flex flex-wrap items-center justify-end gap-3">
      <UiButton v-if="cancelLabel" variant="secondary" appearance="soft" :data-testid="`${testid}-cancel`" @click="cancel">{{ cancelLabel }}</UiButton>
      <UiButton type="submit" :variant="cancelLabel === 'Batalkan Perubahan' ? 'success' : 'primary'" :data-testid="`${testid}-save`">{{ submitLabel }}</UiButton>
    </div>
  </form>
</template>
