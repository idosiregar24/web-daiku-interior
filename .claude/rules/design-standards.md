# Design Standards — Daiku Interior Design System

Sumber kebenaran visual: PRD §8 (UI/UX Design System). Dokumen ini
menjelaskan cara pakainya di kode nyata (Tailwind v4 + shadcn/ui, bukan
Tailwind v3 seperti draft awal PRD — lihat `.claude/plan/README.md`
"Catatan penyesuaian").

## 1. Token warna — jangan tulis hex literal

Semua token ada di `resources/css/app.css`, dua blok:

```css
@theme inline { /* semantic tokens shadcn: --color-primary, --color-border, dst */ }
@theme        { /* palette Daiku mentah: --color-daiku-yellow, --color-daiku-dark, dst */ }
```

`--primary`, `--ring`, `--sidebar-primary` di `:root` **sudah** di-set ke
Daiku Yellow (`#F5C518`) — komponen shadcn default (`<Button>` tanpa
`variant`, focus ring, dsb) otomatis kebrand, tidak perlu override manual.

Pakai utility Tailwind, bukan hex:

| Kebutuhan | Class | JANGAN |
|---|---|---|
| Background utama | `bg-background` | `bg-white` / `bg-[#FFFFFF]` |
| Teks utama | `text-foreground` | `text-[#1A1A1A]` |
| Aksen kuning Daiku | `bg-daiku-yellow`, `text-daiku-yellow-dark` | `bg-[#F5C518]` |
| Border card/table | `border-border` atau `border-daiku-border` | `border-gray-200` |
| Background section abu | `bg-daiku-gray` | `bg-gray-100` |
| Teks sekunder/muted | `text-muted-foreground` atau `text-daiku-muted` | `text-gray-500` |
| Warna status sukses/warn/error/info | `text-success`/`bg-warning`/dst (lihat §3) | class Tailwind default (`text-green-500`) |

## 2. Komponen — shadcn dulu, custom belakangan

`resources/js/Components/ui/` adalah primitive shadcn (preset **Radix +
Nova**, style `radix-nova` — lihat `components.json`). Tambah komponen baru
dengan CLI, bukan tulis manual, supaya konsisten dengan alias project:

```bash
npx shadcn@latest add <component> --yes --overwrite
```

> ⚠️ CLI ini kadang generate import `@/components/ui/...` (huruf kecil).
> Proyek pakai folder **`Components`** (huruf besar, ikut konvensi Breeze —
> lihat `components.json` aliases). Cek & perbaiki casing import sebelum
> commit, atau build TypeScript akan gagal
> (`forceConsistentCasingInFileNames`).

Komponen gabungan modul-spesifik (`StatusChip`, `DataTable` + TanStack,
`PageHeader`, `DatePicker`) masuk ke:

- `resources/js/Components/shared/` — dipakai lintas modul.
- `resources/js/Components/modules/` — spesifik satu modul (mis. Kanban
  board Task, funnel chart CRM).

## 3. Status badge / chip

PRD §8.3: "Status Badge: Chip berwarna sesuai status". Mapping warna →
status mengikuti token `success`/`warning`/`error`/`info` (§8.1 PRD), bukan
warna Tailwind default:

```tsx
const STATUS_COLOR: Record<string, string> = {
  DONE: 'bg-success/10 text-success',
  APPROVED: 'bg-success/10 text-success',
  PENDING: 'bg-daiku-gray text-daiku-muted',
  OVER: 'bg-error/10 text-error',
  REJECTED: 'bg-error/10 text-error',
  ONPROGRESS: 'bg-info/10 text-info',
  WARNING: 'bg-warning/10 text-warning', // delay, follow-up jatuh tempo
};
```

Bangun ini sebagai komponen `<StatusChip status="..." />` (CSV Sprint 1),
jangan duplikasi mapping ini di tiap halaman.

## 4. Layout

- Halaman berautentikasi selalu dibungkus `Layouts/AppLayout.tsx` (sidebar
  fixed kiri dikelompokkan per divisi + topbar). Tambah entri modul baru ke
  `NAV_GROUPS` di file itu begitu route-nya siap — lihat komentar di file
  tsb.
- Topbar pakai **breadcrumb** (PRD §8.3), bukan judul teks polos — kirim
  prop `breadcrumbs={[{ label: '...', routeName: '...' }, { label: '...' }]}`
  ke `<AppLayout>` (entry terakhir tanpa `routeName` = halaman saat ini,
  tidak bisa diklik). Prop `header` lama masih didukung sebagai fallback
  kalau `breadcrumbs` tidak dikirim, tapi jangan pakai untuk halaman baru.
- Halaman auth (login/register/dst) pakai `Layouts/AuthLayout.tsx`
  (background `bg-daiku-cream`, card terpusat).
- Card: `<Card>` shadcn (sudah `shadow-sm rounded-lg border`) — jangan
  bungkus manual dengan div + class Tailwind mentah.
- Modal/Dialog: `<Dialog>` shadcn, `max-w-lg` default sesuai PRD §8.3.
- Tabel: header pakai `bg-daiku-yellow-light` sesuai PRD §8.3 — override
  `<TableHeader>` per instance kalau shadcn default tidak sesuai, jangan
  ubah primitive `table.tsx` global.

## 5. Tipografi & ikon

- Font: Geist Variable (self-hosted via `@fontsource-variable/geist`,
  bukan Google/Bunny Fonts — lihat `resources/css/app.css`). Jangan
  tambahkan `<link>` font eksternal lain di `app.blade.php`.
- Ikon: `lucide-react` — sudah dipakai di `AppLayout.tsx`. Konsisten
  ukuran `size-4` untuk ikon inline nav/button, `size-5` untuk ikon topbar.

## 6. Bahasa antarmuka

Semua teks yang tampil ke user — label, tombol, pesan error/sukses,
placeholder — Bahasa Indonesia (PRD §1.4). Nama variabel/kode tetap Bahasa
Inggris seperti biasa.
