/**
 * Setup global Vitest (CR-135). Sejak ACTIVE_PHASE 8, `RedesignShell` memasang host interaksi form digital/SKL/survei
 * (`InteraksiPopupHost`, FASE_INTERAKSI 7) yang langsung memanggil `form-digital/saya` + `survei-layanan/saya` dan menulis
 * `console.warn` bila gagal. Di spec halaman yang memakai shell tanpa mock API, request nyata itu gagal (ERR_NETWORK) sesudah
 * test selesai dan log-nya memicu "EnvironmentTeardownError: Closing rpc while onUserConsoleLog was pending" di CI.
 * Karena itu host ini di-stub secara bawaan; spec yang memang menguji host di dalam shell memasang ulang komponen aslinya
 * dengan `global: { stubs: { InteraksiPopupHost: false } }`. Mount langsung `InteraksiPopupHost` (komponen akar) tidak terpengaruh.
 */
import { config } from '@vue/test-utils'

config.global.stubs = { ...config.global.stubs, InteraksiPopupHost: true }
