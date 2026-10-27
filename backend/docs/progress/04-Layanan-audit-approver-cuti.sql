-- Kueri audit approver cuti (PRD Fase 4 v1.3 §6.4.10) — BACA-SAJA. Dijalankan reviewer pada salinan data hasil impor.
-- Tujuan: besaran pemakaian "proses atas nama" (T14). Hasil tidak mengubah mekanisme yang sudah diputuskan.
-- Tabel memakai nama tanpa prefix (database produksi/salinan tanpa DBPrefix). Tidak ada INSERT/UPDATE/DELETE.

-- 1) Pejabat calon Atasan Langsung (AL): pemegang jabatan atasan es 4/3/2/1 dan koordinasi di snapshot jabatan.
--    Pemegang aktif = pegawai.status = 1 dan id_jenis_status IN (1, 12). Dikelompokkan menurut keterjangkauan akun.
SELECT
    CASE
        WHEN a.id_pengguna IS NULL THEN 'tanpa akun'
        WHEN a.deleted_at IS NOT NULL THEN 'akun terhapus'
        WHEN a.status <> '1' THEN 'akun nonaktif'
        WHEN a.user_level NOT IN (1, 2, 3, 6, 7) THEN CONCAT('role ', a.user_level, ' (di luar route cuti)')
        ELSE CONCAT('terjangkau (role ', a.user_level, ')')
    END AS keterjangkauan,
    COUNT(DISTINCT pj.nip) AS jumlah_pejabat_al
FROM (
    SELECT id_atasan_es_4 AS id_jabatan FROM pegawai_mutasi_jabatan WHERE id_atasan_es_4 IS NOT NULL
    UNION SELECT id_atasan_es_3 FROM pegawai_mutasi_jabatan WHERE id_atasan_es_3 IS NOT NULL
    UNION SELECT id_atasan_es_2 FROM pegawai_mutasi_jabatan WHERE id_atasan_es_2 IS NOT NULL
    UNION SELECT id_atasan_es_1 FROM pegawai_mutasi_jabatan WHERE id_atasan_es_1 IS NOT NULL
) calon
JOIN pegawai_mutasi_jabatan pj ON pj.id_jabatan = calon.id_jabatan
JOIN pegawai p ON p.nip = pj.nip AND p.status = 1 AND p.id_jenis_status IN (1, 12)
LEFT JOIN pengguna a ON a.nip = p.nip
GROUP BY keterjangkauan
ORDER BY jumlah_pejabat_al DESC;

-- 2) Petugas PyB: aturan pa_layanan jenis 1 (Cuti) berstatus aktif, pejabat per aturan dikelompokkan sama seperti (1).
--    Jabatan struktural (id_jabatan), koordinasi (id_jabatan_koord), atau pegawai tertaut (nip).
SELECT
    CASE
        WHEN a.id_pengguna IS NULL THEN 'tanpa akun'
        WHEN a.deleted_at IS NOT NULL THEN 'akun terhapus'
        WHEN a.status <> '1' THEN 'akun nonaktif'
        WHEN a.user_level NOT IN (1, 2, 3, 6, 7) THEN CONCAT('role ', a.user_level, ' (di luar route cuti)')
        ELSE CONCAT('terjangkau (role ', a.user_level, ')')
    END AS keterjangkauan,
    COUNT(DISTINCT p.nip) AS jumlah_petugas_pyb,
    COUNT(DISTINCT pa.id_pa_layanan) AS jumlah_aturan
FROM pa_layanan pa
LEFT JOIN pegawai_mutasi_jabatan pj
       ON (pa.id_jabatan_koord IS NOT NULL AND pj.id_jabatan_koord = pa.id_jabatan_koord)
       OR (pa.id_jabatan_koord IS NULL AND pa.id_jabatan IS NOT NULL AND pj.id_jabatan = pa.id_jabatan)
LEFT JOIN pegawai p
       ON p.status = 1
      AND (p.nip = pj.nip OR (pa.id_jabatan_koord IS NULL AND pa.id_jabatan IS NULL AND p.nip = pa.nip))
LEFT JOIN pengguna a ON a.nip = p.nip
WHERE pa.id_jenis_pa = 1 AND pa.status = 1
GROUP BY keterjangkauan
ORDER BY jumlah_aturan DESC;

-- 3) Pegawai eselon I aktif (AL/PyB = Menteri tanpa NIP; seluruhnya lewat T14).
SELECT COUNT(*) AS jumlah_pegawai_eselon_i
FROM pegawai_mutasi_jabatan pj
JOIN pegawai p ON p.nip = pj.nip AND p.status = 1 AND p.id_jenis_status IN (1, 12)
WHERE pj.id_sub_group_jabatan = 1;

-- 4) Rincian role akun pejabat AL/PyB di luar route cuti (role 4, 5, 8) bila ada: untuk daftar tindak lanjut.
SELECT a.user_level, COUNT(*) AS jumlah_akun
FROM pengguna a
JOIN pegawai p ON p.nip = a.nip AND p.status = 1
WHERE a.user_level IN (4, 5, 8) AND a.deleted_at IS NULL
GROUP BY a.user_level;
