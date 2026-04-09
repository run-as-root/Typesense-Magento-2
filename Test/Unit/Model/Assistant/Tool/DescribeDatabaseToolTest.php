<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\DescribeDatabaseTool;
use RunAsRoot\TypeSense\Model\Assistant\Tool\SqlSandbox;

final class DescribeDatabaseToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private SqlSandbox $sandbox;
    private DescribeDatabaseTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->sandbox = new SqlSandbox();

        $this->sut = new DescribeDatabaseTool(
            $this->resource,
            $this->sandbox,
        );
    }

    public function test_get_name_returns_describe_database(): void
    {
        self::assertSame('describe_database', $this->sut->getName());
    }

    public function test_get_description_is_not_empty(): void
    {
        self::assertNotEmpty($this->sut->getDescription());
    }

    public function test_get_parameters_schema_has_action_required(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('properties', $schema);
        self::assertArrayHasKey('action', $schema['properties']);
        self::assertContains('action', $schema['required']);
    }

    public function test_get_parameters_schema_action_has_enum(): void
    {
        $schema = $this->sut->getParametersSchema();
        $actionSchema = $schema['properties']['action'];

        self::assertArrayHasKey('enum', $actionSchema);
        self::assertContains('list_tables', $actionSchema['enum']);
        self::assertContains('describe_table', $actionSchema['enum']);
        self::assertContains('show_relationships', $actionSchema['enum']);
    }

    public function test_get_parameters_schema_has_optional_table_name_and_filter(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertArrayHasKey('table_name', $schema['properties']);
        self::assertArrayHasKey('filter', $schema['properties']);
        // These are optional — not in required array
        self::assertNotContains('table_name', $schema['required']);
        self::assertNotContains('filter', $schema['required']);
    }

    public function test_execute_returns_error_for_unknown_action(): void
    {
        $result = json_decode($this->sut->execute(['action' => 'invalid_action']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('invalid_action', $result['error']);
    }

    public function test_describe_table_rejects_blocked_table(): void
    {
        $result = json_decode(
            $this->sut->execute(['action' => 'describe_table', 'table_name' => 'admin_user']),
            true
        );

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('admin_user', $result['error']);
    }

    public function test_describe_table_rejects_oauth_token(): void
    {
        $result = json_decode(
            $this->sut->execute(['action' => 'describe_table', 'table_name' => 'oauth_token']),
            true
        );

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('oauth_token', $result['error']);
    }

    public function test_describe_table_returns_error_when_table_name_missing(): void
    {
        $result = json_decode(
            $this->sut->execute(['action' => 'describe_table']),
            true
        );

        self::assertArrayHasKey('error', $result);
    }

    public function test_show_relationships_rejects_blocked_table(): void
    {
        $result = json_decode(
            $this->sut->execute(['action' => 'show_relationships', 'table_name' => 'admin_user']),
            true
        );

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('admin_user', $result['error']);
    }

    public function test_show_relationships_returns_error_when_table_name_missing(): void
    {
        $result = json_decode(
            $this->sut->execute(['action' => 'show_relationships']),
            true
        );

        self::assertArrayHasKey('error', $result);
    }
}
