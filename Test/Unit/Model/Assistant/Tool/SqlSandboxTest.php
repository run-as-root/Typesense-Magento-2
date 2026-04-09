<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Assistant\Tool;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Assistant\Tool\SqlSandbox;

final class SqlSandboxTest extends TestCase
{
    private SqlSandbox $sut;

    protected function setUp(): void
    {
        $this->sut = new SqlSandbox();
    }

    public function test_allows_valid_select(): void
    {
        $this->sut->validate('SELECT * FROM sales_order LIMIT 10');
        self::assertTrue(true); // No exception = pass
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
