<?php

namespace App\Support\CompanyProfile;

/**
 * Sprint 20 D6 — every placeholder of the company profile, in one file.
 * Shown only while the real material isn't there yet (an empty column in
 * Pengaturan Situs, no published portfolio/testimonial) and only while
 * `daiku.company_profile.placeholders` is on. Before launch:
 * `grep -rn PLACEHOLDER` and replace each one through Pengaturan.
 *
 * Text here may only describe how Daiku really works (survey → desain →
 * RAB → termin → QA, the system's own flow); numbers and quotes are
 * stand-ins and must never go live as they are.
 */
final class Placeholder
{
    // PLACEHOLDER
    public const PUBLIC_TAGLINE = 'Desain interior, arsitektur & pembangunan';

    // PLACEHOLDER — ":city" becomes the home city.
    public const HERO_HEADLINE = 'Desain & Pembuatan Interior di :city';

    // PLACEHOLDER
    public const HERO_SUBHEADLINE = 'Kitchen set, kamar, cafe, toko, sampai kantor. Dirancang arsitek kami, dihitung rinci per item, lalu dikerjakan dan diperiksa di setiap tahap.';

    // PLACEHOLDER
    public const ABOUT = "Daiku Interior adalah tim desain dan pengerjaan interior di :city. Kami menangani pekerjaan dari gambar sampai pemasangan: arsitek menyusun desain 3D, estimator menyusun RAB per item, lalu tim produksi dan pemasang mengerjakannya dengan pemeriksaan mutu di setiap tahap.\n\nKami lebih suka menjelaskan biaya sebelum bekerja daripada menambah tagihan di tengah jalan. Setiap penawaran memuat material, ukuran dan harga per item, dan pekerjaan tambahan selalu dibuat sebagai penawaran terpisah yang Anda setujui dulu.";

    // PLACEHOLDER — not real figures.
    public const FOUNDED_YEAR = 2019;

    // PLACEHOLDER — not real figures.
    public const STAT_PROJECTS = 150;

    // PLACEHOLDER — not real figures.
    public const STAT_CITIES = 6;

    // PLACEHOLDER
    public const SERVICE_AREA = ':city dan sekitarnya: Kampar, Siak, Pelalawan, Dumai, dan kota lain di Riau dengan survei terjadwal.';

    // PLACEHOLDER
    public const OPENING_HOURS = 'Senin–Sabtu, 08.00–17.00 WIB';

    /** Not a placeholder: the opening line of every WhatsApp message (":name" = company). */
    public const WHATSAPP_GREETING = 'Halo :name, saya melihat website Anda dan ingin konsultasi';

    /*
     * Stand-in photos in public/images/site — real interiors from Pexels
     * (Pexels License: free for commercial use, no attribution required;
     * no people, no visible brands). They are NOT Daiku's work: no visible
     * label marks them (user's choice, 2026-10-09), only their `alt` text
     * says "Foto contoh" — so they must be replaced by real project photos
     * and SITE_PLACEHOLDERS turned off before launch. Sources: PHOTO_CREDITS.
     */
    public const HERO_IMAGE = ['images/site/hero-ruang-tamu.webp', 1920, 960];

    /** The same hero at 960 px, for phones (`srcset`). */
    public const HERO_IMAGE_SMALL = ['images/site/hero-ruang-tamu-960.webp', 960];

    /** Portrait photo for the contact card. */
    public const FEATURE_IMAGE = ['images/site/kontak.webp', 1200, 1500];

    /** The photo card among the home page's bento cards. */
    public const BENTO_IMAGE = ['images/site/bento.webp', 1200, 1200];

    /** One photo per service (cards of the home page, service pages without a photo). */
    public const SERVICE_IMAGES = [
        'KITCHEN_SET' => 'images/site/layanan-kitchen-set.webp',
        'KAMAR_SET' => 'images/site/layanan-kamar-set.webp',
        'RUANG_TAMU_TV' => 'images/site/layanan-ruang-tamu.webp',
        'CAFE' => 'images/site/layanan-cafe.webp',
        'TOKO' => 'images/site/layanan-toko.webp',
        'KANTOR' => 'images/site/layanan-kantor.webp',
        'RENOVASI' => 'images/site/layanan-renovasi.webp',
        'ARSITEKTURAL' => 'images/site/layanan-arsitektur.webp',
    ];

    /** Where each stand-in photo comes from (file => Pexels page). */
    public const PHOTO_CREDITS = [
        'hero-ruang-tamu' => 'https://www.pexels.com/photo/28853362/',
        'kontak' => 'https://www.pexels.com/photo/6615908/',
        'bento' => 'https://www.pexels.com/photo/6274242/',
        'layanan-kitchen-set' => 'https://www.pexels.com/photo/6908565/',
        'layanan-kamar-set' => 'https://www.pexels.com/photo/7535012/',
        'layanan-ruang-tamu' => 'https://www.pexels.com/photo/14614673/',
        'layanan-cafe' => 'https://www.pexels.com/photo/18617718/',
        'layanan-toko' => 'https://www.pexels.com/photo/8386651/',
        'layanan-kantor' => 'https://www.pexels.com/photo/7534173/',
        'layanan-renovasi' => 'https://www.pexels.com/photo/36035073/',
        'layanan-arsitektur' => 'https://www.pexels.com/photo/15422346/',
        'contoh-1' => 'https://www.pexels.com/photo/6969875/',
        'contoh-2' => 'https://www.pexels.com/photo/18721982/',
        'contoh-3' => 'https://www.pexels.com/photo/6782479/',
        'contoh-4' => 'https://www.pexels.com/photo/7166926/',
        'contoh-5' => 'https://www.pexels.com/photo/1884579/',
        'contoh-6' => 'https://www.pexels.com/photo/6794970/',
    ];

    public const OG_IMAGE = ['images/site/og-default.jpg', 1200, 630];

    /**
     * PLACEHOLDER — shown until a portfolio item is published.
     *
     * @var list<array{title: string, type: string, location: string, year: int, image: string}>
     */
    public const PORTFOLIO = [
        ['title' => 'Kitchen Set Putih Doff dengan Island', 'type' => 'KITCHEN_SET', 'location' => 'Panam', 'year' => 2025, 'image' => 'images/site/contoh-1.webp'],
        ['title' => 'Cafe 60 m² Bernuansa Kayu', 'type' => 'CAFE', 'location' => 'Sukajadi', 'year' => 2025, 'image' => 'images/site/contoh-2.webp'],
        ['title' => 'Kamar Anak dengan Meja Belajar', 'type' => 'KAMAR_SET', 'location' => 'Rumbai', 'year' => 2024, 'image' => 'images/site/contoh-3.webp'],
        ['title' => 'Backdrop TV & Rak Display', 'type' => 'RUANG_TAMU_TV', 'location' => 'Marpoyan Damai', 'year' => 2024, 'image' => 'images/site/contoh-4.webp'],
        ['title' => 'Toko Pakaian dengan Rak Dinding', 'type' => 'TOKO', 'location' => 'Senapelan', 'year' => 2024, 'image' => 'images/site/contoh-5.webp'],
        ['title' => 'Ruang Kerja Kantor 12 Orang', 'type' => 'KANTOR', 'location' => 'Bukit Raya', 'year' => 2023, 'image' => 'images/site/contoh-6.webp'],
    ];

    /** Size of every contoh-N.webp / layanan-*.webp (square; cards crop with object-cover). */
    public const PORTFOLIO_IMAGE_SIZE = [1200, 1200];

    /**
     * PLACEHOLDER — not real clients. Shown until a testimonial is published.
     *
     * @var list<array{client_label: string, quote: string}>
     */
    public const TESTIMONIALS = [
        ['client_label' => 'Ibu R., Kitchen Set — Panam', 'quote' => 'Yang paling membantu justru RAB-nya. Semua tertulis per item, jadi waktu saya minta ganti HPL, saya langsung tahu selisihnya berapa.'],
        ['client_label' => 'Bapak A., Interior Cafe — Sukajadi', 'quote' => 'Pekerjaannya dibagi per tahap dan tiap tahap dicek dulu sebelum lanjut. Cafe kami bisa buka sesuai jadwal.'],
        ['client_label' => 'Ibu M., Kamar Set — Rumbai', 'quote' => 'Desain 3D-nya direvisi dua kali sampai pas, dan hasil jadinya memang seperti di gambar.'],
    ];

    /** PLACEHOLDER — one line per service on the home page, until its page has its own description. */
    public const SERVICE_BLURBS = [
        'KITCHEN_SET' => 'Kabinet atas-bawah, island dan pantry. Ukurannya mengikuti dapur Anda, bukan ukuran pabrik.',
        'KAMAR_SET' => 'Lemari, dipan, meja rias dan meja belajar yang dirancang satu set dengan kamarnya.',
        'RUANG_TAMU_TV' => 'Backdrop TV, rak display dan partisi yang merapikan ruang tamu tanpa membuatnya sempit.',
        'CAFE' => 'Bar, area duduk dan pencahayaan yang dihitung dari alur pelanggan dan jumlah kursi.',
        'TOKO' => 'Rak display, meja kasir dan fasad yang membuat barang mudah dilihat dan dijangkau.',
        'KANTOR' => 'Meja kerja, ruang rapat dan partisi, dari kantor kecil sampai satu lantai penuh.',
        'RENOVASI' => 'Plafon, lantai, dinding dan instalasi, dikerjakan bertahap dengan jadwal yang jelas.',
        'ARSITEKTURAL' => 'Denah, tampak dan gambar kerja untuk rumah tinggal maupun ruko.',
    ];

    public static function heroHeadline(string $city): string
    {
        return strtr(self::HERO_HEADLINE, [':city' => $city]);
    }

    public static function about(string $city): string
    {
        return strtr(self::ABOUT, [':city' => $city]);
    }

    public static function serviceArea(string $city): string
    {
        return strtr(self::SERVICE_AREA, [':city' => $city]);
    }

    /**
     * PLACEHOLDER — the starting text of a service page (ServicePageSeeder).
     * Readable, because an unpublished page can still be opened from the
     * home page, but generic on purpose: ServicePageService refuses to
     * publish while intro or body still equal this.
     *
     * @return array{title: string, headline: string, intro: string, body: string, highlights: list<string>, faqs: list<array{q: string, a: string}>, meta_description: string}
     */
    public static function servicePage(ServiceEntry $entry): array
    {
        $city = ServiceCatalog::city();
        $name = $entry->name;
        $lower = mb_strtolower($entry->keyword);
        $blurb = self::SERVICE_BLURBS[$entry->type->value] ?? '';

        return [
            'title' => $name,
            'headline' => "Jasa {$entry->keyword} {$city}",
            'intro' => "{$blurb} Tim Daiku Interior mengerjakan {$lower} di {$city} dari survei ukuran sampai pemasangan, dengan RAB rinci per item sebelum pekerjaan dimulai.",
            'body' => "## Apa yang kami kerjakan\n"
                .ucfirst($lower)." dirancang mengikuti ukuran ruang dan kebiasaan pemakainya. Arsitek kami menyiapkan desain 3D yang bisa direvisi sebelum produksi, lalu estimator menyusun RAB dengan material dan harga per item.\n\n"
                ."## Material dan pengerjaan\n"
                ."Pilihan material, warna dan aksesori dibahas saat konsultasi dan tertulis di RAB. Pekerjaan dibagi per tahap, dan setiap tahap diperiksa tim QA sebelum lanjut ke tahap berikutnya.\n\n"
                ."## Area layanan\n"
                ."Kami melayani {$city} dan sekitarnya. Survei lokasi dijadwalkan setelah konsultasi awal lewat WhatsApp.",
            'highlights' => [
                'Survei dan ukur langsung di lokasi',
                'Desain 3D yang bisa direvisi',
                'RAB rinci per item sebelum mulai',
                'Pemeriksaan QA di setiap tahap',
            ],
            'faqs' => [
                ['q' => "Berapa lama pengerjaan {$lower}?", 'a' => 'Tergantung ukuran dan material. Jadwal per tahap kami tuliskan setelah desain disetujui, sebelum produksi dimulai.'],
                ['q' => 'Apakah konsultasi awal dikenakan biaya?', 'a' => 'Tidak. Konsultasi awal lewat WhatsApp gratis. Biaya survei dan desain tertulis di penawaran sebelum Anda menyetujuinya.'],
                ['q' => 'Bagaimana sistem pembayarannya?', 'a' => 'Bertahap sesuai termin di penawaran: uang muka, lalu termin berikutnya mengikuti progres pekerjaan.'],
            ],
            'meta_description' => "Jasa {$lower} di {$city}: desain 3D, RAB rinci per item, pengerjaan bertahap dengan QA. Konsultasi gratis lewat WhatsApp.",
        ];
    }
}
