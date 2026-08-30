<?php

namespace App\Console\Commands;

use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Read-only diagnostic: fetches one lead ad by ID and prints what the Graph
 * API returns. Deliberately does not create a Lead — this is for verifying
 * `leads_retrieval` access and the field_data shape while testing via
 * https://developers.facebook.com/tools/lead-ads-testing, not for
 * reprocessing a real submission (the webhook handler does that).
 */
#[Signature('leads:fetch-test {leadgen_id} {--source= : Facebook Lead Ads LeadSource ID to use for the access token — omit to use the first active one}')]
#[Description('Fetch a single Facebook lead ad by ID via the Graph API, for testing')]
class FetchFacebookLeadTest extends Command
{
    public function handle(): int
    {
        $leadgenId = $this->argument('leadgen_id');
        $sourceId = $this->option('source');

        $source = $sourceId !== null
            ? LeadSource::find($sourceId)
            : LeadSource::where('type', LeadSourceType::FacebookLeadAds)->where('is_active', true)->first();

        if ($source === null) {
            $this->error($sourceId !== null
                ? "No lead source found with ID [{$sourceId}]."
                : 'No active Facebook Lead Ads source found. Pass --source=<lead-source-id>.');

            return self::FAILURE;
        }

        $accessToken = $source->config['page_access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            $this->error("Source [{$source->id}] has no Page Access Token configured.");

            return self::FAILURE;
        }

        $response = Http::timeout(10)->get("https://graph.facebook.com/v20.0/{$leadgenId}", [
            'access_token' => $accessToken,
        ]);

        $this->info("HTTP {$response->status()}");

        if (! $response->successful()) {
            $this->error($response->json('error.message') ?? 'Unknown error');

            $code = (int) $response->json('error.code');
            $subcode = (int) $response->json('error.error_subcode');

            if ($code === 100 && $subcode === 33) {
                $this->warn('This looks like a leads_retrieval permission issue — check the token has Advanced Access for leads_retrieval, or that the person who submitted this test lead is added as a Tester/Developer on the app.');
            }

            return self::FAILURE;
        }

        $this->line((string) json_encode($response->json(), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
