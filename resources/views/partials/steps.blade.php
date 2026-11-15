{{-- The proposal → creation flow as three pills. $current: the step this page is on (1-3), or null for none. --}}
<ol class="flex flex-wrap gap-2 text-[13px]" aria-label="提案から作成までの流れ">
    @foreach ([1 => '動画を選ぶ', 2 => 'URL を送る', 3 => 'チャンネル主が作成'] as $n => $label)
        @if ($current === $n)
            <li class="flex items-center gap-1.5 rounded-full bg-accent px-3 py-1.5 font-bold text-on-accent" aria-current="step">{{ $n }} {{ $label }}</li>
        @else
            <li class="flex items-center gap-1.5 rounded-full border border-line bg-raised px-3 py-1.5 text-ink-muted">{{ $n }} {{ $label }}</li>
        @endif
    @endforeach
</ol>
