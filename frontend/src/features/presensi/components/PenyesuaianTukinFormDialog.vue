<script setup lang="ts">
/**
 * Dialog tambah/ubah Penyesuaian Tukin per NIP (5B MAKE-045, PRD 5B §7.3.10; PRD 5C §7.4; role 1). Pola dialog 5A
 * (`PresensiManualDialog`). Validasi yang mengikat (NIP ada, bulan ≥ 2024-04, rentang, persen, alasan, tumpang tindih)
 * di server; galat 422 ditampilkan per field, 409 `konflik_data` sebagai pesan umum. NIP hanya terkunci saat ubah.
 * Status tidak ada di form (AGENTS §1: diubah lewat ⋮). Figma & layar legacy untuk fitur ini tidak ada (data hard-code).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import { UiButton, UiNotice, UiTextField } from '@/shared/ui'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'

import { labelBulan } from '../formatLaporan'
import { penyesuaianTukinService, type IsianPenyesuaian, type PenyesuaianTukin } from '../services/penyesuaianTukin.service'

const props = defineProps<{
  open: boolean
  /** null = tambah; baris = ubah. */
  baris: PenyesuaianTukin | null
}>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [pesan: string] }>()

const isEdit = computed(() => props.baris !== null)
const nip = ref('')
const bulanMulai = ref('')
const bulanAkhir = ref('')
const persen = ref('')
const alasan = ref('')
const errors = ref<Record<string, string>>({})
const formError = ref('')
const menyimpan = ref(false)

watch(
  () => [props.open, props.baris] as const,
  ([open, b]) => {
    if (!open) return
    nip.value = b?.nip ?? ''
    bulanMulai.value = b?.bulan_mulai ?? ''
    bulanAkhir.value = b?.bulan_akhir ?? ''
    persen.value = b?.persen ?? ''
    alasan.value = b?.alasan ?? ''
    errors.value = {}
    formError.value = ''
  },
  { immediate: true },
)

/** Koma desimal Indonesia diterima di isian; server menerima titik. */
const persenServer = (nilai: string): string => nilai.trim().replace(',', '.')

function isian(): IsianPenyesuaian {
  return {
    nip: nip.value.trim(),
    bulan_mulai: bulanMulai.value,
    bulan_akhir: bulanAkhir.value,
    persen: persenServer(persen.value),
    alasan: alasan.value.trim(),
  }
}

async function simpan(event: Event): Promise<void> {
  event.preventDefault()
  if (menyimpan.value) return
  menyimpan.value = true
  errors.value = {}
  formError.value = ''
  try {
    if (props.baris) {
      await penyesuaianTukinService.ubah(props.baris.id, isian())
      emit('saved', `Penyesuaian Tukin ${props.baris.nama || props.baris.nip} (${labelBulan(bulanMulai.value)} s.d. ${labelBulan(bulanAkhir.value)}) diperbarui.`)
    } else {
      const baru = await penyesuaianTukinService.tambah(isian())
      emit('saved', `Penyesuaian Tukin ${baru.nama || baru.nip} (${labelBulan(baru.bulan_mulai)} s.d. ${labelBulan(baru.bulan_akhir)}) ditambahkan.`)
    }
    emit('update:open', false)
  } catch (err) {
    if (isApiError(err) && err.status === 422 && err.errors) {
      errors.value = Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, String(v[0] ?? '')]))
      formError.value = 'Periksa kembali isian yang ditandai.'
    } else {
      formError.value = isApiError(err) && (err.status === 403 || err.status === 404 || err.status === 409) && err.message ? err.message : describeApiError(err).message
    }
  } finally {
    menyimpan.value = false
  }
}
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => !menyimpan && emit('update:open', v)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="scrollbar-slim fixed left-1/2 top-1/2 z-50 max-h-[calc(100vh-2rem)] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="penyesuaian-tukin-dialog"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ isEdit ? 'Edit Penyesuaian Tukin' : 'Tambah Penyesuaian Tukin' }}</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">
              Persen negatif = pengurangan (mis. -20). Rentang bulan dibatasi mulai 2024-04. Tidak boleh tumpang tindih dengan penyesuaian aktif NIP yang sama. Status diubah lewat menu ⋮.
            </DialogDescription>
          </div>
          <DialogClose :disabled="menyimpan" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 disabled:opacity-50" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <form class="space-y-3" novalidate data-testid="penyesuaian-tukin-form" @submit="simpan">
          <UiNotice v-if="formError" tone="danger" data-testid="penyesuaian-tukin-error">{{ formError }}</UiNotice>
          <UiTextField
            v-model="nip"
            name="nip"
            label="NIP"
            inputmode="numeric"
            required
            :disabled="isEdit || menyimpan"
            :state="errors.nip ? 'error' : 'default'"
            :help-text="errors.nip ?? (isEdit ? 'NIP tidak dapat diubah.' : '')"
            data-testid="penyesuaian-tukin-nip"
          />
          <div class="grid grid-cols-2 gap-3">
            <UiTextField
              v-model="bulanMulai"
              name="bulan_mulai"
              type="month"
              label="Bulan Mulai"
              required
              :disabled="menyimpan"
              :state="errors.bulan_mulai ? 'error' : 'default'"
              :help-text="errors.bulan_mulai ?? ''"
              data-testid="penyesuaian-tukin-bulan-mulai"
            />
            <UiTextField
              v-model="bulanAkhir"
              name="bulan_akhir"
              type="month"
              label="Bulan Akhir"
              required
              :disabled="menyimpan"
              :state="errors.bulan_akhir ? 'error' : 'default'"
              :help-text="errors.bulan_akhir ?? ''"
              data-testid="penyesuaian-tukin-bulan-akhir"
            />
          </div>
          <UiTextField
            v-model="persen"
            name="persen"
            inputmode="decimal"
            label="Persen Penyesuaian (%)"
            placeholder="-20"
            required
            :disabled="menyimpan"
            :state="errors.persen ? 'error' : 'default'"
            :help-text="errors.persen ?? 'Maks. dua angka di belakang koma; antara -100 dan 100, tidak boleh 0.'"
            data-testid="penyesuaian-tukin-persen"
          />
          <UiTextField
            v-model="alasan"
            name="alasan"
            label="Alasan"
            required
            :disabled="menyimpan"
            :state="errors.alasan ? 'error' : 'default'"
            :help-text="errors.alasan ?? 'Maks. 255 karakter, mis. Predikat Kinerja Sangat Kurang.'"
            data-testid="penyesuaian-tukin-alasan"
          />

          <div class="flex flex-wrap justify-end gap-2 pt-2">
            <UiButton variant="secondary" appearance="outline" :disabled="menyimpan" @click="emit('update:open', false)">Batal</UiButton>
            <UiButton type="submit" :loading="menyimpan" data-testid="penyesuaian-tukin-simpan">Simpan</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
