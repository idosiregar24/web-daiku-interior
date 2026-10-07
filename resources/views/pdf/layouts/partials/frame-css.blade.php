{{-- Sprint 17 — page frame shared by pdf/layouts/letter and pdf/layouts/invoice: margins, letterhead, footer, watermark, signature. CSS only (included inside <style>). --}}
        @page { margin: 125px 56px 70px 56px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1a1a1a; line-height: 1.45; }

        .letterhead { position: fixed; top: -125px; left: -56px; right: -56px; height: 104px; }
        .letterhead table { width: 100%; border-collapse: collapse; }
        .letterhead td { vertical-align: middle; padding: 0; }
        .letterhead .brand { padding-left: 56px; height: 96px; }
        .letterhead .brand img { height: 58px; }
        .letterhead .brand .name { font-size: 20px; font-weight: bold; }
        .letterhead .contact { width: 46%; background: #f5c518; text-align: right; padding: 10px 56px 10px 16px; font-size: 9.5px; color: #1a1a1a; }
        .letterhead .contact .line { height: 17px; line-height: 17px; }
        .letterhead .contact .icon { width: 11px; height: 11px; margin-left: 5px; vertical-align: middle; }
        .letterhead .rule { height: 6px; background: #1a1a1a; }

        .footer { position: fixed; bottom: -70px; left: -56px; right: -56px; height: 22px; background: #1a1a1a; color: #ffffff;
            text-align: center; font-size: 9px; font-weight: bold; letter-spacing: 0.4px; padding-top: 8px; }

        .watermark { position: fixed; top: 210px; left: 90px; width: 420px; opacity: 0.06; }

        .sign { width: 100%; margin-top: 18px; }
        .sign td { vertical-align: top; }
        .sign .slot { width: 210px; }
        .sign .signature { height: 64px; margin: 4px 0 2px; }
        .sign .name { font-weight: bold; }
