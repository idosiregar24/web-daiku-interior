<?php

/*
 * Ziggy (`@routes` in resources/views/app.blade.php) — which named routes
 * the browser receives. Staff pages get every route (no `only`/`except`
 * here); pages anyone can open without logging in get only their group, so
 * the client's offer page doesn't ship the internal route map (horizon.*,
 * finance.*, …) — Sprint 17 Sub 01.
 */
return [
    'groups' => [
        // `penawaran/{token}` (Pages/Public/Quotation.tsx): the approve form and PDF link.
        'public' => ['public.quotation.*', 'login'],
    ],
];
