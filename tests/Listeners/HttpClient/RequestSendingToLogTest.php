<?php

namespace Caixingyue\LaravelStarLog\Tests\Listeners\HttpClient;

use Caixingyue\LaravelStarLog\Http\Client\AttachedFileInfo;
use Caixingyue\LaravelStarLog\Listeners\HttpClient\RequestSendingToLog;
use Caixingyue\LaravelStarLog\StarLog as StarLogImplementation;
use Caixingyue\LaravelStarLog\Support\Redactor;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Http\Message\StreamInterface;

final class RequestSendingToLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('starlog.http_client', [
            'enable' => true,
            'sensitive_fields' => ['password', 'profile.password', 'token'],
            'limits' => ['max_string_length' => 12],
        ]);
        $this->refreshStarLog();
        Log::spy();
    }

    public function test_records_a_redacted_json_request(): void
    {
        $request = $this->request(
            'POST',
            'https://example.test/orders?token=url-secret',
            ['Content-Type' => 'application/json'],
            json_encode([
                'profile' => ['password' => 'body-secret'],
                'name' => 'Taylor',
            ], JSON_THROW_ON_ERROR),
        );

        (new RequestSendingToLog)->handle(new RequestSending($request));

        Log::shouldHaveReceived('info')->with(
            'POST[https://example.test/orders?token=******] - Request:',
            [
                'body' => [
                    'type' => 'json',
                    'content_type' => 'application/json',
                    'data' => [
                        'profile' => ['password' => Redactor::MASK],
                        'name' => 'Taylor',
                    ],
                ],
            ],
        )->once();
    }

    public function test_records_file_metadata_without_recording_inline_file_contents(): void
    {
        $request = $this->request('POST', 'https://example.test/upload', [
            'Content-Type' => 'multipart/form-data; boundary=test',
        ]);
        $request->withData([
            [
                'name' => 'document',
                'contents' => 'token=inline-secret',
                'filename' => 'document.txt',
                'headers' => ['Content-Type' => 'text/plain'],
            ],
        ]);

        (new RequestSendingToLog)->handle(new RequestSending($request));

        Log::shouldHaveReceived('info')->with(
            'POST[https://example.test/upload] - Request:',
            Mockery::on(static function (array $data): bool {
                return $data['body']['type'] === 'multipart'
                    && $data['body']['content_type'] === 'multipart/form-data'
                    && $data['body']['data']['document'][AttachedFileInfo::class] === [
                        'name' => 'document.txt',
                        'extension' => 'txt',
                        'mime' => 'text/plain',
                        'size' => '19B',
                    ];
            }),
        )->once();
    }

    public function test_does_not_record_when_http_client_logging_is_disabled(): void
    {
        config()->set('starlog.http_client.enable', false);
        $this->refreshStarLog();

        (new RequestSendingToLog)->handle(new RequestSending(
            $this->request('GET', 'https://example.test/orders'),
        ));

        Log::shouldNotHaveReceived('info');
    }

    public function test_recognizes_lowercase_headers_and_json_media_type_case(): void
    {
        $request = $this->request('POST', 'https://example.test', ['content-type' => 'Application/JSON; charset=UTF-8'], '{"password":"secret"}');
        (new RequestSendingToLog)->handle(new RequestSending($request));

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body'] === [
            'type' => 'json', 'content_type' => 'application/json', 'data' => ['password' => Redactor::MASK],
        ])->once();
    }

    public function test_recognizes_form_charset_and_masks_fields(): void
    {
        $request = $this->request('POST', 'https://example.test', ['content-type' => 'application/x-www-form-urlencoded; charset=UTF-8'], 'password=secret&name=Taylor');
        (new RequestSendingToLog)->handle(new RequestSending($request));

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body']['type'] === 'form'
            && $context['body']['data'] === ['password' => Redactor::MASK, 'name' => 'Taylor'])->once();
    }

    public function test_preserves_every_file_under_a_repeated_multipart_name(): void
    {
        $parts = [
            ['name' => 'files[]', 'contents' => 'first secret', 'filename' => 'first.txt'],
            ['name' => 'files[]', 'contents' => 'second secret', 'filename' => 'second.txt'],
        ];
        $stream = new MultipartStream($parts);
        $request = $this->request('POST', 'https://example.test', ['content-type' => 'Multipart/Form-Data; boundary=' . $stream->getBoundary()], $stream);
        $request->withData($parts);
        (new RequestSendingToLog)->handle(new RequestSending($request));

        $this->assertSame(0, $stream->tell());
        Log::shouldHaveReceived('info')->withArgs(static function ($message, $context): bool {
            $files = $context['body']['data']['files[]'];

            return $context['body']['type'] === 'multipart'
                && count($files) === 2
                && $files[0][AttachedFileInfo::class]['name'] === 'first.txt'
                && $files[1][AttachedFileInfo::class]['name'] === 'second.txt'
                && ! str_contains(json_encode($context), 'secret');
        })->once();
    }

    public function test_preserves_a_non_seekable_request_body(): void
    {
        $stream = new NoSeekStream(Utils::streamFor('upload content'));
        $request = $this->request('POST', 'https://example.test', ['Content-Type' => 'text/plain'], $stream);
        (new RequestSendingToLog)->handle(new RequestSending($request));

        $this->assertSame(0, $stream->tell());
        $this->assertSame('upload content', $stream->getContents());
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body']['type'] === 'stream')->once();
    }

    public function test_records_psr_stream_attachment_metadata_without_consuming_its_contents(): void
    {
        $file = new NoSeekStream(Utils::streamFor('private file contents'));
        $parts = [['name' => 'document', 'contents' => $file, 'filename' => 'document.txt', 'headers' => ['Content-Type' => 'text/plain']]];
        $multipart = new MultipartStream($parts);
        $request = $this->request('POST', 'https://example.test', ['Content-Type' => 'multipart/form-data; boundary=' . $multipart->getBoundary()], $multipart);
        $request->withData($parts);

        (new RequestSendingToLog)->handle(new RequestSending($request));

        $this->assertSame(0, $file->tell());
        $this->assertSame('private file contents', $file->getContents());
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['body']['data']['document'][NoSeekStream::class] === [
            'name' => 'document.txt', 'extension' => 'txt', 'mime' => 'text/plain', 'size' => '21B',
        ])->once();
    }

    public function test_restores_the_request_stream_position(): void
    {
        $stream = Utils::streamFor('{"password":"secret"}');
        $stream->seek(3);
        (new RequestSendingToLog)->handle(new RequestSending($this->request('POST', 'https://example.test', ['Content-Type' => 'application/json'], $stream)));

        $this->assertSame(3, $stream->tell());
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body']['data']['password'] === Redactor::MASK)->once();
    }

    public function test_omits_unknown_body_contents(): void
    {
        (new RequestSendingToLog)->handle(new RequestSending($this->request('POST', 'https://example.test', ['Content-Type' => 'application/custom'], 'private payload')));

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body'] === ['type' => 'unknown', 'content_type' => 'application/custom'])->once();
    }

    public function test_does_not_read_binary_request_bodies(): void
    {
        $reads = 0;
        $stream = FnStream::decorate(Utils::streamFor('file contents'), [
            'getContents' => function () use (&$reads): string {
                $reads++;

                return 'file contents';
            },
        ]);
        (new RequestSendingToLog)->handle(new RequestSending($this->request('POST', 'https://example.test', ['Content-Type' => 'application/pdf'], $stream)));

        $this->assertSame(0, $reads);
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body'] === ['type' => 'binary', 'content_type' => 'application/pdf'])->once();
    }

    public function test_omits_html_request_contents(): void
    {
        $html = '<title>Page</title><input name="password" value="secret">';
        (new RequestSendingToLog)->handle(new RequestSending($this->request('POST', 'https://example.test', ['Content-Type' => 'text/html'], $html)));

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body'] === ['type' => 'html', 'content_type' => 'text/html', 'length' => strlen($html), 'summary' => ['title' => 'Page']])->once();
    }

    private function request(string $method, string $url, array $headers = [], string|StreamInterface $body = ''): Request
    {
        return new Request(new PsrRequest($method, $url, $headers, $body));
    }

    private function refreshStarLog(): void
    {
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLogImplementation::class);
    }
}
