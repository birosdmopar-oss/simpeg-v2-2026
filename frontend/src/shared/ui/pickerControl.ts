/**
 * Kelas kontrol input picker (UiDatePicker/UiTimePicker/UiMonthPicker, CR-081).
 *
 * Lewat `UiTextField` picker menerima `controlClass` (gaya field lengkap). Dipakai langsung tanpa `controlClass`
 * (mis. pemilih bulan di header Presensi Saya), picker memakai gaya bawaan yang sama dengan `UiTextField` (h-11) supaya
 * tidak tampil sebagai input polos. Selalu `block w-full min-w-0`: kontrol mengisi wadahnya dan tidak pernah lebih lebar
 * darinya (lebar intrinsik atribut `size` input tidak berlaku).
 */
export function kelasKontrolPicker(controlClass: string | unknown[], invalid: boolean, disabled: boolean): unknown[] {
  const dariPemanggil = Array.isArray(controlClass) ? controlClass.length > 0 : controlClass.trim() !== ''
  if (dariPemanggil) return ['block w-full min-w-0', controlClass, 'pr-11']
  return [
    'block h-11 w-full min-w-0 rounded-lg border bg-white px-3 pr-11 text-body1 text-slate-900 outline-none transition',
    'placeholder:text-slate-400',
    invalid ? 'border-danger' : 'border-slate-300',
    !invalid && !disabled ? 'focus:border-brand-tertiary focus:ring-2 focus:ring-brand-tertiary/25' : '',
    disabled ? 'cursor-not-allowed bg-slate-100 text-slate-400' : '',
  ]
}
