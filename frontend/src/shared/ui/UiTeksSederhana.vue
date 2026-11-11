<script setup lang="ts">
/**
 * Editor teks sederhana (Q7-10, PRD Fase 7 v2.0 §5.5): tebal, miring, daftar, tautan — tanpa pustaka rich-text.
 * `v-model` = HTML terbatas (`p, br, strong, em, ul, ol, li, a`); server tetap membersihkan ulang. Penulisan di textarea
 * memakai format ringan (**tebal**, *miring*, "- butir", [teks](https://…)), tombol toolbar membungkus pilihan.
 * Aksesibel: toolbar `role="toolbar"` dengan label, tombol ber-aria-label, textarea ber-label; pratinjau hasil bersih.
 */
import { List, ListOrdered, Link2, Bold, Italic } from 'lucide-vue-next'
import { computed, nextTick, ref, useId, watch } from 'vue'

import { bersihkanHtml, htmlKeMarkup, markupKeHtml } from './teksSederhana'

const props = withDefaults(
  defineProps<{
    modelValue?: string
    label?: string
    disabled?: boolean
    invalid?: boolean
    describedBy?: string
    rows?: number
    placeholder?: string
  }>(),
  { modelValue: '', label: 'Isi', disabled: false, invalid: false, describedBy: undefined, rows: 8, placeholder: '' },
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const id = useId()
const area = ref<HTMLTextAreaElement | null>(null)
const teks = ref(htmlKeMarkup(props.modelValue))

const pratinjau = computed(() => bersihkanHtml(markupKeHtml(teks.value)))

watch(
  () => props.modelValue,
  (v) => {
    if (v !== markupKeHtml(teks.value)) teks.value = htmlKeMarkup(v)
  },
)

function perbarui(nilai: string): void {
  teks.value = nilai
  emit('update:modelValue', markupKeHtml(nilai))
}

function onInput(e: Event): void {
  perbarui((e.target as HTMLTextAreaElement).value)
}

async function ganti(awal: number, akhir: number, sisip: string, kursorAwal: number, kursorAkhir: number): Promise<void> {
  const a = teks.value
  perbarui(a.slice(0, awal) + sisip + a.slice(akhir))
  await nextTick()
  area.value?.focus()
  area.value?.setSelectionRange(awal + kursorAwal, awal + kursorAkhir)
}

function bungkus(awalan: string, akhiran: string, contoh: string): void {
  const el = area.value
  if (!el || props.disabled) return
  const s = el.selectionStart
  const e = el.selectionEnd
  const dipilih = teks.value.slice(s, e) || contoh
  void ganti(s, e, `${awalan}${dipilih}${akhiran}`, awalan.length, awalan.length + dipilih.length)
}

function daftar(bernomor: boolean): void {
  const el = area.value
  if (!el || props.disabled) return
  const awalBaris = teks.value.lastIndexOf('\n', el.selectionStart - 1) + 1
  const akhirIdx = teks.value.indexOf('\n', el.selectionEnd)
  const akhirBaris = akhirIdx === -1 ? teks.value.length : akhirIdx
  const bagian = teks.value.slice(awalBaris, akhirBaris) || 'butir'
  const hasil = bagian
    .split('\n')
    .map((l, i) => `${bernomor ? `${i + 1}. ` : '- '}${l.replace(/^(- |\d+\. )/, '')}`)
    .join('\n')
  void ganti(awalBaris, akhirBaris, hasil, 0, hasil.length)
}

function tautan(): void {
  const el = area.value
  if (!el || props.disabled) return
  const s = el.selectionStart
  const e = el.selectionEnd
  const dipilih = teks.value.slice(s, e) || 'teks'
  const hasil = `[${dipilih}](https://)`
  void ganti(s, e, hasil, dipilih.length + 3, hasil.length - 1)
}

const aksi = [
  { kode: 'tebal', label: 'Tebal', ikon: Bold, jalan: () => bungkus('**', '**', 'teks tebal') },
  { kode: 'miring', label: 'Miring', ikon: Italic, jalan: () => bungkus('*', '*', 'teks miring') },
  { kode: 'daftar', label: 'Daftar', ikon: List, jalan: () => daftar(false) },
  { kode: 'daftar-nomor', label: 'Daftar bernomor', ikon: ListOrdered, jalan: () => daftar(true) },
  { kode: 'tautan', label: 'Tautan', ikon: Link2, jalan: tautan },
]
</script>

<template>
  <div class="flex flex-col gap-2" data-testid="teks-sederhana">
    <label :for="id" class="text-body1 font-medium text-slate-700">{{ label }}</label>
    <div role="toolbar" :aria-label="`Format ${label}`" class="flex flex-wrap gap-1">
      <button
        v-for="a in aksi"
        :key="a.kode"
        type="button"
        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-brand-tertiary/50 disabled:cursor-not-allowed disabled:opacity-50"
        :aria-label="a.label"
        :data-aksi="a.kode"
        :disabled="disabled"
        @click="a.jalan"
      >
        <component :is="a.ikon" class="h-4 w-4" aria-hidden="true" />
      </button>
    </div>
    <textarea
      :id="id"
      ref="area"
      class="w-full rounded-xl border bg-white p-3 text-body1 text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary/50 disabled:bg-slate-100"
      :class="invalid ? 'border-danger' : 'border-slate-300'"
      :rows="rows"
      :value="teks"
      :disabled="disabled"
      :placeholder="placeholder"
      :aria-invalid="invalid || undefined"
      :aria-describedby="describedBy"
      @input="onInput"
    />
    <div v-if="pratinjau" class="rounded-xl bg-slate-50 p-3 text-body1 text-slate-800" data-testid="teks-sederhana-pratinjau">
      <span class="mb-1 block text-body2 font-medium text-slate-500">Pratinjau</span>
      <!-- eslint-disable-next-line vue/no-v-html -- HTML sudah dibersihkan ke whitelist (bersihkanHtml) -->
      <div class="teks-sederhana" v-html="pratinjau" />
    </div>
  </div>
</template>

<style scoped>
.teks-sederhana :deep(ul) {
  list-style: disc;
  padding-left: 1.25rem;
}
.teks-sederhana :deep(ol) {
  list-style: decimal;
  padding-left: 1.25rem;
}
.teks-sederhana :deep(p + p),
.teks-sederhana :deep(p + ul),
.teks-sederhana :deep(p + ol),
.teks-sederhana :deep(ul + p),
.teks-sederhana :deep(ol + p) {
  margin-top: 0.5rem;
}
.teks-sederhana :deep(a) {
  text-decoration: underline;
}
</style>
