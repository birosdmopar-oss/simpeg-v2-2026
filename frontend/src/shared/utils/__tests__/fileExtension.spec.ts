/**
 * fileExtension / fileExtensionError (CR-055 Q4R2-I2): ekstensi = sesudah titik terakhir (seperti PHP `pathinfo` di
 * `ValidasiBerkas` server), kosong untuk nama tanpa titik atau berakhiran titik; nama tanpa ekstensi diberi pesan jelas.
 */
import { describe, expect, it } from 'vitest'

import { fileExtension, fileExtensionError } from '../fileExtension'

describe('fileExtension', () => {
  it.each([
    ['sk.pdf', 'pdf'],
    ['SK.Final.PDF', 'pdf'],
    ['arsip.tar.gz', 'gz'],
    ['sk', ''],
    ['sk.', ''],
    ['.pdf', 'pdf'],
  ])('%s → "%s"', (nama, ext) => {
    expect(fileExtension(nama)).toBe(ext)
  })
})

describe('fileExtensionError', () => {
  it('ekstensi diperbolehkan → null; lainnya → pesan jenis', () => {
    expect(fileExtensionError('sk.PDF', ['pdf', 'jpg'])).toBeNull()
    expect(fileExtensionError('virus.exe', ['pdf'])).toBe('Jenis file .exe tidak diperbolehkan.')
  })

  it('nama tanpa ekstensi ("sk", "sk.") → pesan tanpa ekstensi, bukan ".sk"/".?"', () => {
    const pesan = 'Nama berkas tidak memiliki ekstensi. Gunakan berkas berekstensi .pdf, .jpg.'
    expect(fileExtensionError('sk', ['pdf', 'jpg'])).toBe(pesan)
    expect(fileExtensionError('sk.', ['pdf', 'jpg'])).toBe(pesan)
  })
})
