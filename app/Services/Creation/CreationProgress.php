<?php

namespace App\Services\Creation;

use App\Models\PlaylistCreation;
use App\Models\PlaylistCreationItem;
use App\Support\RemainingToday;

/**
 * The progress JSON of S6 (spec 7.3, 7.5) and its texts. Built from the creation tables only: no token,
 * no video ids (the owner's unlisted videos stay in playlist_creation_items).
 */
final class CreationProgress
{
    /** Item status labels (S6; resources/js/lib/creation-runner.js has the same map). */
    public const ITEM_LABELS = [
        'pending' => '待機中',
        'in_flight' => '追加中',
        'done' => '追加済み',
        'failed' => '失敗',
        'skipped' => '中止',
    ];

    /** The S6 progress line (same text as creation-runner.js progressText). */
    public static function progressText(array $p): string
    {
        return sprintf(
            '%d / %d 本を処理しました（追加 %d・失敗 %d・中止 %d）',
            $p['done'] + $p['failed'] + $p['skipped'], $p['total'], $p['done'], $p['failed'], $p['skipped'],
        );
    }

    /**
     * @return array{status: string, pause_reason: ?string, done: int, failed: int, skipped: int, total: int,
     *               playlist_url: ?string, message: ?string, remaining_today: string,
     *               items: list<array{position: int, status: string, error: ?string}>}
     */
    public static function toArray(PlaylistCreation $c, RemainingToday $r): array
    {
        $items = $c->items()->get(['position', 'status', 'last_error']);
        $count = fn (string $status) => $items->where('status', $status)->count();

        return [
            'status' => $c->status,
            'pause_reason' => $c->pause_reason,
            'done' => $count(PlaylistCreationItem::DONE),
            'failed' => $count(PlaylistCreationItem::FAILED),
            'skipped' => $count(PlaylistCreationItem::SKIPPED),
            'total' => $items->count(),
            'playlist_url' => $c->playlistUrl(),
            'message' => self::message($c, $r),
            'remaining_today' => $r->sentence(),
            'items' => $items->map(fn (PlaylistCreationItem $i) => [
                'position' => $i->position,
                'status' => $i->status,
                'error' => self::errorLabel($i->last_error),
            ])->values()->all(),
        ];
    }

    /** The state text shown in S6's status line; null while it is simply running. */
    public static function message(PlaylistCreation $c, RemainingToday $r): ?string
    {
        if ($c->status === PlaylistCreation::PAUSED) {
            return match ($c->pause_reason) {
                PlaylistCreation::PAUSE_QUOTA_EXCEEDED => 'YouTube 側の本日の上限に達したため一時停止しました。リセット後に「続きから」を押してください（次にリセットされるのは 日本時間 '.$r->resetLabel().' です）',
                PlaylistCreation::PAUSE_QUOTA_BUDGET => '本日の作成枠が足りないため一時停止しました。'.$r->sentence(),
                PlaylistCreation::PAUSE_AUTH => 'ログインの有効期限が切れたため一時停止しました。もう一度ログインして「続きから」を押してください',
                default => '一時停止中です。「続きから」で再開できます',
            };
        }
        if ($c->status === PlaylistCreation::NEEDS_CHECK) {
            return '再生リストが作成されたか確認できませんでした。YouTube Studio で「'.$c->title.'」が作成されていないか確認し、見つからなければ「見つからなかったので作り直す」を押してください';
        }
        if ($c->status === PlaylistCreation::COMPLETED) {
            if ($c->youtube_playlist_id === null) {
                return '再生リストは作成されませんでした';
            }
            $counts = $c->items()->reorder()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

            return sprintf(
                '作成が完了しました（追加 %d 本・失敗 %d 本・中止 %d 本）',
                (int) ($counts[PlaylistCreationItem::DONE] ?? 0),
                (int) ($counts[PlaylistCreationItem::FAILED] ?? 0),
                (int) ($counts[PlaylistCreationItem::SKIPPED] ?? 0),
            );
        }

        return null;
    }

    /** A fixed Japanese label for an item's last_error (YouTube's reason or our own marker); never the raw value. */
    public static function errorLabel(?string $reason): ?string
    {
        return match ($reason) {
            null => null,
            'videoNotFound' => '動画が見つかりません（削除または非公開）',
            'forbidden', 'videoNotAvailable', 'invalidValue' => 'この動画は追加できません',
            'transient' => 'YouTube との通信に失敗しました',
            CreationLifecycle::UNKNOWN_RESULT => '追加されたか確認できませんでした（YouTube で確認してください）',
            default => '追加できませんでした',
        };
    }
}
