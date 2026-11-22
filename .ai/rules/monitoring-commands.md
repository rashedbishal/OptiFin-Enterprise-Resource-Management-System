---
paths:
  - 'app/{Services/IntegrationHealth.php,Services/MetaTokenChecker.php,Livewire/Platform/Monitoring/Integrations.php,Health/**,Console/Commands/CheckMetaTokens.php}'
---

# Monitoring Commands

## Integrations health: one rule source, read-only Meta token checks
IntegrationHealth::row() is the single place the problem rules live (quiet 7d, webhook rejected 7d, inbox can't connect at ops.imap_failure_threshold, emails failed 7d, token missing/invalid/expiring 7d, calling off at Meta); the Health checks MetaTokensCheck/EmailInboxesCheck reuse it — don't re-implement rules there. lead_sources.token_expires_at is NOT written by anything; Meta token state lives in lead_source_token_checks (separate table, cascade with the source), written only by MetaTokenChecker (debug_token with the token inspecting itself; Instagram-login via graph.instagram.com/me, expiry unknown). Never store or log the token. A Health "not set up" result must be a warning with NO notification message so it never emails.
