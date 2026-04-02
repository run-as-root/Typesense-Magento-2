# Agentic Admin AI Assistant — Design Document

**Date:** 2026-04-02
**Status:** Approved
**Approach:** OpenAI Function Calling agent loop with Typesense + Magento DB tools

## Overview

Replace the current Typesense-only RAG approach with an agentic architecture where OpenAI decides which tools to call based on the user's question. Instead of blindly searching all 7 collections, the LLM analyzes the question and invokes specific tools (Typesense search, SQL aggregations) to get exactly the data it needs.

## Why

The current approach dumps all search results from 7 Typesense collections into the LLM context. This causes:
- Context flooding (product data drowns out customer/order data)
- Wrong data sources ("best selling products" looks at customer lifetime values instead of `sales_count`)
- max_bytes truncation issues
- No ability to do aggregations (SUM, COUNT, AVG)

## Architecture

### Agent Loop

```
1. User sends question via AJAX
2. PHP Chat controller builds messages: [system_prompt, user_question]
3. Call OpenAI chat/completions with tool definitions
4. If response contains tool_calls:
   a. Execute each tool (Typesense search, DB query, etc.)
   b. Append tool_call + tool_result to messages
   c. Go back to step 3
5. If response is final text: return answer to browser
6. Max iterations: 5 (safety limit)
```

### Tools

#### 1. `search_typesense`
Search any indexed Typesense collection with optional filters and sorting.

**Parameters:**
- `collection` (enum: product, order, customer, category, cms_page, store, system_config)
- `query` (string) — the search query
- `filter_by` (string, optional) — Typesense filter syntax, e.g. `shipping_country:DE`
- `sort_by` (string, optional) — e.g. `sales_count:desc`, `grand_total:desc`
- `limit` (int, optional, default: 10)

**Returns:** Array of matching documents with all fields.

#### 2. `query_orders`
Run pre-built SQL aggregations on order data.

**Parameters:**
- `aggregation` (enum):
  - `total_revenue` — SUM of grand_total
  - `revenue_by_country` — grouped by shipping country
  - `revenue_by_customer` — grouped by customer, sorted by total
  - `order_count_by_status` — grouped by status
  - `avg_order_value` — AVG of grand_total
  - `top_customers_by_revenue` — top N customers by total spend
  - `orders_by_month` — grouped by month
- `filters` (object, optional):
  - `country` (string)
  - `status` (string)
  - `date_from` (string, YYYY-MM-DD)
  - `date_to` (string, YYYY-MM-DD)
  - `limit` (int, default: 10)

**Returns:** Aggregated data as key-value pairs.

#### 3. `query_customers`
Run pre-built SQL aggregations on customer data.

**Parameters:**
- `aggregation` (enum):
  - `count_by_country` — customer count per country
  - `count_by_group` — customer count per group
  - `top_by_lifetime_value` — top N customers by total spend
  - `top_by_order_count` — top N customers by order count
- `filters` (object, optional):
  - `country` (string)
  - `group` (string)
  - `limit` (int, default: 10)

**Returns:** Aggregated data.

#### 4. `query_products`
Run pre-built SQL/Typesense aggregations on product data.

**Parameters:**
- `aggregation` (enum):
  - `top_by_sales_count` — best sellers
  - `low_stock` — products with low inventory
  - `price_range` — min/max/avg prices
  - `count_by_category` — products per category
- `filters` (object, optional):
  - `category` (string)
  - `limit` (int, default: 10)

**Returns:** Aggregated data.

## System Prompt

```
You are an AI assistant for a Magento e-commerce store administrator.
You have tools to search and query store data. Always use tools to get data before answering — never guess or make assumptions.

TOOL SELECTION GUIDE:
- "best selling products" → query_products(aggregation: top_by_sales_count)
- "total revenue" / "how much did I sell" → query_orders(aggregation: total_revenue)
- "revenue by country" → query_orders(aggregation: revenue_by_country)
- "top customers" / "highest spending" → query_orders(aggregation: top_customers_by_revenue)
- "how many customers in Germany" → query_customers(aggregation: count_by_country, filters: {country: DE})
- "find product X" / "search for Y" → search_typesense(collection: product, query: X)
- "what is my store URL" → search_typesense(collection: system_config, query: base_url)
- "shipping policy" / CMS content → search_typesense(collection: cms_page, query: shipping policy)

RESPONSE GUIDELINES:
- Include specific numbers, names, currencies, and values
- Use bullet points or tables for lists
- For financial data, always include the currency
- If a tool returns no results, say so clearly
- You can call multiple tools to build a complete answer
```

## New Dependencies

- `openai-php/client` — OpenAI PHP SDK for chat completions with function calling

## Files to Create/Modify

### New Files
- `Model/Assistant/AgentLoop.php` — Core agent loop: messages → OpenAI → tool execution → repeat
- `Model/Assistant/Tool/SearchTypesenseTool.php` — Executes Typesense searches
- `Model/Assistant/Tool/QueryOrdersTool.php` — Executes order SQL aggregations
- `Model/Assistant/Tool/QueryCustomersTool.php` — Executes customer SQL aggregations
- `Model/Assistant/Tool/QueryProductsTool.php` — Executes product SQL aggregations
- `Model/Assistant/ToolRegistry.php` — Registers all tools, generates OpenAI tool definitions
- `Model/Assistant/OpenAiClientFactory.php` — Creates OpenAI client instances

### Modified Files
- `Controller/Adminhtml/Assistant/Chat.php` — Replace multi_search with AgentLoop
- `composer.json` — Add `openai-php/client` dependency

### Removed/Deprecated
- `SearchRequestBuilder.php` — No longer needed (agent decides what to search)
- Typesense conversation model dependency — OpenAI handles conversation directly
- `AdminConversationModelManager.php` — Conversation history managed differently

## Security

- All tools are read-only (no writes to DB or Typesense)
- SQL queries are pre-built with parameterized inputs (no raw SQL from LLM)
- Tool parameters are validated before execution
- Agent loop has max iteration limit (5) to prevent infinite loops
- OpenAI API key stored encrypted in Magento config (already exists)

## Conversation History

- Session-based: messages array stored in PHP session or passed from frontend via sessionStorage
- Each new request includes previous messages for context continuity
- "New Chat" clears the history
- No Typesense conversation_history collection needed

## Config Changes

- Reuse existing `admin_assistant` config section
- Add: `openai_api_key` field (or reuse existing conversational_search key)
- Add: `max_iterations` field (default: 5)
- OpenAI model: reuse existing `admin_assistant/openai_model` config (change default to `gpt-4o`)
