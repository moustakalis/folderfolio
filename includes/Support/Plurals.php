<?php

declare(strict_types=1);

namespace FolderFolio\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plural strings for the React apps, in every form the site's language has.
 *
 * ## Why this exists
 *
 * The apps are handed their strings in `window.folderFolio.i18n`, not through
 * wp-i18n's JSON files. Until 1.0 a counted label was two keys — `xOne` and
 * `xMany` — and the client chose between them with `count === 1`. That is
 * English's rule applied to every language: Polish, Czech, Russian, Arabic
 * and the rest have three to six forms, and a translator had exactly two
 * slots to fill, chosen by the wrong rule ("5 plik" instead of "5 plików").
 *
 * So each counted label is one gettext plural (`_n_noop()`, a normal msgid /
 * msgid_plural pair in the POT), and the client gets **all of its translated
 * forms**, in the translation's own order, plus the translation's
 * `Plural-Forms` expression, which `tn()` evaluates to pick one. Nothing
 * extra ships: the forms come from the .mo the PHP side already loads.
 *
 * ## How the forms are read
 *
 * Through `translate_nooped_plural()`, one call per form, with a count that
 * the language's own rule sends to that form — the smallest such count,
 * found by evaluating the rule with core's `Plural_Forms`. That keeps the
 * `ngettext` filters in the path and works with both of core's translation
 * back ends (the `.l10n.php` controller since 6.5 and the old MO reader)
 * without touching either one's internals.
 *
 * With no translation loaded the rule is gettext's default, `n != 1`, and the
 * forms are the English singular and plural.
 */
final class Plurals
{
    public const DOMAIN = 'folderfolio';

    /** gettext's rule when a catalogue does not state one. */
    public const DEFAULT_RULE = 'n != 1';

    /** How far to look for a count that selects each form. Arabic's last form starts at 100. */
    private const SEARCH_LIMIT = 1000;

    /** @var array<string, array{rule: string, samples: list<int>}> */
    private static array $cache = [];

    /**
     * Every form of one plural string, in the loaded translation's order.
     *
     * @param array{0?: string, 1?: string, singular: string, plural: string, context: ?string, domain: ?string} $noop From `_n_noop()`.
     * @return list<string>
     */
    public static function forms(array $noop): array
    {
        $forms = [];

        foreach (self::current()['samples'] as $count) {
            $forms[] = translate_nooped_plural($noop, $count, self::DOMAIN);
        }

        return $forms;
    }

    /**
     * The expression that turns a count into a form index, for the client.
     *
     * Only ever an expression core's own `Plural_Forms` accepted — a
     * catalogue whose header it cannot parse gets the default, which is what
     * core does with it on the PHP side.
     */
    public static function rule(): string
    {
        return self::current()['rule'];
    }

    /** Forget what was worked out — for a test that switches the language. */
    public static function reset(): void
    {
        self::$cache = [];
    }

    /**
     * @return array{rule: string, samples: list<int>}
     */
    private static function current(): array
    {
        $header = self::header();

        return self::$cache[$header] ??= self::parse($header);
    }

    /**
     * The `Plural-Forms` header of this plugin's loaded translation, or ''.
     */
    private static function header(): string
    {
        $translations = get_translations_for_domain(self::DOMAIN);
        // `WP_Translations` answers `headers` through __get; MO and
        // NOOP_Translations have it as a property. Both are an array.
        $headers = (array) ($translations->headers ?? []);

        foreach ($headers as $name => $value) {
            if (0 === strcasecmp((string) $name, 'Plural-Forms')) {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * @return array{rule: string, samples: list<int>}
     */
    private static function parse(string $header): array
    {
        $fallback = ['rule' => self::DEFAULT_RULE, 'samples' => [1, 2]];

        // Core's own reading of the header (Translations::nplurals_and_expression_from_header).
        if (!preg_match('/^\s*nplurals\s*=\s*(\d+)\s*;\s+plural\s*=\s*(.+)$/', $header, $matches)) {
            return $fallback;
        }

        $nplurals = (int) $matches[1];
        $rule = rtrim(trim($matches[2]), ';');

        if ($nplurals < 1 || '' === $rule) {
            return $fallback;
        }

        try {
            $handler = new \Plural_Forms($rule);
            $samples = [];

            for ($n = 0; $n < self::SEARCH_LIMIT && count($samples) < $nplurals; $n++) {
                $index = $handler->get($n);

                if ($index >= 0 && $index < $nplurals && !isset($samples[$index])) {
                    $samples[$index] = $n;
                }
            }
        } catch (\Exception $e) {
            return $fallback;
        }

        // A form no count reaches is a form no reader will see; the client
        // clamps an index past the end to the last form it has.
        ksort($samples);

        if ([] === $samples || array_keys($samples) !== range(0, count($samples) - 1)) {
            return $fallback;
        }

        return ['rule' => $rule, 'samples' => array_values($samples)];
    }
}
