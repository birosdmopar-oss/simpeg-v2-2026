/**
 * Tanpa data contoh di runtime (Sprint 0-2 §2.3.7): tidak ada kode non-test di `src/` yang mengimpor `*.mock` atau
 * fixture `__tests__`. Setara pemeriksaan `grep -rn "\.mock'" frontend/src --include=*.ts --include=*.vue`.
 */
import { describe, expect, it } from 'vitest'

const sources = import.meta.glob<string>(['/src/**/*.ts', '/src/**/*.vue', '!/src/**/__tests__/**'], {
  query: '?raw',
  import: 'default',
  eager: true,
})

describe('tanpa data contoh di runtime', () => {
  it('memindai berkas sumber', () => {
    expect(Object.keys(sources).length).toBeGreaterThan(50)
  })

  it('tidak ada impor *.mock atau __tests__ dari kode runtime', () => {
    const offenders = Object.entries(sources)
      .filter(([, code]) => /from\s+['"][^'"]*(\.mock|__tests__\/)[^'"]*['"]/.test(code))
      .map(([path]) => path)
    expect(offenders).toEqual([])
  })

  it('tidak ada berkas *.mock.ts di luar __tests__', () => {
    expect(Object.keys(sources).filter((path) => /\.mock\.ts$/.test(path))).toEqual([])
  })
})
