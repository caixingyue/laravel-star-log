<?php

namespace Caixingyue\LaravelStarLog\Tests\Listeners\HttpClient;

use Caixingyue\LaravelStarLog\Listeners\HttpClient\ResponseReceivedToLog;
use Caixingyue\LaravelStarLog\StarLog as StarLogImplementation;
use Caixingyue\LaravelStarLog\Support\Redactor;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;

final class ResponseReceivedToLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('starlog.http_client', [
            'enable' => true,
            'sensitive_fields' => ['password', 'token'],
            'limits' => ['max_body_length' => 4],
        ]);
        $this->refreshStarLog();
        Log::spy();
    }

    public function test_records_a_redacted_json_response(): void
    {
        $this->record('application/json', '{"password":"secret","name":"Taylor"}');

        Log::shouldHaveReceived('info')->with(
            'Duration[N/A] - 200[https://example.test/orders?token=******] - Response:',
            [
                'body' => [
                    'type' => 'json',
                    'content_type' => 'application/json',
                    'data' => ['password' => Redactor::MASK, 'name' => 'Taylor'],
                ],
            ],
        )->once();
    }

    public function test_records_binary_responses_by_type_and_content_type(): void
    {
        $this->record('Application/PDF', '%PDF-1.7');

        Log::shouldHaveReceived('info')->with(
            'Duration[N/A] - 200[https://example.test/orders?token=******] - Response:',
            ['body' => ['type' => 'binary', 'content_type' => 'application/pdf']],
        )->once();
    }

    public function test_truncates_plain_text_response_bodies(): void
    {
        $this->record('text/plain', 'long response');

        Log::shouldHaveReceived('info')->with(
            'Duration[N/A] - 200[https://example.test/orders?token=******] - Response:',
            ['body' => ['type' => 'text', 'content_type' => 'text/plain', 'data' => 'long…']],
        )->once();
    }

    public function test_records_html_responses_by_type_and_length(): void
    {
        $body = '<html><body>response</body></html>';
        $this->record('text/html; charset=UTF-8', $body);

        Log::shouldHaveReceived('info')->with(
            'Duration[N/A] - 200[https://example.test/orders?token=******] - Response:',
            ['body' => [
                'type' => 'html',
                'content_type' => 'text/html',
                'length' => strlen($body),
            ]],
        )->once();
    }

    public function test_does_not_record_when_http_client_logging_is_disabled(): void
    {
        config()->set('starlog.http_client.enable', false);
        $this->refreshStarLog();

        $this->record('application/json', '{"ok":true}');

        Log::shouldNotHaveReceived('info');
    }

    public function test_records_html_title_without_input_or_script_contents(): void
    {
        $html = '<html><head><title>Page</title></head><body><input name="password" value="secret"><script>{"token":"secret"}</script></body></html>';
        $this->record('text/html', $html);

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body'] === [
            'type' => 'html', 'content_type' => 'text/html', 'length' => strlen($html), 'summary' => ['title' => 'Page'],
        ])->once();
    }

    public function test_does_not_read_binary_response_bodies(): void
    {
        $reads = 0;
        $stream = FnStream::decorate(Utils::streamFor(str_repeat('binary', 1000)), [
            'getContents' => function () use (&$reads): string {
                $reads++;

                return 'binary contents';
            },
        ]);
        $stream->seek(2);
        $this->record('application/gzip', $stream);

        $this->assertSame(0, $reads);
        $this->assertSame(2, $stream->tell());
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body'] === ['type' => 'binary', 'content_type' => 'application/gzip'])->once();
    }

    public function test_preserves_a_non_seekable_response_body(): void
    {
        $stream = new NoSeekStream(Utils::streamFor('stream content'));
        $this->record('text/plain', $stream);

        $this->assertSame(0, $stream->tell());
        $this->assertSame('stream content', $stream->getContents());
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body']['type'] === 'stream')->once();
    }

    public function test_restores_the_response_stream_position(): void
    {
        $stream = Utils::streamFor('{"token":"secret"}');
        $stream->seek(4);
        $this->record('application/json', $stream);

        $this->assertSame(4, $stream->tell());
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body']['data']['token'] === Redactor::MASK)->once();
    }

    public function test_omits_unknown_response_contents_but_recognizes_valid_json(): void
    {
        $this->record('application/custom', 'private content');
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body'] === ['type' => 'unknown', 'content_type' => 'application/custom'])->once();

        $this->record('application/custom', '{"token":"secret"}');
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body']['type'] === 'json' && $context['body']['data']['token'] === Redactor::MASK)->once();
    }

    public function test_marks_duration_as_unavailable_when_transfer_statistics_have_no_time(): void
    {
        $stats = new TransferStats(new PsrRequest('GET', 'https://example.test'), null, null);
        $this->record('application/json', '{"ok":true}', $stats);

        Log::shouldHaveReceived('info')->with(
            'Duration[N/A] - 200[https://example.test/orders?token=******] - Response:',
            ['body' => ['type' => 'json', 'content_type' => 'application/json', 'data' => ['ok' => true]]],
        )->once();
    }

    public function test_preserves_a_measured_zero_duration(): void
    {
        $stats = new TransferStats(new PsrRequest('GET', 'https://example.test'), null, 0.0);
        $this->record('application/json', '{"ok":true}', $stats);

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'Duration[0s] - 200[https://example.test/orders?token=******] - Response:')->once();
    }

    public function test_formats_an_available_duration_in_seconds(): void
    {
        $stats = new TransferStats(new PsrRequest('GET', 'https://example.test'), null, 0.1234);
        $this->record('application/json', '{"ok":true}', $stats);

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'Duration[0.12s] - 200[https://example.test/orders?token=******] - Response:')->once();
    }

    public function test_does_not_round_a_small_nonzero_duration_to_zero(): void
    {
        $stats = new TransferStats(new PsrRequest('GET', 'https://example.test'), null, 0.000123);
        $this->record('application/json', '{"ok":true}', $stats);

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'Duration[0.000123s] - 200[https://example.test/orders?token=******] - Response:')->once();
    }

    public function test_marks_unavailable_duration_in_the_chinese_response_template(): void
    {
        config()->set('starlog.locale', 'zh_CN');
        $this->refreshStarLog();
        $this->record('application/json', '{"ok":true}');

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === '耗时[N/A] - 200[https://example.test/orders?token=******] - 响应报文:')->once();
    }

    public function test_uses_the_chinese_response_template_with_measured_duration(): void
    {
        config()->set('starlog.locale', 'zh_CN');
        $this->refreshStarLog();
        $stats = new TransferStats(new PsrRequest('GET', 'https://example.test'), null, 0.1234);
        $this->record('application/json', '{"ok":true}', $stats);

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === '耗时[0.12s] - 200[https://example.test/orders?token=******] - 响应报文:')->once();
    }

    private function record(string $contentType, string|StreamInterface $body, ?TransferStats $stats = null): void
    {
        $request = new Request(new PsrRequest('GET', 'https://example.test/orders?token=url-secret'));
        $response = new Response(new PsrResponse(200, ['Content-Type' => $contentType], $body));
        $response->transferStats = $stats;

        (new ResponseReceivedToLog)->handle(new ResponseReceived($request, $response));
    }

    private function refreshStarLog(): void
    {
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLogImplementation::class);
    }
}
