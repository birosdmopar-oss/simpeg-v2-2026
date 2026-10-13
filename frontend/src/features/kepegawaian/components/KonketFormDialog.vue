<script setup lang="ts">
/**
 * Form Konfirmasi Ketidakhadiran (B-13, MAKE-013) — salinan isian legacy `views/hr/employee/rwy/konket/form.php`
 * (pegawai) dan `form_ad.php` (role 1/3: pilih pegawai, Pengaruh ke Tukin). Tanggal dibatasi [tanggal minimal, hari ini]
 * (aturan server tetap mengikat, 422 per kolom); Dinas Luar (kategori 8) gabungan meminta peserta (NIP per baris —
 * TODO kartu lanjutan: combobox pencarian pegawai). Berkas bukti wajib saat tambah (5 MB, jpg/jpeg/png/gif/pdf/
 * xls/xlsx/doc/docx).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import { UiButton, UiFileField, UiNotice, UiRadioGroup, UiSelect, UiTextField } from '@/shared/ui'

import { describeApiError } from '../services/apiErrors'
import { konketService, type KonketIsian } from '../services/konket.service'
import type { KonketItem, KonketOpsi } from '../types'

const props = defineProps<{ open: boolean; opsi: KonketOpsi; row: KonketItem | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [row: KonketItem] }>()

const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'xls', 'xlsx', 'doc', 'docx']

const isian = reactive({ nip: '', date_start: '', date_end: '', kategori: '', jenis_dinas: '0', affect_tukin: '1', alasan: '', peserta: '' })
const berkas = ref<File | null>(null)
const errors = ref<Record<string, string>>({})
const umum = ref('')
const busy = ref(false)

const admin = computed(() => props.opsi.mode === 'admin')
const dinas = computed(() => Number(isian.kategori) === props.opsi.kategori_dinas)
const gabungan = computed(() => dinas.value && isian.jenis_dinas === '1')
const jenisOpsi = computed(() => props.opsi.jenis.map((j) => ({ value: String(j.kategori), label: j.jenis_konket })))

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    errors.value = {}
    umum.value = ''
    berkas.value = null
    const r = props.row
    Object.assign(isian, {
      nip: r?.nip ?? '',
      date_start: r?.date_start ?? '',
      date_end: r?.date_end ?? '',
      kategori: r ? String(r.kategori) : '',
      jenis_dinas: r?.jenis_dinas === 1 ? '1' : '0',
      affect_tukin: String(r?.affect_tukin ?? 1),
      alasan: r?.alasan ?? '',
      peserta: '',
    })
    if (r && r.kategori === props.opsi.kategori_dinas && r.jenis_dinas === 1) {
      try {
        const detail = await konketService.detail(r.id)
        isian.peserta = (detail.peserta ?? []).filter((p) => !p.pemohon).map((p) => p.nip).join('\n')
      } catch {
        // Peserta tidak termuat → diisi ulang pengguna.
      }
    }
  },
  { immediate: true },
)

// Admin: nilai awal Pengaruh ke Tukin dari master jenis (K5a) saat jenis dipilih pada pengajuan baru.
watch(
  () => isian.kategori,
  (kategori) => {
    if (!admin.value || props.row) return
    const j = props.opsi.jenis.find((x) => String(x.kategori) === kategori)
    if (j) isian.affect_tukin = String(j.affect_tukin)
  },
)

async function simpan(): Promise<void> {
  busy.value = true
  errors.value = {}
  umum.value = ''
  const data: KonketIsian = {
    date_start: isian.date_start,
    date_end: isian.date_end,
    kategori: isian.kategori,
    alasan: isian.alasan,
  }
  if (admin.value && !props.row) data.nip = isian.nip.trim()
  if (admin.value) data.affect_tukin = isian.affect_tukin
  if (dinas.value) data.jenis_dinas = isian.jenis_dinas
  if (gabungan.value) data.peserta = isian.peserta.split(/[\s,;]+/).map((n) => n.trim()).filter(Boolean)
  try {
    const row = props.row ? await konketService.update(props.row.id, data, berkas.value) : await konketService.create(data, berkas.value)
    emit('saved', row)
    emit('update:open', false)
  } catch (e) {
    if (isApiError(e) && e.status === 422 && e.errors) {
      errors.value = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v?.[0] ?? '']))
      const dikenal = ['nip', 'date_start', 'date_end', 'kategori', 'jenis_dinas', 'affect_tukin', 'alasan', 'peserta', 'file_bukti']
      umum.value = Object.entries(errors.value).filter(([k]) => !dikenal.includes(k)).map(([, v]) => v).join(' ')
    } else {
      umum.value = describeApiError(e).message
    }
  } finally {
    busy.value = false
  }
}

function tutup(): void {
  if (!busy.value) emit('update:open', false)
}

const status = (k: string): 'default' | 'error' => (errors.value[k] ? 'error' : 'default')
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => (v ? emit('update:open', true) : tutup())">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="konket-dialog"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ row ? 'Ubah' : 'Ajukan' }} Konfirmasi Ketidakhadiran</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">
              Tanggal minimal {{ opsi.tanggal_minimal }}; data sebelum tanggal minimal tidak diterima sistem.
            </DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <form class="grid gap-4 md:grid-cols-2" data-testid="konket-form" @submit.prevent="simpan">
          <UiTextField
            v-if="admin && !row"
            v-model="isian.nip"
            class="md:col-span-2"
            label="NIP Pegawai"
            required
            :state="status('nip')"
            :help-text="errors.nip"
            data-testid="konket-nip"
          />
          <UiTextField
            v-model="isian.date_start"
            type="date"
            label="Tanggal Mulai"
            required
            :state="status('date_start')"
            :help-text="errors.date_start"
            data-testid="konket-date-start"
          />
          <UiTextField
            v-model="isian.date_end"
            type="date"
            label="Tanggal Akhir"
            required
            :state="status('date_end')"
            :help-text="errors.date_end"
            data-testid="konket-date-end"
          />
          <UiSelect
            v-model="isian.kategori"
            class="md:col-span-2"
            label="Jenis Ketidakhadiran"
            placeholder="Pilih jenis..."
            :options="jenisOpsi"
            required
            :state="status('kategori')"
            :help-text="errors.kategori"
            data-testid="konket-kategori"
          />
          <UiRadioGroup
            v-if="dinas"
            v-model="isian.jenis_dinas"
            label="Jenis Dinas"
            :options="[
              { value: '0', label: 'Pribadi' },
              { value: '1', label: 'Gabungan' },
            ]"
            :state="status('jenis_dinas')"
            :help-text="errors.jenis_dinas"
          />
          <UiRadioGroup
            v-if="admin"
            v-model="isian.affect_tukin"
            label="Pengaruh ke Tukin"
            :options="[
              { value: '1', label: 'Ya' },
              { value: '2', label: 'Tidak' },
            ]"
            :state="status('affect_tukin')"
            :help-text="errors.affect_tukin"
          />
          <label v-if="gabungan" class="md:col-span-2" data-testid="konket-peserta">
            <span class="mb-1 block text-body2 font-medium text-slate-700">Peserta (NIP, satu per baris) *</span>
            <textarea v-model="isian.peserta" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-body1" />
            <span v-if="errors.peserta" role="alert" class="mt-1 block text-caption text-danger">{{ errors.peserta }}</span>
          </label>
          <label class="md:col-span-2">
            <span class="mb-1 block text-body2 font-medium text-slate-700">Alasan *</span>
            <textarea v-model="isian.alasan" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-body1" data-testid="konket-alasan" />
            <span v-if="errors.alasan" role="alert" class="mt-1 block text-caption text-danger">{{ errors.alasan }}</span>
          </label>
          <div class="md:col-span-2" data-testid="konket-berkas">
            <UiFileField
              :label="`Berkas Bukti${row?.ada_berkas ? ' (sudah ada; pilih untuk mengganti)' : ' *'}`"
              :allowed="EKSTENSI"
              :max-mb="5"
              @change="(f) => (berkas = f)"
            />
            <span v-if="errors.file_bukti" role="alert" class="mt-1 block text-caption text-danger">{{ errors.file_bukti }}</span>
          </div>

          <UiNotice v-if="umum" tone="danger" class="md:col-span-2" data-testid="konket-error">{{ umum }}</UiNotice>

          <div class="flex justify-end gap-2 md:col-span-2">
            <UiButton type="button" variant="secondary" :disabled="busy" @click="tutup">Batal</UiButton>
            <UiButton type="submit" :loading="busy" data-testid="konket-simpan">Simpan</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
