# Frontend Standards — React 18 + Inertia v2 + TypeScript

Berlaku untuk semua kode di `resources/js/`. Untuk aturan visual/warna lihat
[`design-standards.md`](design-standards.md).

## 1. Struktur folder (jangan menyimpang)

```
resources/js/
├── Pages/{Modul}/{Action}.tsx   # 1:1 dengan Controller — index.blade lama = Index.tsx
├── Components/
│   ├── ui/                      # shadcn primitives — generate via CLI, jangan edit isi logic-nya
│   ├── shared/                  # reusable lintas modul (StatusChip, DataTable, PageHeader)
│   └── modules/                 # spesifik satu modul
├── Layouts/                     # AppLayout.tsx, AuthLayout.tsx — lihat design-standards.md §4
├── types/index.d.ts             # semua interface/union type domain (Lead, Role, *Status, dst)
├── hooks/                       # custom hook React (useXxx)
└── lib/
    ├── utils.ts                 # cn() dari shadcn — jangan tambah util tak terkait di sini
    └── echo.ts                  # Laravel Echo client, lihat §5
```

Import selalu lewat alias `@/...` (map ke `resources/js/*`), bukan path
relatif panjang (`../../../Components/...`).

## 2. Halaman Inertia

- Satu file per action controller (`Index.tsx`, `Create.tsx`, `Edit.tsx`,
  `Show.tsx`) di dalam folder modul, mengikuti Controller-nya —
  `LeadController@index` → `Pages/CRM/Index.tsx`.
- Props controller di-type lewat generic `PageProps<T>` dari
  `@/types` (`usePage<PageProps<{ leads: Lead[] }>>().props`), bukan
  `any`.
- Halaman berautentikasi dibungkus `<AppLayout header={...}>`; jangan
  bikin markup sidebar/topbar ulang per halaman.

## 3. Form: React Hook Form + Zod — wajib, jangan `useState` manual per-field

```tsx
const schema = z.object({
  client_name: z.string().min(1, 'Nama wajib diisi'),
  priority: z.enum(['HOT', 'WARM', 'COLD']),
});

const form = useForm<z.infer<typeof schema>>({
  resolver: zodResolver(schema),
  defaultValues: { priority: 'WARM' },
});
```

- Validasi client-side (Zod) **selalu** mencerminkan validasi server-side
  (Form Request) — jangan sampai keduanya beda aturan.
- Submit ke backend tetap lewat Inertia (`router.post`/`useForm` dari
  `@inertiajs/react` untuk progress bar & error bag), bukan `fetch`
  manual — RHF hanya menangani validasi client-side + shape data sebelum
  dikirim.
- Gunakan komponen `<Form>`/`<FormField>`/`<FormItem>` dari
  `@/Components/ui/form` (sudah ditulis manual untuk preset Nova — CLI
  shadcn saat ini tidak generate file ini untuk style project, lihat
  git blame `Components/ui/form.tsx` kalau perlu referensi ulang).

## 4. Tabel: TanStack Table

Semua tabel data (lead list, task list, transaksi finance, dst) pakai
`Components/shared/DataTable.tsx` (`@tanstack/react-table` **v8**, bukan
v9 — lihat `.claude/plan/README.md` untuk alasan pin versi), bukan
`<table>` manual atau looping `<TableRow>` langsung di tiap halaman.
Sorting/filtering yang sifatnya query besar (>1 halaman data) dilakukan di
backend (query string + `Inertia::render` props), bukan client-side TanStack
filtering — supaya konsisten dengan pagination Laravel.

## 5. Real-time: Laravel Echo

`lib/echo.ts` inisialisasi `window.Echo` tapi **tidak** di-import otomatis
di `app.tsx` — supaya tidak buka koneksi WebSocket di setiap halaman
termasuk yang tidak butuh notifikasi real-time. Import eksplisit di
komponen yang perlu (mis. notification bell di `AppLayout.tsx`):

```tsx
import echo from '@/lib/echo';

useEffect(() => {
  const channel = echo.private(`App.Models.User.${user.id}`);
  channel.notification((notification) => { /* ... */ });
  return () => echo.leave(`App.Models.User.${user.id}`);
}, [user.id]);
```

Channel private per PRD §4.9 ("update real-time via Echo private
channel") — jangan pakai public channel untuk data milik user tertentu.

## 6. Route helper

Selalu `route('nama.route')` (Ziggy, global lewat `@routes` di
`app.blade.php`), **jangan** hardcode path string (`'/crm/leads'`). Kalau
route belum ada (modul belum dibangun), item nav dirender disabled — lihat
pola `NAV_GROUPS` di `AppLayout.tsx` (field `routeName` opsional).

## 7. State & data fetching

- Data dari server = props Inertia. Jangan duplikasi ke `useState` lalu
  di-sync manual — pakai props langsung atau `useMemo` turunan dari props.
- State UI lokal murni (modal terbuka, tab aktif) baru pakai `useState`.
- Tidak ada React Query/SWR di stack ini — Inertia sudah berperan sebagai
  data layer (partial reload via `router.reload({ only: [...] })` kalau
  butuh refresh sebagian data tanpa full page visit).

## 8. TypeScript

- `strict: true` sudah aktif (`tsconfig.json`) — jangan matikan.
- Semua enum/union status domain didefinisikan sekali di
  `resources/js/types/index.d.ts`, dipakai ulang di seluruh halaman/komponen
  (`import type { LeadStatus } from '@/types'`) — jangan tulis ulang union
  literal di tiap file.
- `npm run build` (= `tsc && vite build`) harus lulus sebelum PR — CI
  (`.github/workflows/ci.yml`) menjalankan build yang sama.
