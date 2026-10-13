<script setup lang="ts">
/**
 * Form generik riwayat: dirender dari FieldDef (dua kolom, label di atas, tanda (*) merah), divalidasi skema Zod
 * dinamis lewat VeeValidate (ADR-026). Nama isian = kolom DDL.
 *
 * - Isian `ref` memuat opsi master lewat `useCascadeOptions` (riwayatCascade.ts): isian ber-`dependsOn` berjenjang —
 *   mengganti induk mengosongkan dan memuat ulang turunannya.
 * - Isian ber-`showWhen` hanya tampil (dan hanya wajib) bila syaratnya terpenuhi; saat kirim, isian tersembunyi
 *   dikirim kosong (= NULL).
 * - Isian ber-`hint` (isian hitung) menampilkan petunjuk, mis. "dikosongkan = dihitung otomatis"; isian ber-`resetOn`
 *   dikosongkan saat pengguna mengubah isian pemicunya (dihitung ulang server kecuali diisi lagi).
 *
 * Lampiran: satu isian berkas per kode `jenis_rwy` (`berkas[<id_riwayat>]`). Lampiran ber-`wajib` harus dipilih saat
 * TAMBAH, dan saat UBAH bila baris itu belum punya lampiran kode tersebut (`lampiranTersimpan[<kode>] === 0`, ikut legacy
 * `vp()`); selain itu berkas saat ubah bersifat tambahan. Server tetap yang menegakkan (422 `berkas.<id>`).
 * Galat server 422 (`errors.<kolom>`, `errors["berkas.<id>"]`) diteruskan lewat prop `serverErrors`.
 *
 * Isian `multiselect` (MAKE-018): kotak ceklis opsi master (riwayatCascade), wajib = minimal satu, nilai dikirim lewat
 * argumen KETIGA event `submit` (`jamak: Record<kolom, string[]>`) supaya handler dua-argumen yang sudah ada tidak
 * berubah tipe. Saat Edit, `loadFrom` mencocokkan teks kolom baris ke label opsi; potongan yang tidak cocok ditampilkan
 * sebagai catatan (tidak dibuang diam-diam).
 */
import { toTypedSchema } from '@vee-validate/zod'
import { useForm } from 'vee-validate'
import { onMounted, reactive, ref, type Ref, watch } from 'vue'

import FormField from '@/shared/components/FormField.vue'
import { UiButton, UiCheckbox, UiFileField, UiSelect, UiTextField } from '@/shared/ui'

import type { RiwayatValidationErrors } from '../services/apiErrors'

import { fieldVisible, fieldWajib, type BerkasKolomDef, type FieldDef, type LampiranDef, type PratinjauDef } from './riwayat.config'
import { buildRiwayatSchema } from './riwayat.schema'
import type { BerkasMap } from './riwayat.service'
import { useRiwayatRefOptions } from './riwayatCascade'

const props = withDefaults(
  defineProps<{
    fields: FieldDef[]
    lampiran?: LampiranDef[]
    mode?: 'create' | 'edit'
    initial?: Record<string, string>
    /** Pratinjau isian turunan (mode tambah, MAKE-017): definisi + pemuat (nip/jenis diikat pemanggil). */
    pratinjau?: { def: PratinjauDef; muat: (query: Record<string, string>) => Promise<Record<string, unknown>> } | null
    /** Baris yang diedit (mode edit) — diteruskan ke `compute` (mis. `jenis_ak` AK). */
    baris?: Readonly<Record<string, unknown>> | null
    /** Jumlah lampiran yang sudah tersimpan per kode (mode edit); kode tanpa entri = tidak diketahui. */
    lampiranTersimpan?: Record<number, number>
    /** Berkas kolom Definisi (`berkas[<kolom>]`, MAKE-008). */
    berkasKolom?: BerkasKolomDef[]
    /** Kolom berkas yang sudah terisi di baris (mode edit) — tidak diwajibkan lagi; pilihan baru MENGGANTI. */
    kolomTerisi?: Record<string, boolean>
    serverErrors?: RiwayatValidationErrors | null
    busy?: boolean
    submitLabel?: string
    cancelLabel?: string
    /** Awalan data-testid agar beberapa form di satu halaman tidak bentrok. */
    testid?: string
  }>(),
  {
    lampiran: () => [],
    mode: 'create',
    initial: () => ({}),
    baris: null,
    pratinjau: null,
    lampiranTersimpan: () => ({}),
    berkasKolom: () => [],
    kolomTerisi: () => ({}),
    serverErrors: null,
    busy: false,
    submitLabel: 'Simpan',
    cancelLabel: 'Batal',
    testid: 'riwayat',
  },
)

const emit = defineEmits<{ submit: [values: Record<string, string>, berkas: BerkasMap, jamak: Record<string, string[]>]; cancel: [] }>()

const blank = (): Record<string, string> => Object.fromEntries(props.fields.map((f) => [f.name, props.initial[f.name] ?? '']))

const { errors, values, handleSubmit, resetForm, defineField, setFieldError } = useForm<Record<string, string>>({
  validationSchema: toTypedSchema(buildRiwayatSchema(props.fields)),
  initialValues: blank(),
})

const models: Record<string, Ref<string>> = {}
for (const f of props.fields) if (f.type !== 'multiselect') models[f.name] = defineField(f.name)[0] as Ref<string>

// Isian multiselect (MAKE-018): di luar skema VeeValidate; galat & catatan pencocokan dikelola sendiri.
const jamakFields = props.fields.filter((f) => f.type === 'multiselect')
const jamak = reactive<Record<string, string[]>>({})
const galatJamak = reactive<Record<string, string>>({})
const catatanJamak = reactive<Record<string, string[]>>({})

/** Nilai awal multiselect dari baris (array ID) atau, dengan `loadFrom`, dari teks kolom yang dicocokkan ke opsi. */
function isiJamakAwal(): void {
  for (const f of jamakFields) {
    galatJamak[f.name] = ''
    catatanJamak[f.name] = []
    const asli = props.baris?.[f.name]
    if (Array.isArray(asli)) {
      jamak[f.name] = asli.map(String)
      continue
    }
    jamak[f.name] = []
    if (!f.loadFrom || !props.baris) continue
    const potongan = String(props.baris[f.loadFrom.kolom] ?? '')
      .split(f.loadFrom.pemisah)
      .map((p) => p.trim())
      .filter((p) => p !== '')
    const opsi = refs.options(f.name)
    const kunci = (s: string): string => s.trim().toLowerCase()
    for (const p of potongan) {
      const cocok = opsi.find((o) => kunci(o.label) === kunci(p))
      if (cocok) jamak[f.name]?.push(cocok.value)
      else catatanJamak[f.name]?.push(p)
    }
  }
}

function toggleJamak(f: FieldDef, value: string, pilih: boolean): void {
  const kini = jamak[f.name] ?? []
  jamak[f.name] = pilih ? [...kini.filter((v) => v !== value), value] : kini.filter((v) => v !== value)
  galatJamak[f.name] = ''
  onEdited(f.name)
}

/** id pesan galat / petunjuk / catatan multiselect yang sedang tampil, untuk aria-describedby ('' = tidak ada). */
function keteranganJamak(f: FieldDef): string {
  const ids = [fieldError(f.name) ? `${props.testid}-${f.name}-galat` : f.hint ? `${props.testid}-${f.name}-hint` : '']
  if (catatanJamak[f.name]?.length) ids.push(`${props.testid}-${f.name}-catatan`)
  return ids.filter(Boolean).join(' ')
}

/** Wajib multiselect (minimal satu) atas isian yang tampil; galat dipasang per isian. */
function jamakKurang(): boolean {
  let kurang = false
  for (const f of jamakFields) {
    if (wajib(f) && visible(f) && (jamak[f.name] ?? []).length === 0) {
      galatJamak[f.name] = `${f.label} wajib dipilih minimal satu`
      kurang = true
    }
  }
  return kurang
}

const berkas = reactive<BerkasMap>({})
const berkasError = reactive<Record<number | string, string>>({})

const refs = useRiwayatRefOptions(props.fields)
onMounted(() => void refs.init(blank()).then(isiJamakAwal))

watch(
  () => props.initial,
  () => {
    resetForm({ values: blank() })
    disentuh.clear()
    for (const key of Object.keys(berkas)) delete berkas[Number(key)]
    for (const key of Object.keys(berkasError)) delete berkasError[Number(key)]
    void refs.init(blank()).then(isiJamakAwal)
  },
)

/** Isian yang pernah diubah pengguna (isi otomatis pratinjau tidak menyentuhnya lagi). */
const disentuh = new Set<string>()

/**
 * Sesudah pengguna mengubah isian $name: isian hitung ber-`resetOn` pemicu itu dikosongkan (dihitung ulang server), dan
 * isian ref ber-`optionsFilter` dari isian itu dimuat ulang lalu dikosongkan.
 */
function onEdited(name: string): void {
  disentuh.add(name)
  for (const g of props.fields) {
    const model = models[g.name]
    if (model && g.resetOn?.includes(name)) model.value = ''
  }
  // Dikosongkan sinkron sebelum opsi baru dimuat (respons lama tidak menimpa — token per isian di riwayatCascade).
  for (const n of refs.pemicu(name, values as Record<string, string>)) {
    const model = models[n]
    if (model) model.value = ''
    if (n in jamak) jamak[n] = []
  }
}

async function onRefChange(f: FieldDef, value: string): Promise<void> {
  const model = models[f.name]
  if (model) model.value = value
  onEdited(f.name)
  for (const name of await refs.select(f.name, value)) {
    const turunan = models[name]
    if (turunan) turunan.value = ''
    if (name in jamak) jamak[name] = []
    onEdited(name)
  }
}

const visible = (f: FieldDef): boolean => fieldVisible(f, values as Record<string, string | undefined>)
/** Tanda wajib (*) isian: `required`, atau `requiredWhen` yang terpenuhi untuk nilai form saat ini. */
const wajib = (f: FieldDef): boolean => fieldWajib(f, values as Record<string, string | undefined>)

// Isian hitung (`compute`): diperbarui setiap kali isian lain berubah; hanya-baca selama hasilnya bukan null. Saat
// Edit, nilai tersimpan baris tidak ditimpa sampai pengguna mengubah isian (baris legacy, mis. kategori SKP lama).
const hasilHitung = (f: FieldDef, vals: Record<string, string>): string | null => f.compute?.(vals, props.baris ?? null) ?? null
const terkunciHitung = (f: FieldDef): boolean => Boolean(f.compute) && hasilHitung(f, values as Record<string, string>) !== null
const samaAwal = (vals: Record<string, string>): boolean => props.fields.every((f) => (vals[f.name] ?? '') === (props.initial[f.name] ?? ''))
const hitung = props.fields.filter((f) => f.compute)
if (hitung.length) {
  watch(
    () => ({ ...values }) as Record<string, string>,
    (sekarang) => {
      if (props.mode === 'edit' && samaAwal(sekarang)) return
      for (const f of hitung) {
        const model = models[f.name]
        const hasil = hasilHitung(f, sekarang)
        if (model && hasil !== null && model.value !== hasil) model.value = hasil
      }
    },
    { deep: true, immediate: true },
  )
}

// Pratinjau isian turunan (MAKE-017): dimuat saat form tambah dibuka dan saat isian query berubah; respons lama
// diabaikan (token). Isian `isiOtomatis` diisi selama belum pernah diubah pengguna (setelah itu tidak ditimpa lagi).
const hasilPratinjau = ref<Record<string, unknown> | null>(null)
const galatPratinjau = ref('')
let tokenPratinjau = 0
async function muatPratinjau(vals: Record<string, string>): Promise<void> {
  const p = props.pratinjau
  if (!p || props.mode !== 'create') return
  const t = ++tokenPratinjau
  try {
    const hasil = await p.muat(Object.fromEntries(p.def.query.map((k) => [k, vals[k] ?? ''])))
    if (t !== tokenPratinjau) return
    hasilPratinjau.value = hasil
    galatPratinjau.value = ''
    for (const [field, kunci] of Object.entries(p.def.isiOtomatis)) {
      const model = models[field]
      const nilai = hasil[kunci]
      if (model && !disentuh.has(field) && nilai !== null && nilai !== undefined) model.value = String(nilai)
    }
  } catch {
    if (t === tokenPratinjau) galatPratinjau.value = 'Data pegawai untuk isian otomatis tidak dapat dimuat; isian tetap diisi server saat simpan.'
  }
}
if (props.pratinjau) {
  watch(
    () => (props.pratinjau?.def.query ?? []).map((k) => (values as Record<string, string>)[k] ?? '').join('|'),
    () => void muatPratinjau(values as Record<string, string>),
    { immediate: true },
  )
}

/** Batas atas angka (`batasAtas`) atas isian yang tampil; galat dipasang di isiannya. */
function lewatBatas(submitted: Record<string, string>): boolean {
  let lewat = false
  for (const f of props.fields) {
    const maks = f.batasAtas?.(submitted) ?? null
    const teks = String(submitted[f.name] ?? '').trim()
    if (maks === null || teks === '' || !visible(f)) continue
    if (Number(teks.replace(',', '.')) > maks) {
      setFieldError(f.name, `${f.label} maksimal ${maks}.`)
      lewat = true
    }
  }
  return lewat
}

function onFile(def: LampiranDef, file: File | null): void {
  delete berkasError[def.id_riwayat]
  if (file) berkas[def.id_riwayat] = file
  else delete berkas[def.id_riwayat]
}

const fileRequired = (def: LampiranDef): boolean =>
  def.wajib &&
  (!def.wajibBila || fieldVisible({ name: '', label: '', type: 'text', showWhen: def.wajibBila }, values as Record<string, string | undefined>)) &&
  (props.mode === 'create' || props.lampiranTersimpan[def.id_riwayat] === 0)

const kolomWajib = (def: BerkasKolomDef): boolean =>
  def.wajib &&
  (!def.wajibBila || fieldVisible({ name: '', label: '', type: 'text', showWhen: def.wajibBila }, values as Record<string, string | undefined>)) &&
  !props.kolomTerisi[def.kolom]

function onFileKolom(def: BerkasKolomDef, file: File | null): void {
  delete berkasError[def.kolom]
  if (file) berkas[def.kolom] = file
  else delete berkas[def.kolom]
}

function missingWajib(): boolean {
  let missing = false
  for (const def of props.berkasKolom) {
    if (kolomWajib(def) && !berkas[def.kolom]) {
      berkasError[def.kolom] = `${def.label} wajib diunggah.`
      missing = true
    }
  }
  for (const def of props.lampiran) {
    if (fileRequired(def) && !berkas[def.id_riwayat]) {
      berkasError[def.id_riwayat] = `${def.label} wajib diunggah.`
      missing = true
    }
  }
  return missing
}

const submit = handleSubmit(
  (submitted) => {
    const lewat = lewatBatas(submitted)
    const kurang = jamakKurang()
    if (missingWajib() || lewat || kurang) return
    // Isian bersyarat yang tersembunyi dikirim kosong (= NULL), bukan nilai lama yang tak terlihat.
    const out = Object.fromEntries(props.fields.filter((f) => f.type !== 'multiselect').map((f) => [f.name, visible(f) ? (submitted[f.name] ?? '') : '']))
    const pilihan = Object.fromEntries(jamakFields.map((f) => [f.name, visible(f) ? [...(jamak[f.name] ?? [])] : []]))
    emit('submit', out, { ...berkas }, pilihan)
  },
  () => {
    lewatBatas(values as Record<string, string>)
    jamakKurang()
    missingWajib()
  },
)

function cancel(): void {
  resetForm({ values: blank() })
  emit('cancel')
}

const fieldError = (name: string): string => galatJamak[name] || (errors.value[name] ?? props.serverErrors?.fields[name] ?? '')
const fileError = (id: number): string => berkasError[id] ?? props.serverErrors?.berkas[id] ?? ''
const helpText = (f: FieldDef): string => fieldError(f.name) || f.hint || ''
</script>

<template>
  <form novalidate :data-testid="`${testid}-form`" @submit.prevent="submit">
    <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
      <template v-for="f in fields" :key="f.name">
        <div v-if="visible(f)" :class="f.wide ? 'md:col-span-2' : ''">
          <FormField
            v-if="f.type === 'textarea'"
            v-model="models[f.name].value"
            type="textarea"
            :name="f.name"
            :label="f.label"
            :required="wajib(f)"
            :disabled="busy"
            :error="fieldError(f.name)"
            :hint="f.hint"
            :placeholder="f.placeholder"
            :data-field="f.name"
          />
          <UiSelect
            v-else-if="f.type === 'ref'"
            :model-value="models[f.name].value"
            :label="f.label"
            :required="wajib(f)"
            :disabled="busy"
            :options="refs.options(f.name)"
            :placeholder="`Pilih ${f.label.toLowerCase()}...`"
            :clearable="!f.required"
            :state="fieldError(f.name) ? 'error' : 'default'"
            :help-text="helpText(f)"
            :data-field="f.name"
            @update:model-value="(v) => onRefChange(f, v)"
          />
          <fieldset v-else-if="f.type === 'multiselect'" :data-field="f.name" :aria-describedby="keteranganJamak(f) || undefined">
            <legend class="mb-2 text-body2 font-medium text-slate-700">
              {{ f.label }}<span v-if="wajib(f)" class="text-danger" aria-hidden="true"> *</span>
            </legend>
            <div class="grid gap-2 sm:grid-cols-2">
              <span v-for="o in refs.options(f.name)" :key="o.value" :data-opsi="o.value">
                <UiCheckbox
                  :model-value="(jamak[f.name] ?? []).includes(o.value)"
                  :label="o.label"
                  :disabled="busy"
                  :invalid="Boolean(fieldError(f.name))"
                  :described-by="keteranganJamak(f) || undefined"
                  @update:model-value="(v) => toggleJamak(f, o.value, v)"
                />
              </span>
            </div>
            <p v-if="refs.options(f.name).length === 0" class="text-body2 text-slate-500">Belum ada pilihan.</p>
            <p v-if="fieldError(f.name)" :id="`${testid}-${f.name}-galat`" role="alert" class="mt-1 text-caption text-danger">{{ fieldError(f.name) }}</p>
            <p v-else-if="f.hint" :id="`${testid}-${f.name}-hint`" class="mt-1 text-caption text-slate-500">{{ f.hint }}</p>
            <p
              v-if="catatanJamak[f.name]?.length"
              :id="`${testid}-${f.name}-catatan`"
              class="mt-1 text-caption text-warning"
              :data-testid="`${testid}-catatan-${f.name}`"
            >
              Tidak ditemukan di pilihan, pilih ulang bila perlu: {{ catatanJamak[f.name]?.join(', ') }}
            </p>
          </fieldset>
          <UiSelect
            v-else-if="f.type === 'select'"
            v-model="models[f.name].value"
            :label="f.label"
            :required="wajib(f)"
            :disabled="busy"
            :options="f.options ?? []"
            :placeholder="`Pilih ${f.label.toLowerCase()}...`"
            :clearable="!f.required"
            :state="fieldError(f.name) ? 'error' : 'default'"
            :help-text="helpText(f)"
            :data-field="f.name"
            @update:model-value="onEdited(f.name)"
          />
          <UiTextField
            v-else
            v-model="models[f.name].value"
            :list="f.suggestEntity ? `${testid}-saran-${f.name}` : undefined"
            :label="f.label"
            :required="wajib(f)"
            :disabled="busy"
            :readonly="terkunciHitung(f)"
            :type="f.type === 'date' ? 'date' : 'text'"
            :inputmode="f.type === 'number' ? 'numeric' : undefined"
            :placeholder="f.placeholder"
            :state="fieldError(f.name) ? 'error' : 'default'"
            :help-text="helpText(f)"
            :data-field="f.name"
            @update:model-value="onEdited(f.name)"
          />
          <datalist v-if="f.suggestEntity" :id="`${testid}-saran-${f.name}`">
            <option v-for="s in refs.saran(f.name)" :key="s" :value="s" />
          </datalist>
        </div>
      </template>
    </div>

    <section v-if="pratinjau && mode === 'create'" class="mt-6 border-t border-slate-200 pt-5" :data-testid="`${testid}-pratinjau`">
      <h3 class="mb-3 text-body1 font-semibold text-slate-900">Diisi otomatis dari data pegawai</h3>
      <p v-if="galatPratinjau" role="alert" class="text-body2 text-danger" :data-testid="`${testid}-pratinjau-galat`">{{ galatPratinjau }}</p>
      <dl v-else-if="hasilPratinjau" class="grid gap-x-6 gap-y-2 text-body2 md:grid-cols-2">
        <div v-for="t in pratinjau.def.tampil" :key="t.label">
          <dt class="text-slate-500">{{ t.label }}</dt>
          <dd class="text-slate-900">{{ t.nilai(hasilPratinjau) || '—' }}</dd>
        </div>
      </dl>
      <p v-else class="text-body2 text-slate-500">Memuat…</p>
    </section>

    <fieldset v-if="lampiran.length" class="mt-6 border-t border-slate-200 pt-5">
      <legend class="mb-3 text-body1 font-semibold text-slate-900">Lampiran</legend>
      <p v-if="mode === 'edit'" class="mb-3 text-body2 text-slate-500">Berkas yang dipilih ditambahkan ke lampiran data ini.</p>
      <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
        <div v-for="def in lampiran" :key="def.id_riwayat" :data-testid="`${testid}-berkas-${def.id_riwayat}`">
          <UiFileField
            :label="fileRequired(def) ? `${def.label} *` : def.label"
            :allowed="def.ekstensi"
            :max-mb="def.batas_mb"
            :disabled="busy"
            @change="(file) => onFile(def, file)"
          />
          <p v-if="fileError(def.id_riwayat)" role="alert" class="mt-1 text-caption text-danger" :data-testid="`${testid}-berkas-error-${def.id_riwayat}`">
            {{ fileError(def.id_riwayat) }}
          </p>
        </div>
      </div>
    </fieldset>

    <fieldset v-if="berkasKolom.length" class="mt-6 border-t border-slate-200 pt-5">
      <legend class="mb-3 text-body1 font-semibold text-slate-900">Berkas</legend>
      <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
        <div v-for="def in berkasKolom" :key="def.kolom" :data-testid="`${testid}-berkas-${def.kolom}`">
          <UiFileField
            :label="kolomWajib(def) ? `${def.label} *` : def.label"
            :allowed="def.ekstensi ?? ['pdf']"
            :max-mb="def.batas_mb ?? 5"
            :disabled="busy"
            @change="(file) => onFileKolom(def, file)"
          />
          <p v-if="kolomTerisi[def.kolom]" class="mt-1 text-caption text-slate-500">Sudah ada; pilih berkas untuk mengganti.</p>
          <p
            v-if="berkasError[def.kolom] || serverErrors?.fields[`berkas.${def.kolom}`]"
            role="alert"
            class="mt-1 text-caption text-danger"
            :data-testid="`${testid}-berkas-error-${def.kolom}`"
          >
            {{ berkasError[def.kolom] || serverErrors?.fields[`berkas.${def.kolom}`] }}
          </p>
        </div>
      </div>
    </fieldset>

    <ul v-if="serverErrors?.general.length" role="alert" class="mt-5 space-y-1 rounded-lg bg-danger-soft px-3 py-2 text-body2 text-danger" :data-testid="`${testid}-general-error`">
      <li v-for="message in serverErrors.general" :key="message">{{ message }}</li>
    </ul>

    <div class="mt-6 flex flex-wrap items-center justify-end gap-3">
      <UiButton v-if="cancelLabel" variant="secondary" appearance="soft" :disabled="busy" :data-testid="`${testid}-cancel`" @click="cancel">{{ cancelLabel }}</UiButton>
      <UiButton type="submit" :loading="busy" :data-testid="`${testid}-save`">{{ submitLabel }}</UiButton>
    </div>
  </form>
</template>
