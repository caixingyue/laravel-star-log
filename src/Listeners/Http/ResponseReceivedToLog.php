<?php

namespace Caixingyue\LaravelStarLog\Listeners\Http;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Record Http client response log
 */
class ResponseReceivedToLog
{
    /**
     * Handle the event.
     *
     * @param ResponseReceived $event
     * @return void
     */
    public function handle(ResponseReceived $event): void
    {
        if (StarLog::getConfig('http.enable', false)) {
            $data = [
                Str::of('耗时[')->append($event->response->transferStats->getTransferTime())->append('s]'),
                Str::of($event->response->status())->append('[')->append($event->request->url())->append(']')
            ];

            $data = implode(' - ', $data);
            $responseData = $this->getResponseData($event->response);

            if (is_array($responseData)) {
                Log::info("{$data} - 响应报文:", $responseData);
            } else {
                Log::info("{$data} - 响应报文: {$responseData}");
            }
        }
    }

    /**
     * Get response data, if data is json then parsing json to array
     *
     * @param Response $response
     * @return mixed
     */
    public function getResponseData(Response $response): mixed
    {
        $data = $response->body();
        $contentType = $response->header('Content-Type');

        if ($this->isBinaryContent($contentType)) {
            return '(二进制流)';
        } elseif (Str::isJson($data)) {
            $data = json_decode($data, true);
        }

        return $data;
    }

    /**
     * Check if the data content is a binary file stream
     *
     * @param string|null $contentType
     * @return bool
     */
    private function isBinaryContent(?string $contentType): bool
    {
        if (is_null($contentType)) {
            return false;
        }

        $binaryTypes = [
            'application/octet-stream',
            'application/pdf',
            'application/zip',
            'application/x-tar',
            'application/x-gzip',
            'application/x-7z-compressed',
            'application/msword',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument',
            'image/',
            'video/',
            'audio/',
        ];

        foreach ($binaryTypes as $type) {
            if (str_starts_with($contentType, $type)) {
                return true;
            }
        }

        return false;
    }
}
