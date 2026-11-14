<div class="remaining-today">
    @if ($remaining->exhausted())
        <p>本日はこれ以上作成できません。次にリセットされるのは 日本時間 {{ $remaining->resetAtJst->format('n月j日 G時') }} です</p>
    @else
        <p>本日の残り: 再生リスト 1 つ＋動画あと {{ $remaining->items }} 本まで作成できます</p>
        @if (! is_null($itemCount ?? null) && ! $remaining->covers($itemCount))
            <p>この提案（動画 {{ $itemCount }} 本）は本日の残りを超えています。次にリセットされるのは 日本時間 {{ $remaining->resetAtJst->format('n月j日 G時') }} です</p>
        @endif
    @endif
    @if (! empty($note ?? null))
        <p><small>{{ $note }}</small></p>
    @endif
</div>
