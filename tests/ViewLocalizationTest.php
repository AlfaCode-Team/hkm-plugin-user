<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\User;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Plugins\I18n\Support\Lang;
use Plugins\I18n\Translator;

/**
 * The User plugin's screens and its verification email must render translated
 * copy.
 *
 * A catalogue existing is not the same as a view using it: a template still
 * holding a hard-coded sentence passes every catalogue test and still serves
 * English to a French user.
 */
#[CoversNothing]
final class ViewLocalizationTest extends TestCase
{
    protected function tearDown(): void
    {
        Lang::clear();
    }

    /**
     * module.json declares the catalogue with "global": false, so the `user`
     * NAMESPACE is the only route in — registering it as a global path would
     * resolve nothing and every key would render as itself.
     */
    private function bindLocale(string $locale): void
    {
        Lang::bind(new Translator(
            directory:  [],
            locale:     $locale,
            fallback:   'en',
            namespaces: ['user' => [dirname(__DIR__) . '/resources/lang']],
        ));
    }

    /** @param array<string,mixed> $data */
    private function render(string $view, array $data = []): string
    {
        $file = dirname(__DIR__) . '/resources/views/' . $view . '.php';
        self::assertFileExists($file);

        extract($data + [
            'title' => null, 'apiBase' => '/ajx', 'csrf' => 'tok', 'view' => '',
            'verifyUrl' => 'https://example.test/verify?token=abc', 'token' => 'abc',
            'appName' => 'Acme', 'email' => 'user@example.com',
        ], EXTR_SKIP);
        ob_start();
        include $file;

        return (string) ob_get_clean();
    }

    /** @return list<string> */
    private static function views(): array
    {
        return [
            'layouts/app', 'user', 'users/index', 'users/create', 'users/edit', 'users/show',
            'account/settings', 'account/verify', 'account/feedback', 'emails/verify',
        ];
    }

    private function renderAll(): string
    {
        $html = '';
        foreach (self::views() as $v) {
            $html .= $this->render($v);
        }

        return $html;
    }

    public function test_user_list_renders_french(): void
    {
        $this->bindLocale('fr');
        $html = $this->render('users/index');

        $this->assertStringContainsString('Utilisateurs', $html);
        $this->assertStringContainsString('Aucun utilisateur pour le moment.', $html);
        $this->assertStringNotContainsString('>No users yet.<', $html);
    }

    public function test_settings_screen_renders_french(): void
    {
        $this->bindLocale('fr');
        $html = $this->render('account/settings');

        $this->assertStringContainsString('Fuseau horaire', $html);
        $this->assertStringContainsString('Confidentialité', $html);
    }

    /**
     * "Language" and "Locale" are NOT synonyms — one is the display language,
     * the other the full formatting context. Collapsing them into a single
     * French word would leave two settings with the same label.
     */
    public function test_language_and_locale_stay_distinct_in_french(): void
    {
        $this->bindLocale('fr');
        $html = $this->render('account/settings');

        $this->assertStringContainsString('Langue', $html);
        $this->assertStringContainsString('Paramètres régionaux', $html);
    }

    public function test_verification_email_renders_french_and_declares_its_language(): void
    {
        $this->bindLocale('fr');
        $html = $this->render('emails/verify');

        $this->assertStringContainsString('Confirmez votre adresse e-mail', $html);
        $this->assertStringContainsString('lang="fr"', $html);
        $this->assertStringNotContainsString('Confirm your email', $html);
    }

    /**
     * The edit form's hint says an empty password field KEEPS the current one.
     * The opposite reading would have an operator clear a password by accident,
     * so it has to survive translation intact.
     */
    public function test_the_partial_update_warning_is_translated(): void
    {
        $this->bindLocale('fr');
        $html = $this->render('users/edit');

        $this->assertStringContainsString('laissez le mot de passe vide pour le conserver', $html);
    }

    public function test_no_english_copy_survives_a_french_render(): void
    {
        $this->bindLocale('fr');
        $html = $this->renderAll();

        foreach ([
            '>Users<', '>No users yet.<', '>Create account<', '>Save changes<',
            '>Verify your email<', '>Submit feedback<', '>Timezone<', '>Privacy<',
        ] as $english) {
            $this->assertStringNotContainsString($english, $html, "untranslated: {$english}");
        }
    }

    public function test_no_raw_translation_keys_leak_into_the_output(): void
    {
        foreach (['en', 'fr'] as $locale) {
            $this->bindLocale($locale);
            $this->assertStringNotContainsString(
                'user::',
                $this->renderAll(),
                "[{$locale}] an unresolved key reached the output",
            );
        }
    }

    /**
     * API endpoints shown as developer hints are identifiers, not copy. A bulk
     * replacement sweeping one into the catalogue would leave the screen
     * describing an endpoint that does not exist.
     */
    public function test_api_endpoints_are_not_translated(): void
    {
        $this->bindLocale('fr');

        $this->assertStringContainsString('/ajx/users', $this->render('users/index'));
        $this->assertStringContainsString('/ajx/users/verify', $this->render('account/verify'));
    }
}
