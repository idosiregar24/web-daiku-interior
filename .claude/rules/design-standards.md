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
| Warna status sukses/warn/error/info | `bg-success/10`/`bg-warning`/dst (lihat §3) | class Tailwind default (`text-green-500`) |
| Teks berwarna status (angka, label kecil) | `text-success-ink`/`text-error-ink`/`text-warning-ink`/`text-info-ink` | `text-success` untuk teks — terlalu pucat di atas putih (±2.3:1) |

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

### Building block `shared/` — pakai ini dulu sebelum menulis markup sendiri

| Kebutuhan | Komponen |
|---|---|
| Judul halaman + deskripsi + tombol aksi | `PageHeader` (prop `icon` = ikon modul yang sama dengan sidebar) |
| Panel berjudul (widget, detail, form section) | `SectionCard` (`title`, `description`, `icon`, `action`, `footer`, `flush`) — jangan rakit `Card`+`CardHeader`+`CardTitle` manual |
| Angka KPI | `StatCard` (`tone`, `hint`, `delta` vs periode, `trend` → sparkline, `children` mis. `ProgressBar`) |
| Tabel data | `DataTable` — filter masuk prop `toolbar`, paginator Laravel masuk prop `pagination` (bukan `<Pagination>` terpisah di bawah) |
| Tabel `<table>` manual (baris custom/editable) | bungkus `TableCard` (prop `toolbar`/`pagination` sama) + `<thead className={TABLE_HEAD_CLASS}>` |
| Kolom pencarian | `SearchInput` (Input + ikon cari) |
| Kolom password | `PasswordInput` (Input + tombol tampilkan/sembunyikan) |
| Logo perusahaan | `BrandMark` — logo yang diunggah di Pengaturan Situs (`site.logoUrl`), fallback ke mark bawaan. Nama/tagline dari prop bersama `site`, jangan hardcode "Daiku Interior" di layout |
| Data kosong | `EmptyState` (di tabel, list, widget) |
| Banner peringatan/info | `Notice` (`tone` info/success/warning/error) |
| Pasangan label–nilai di halaman detail | `DetailList` + `DetailItem` |
| Rasio / progres | `ProgressBar` |
| Tab level halaman (Detail Proyek, Data Master) | `UnderlineTabsList` di dalam `<Tabs>`; tab kecil di dalam section tetap `TabsList` biasa |
| Status | `StatusChip` (pill ber-tint polos, tanpa titik/outline; prop `tone` untuk status di luar union domain, mis. Aktif/Nonaktif user) |

Jangan pakai ornamen dekoratif: pill "badge" dengan titik + border kuning,
halo/ring kuning di sekitar titik atau ikon, glow. Aksen kuning cukup
lewat fill (`bg-daiku-yellow`, `bg-daiku-yellow-light`) atau garis bawah
link (`decoration-daiku-yellow`). Teks kuning di atas putih dilarang —
kontrasnya ±2:1.

## 3. Status badge / chip

PRD §8.3: "Status Badge: Chip berwarna sesuai status". Mapping warna →
status mengikuti token `success`/`warning`/`error`/`info` (§8.1 PRD), bukan
warna Tailwind default:

```tsx
const STATUS_COLOR: Record<string, string> = {
  DONE: 'bg-success/10 text-success-ink',
  APPROVED: 'bg-success/10 text-success-ink',
  PENDING: 'bg-daiku-gray text-daiku-muted',
  OVER: 'bg-error/10 text-error-ink',
  REJECTED: 'bg-error/10 text-error-ink',
  ONPROGRESS: 'bg-info/10 text-info-ink',
  WARNING: 'bg-warning/10 text-warning-ink', // delay, follow-up jatuh tempo
};
```

Bangun ini sebagai komponen `<StatusChip status="..." />` (CSV Sprint 1),
jangan duplikasi mapping ini di tiap halaman.

## 4. Layout

- Halaman berautentikasi selalu dibungkus `Layouts/AppLayout.tsx`: sidebar
  di atas kanvas `bg-daiku-gray` (item aktif = pill putih + garis kuning di
  kiri, kartu user di bawah) dan konten di panel putih `rounded-2xl`
  ("inset shell"). Topbar: breadcrumb, `CommandMenu` (Ctrl/⌘ K — cari &
  lompat ke menu yang boleh diakses role), lonceng notifikasi. Tambah entri
  modul baru ke `NAV_GROUPS` di file itu begitu route-nya siap — sidebar,
  command menu, dan daftar "Modul Anda" di Dashboard membaca data yang sama
  lewat `useNavGroups()`.
- Topbar pakai **breadcrumb** (PRD §8.3). Awal jejaknya **otomatis** dari
  menu sidebar tempat route berada: 🏠 › grup ▾ (dropdown menu lain di grup
  itu) › menu (+ ikon). Menu dicocokkan lewat pola Ziggy `NavItem.match`
  (default: `x.index`/`x.edit` → `x.*`, jadi halaman detail/tambah ikut
  menandai menunya di sidebar). Halaman **index menu tidak mengirim
  `breadcrumbs`**; halaman detail/form hanya mengirim level setelah menu:
  `breadcrumbs={[{ label: project.name }, { label: 'Finance' }]}` (entry
  bisa `routeName` atau `href`; entry terakhir = halaman saat ini). Tab
  level halaman ikut jadi crumb terakhir (lihat Detail Proyek, Termin,
  Data Master). Jangan ulangi nama grup/menu di `breadcrumbs`.
- Halaman auth (login/register/dst) pakai `Layouts/AuthLayout.tsx`
  (kartu putih mengambang di atas `bg-daiku-cream`: panel gradien emas di
  kiri — desktop saja — dan form di kanan; judul/deskripsi lewat prop
  `title`/`description` layout, bukan `<h1>` di halaman).
- Card: `<Card>` shadcn (sudah `rounded-xl ring-1 ring-border shadow-xs`) —
  jangan bungkus manual dengan div + class Tailwind mentah.
- Modal/Dialog: `<Dialog>` shadcn, `max-w-lg` default sesuai PRD §8.3.
- Tabel: header pakai `bg-daiku-yellow-light` sesuai PRD §8.3 (label kecil
  uppercase `text-daiku-muted`) — sudah diterapkan oleh `DataTable` dan
  `TABLE_HEAD_CLASS`; jangan ubah primitive `table.tsx` global.

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
