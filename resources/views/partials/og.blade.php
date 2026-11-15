{{-- Link preview card (spec D48). Only S4 passes $og (OpenGraph::forProposal); every other page gets the generic card. --}}
@php($card = \App\Support\OpenGraph::resolve($og ?? null))
    <meta name="theme-color" content="{{ \App\Support\OpenGraph::THEME_COLOR }}">
    <meta property="og:site_name" content="{{ \App\Support\OpenGraph::SITE_NAME }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="ja_JP">
    <meta property="og:url" content="{{ $card['url'] }}">
    <meta property="og:title" content="{{ $card['title'] }}">
    <meta property="og:description" content="{{ $card['description'] }}">
    <meta property="og:image" content="{{ $card['image'] }}">
    <meta property="og:image:width" content="{{ \App\Support\OpenGraph::IMAGE_WIDTH }}">
    <meta property="og:image:height" content="{{ \App\Support\OpenGraph::IMAGE_HEIGHT }}">
    <meta property="og:image:alt" content="{{ \App\Support\OpenGraph::IMAGE_ALT }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $card['title'] }}">
    <meta name="twitter:description" content="{{ $card['description'] }}">
    <meta name="twitter:image" content="{{ $card['image'] }}">
    <meta name="twitter:image:alt" content="{{ \App\Support\OpenGraph::IMAGE_ALT }}">
