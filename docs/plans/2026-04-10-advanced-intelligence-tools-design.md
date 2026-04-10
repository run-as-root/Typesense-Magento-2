# Advanced Intelligence Tools — Design Document

**Date:** 2026-04-10
**Status:** Approved
**Goal:** Add 14 advanced analytics tools to the Store Intelligence Platform, covering profitability, LTV, funnels, discounts, returns, product velocity, churn, attribution, purchase journeys, basket analysis, geographic performance, time patterns, concentration risk, and shipping performance.

## Tools to Build

### Tier 1 — Core Analytics

**1. `profit_analysis`** — Product & order profitability
- Uses `catalog_product_entity_decimal` (cost attribute) + `sales_order_item` (price, qty, row_total) + `sales_order` (discount, tax, shipping)
- Aggregations: profit_by_product, profit_by_category, profit_by_order, profit_margin_trend
- Returns: revenue, cost, gross_profit, margin_percentage

**2. `customer_lifetime_value`** — Historical & projected LTV
- Uses `sales_order` grouped by customer
- Aggregations: ltv_by_customer (top N), ltv_by_segment, ltv_by_first_product, ltv_by_acquisition_month, avg_ltv
- Projected LTV: avg_orders_per_customer × avg_order_value × avg_customer_lifespan

**3. `funnel_analysis`** — Purchase funnel & cart abandonment
- Uses `quote` (carts), `quote_item`, `sales_order`
- Metrics: total_carts_created, carts_with_items, carts_converted, abandonment_rate, avg_cart_value_abandoned, avg_time_to_convert, most_abandoned_products
- Date range filter

**4. `discount_effectiveness`** — Promotion & coupon ROI
- Uses `salesrule`, `salesrule_coupon`, `sales_order` (coupon_code, discount_amount)
- Aggregations: coupon_performance (by code), discount_vs_fullprice_aov, repeat_rate_coupon_vs_noncoupon, revenue_by_coupon, top_coupons

**5. `returns_analysis`** — Refund & return analytics
- Uses `sales_creditmemo`, `sales_creditmemo_item`, `sales_order`
- Aggregations: return_rate_by_product, return_rate_by_category, total_refunds, refund_reasons (if available), return_rate_trend

### Tier 2 — Advanced Intelligence

**6. `product_velocity`** — Sales velocity & lifecycle
- Sell-through rate, units/day, days of inventory remaining
- Classifications: fast_mover, slow_mover, dead_stock (no sales in 90 days)
- Uses `sales_order_item` + `cataloginventory_stock_item`

**7. `customer_churn_risk`** — Churn prediction
- Calculate avg repurchase interval per customer
- Flag customers overdue by >1.5× their avg interval
- Risk levels: low, medium, high
- Uses `sales_order` grouped by customer_id with date diffs

**8. `revenue_attribution`** — Channel/source attribution
- Uses UTM parameters from `sales_order` (if stored) or `quote` tracking data
- Fallback: group by coupon_code as proxy for campaign
- Metrics per channel: revenue, order_count, aov, customer_count

**9. `customer_purchase_journey`** — Order sequence analysis
- First purchase → second purchase → third purchase patterns
- Which entry products lead to highest LTV
- Uses `sales_order` + `sales_order_item` ordered by created_at per customer

### Tier 3 — Differentiating

**10. `basket_analysis`** — Active/abandoned cart intelligence
- Uses `quote` table (is_active=1 for open carts)
- Metrics: open_cart_count, total_abandoned_value, avg_cart_age, most_common_cart_items, carts_by_value_range

**11. `geographic_performance`** — Regional analytics
- Uses `sales_order_address` (shipping address)
- Aggregations: revenue_by_country, revenue_by_region, aov_by_country, top_cities, product_preferences_by_country

**12. `time_pattern_analysis`** — Temporal purchase patterns
- Uses `sales_order.created_at`
- Aggregations: orders_by_hour, orders_by_day_of_week, orders_by_month, peak_hours, seasonal_products

**13. `customer_concentration_risk`** — Revenue dependency (Pareto)
- What % of revenue comes from top N% of customers
- Trend: is concentration increasing or decreasing
- Uses `sales_order` grouped by customer

**14. `shipping_performance`** — Fulfillment analytics
- Uses `sales_order` + `sales_shipment`
- Metrics: avg_fulfillment_time, shipping_method_distribution, shipping_cost_percentage, free_shipping_usage_rate, shipping_cost_by_country

## Implementation Approach

All tools follow the same pattern as existing tools:
- Implement `ToolInterface`
- Constructor takes `ResourceConnection`
- `getParametersSchema()` returns OpenAI-compatible JSON schema
- `execute()` runs parameterized SQL, returns JSON
- Register in `di.xml` ToolRegistry

## Security

All read-only. Use parameterized queries. Same SqlSandbox validation where applicable.
