# Store Intelligence Platform — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the 3 pre-built aggregation tools with 9 powerful tools including read-only SQL execution, schema discovery, time intelligence, customer intelligence, product intelligence, and anomaly detection.

**Architecture:** Same OpenAI agent loop. Add 9 new ToolInterface implementations, remove 3 old ones, update DI wiring and system prompt. The `execute_sql` tool is the key unlock — the AI writes its own SQL queries with PHP-level sandboxing.

**Tech Stack:** PHP 8.3, Magento 2 (Mage-OS), openai-php/client, Typesense PHP SDK, PHPUnit 10.5

**Design Doc:** `docs/plans/2026-04-09-store-intelligence-platform-design.md`

---

## Task 1: ExecuteSqlTool — The Foundation

The most important tool. Read-only SQL execution with sandboxing.

**Files:**
- Create: `Model/Assistant/Tool/ExecuteSqlTool.php`
- Create: `Model/Assistant/Tool/SqlSandbox.php`
- Test: `Test/Unit/Model/Assistant/Tool/SqlSandboxTest.php`
- Test: `Test/Unit/Model/Assistant/Tool/ExecuteSqlToolTest.php`

**Step 1: Create SqlSandbox** — a dedicated class for query validation

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

class SqlSandbox
{
    private const BLOCKED_TABLES = [
        'admin_user', 'admin_passwords', 'oauth_token', 'oauth_consumer',
        'oauth_token_request_log', 'authorization_role', 'authorization_rule',
        'persistent_session', 'admin_user_session',
    ];

    private const BLOCKED_COLUMNS = ['password_hash', 'rp_token'];

    private const SENSITIVE_CONFIG_PATTERNS = [
        'password', 'key', 'secret', 'token', 'encryption',
        'credential', 'oauth', 'api_key', 'passphrase', 'private',
        'cert', 'auth', 'hash', 'username', 'license',
    ];

    /**
     * Validate a SQL query is safe to execute.
     * @throws \InvalidArgumentException if query is unsafe
     */
    public function validate(string $query): void
    {
        $normalized = trim(strtolower($query));

        // Must start with SELECT
        if (!str_starts_with($normalized, 'select')) {
            throw new \InvalidArgumentException('Only SELECT queries are allowed.');
        }

        // Block write keywords anywhere in query
        $blocked = ['insert ', 'update ', 'delete ', 'drop ', 'alter ', 'truncate ', 'create ', 'grant ', 'revoke '];
        foreach ($blocked as $keyword) {
            if (str_contains($normalized, $keyword)) {
                throw new \InvalidArgumentException('Query contains blocked keyword: ' . trim($keyword));
            }
        }

        // Block sensitive tables
        foreach (self::BLOCKED_TABLES as $table) {
            if (preg_match('/\b' . preg_quote($table, '/') . '\b/i', $query)) {
                throw new \InvalidArgumentException('Access to table "' . $table . '" is blocked.');
            }
        }

        // Block sensitive columns
        foreach (self::BLOCKED_COLUMNS as $column) {
            if (preg_match('/\b' . preg_quote($column, '/') . '\b/i', $query)) {
                throw new \InvalidArgumentException('Access to column "' . $column . '" is blocked.');
            }
        }
    }

    /**
     * Filter sensitive config rows from results.
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function filterSensitiveConfigRows(array $rows): array
    {
        return array_values(array_filter($rows, function (array $row): bool {
            $path = strtolower((string) ($row['path'] ?? ''));
            if ($path === '') {
                return true;
            }
            foreach (self::SENSITIVE_CONFIG_PATTERNS as $pattern) {
                if (str_contains($path, $pattern)) {
                    return false;
                }
            }
            return true;
        }));
    }
}
```

**Step 2: Write SqlSandbox tests**

```php
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
}
```

**Step 3: Run tests**

```bash
./vendor/bin/phpunit --filter=SqlSandboxTest --testdox
```

**Step 4: Create ExecuteSqlTool**

```php
<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Assistant\Tool;

use Magento\Framework\App\ResourceConnection;

class ExecuteSqlTool implements ToolInterface
{
    private const DEFAULT_TIMEOUT = 5;
    private const DEFAULT_ROW_LIMIT = 100;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SqlSandbox $sandbox,
    ) {
    }

    public function getName(): string
    {
        return 'execute_sql';
    }

    public function getDescription(): string
    {
        return 'Execute a read-only SELECT query on the Magento MySQL database. Returns up to 100 rows. Use describe_database to explore table/column names first. Blocked tables: admin_user, oauth_token, authorization_role. Blocked columns: password_hash.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The SQL SELECT query to execute. Must start with SELECT.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments): string
    {
        $query = trim($arguments['query'] ?? '');

        if ($query === '') {
            return json_encode(['error' => 'Query cannot be empty.']);
        }

        try {
            $this->sandbox->validate($query);
        } catch (\InvalidArgumentException $e) {
            return json_encode(['error' => $e->getMessage()]);
        }

        // Enforce LIMIT
        if (!preg_match('/\bLIMIT\b/i', $query)) {
            $query .= ' LIMIT ' . self::DEFAULT_ROW_LIMIT;
        }

        try {
            $connection = $this->resource->getConnection();
            $rows = $connection->fetchAll($query);

            // Filter sensitive config data if querying core_config_data
            if (stripos($query, 'core_config_data') !== false) {
                $rows = $this->sandbox->filterSensitiveConfigRows($rows);
            }

            return json_encode([
                'rows' => $rows,
                'count' => count($rows),
            ]);
        } catch (\Exception $e) {
            return json_encode(['error' => 'SQL error: ' . $e->getMessage()]);
        }
    }
}
```

**Step 5: Commit**

```bash
git add Model/Assistant/Tool/ExecuteSqlTool.php Model/Assistant/Tool/SqlSandbox.php \
    Test/Unit/Model/Assistant/Tool/SqlSandboxTest.php Test/Unit/Model/Assistant/Tool/ExecuteSqlToolTest.php
git commit -m "feat(intelligence): add ExecuteSqlTool with SqlSandbox security layer"
```

---

## Task 2: DescribeDatabaseTool — Schema Discovery

**Files:**
- Create: `Model/Assistant/Tool/DescribeDatabaseTool.php`
- Test: `Test/Unit/Model/Assistant/Tool/DescribeDatabaseToolTest.php`

**Step 1: Implement DescribeDatabaseTool**

Constructor takes `ResourceConnection` and `SqlSandbox`. Three actions:

- `list_tables`: `SHOW TABLES` then filter by optional pattern, exclude blocked tables
- `describe_table`: `DESCRIBE {table}` — validate table name against blocked list first
- `show_relationships`: `SELECT * FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL` plus reverse lookup

Parameters schema:
```php
'properties' => [
    'action' => [
        'type' => 'string',
        'enum' => ['list_tables', 'describe_table', 'show_relationships'],
        'description' => 'What to discover about the database schema',
    ],
    'table_name' => [
        'type' => 'string',
        'description' => 'Table name (required for describe_table and show_relationships)',
    ],
    'filter' => [
        'type' => 'string',
        'description' => 'Filter table list by pattern (e.g. "sales_", "customer_")',
    ],
],
'required' => ['action'],
```

Important: validate `table_name` against blocked tables list before any DESCRIBE or relationship query. Use `SqlSandbox::BLOCKED_TABLES` — make it accessible or create a public method `isBlockedTable(string $table): bool`.

**Step 2: Write tests, commit**

```bash
git add Model/Assistant/Tool/DescribeDatabaseTool.php Test/Unit/Model/Assistant/Tool/
git commit -m "feat(intelligence): add DescribeDatabaseTool for schema discovery"
```

---

## Task 3: Time Intelligence Tools

**Files:**
- Create: `Model/Assistant/Tool/ComparePeriodsTool.php`
- Create: `Model/Assistant/Tool/TrendAnalysisTool.php`
- Test: `Test/Unit/Model/Assistant/Tool/ComparePeriodsToolTest.php`
- Test: `Test/Unit/Model/Assistant/Tool/TrendAnalysisToolTest.php`

**Step 1: Implement ComparePeriodsTool**

Constructor takes `ResourceConnection`. For each metric, run an appropriate SQL query for each period:

```php
private function getMetricQuery(string $metric): string
{
    return match($metric) {
        'revenue' => 'SELECT COALESCE(SUM(grand_total), 0) as value FROM %s WHERE created_at BETWEEN ? AND ?',
        'order_count' => 'SELECT COUNT(*) as value FROM %s WHERE created_at BETWEEN ? AND ?',
        'new_customers' => 'SELECT COUNT(*) as value FROM %s WHERE created_at BETWEEN ? AND ?',
        'avg_order_value' => 'SELECT COALESCE(AVG(grand_total), 0) as value FROM %s WHERE created_at BETWEEN ? AND ? AND grand_total > 0',
        'units_sold' => 'SELECT COALESCE(SUM(soi.qty_ordered), 0) as value FROM %s so JOIN %s soi ON soi.order_id = so.entity_id WHERE so.created_at BETWEEN ? AND ?',
    };
}
```

Use `sales_order` for revenue/order_count/avg, `customer_entity` for new_customers, and join `sales_order_item` for units_sold.

Calculate: absolute_change, percentage_change, direction.

**Step 2: Implement TrendAnalysisTool**

Constructor takes `ResourceConnection`. Queries by granularity (day/week/month), returns series + calculates moving average (3-period) and overall growth rate.

```php
// For monthly:
$sql = "SELECT DATE_FORMAT(created_at, '%Y-%m') as period, {$metricExpr} as value
        FROM {$table} WHERE created_at >= ? GROUP BY period ORDER BY period ASC";
```

Growth rate: `(last_value - first_value) / first_value * 100`. Direction: growing if > 5%, declining if < -5%, stable otherwise.

**Step 3: Write tests, commit**

```bash
git add Model/Assistant/Tool/ComparePeriodsTool.php Model/Assistant/Tool/TrendAnalysisTool.php \
    Test/Unit/Model/Assistant/Tool/
git commit -m "feat(intelligence): add ComparePeriods and TrendAnalysis tools"
```

---

## Task 4: Customer Intelligence Tools

**Files:**
- Create: `Model/Assistant/Tool/CustomerSegmentationTool.php`
- Create: `Model/Assistant/Tool/CohortAnalysisTool.php`
- Test: `Test/Unit/Model/Assistant/Tool/CustomerSegmentationToolTest.php`

**Step 1: Implement CustomerSegmentationTool**

Constructor takes `ResourceConnection`. Single aggregation query:

```sql
SELECT
    ce.entity_id,
    DATEDIFF(NOW(), MAX(so.created_at)) as recency_days,
    COUNT(so.entity_id) as frequency,
    COALESCE(SUM(so.grand_total), 0) as monetary
FROM customer_entity ce
LEFT JOIN sales_order so ON so.customer_id = ce.entity_id
GROUP BY ce.entity_id
```

Then in PHP: calculate quintiles for R/F/M (1-5), assign segments based on design doc rules, aggregate into segment summaries.

No parameters — analyzes all customers.

**Step 2: Implement CohortAnalysisTool**

Constructor takes `ResourceConnection`. Steps:
1. Find each customer's first purchase date
2. Group into cohorts by month/quarter
3. For each subsequent period, check if the customer ordered again
4. Calculate retention rate per cohort × period

```sql
-- Step 1: customer first purchase
SELECT customer_id, MIN(DATE_FORMAT(created_at, '%Y-%m')) as cohort
FROM sales_order
WHERE customer_id IS NOT NULL
GROUP BY customer_id
```

Then for each cohort period, count distinct customers who ordered.

Parameters: `cohort_by` (first_purchase_month/first_purchase_quarter), `periods` (default 6).

**Step 3: Tests, commit**

```bash
git add Model/Assistant/Tool/CustomerSegmentationTool.php Model/Assistant/Tool/CohortAnalysisTool.php \
    Test/Unit/Model/Assistant/Tool/
git commit -m "feat(intelligence): add CustomerSegmentation and CohortAnalysis tools"
```

---

## Task 5: Product Intelligence Tools

**Files:**
- Create: `Model/Assistant/Tool/FrequentlyBoughtTogetherTool.php`
- Create: `Model/Assistant/Tool/InventoryForecastTool.php`
- Test: `Test/Unit/Model/Assistant/Tool/InventoryForecastToolTest.php`

**Step 1: Implement FrequentlyBoughtTogetherTool**

Constructor takes `ResourceConnection`. Self-join on `sales_order_item`:

```sql
SELECT
    a.name as product_a, a.sku as sku_a,
    b.name as product_b, b.sku as sku_b,
    COUNT(*) as times_bought_together
FROM sales_order_item a
JOIN sales_order_item b ON a.order_id = b.order_id AND a.item_id < b.item_id
WHERE a.parent_item_id IS NULL AND b.parent_item_id IS NULL
GROUP BY a.sku, b.sku
HAVING times_bought_together >= ?
ORDER BY times_bought_together DESC
LIMIT ?
```

If `product_sku` is provided, add `WHERE a.sku = ?` filter.

**Step 2: Implement InventoryForecastTool**

Constructor takes `ResourceConnection`. Steps:
1. Get current stock from `cataloginventory_stock_item`
2. Calculate avg daily sales from `sales_order_item` over lookback window
3. days_until_stockout = current_stock / avg_daily_sales
4. Status: critical (< 7 days), warning (< threshold days), ok

```sql
SELECT
    cpe.sku, csi.qty as current_stock,
    COALESCE(SUM(soi.qty_ordered) / ?, 0) as avg_daily_sales
FROM catalog_product_entity cpe
JOIN cataloginventory_stock_item csi ON csi.product_id = cpe.entity_id AND csi.is_in_stock = 1
LEFT JOIN sales_order_item soi ON soi.product_id = cpe.entity_id
    AND soi.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
GROUP BY cpe.entity_id
HAVING avg_daily_sales > 0
ORDER BY (csi.qty / avg_daily_sales) ASC
```

**Step 3: Tests, commit**

```bash
git add Model/Assistant/Tool/FrequentlyBoughtTogetherTool.php Model/Assistant/Tool/InventoryForecastTool.php \
    Test/Unit/Model/Assistant/Tool/
git commit -m "feat(intelligence): add FrequentlyBoughtTogether and InventoryForecast tools"
```

---

## Task 6: Anomaly Detection Tool

**Files:**
- Create: `Model/Assistant/Tool/DetectAnomaliesTool.php`
- Test: `Test/Unit/Model/Assistant/Tool/DetectAnomaliesToolTest.php`

**Step 1: Implement DetectAnomaliesTool**

Constructor takes `ResourceConnection`. Steps:
1. Calculate current period value
2. Calculate historical average and stddev over lookback periods
3. Z-score = (current - avg) / stddev
4. Status: normal (|z| < 1.5), warning (1.5 ≤ |z| < 2.5), critical (|z| ≥ 2.5)

For `today_vs_avg`: current = today's metric, historical = same weekday over past N weeks.
For `this_week_vs_avg`: current = this week, historical = past N weeks.
For `this_month_vs_avg`: current = this month, historical = past N months.

**Step 2: Test, commit**

```bash
git add Model/Assistant/Tool/DetectAnomaliesTool.php Test/Unit/Model/Assistant/Tool/
git commit -m "feat(intelligence): add DetectAnomalies tool with z-score deviation"
```

---

## Task 7: DI Wiring, Remove Old Tools, Update System Prompt

**Files:**
- Modify: `etc/di.xml` — replace old tool registrations with new ones
- Modify: `etc/config.xml` — update system prompt
- Delete: `Model/Assistant/Tool/QueryOrdersTool.php`
- Delete: `Model/Assistant/Tool/QueryCustomersTool.php`
- Delete: `Model/Assistant/Tool/QueryProductsTool.php`
- Delete: `Model/Assistant/SearchRequestBuilder.php`
- Delete: associated test files for removed tools

**Step 1: Update di.xml ToolRegistry**

Replace the tool registrations:
```xml
<type name="RunAsRoot\TypeSense\Model\Assistant\ToolRegistry">
    <arguments>
        <argument name="tools" xsi:type="array">
            <item name="execute_sql" xsi:type="object">RunAsRoot\TypeSense\Model\Assistant\Tool\ExecuteSqlTool</item>
            <item name="describe_database" xsi:type="object">RunAsRoot\TypeSense\Model\Assistant\Tool\DescribeDatabaseTool</item>
            <item name="search_typesense" xsi:type="object">RunAsRoot\TypeSense\Model\Assistant\Tool\SearchTypesenseTool</item>
            <item name="compare_periods" xsi:type="object">RunAsRoot\TypeSense\Model\Assistant\Tool\ComparePeriodsTool</item>
            <item name="trend_analysis" xsi:type="object">RunAsRoot\TypeSense\Model\Assistant\Tool\TrendAnalysisTool</item>
            <item name="customer_segmentation" xsi:type="object">RunAsRoot\TypeSense\Model\Assistant\Tool\CustomerSegmentationTool</item>
            <item name="cohort_analysis" xsi:type="object">RunAsRoot\TypeSense\Model\Assistant\Tool\CohortAnalysisTool</item>
            <item name="frequently_bought_together" xsi:type="object">RunAsRoot\TypeSense\Model\Assistant\Tool\FrequentlyBoughtTogetherTool</item>
            <item name="inventory_forecast" xsi:type="object">RunAsRoot\TypeSense\Model\Assistant\Tool\InventoryForecastTool</item>
            <item name="detect_anomalies" xsi:type="object">RunAsRoot\TypeSense\Model\Assistant\Tool\DetectAnomaliesTool</item>
        </argument>
    </arguments>
</type>
```

**Step 2: Update system prompt in config.xml** with the full Store Intelligence prompt from the design doc.

**Step 3: Delete old files**

```bash
git rm Model/Assistant/Tool/QueryOrdersTool.php \
    Model/Assistant/Tool/QueryCustomersTool.php \
    Model/Assistant/Tool/QueryProductsTool.php \
    Model/Assistant/SearchRequestBuilder.php \
    Test/Unit/Model/Assistant/Tool/QueryOrdersToolTest.php \
    Test/Unit/Model/Assistant/Tool/QueryCustomersToolTest.php \
    Test/Unit/Model/Assistant/Tool/QueryProductsToolTest.php
```

**Step 4: Update AgentLoop MAX_ITERATIONS**

Increase from 5 to 10 — the AI may need more steps now (explore schema → write SQL → refine query → answer).

**Step 5: Commit**

```bash
git add etc/di.xml etc/config.xml Model/Assistant/AgentLoop.php
git commit -m "feat(intelligence): wire new tools, update prompt, remove old aggregation tools"
```

---

## Task 8: Smoke Test

**Step 1: Deploy and compile**

```bash
cd /Users/david/Herd/mage-os-typesense
warden env exec php-fpm composer reinstall run-as-root/magento2-typesense
warden env exec php-fpm bash -c "rm -rf /var/www/html/vendor/run-as-root/magento2-typesense/vendor"
warden env exec php-fpm bin/magento setup:di:compile
warden env exec php-fpm bin/magento cache:flush
```

**Step 2: Test these questions**

1. **"What are my best selling products?"** — should use `execute_sql` with ORDER BY from sales_order_item
2. **"Compare Q1 vs Q2 revenue"** — should use `compare_periods` with correct date ranges
3. **"Who is my highest spending customer?"** — should use `execute_sql` with SUM + GROUP BY
4. **"Show me my customer segments"** — should use `customer_segmentation`
5. **"Which products are frequently bought together?"** — should use `frequently_bought_together`
6. **"Which products will run out of stock soon?"** — should use `inventory_forecast`
7. **"Is today's revenue normal?"** — should use `detect_anomalies`
8. **"What tables contain order data?"** — should use `describe_database(action: list_tables, filter: sales_)`
9. **"Show me revenue trend by month"** — should use `trend_analysis`
10. **"What is my shipping policy?"** — should use `search_typesense(collection: cms_page)`

**Step 3: Commit fixes**

---

## Task Summary

| Task | Description | Depends On |
|------|-------------|------------|
| 1 | ExecuteSqlTool + SqlSandbox | — |
| 2 | DescribeDatabaseTool | 1 (uses SqlSandbox) |
| 3 | ComparePeriodsTool + TrendAnalysisTool | — |
| 4 | CustomerSegmentationTool + CohortAnalysisTool | — |
| 5 | FrequentlyBoughtTogetherTool + InventoryForecastTool | — |
| 6 | DetectAnomaliesTool | — |
| 7 | DI wiring + system prompt + remove old tools | 1-6 |
| 8 | Smoke test | 7 |

**Parallelizable:** Tasks 1-6 can all run in parallel (they're independent tool implementations). Task 7 depends on all of them. Task 8 depends on 7.
