<script setup lang="ts">
/**
 * Form pengajuan / ubah cuti (PRD §6.11.1, §6.11.3). Form 2 kolom (`formLayout.ts`; 1 kolom ≤ 600 px) dengan komponen
 * custom `shared/ui`. Pilihan jenis, atasan langsung (readonly), PyB, dan ketua tim dari `GET cuti/opsi`; lama cuti,
 * tanggal akhir jenis Besar, hari libur, dan kuota dari kalkulator `POST cuti/hitung-lama` (`useHitungLama`, debounce
 * 300 ms) — FE TIDAK menghitung apa pun dan Simpan tidak dikunci hasilnya. Galat 422 ditampilkan per kolom.
 * Role 1: memilih pegawai (NIP) dan status 0/1; role 2/7: untuk diri sendiri.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role } from '@/features/auth/types'
import { describeApiError, splitValidationErrors } from '@/features/kepegawaian/services/apiErrors'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import { UiButton, UiCheckbox, UiFileField, UiNotice, UiRadioGroup, UiSearchInput, UiSelect, UiTextField } from '@/shared/ui'

import { BERKAS_BUKTI, DURASI_OPSI, JENIS_BUKTI_WAJIB, JENIS_PAKAI_DURASI } from '../cutiJenis'
import { useHitungLama } from '../composables/useHitungLama'
import { cutiService } from '../services/cuti.service'
import type { CutiIsian, CutiItem, CutiOpsi } from '../types'

import KalkulatorCutiPanel from './KalkulatorCutiPanel.vue'

const props = defineProps<{ open: boolean; row: CutiItem | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [row: CutiItem] }>()

const KOLOM = [
  'nip',
  'status',
  'id_jenis_cuti',
  'tgl_mulai',
  'tgl_akhir',
  'durasi',
  'no_telp_saat_cuti',
  'alamat_saat_cuti',
  'keterangan',
  'nip_atasan_langsung',
  'nip_yang_menyetujui',
  'ketua_tim',
  'file_bukti',
  'lama_cuti',
]

const auth = useAuthStore()
const admin = computed(() => auth.role === Role.SUPER_ADMIN)

const opsi = ref<CutiOpsi | null>(null)
const memuatOpsi = ref(false)
const isian = reactive({
  nip: '',
  status: '0',
  id_jenis_cuti: '',
  tgl_mulai: '',
  tgl_akhir: '',
  durasi: '',
  no_telp_saat_cuti: '',
  alamat_saat_cuti: '',
  keterangan: '',
  nip_yang_menyetujui: '',
})
const ketuaTim = ref<string[]>([])
const cariKetua = ref('')
const berkas = ref<File | null>(null)
const errors = ref<Record<string, string>>({})
const umum = ref('')
const busy = ref(false)

const edit = computed(() => props.row !== null)
const jenis = computed(() => Number(isian.id_jenis_cuti))
const pakaiDurasi = computed(() => jenis.value === JENIS_PAKAI_DURASI)
const siapKalkulator = computed(() => props.open && opsi.value !== null)
const nipKalkulator = computed(() => (admin.value ? isian.nip.trim() : ''))
const idRiwayat = computed(() => props.row?.id ?? null)
const kalkulator = useHitungLama(isian, { aktif: siapKalkulator, nip: nipKalkulator, idRiwayatCuti: idRiwayat })

const jenisOpsi = computed(() => (opsi.value?.jenis_cuti ?? []).map((j) => ({ value: String(j.id_jenis_cuti), label: j.nama_jenis_cuti })))
const pybOpsi = computed(() => (opsi.value?.pyb ?? []).map((p) => ({ value: p.nip, label: p.jabatan ? `${p.nama} — ${p.jabatan}` : p.nama })))
const tampilPejabat = computed(() => opsi.value !== null && !opsi.value.eselon_i)
const tampilPyb = computed(() => tampilPejabat.value && (opsi.value?.pyb.length ?? 0) > 0)
const ketuaTersaring = computed(() => {
  const kata = cariKetua.value.trim().toLowerCase()
  const semua = opsi.value?.ketua_tim ?? []
  return kata === '' ? semua : semua.filter((k) => k.nama.toLowerCase().includes(kata) || k.nip.includes(kata))
})
const bisaDiisi = computed(() => opsi.value !== null && opsi.value.can_create)
const maksTglAkhir = computed(() => (jenis.value === 1 ? (opsi.value?.tgl_akhir_maks ?? undefined) : undefined))

function reset(): void {
  opsi.value = null
  errors.value = {}
  umum.value = ''
  berkas.value = null
  cariKetua.value = ''
  ketuaTim.value = []
  Object.assign(isian, {
    nip: '',
    status: '0',
    id_jenis_cuti: '',
    tgl_mulai: '',
    tgl_akhir: '',
    durasi: '',
    no_telp_saat_cuti: '',
    alamat_saat_cuti: '',
    keterangan: '',
    nip_yang_menyetujui: '',
  })
}

async function muatOpsi(nip?: string): Promise<void> {
  memuatOpsi.value = true
  umum.value = ''
  try {
    const o = await cutiService.opsi(nip)
    opsi.value = o
    if (!isian.nip_yang_menyetujui && o.pyb.length === 1) isian.nip_yang_menyetujui = o.pyb[0]!.nip
  } catch (e) {
    opsi.value = null
    umum.value = describeApiError(e).message
  } finally {
    memuatOpsi.value = false
  }
}

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    reset()
    const r = props.row
    if (r) {
      try {
        const d = await cutiService.detail(r.id)
        Object.assign(isian, {
          nip: d.nip,
          id_jenis_cuti: String(d.id_jenis_cuti),
          tgl_mulai: d.tgl_mulai,
          tgl_akhir: d.tgl_akhir,
          no_telp_saat_cuti: d.no_telp_saat_cuti,
          alamat_saat_cuti: d.alamat_saat_cuti,
          keterangan: d.keterangan,
          nip_yang_menyetujui: d.nip_yang_menyetujui ?? '',
        })
        ketuaTim.value = d.ketua_tim_detail.map((k) => k.nip)
      } catch (e) {
        umum.value = describeApiError(e).message
        return
      }
      await muatOpsi(admin.value ? r.nip : undefined)
    } else if (!admin.value) {
      await muatOpsi()
    }
  },
  { immediate: true },
)

function pilihKetua(nip: string, dipilih: boolean): void {
  ketuaTim.value = dipilih ? [...new Set([...ketuaTim.value, nip])] : ketuaTim.value.filter((n) => n !== nip)
}

function isianKirim(): CutiIsian {
  const data: CutiIsian = {
    id_jenis_cuti: isian.id_jenis_cuti,
    tgl_mulai: isian.tgl_mulai,
    no_telp_saat_cuti: isian.no_telp_saat_cuti,
    alamat_saat_cuti: isian.alamat_saat_cuti,
    keterangan: isian.keterangan,
    ketua_tim: ketuaTim.value,
  }
  if (pakaiDurasi.value) data.durasi = isian.durasi
  else data.tgl_akhir = isian.tgl_akhir
  const o = opsi.value
  if (o && tampilPejabat.value) {
    if (o.atasan_langsung) data.nip_atasan_langsung = o.atasan_langsung.nip
    if (tampilPyb.value) data.nip_yang_menyetujui = isian.nip_yang_menyetujui
  }
  if (admin.value) {
    data.nip = isian.nip.trim()
    data.status = isian.status
  }
  return data
}

async function simpan(): Promise<void> {
  errors.value = {}
  umum.value = ''
  if (jenis.value === JENIS_BUKTI_WAJIB && !edit.value && !berkas.value) {
    errors.value = { file_bukti: 'Lampiran bukti wajib untuk cuti sakit.' }
    return
  }
  busy.value = true
  try {
    const item = props.row ? await cutiService.update(props.row.id, isianKirim(), berkas.value) : await cutiService.create(isianKirim(), berkas.value)
    emit('saved', item)
    emit('update:open', false)
  } catch (e) {
    const v = splitValidationErrors(e, KOLOM)
    errors.value = v.fields
    umum.value = v.general.length ? v.general.join(' ') : Object.keys(v.fields).length ? '' : describeApiError(e).message
  } finally {
    busy.value = false
  }
}

function tutup(): void {
  if (!busy.value) emit('update:open', false)
}

const state = (k: string): 'default' | 'error' => (errors.value[k] || kalkulator.galat.value[k] ? 'error' : 'default')
const pesan = (k: string): string | undefined => errors.value[k] || kalkulator.galat.value[k] || undefined
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => (v ? emit('update:open', true) : tutup())">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="cuti-form-dialog"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ edit ? 'Ubah' : 'Ajukan' }} Cuti</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">
              Lama cuti dan sisa kuota dihitung oleh sistem; keputusan akhir tetap pada validasi server saat disimpan.
            </DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <p v-if="memuatOpsi" class="py-6 text-center text-body2 text-slate-500" data-testid="cuti-form-memuat">Memuat…</p>

        <form v-else :class="FORM_GRID_CLASS" novalidate data-testid="cuti-form" @submit.prevent="simpan">
          <p v-if="umum" role="alert" :class="FORM_ALERT_CLASS" data-testid="cuti-form-error">{{ umum }}</p>

          <template v-if="admin && !edit">
            <UiTextField
              v-model="isian.nip"
              label="NIP Pegawai"
              required
              :state="state('nip')"
              :help-text="pesan('nip')"
              data-testid="cuti-nip"
            />
            <div class="flex items-end">
              <UiButton type="button" appearance="soft" variant="secondary" :disabled="isian.nip.trim() === '' || memuatOpsi" data-testid="cuti-muat-pegawai" @click="muatOpsi(isian.nip.trim())">
                Muat Data Pegawai
              </UiButton>
            </div>
          </template>

          <UiNotice v-if="opsi && !bisaDiisi" tone="warning" :class="FORM_WIDE_CLASS" data-testid="cuti-tidak-bisa">
            Tidak ada jenis cuti yang dapat diajukan untuk pegawai ini.
          </UiNotice>

          <template v-if="opsi && bisaDiisi">
            <UiRadioGroup
              v-if="admin"
              v-model="isian.status"
              label="Status Pengajuan"
              :options="[
                { value: '0', label: 'Diajukan (menunggu persetujuan)' },
                { value: '1', label: 'Langsung disetujui' },
              ]"
              :state="state('status')"
              :help-text="pesan('status')"
              :class="FORM_WIDE_CLASS"
            />

            <UiSelect
              v-model="isian.id_jenis_cuti"
              label="Jenis Cuti"
              placeholder="Pilih jenis cuti..."
              :options="jenisOpsi"
              required
              :state="state('id_jenis_cuti')"
              :help-text="pesan('id_jenis_cuti')"
              data-testid="cuti-jenis"
            />
            <UiTextField
              v-model="isian.tgl_mulai"
              type="date"
              label="Tanggal Mulai"
              required
              :state="state('tgl_mulai')"
              :help-text="pesan('tgl_mulai')"
              data-testid="cuti-tgl-mulai"
            />
            <UiSelect
              v-if="pakaiDurasi"
              v-model="isian.durasi"
              label="Durasi"
              placeholder="Pilih durasi..."
              :options="DURASI_OPSI"
              required
              :state="state('durasi')"
              :help-text="pesan('durasi')"
              data-testid="cuti-durasi"
            />
            <UiTextField
              v-else
              v-model="isian.tgl_akhir"
              type="date"
              label="Tanggal Akhir"
              required
              :max="maksTglAkhir"
              :state="state('tgl_akhir')"
              :help-text="pesan('tgl_akhir')"
              data-testid="cuti-tgl-akhir"
            />

            <KalkulatorCutiPanel
              :class="FORM_WIDE_CLASS"
              :hasil="kalkulator.hasil.value"
              :memuat="kalkulator.memuat.value"
              :galat-umum="kalkulator.galatUmum.value"
              :tampilkan-tgl-akhir="pakaiDurasi"
            />
            <p v-if="state('lama_cuti') === 'error'" role="alert" class="text-caption text-danger" :class="FORM_WIDE_CLASS" data-testid="cuti-lama-error">
              {{ pesan('lama_cuti') }}
            </p>

            <UiTextField
              v-model="isian.no_telp_saat_cuti"
              label="No. Telepon Saat Cuti"
              required
              inputmode="tel"
              :state="state('no_telp_saat_cuti')"
              :help-text="pesan('no_telp_saat_cuti')"
              data-testid="cuti-telp"
            />
            <UiTextField
              v-model="isian.alamat_saat_cuti"
              label="Alamat Saat Cuti"
              required
              :state="state('alamat_saat_cuti')"
              :help-text="pesan('alamat_saat_cuti')"
              data-testid="cuti-alamat"
            />
            <label :class="FORM_WIDE_CLASS">
              <span class="mb-1.5 block text-body2 font-medium text-slate-700">Keterangan<span class="text-danger" aria-hidden="true"> *</span></span>
              <textarea
                v-model="isian.keterangan"
                rows="3"
                class="w-full rounded-xl border px-3 py-2 text-body2 text-slate-800 focus:outline-none focus:ring-2"
                :class="errors.keterangan ? 'border-danger focus:ring-danger/30' : 'border-slate-300 focus:ring-brand-primary/30'"
                :aria-invalid="errors.keterangan ? 'true' : undefined"
                data-testid="cuti-keterangan"
              />
              <span v-if="errors.keterangan" role="alert" class="mt-1 block text-caption text-danger">{{ errors.keterangan }}</span>
            </label>

            <template v-if="tampilPejabat">
              <div v-if="opsi.atasan_langsung" data-testid="cuti-atasan">
                <span class="mb-1.5 block text-body2 font-medium text-slate-700">Atasan Langsung</span>
                <p class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-body2 text-slate-800">
                  {{ opsi.atasan_langsung.nama }}<span v-if="opsi.atasan_langsung.jabatan" class="block text-slate-500">{{ opsi.atasan_langsung.jabatan }}</span>
                </p>
                <span v-if="errors.nip_atasan_langsung" role="alert" class="mt-1 block text-caption text-danger">{{ errors.nip_atasan_langsung }}</span>
              </div>
              <UiSelect
                v-if="tampilPyb"
                v-model="isian.nip_yang_menyetujui"
                label="Pejabat yang Menyetujui"
                placeholder="Pilih pejabat..."
                :options="pybOpsi"
                required
                :state="state('nip_yang_menyetujui')"
                :help-text="pesan('nip_yang_menyetujui')"
                data-testid="cuti-pyb"
              />
            </template>
            <UiNotice v-else tone="info" :class="FORM_WIDE_CLASS" data-testid="cuti-eselon-i">
              Pengajuan pejabat Eselon I diproses admin atas nama Menteri.
            </UiNotice>

            <fieldset :class="FORM_WIDE_CLASS" data-testid="cuti-ketua-tim">
              <legend class="mb-1.5 text-body2 font-medium text-slate-700">Tembusan Ketua Tim (opsional)</legend>
              <UiSearchInput v-model="cariKetua" label="Cari ketua tim" placeholder="Cari nama atau NIP" />
              <ul class="scrollbar-slim mt-2 max-h-40 space-y-1 overflow-y-auto rounded-xl border border-slate-200 p-2">
                <li v-for="k in ketuaTersaring" :key="k.nip">
                  <UiCheckbox :model-value="ketuaTim.includes(k.nip)" :label="`${k.nama} (${k.nip})`" @update:model-value="(v) => pilihKetua(k.nip, v)" />
                </li>
                <li v-if="ketuaTersaring.length === 0" class="px-1 text-body2 text-slate-500">Tidak ada ketua tim yang cocok.</li>
              </ul>
              <p class="mt-1 text-caption text-slate-500" data-testid="cuti-ketua-jumlah">{{ ketuaTim.length }} dipilih</p>
              <span v-if="errors.ketua_tim" role="alert" class="mt-1 block text-caption text-danger">{{ errors.ketua_tim }}</span>
            </fieldset>

            <div :class="FORM_WIDE_CLASS">
              <UiFileField
                label="Lampiran Bukti"
                :allowed="[...BERKAS_BUKTI.allowed]"
                :max-mb="BERKAS_BUKTI.maxMb"
                :hint="jenis === JENIS_BUKTI_WAJIB && !edit ? 'Wajib untuk cuti sakit.' : 'Opsional.'"
                @change="(f) => (berkas = f)"
              />
              <span v-if="errors.file_bukti" role="alert" class="mt-1 block text-caption text-danger" data-testid="cuti-file-error">{{ errors.file_bukti }}</span>
            </div>
          </template>

          <div :class="FORM_ACTIONS_CLASS">
            <UiButton type="button" appearance="soft" variant="secondary" :disabled="busy" @click="tutup">Batal</UiButton>
            <UiButton type="submit" :loading="busy" :disabled="!bisaDiisi" data-testid="cuti-simpan">Simpan</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
