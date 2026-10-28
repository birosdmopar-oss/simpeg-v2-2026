<script setup lang="ts">
/**
 * Proses PIC Tugas/Izin Belajar (`POST {jenis}/{id}/process`). Dialog sendiri, BUKAN `ApprovalDialog`, karena ada isian SK:
 * Setujui → isian SK (server menegakkan wajib dan efek final); Tolak → `reason_note` (server menegakkan wajib dan ≤ 255
 * byte). Hitung byte di FE hanya bantuan. Galat 422 per kolom ditampilkan di field masing-masing.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { describeApiError, splitValidationErrors } from '@/features/kepegawaian/services/apiErrors'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import { UiButton, UiFileField, UiTextField } from '@/shared/ui'

import type { StudiConfig } from '../studiConfig'
import { kolomSkStudi, type BerkasForm, type StudiItem, type StudiService } from '../services/studi.service'

export type AksiProsesStudi = 'setujui' | 'tolak'

const props = defineProps<{ open: boolean; row: StudiItem | null; aksi: AksiProsesStudi; cfg: StudiConfig; service: StudiService }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const MAKS_ALASAN_BYTE = 255
const berkas = reactive<BerkasForm>({})
const kunciBerkas = ref(0)
const errors = ref<Record<string, string>>({})
const umum = ref('')
const busy = ref(false)
const isian = reactive({ no_sk: '', tgl_sk: '', ds: '', no_ds: '', tgl_ds: '', perihal_ds: '', reason_note: '' })

const sk = computed(() => kolomSkStudi(props.cfg.jenis))
const judulAksi = computed(() => (props.aksi === 'setujui' ? 'Setujui' : 'Tolak'))
const bytes = computed(() => new TextEncoder().encode(isian.reason_note).length)
const KOLOM_KNOWN = computed(() => ['aksi', 'reason_note', 'no_sk', 'tgl_sk', ...Object.values(sk.value), ...(props.cfg.berkasProses ? [props.cfg.berkasProses] : [])])

watch(
  () => props.open,
  (open) => {
    if (!open) return
    errors.value = {}
    umum.value = ''
    for (const k of Object.keys(berkas)) delete berkas[k]
    kunciBerkas.value++
    Object.assign(isian, { no_sk: '', tgl_sk: '', ds: '', no_ds: '', tgl_ds: '', perihal_ds: '', reason_note: '' })
  },
  { immediate: true },
)

function payload(): Record<string, string> {
  if (props.aksi === 'tolak') return { aksi: 'tolak', reason_note: isian.reason_note }
  return {
    aksi: 'setujui',
    [sk.value.no_sk]: isian.no_sk,
    [sk.value.tgl_sk]: isian.tgl_sk,
    [sk.value.ds]: isian.ds,
    [sk.value.no_ds]: isian.no_ds,
    [sk.value.tgl_ds]: isian.tgl_ds,
    [sk.value.perihal_ds]: isian.perihal_ds,
  }
}

async function simpan(): Promise<void> {
  if (!props.row) return
  busy.value = true
  errors.value = {}
  umum.value = ''
  try {
    await props.service.proses(props.row.id, payload(), berkas)
    emit('saved')
    emit('update:open', false)
  } catch (e) {
    const v = splitValidationErrors(e, KOLOM_KNOWN.value)
    errors.value = v.fields
    umum.value = v.general.length ? v.general.join(' ') : Object.keys(v.fields).length ? '' : describeApiError(e).message
  } finally {
    busy.value = false
  }
}

function tutup(): void {
  if (!busy.value) emit('update:open', false)
}

const state = (k: string): 'default' | 'error' => (errors.value[k] ? 'error' : 'default')
const pesan = (k: string): string | undefined => errors.value[k] || undefined
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => (v ? emit('update:open', true) : tutup())">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        :data-testid="`${cfg.testid}-proses-dialog`"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ judulAksi }} {{ cfg.judul }}</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">
              {{ aksi === 'setujui' ? 'Isi data SK. Persetujuan bersifat final.' : 'Tulis alasan penolakan untuk pegawai.' }}
            </DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <form :class="FORM_GRID_CLASS" novalidate :data-testid="`${cfg.testid}-proses-form`" @submit.prevent="simpan">
          <p v-if="umum" role="alert" :class="FORM_ALERT_CLASS" :data-testid="`${cfg.testid}-proses-error`">{{ umum }}</p>

          <template v-if="aksi === 'setujui'">
            <UiTextField v-model="isian.no_sk" label="No. SK" required :state="state(sk.no_sk)" :help-text="pesan(sk.no_sk)" />
            <UiTextField v-model="isian.tgl_sk" type="date" label="Tanggal SK" required :state="state(sk.tgl_sk)" :help-text="pesan(sk.tgl_sk)" />
            <UiTextField v-model="isian.ds" label="Dasar SK" required :state="state(sk.ds)" :help-text="pesan(sk.ds)" />
            <UiTextField v-model="isian.no_ds" label="No. Dasar" required :state="state(sk.no_ds)" :help-text="pesan(sk.no_ds)" />
            <UiTextField v-model="isian.tgl_ds" type="date" label="Tanggal Dasar" :state="state(sk.tgl_ds)" :help-text="pesan(sk.tgl_ds)" />
            <UiTextField v-model="isian.perihal_ds" label="Perihal Dasar" :state="state(sk.perihal_ds)" :help-text="pesan(sk.perihal_ds)" />
          </template>

          <template v-else>
            <UiTextField
              v-model="isian.reason_note"
              label="Alasan Penolakan"
              required
              :state="state('reason_note')"
              :help-text="pesan('reason_note') ?? `${bytes}/${MAKS_ALASAN_BYTE} byte`"
              :data-testid="`${cfg.testid}-alasan`"
            />
          </template>

          <div v-if="cfg.berkasProses && aksi === 'setujui'" :class="FORM_WIDE_CLASS">
            <UiFileField
              :key="kunciBerkas"
              label="Surat (opsional, PDF)"
              :allowed="['pdf']"
              :max-mb="5"
              @change="(f: File | null) => (berkas[cfg.berkasProses as string] = f ?? undefined)"
            />
            <p v-if="pesan(cfg.berkasProses)" role="alert" class="mt-1 text-caption text-danger">{{ pesan(cfg.berkasProses) }}</p>
          </div>

          <div :class="FORM_ACTIONS_CLASS">
            <UiButton variant="secondary" appearance="outline" :disabled="busy" @click="tutup">Batal</UiButton>
            <UiButton type="submit" :disabled="busy" :data-testid="`${cfg.testid}-proses-simpan`">{{ busy ? 'Memproses…' : judulAksi }}</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
