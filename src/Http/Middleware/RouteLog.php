<?php

namespace Caixingyue\LaravelStarLog\Http\Middleware;

use Caixingyue\LaravelStarLog\Agent;
use Caixingyue\LaravelStarLog\Facades\StarLog;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JetBrains\PhpStorm\ArrayShape;

/**
 * Log manager for routing requests and responses
 */
class RouteLog
{
    /**
     * The URIs that should be excluded from LOG record.
     *
     * @var array
     */
    protected array $except = [
        //
    ];

    /**
     * The method that should be excluded from LOG record.
     *
     * @var array
     */
    protected array $exceptMethod = [
//        'GET'
    ];

    /**
     * The field should be replaced by "******" from the LOG
     *
     * @var array
     */
    protected array $secretField = [
        //
    ];

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $this->setConfig();

        $this->request($request);

        $response = $next($request);

        $this->response($request, $response);

        return $response;
    }

    /**
     * Set config info
     *
     * @return void
     */
    private function setConfig(): void
    {
        $this->except = StarLog::getConfig('route.except', []);
        $this->exceptMethod = StarLog::getConfig('route.except_method', []);
        $this->secretField = StarLog::getConfig('route.secret_fields', []);
    }

    /**
     * The write request message to LOG
     *
     * @param Request $request
     */
    public function request(Request $request): void
    {
        if ($this->isExceptMethod($request) && $this->inExceptArray($request)) {
            $data = [
                Str::of('[')->append($this->getTerminalName())->append(']'),
                Str::of('[')->append($request->ip())->append(']'),
                Str::of($request->getMethod())->append('[')->append($request->decodedPath())->append(']')
            ];

            $data = implode(' - ', $data);
            Log::info("{$data} - 请求报文:", $this->getRequestData($request));
        }
    }

    /**
     * The write response message to LOG
     *
     * @param Request $request
     * @param JsonResponse|Response|RedirectResponse $response
     * @return void
     */
    public function response(Request $request, JsonResponse|Response|RedirectResponse $response): void
    {
        if ($this->isExceptMethod($request) && $this->inExceptArray($request)) {
            $data = [
                Str::of('耗时[')->append($this->getElapsedTime($request))->append(']'),
                Str::of('内存消耗[')->append($this->getMemoryUsage())->append(']')
            ];

            $data = implode(' - ', $data);
            $responseData = $this->getResponseData($response);
            if (is_array($responseData)) {
                Log::info("{$data} - 响应报文:", $responseData);
            } else {
                Log::info("{$data} - 响应报文: {$responseData}");
            }
        }
    }

    /**
     * Determines whether the HTTP request has a verb that should be verb.
     *
     * @param Request $request
     * @return bool
     */
    public function isExceptMethod(Request $request): bool
    {
        return !in_array($request->method(), $this->exceptMethod);
    }

    /**
     * Determine if the request has a URI that should be recorded.
     *
     * @param Request $request
     * @return bool
     */
    public function inExceptArray(Request $request): bool
    {
        foreach ($this->except as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            if ($request->fullUrlIs($except) || $request->is($except)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get request terminal name
     *
     * @return string
     */
    public function getTerminalName(): string
    {
        try {
            $agent = new Agent();

            $device = $agent->device();
            $platform = $agent->platform();

            $data = [$device, $platform];

            if ($agent->isDesktop()) {
                $data[] = 'PC端';
            } elseif ($agent->isMobile()) {
                $data[] = '移动端';
            } else {
                $data[] = '未知终端';
            }

            $data = array_filter($data);
            return implode('|', $data);
        } catch (\Exception $e) {
            return 'Unknown';
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
        $data = $request->all();

        foreach ($data as $key => $value){
            if (in_array($key, $this->secretField)) data_set($data, $key, '******');
            if ($value instanceof UploadedFile) data_set($data, $key, $this->getUploadedFileInfo($value));
        }

        return $data === [] ? [null] : $data;
    }

    /**
     * Get response data, if data is json then parsing json to array
     *
     * @param JsonResponse|Response|RedirectResponse $response
     * @return mixed
     */
    public function getResponseData(JsonResponse|Response|RedirectResponse $response): mixed
    {
        $data = $response->getContent();

        if (Str::isJson($data)) {
            $data = json_decode($data, true);
        }

        return $data;
    }

    /**
     * Get the full time from request to response
     *
     * @param Request $request
     * @param int $decimals
     * @return string
     */
    public function getElapsedTime(Request $request, int $decimals  = 2): string
    {
        return number_format(microtime(true) - $request->server('REQUEST_TIME_FLOAT'), $decimals) . 's';
    }

    /**
     * Get all the memory consumption required from request to response
     *
     * @param int $precision
     * @return string
     */
    public function getMemoryUsage(int $precision = 2): string
    {
        $size = memory_get_usage(true);

        $unit = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];

        return round($size / pow(1024, ($i = floor(log($size, 1024)))), $precision) . '' . $unit[$i];
    }

    /**
     * Get uploaded file information
     *
     * @param UploadedFile $file
     * @param int $sizePrecision
     * @return array
     */
    #[ArrayShape([UploadedFile::class => "array"])]
    public function getUploadedFileInfo(UploadedFile $file, int $sizePrecision = 2): array
    {
        // 获取文件名
        $name = $file->getClientOriginalName();

        // 获取文件扩展名
        $extension = $file->getClientOriginalExtension();

        // 获取文件类型
        $type = $file->getClientMimeType();

        // 获取文件大小（单位为字节）
        $size = $file->getSize();

        // 给文件大小附加合适的单位
        $unit = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];
        $fileSizeSuffix = round($size / pow(1024, ($i = floor(log($size, 1024)))), $sizePrecision) . '' . $unit[$i];

        // 获取临时文件路径
        $path = $file->getRealPath();

        return [
            UploadedFile::class => [
                'name' => $name,
                'extension' => $extension,
                'type' => $type,
                'size' => $fileSizeSuffix,
                'path' => $path,
            ]
        ];
    }
}
