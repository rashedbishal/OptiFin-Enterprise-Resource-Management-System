<?php

namespace App\Console\Commands;

use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * A valid Page Access Token alone doesn't make Meta deliver `leadgen`
 * events to our webhook — the Page must be explicitly subscribed to that
 * field, the same way testFacebookConnection() already does for
 * `messages,messaging_postbacks`. This is the same operation, run from the
 * CLI for a source that isn't reachable through the admin UI's "Test
 * Connection" button yet, or to double-check a Page's current subscription.
 */
#[Signature('meta:subscribe-leadgen {source? : Lead Source ID to subscribe — omit to subscribe every active Facebook Lead Ads source}')]
#[Description('Subscribe a Facebook Page to the leadgen webhook field via the Graph API')]
class SubscribeLeadgenWebhook extends Command
{
    public function handle(): int
    {
        $sourceId = $this->argument('source');

        $sources = $sourceId !== null
            ? LeadSource::where('id', $sourceId)->where('type', LeadSourceType::FacebookLeadAds)->get()
            : LeadSource::where('type', LeadSourceType::FacebookLeadAds)->where('is_active', true)->get();

        if ($sources->isEmpty()) {
            $this->error($sourceId !== null
                ? "No Facebook Lead Ads source found with ID [{$sourceId}]."
                : 'No active Facebook Lead Ads sources are configured.');

            return self::FAILURE;
        }

        $failures = 0;

        foreach ($sources as $source) {
            if (! $this->subscribe($source)) {
                $failures++;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function subscribe(LeadSource $source): bool
    {
        $pageId = $source->config['page_id'] ?? null;
        $accessToken = $source->config['page_access_token'] ?? null;

        if (! is_string($pageId) || $pageId === '' || ! is_string($accessToken) || $accessToken === '') {
            $this->warn("Source [{$source->id}] ({$source->name}) has no Page ID / Page Access Token configured — skipping.");

            return false;
        }

        $response = Http::asForm()->timeout(10)->post("https://graph.facebook.com/{$pageId}/subscribed_apps", [
            'subscribed_fields' => 'leadgen',
            'access_token' => $accessToken,
        ]);

        if (! $response->successful() || $response->json('success') !== true) {
            $this->error("Failed to subscribe page [{$pageId}]: ".($response->json('error.message') ?? 'unknown error'));

            return false;
        }

        $this->info("Subscribed page [{$pageId}] ({$source->name}) to leadgen events.");
        $this->printCurrentSubscription($pageId, $accessToken);

        return true;
    }

    private function printCurrentSubscription(string $pageId, string $accessToken): void
    {
        $response = Http::timeout(10)->get("https://graph.facebook.com/{$pageId}/subscribed_apps", [
            'access_token' => $accessToken,
        ]);

        if (! $response->successful()) {
            return;
        }

        $fields = $response->json('data.0.subscribed_fields') ?? [];

        $this->line('  Currently subscribed fields: '.(is_array($fields) ? implode(', ', $fields) : '(none)'));
    }
}
