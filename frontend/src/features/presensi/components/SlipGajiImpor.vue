<script setup lang="ts">
/**
 * Tab Impor Kelola Slip Gaji (D-10, PRD 5A §7.2, §7.8.1, AC-FE.2) [K `admin_import.php`]: Langkah 1 unggah berkas
 * .xlsx/.xls + filter periode opsional (bulan & tahun sekaligus) → Langkah 2 tabel pratinjau (status per baris dari
 * server: Valid / Duplikat / Galat; baris galat ditandai warna + daftar pesan; ringkasan) + kotak centang "Timpa data
 * yang sudah ada" (bawaan dari server, tercentang, F5-5) → "Simpan ke Database" lewat `ConfirmDialog` → hasil commit.
 * Validasi impor seluruhnya di server (ADR-025); 409 (data berubah oleh proses lain) ditampilkan dengan ajakan unggah ulang.
 */
import { Upload } from 'lucide-vue-next'
import { computed, ref } from 'vue'

import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import { UiBadge, UiButton, UiCheckbox, UiFileField, UiMonthPicker, UiNotice, UiStatTile } from '@/shared/ui'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'

import { labelPeriode, slipGajiAdminService } from '../services/slipGajiAdmin.service'
import type { BarisPratinjau, GalatImpor, HasilImpor, PratinjauImpor, StatusBarisImpor } from '../types'

const emit = defineEmits<{ selesai: [periode: string | null] }>()

const berkas = ref<File | null>(null)
const filter = ref('')
const pratinjau = ref<PratinjauImpor | null>(null)
const timpa = ref(true)
const hasil = ref<HasilImpor | null>(null)
const memproses = ref(false)
const failure = ref<string | null>(null)
const galatField = ref<Record<string, string>>({})
const konflik = ref(false)
const konfirmasi = ref(false)
const kunciUnggah = ref(0)

const STATUS: Record<StatusBarisImpor, { label: string; tone: 'success' | 'warning' | 'danger'; baris: string }> = {
  valid: { label: 'Valid', tone: 'success', baris: '' },
  duplikat: { label: 'Duplikat', tone: 'warning', baris: 'bg-warning-soft/60' },
  galat: { label: 'Galat', tone: 'danger', baris: 'bg-danger-soft/60' },
}

const akanDisimpan = computed(() => {
  const p = pratinjau.value
  if (!p) return 0
  return p.baris.filter((b) => {
    const r = timpa.value ? b.rencana_bila_timpa : b.rencana_bila_tidak_timpa
    return r === 'sisip' || r === 'perbarui'
  }).length
})

function keterangan(b: BarisPratinjau): string {
  if (b.galat.length > 0) return ''
  if (b.status === 'duplikat') return timpa.value ? 'Data periode ini sudah ada; akan diperbarui (timpa).' : 'Data periode ini sudah ada; akan dilewati.'
  return 'Siap disimpan'
}

/**
 * CR-082 (T5A-02): server kadang sudah memberi awalan lokasi "Sheet <sheet> baris <n>" (BarisImpor.php), kadang tidak (mis.
 * NIP tidak terdaftar). Awalan hanya ditambahkan bila pesan belum berawalan lokasi apa pun, termasuk sheet lain (galat
 * lintas sheet milik baris kunci), supaya label tidak ganda.
 */
const AWALAN_LOKASI = /^Sheet \S+ baris \d+\b/

function labelGalat(g: GalatImpor): string {
  if (AWALAN_LOKASI.test(g.pesan)) return g.pesan
  return `Sheet ${g.sheet} baris ${g.baris}: ${g.pesan}`
}

function pilihBerkas(file: File | null): void {
  berkas.value = file
  failure.value = null
  galatField.value = {}
}

async function validasi(): Promise<void> {
  if (!berkas.value || memproses.value) return
  memproses.value = true
  failure.value = null
  galatField.value = {}
  konflik.value = false
  hasil.value = null
  try {
    pratinjau.value = await slipGajiAdminService.pratinjau(berkas.value, filter.value || null)
    timpa.value = pratinjau.value.timpa_bawaan
  } catch (err) {
    pratinjau.value = null
    if (isApiError(err) && err.status === 422 && err.errors) {
      galatField.value = Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, String(v[0] ?? '')]))
    }
    failure.value = isApiError(err) && err.status === 422 && err.message ? err.message : describeApiError(err).message
  } finally {
    memproses.value = false
  }
}

async function simpan(): Promise<void> {
  const p = pratinjau.value
  if (!p) return
  memproses.value = true
  failure.value = null
  try {
    hasil.value = await slipGajiAdminService.commit(p.token_pratinjau, timpa.value)
    konfirmasi.value = false
    const periodeHasil = hasil.value.baris.find((b) => b.hasil === 'sisip' || b.hasil === 'perbarui')?.periode ?? null
    pratinjau.value = null
    emit('selesai', periodeHasil)
  } catch (err) {
    konfirmasi.value = false
    konflik.value = isApiError(err) && (err.status === 409 || err.status === 410 || err.status === 404)
    failure.value = isApiError(err) && err.message && err.status !== null && err.status < 500 ? err.message : describeApiError(err).message
  } finally {
    memproses.value = false
  }
}

function ulang(): void {
  pratinjau.value = null
  hasil.value = null
  berkas.value = null
  failure.value = null
  konflik.value = false
  galatField.value = {}
  kunciUnggah.value += 1
}
</script>

<template>
  <div class="space-y-4">
    <UiNotice v-if="failure" tone="danger" data-testid="impor-failure">
      {{ failure }}
      <template v-if="konflik"> Silakan unggah ulang berkas untuk membuat pratinjau baru.</template>
    </UiNotice>

    <!-- Langkah 1 -->
    <section v-if="!pratinjau && !hasil" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5" aria-labelledby="impor-langkah-1">
      <h2 id="impor-langkah-1" class="text-h5 text-slate-900">Langkah 1: Unggah Berkas</h2>
      <UiFileField
        :key="kunciUnggah"
        label="Berkas data gaji (.xlsx / .xls)"
        :allowed="['xlsx', 'xls']"
        :max-mb="5"
        hint="Sheet GPP dan TK sesuai templat data gaji dari Biro Perencanaan Keuangan."
        :disabled="memproses"
        data-testid="impor-berkas"
        @change="pilihBerkas"
      />
      <p v-if="galatField.berkas" class="text-body2 text-red-700" data-testid="impor-galat-berkas">{{ galatField.berkas }}</p>
      <div class="max-w-xs">
        <UiMonthPicker
          v-model="filter"
          aria-label="Filter periode sebelum validasi (opsional)"
          placeholder="Semua periode"
          :invalid="Boolean(galatField.filter_bulan)"
          data-testid="impor-filter"
        />
        <p class="mt-1 text-caption text-slate-500">
          Filter periode (opsional): bila berkas berisi banyak periode, hanya baris periode ini yang divalidasi dan disimpan.
        </p>
      </div>
      <UiButton :disabled="!berkas" :loading="memproses" data-testid="impor-validasi" @click="validasi">
        <Upload class="h-4 w-4" aria-hidden="true" /> Validasi &amp; Pratinjau
      </UiButton>
    </section>

    <!-- Langkah 2 -->
    <section v-if="pratinjau" class="space-y-4" aria-labelledby="impor-langkah-2" data-testid="impor-pratinjau">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="impor-langkah-2" class="text-h5 text-slate-900">
          Langkah 2: Pratinjau &amp; Validasi<template v-if="pratinjau.filter"> · {{ labelPeriode(pratinjau.filter) }}</template>
        </h2>
        <UiButton variant="secondary" appearance="outline" size="sm" :disabled="memproses" data-testid="impor-batal" @click="ulang">Batal</UiButton>
      </div>
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-4" data-testid="impor-ringkasan">
        <UiStatTile label="Total Baris" :value="pratinjau.baris.length" />
        <UiStatTile label="Valid" :value="pratinjau.ringkasan.valid" />
        <UiStatTile label="Duplikat" :value="pratinjau.ringkasan.duplikat" />
        <UiStatTile label="Galat" :value="pratinjau.ringkasan.galat" />
      </div>
      <UiNotice v-if="pratinjau.ringkasan.galat > 0" tone="warning">
        Ditemukan {{ pratinjau.ringkasan.galat }} baris bermasalah. Baris bermasalah dilewati saat penyimpanan; perbaiki berkas lalu unggah ulang bila perlu.
      </UiNotice>

      <div class="scrollbar-slim max-h-[60vh] overflow-auto rounded-2xl border border-slate-200 bg-white" data-testid="impor-tabel">
        <table class="min-w-full text-sm">
          <thead class="sticky top-0 z-10 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-3 py-2">Sheet</th>
              <th class="px-3 py-2">Baris</th>
              <th class="px-3 py-2">NIP</th>
              <th class="px-3 py-2">Nama Pegawai</th>
              <th class="px-3 py-2">Periode</th>
              <th class="px-3 py-2">Status</th>
              <th class="px-3 py-2">Keterangan</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="pratinjau.baris.length === 0">
              <td colspan="7" class="px-3 py-6 text-center text-slate-500">Berkas tidak berisi baris data.</td>
            </tr>
            <tr
              v-for="b in pratinjau.baris"
              :key="`${b.sheet}-${b.nomor}`"
              :class="STATUS[b.status]?.baris"
              :data-status="b.status"
              :data-testid="`impor-baris-${b.sheet}-${b.nomor}`"
            >
              <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ b.sheet }}</td>
              <td class="px-3 py-2 tabular-nums text-slate-600">{{ b.nomor }}</td>
              <td class="whitespace-nowrap px-3 py-2 tabular-nums text-slate-800">{{ b.nip ?? '-' }}</td>
              <td class="px-3 py-2 text-slate-800">{{ b.nama ?? '-' }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ labelPeriode(b.periode) }}</td>
              <td class="px-3 py-2">
                <UiBadge :tone="STATUS[b.status]?.tone ?? 'neutral'" size="sm">{{ STATUS[b.status]?.label ?? b.status }}</UiBadge>
              </td>
              <td class="px-3 py-2">
                <ul v-if="b.galat.length > 0" class="list-disc space-y-0.5 pl-4 text-red-700">
                  <li v-for="(g, i) in b.galat" :key="i">{{ g.pesan }}</li>
                </ul>
                <span v-else class="text-slate-500">{{ keterangan(b) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="space-y-1 rounded-2xl border border-slate-200 bg-white p-4" data-testid="impor-timpa">
        <UiCheckbox v-model="timpa" label="Timpa data yang sudah ada" :disabled="memproses" />
        <p class="pl-7 text-caption text-slate-500">
          Bila dicentang, data pegawai pada periode yang sama diperbarui dengan data dari berkas; bila tidak, baris duplikat dilewati.
        </p>
      </div>

      <div class="flex justify-end">
        <UiButton :disabled="akanDisimpan === 0" :loading="memproses" data-testid="impor-simpan" @click="konfirmasi = true">Simpan ke Database</UiButton>
      </div>
    </section>

    <!-- Hasil -->
    <section v-if="hasil" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5" aria-labelledby="impor-hasil" data-testid="impor-hasil">
      <h2 id="impor-hasil" class="text-h5 text-slate-900">Proses Impor Selesai</h2>
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <UiStatTile label="Baris Baru" :value="hasil.disisip" />
        <UiStatTile label="Diperbarui" :value="hasil.diperbarui" />
        <UiStatTile label="Dilewati" :value="hasil.dilewati" />
        <UiStatTile label="Gagal" :value="hasil.gagal" />
      </div>
      <div v-if="hasil.baris.some((b) => b.galat.length > 0)">
        <h3 class="mb-1 text-body2 font-semibold text-red-700">Rincian baris yang gagal</h3>
        <ul class="list-disc space-y-0.5 pl-5 text-body2 text-red-700" data-testid="impor-hasil-galat">
          <template v-for="b in hasil.baris" :key="`${b.nip}-${b.periode}`">
            <li v-for="(g, i) in b.galat" :key="i">{{ labelGalat(g) }}</li>
          </template>
        </ul>
      </div>
      <UiButton variant="secondary" appearance="outline" data-testid="impor-ulang" @click="ulang">Impor Berkas Lain</UiButton>
    </section>

    <ConfirmDialog
      v-model:open="konfirmasi"
      title="Konfirmasi Impor Gaji"
      :description="`Simpan ${akanDisimpan} baris ke database gaji pegawai? Duplikat: ${pratinjau?.ringkasan.duplikat ?? 0} (${timpa ? 'diperbarui' : 'dilewati'}); baris galat dilewati.`"
      confirm-label="Ya, Simpan ke Database"
      :loading="memproses"
      @confirm="simpan"
    />
  </div>
</template>
