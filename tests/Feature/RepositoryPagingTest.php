<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Tests\Feature;

use App\Contracts\RecordRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RepositoryPagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_indexed_cursor_pages_are_stable_and_filter_bound(): void
    {
        config(['cms.data_provider' => 'postgres']);
        $r = app(RecordRepository::class);
        for ($i = 0; $i < 7; $i++) {
            $r->create('jobs', ['customer_id' => $i % 2 ? 'other' : 'customer', 'status' => 'scheduled']);
        }
        $first = $r->page('jobs', ['customer_id' => 'customer'], 2);
        $this->assertCount(2, $first['data']);
        $this->assertNotNull($first['next_cursor']);
        $second = $r->page('jobs', ['customer_id' => 'customer'], 2, $first['next_cursor']);
        $this->assertCount(2, $second['data']);
        $this->assertNull($second['next_cursor']);
        $this->assertCount(4, array_unique(array_column(array_merge($first['data'], $second['data']), 'id')));
        $r->update('jobs', $first['data'][0]['id'], ['customer_id' => 'other']);
        $this->assertCount(3, $r->all('jobs', ['customer_id' => 'customer']));
        $this->expectException(HttpException::class);
        $r->page('jobs', ['customer_id' => 'other'], 2, $first['next_cursor']);
    }
}
