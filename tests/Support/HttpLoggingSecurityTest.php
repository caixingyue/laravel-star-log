<?php

namespace Caixingyue\LaravelStarLog\Tests\Support;

use Caixingyue\LaravelStarLog\Support\HttpLogDataNormalizer;
use Caixingyue\LaravelStarLog\Support\Redactor;
use JsonSerializable;
use PHPUnit\Framework\TestCase;

final class HttpLoggingSecurityTest extends TestCase
{
    public function test_sensitive_fields_support_specific_paths_without_override_rules(): void
    {
        $fields = ['password.value', 'user.token', 'profile.private', 'metadata.user.token'];
        $normalizer = HttpLogDataNormalizer::fromConfig(['sensitive_fields' => $fields]);
        $data = [
            'password' => ['policy' => 'minimum-length-8', 'value' => 'secret'],
            'pagination' => ['token' => 'cursor'], 'user' => ['token' => 'secret'],
            'profile' => ['private' => 'secret', 'name' => 'Taylor'],
        ];
        $expected = [
            'password' => ['policy' => 'minimum-length-8', 'value' => Redactor::MASK],
            'pagination' => ['token' => 'cursor'], 'user' => ['token' => Redactor::MASK],
            'profile' => ['private' => Redactor::MASK, 'name' => 'Taylor'],
        ];

        $this->assertSame($expected, $normalizer->prepare($data));
        $this->assertSame($expected, Redactor::redact($data, $fields));
        $output = $normalizer->prepare(['metadata' => '{"pagination":{"token":"cursor"},"user":{"token":"secret"}}']);
        $this->assertSame(['pagination' => ['token' => 'cursor'], 'user' => ['token' => Redactor::MASK]], json_decode($output['metadata'], true));
        $this->assertSame('https://example.test?pagination%5Btoken%5D=cursor&user%5Btoken%5D=******', $normalizer->redactUrl(
            'https://example.test?pagination%5Btoken%5D=cursor&user%5Btoken%5D=secret',
        ));
    }

    public function test_ordinary_bracketed_messages_and_templates_are_preserved(): void
    {
        $normalizer = new HttpLogDataNormalizer(['password', '*.password']);
        $data = ['message' => '[OK] import finished', 'template' => '{customer_name}', 'password' => 'secret'];
        $expected = array_replace($data, ['password' => Redactor::MASK]);

        $this->assertSame($expected, $normalizer->prepare($data));
        $this->assertSame($expected, $normalizer->describeJsonBody(json_encode($data), 'application/json')['data']);

        foreach (['[OK] import finished', '{customer_name}', '[2026] receipt.pdf'] as $text) {
            $this->assertSame($text, $normalizer->describeTextBody($text, 'text/plain')['data']);
        }
        $this->assertSame('[1,2]', $normalizer->prepare('[1,2]'));
        $this->assertSame('[1,{"password":"******"}]', $normalizer->prepare('[1,{"password":"secret"}]'));
    }

    public function test_cyclic_arrays_are_bounded_before_redaction_traversal(): void
    {
        $data = ['password' => 'secret'];
        $data['self'] = &$data;

        $output = (new HttpLogDataNormalizer(['password'], ['max_depth' => 4]))->prepare($data);

        $this->assertSame(Redactor::MASK, $output['password']);
        $this->assertStringContainsString('[maximum depth reached]', json_encode($output));
        $this->assertStringContainsString('[redaction limit reached]', json_encode(Redactor::redact($data, ['password'])));
    }

    public function test_self_serializing_objects_cannot_recurse_indefinitely(): void
    {
        $object = new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return $this;
            }
        };

        $this->assertSame('[maximum depth reached]', (new HttpLogDataNormalizer([]))->prepare($object));
    }

    public function test_dotted_and_bracketed_sensitive_names_are_masked_in_data_and_urls(): void
    {
        $normalizer = new HttpLogDataNormalizer(['profile.password']);

        $this->assertSame(['profile.password' => Redactor::MASK, 'profile[password]' => Redactor::MASK], $normalizer->prepare([
            'profile.password' => 'secret', 'profile[password]' => 'secret',
        ]));
        $this->assertSame('https://example.test?profile.password=******', $normalizer->redactUrl('https://example.test?profile.password=secret'));
    }

    public function test_text_is_recorded_and_recognized_fields_are_redacted(): void
    {
        $normalizer = new HttpLogDataNormalizer(['password']);

        $this->assertSame(['type' => 'text', 'content_type' => 'text/plain', 'data' => 'OK'], $normalizer->describeTextBody('OK', 'text/plain'));
        $this->assertSame('password=******&name=Taylor', $normalizer->describeTextBody('password=secret&name=Taylor', 'text/plain')['data']);
    }

    public function test_long_array_keys_cannot_bypass_value_length_limits(): void
    {
        $output = (new HttpLogDataNormalizer(['password']))->prepare([str_repeat('x', 10000) => 'value']);

        $this->assertLessThan(1100, strlen(array_key_first($output)));
        $this->assertSame('value', array_values($output)[0]);
    }

    public function test_malformed_embedded_json_is_preserved_without_guessing_sensitive_values(): void
    {
        $normalizer = HttpLogDataNormalizer::fromConfig(['sensitive_fields' => ['password']]);

        $this->assertSame('{"password":"secret",', $normalizer->describeTextBody('{"password":"secret",', 'text/plain')['data']);
        $this->assertSame(['metadata' => '{"password":"secret",'], $normalizer->prepare(['metadata' => '{"password":"secret",']));
        $this->assertSame('[1,{"password":"secret",', $normalizer->prepare('[1,{"password":"secret",'));
    }

    public function test_existing_limits_can_leave_large_structured_payloads_complete(): void
    {
        $normalizer = new HttpLogDataNormalizer(['password'], ['max_body_length' => 8]);
        $data = ['message' => str_repeat('x', 70000), 'items' => range(1, 1200), 'password' => 'secret'];

        $this->assertSame(array_replace($data, ['password' => Redactor::MASK]),
            $normalizer->describeJsonBody(json_encode($data), 'application/json')['data']);
    }
}
