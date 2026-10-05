<?php

namespace GuardedTools\Tests;

use GuardedTools\CanonicalToolResult;
use PHPUnit\Framework\TestCase;

class CanonicalToolResultTest extends TestCase
{
    public function test_ok_keeps_data_and_private_provenance_in_the_full_result(): void
    {
        $outcome = CanonicalToolResult::ok(['count' => 3], ['source' => 'db:orders', 'workspace_id' => 42]);
        $this->assertSame('ok', $outcome->status);
        $this->assertSame(['count' => 3], $outcome->data);
        $this->assertNull($outcome->errorCode);
        $this->assertSame(['source' => 'db:orders', 'workspace_id' => 42], $outcome->toArray()['provenance']);
        $this->assertSame($outcome->toArray(), json_decode($outcome->toJson(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_empty_is_a_successful_outcome_with_zero_data(): void
    {
        $outcome = CanonicalToolResult::empty(['count' => 0], ['source' => 'db:orders']);
        $this->assertSame('empty', $outcome->status);
        $this->assertSame(['count' => 0], $outcome->data);
        $this->assertNull($outcome->errorCode);
    }

    public function test_error_has_a_code_and_no_data(): void
    {
        $outcome = CanonicalToolResult::error('UPSTREAM_UNAVAILABLE', ['source' => 'db:orders']);
        $this->assertSame('error', $outcome->status);
        $this->assertNull($outcome->data);
        $this->assertSame(['code' => 'UPSTREAM_UNAVAILABLE'], $outcome->toArray()['error']);
    }

    public function test_added_provenance_preserves_existing_fields_and_original_instance(): void
    {
        $original = CanonicalToolResult::ok(['count' => 3], ['source' => 'db:orders']);
        $enriched = $original->withProvenance(['source' => 'overwritten', 'workspace_id' => 42]);
        $this->assertSame(['source' => 'db:orders'], $original->provenance);
        $this->assertSame(['source' => 'db:orders', 'workspace_id' => 42], $enriched->provenance);
        $this->assertNotSame($original, $enriched);
    }
}
