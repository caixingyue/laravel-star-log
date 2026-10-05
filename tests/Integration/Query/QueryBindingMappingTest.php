<?php

namespace Caixingyue\LaravelStarLog\Tests\Integration\Query;

use Caixingyue\LaravelStarLog\StarLog;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;

final class QueryBindingMappingTest extends TestCase
{
    public function test_real_queries_keep_public_bindings_and_mask_actual_sensitive_columns(): void
    {
        config()->set('starlog.query.enable', false);
        DB::statement('create table audit_users (id integer, password text)');
        DB::insert('insert into audit_users (id, password) values (?, ?)', [123, 'secret']);
        config()->set('starlog.query.enable', true);
        config()->set('starlog.query.bindings.enable', true);
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLog::class);
        Log::spy();

        $rows = DB::select('select * from audit_users where id = ? and password /* note */ = ?', [123, 'secret']);
        $named = DB::select('select * from audit_users where password = :public_value and id = :id', ['public_value' => 'secret', 'id' => 123]);

        $this->assertCount(1, $rows);
        $this->assertCount(1, $named);
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => ($context['bindings'] ?? null) === [123, '******'])->once();
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => ($context['bindings'] ?? null) === ['public_value' => '******', 'id' => 123])->once();
    }

    public function test_unmapped_real_query_bindings_remain_visible(): void
    {
        config()->set('starlog.query.enable', false);
        DB::statement('create table audit_users (name text)');
        DB::insert('insert into audit_users (name) values (?)', ['Taylor']);
        config()->set('starlog.query.enable', true);
        config()->set('starlog.query.bindings.enable', true);
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLog::class);
        Log::spy();

        $rows = DB::select('select * from audit_users where lower(name) = :password', ['password' => 'taylor']);

        $this->assertCount(1, $rows);
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => ($context['bindings'] ?? null) === ['password' => 'taylor'])->once();
    }
}
