<?php

namespace Caixingyue\LaravelStarLog\Listeners\Http;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use JetBrains\PhpStorm\ArrayShape;
use SplFileInfo;

/**
 * Record Http client request log
 */
class RequestSendingToLog
{
    /**
     * The field should be replaced by "******" from the LOG
     */
    protected array $secretFields = [
        //
    ];

    /**
     * Handle the event.
     *
     * @param RequestSending $event
     * @return void
     */
    public function handle(RequestSending $event): void
    {
        $this->secretFields = StarLog::getConfig('http.secret_fields', []);

        if (StarLog::getConfig('http.enable', false)) {
            Log::info("{$event->request->method()}[{$event->request->url()}] - 请求报文:", $this->getRequestData($event->request));
        }
    }

    /**
     * Get request data and replace secret field data
     * PS:[null] is no-data
     *
     * @param Request $request
     * @return array
     */
    public function getRequestData(Request $request): array
    {
        $data = $request->data();

        if ($request->method() !== 'GET') {
            $collection = collect($data);
            $data = $collection->mapWithKeys(function ($item, $key) {
                $name = $key;
                $contents = $item;

                if (is_array($item)) {
                    $name = Arr::get($item, 'name', $key);
                    $contents = Arr::get($item, 'contents', $item);
                }

                if (is_resource($contents)) {
                    $meta = stream_get_meta_data($contents);
                    $fileInfo = new SplFileInfo($meta['uri']);
                    return [$name => $this->getUploadedFileInfo($fileInfo)];
                }

                if (in_array($name, $this->secretFields)) {
                    return [$name => '******'];
                }

                return [$name => $contents];
            })->toArray();
        }

        return $data === [] ? [null] : $data;
    }

    /**
     * Get uploaded file information
     *
     * @param SplFileInfo $file
     * @param int $sizePrecision
     * @return array
     */
    #[ArrayShape([SplFileInfo::class => "array"])]
    public function getUploadedFileInfo(SplFileInfo $file, int $sizePrecision = 2): array
    {
        // 获取文件名
        $name = $file->getFilename();

        // 获取文件扩展名
        $extension = $file->getExtension();

        // 获取文件类型
        $type = File::mimeType($file->getRealPath());

        // 获取文件大小（单位为字节）
        $size = $file->getSize();

        // 给文件大小附加合适的单位
        $unit = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];
        $fileSizeSuffix = round($size / pow(1024, ($i = floor(log($size, 1024)))), $sizePrecision) . '' . $unit[$i];

        // 获取文件路径
        $path = $file->getRealPath();

        return [
            SplFileInfo::class => [
                'name' => $name,
                'extension' => $extension,
                'type' => $type,
                'size' => $fileSizeSuffix,
                'path' => $path,
            ]
        ];
    }
}
