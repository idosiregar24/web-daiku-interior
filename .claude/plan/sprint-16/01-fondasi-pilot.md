# Sprint 16 · 01 — Fondasi & Pilot

> Induk: [`../sprint-16-penanda-wajib.md`](../sprint-16-penanda-wajib.md) · Keputusan: K1, K3
> Status: **selesai 2026-10-07**

## Rancangan
```tsx
// Components/shared/RequiredMark.tsx
export default function RequiredMark() {
    return (
        <>
            <span aria-hidden="true" className="-ms-1 text-error-ink">*</span>
            <span className="sr-only">(wajib)</span>
        </>
    );
}
```
- `Label` shadcn memakai `flex items-center gap-2`, jadi tanpa `-ms-1`
  bintang berjarak 8px dari teks. Atur sampai jaraknya ±2px dan cek
  visualnya. Saat `FormLabel` error (`text-destructive`), bintang tetap
  `text-error-ink`.
- `FormLabel`: `({ className, required, children, ...props })` lalu
  `{children}{required && <RequiredMark />}`. Tipe props:
  `React.ComponentProps<typeof Label> & { required?: boolean }`.
- `InputLabel`: prop `required?: boolean`, perilakunya sama.
- `ui/label.tsx` **tidak diubah** (K1/§4 induk).

## Checklist
- [x] **[UI]** `Components/shared/RequiredMark.tsx`
- [x] **[UI]** `FormLabel` prop `required` (`Components/ui/form.tsx`)
- [x] **[UI]** `InputLabel` prop `required` (`Components/InputLabel.tsx`)
- [x] **[Pilot]** `Components/modules/crm/LeadFormDialog.tsx`: bintang di
      Nama Klien, Kontak, Sumber, Prioritas, PIC Marketing (ikut
      `StoreLeadRequest` / `UpdateLeadRequest`). Cek di browser.
- [x] **[Docs]** `rules/design-standards.md` §2: baris tabel "Penanda kolom
      wajib → `<FormLabel required>` / `<RequiredMark />`", ditambah aturan
      K2/K4/K6–K8 secara singkat
- [x] **[Docs]** `rules/frontend-standards.md` §3: "label wajib = `required`
      sesuai Form Request; jangan tulis '(opsional)'"
- [x] **[Docs]** skill `front-end-design` dan `laravel-inertia-module`
      (langkah halaman/form): sebut `required` pada `FormLabel`
- [x] **[Build]** `npm run build` lulus

## Catatan pelaksanaan (2026-10-07)
- `RequiredMark` (export bernama) memakai varian Tailwind v4
  `in-data-[slot=label]` / `in-data-[slot=form-label]` untuk menarik bintang
  ke teks di label shadcn yang `flex gap-2` — pemanggil tidak perlu memberi
  margin sendiri. `InputLabel` (block) memberi spasi biasa.
- `FormLabel` & `InputLabel` mendapat prop `required?: boolean`;
  `ui/label.tsx` tidak diubah.
- Pilot `LeadFormDialog`: Nama Klien, Kontak, Sumber, Prioritas, PIC
  Marketing — Store & Update Request sama. (Kontak dirombak lagi di Sub 07.)
