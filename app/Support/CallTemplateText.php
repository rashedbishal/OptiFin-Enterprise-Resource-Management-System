<?php

namespace App\Support;

/**
 * WhatsApp Outbound Calling — the real Meta constraint verified against a
 * live `call_permission_request` template send (error 132018): a template
 * text parameter must not contain a newline, a tab, or a run of more than 4
 * consecutive spaces. A pure, stateless transform used ONLY to build the
 * outgoing Meta parameter value — it never touches the `Lead::message` it is
 * derived from, and never truncates or otherwise removes ordinary content.
 */
final class CallTemplateText
{
    public static function sanitize(string $text): string
    {
        $text = preg_replace('/\r\n|\r|\n/', ' ', $text) ?? $text;
        $text = str_replace("\t", ' ', $text);
        $text = preg_replace('/ {2,}/', ' ', $text) ?? $text;

        return trim($text);
    }
}
