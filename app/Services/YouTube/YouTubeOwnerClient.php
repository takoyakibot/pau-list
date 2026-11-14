<?php

namespace App\Services\YouTube;

use App\Support\ChannelInput;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * YouTube calls made with the channel owner's OAuth access token (never the API key).
 * Phase 2 only reads the owner's own channel; usage is recorded in the owner_read bucket without a cap.
 */
class YouTubeOwnerClient
{
    public function __construct(private readonly QuotaLedger $ledger) {}

    /**
     * The channel of the account the token belongs to; null when it has none (or the id is malformed).
     *
     * @throws YouTubeApiException
     */
    public function myChannel(#[\SensitiveParameter] string $accessToken): ?OwnerChannel
    {
        $endpoint = 'channels';
        $this->ledger->record(QuotaLedger::OWNER_READ, 1);

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting()
                ->get(YouTubeReader::BASE_URL.$endpoint, ['part' => 'snippet', 'mine' => 'true']);
        } catch (ConnectionException|RequestException) {
            // Exception messages may carry the request or body: classify without them.
            throw $this->fail($endpoint, YouTubeError::connection());
        }

        if (! $response->successful()) {
            throw $this->fail($endpoint, YouTubeError::fromResponse($response));
        }
        $json = $response->json();
        $items = is_array($json) ? ($json['items'] ?? []) : null;
        if (! is_array($items)) {
            throw $this->fail($endpoint, YouTubeError::malformed());
        }

        $item = array_values(array_filter($items, 'is_array'))[0] ?? null;
        $id = $item['id'] ?? null;
        if (! is_string($id) || preg_match(ChannelInput::ID_PATTERN, $id) !== 1) {
            return null;
        }
        $title = $item['snippet']['title'] ?? null;

        return new OwnerChannel($id, is_string($title) ? $title : '');
    }

    private function fail(string $endpoint, YouTubeError $error): YouTubeApiException
    {
        Log::warning('youtube owner api error', [
            'endpoint' => $endpoint,
            'status' => $error->status,
            'reason' => $error->reason,
            'kind' => $error->kind->value,
        ]);

        return new YouTubeApiException($error);
    }
}
