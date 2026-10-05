<?php

namespace Caixingyue\LaravelStarLog\Tests\Support;

use Caixingyue\LaravelStarLog\Support\HtmlLogSummaryExtractor;
use PHPUnit\Framework\TestCase;

final class HtmlLogSummaryExtractorTest extends TestCase
{
    public function test_extracts_bounded_document_metadata(): void
    {
        $html = <<<'HTML'
            <html><head><title>Example page</title><meta property="og:description" content="Example description"></head><body><h1>Example heading</h1></body></html>
            HTML;

        $this->assertSame([
            'title' => 'Example…',
            'description' => 'Example…',
            'heading' => 'Example…',
        ], (new HtmlLogSummaryExtractor(7))->extract($html));
    }

    public function test_returns_null_when_the_document_has_no_supported_metadata(): void
    {
        $this->assertNull((new HtmlLogSummaryExtractor)->extract('<html><body>Only body text</body></html>'));
    }

    public function test_uses_a_later_non_empty_description_when_an_earlier_one_is_empty(): void
    {
        $html = <<<'HTML'
            <meta name="description" content=""><meta property="og:description" content="Useful summary">
            HTML;

        $this->assertSame(['description' => 'Useful summary'], (new HtmlLogSummaryExtractor)->extract($html));
    }

    public function test_checks_the_property_attribute_even_when_name_is_present(): void
    {
        $html = '<meta name="keywords" property="og:description" content="Useful summary">';

        $this->assertSame(['description' => 'Useful summary'], (new HtmlLogSummaryExtractor)->extract($html));
    }

    public function test_does_not_truncate_when_the_maximum_text_length_is_not_positive(): void
    {
        $html = '<title>Example page</title>';

        $this->assertSame(['title' => 'Example page'], (new HtmlLogSummaryExtractor(0))->extract($html));
        $this->assertSame(['title' => 'Example page'], (new HtmlLogSummaryExtractor(-1))->extract($html));
    }

    public function test_preserves_existing_libxml_errors(): void
    {
        $previous = libxml_use_internal_errors(true);

        try {
            libxml_clear_errors();
            (new \DOMDocument)->loadXML('<broken>');
            $errors = libxml_get_errors();

            $this->assertNotEmpty($errors);
            $this->assertSame(['title' => 'Example'], (new HtmlLogSummaryExtractor)->extract('<title>Example</title>'));

            $remainingErrors = libxml_get_errors();

            $this->assertCount(count($errors), $remainingErrors);
            $this->assertSame($errors[0]->code, $remainingErrors[0]->code);
            $this->assertSame($errors[0]->message, $remainingErrors[0]->message);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
