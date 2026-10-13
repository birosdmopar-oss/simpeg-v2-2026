<script setup lang="ts">
/**
 * Dialog ajukan/ubah usulan mandiri (Karpeg/Karis, WS-1 M4 MAKE-007): isian pilihan Definisi (mis. jenis permohonan)
 * + satu isian berkas per kolom berkas (`berkas[<kolom>]`). Berkas wajib harus dipilih saat AJUKAN, dan saat UBAH bila
 * kolomnya masih kosong (berkas yang dipilih saat ubah MENGGANTI berkas lama). Galat server 422 (`errors.<kolom DDL>`,
 * `errors["berkas.<kolom>"]`, `errors.keluarga`) ditampilkan di isiannya / sebagai pesan umum.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { UiButton, UiFileField, UiSelect } from '@/shared/ui'

import type { RiwayatRow } from '../types'

import { fieldVisible } from './riwayat.config'
import type { BerkasMap } from './riwayat.service'
import type { BerkasKolomDef, UsulanConfig } from './usulan.config'

const props = withDefaults(
  defineProps<{
    open: boolean
    config: UsulanConfig
    /** Baris yang diubah; null = ajukan baru. */
    row: RiwayatRow | null
    busy?: boolean
    /** Galat 422 server: kunci kolom DDL / `berkas.<kolom>`; `umum` = pesan lain. */
    serverErrors?: { fields: Record<string, string>; umum: string[] } | null
  }>(),
  { busy: false, serverErrors: null },
)
const emit = defineEmits<{ 'update:open': [value: boolean]; submit: [values: Record<string, string>, berkas: BerkasMap] }>()

const values = reactive<Record<string, string>>({})
const berkas = reactive<BerkasMap>({})
const errors = ref<Record<string, string>>({})

watch(
  () => [props.open, props.row] as const,
  ([open]) => {
    if (!open) return
    for (const f of props.config.fields) values[f.name] = props.row?.[f.name] == null ? '' : String(props.row[f.name])
    for (const key of Object.keys(berkas)) delete berkas[key]
    errors.value = {}
  },
  { immediate: true },
)

const visibleBerkas = computed(() => props.config.berkas)

function wajib(def: BerkasKolomDef): boolean {
  if (!def.wajib) return false
  if (def.wajibBila && !fieldVisible({ name: '', label: '', type: 'text', showWhen: def.wajibBila }, values)) return false
  return props.row === null || String(props.row[def.kolom] ?? '').trim() === ''
}

function onFile(def: BerkasKolomDef, file: File | null): void {
  const next = { ...errors.value }
  delete next[`berkas.${def.kolom}`]
  errors.value = next
  if (file) berkas[def.kolom] = file
  else delete berkas[def.kolom]
}

function submit(): void {
  const next: Record<string, string> = {}
  for (const f of props.config.fields) if (f.required && (values[f.name] ?? '').trim() === '') next[f.name] = `${f.label} wajib diisi`
  for (const def of props.config.berkas) if (wajib(def) && !berkas[def.kolom]) next[`berkas.${def.kolom}`] = `${def.label} wajib diunggah.`
  errors.value = next
  if (Object.keys(next).length > 0) return
  // Berkas bersyarat yang syaratnya tidak terpenuhi (mis. surat hilang untuk permohonan pertama) tidak dikirim.
  const kirim: BerkasMap = {}
  for (const def of props.config.berkas) {
    const file = berkas[def.kolom]
    if (file && (!def.wajibBila || fieldVisible({ name: '', label: '', type: 'text', showWhen: def.wajibBila }, values))) kirim[def.kolom] = file
  }
  emit('submit', { ...values }, kirim)
}

const error = (key: string): string => errors.value[key] ?? props.serverErrors?.fields[key] ?? ''
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="usulan-dialog"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ row ? 'Ubah' : 'Ajukan' }} {{ config.singular }}</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">
              Berkas {{ config.ekstensi.map((e) => e.toUpperCase()).join(', ') }}, maksimal {{ config.batas_mb }} MB. Kolom bertanda (*) wajib.
            </DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <form novalidate data-testid="usulan-form" @submit.prevent="submit">
          <div v-if="row?.reason_note" class="mb-4 rounded-lg bg-danger-soft px-3 py-2 text-body2 text-danger" data-testid="usulan-alasan">
            Alasan penolakan: {{ row.reason_note }}
          </div>

          <div v-for="f in config.fields" :key="f.name" class="mb-5">
            <UiSelect
              v-model="values[f.name]"
              :label="f.label"
              :required="f.required"
              :disabled="busy"
              :options="f.options ?? []"
              :placeholder="`Pilih ${f.label.toLowerCase()}...`"
              :state="error(f.name) ? 'error' : 'default'"
              :help-text="error(f.name)"
              :data-field="f.name"
            />
          </div>

          <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
            <div v-for="def in visibleBerkas" :key="def.kolom" :data-testid="`usulan-berkas-${def.kolom}`">
              <UiFileField
                :label="wajib(def) ? `${def.label} *` : def.label"
                :allowed="config.ekstensi"
                :max-mb="config.batas_mb"
                :disabled="busy"
                @change="(file) => onFile(def, file)"
              />
              <p v-if="row && row[def.kolom]" class="mt-1 text-caption text-slate-500">Sudah ada; pilih berkas untuk mengganti.</p>
              <p v-if="error(`berkas.${def.kolom}`)" role="alert" class="mt-1 text-caption text-danger" :data-testid="`usulan-berkas-error-${def.kolom}`">
                {{ error(`berkas.${def.kolom}`) }}
              </p>
            </div>
          </div>

          <ul v-if="serverErrors?.umum.length" role="alert" class="mt-5 space-y-1 rounded-lg bg-danger-soft px-3 py-2 text-body2 text-danger" data-testid="usulan-general-error">
            <li v-for="m in serverErrors.umum" :key="m">{{ m }}</li>
          </ul>

          <div class="mt-6 flex justify-end gap-3">
            <UiButton variant="secondary" appearance="soft" :disabled="busy" @click="emit('update:open', false)">Batal</UiButton>
            <UiButton type="submit" :loading="busy" data-testid="usulan-save">{{ row ? 'Simpan Perubahan' : 'Ajukan' }}</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
