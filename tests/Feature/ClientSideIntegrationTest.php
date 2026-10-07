<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Guards for two classes of production bug that are invisible in development.
 *
 *   1. **An upstream API called from the browser.** It publishes whatever
 *      credential it needs — one page fetched the data-plan catalogue from
 *      NelloBytes with the account id in the query string — and it moves a
 *      pricing decision into code the customer controls.
 *   2. **A request that finishes without telling the customer anything.** Flash
 *      messages only appear where `partials/flash` is included, and two of the
 *      three layouts did not include it, so a successful contact form and a
 *      refused purchase both looked exactly like nothing happening.
 *
 * Both are properties of the templates themselves, so they are asserted against
 * the source rather than by rendering pages.
 */
class ClientSideIntegrationTest extends TestCase
{
    /** Hosts and identifiers that must never reach a browser. */
    private const FORBIDDEN = [
        'nellobytesystems.com',
        'clubkonnect.com',
        'pairgate.com',
        'payvessel',
        'UserID=',
        'CLUBKONNECT_API_KEY',
        'PAIRGATE_API_KEY',
        'PAIRGATE_WEBHOOK_SECRET',
    ];

    public function test_no_template_or_script_talks_to_a_provider_api_directly(): void
    {
        $files = $this->clientSideFiles();

        $this->assertNotEmpty($files);

        foreach ($files as $file => $contents) {
            foreach (self::FORBIDDEN as $needle) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $needle,
                    $contents,
                    "{$file} references [{$needle}]. Provider calls and pricing belong on the server."
                );
            }
        }
    }

    public function test_every_layout_renders_flash_messages(): void
    {
        foreach (['app', 'main', 'guest'] as $layout) {
            $path = resource_path("views/layouts/{$layout}.blade.php");

            $this->assertFileExists($path);

            $this->assertStringContainsString(
                "@include('partials.flash')",
                (string) file_get_contents($path),
                "layouts/{$layout}.blade.php does not render flash messages, so a result redirected back to it is invisible."
            );
        }
    }

    public function test_the_global_form_and_feedback_behaviour_is_loaded(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("import './forms'", $app);
        $this->assertStringContainsString("import './feedback'", $app);
        $this->assertFileExists(resource_path('js/forms.js'));
        $this->assertFileExists(resource_path('js/feedback.js'));
    }

    public function test_the_data_plan_list_comes_from_this_application(): void
    {
        $view = (string) file_get_contents(resource_path('views/airtime-data/index.blade.php'));

        $this->assertStringContainsString("route('pricelist.api')", $view);

        // Our wholesale cost has no business in the browser, and the form must
        // not carry a price the server would trust.
        $this->assertStringNotContainsString('plan_base_price', $view);
    }

    /**
     * Every authored template and script, keyed by a readable path.
     *
     * @return array<string, string>
     */
    private function clientSideFiles(): array
    {
        $files = [];

        foreach ([resource_path('views'), resource_path('js')] as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'js'], true)) {
                    continue;
                }

                $key = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());

                $files[$key] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }
}
