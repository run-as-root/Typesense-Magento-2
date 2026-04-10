<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\SqlSandbox;

final class SqlSandboxTest extends TestCase
{
    private ResourceConnection&MockObject $resource;
    private SqlSandbox $sut;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->sut = new SqlSandbox($this->resource);
    }

    public function test_allows_valid_select(): void
    {
        $this->sut->validate('SELECT * FROM sales_order LIMIT 10');
        self::assertTrue(true);
    }

    public function test_blocks_insert(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('INSERT INTO sales_order VALUES (1)');
    }

    public function test_blocks_delete(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('DELETE FROM sales_order WHERE 1=1');
    }

    public function test_blocks_update(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('UPDATE sales_order SET status = "canceled"');
    }

    public function test_blocks_drop(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('DROP TABLE sales_order');
    }

    public function test_blocks_admin_user_table(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('SELECT * FROM admin_user');
    }

    public function test_blocks_oauth_token_table(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('SELECT * FROM oauth_token');
    }

    public function test_blocks_password_hash_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('SELECT password_hash FROM customer_entity');
    }

    public function test_blocks_non_select(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('SHOW TABLES');
    }

    public function test_blocks_union_select(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('SELECT 1 UNION SELECT username FROM admin_user');
    }

    public function test_blocks_into_outfile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('SELECT * INTO OUTFILE "/tmp/data.csv" FROM sales_order');
    }

    public function test_blocks_sql_comments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('SELECT /* comment */ * FROM sales_order');
    }

    public function test_blocks_inline_comments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('SELECT * FROM sales_order -- comment');
    }

    public function test_blocks_call_keyword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('SELECT 1; CALL dangerous_proc()');
    }

    public function test_blocks_set_keyword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('SELECT 1; SET @var = 1');
    }

    public function test_blocks_load_file(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('SELECT LOAD_FILE("/etc/passwd")');
    }

    public function test_blocks_prefixed_table(): void
    {
        $prefixedResource = $this->createMock(ResourceConnection::class);
        $prefixedResource->method('getTableName')->willReturnCallback(
            fn(string $table): string => 'm2_' . $table
        );

        $sandbox = new SqlSandbox($prefixedResource);

        $this->expectException(\InvalidArgumentException::class);
        $sandbox->validate('SELECT * FROM m2_admin_user');
    }

    public function test_blocks_string_functions_on_config_data(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('SELECT SUBSTR(value, 1, 1) FROM core_config_data');
    }

    public function test_allows_simple_select_on_config_data(): void
    {
        $this->sut->validate('SELECT path, value FROM core_config_data WHERE path LIKE "web%"');
        self::assertTrue(true);
    }

    public function test_blocks_token_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('SELECT token FROM some_table');
    }

    public function test_blocks_secret_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('SELECT secret FROM some_table');
    }

    public function test_blocks_integration_table(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sut->validate('SELECT * FROM integration');
    }

    public function test_filters_sensitive_config_rows(): void
    {
        $rows = [
            ['path' => 'web/secure/base_url', 'value' => 'https://example.com'],
            ['path' => 'payment/braintree/api_key', 'value' => 'sk-xxx'],
            ['path' => 'general/locale/code', 'value' => 'en_US'],
            ['path' => 'oauth/consumer/secret', 'value' => 'xxx'],
        ];

        $filtered = $this->sut->filterSensitiveConfigRows($rows);

        self::assertCount(2, $filtered);
        self::assertSame('web/secure/base_url', $filtered[0]['path']);
        self::assertSame('general/locale/code', $filtered[1]['path']);
    }

    public function test_is_blocked_table_returns_true_for_admin_user(): void
    {
        self::assertTrue($this->sut->isBlockedTable('admin_user'));
    }

    public function test_is_blocked_table_returns_true_for_oauth_token(): void
    {
        self::assertTrue($this->sut->isBlockedTable('oauth_token'));
    }

    public function test_is_blocked_table_returns_false_for_sales_order(): void
    {
        self::assertFalse($this->sut->isBlockedTable('sales_order'));
    }

    public function test_is_blocked_table_is_case_insensitive(): void
    {
        self::assertTrue($this->sut->isBlockedTable('ADMIN_USER'));
    }
}
