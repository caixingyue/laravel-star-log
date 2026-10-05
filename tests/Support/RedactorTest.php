<?php

namespace Caixingyue\LaravelStarLog\Tests\Support;

use Caixingyue\LaravelStarLog\Support\HttpLogDataNormalizer;
use Caixingyue\LaravelStarLog\Support\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    public function test_form_names_are_masked_under_both_wire_and_php_interpretations(): void
    {
        foreach (['password%00suffix', '%20password', 'api.key', 'api+key', 'api%5Bkey', 'profile%5Bpassword%5Dsuffix'] as $field) {
            $this->assertSame($field . '=******', Redactor::redactFormData($field . '=synthetic-secret', ['password', 'api_key', 'profile.password']));
        }
        $this->assertSame('tag=a&tag=b&flag', Redactor::redactFormData('tag=a&tag=b&flag', ['password']));
    }

    public function test_redacts_nested_sensitive_fields_case_insensitively(): void
    {
        $data = [
            'email' => 'user@example.com',
            'profile' => [
                'password' => 'secret',
                'TOKEN' => 'token-value',
                'password_hint' => 'not-secret',
            ],
            'credentials' => [
                'password' => [
                    'value' => 'nested-secret',
                ],
            ],
        ];

        $this->assertSame([
            'email' => 'user@example.com',
            'profile' => [
                'password' => Redactor::MASK,
                'TOKEN' => Redactor::MASK,
                'password_hint' => 'not-secret',
            ],
            'credentials' => [
                'password' => Redactor::MASK,
            ],
        ], Redactor::redact($data, ['*.password', '*.token']));
    }

    public function test_ignores_invalid_sensitive_field_configuration(): void
    {
        $this->assertTrue(Redactor::isSensitive('TOKEN', [['invalid'], 'token', null]));
        $this->assertFalse(Redactor::isSensitive('user_token', [['invalid'], 'token', null]));
    }

    public function test_redacts_only_the_query_part_of_a_url(): void
    {
        $url = 'https://example.com/token&password=path?TOKEN=secret&password%5Bvalue%5D=nested&page=2#token=fragment';

        $this->assertSame(
            'https://example.com/token&password=path?TOKEN=******&password%5Bvalue%5D=******&page=2#token=fragment',
            Redactor::redactUrl($url, ['token', 'password'])
        );
    }

    public function test_does_not_treat_a_fragment_as_a_query_string(): void
    {
        $url = 'https://example.com/path#section?token=fragment';

        $this->assertSame($url, Redactor::redactUrl($url, ['token']));
    }

    public function test_preserves_repeated_and_unchanged_query_parameters(): void
    {
        $url = 'https://example.com/path?tag=a&token=first&tag=b&empty&encoded=a%2Bb&token=second';

        $this->assertSame(
            'https://example.com/path?tag=a&token=******&tag=b&empty&encoded=a%2Bb&token=******',
            Redactor::redactUrl($url, ['token'])
        );
    }

    public function test_redacts_url_encoded_form_data_without_reordering_fields(): void
    {
        $data = 'tag=one&token=first&tag=two&password%5Bvalue%5D=nested&token=second';

        $this->assertSame(
            'tag=one&token=******&tag=two&password%5Bvalue%5D=******&token=******',
            Redactor::redactFormData($data, ['token', 'password'])
        );
    }

    public function test_redacts_sensitive_nested_form_fields_when_they_are_request_data_keys(): void
    {
        $this->assertSame([
            'profile[token]' => Redactor::MASK,
            'credentials[password]' => Redactor::MASK,
            'email' => 'user@example.com',
        ], Redactor::redact([
            'profile[token]' => 'nested-token',
            'credentials[password]' => 'nested-password',
            'email' => 'user@example.com',
        ], ['profile.token', 'credentials.password']));
    }

    public function test_returns_non_array_values_unchanged(): void
    {
        $this->assertSame('value', Redactor::redact('value', ['password']));
    }

    public function test_masks_url_credentials_with_and_without_a_query(): void
    {
        $this->assertSame('https://******:******@example.test/path?token=******', Redactor::redactUrl(
            'https://alice:password@example.test/path?token=secret', ['username', 'password', 'token'],
        ));
        $this->assertSame('https://******:******@example.test/path', Redactor::redactUrl('https://alice:password@example.test/path', ['username', 'password']));
        $this->assertSame('//******:******@example.test/path', Redactor::redactUrl('//alice:p%40ss@example.test/path', ['username', 'password']));
        $this->assertSame('https://example.test/alice@example.test', Redactor::redactUrl('https://example.test/alice@example.test', ['username', 'password']));
    }

    public function test_preserves_url_credential_separators_and_other_components(): void
    {
        $this->assertSame('https://******@example.test/path', Redactor::redactUrl('https://alice@example.test/path', ['username', 'password']));
        $this->assertSame('https://******:@example.test/path', Redactor::redactUrl('https://alice:@example.test/path', ['username', 'password']));
        $this->assertSame('https://:******@example.test/path', Redactor::redactUrl('https://:password@example.test/path', ['username', 'password']));
        $this->assertSame('https://******:******@[::1]:8443/a%2Fb?tag=one&token=******&tag=two#section', Redactor::redactUrl(
            'https://alice:p%40ss@[::1]:8443/a%2Fb?tag=one&token=secret&tag=two#section', ['username', 'password', 'token'],
        ));
    }

    public function test_preserves_url_credentials_that_are_not_configured_as_sensitive(): void
    {
        $url = 'https://alice:p%40ss@example.test/path?token=secret';

        $this->assertSame($url, Redactor::redactUrl($url, []));
        $this->assertSame('https://alice:p%40ss@example.test/path?token=******', Redactor::redactUrl($url, ['token']));
    }

    public function test_configures_username_and_password_redaction_independently(): void
    {
        $url = 'https://alice:p%40ss@example.test/path?token=secret';

        $this->assertSame('https://alice:******@example.test/path?token=secret', Redactor::redactUrl($url, ['password']));
        $this->assertSame('https://******:p%40ss@example.test/path?token=secret', Redactor::redactUrl($url, ['username']));
        $this->assertSame('https://******:******@example.test/path?token=secret', Redactor::redactUrl($url, ['USERNAME', 'PASSWORD']));
    }

    public function test_exact_and_wildcard_paths_preserve_public_fields_at_other_depths(): void
    {
        $data = ['token' => 'private', 'profile' => ['token' => 'public'],
            'items' => [['token' => 'private', 'profile' => ['token' => 'public']], ['token' => 'private']]];
        $expected = ['token' => Redactor::MASK, 'profile' => ['token' => 'public'],
            'items' => [['token' => Redactor::MASK, 'profile' => ['token' => 'public']], ['token' => Redactor::MASK]]];
        $fields = ['token', 'items.*.token'];

        $this->assertSame($expected, Redactor::redact($data, $fields));
        $this->assertSame($expected, (new HttpLogDataNormalizer($fields))->prepare($data));
        $this->assertSame('profile[token]=public&items[0][token]=******&items[0][profile][token]=public',
            Redactor::redactFormData('profile[token]=public&items[0][token]=private&items[0][profile][token]=public', $fields));
        $this->assertSame(['items' => [Redactor::MASK, ['token' => 'public']]], Redactor::redact([
            'items' => [['token' => 'private'], ['token' => 'public']],
        ], ['items.0']));
    }
}
