# Store Intelligence Platform — Design Document

**Date:** 2026-04-09
**Status:** Approved
**Vision:** Transform the Admin AI Assistant from a limited analytics chatbot into a full Store Intelligence Platform — read-only but insanely smart. The AI can answer any data question about the store.

## Core Principle

Give the AI flexible data access tools instead of pre-built aggregations. The AI writes its own queries, explores the schema, and performs sophisticated analytics — all read-only, all sandboxed.

## Architecture

Same OpenAI function calling agent loop. We add 8 new tools (keeping the existing `search_typesense`), replacing the 3 pre-built aggregation tools with the more powerful `execute_sql`.

### Tool Suite (10 tools)

#### Tier 1 — Data Access

**`execute_sql`** — Read-only SQL execution
- Parameters: `query` (string) — the SELECT query to execute
- Sandboxing:
  - Read-only MySQL connection (GRANT SELECT only)
  - Query must start with SELECT (block INSERT/UPDATE/DELETE/DROP/ALTER/TRUNCATE/CREATE)
  - 5 second timeout
  - 100 row limit
  - Blocked tables: admin_user, admin_passwords, oauth_token, oauth_consumer, oauth_token_request_log, authorization_role, authorization_rule, persistent_session, customer_entity (password_hash column only)
  - Block queries on core_config_data rows matching sensitive patterns (password, key, secret, token, etc.)
- Returns: JSON array of rows + row count

**`describe_database`** — Schema discovery
- Parameters:
  - `action` (enum): `list_tables`, `describe_table`, `show_relationships`
  - `table_name` (string, optional) — for describe_table and show_relationships
  - `filter` (string, optional) — filter table list by pattern (e.g. "sales_", "customer_")
- list_tables: returns table names matching filter
- describe_table: returns column names, types, keys for a specific table
- show_relationships: returns foreign keys referencing or referenced by a table
- Blocked: same tables as execute_sql

**`search_typesense`** — (existing) Text/semantic search across indexed collections

#### Tier 2 — Time Intelligence

**`compare_periods`** — Period-over-period comparison
- Parameters:
  - `metric` (enum): revenue, order_count, new_customers, avg_order_value, units_sold
  - `period_1_start` (string, YYYY-MM-DD)
  - `period_1_end` (string, YYYY-MM-DD)
  - `period_2_start` (string, YYYY-MM-DD)
  - `period_2_end` (string, YYYY-MM-DD)
- Returns: period_1_value, period_2_value, absolute_change, percentage_change, direction (up/down/flat)

**`trend_analysis`** — Time series trend analysis
- Parameters:
  - `metric` (enum): revenue, order_count, new_customers, avg_order_value
  - `granularity` (enum): day, week, month
  - `periods` (int, default 12) — how many periods to look back
- Returns: series array (period + value), moving_average, overall_growth_rate, direction (growing/declining/stable)

#### Tier 3 — Customer Intelligence

**`customer_segmentation`** — RFM-based customer segmentation
- Parameters: none (analyzes all customers)
- Logic: Score each customer on Recency (days since last order), Frequency (order count), Monetary (lifetime value). Each scored 1-5. Combine into segments:
  - Champions (R:4-5, F:4-5, M:4-5)
  - Loyal Customers (R:2-5, F:3-5, M:3-5)
  - Potential Loyalists (R:3-5, F:1-3, M:1-3)
  - At Risk (R:2-3, F:2-5, M:2-5)
  - Lost (R:1, F:1-5, M:1-5)
- Returns: segment name, customer count, avg lifetime value, avg order count per segment

**`cohort_analysis`** — Customer cohort retention
- Parameters:
  - `cohort_by` (enum): first_purchase_month, first_purchase_quarter
  - `periods` (int, default 6) — how many cohort periods
- Returns: matrix of cohorts × periods showing retention rate and repeat purchase rate

#### Tier 4 — Product Intelligence

**`frequently_bought_together`** — Product co-occurrence analysis
- Parameters:
  - `product_sku` (string, optional) — find items bought with this product, or leave empty for all combinations
  - `min_occurrences` (int, default 2) — minimum co-occurrence count
  - `limit` (int, default 10)
- Logic: JOIN sales_order_item ON order_id, self-join to find product pairs, count occurrences
- Returns: product_a, product_b, times_bought_together, percentage_of_orders

**`inventory_forecast`** — Stock-out prediction
- Parameters:
  - `days_lookback` (int, default 30) — sales velocity calculation window
  - `alert_threshold` (int, default 14) — flag products running out within N days
- Logic: Calculate avg daily sales from order items over lookback period. Current stock / daily_rate = days_until_stockout.
- Returns: product_name, sku, current_stock, avg_daily_sales, days_until_stockout, status (critical/warning/ok)

#### Tier 5 — Anomaly Detection

**`detect_anomalies`** — Metric deviation detection
- Parameters:
  - `metric` (enum): revenue, order_count, avg_order_value, new_customers, refund_count
  - `compare_window` (enum): today_vs_avg, this_week_vs_avg, this_month_vs_avg
  - `lookback_periods` (int, default 4) — how many previous periods to average
- Logic: Calculate current period value and historical average. Z-score = (current - avg) / stddev. Flag if |z| > 2.
- Returns: current_value, historical_avg, deviation_percentage, z_score, status (normal/warning/critical)

### Tools to Remove

- `query_orders` — replaced by `execute_sql`
- `query_customers` — replaced by `execute_sql`
- `query_products` — replaced by `execute_sql`

### SQL Sandboxing Implementation

Create a dedicated read-only MySQL user for the AI:
```sql
CREATE USER 'ai_readonly'@'%' IDENTIFIED BY '...';
GRANT SELECT ON magento.* TO 'ai_readonly'@'%';
REVOKE SELECT ON magento.admin_user FROM 'ai_readonly'@'%';
REVOKE SELECT ON magento.admin_passwords FROM 'ai_readonly'@'%';
REVOKE SELECT ON magento.oauth_token FROM 'ai_readonly'@'%';
REVOKE SELECT ON magento.oauth_consumer FROM 'ai_readonly'@'%';
```

Or simpler: use the existing connection but validate queries in PHP before execution.

### Updated System Prompt

```
You are a Store Intelligence AI for a Magento e-commerce platform.
You have powerful tools to analyze any aspect of the store. Always use tools — never guess.

KEY TOOLS:
- execute_sql: Run any SELECT query on the Magento database. Use describe_database first if unsure about table/column names.
- describe_database: Explore the database schema (tables, columns, relationships).
- search_typesense: Search indexed content (products, orders, customers, CMS pages, config).
- compare_periods: Compare metrics across two time periods (Q1 vs Q2, month over month, etc.).
- trend_analysis: Analyze metric trends over time.
- customer_segmentation: RFM-based customer segmentation.
- cohort_analysis: Customer retention by cohort.
- frequently_bought_together: Find product co-purchase patterns.
- inventory_forecast: Predict stock-outs based on sales velocity.
- detect_anomalies: Spot unusual metric deviations.

KEY MAGENTO TABLES:
- sales_order: entity_id, increment_id, customer_id, grand_total, status, state, created_at, customer_email, customer_firstname, customer_lastname, order_currency_code, total_item_count, shipping_amount, tax_amount, discount_amount
- sales_order_item: item_id, order_id, product_id, sku, name, qty_ordered, price, row_total
- sales_order_address: entity_id, parent_id, address_type, country_id, region, city
- customer_entity: entity_id, email, firstname, lastname, group_id, created_at, store_id, website_id
- catalog_product_entity: entity_id, sku, type_id, created_at
- cataloginventory_stock_item: product_id, qty, is_in_stock
- catalog_category_product: category_id, product_id
- catalog_category_entity: entity_id, name, path, level

GUIDELINES:
- For complex questions, break them down: first explore schema, then query data
- Always include specific numbers, names, and currencies
- Use markdown tables for tabular data
- For financial data, always include currency
- You can call multiple tools in sequence to build a complete answer
```

### Config Changes

- Add: `admin_assistant/sql_timeout` (default: 5)
- Add: `admin_assistant/sql_row_limit` (default: 100)
- The SQL connection can reuse Magento's existing read connection with query validation

### New Files

- `Model/Assistant/Tool/ExecuteSqlTool.php`
- `Model/Assistant/Tool/DescribeDatabaseTool.php`
- `Model/Assistant/Tool/ComparePeriodsTool.php`
- `Model/Assistant/Tool/TrendAnalysisTool.php`
- `Model/Assistant/Tool/CustomerSegmentationTool.php`
- `Model/Assistant/Tool/CohortAnalysisTool.php`
- `Model/Assistant/Tool/FrequentlyBoughtTogetherTool.php`
- `Model/Assistant/Tool/InventoryForecastTool.php`
- `Model/Assistant/Tool/DetectAnomaliesTool.php`

### Files to Remove

- `Model/Assistant/Tool/QueryOrdersTool.php`
- `Model/Assistant/Tool/QueryCustomersTool.php`
- `Model/Assistant/Tool/QueryProductsTool.php`
- `Model/Assistant/SearchRequestBuilder.php` (already deprecated)

### Security

- All tools are strictly read-only
- SQL queries validated in PHP before execution (must be SELECT)
- Blocked tables enforced at PHP level (even if MySQL user has access)
- Sensitive config values filtered from results
- Query timeout prevents long-running queries from impacting performance
- Row limit prevents memory exhaustion
- Max 5 agent iterations prevents runaway loops
