<script setup lang="ts">
/**
 * Generator dokumen F-09 (MAKE-037, PRD Fase 7 §7.2.10): satu halaman untuk DUK, Nominatif, Proyeksi Pensiun, dan
 * Baperjakat (`/dokumen/:jenis`). Pemilih lingkup (dari `statistik/lingkup`, sama dengan laporan), filter tahun/sampai
 * untuk Pensiun, tabel berhalaman kolom inti (kolom lengkap ada di XLSX), unduh PDF/XLSX. Baperjakat: centang baris
 * (lintas halaman) + judul/subjudul/teks → POST. Fallback PDF F7-15 (ADR-034): PDF hanya per unit/satker dan maksimal
 * 1.700 baris — tombol PDF dinonaktifkan dengan penjelasan saat lingkup kementerian, dan pesan 422 server tampil apa
 * adanya. Aksi per baris (⋮): "Lihat DRH". Akses role 1, 3, 4, 5, 8; data dari API, tidak ada data contoh.
 */
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiCheckbox, UiNotice, UiPagination, UiSearchInput, UiSelect, UiTextField } from '@/shared/ui'
import type { SelectOption } from '@/shared/ui'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import { pegawaiService } from '@/features/kepegawaian/services/pegawai.service'
import { laporanService } from '@/features/laporan/laporan.service'
import type { LingkupPilihan, LingkupTersedia } from '@/features/laporan/types'

import { dokumenService, isDokumenJenis, pesanGalatUnduhan } from '../dokumen.service'
import {
  BAPERJAKAT_JUDUL_MAKS,
  BAPERJAKAT_NIP_MAKS,
  BAPERJAKAT_TEKS_MAKS,
  DOKUMEN_LABEL,
  KOLOM_INTI,
  PDF_MAKS_BARIS,
  type DokumenData,
  type DokumenFormat,
  type DokumenItem,
  type DokumenJenis,
} from '../types'

const PER_PAGE = 20
const nf = new Intl.NumberFormat('id-ID')

const route = useRoute()
const data = ref<DokumenData | null>(null)
const lingkupTersedia = ref<LingkupTersedia | null>(null)
const pilihan = ref<LingkupPilihan>('')
const search = ref('')
const page = ref(1)
const tahun = ref('')
const sampai = ref('')
const failure = ref<string | null>(null)
const galatUnduh = ref<string | null>(null)
const memuat = ref(false)
const mengunduh = ref<DokumenFormat | null>(null)

const terpilih = ref<Set<string>>(new Set())
const bap = reactive({ judul: '', subjudul: '', teks: '' })
const bapErrors = reactive<{ judul?: string; nip?: string }>({})

const jenis = computed<DokumenJenis>(() => (isDokumenJenis(route.params.jenis) ? route.params.jenis : 'duk'))
const isBaperjakat = computed(() => jenis.value === 'baperjakat')
const isPensiun = computed(() => jenis.value === 'pensiun')

/** Opsi pemilih lingkup (pola laporan): kementerian (bila boleh), tiap unit, lalu satker di bawahnya. */
const lingkupOptions = computed<SelectOption[]>(() => {
  const l = lingkupTersedia.value
  if (!l) return []
  const opsi: SelectOption[] = []
  if (l.kementerian) opsi.push({ value: 'kementerian', label: l.kementerian.nama })
  for (const u of l.unit) {
    opsi.push({ value: `unit:${u.id}`, label: u.nama })
    for (const s of u.satker) opsi.push({ value: `satker:${s.id}`, label: `— ${s.nama}` })
  }
  return opsi
})

/**
 * Lingkup efektif kementerian: dipilih eksplisit, atau bawaan server untuk akun yang boleh lingkup kementerian
 * (role 1/4/5/8). Role 3 tanpa pilihan = satker/unit akun.
 */
const lingkupKementerian = computed(() => pilihan.value === 'kementerian' || (pilihan.value === '' && lingkupTersedia.value?.kementerian != null))

/** Alasan tombol PDF dinonaktifkan (fallback F7-15), atau null bila PDF boleh dicoba. */
const alasanPdfTerkunci = computed<string | null>(() => {
  if (isBaperjakat.value) {
    return terpilih.value.size > PDF_MAKS_BARIS ? `PDF Baperjakat maksimal ${nf.format(PDF_MAKS_BARIS)} pegawai; kurangi pilihan atau unduh XLSX.` : null
  }
  if (lingkupKementerian.value) return 'PDF hanya tersedia per unit/satker. Pilih unit atau satker, atau unduh XLSX untuk seluruh kementerian.'
  if (data.value && data.value.total > PDF_MAKS_BARIS) return `PDF maksimal ${nf.format(PDF_MAKS_BARIS)} baris. Persempit lingkup atau unduh XLSX.`
  return null
})

async function muatLingkup(): Promise<void> {
  try {
    lingkupTersedia.value = await laporanService.lingkup()
  } catch (error) {
    failure.value = describeApiError(error).message
  }
}

async function load(): Promise<void> {
  memuat.value = true
  failure.value = null
  try {
    data.value = await dokumenService.daftar(jenis.value, pilihan.value, {
      search: search.value,
      page: page.value,
      per_page: PER_PAGE,
      ...(isPensiun.value ? { tahun: tahun.value, sampai: sampai.value } : {}),
    })
  } catch (error) {
    data.value = null
    failure.value = await pesanGalatUnduhan(error)
  } finally {
    memuat.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | null = null
function muatTertunda(): void {
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => {
    page.value = 1
    void load()
  }, 300)
}
onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
})

void muatLingkup()
watch(
  jenis,
  () => {
    search.value = ''
    page.value = 1
    galatUnduh.value = null
    terpilih.value = new Set()
    void load()
  },
  { immediate: true },
)
watch(pilihan, () => {
  page.value = 1
  galatUnduh.value = null
  void load()
})
watch([search, tahun, sampai], muatTertunda)
watch(page, () => void load())

/** Kolom inti yang memang ada di respons (label dari server). */
const kolom = computed(() => KOLOM_INTI[jenis.value].filter((k) => data.value?.kolom[k] !== undefined).map((k) => ({ key: k, label: data.value?.kolom[k] ?? k })))
const items = computed<DokumenItem[]>(() => data.value?.items ?? [])
const judul = computed(() => data.value?.judul ?? `Dokumen ${DOKUMEN_LABEL[jenis.value]}`)
const crumbs = computed(() => [{ label: 'Home', to: { name: 'home' } }, { label: 'Dokumen' }, { label: judul.value }])
const periode = computed(() => (data.value ? new Date(`${data.value.periode}T00:00:00`).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : ''))

// --- Baperjakat: pilihan baris lintas halaman ---
const nipHalaman = computed(() => items.value.map((r) => String(r.nip)))
const semuaHalamanTerpilih = computed(() => nipHalaman.value.length > 0 && nipHalaman.value.every((n) => terpilih.value.has(n)))

function pilih(nip: string, nilai: boolean): void {
  const baru = new Set(terpilih.value)
  if (nilai) baru.add(nip)
  else baru.delete(nip)
  terpilih.value = baru
}

function pilihHalaman(nilai: boolean): void {
  const baru = new Set(terpilih.value)
  for (const n of nipHalaman.value) {
    if (nilai) baru.add(n)
    else baru.delete(n)
  }
  terpilih.value = baru
}

function validasiBaperjakat(): boolean {
  bapErrors.judul = undefined
  bapErrors.nip = undefined
  if (terpilih.value.size === 0) bapErrors.nip = 'Pilih minimal satu pegawai.'
  else if (terpilih.value.size > BAPERJAKAT_NIP_MAKS) bapErrors.nip = `Maksimal ${nf.format(BAPERJAKAT_NIP_MAKS)} pegawai per dokumen.`
  if (bap.judul.trim() === '') bapErrors.judul = 'Judul wajib diisi.'
  else if (bap.judul.trim().length > BAPERJAKAT_JUDUL_MAKS) bapErrors.judul = `Judul maksimal ${BAPERJAKAT_JUDUL_MAKS} karakter.`
  return !bapErrors.judul && !bapErrors.nip
}

// --- Unduhan ---
async function unduh(format: DokumenFormat): Promise<void> {
  if (mengunduh.value) return
  galatUnduh.value = null
  if (isBaperjakat.value && !validasiBaperjakat()) return

  mengunduh.value = format
  try {
    const berkas = isBaperjakat.value
      ? await dokumenService.baperjakat(format, {
          nip: [...terpilih.value],
          judul: bap.judul.trim(),
          ...(bap.subjudul.trim() ? { subjudul: bap.subjudul.trim() } : {}),
          ...(bap.teks.trim() ? { teks: bap.teks.trim() } : {}),
        })
      : await dokumenService.unduh(jenis.value as Exclude<DokumenJenis, 'baperjakat'>, format, pilihan.value, {
          search: search.value,
          ...(isPensiun.value ? { tahun: tahun.value, sampai: sampai.value } : {}),
        })
    simpanBerkas(berkas)
  } catch (error) {
    galatUnduh.value = await pesanGalatUnduhan(error)
  } finally {
    mengunduh.value = null
  }
}

const AKSI: RowAction[] = [{ key: 'drh', label: 'Lihat DRH' }]

async function onAksi(key: string, row: DokumenItem): Promise<void> {
  if (key !== 'drh') return
  try {
    simpanBerkas(await pegawaiService.drh(String(row.nip)))
  } catch (error) {
    galatUnduh.value = await pesanGalatUnduhan(error)
  }
}
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiNotice v-if="failure" tone="danger" class="mb-4" data-testid="dokumen-failure">{{ failure }}</UiNotice>
    <UiNotice v-if="galatUnduh" tone="warning" dismissible class="mb-4" data-testid="dokumen-unduh-galat" @dismiss="galatUnduh = null">{{ galatUnduh }}</UiNotice>

    <div class="space-y-5" data-testid="dokumen-page" :aria-busy="memuat">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900">{{ judul }}</h1>
          <p v-if="data" class="text-body2 text-slate-500">{{ data.lingkup.nama }} · {{ nf.format(data.total) }} pegawai · Data per {{ periode }}</p>
        </div>
        <div class="flex flex-col items-end gap-1">
          <div class="flex gap-2">
            <UiButton
              appearance="outline"
              :disabled="!data || alasanPdfTerkunci !== null || mengunduh !== null"
              :loading="mengunduh === 'pdf'"
              data-testid="dokumen-unduh-pdf"
              @click="unduh('pdf')"
            >
              Unduh PDF
            </UiButton>
            <UiButton :disabled="!data || mengunduh !== null" :loading="mengunduh === 'xlsx'" data-testid="dokumen-unduh-xlsx" @click="unduh('xlsx')">Unduh XLSX</UiButton>
          </div>
          <p v-if="data && alasanPdfTerkunci" class="max-w-sm text-right text-caption text-slate-500" data-testid="dokumen-pdf-alasan">{{ alasanPdfTerkunci }}</p>
        </div>
      </div>

      <UiCard title="Filter" :subtitle="isBaperjakat ? 'Daftar pegawai aktif; centang pegawai yang dimasukkan ke dokumen' : 'Kolom lengkap tersedia di XLSX'">
        <div class="grid gap-4 md:grid-cols-3">
          <div v-if="lingkupOptions.length" data-testid="dokumen-lingkup">
            <UiSelect v-model="pilihan" label="Lingkup" :options="lingkupOptions" placeholder="Lingkup bawaan akun" clearable />
          </div>
          <UiSearchInput v-model="search" placeholder="Cari nama atau NIP" label="Cari pegawai" />
          <div v-if="isPensiun" class="grid grid-cols-2 gap-3" data-testid="dokumen-rentang">
            <UiTextField v-model="tahun" label="Tahun" placeholder="Tahun berjalan" name="tahun" />
            <UiTextField v-model="sampai" label="Sampai" placeholder="Opsional" name="sampai" help-text="Maks. 4 tahun sesudah Tahun" />
          </div>
        </div>
      </UiCard>

      <UiCard v-if="isBaperjakat" title="Dokumen Baperjakat" :subtitle="`${nf.format(terpilih.size)} pegawai dipilih`" data-testid="dokumen-baperjakat">
        <div class="grid gap-4 md:grid-cols-2">
          <UiTextField
            v-model="bap.judul"
            label="Judul"
            name="judul"
            required
            :state="bapErrors.judul ? 'error' : 'default'"
            :help-text="bapErrors.judul ?? `Maks. ${BAPERJAKAT_JUDUL_MAKS} karakter`"
          />
          <UiTextField v-model="bap.subjudul" label="Subjudul" name="subjudul" :help-text="`Opsional, maks. ${BAPERJAKAT_JUDUL_MAKS} karakter`" />
          <label class="md:col-span-2">
            <span class="mb-1 block text-body2 font-medium text-slate-700">Teks</span>
            <textarea v-model="bap.teks" rows="3" :maxlength="BAPERJAKAT_TEKS_MAKS" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-body1" data-testid="dokumen-bap-teks" />
          </label>
        </div>
        <p v-if="bapErrors.nip" role="alert" class="mt-2 text-caption text-danger" data-testid="dokumen-bap-nip-galat">{{ bapErrors.nip }}</p>
      </UiCard>

      <UiCard :title="`Tabel ${judul}`" flush>
        <div class="space-y-4 pb-5">
          <div class="scrollbar-slim overflow-x-auto">
            <table class="w-full min-w-[48rem] border-collapse text-left text-body1" data-testid="dokumen-table">
              <thead>
                <tr class="border-y border-slate-200 text-body2 font-semibold text-slate-900">
                  <th v-if="isBaperjakat" scope="col" class="w-10 px-5 py-3">
                    <UiCheckbox :model-value="semuaHalamanTerpilih" label="Pilih semua di halaman ini" data-testid="dokumen-pilih-halaman" @update:model-value="pilihHalaman" />
                  </th>
                  <th scope="col" class="px-5 py-3">No</th>
                  <th v-for="k in kolom" :key="k.key" scope="col" class="whitespace-nowrap px-5 py-3">{{ k.label }}</th>
                  <th scope="col" class="px-5 py-3 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-200">
                <tr v-for="row in items" :key="String(row.nip)" :data-testid="`dokumen-row-${row.nip}`">
                  <td v-if="isBaperjakat" class="px-5 py-3">
                    <UiCheckbox
                      :model-value="terpilih.has(String(row.nip))"
                      :label="`Pilih ${row.nama}`"
                      :data-testid="`dokumen-pilih-${row.nip}`"
                      @update:model-value="(v: boolean) => pilih(String(row.nip), v)"
                    />
                  </td>
                  <td class="px-5 py-3 tabular-nums">{{ row.no }}</td>
                  <td v-for="k in kolom" :key="k.key" class="px-5 py-3 text-slate-800">{{ row[k.key] || '—' }}</td>
                  <td class="px-5 py-3 text-right">
                    <RowActionsMenu :actions="AKSI" :label="`Aksi untuk ${row.nama}`" :testid="`dokumen-actions-${row.nip}`" @select="(key) => onAksi(key, row)" />
                  </td>
                </tr>
                <tr v-if="data && items.length === 0">
                  <td :colspan="kolom.length + (isBaperjakat ? 3 : 2)" class="px-5 py-10 text-center text-slate-500" data-testid="dokumen-empty">
                    {{ search ? 'Tidak ada pegawai yang cocok' : 'Belum ada data pada lingkup ini' }}
                  </td>
                </tr>
                <tr v-if="!data && memuat">
                  <td :colspan="kolom.length + (isBaperjakat ? 3 : 2)" class="px-5 py-10 text-center text-slate-500">Memuat…</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="data && data.total > data.per_page" class="px-5">
            <UiPagination v-model:page="page" :per-page="data.per_page" :total="data.total" />
          </div>
        </div>
      </UiCard>
    </div>
  </RedesignShell>
</template>
