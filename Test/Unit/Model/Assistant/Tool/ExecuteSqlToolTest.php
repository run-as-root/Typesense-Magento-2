<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\ExecuteSqlTool;
use RunAsRoot\TypeSense\Model\Assistant\Tool\SqlSandbox;

final class ExecuteSqlToolTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private SqlSandbox $sandbox;
    private ExecuteSqlTool $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->sandbox = new SqlSandbox();

        $this->sut = new ExecuteSqlTool(
            $this->resource,
            $this->sandbox,
        );
    }

    public function test_get_name_returns_execute_sql(): void
    {
        self::assertSame('execute_sql', $this->sut->getName());
    }

    public function test_get_description_is_not_empty(): void
    {
        self::assertNotEmpty($this->sut->getDescription());
    }

    public function test_get_parameters_schema_has_query_required(): void
    {
        $schema = $this->sut->getParametersSchema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('properties', $schema);
        self::assertArrayHasKey('query', $schema['properties']);
        self::assertContains('query', $schema['required']);
    }

    public function test_execute_returns_error_for_empty_query(): void
    {
        $result = json_decode($this->sut->execute(['query' => '']), true);

        self::assertArrayHasKey('error', $result);
    }

    public function test_execute_returns_error_for_missing_query(): void
    {
        $result = json_decode($this->sut->execute([]), true);

        self::assertArrayHasKey('error', $result);
    }

    public function test_execute_rejects_non_select_query(): void
    {
        $result = json_decode($this->sut->execute(['query' => 'DROP TABLE sales_order']), true);

        self::assertArrayHasKey('error', $result);
    }

    public function test_execute_rejects_blocked_table(): void
    {
        $result = json_decode($this->sut->execute(['query' => 'SELECT * FROM admin_user']), true);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('admin_user', $result['error']);
    }

    public function test_execute_rejects_blocked_column(): void
    {
        $result = json_decode($this->sut->execute(['query' => 'SELECT password_hash FROM customer_entity']), true);

        self::assertArrayHasKey('error', $result);
    }
}
