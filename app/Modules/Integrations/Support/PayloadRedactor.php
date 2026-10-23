<?php

namespace App\Modules\Integrations\Support;

/**
 * Keeps secrets out of everything that is stored or shown: the payload kept with each delivery, an error message on a
 * connection, a log line. Two kinds of protection, because a secret can be hiding in either place:
 *
 *  - by **name**: a field called `…key`, `…token`, `…secret`, `…password`, `…signature` or `authorization` (whole words — a
 *    field called `keyword` is not touched) is replaced, whatever it holds;
 *  - by **value**: text is scrubbed of `glusr_crm_key=…`-style query parameters, bearer tokens and any secret the caller says it
 *    knows (the connection's own key), wherever they appear.
 */
final class PayloadRedactor
{
    public const MASK = '[removed]';

    private const NAME = '/(^|_)(key|token|secret|password|passwd|authorization|signature|apikey|api_key)(_|$)/';

    /** One field is never allowed to be larger than this when stored. */
    private const MAX_FIELD = 4000;

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $secrets  values to scrub wherever they occur
     * @return array<array-key, mixed>
     */
    public static function payload(array $payload, array $secrets = []): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            $name = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) $key));

            if (is_string($key) && preg_match(self::NAME, $name) === 1) {
                $clean[$key] = self::MASK;
            } elseif (is_array($value)) {
                $clean[$key] = self::payload($value, $secrets);
            } elseif (is_string($value)) {
                $clean[$key] = mb_substr(self::text($value, $secrets), 0, self::MAX_FIELD);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * @param  list<string>  $secrets
     */
    public static function text(string $text, array $secrets = []): string
    {
        // bytes that are not valid text would make the record impossible to store, and a record that cannot be stored must not
        // be able to block a whole pull for ever
        $text = mb_scrub($text, 'UTF-8');

        foreach ($secrets as $secret) {
            if ($secret !== '' && strlen($secret) >= 4) {
                $text = str_replace($secret, self::MASK, $text);
            }
        }

        $text = preg_replace('/((?:glusr_crm_)?(?:api_?)?(?:key|token|secret|password)=)[^&\s"\']+/i', '$1'.self::MASK, $text) ?? $text;

        return preg_replace('/(Bearer\s+)[A-Za-z0-9._\-|]+/i', '$1'.self::MASK, $text) ?? $text;
    }
}
