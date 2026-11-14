<?php

namespace App\Console\Commands;

use App\Models\Proposal;
use App\Services\ProposalDeleter;
use App\Support\ProposalKey;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Operator command for deletion requests (contact form, 「削除依頼」: deleted within 7 days). Takes the proposal's
 * URL or key; an expired proposal not yet pruned is found too. Unlike the owner's deletion (Y19), a creation in
 * progress is deleted as well (its unused reservation is released); the playlist on YouTube stays.
 * Never prints or logs the key or the full key_hash.
 */
class DeleteProposal extends Command
{
    protected $signature = 'app:delete-proposal {target : 提案の URL またはキー} {--force : 確認せずに削除する}';

    protected $description = '削除依頼のあった提案を作成の記録ごと削除する（運用。作成中でも削除する）';

    public const CONFIRM = 'この提案を削除しますか？（作成中の場合は作成も止めて削除します。YouTube の再生リストは残ります）';

    public function handle(ProposalDeleter $deleter): int
    {
        $key = ProposalKey::fromInput((string) $this->argument('target'));
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

        // Without --force it needs a "yes" at the prompt (default no); --no-interaction alone deletes nothing.
        $confirmed = $this->option('force') || ($this->input->isInteractive() && $this->confirm(self::CONFIRM, false));
        if (! $confirmed) {
            $this->line('cancelled');

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

    /** User text on the operator's terminal: no control characters (escape sequences), no console style tags. */
    private static function plain(string $text): string
    {
        return OutputFormatter::escape((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $text));
    }
}
