<?php

namespace App\Console\Commands;

use App\Models\PlaylistCreation;
use App\Models\Proposal;
use App\Services\ChannelVideoCatalog;
use App\Services\ProposalDeleter;
use App\Support\ChannelInput;
use App\Support\ProposalKey;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Operator command for deletion requests (contact form, 「削除依頼」: deleted within 7 days).
 *
 * One proposal: by its URL or key (omit the argument to type it at a hidden prompt, out of the shell history); an
 * expired proposal not yet pruned is found too. Unlike the owner's deletion (Y19), a creation in progress is
 * deleted as well (its unused reservation is released); the playlist on YouTube stays.
 *
 * A whole channel (--channel=UC… or https://www.youtube.com/channel/UC…): every proposal for that channel or whose
 * creation was made by it (ProposalDeleter, same lock order), then the channel's imported video list and its
 * expiration record. Prints counts only.
 *
 * Never prints or logs the key, the full key_hash or the full channel id.
 */
class DeleteProposal extends Command
{
    protected $signature = 'app:delete-proposal
        {target? : 提案の URL またはキー（省略すると画面に表示せずに入力を求める）}
        {--channel= : チャンネル単位で削除する（UC… の ID か https://www.youtube.com/channel/UC… の URL）}
        {--force : 確認せずに削除する}';

    protected $description = '削除依頼のあった提案（またはチャンネルの全データ）を作成の記録ごと削除する（運用。作成中でも削除する）';

    public const CONFIRM = 'この提案を削除しますか？（作成中の場合は作成も止めて削除します。YouTube の再生リストは残ります）';

    public const CONFIRM_CHANNEL = 'このチャンネルの提案・作成の記録・取り込んだ動画の一覧をすべて削除しますか？（作成中のものも止めて削除します。YouTube の再生リストは残ります）';

    public const ASK_TARGET = '提案の URL またはキー（入力は表示されません）';

    public function handle(ProposalDeleter $deleter, ChannelVideoCatalog $catalog): int
    {
        $channel = $this->option('channel');
        if ($channel !== null) {
            if ($this->argument('target') !== null) {
                $this->error('use either a proposal or --channel');

                return self::FAILURE;
            }

            return $this->deleteChannel((string) $channel, $deleter, $catalog);
        }

        $target = $this->argument('target');
        if ($target === null && $this->input->isInteractive()) {
            $target = $this->secret(self::ASK_TARGET);
        }
        $key = ProposalKey::fromInput((string) $target);
        $proposal = $key === null ? null : Proposal::where('key_hash', ProposalKey::hash($key))->first();
        if ($proposal === null) {
            $this->error('proposal not found');

            return self::FAILURE;
        }

        $status = $proposal->creation()->value('status');
        $this->line('channel: '.substr($proposal->channel_id, 0, 8).'…');
        $this->line('title: '.self::plain($proposal->title));
        $this->line('items: '.$proposal->item_count);
        $this->line('created: '.$proposal->created_at->format('Y-m-d'));
        $this->line('creation: '.($status ?? 'なし'));

        if (! $this->confirmed(self::CONFIRM)) {
            return self::FAILURE;
        }

        try {
            $deleted = $deleter->delete($proposal, evenIfRunning: true);
        } catch (QueryException $e) {
            // The exception message contains SQL bindings: log only the SQLSTATE.
            Log::error('Proposal delete failed (database)', ['sqlstate' => (string) $e->getCode()]);
            $this->error('failed');

            return self::FAILURE;
        }
        if (! $deleted) {
            // Only after repeated races with a creation start; nothing was changed.
            Log::warning('Proposal delete raced');
            $this->error('failed');

            return self::FAILURE;
        }

        Log::info('Proposal deleted by operator', [
            'key_hash_prefix' => substr($proposal->key_hash, 0, 8),
            'creation' => $status,
        ]);
        $this->line('deleted');

        return self::SUCCESS;
    }

    private function deleteChannel(string $input, ProposalDeleter $deleter, ChannelVideoCatalog $catalog): int
    {
        // Only an id (or a /channel/UC… URL): a handle would need the API and can change hands.
        $ref = ChannelInput::parse($input);
        if ($ref === null || ! $ref->isId()) {
            $this->error('invalid channel (UC… の ID か https://www.youtube.com/channel/UC… の URL)');

            return self::FAILURE;
        }
        $channelId = $ref->value;

        $proposals = $this->channelProposals($channelId);
        $this->line('channel: '.substr($channelId, 0, 8).'…');
        $this->line('proposals: '.$proposals->count());
        $this->line('creations: '.PlaylistCreation::whereIn('proposal_id', $proposals->pluck('id'))->count());
        foreach ($catalog->channelDataCounts($channelId) as $table => $n) {
            $this->line("{$table}: {$n}");
        }

        if (! $this->confirmed(self::CONFIRM_CHANNEL)) {
            return self::FAILURE;
        }

        try {
            $deleted = 0;
            foreach ($proposals as $proposal) {
                if (! $deleter->delete($proposal, evenIfRunning: true)) {
                    Log::warning('Proposal delete raced');
                    $this->error('failed');

                    return self::FAILURE;
                }
                $deleted++;
            }
            $cache = $catalog->deleteChannelData($channelId);
        } catch (QueryException $e) {
            Log::error('Channel delete failed (database)', ['sqlstate' => (string) $e->getCode()]);
            $this->error('failed');

            return self::FAILURE;
        } catch (LockTimeoutException) {
            // The channel was being imported for longer than the lock: nothing of the list was deleted; run again.
            Log::warning('Channel delete waited too long for an import');
            $this->error('failed (channel busy; run again)');

            return self::FAILURE;
        }

        Log::info('Channel data deleted by operator', ['channel' => substr($channelId, 0, 8), 'proposals' => $deleted, ...$cache]);
        $this->line('deleted');

        return self::SUCCESS;
    }

    /** @return Collection<int, Proposal> */
    private function channelProposals(string $channelId): Collection
    {
        return Proposal::where('channel_id', $channelId)
            ->orWhereIn('id', PlaylistCreation::where('owner_channel_id', $channelId)->select('proposal_id'))
            ->orderBy('id')
            ->get();
    }

    /** Without --force it needs a "yes" at the prompt (default no); --no-interaction alone deletes nothing. */
    private function confirmed(string $question): bool
    {
        if ($this->option('force') || ($this->input->isInteractive() && $this->confirm($question, false))) {
            return true;
        }
        $this->line('cancelled');

        return false;
    }

    /** User text on the operator's terminal: no control characters (escape sequences), no console style tags. */
    private static function plain(string $text): string
    {
        return OutputFormatter::escape((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $text));
    }
}
