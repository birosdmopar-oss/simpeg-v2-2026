<script setup lang="ts">
/**
 * Form tambah/ubah QnA (PRD Fase 8 v1.1 §7.10.4, F8-41/F8-49; `dev:V/form_qna.php`): kategori wajib (status 1), tag ganda
 * (tag aktif), "Opsi Alternatif" Ya/Tidak (petunjuk "Maks. 3 QnA unggulan" tidak ditegakkan, A15), status 1/2 saat ubah,
 * pertanyaan wajib, jawaban HTML wajib + pratinjau `SafeHtml` (field `html` `FormField`, pola FAQ). Ubah mengganti seluruh set
 * tag. Status induk (F8-49): kategori Tidak Aktif boleh dipertahankan; kategori Dihapus harus diganti (BE 422); tag Tidak Aktif
 * terpasang tetap terpilih sampai dilepas; tag Dihapus tidak tampil dan tidak dikirim.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import { UiButton, UiNotice, UiRadioGroup } from '@/shared/ui'

import { galatPerKolom } from '../../galat'
import { qnaChatbotService } from '../../services/chatbot.service'
import type { OpsiQna, QnaDetail, QnaRow, RujukanBerstatus, StatusUbah } from '../../types'
import TagPilihan, { type OpsiTag } from './TagPilihan.vue'

const props = withDefaults(defineProps<{ open: boolean; row: QnaRow | null; opsi: OpsiQna; opsiSiap?: boolean; opsiGalat?: string }>(), {
  opsiSiap: true,
  opsiGalat: '',
})
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: []; 'muat-ulang-opsi': [] }>()

const YA_TIDAK = [
  { value: '0', label: 'Tidak' },
  { value: '1', label: 'Ya' },
]
const STATUS = [
  { value: '1', label: 'Aktif' },
  { value: '2', label: 'Tidak Aktif' },
]

const isEdit = computed(() => props.row !== null)
const form = reactive({ id_kategori: '', tag: [] as number[], fallback: '0', status: '1', pertanyaan: '', jawaban: '' })
const kategoriAwal = ref<RujukanBerstatus | null>(null)
const tagAwal = ref<RujukanBerstatus[]>([])
const memuatDetail = ref(false)
const submitting = ref(false)
const submitted = ref(false)
const formError = ref('')
const serverErrors = ref<Record<string, string>>({})

function isiDari(d: QnaDetail | null): void {
  kategoriAwal.value = d?.kategori ?? null
  tagAwal.value = d?.tag ?? []
  form.id_kategori = d?.kategori ? String(d.kategori.id) : ''
  form.tag = (d?.tag ?? []).filter((t) => t.status !== 10).map((t) => t.id)
  form.fallback = d?.is_fallback_option ? '1' : '0'
  form.status = d?.status === 2 ? '2' : '1'
  form.pertanyaan = d?.pertanyaan ?? ''
  form.jawaban = d?.jawaban ?? ''
}

watch(
  () => [props.open, props.row] as const,
  async ([open, row]) => {
    if (!open) return
    submitted.value = false
    formError.value = ''
    serverErrors.value = {}
    isiDari(null)
    if (row === null) return
    memuatDetail.value = true
    try {
      const d = await qnaChatbotService.detail(row.id)
      if (props.open && props.row?.id === row.id) isiDari(d)
    } catch (e) {
      formError.value = isApiError(e) ? e.message : 'Gagal memuat QnA.'
    } finally {
      memuatDetail.value = false
    }
  },
  { immediate: true },
)

const LABEL_STATUS = { 2: 'Tidak Aktif', 10: 'Dihapus' } as const

/** Opsi kategori: kategori aktif + kategori lama QnA ini bila Tidak Aktif/Dihapus (berpenanda, F8-49c/d). */
const opsiKategori = computed(() => {
  const opsi = props.opsi.kategori.map((k) => ({ value: String(k.id), label: k.judul }))
  const awal = kategoriAwal.value
  if (awal && awal.status !== 1 && !opsi.some((o) => o.value === String(awal.id))) {
    opsi.unshift({ value: String(awal.id), label: `${awal.judul} (${LABEL_STATUS[awal.status]})` })
  }
  return opsi
})

const kategoriDihapus = computed(() => kategoriAwal.value?.status === 10 && form.id_kategori === String(kategoriAwal.value.id))
const kategoriTidakAktif = computed(() => kategoriAwal.value?.status === 2 && form.id_kategori === String(kategoriAwal.value.id))

/** Opsi tag: tag aktif + tag Tidak Aktif yang sudah terpasang (berbadge); tag Dihapus tidak tampil (F8-49a/b). */
const opsiTag = computed<OpsiTag[]>(() => {
  const opsi: OpsiTag[] = props.opsi.tag.map((t) => ({ id: t.id, judul: t.judul, aktif: true }))
  for (const t of tagAwal.value) {
    // Tag lama yang tidak ada di opsi (Tidak Aktif, atau opsi belum/gagal termuat) tetap tampil dan terpilih (M-2).
    if (t.status !== 10 && !opsi.some((o) => o.id === t.id)) opsi.push({ id: t.id, judul: t.judul, aktif: t.status === 1 })
  }
  return opsi
})

const errors = computed(() => {
  const e: Record<string, string> = {}
  if (form.id_kategori === '') e.id_kategori = 'Kategori wajib dipilih.'
  if (form.pertanyaan.trim() === '') e.pertanyaan = 'Pertanyaan wajib diisi.'
  if (form.jawaban.trim() === '') e.jawaban = 'Jawaban wajib diisi.'
  return e
})

const PETA_GALAT: Record<string, string> = { id_qna_kategori_fk: 'id_kategori', kategori: 'id_kategori', is_fallback_option: 'fallback' }

function err(f: string): string | undefined {
  return serverErrors.value[f] ?? (submitted.value ? errors.value[f] : undefined)
}

async function onSubmit(): Promise<void> {
  submitted.value = true
  formError.value = ''
  serverErrors.value = {}
  if (Object.keys(errors.value).length > 0 || submitting.value || memuatDetail.value || !props.opsiSiap) return
  submitting.value = true
  try {
    await qnaChatbotService.simpan(props.row?.id ?? null, {
      id_kategori: Number(form.id_kategori),
      // Dikirim apa adanya: tag Dihapus sudah dibuang di isiDari, tag tambahan yang tidak aktif ditolak BE (M-2).
      tag: [...form.tag],
      is_fallback_option: form.fallback === '1',
      status: Number(form.status) as StatusUbah,
      pertanyaan: form.pertanyaan,
      jawaban: form.jawaban,
    })
    emit('saved')
    emit('update:open', false)
  } catch (e) {
    serverErrors.value = galatPerKolom(e, (f) => PETA_GALAT[f] ?? (f.startsWith('tag') ? 'tag' : f))
    formError.value = isApiError(e) ? e.message : 'Terjadi kesalahan. Silakan coba lagi.'
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
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
      >
        <div class="mb-4 flex items-start justify-between">
          <div>
            <DialogTitle class="text-h5 font-semibold text-slate-900">{{ isEdit ? 'Ubah QnA' : 'Tambah QnA' }}</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">Pasangan pertanyaan dan jawaban yang dipakai Vanya.</DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <p v-if="memuatDetail" class="py-6 text-center text-sm text-slate-500" data-testid="chatbot-qna-memuat">Memuat...</p>
        <form v-else :class="FORM_GRID_CLASS" novalidate data-testid="chatbot-qna-form" @submit.prevent="onSubmit">
          <p v-if="formError" :class="FORM_ALERT_CLASS" role="alert">{{ formError }}</p>
          <div v-if="!opsiSiap" :class="[FORM_WIDE_CLASS, FORM_ALERT_CLASS, 'flex flex-wrap items-center justify-between gap-2']" role="alert" data-testid="chatbot-qna-opsi-galat">
            <span>{{ opsiGalat || 'Pilihan kategori dan tag belum termuat.' }} Simpan ditahan sampai pilihan termuat.</span>
            <UiButton size="sm" variant="secondary" appearance="outline" data-testid="chatbot-qna-opsi-muat-ulang" @click="emit('muat-ulang-opsi')">Muat ulang</UiButton>
          </div>
          <FormField
            v-model="form.id_kategori"
            name="id_kategori"
            label="Kategori"
            type="select"
            required
            placeholder="Pilih kategori"
            :options="opsiKategori"
            :error="err('id_kategori')"
          />
          <div data-testid="chatbot-qna-fallback">
            <UiRadioGroup v-model="form.fallback" name="is_fallback_option" label="Opsi Alternatif" :options="YA_TIDAK" />
            <p class="mt-1 text-caption text-slate-500">Ditampilkan di tombol "Pertanyaan Populer" saat bot tidak paham. Maks. 3 QnA unggulan.</p>
          </div>
          <div v-if="kategoriDihapus || kategoriTidakAktif" :class="FORM_WIDE_CLASS">
            <UiNotice :tone="kategoriDihapus ? 'warning' : 'info'" data-testid="chatbot-qna-kategori-induk">
              {{ kategoriDihapus ? 'Kategori QnA sudah dihapus. Pilih kategori lain.' : 'Kategori QnA ini Tidak Aktif; boleh dipertahankan.' }}
            </UiNotice>
          </div>
          <div :class="FORM_WIDE_CLASS">
            <TagPilihan v-model="form.tag" :opsi="opsiTag" :disabled="submitting" :error="err('tag')" />
          </div>
          <div v-if="isEdit" :class="FORM_WIDE_CLASS" data-testid="chatbot-qna-status">
            <UiRadioGroup v-model="form.status" name="status" label="Status" :options="STATUS" />
          </div>
          <FormField v-model="form.pertanyaan" name="pertanyaan" label="Pertanyaan" type="textarea" required :class="FORM_WIDE_CLASS" :error="err('pertanyaan')" />
          <FormField
            v-model="form.jawaban"
            name="jawaban"
            label="Jawaban"
            type="html"
            required
            placeholder="<p>Tulis jawaban dalam format HTML...</p>"
            hint="Tag/atribut di luar daftar yang diizinkan dibuang saat disimpan."
            :class="FORM_WIDE_CLASS"
            :error="err('jawaban')"
          />
          <div :class="FORM_ACTIONS_CLASS">
            <DialogClose as-child>
              <UiButton variant="secondary" appearance="soft">Batal</UiButton>
            </DialogClose>
            <UiButton type="submit" :disabled="submitting || !opsiSiap">{{ submitting ? 'Menyimpan...' : 'Simpan' }}</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
