# Rebuild the log structure based on laravel log

[![Latest Version on Packagist](https://img.shields.io/packagist/v/caixingyue/laravel-star-log.svg?style=flat-square)](https://packagist.org/packages/caixingyue/laravel-star-log)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/caixingyue/laravel-star-log/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/caixingyue/laravel-star-log/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/caixingyue/laravel-star-log/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/caixingyue/laravel-star-log/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/caixingyue/laravel-star-log.svg?style=flat-square)](https://packagist.org/packages/caixingyue/laravel-star-log)

This is a package that enhances the Laravel log format. It can inject request ID, craftsman ID, queue ID, and supports enhanced capabilities such as routing request log, HTTP client request log, SQL Query log, etc.

## Installation

You can install the package via composer:

```bash
composer require caixingyue/laravel-star-log
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="star-log-config"
```

## Usage

### Log format configuration

After the installation is complete, you need to add a new ```channels``` information to the ```config/logging.php``` configuration file. The following is a common reference example, you can modify the configuration as needed.

```php
use Caixingyue\LaravelStarLog\Formatter\StrengthenFormatter;

'channels' => [
    'star_daily' => [
        'driver' => 'daily',
        'formatter' => StrengthenFormatter::class,
        'formatter_with' => [
            // Defined as microsecond time
            'dateFormat' => 'Y-m-d H:i:s.u'
        ],
        'path' => storage_path('logs/laravel.log'),
        'level' => env('LOG_LEVEL', 'debug'),
        'days' => env('LOG_DAILY_DAYS', 14),
        'replace_placeholders' => true,
    ],
],

// [2024-09-03 08:32:05.161104] local.INFO[App.Http.Controllers.ExampleController@index:12]: Hello, I am now in the index method under the ExampleController controller and have issued this record.
```

### Injecting request ID

If you wish to inject the ```request ID```, you may append it to the global ```middleware``` stack in your application's ```bootstrap/app.php``` file:

```php
use Caixingyue\LaravelStarLog\Http\Middleware\AssignRequestId;

->withMiddleware(function (Middleware $middleware) {
    $middleware->append(AssignRequestId::class);
})

// [2024-09-03 08:36:10.896827] 7146967637.INFO[App.Http.Controllers.ExampleController@index:12]: Hello, I am now in the index method under the ExampleController controller and have issued this record.
```

### Enable request logging

If you would like clients to automatically log request and response information when they request your routes, you may attach this to the global ```middleware``` stack in your application's ```bootstrap/app.php``` file:

```php
use Caixingyue\LaravelStarLog\Http\Middleware\RouteLog;

->withMiddleware(function (Middleware $middleware) {
    $middleware->append(RouteLog::class);
})

// [2024-09-03 08:39:18.923444] 8055622306.INFO[System@request:96]: [Macintosh|OS X|PC端] - [127.0.0.1] - GET[/] - 请求报文: [null] 
// [2024-09-03 08:39:18.935572] 8055622306.INFO[App.Http.Controllers.ExampleController@index:12]: Hello, I am now in the index method under the ExampleController controller and have issued this record.  
// [2024-09-03 08:39:18.938116] 8055622306.INFO[System@response:118]: 耗时[0.09s] - 内存消耗[2mb] - 响应报文: {"code":"success"}
```

### Routing Configuration

Sometimes we want to return the request ID in the response header when responding to troubleshoot problems. Or we may not want to record request logs for certain URLs or methods, or even secret field information. For these situations, you can add relevant configuration to the ```starlog.php``` configuration file:

When you want to return the request ID in the response, you can set ```STAR_LOG_RESPONSE_HEAD_ID``` to ```true``` in the ```env``` configuration.

```php
'route' => [
    'response_head_id' => env('STAR_LOG_RESPONSE_HEAD_ID', false),
],
```

If you do not want to record request logs for certain URLs, you can add the information to be excluded in the ```except```.
- For example, you can add ```/home``` to exclude the path ```https://example.com/home``` from processing.
- You can use the * wildcard to match all paths in a pattern. For example, ```/admin/*``` will exclude all paths starting with ```/admin/```.
- If desired, you can also add the full URL including domain name and protocol.
- If you want to exclude certain paths based on the URL's query string, include those as well. For example, ```/search?q=laravel```.

```php
'route' => [
    'except' => [
        //
    ],
],
```

If you do not want to record requests of certain methods, such as ```GET```, ```POST```, etc., you can add the information to be excluded in ```except_method```.

```php
'route' => [
    'except_method' => [
        //
    ],
],
```

For some secret information fields that you do not want to be recorded in the log, you can add the relevant field name in the ```secret_fields``` field, and the system will automatically replace the data with ```******``` before recording the data. Currently, we have configured the common fields ```current_password```, ```password```, and ```password_confirmation``` as secret fields, which can be adjusted if necessary.

```php
'route' => [
    'secret_fields' => [
        'current_password',
        'password',
        'password_confirmation',
    ],
],
```

### Injecting Artisan ID

If you wish to inject the ```artisan ID```, you may use the ```InjectionId``` method in your command class:

```php
use Caixingyue\LaravelStarLog\Console\InjectionId;

class Example extends Command
{
    use InjectionId;
}

// [2024-09-03 10:20:24.107848] 90819036.INFO[App.Console.Commands.Example@handle:52]: Hello, I have now issued this record under the Example command class.
```

### Injecting Queue ID

If you wish to inject the ```queue ID```, you may use the ```InjectionId``` method in your job class:

```php
use Caixingyue\LaravelStarLog\Queue\InjectionId;

class ExampleJob implements ShouldQueue
{
    use InjectionId;
}

// [2024-09-03 10:24:37.351645] 57326518.INFO[App.Jobs.ExampleJob@handle:30]: Hello, I have now issued this record under the ExampleJob class.
```

Generally, only ```handle``` can use the ```queue ID```. If ```__construct``` also needs to use it, you can call ```$this->initializeInjectionId()``` in ```__construct``` to initialize it.

### Enable HTTP client request logging

If you want the system to automatically log requests and responses when making HTTP client requests, you can set ```STAR_LOG_ENABLE_HTTP_CLIENT``` to ```true``` in the ```env``` configuration.

```php
'http' => [
    'enable' => env('STAR_LOG_ENABLE_HTTP_CLIENT', false),
],

// [2024-09-03 10:52:51.455935] 85234719.INFO[HttpClient@request:39]: GET[https://example.com] - 请求报文: [null] 
// [2024-09-03 10:52:51.787507] 85234719.INFO[HttpClient@response:37]: 耗时[0.325134s] - 200[https://example.com] - 响应报文: {"code":"success"}
```

For some secret information fields that you do not want to be recorded in the log, you can add the relevant field name in the ```secret_fields``` field, and the system will automatically replace the data with ```******``` before recording the data. Currently, we have configured the common fields ```current_password```, ```password```, and ```password_confirmation``` as secret fields, which can be adjusted if necessary.

```php
'http' => [
    'secret_fields' => [
        'current_password',
        'password',
        'password_confirmation',
    ],
],
```

### Enable SQL Query logging

If you want the system to automatically log when SQL queries are issued, you can set ```STAR_LOG_ENABLE_SQL_QUERY``` to ```true``` in the ```env``` configuration.

```php
'query' => [
    'enable' => env('STAR_LOG_ENABLE_SQL_QUERY', false),
],

// [2024-09-03 10:49:41.908266] 81156302.INFO[System@db:46]: SQL Query: insert into "users" ("name", "email", "email_verified_at", "password", "remember_token", "updated_at", "created_at") values (?, ?, ?, ?, ?, ?, ?) | Bindings: ["Gilda Sawayn IV","jena36@example.net","2024-09-03 10:49:41","$2y$12$lxv6rkqo8DCGmWC.JviQe.rD0mytUTPUm2DnyanWM8gPcceRN7EmS","pn0TkAqvxW","2024-09-03 10:49:41","2024-09-03 10:49:41"] | Time: 0.69ms
```

If you do not want to record certain SQL queries, you can add the SQL to be excluded in the ``except``. The SQL statement to be excluded does not have to be complete, as long as it is a part of the SQL.

By default, we will exclude SQL operations on laravel basic tables. If necessary, you can adjust them as needed.

In the exclusion, * key means that it has effect in all class methods. If you need to limit the restrictions within a class, you can specify the class name as the key, such as ```ExampleJob```.

```php
'query' => [
    'except' => [
        ExampleJob::class => [
            'select * from "users"',
        ],
        
        '*' => [
            'insert into "sessions"',
            'insert into "cache"',
            'insert into "cache_locks"',
            'insert into "jobs"',
            'insert into "job_batches"',
            'insert into "failed_jobs"',

            'delete from "sessions"',
            'delete from "cache"',
            'delete from "cache_locks"',
            'delete from "jobs"',
            'delete from "job_batches"',
            'delete from "failed_jobs"',

            'update "sessions"',
            'update "cache"',
            'update "cache_locks"',
            'update "jobs"',
            'update "job_batches"',
            'update "failed_jobs"',

            'select * from "sessions"',
            'select * from "cache"',
            'select * from "cache_locks"',
            'select * from "jobs"',
            'select * from "job_batches"',
            'select * from "failed_jobs"',
        ],
    ],
],
```

### Helpers

We provide some helpers that can help you get some data in different scenarios.

```php
// Get current request id
StarLog::getRequestId();

// Get the most recent artisan id
StarLog::getArtisanId();

// Get the most recent queue id
StarLog::getQueueId();

// Get all injection id list
StarLog::getInjectionIds();
```

Generally, in classes that use injected id, we recommend using the $this method.

```php
// Get current artisan or queue id
$this->getId();

// Get current request id
$this->getRequestId();

// Get current artisan id
$this->getArtisanId();

// Get current queue id
$this->getQueueId();
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [xingyue cai](https://github.com/caixingyue)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
