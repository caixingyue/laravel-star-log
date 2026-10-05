<?php

namespace Caixingyue\LaravelStarLog\Tests\Support;

use Caixingyue\LaravelStarLog\Support\HttpContentType;
use Caixingyue\LaravelStarLog\Support\HttpLogDataNormalizer;
use PHPUnit\Framework\TestCase;

final class HttpContentTypeTest extends TestCase
{
    public function test_headers_and_body_descriptions_use_the_same_media_type_normalization(): void
    {
        $normalizer = new HttpLogDataNormalizer([]);

        foreach (['Application/JSON; charset=utf-8', ' TEXT/PLAIN ', '', ' ; charset=utf-8'] as $header) {
            $type = HttpContentType::normalizeContentType($header);
            $body = $normalizer->describeBody('unknown', $header);

            $this->assertSame($type, $body['content_type'] ?? null);
        }
    }

    public function test_binary_and_supported_text_types_are_classified_independently(): void
    {
        foreach (['image/png', 'application/pdf', 'application/octet-stream'] as $type) {
            $this->assertTrue(HttpContentType::isBinaryContentType($type));
            $this->assertFalse(HttpContentType::isTextContentType($type));
        }

        foreach (['text/plain', 'application/xml', 'application/problem+xml', 'application/x-www-form-urlencoded'] as $type) {
            $this->assertTrue(HttpContentType::isTextContentType($type));
            $this->assertFalse(HttpContentType::isBinaryContentType($type));
        }

        $this->assertFalse(HttpContentType::isBinaryContentType(null));
        $this->assertFalse(HttpContentType::isTextContentType(null));
    }
}
