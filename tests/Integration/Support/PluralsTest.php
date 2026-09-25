<?php

namespace FolderFolio\Tests\Integration\Support;

use FolderFolio\Support\ClientConfig;
use FolderFolio\Support\Plurals;
use WP_UnitTestCase;

/**
 * A counted label reaches the client in every form the translation has.
 *
 * Against core's real translation lookup: a catalogue is put where
 * `get_translations_for_domain()` finds it, and the forms come back through
 * `translate_nooped_plural()`, as they do on a translated site.
 */
class PluralsTest extends WP_UnitTestCase
{
    private const POLISH = 'n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2';

    /** @var mixed */
    private $had;

    public function setUp(): void
    {
        parent::setUp();

        $this->had = $GLOBALS['l10n'][Plurals::DOMAIN] ?? null;
        Plurals::reset();
    }

    public function tearDown(): void
    {
        if (null === $this->had) {
            unset($GLOBALS['l10n'][Plurals::DOMAIN]);
        } else {
            $GLOBALS['l10n'][Plurals::DOMAIN] = $this->had;
        }

        Plurals::reset();
        parent::tearDown();
    }

    /**
     * @param list<string> $translations
     */
    private function load(string $header, array $translations = []): void
    {
        $mo = new \MO();
        $mo->set_header('Plural-Forms', $header);

        if ([] !== $translations) {
            $mo->add_entry(new \Translation_Entry([
                'singular' => '%s file selected',
                'plural' => '%s files selected',
                'translations' => $translations,
            ]));
        }

        $GLOBALS['l10n'][Plurals::DOMAIN] = $mo;
    }

    public function test_untranslated_is_english_two_forms_and_its_rule(): void
    {
        unset($GLOBALS['l10n'][Plurals::DOMAIN]);

        $this->assertSame(
            ['%s file selected', '%s files selected'],
            Plurals::forms(_n_noop('%s file selected', '%s files selected', 'folderfolio'))
        );
        $this->assertSame('n != 1', Plurals::rule());
    }

    public function test_a_three_form_language_sends_three_forms_in_its_order(): void
    {
        $this->load(
            'nplurals=3; plural=' . self::POLISH . ';',
            ['%s plik zaznaczony', '%s pliki zaznaczone', '%s plików zaznaczonych']
        );

        $this->assertSame(
            ['%s plik zaznaczony', '%s pliki zaznaczone', '%s plików zaznaczonych'],
            Plurals::forms(_n_noop('%s file selected', '%s files selected', 'folderfolio'))
        );
        $this->assertSame(self::POLISH, Plurals::rule(), 'the expression alone, its closing semicolon gone');
    }

    // Arabic's sixth form starts at 100: the search has to get that far.
    public function test_every_form_of_a_six_form_language_is_reached(): void
    {
        $this->load(
            'nplurals=6; plural=n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5;',
            ['f0', 'f1', 'f2', 'f3', 'f4', 'f5']
        );

        $this->assertSame(
            ['f0', 'f1', 'f2', 'f3', 'f4', 'f5'],
            Plurals::forms(_n_noop('%s file selected', '%s files selected', 'folderfolio'))
        );
    }

    public function test_a_string_the_catalogue_lacks_is_english_in_each_slot(): void
    {
        $this->load('nplurals=3; plural=' . self::POLISH . ';');

        // Form 0 is chosen by 1, forms 1 and 2 by 2 and 5 — English's plural.
        $this->assertSame(
            ['%s folder', '%s folders', '%s folders'],
            Plurals::forms(_n_noop('%s folder', '%s folders', 'folderfolio'))
        );
    }

    public function test_a_header_core_cannot_parse_is_the_default(): void
    {
        $this->load('nplurals=2; plural=n ** 2;');

        $this->assertSame('n != 1', Plurals::rule());
    }

    public function test_every_config_carries_the_rule(): void
    {
        $this->load('nplurals=3; plural=' . self::POLISH . ';');

        $this->assertStringContainsString(
            '"pluralRule":' . wp_json_encode(self::POLISH),
            ClientConfig::script(['i18n' => []])
        );
    }
}
