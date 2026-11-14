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
    public const MAYBE_CREATED = 'YouTube に作りかけの再生リストが残っている可能性があります。YouTube Studio で確認してください';

    /** A completed creation without a playlist (not created / unknown): the proposal cannot be created again (S4, S6). */
    public const REMAKE_HINT = '作り直すには、「この提案を削除」で提案を削除してから、もう一度提案を作成してください';

    /** Appended to the result once the items were deleted 7 days after the owner's last use (PruneExpiredData). */
    public const PRUNED = '最後の操作から 7 日たったため、作成の記録（動画の一覧など）は削除しました';

    /** Shown next to the armed 「残りを中止」 (S6). */
    public const ABORT_WARNING = '中止すると、まだ追加していない動画は追加されません。元に戻せません（失敗した動画の再試行だけはできます）';

    public const AUTH_EXPIRED = 'ログインの有効期限が切れたため一時停止しました。もう一度ログインして「続きから」を押してください';

    /** paused(auth_expired) seen by an owner who has logged in again. */
    public const LOGGED_IN_AGAIN = 'ログインし直しました。「続きから」を押してください';

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
     *               playlist_url: ?string, playlist_created: bool, message: ?string, remaining_today: string,
     *               items: list<array{position: int, status: string, error: ?string}>}
     */
    public static function toArray(PlaylistCreation $c, RemainingToday $r, bool $ownerLoggedIn = false): array
    {
        $items = $c->items()->get(['position', 'status', 'last_error']);
        $count = fn (string $status) => $items->where('status', $status)->count();
        $counts = $c->isPruned() ? self::counts($c) : [
            'done' => $count(PlaylistCreationItem::DONE),
            'failed' => $count(PlaylistCreationItem::FAILED),
            'skipped' => $count(PlaylistCreationItem::SKIPPED),
            'total' => $items->count(),
        ];

        return [
            'status' => $c->status,
            'pause_reason' => $c->pause_reason,
            'done' => $counts['done'],
            'failed' => $counts['failed'],
            'skipped' => $counts['skipped'],
            'total' => $counts['total'],
            'playlist_url' => $c->playlistUrl(),
            // Also true once a non-public playlist id was dropped with the owner's data (no link, no remake hint).
            'playlist_created' => $c->playlistCreated(),
            'message' => self::message($c, $r, $ownerLoggedIn),
            'remaining_today' => $r->sentence(),
            'items' => $items->map(fn (PlaylistCreationItem $i) => [
                'position' => $i->position,
                'status' => $i->status,
                'error' => self::errorLabel($i->last_error),
            ])->values()->all(),
        ];
    }

    /**
     * The state text shown in S6's status line; null while it is simply running. $ownerLoggedIn: the request
     * carries the owner's login (after a Google 401 the owner had to log in again to see S6).
     */
    public static function message(PlaylistCreation $c, RemainingToday $r, bool $ownerLoggedIn = false): ?string
    {
        if ($c->status === PlaylistCreation::PAUSED) {
            return match ($c->pause_reason) {
                PlaylistCreation::PAUSE_QUOTA_EXCEEDED => 'YouTube 側の本日の上限に達したため一時停止しました。リセット後に「続きから」を押してください（次にリセットされるのは 日本時間 '.$r->resetLabel().' です）',
                PlaylistCreation::PAUSE_QUOTA_BUDGET => '本日の作成枠が足りないため一時停止しました。'.$r->sentence(),
                PlaylistCreation::PAUSE_AUTH => $ownerLoggedIn ? self::LOGGED_IN_AGAIN : self::AUTH_EXPIRED,
                default => '一時停止中です。「続きから」で再開できます',
            };
        }
        if ($c->status === PlaylistCreation::NEEDS_CHECK) {
            return '再生リストが作成されたか確認できませんでした。YouTube Studio で「'.$c->title.'」が作成されていないか確認し、見つからなければ「見つからなかったので作り直す」を押してください';
        }
        if ($c->status === PlaylistCreation::COMPLETED) {
            if (! $c->playlistCreated()) {
                // An attempt whose outcome was never settled (stopped from creating_playlist / needs_check).
                $result = $c->playlist_attempted_at !== null
                    ? self::MAYBE_CREATED
                    : '再生リストは作成されませんでした';
            } else {
                $counts = self::counts($c);
                $result = sprintf('作成が完了しました（追加 %d 本・失敗 %d 本・中止 %d 本）', $counts['done'], $counts['failed'], $counts['skipped']);
            }

            return $c->isPruned() ? $result.'。'.self::PRUNED : $result;
        }

        return null;
    }

    /**
     * Item counts by status: from the items, or from the columns kept when the items were deleted.
     *
     * @return array{done: int, failed: int, skipped: int, total: int}
     */
    public static function counts(PlaylistCreation $c): array
    {
        if ($c->isPruned()) {
            return [
                'done' => (int) $c->done_count,
                'failed' => (int) $c->failed_count,
                'skipped' => (int) $c->skipped_count,
                'total' => (int) $c->total_count,
            ];
        }
        $n = $c->items()->reorder()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'done' => (int) ($n[PlaylistCreationItem::DONE] ?? 0),
            'failed' => (int) ($n[PlaylistCreationItem::FAILED] ?? 0),
            'skipped' => (int) ($n[PlaylistCreationItem::SKIPPED] ?? 0),
            'total' => (int) $n->sum(),
        ];
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
