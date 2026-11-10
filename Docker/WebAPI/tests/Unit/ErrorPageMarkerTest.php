<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/errors.php';

/**
 * C5: the browser specs find the portal error page by one attribute
 * (tests/e2e/lib/error-page.js). The node mirror test reads the source; this
 * test renders the page and proves the attribute reaches the HTML, on <main>,
 * once, with and without debug details, also when the page is appended below an
 * already sent portal page.
 */
final class ErrorPageMarkerTest extends TestCase
{
    private string|false $debugBefore = false;

    protected function setUp(): void
    {
        $this->debugBefore = getenv('VIRTUSPHERE_DEBUG');
    }

    protected function tearDown(): void
    {
        putenv($this->debugBefore === false ? 'VIRTUSPHERE_DEBUG' : 'VIRTUSPHERE_DEBUG=' . $this->debugBefore);
    }

    public function testTheErrorPageCarriesItsMarkerOnceOnMain(): void
    {
        foreach (['0', '1'] as $debug) {
            putenv('VIRTUSPHERE_DEBUG=' . $debug);
            $marked = $this->markedElements($this->render());

            self::assertCount(1, $marked, 'debug=' . $debug . ': exactly one marked element');
            self::assertSame('main', $marked[0]->localName, 'debug=' . $debug . ': the marker sits on <main>');
        }
    }

    public function testTheMarkerSurvivesBelowAnAlreadyRenderedPortalPage(): void
    {
        putenv('VIRTUSPHERE_DEBUG=0');
        $partial = "<!doctype html>\n<html lang=\"en\"><head><title>Portal</title></head><body><main><h1>Missions</h1><p>half a page";
        $marked = $this->markedElements($partial . $this->render());

        self::assertCount(1, $marked);
        self::assertSame('main', $marked[0]->localName);
    }

    public function testAnOrdinaryPageHasNoMarker(): void
    {
        $page = "<!doctype html>\n<html lang=\"en\"><head><title>Portal</title></head><body><main><h1>Missions</h1></main></body></html>";

        self::assertCount(0, $this->markedElements($page));
    }

    private function render(): string
    {
        ob_start();
        try {
            virtusphere_render_error_page(new RuntimeException('synthetic failure'), '0123456789abcdef');
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * @return list<\Dom\Element>
     */
    private function markedElements(string $html): array
    {
        $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);

        return iterator_to_array($document->querySelectorAll('[' . VIRTUSPHERE_ERROR_PAGE_ATTRIBUTE . ']'), false);
    }
}
