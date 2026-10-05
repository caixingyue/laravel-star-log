<?php

namespace Caixingyue\LaravelStarLog\Support;

use DOMDocument;
use Throwable;

/**
 * Extract a concise, bounded summary from an HTML document for log output.
 */
final readonly class HtmlLogSummaryExtractor
{
    /**
     * Set the summary text length limit.
     */
    public function __construct(
        private ?int $maximumTextLength = null,
    ) {}

    /**
     * Extract the title, description, and first heading without loading HTML resources.
     *
     * @return array{title?: string, description?: string, heading?: string}|null
     */
    public function extract(string $html): ?array
    {
        try {
            $document = new DOMDocument;

            if (! $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET)) {
                return null;
            }

            $description = null;

            foreach ($document->getElementsByTagName('meta') as $meta) {
                foreach (['name', 'property'] as $attribute) {
                    $name = strtolower($meta->getAttribute($attribute));

                    if (! in_array($name, ['description', 'og:description'], true)) {
                        continue;
                    }

                    $description = $this->text($meta->getAttribute('content'));

                    if ($description !== null) {
                        break 2;
                    }
                }
            }

            $summary = array_filter([
                'title' => $this->text($document->getElementsByTagName('title')->item(0)?->textContent),
                'description' => $description,
                'heading' => $this->text($document->getElementsByTagName('h1')->item(0)?->textContent),
            ], static fn (?string $item): bool => $item !== null);

            return $summary === [] ? null : $summary;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Normalize one extracted value for bounded log output.
     */
    private function text(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = preg_replace('/\s+/', ' ', trim($value));

        if (! is_string($value) || $value === '') {
            return null;
        }

        if ($this->maximumTextLength === null || $this->maximumTextLength <= 0 || mb_strlen($value) <= $this->maximumTextLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $this->maximumTextLength)) . '…';
    }
}
