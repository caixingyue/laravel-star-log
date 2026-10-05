<?php

namespace Caixingyue\LaravelStarLog\Tests\Support;

use Caixingyue\LaravelStarLog\Http\Client\AttachedFileInfo;
use Caixingyue\LaravelStarLog\Support\HttpLogDataNormalizer;
use Caixingyue\LaravelStarLog\Support\Redactor;
use Caixingyue\LaravelStarLog\Support\SensitiveFieldRules;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Utils;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Stringable;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class HttpLogDataNormalizerTest extends TestCase
{
    public function test_child_exceptions_keep_other_branch_values_masked_in_arrays_and_embedded_json(): void
    {
        $fields = ['profile', 'items.*.token'];
        $rules = new SensitiveFieldRules($fields, [
            ['fields' => ['profile.phone', 'items.0.token'], 'sensitive' => false],
        ]);
        $normalizer = new HttpLogDataNormalizer($fields, fieldRules: $rules);

        $this->assertSame([
            'profile' => ['phone' => 'visible', 'token' => Redactor::MASK],
            'items' => [['token' => 'visible'], ['token' => Redactor::MASK]],
        ], $normalizer->prepare([
            'profile' => ['phone' => 'visible', 'token' => 'private'],
            'items' => [['token' => 'visible'], ['token' => 'private']],
        ]));
        $this->assertSame(['profile' => '{"phone":"visible","token":"******"}'], $normalizer->prepare([
            'profile' => '{"phone":"visible","token":"private"}',
        ]));
        $this->assertSame('https://example.test?profile[phone]=visible&profile[token]=******&profile[token]=******',
            $normalizer->redactUrl('https://example.test?profile[phone]=visible&profile[token]=private&profile[token]=private'));
    }

    public function test_child_exceptions_do_not_expose_opaque_or_scalar_serialized_parent_values(): void
    {
        $normalizer = new HttpLogDataNormalizer(['profile'], fieldRules: new SensitiveFieldRules(['profile'], [
            ['fields' => ['profile.phone'], 'sensitive' => false],
        ]));
        $scalar = new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return 'opaque private value';
            }
        };

        $this->assertSame(['profile' => Redactor::MASK], $normalizer->prepare(['profile' => 'opaque private value']));
        $this->assertSame(['profile' => Redactor::MASK], $normalizer->prepare(['profile' => $scalar]));
    }

    public function test_header_allowlist_matches_exact_names_case_insensitively_and_preserves_multiple_values(): void
    {
        $normalizer = new HttpLogDataNormalizer(['authorization']);
        $headers = [
            'X-Trace' => ['first', 'second'],
            'Authorization' => ['Bearer secret'],
            'X-Unlisted' => ['private'],
        ];

        $this->assertSame([
            'X-Trace' => ['first', 'second'],
            'Authorization' => Redactor::MASK,
        ], $normalizer->prepareHeaders($headers, [' x-trace ', 'AUTHORIZATION', 'X-TRACE', null, 123, '']));
        $this->assertNull($normalizer->prepareHeaders($headers, []));
        $this->assertNull($normalizer->prepareHeaders($headers, ['X-*', 'X', 'Missing']));
        $this->assertSame(['Bearer secret'], $headers['Authorization']);
    }

    public function test_header_values_and_counts_follow_existing_output_limits(): void
    {
        $normalizer = new HttpLogDataNormalizer([], ['max_string_length' => 4, 'max_array_items' => 2]);

        $this->assertSame([
            'X-Trace' => ['long…', 'next', '…' => '1 item(s) omitted'],
            'X-Other' => ['OK'],
            '…' => '1 item(s) omitted',
        ], $normalizer->prepareHeaders([
            'X-Trace' => ['long trace', 'next', 'omitted'],
            'X-Other' => ['OK'],
            'X-Last' => ['omitted'],
        ], ['x-trace', 'x-other', 'x-last']));
    }

    public function test_route_and_client_header_masking_uses_default_and_explicit_fields(): void
    {
        $config = require __DIR__ . '/../../config/starlog.php';

        foreach (['route', 'http_client'] as $section) {
            $normalizer = HttpLogDataNormalizer::fromConfig($config[$section]);
            $headers = [
                'Authorization' => ['Bearer secret'],
                'Cookie' => ['session=secret'],
                'Set-Cookie' => ['session=secret', 'token=secret'],
            ];
            $allowedHeaders = ['authorization', 'cookie', 'set-cookie'];
            $this->assertSame([
                'Authorization' => Redactor::MASK,
                'Cookie' => ['session=secret'],
                'Set-Cookie' => ['session=secret', 'token=secret'],
            ], $normalizer->prepareHeaders($headers, $allowedHeaders));

            $config[$section]['sensitive_fields'] = array_merge($config[$section]['sensitive_fields'], ['cookie', 'set-cookie']);
            $normalizer = HttpLogDataNormalizer::fromConfig($config[$section]);
            $this->assertSame([
                'Authorization' => Redactor::MASK,
                'Cookie' => Redactor::MASK,
                'Set-Cookie' => Redactor::MASK,
            ], $normalizer->prepareHeaders($headers, $allowedHeaders));
        }
    }

    public function test_url_preparation_omits_only_the_query_and_preserves_masking_encoding_and_fragments(): void
    {
        $normalizer = new HttpLogDataNormalizer(['username', 'password', 'token']);
        $url = 'https://alice:p%40ss@[::1]:8443/a%2Fb?keep=a%20b&token=secret&keep=c+d#section';

        $this->assertSame(
            'https://******:******@[::1]:8443/a%2Fb?keep=a%20b&token=******&keep=c+d#section',
            $normalizer->prepareUrl($url),
        );
        $this->assertSame('https://******:******@[::1]:8443/a%2Fb#section', $normalizer->prepareUrl($url, false));
        $this->assertSame('https://example.test#section?token=private', $normalizer->prepareUrl('https://example.test#section?token=private', false));
        $this->assertSame('https://example.test', $normalizer->prepareUrl('https://example.test?', false));
        $this->assertSame('https://example.test?token=******', $normalizer->prepareUrl('https://example.test?token=' . str_repeat('x', 70000)));
        $this->assertSame('https://example.test', $normalizer->prepareUrl('https://example.test?token=' . str_repeat('x', 70000), false));
    }

    public function test_redacts_and_bounds_nested_data(): void
    {
        $normalizer = new HttpLogDataNormalizer(['password'], [
            'max_string_length' => 4,
            'max_array_items' => 3,
            'max_depth' => 2,
        ]);

        $this->assertSame([
            'password' => Redactor::MASK,
            'name' => 'long…',
            'nested' => [
                'deeper' => '[maximum depth reached]',
            ],
            '…' => '1 item(s) omitted',
        ], $normalizer->prepare([
            'password' => 'secret',
            'name' => 'long customer name',
            'nested' => [
                'deeper' => [
                    'value' => 'not reached',
                ],
            ],
            'omitted' => 'not reached',
        ]));
    }

    public function test_creates_a_normalizer_from_http_log_configuration(): void
    {
        $normalizer = HttpLogDataNormalizer::fromConfig([
            'sensitive_fields' => ['token'],
            'limits' => ['max_string_length' => 4],
        ]);

        $this->assertSame(['token' => Redactor::MASK, 'name' => 'long…'], $normalizer->prepare([
            'token' => 'secret',
            'name' => 'long customer name',
        ]));
        $this->assertSame('https://example.test?token=******', $normalizer->redactUrl('https://example.test?token=secret'));
    }

    public function test_redacts_json_data_embedded_in_a_string(): void
    {
        $normalizer = new HttpLogDataNormalizer(['body.password']);

        $this->assertSame([
            'body' => '{"password":"******","name":"Taylor"}',
        ], $normalizer->prepare([
            'body' => '{"password":"secret","name":"Taylor"}',
        ]));
    }

    public function test_redacts_url_encoded_data_embedded_in_a_string(): void
    {
        $normalizer = new HttpLogDataNormalizer(['body.token']);

        $this->assertSame([
            'body' => 'token=******&name=Taylor',
        ], $normalizer->prepare([
            'body' => 'token=secret&name=Taylor',
        ]));
    }

    public function test_redacts_stringable_values_before_truncating_them(): void
    {
        $normalizer = new HttpLogDataNormalizer(['password']);
        $value = new class implements Stringable
        {
            public function __toString(): string
            {
                return '{"password":"secret","name":"Taylor"}';
            }
        };

        $this->assertSame('{"password":"******","name":"Taylor"}', $normalizer->prepare($value));
    }

    public function test_does_not_stringify_psr_stream_values(): void
    {
        $stream = new NoSeekStream(Utils::streamFor('private bytes'));

        $this->assertSame('[stream]', (new HttpLogDataNormalizer([]))->prepare($stream));
        $this->assertSame(0, $stream->tell());
    }

    public function test_describes_uploaded_files_without_the_temporary_path_or_contents(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'star-log-');
        file_put_contents($path, 'content');

        try {
            $file = new UploadedFile($path, 'avatar.png', 'image/png', null, true);
            $normalizer = new HttpLogDataNormalizer([], []);

            $this->assertSame([
                UploadedFile::class => [
                    'name' => 'avatar.png',
                    'extension' => 'png',
                    'mime' => 'image/png',
                    'size' => '7B',
                ],
            ], $normalizer->prepare($file));
        } finally {
            unlink($path);
        }
    }

    public function test_describes_client_files_with_their_runtime_class(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'star-log-');
        file_put_contents($path, 'content');

        try {
            $description = (new HttpLogDataNormalizer([], []))->describeFile(new SplFileInfo($path), 'document.txt');

            $this->assertSame('document.txt', $description[SplFileInfo::class]['name']);
            $this->assertSame('txt', $description[SplFileInfo::class]['extension']);
            $this->assertSame('7B', $description[SplFileInfo::class]['size']);
            $this->assertArrayNotHasKey('path', $description[SplFileInfo::class]);
        } finally {
            unlink($path);
        }
    }

    public function test_describes_inline_client_files_without_logging_their_contents(): void
    {
        $description = (new HttpLogDataNormalizer([], []))->describeAttachedFile(
            new AttachedFileInfo('document.txt', 12, 'text/plain'),
        );

        $this->assertSame([
            AttachedFileInfo::class => [
                'name' => 'document.txt',
                'extension' => 'txt',
                'mime' => 'text/plain',
                'size' => '12B',
            ],
        ], $description);
    }

    public function test_applies_the_body_limit_independently_from_string_limits(): void
    {
        $normalizer = new HttpLogDataNormalizer([], [
            'max_string_length' => 4,
            'max_body_length' => 8,
        ]);

        $this->assertSame('long cus…', $normalizer->truncateBody('long customer name'));
    }

    public function test_describes_bodies_with_a_stable_type_and_media_type(): void
    {
        $normalizer = new HttpLogDataNormalizer(['token']);

        $this->assertSame([
            'type' => 'json',
            'content_type' => 'application/json',
            'data' => ['token' => Redactor::MASK],
        ], $normalizer->describeDataBody('json', ['token' => 'secret'], 'Application/JSON; charset=UTF-8'));
        $this->assertSame(['type' => 'empty'], $normalizer->describeBody('empty'));
    }

    public function test_retains_malformed_json_as_bounded_text(): void
    {
        $normalizer = new HttpLogDataNormalizer(['password'], ['max_body_length' => 8]);

        $this->assertSame([
            'type' => 'text',
            'content_type' => 'application/json',
            'data' => '{"passwo…',
        ], $normalizer->describeJsonBody('{"password":"secret",', 'application/json'));
    }

    public function test_redacts_json_text_before_truncation_breaks_its_structure(): void
    {
        $normalizer = new HttpLogDataNormalizer(['password'], ['max_body_length' => 20]);
        $body = $normalizer->describeTextBody('{"password":"secret","name":"Taylor"}', 'text/plain');

        $this->assertStringNotContainsString('secret', $body['data']);
        $this->assertStringContainsString(Redactor::MASK, $body['data']);
        $this->assertSame('text', $body['type']);
    }

    public function test_records_html_summary_without_markup_or_unselected_document_text(): void
    {
        $html = <<<'HTML'
            <html><head><title>Example page</title><meta name="description" content="Page description"></head><body><h1>Main heading</h1><input name="password" value="secret"><script>const token = "secret";</script></body></html>
            HTML;
        $normalizer = new HttpLogDataNormalizer(['password', 'token'], ['max_body_length' => 24]);

        $this->assertSame([
            'type' => 'html',
            'content_type' => 'text/html',
            'length' => strlen($html),
            'summary' => ['title' => 'Example page', 'description' => 'Page description', 'heading' => 'Main heading'],
        ], $normalizer->describeHtmlBody($html, 'text/html; charset=UTF-8'));
    }

    public function test_html_summary_uses_existing_limits_and_sensitive_fields(): void
    {
        $html = '<!--' . str_repeat('x', 70000) . '--><title>Private title</title><meta name="description" content="Public description">';
        $normalizer = new HttpLogDataNormalizer(['title'], ['max_string_length' => 8, 'max_body_length' => 4]);
        $this->assertSame(['title' => Redactor::MASK, 'description' => 'Public d…'],
            $normalizer->describeHtmlBody($html)['summary']);
    }
}
