# OptiFin Enterprise Resource Management System

## Testing Facebook Lead Ads (Instant Forms) ingestion

Facebook Lead Ads submissions arrive on the same webhook URL as Messenger
(`/api/webhook/facebook/{tenant_code}`) as a `leadgen` change event, not a
`messaging` event — Meta delivers both to one Page-level subscription.

### 1. Configure the Lead Source

In the Platform admin panel, under the client's **Lead Sources**, add a
**Facebook Lead Ads** source with:

- **Page ID** — `1087258341146975` (OSL Tester)
- **Page Access Token** — a Page token with `leads_retrieval` permission
  (Advanced Access, not just Standard/Development Access — a token without
  it will fail with a permissions error on every fetch, logged and handled
  gracefully as a fallback lead with just the `leadgen_id`)
- **Verify Token** — any value you choose; enter the same value in Meta's
  webhook subscription setup

Click **Test Connection** — this both verifies the Page ID/token and
subscribes the Page to the `leadgen` field via the Graph API
(`POST /{page-id}/subscribed_apps?subscribed_fields=leadgen`).

If the source was created before this feature existed, or if you need to
(re)subscribe from the CLI instead:

```
php artisan meta:subscribe-leadgen {leadSourceId}
```

Omit the argument to subscribe every active Facebook Lead Ads source at once.

### 2. Point Meta's webhook subscription at this app

In the Meta App dashboard (App ID `2840700582974064`) → **Webhooks** →
Page subscription, set the callback URL to the Lead Source's webhook URL
(shown on the Lead Sources page) and subscribe to the `leadgen` field. Use
the same Verify Token entered in step 1.

### 3. Submit a test lead

Use Meta's [Lead Ads Testing
Tool](https://developers.facebook.com/tools/lead-ads-testing):

1. Select the Page **OSL Tester**.
2. Select the Instant Form you want to test.
3. Fill in the test lead's details and **Submit**.

Within a few seconds, a new Lead should appear in this tenant's Leads list,
named from the form's `full_name` field (or `first_name` + `last_name`, or
the submitted email, in that order of preference), with a
`facebook_lead_ads` source badge.

### 4. If it doesn't show up

- **Check `storage/logs/laravel.log`** — every step (missing source,
  missing token, a failed Graph API fetch, a duplicate delivery) logs a
  specific reason rather than failing silently.
- **A `leads_retrieval` permission error** logs a warning naming it
  explicitly and still creates a minimal lead (name `Facebook Lead
  {last 4 of the leadgen_id}`, no email/phone) so the submission isn't lost
  — check the token's granted permissions.
- **Manually fetch one lead by ID** without needing a real webhook
  delivery:

  ```
  php artisan leads:fetch-test {leadgen_id} --source={leadSourceId}
  ```

- **Check what permissions the stored token actually has** (via a
  platform-admin-authenticated route, not a public endpoint — token scope
  info is sensitive):

  ```
  GET /admin/clients/{tenant}/lead-sources/{leadSource}/meta-permissions
  ```

  Reports granted vs. required scopes (`pages_messaging`,
  `instagram_manage_messages`, `leads_retrieval`, `pages_manage_metadata`)
  with the token itself masked to its last 10 characters.
- **A stale queue worker will not affect Lead Ads** — unlike attachment
  downloads, leadgen processing runs synchronously inside the webhook
  request, not on a queue. If you don't see a lead, the cause is on the
  Meta/permissions side, not a worker that needs restarting.
