<?php

namespace McpHub\Laravel\Tests;

use InvalidArgumentException;
use McpHub\Laravel\Support\InputSchema;

class InputSchemaTest extends TestCase
{
    public function test_nullable_scalars_and_opaque_reference_limits_match_the_hub_contract(): void
    {
        $schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'status' => ['type' => ['string', 'null'], 'maxLength' => 100],
            'ref' => ['type' => 'string', 'maxLength' => 1024],
        ]];
        $input = ['status' => null, 'ref' => 'ref_'.str_repeat('a', 1020)];
        $this->assertSame($input, InputSchema::validate($schema, $input));
        foreach ([['type' => 'null'], ['type' => ['string', 'string'], 'maxLength' => 100],
            ['type' => ['null', 'null']], ['type' => ['integer', 'number']], ['type' => [['string']]],
            ['type' => 'string', 'maxLength' => 1025]] as $field) {
            try {
                InputSchema::definition(['type' => 'object', 'additionalProperties' => false, 'properties' => ['ref' => $field]]);
                $this->fail('A schema rejected by the Hub was accepted locally');
            } catch (InvalidArgumentException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
    }
}
