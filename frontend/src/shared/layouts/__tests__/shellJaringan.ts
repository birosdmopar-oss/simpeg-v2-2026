/**
 * Helper test bersama (bukan spec, CR-135 R1): jaringan hermetik untuk spec yang me-mount `RedesignShell` tanpa menguji
 * request host shell-nya. Sejak ACTIVE_PHASE 8 shell memanggil `notifikasi/ringkasan`, `halo-simpeg/tiket/ringkasan`, dan
 * (role cuti) `cuti/pengingat` lewat klien `api` asli. Di mesin dev request itu sampai ke backend :8080, di CI gagal
 * ECONNREFUSED. `adapterPenolakShell` menolak setiap request dengan 503 (host shell diam saat gagal) dan mencatat path-nya.
 *
 * Pakai di spec:
 *   vi.mock('@/lib/axios', async (asli) => {
 *     const a = await asli<typeof import('@/lib/axios')>()
 *     const { adapterPenolakShell } = await import('@/shared/layouts/__tests__/shellJaringan')
 *     return { ...a, api: a.createApiClient({ adapter: adapterPenolakShell }) }
 *   })
 * lalu `resetShellGlobal()` (dari `shellReset.ts`) di afterEach, plus stub host yang tidak diuji (`STUB_HOST_SHELL`).
 * Berkas ini sengaja HANYA mengimpor `axios`: ia dimuat dari dalam factory `vi.mock('@/lib/axios')`, sehingga impor yang
 * (transitif) memuat `@/lib/axios` di sini akan membuat impor melingkar yang menggantung.
 */
import { AxiosError, AxiosHeaders, type InternalAxiosRequestConfig } from 'axios'

export const requestShell: string[] = []

export function adapterPenolakShell(config: InternalAxiosRequestConfig): Promise<never> {
  requestShell.push(`${String(config.method)} ${config.url ?? ''}`)
  const response = { status: 503, statusText: 'tiruan', headers: new AxiosHeaders(), config, data: { status: 'error', message: 'tiruan' } }
  return Promise.reject(new AxiosError('tiruan', 'ERR_BAD_RESPONSE', config, null, response))
}

/** Host shell yang memanggil API sendiri / tampil sebagai dialog; di-stub bila spec tidak mengujinya. */
export const STUB_HOST_SHELL = { InteraksiPopupHost: true, VanyaWidget: true, NotifikasiPushShell: true, PengingatCutiShell: true } as const
