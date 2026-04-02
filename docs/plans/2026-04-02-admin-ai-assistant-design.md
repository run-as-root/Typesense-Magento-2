# Admin AI Assistant — Design Document

**Date:** 2026-04-02
**Status:** Approved
**Approach:** Typesense Multi-Collection RAG (Approach A)

## Overview

Add an AI-powered assistant to the Magento admin interface that lets administrators ask natural language questions about their store — products, orders, customers, CMS content, store configuration, and more. The assistant uses Typesense's built-in RAG (conversation) feature with a dedicated admin conversation model, separate from the existing frontend product search RAG.

## Goals

- Admin users can ask questions like "Which countries do I sell the most to?", "Who are my top customers?", "What products have the lowest stock?"
- AI button visible on every admin page (global toolbar injection)
- Right-side slideout panel with ChatGPT-like interface
- Conversation persists across page navigations (sessionStorage)
- Sensitive data (orders, customers, config) never accessible from the frontend

## Non-Goals

- Real-time SQL aggregation queries (we rely on Typesense search + LLM interpretation)
- Frontend access to admin data collections
- Multi-user conversation sharing

---

## Architecture

### Approach: Typesense Multi-Collection RAG

All store data is indexed into Typesense collections. When the admin asks a question, the PHP backend performs a `multi_search` across all collections with `conversation=true` and a dedicated admin conversation model. Typesense retrieves relevant documents and the LLM generates a natural language answer.

### Why This Approach

- Consistent with existing frontend RAG architecture
- Leverages Typesense's built-in conversation feature (no custom RAG pipeline)
- Ships faster than alternatives (direct LLM or hybrid approaches)
- Can evolve toward custom aggregation if needed later

---

## New Data Collections

### Orders Collection (`{prefix}_order_{store}`)

| Field | Type | Notes |
|-------|------|-------|
| id | string | Primary key |
| order_id | int32 | Magento entity ID |
| increment_id | string | Human-readable order number |
| status | string | Facetable |
| state | string | Facetable |
| customer_email | string | |
| customer_name | string | |
| customer_group | string | Facetable |
| grand_total | float | |
| subtotal | float | |
| tax_amount | float | |
| shipping_amount | float | |
| discount_amount | float | |
| currency_code | string | Facetable |
| payment_method | string | Facetable |
| shipping_country | string | Facetable |
| shipping_region | string | Facetable |
| shipping_city | string | |
| shipping_method | string | Facetable |
| billing_country | string | Facetable |
| billing_region | string | |
| item_count | int32 | |
| item_skus | string[] | |
| item_names | string[] | |
| created_at | int64 | Unix timestamp |
| updated_at | int64 | Unix timestamp |
| store_id | int32 | Facetable |
| embedding | float[] | Auto-generated, from: increment_id, customer_name, item_names, shipping_country, status |

### Customer Collection (`{prefix}_customer_{store}`)

| Field | Type | Notes |
|-------|------|-------|
| id | string | Primary key |
| customer_id | int32 | |
| email | string | |
| firstname | string | |
| lastname | string | |
| group_id | int32 | |
| group_name | string | Facetable |
| created_at | int64 | Unix timestamp |
| updated_at | int64 | Unix timestamp |
| dob | string | Optional |
| gender | string | Optional, facetable |
| default_billing_country | string | Facetable |
| default_billing_region | string | Facetable |
| default_billing_city | string | |
| default_shipping_country | string | Facetable |
| default_shipping_region | string | Facetable |
| default_shipping_city | string | |
| order_count | int32 | |
| lifetime_value | float | |
| last_order_date | int64 | Unix timestamp |
| store_id | int32 | Facetable |
| website_id | int32 | Facetable |
| is_active | bool | Facetable |
| embedding | float[] | Auto-generated, from: email, firstname, lastname, group_name |

### Store/Website Collection (`{prefix}_store_{store}`)

| Field | Type | Notes |
|-------|------|-------|
| id | string | Primary key |
| store_id | int32 | |
| store_code | string | |
| store_name | string | |
| website_id | int32 | |
| website_code | string | |
| website_name | string | |
| group_id | int32 | |
| group_name | string | |
| root_category_id | int32 | |
| base_url | string | |
| base_currency | string | |
| default_locale | string | |
| is_active | bool | Facetable |

### System Config Collection (`{prefix}_config_{store}`)

| Field | Type | Notes |
|-------|------|-------|
| id | string | Primary key |
| path | string | Config path (e.g. `web/secure/base_url`) |
| scope | string | default/websites/stores |
| scope_id | int32 | |
| value | string | |
| section | string | First segment of path |
| group | string | Second segment of path |
| field | string | Third segment of path |
| label | string | Human-readable path description |

**Security filter:** Exclude sensitive paths containing: `password`, `key`, `secret`, `token`, `encryption`, `credential`, `oauth`, `api_key`.

---

## Security Model

### API Key Scoping

- **Frontend search-only API key** (existing): scoped to products, categories, CMS pages, suggestions only. No change needed.
- **Server API key** (full access): used exclusively in PHP admin controller. Never exposed to browser.

### Request Flow

1. Admin clicks AI button, types question in slideout chat
2. Browser sends AJAX POST to `typesense/assistant/chat` with question + conversation_id
3. Magento admin controller verifies admin session (standard admin auth)
4. Controller checks ACL resource `RunAsRoot_TypeSense::ai_assistant`
5. Controller calls Typesense `multi_search` with server API key across all collections
6. Response returned to browser as JSON

### ACL

New ACL resource: `RunAsRoot_TypeSense::ai_assistant` — controls which admin roles can access the AI assistant.

---

## Admin Conversation Model

**Model ID:** `rar-admin-assistant` (separate from frontend's `rar-product-assistant`)

**Configuration:**
- OpenAI API key: shared from existing conversational search config
- OpenAI model: configurable (default: `openai/gpt-4o-mini`)
- History collection: `{prefix}_admin_conversation_history`
- max_bytes: 16384
- TTL: configurable (default: 86400s)

**System Prompt:**
```
You are a Magento store analytics assistant. You have access to the following data:
- Products: catalog items with prices, stock, categories, attributes
- Categories: product category hierarchy
- CMS Pages: content pages
- Orders: sales data including amounts, countries, items, payment methods
- Customers: customer profiles with order history and lifetime value
- Store Config: system configuration and settings

Answer questions accurately based on the search results provided. Format numbers clearly.
When discussing revenue, always mention the currency. For time-based questions, note the data scope.
```

---

## Admin UI

### Global AI Button

Injected via `adminhtml/default.xml` layout handle (applies to all admin pages). Floating button in bottom-right corner or header toolbar button with AI icon.

### Slideout Panel

Uses Magento's `Magento_Ui/js/modal/modal` with `type: 'slide'` (standard right-side slideout).

**Layout:**
```
+----------------------------------+
|  TypeSense AI Assistant        X |
|----------------------------------|
|                                  |
|  [Welcome message]              |
|                                  |
|  USER: question bubble           |
|                                  |
|  AI: answer bubble with          |
|  markdown formatting             |
|                                  |
|----------------------------------|
| [input field............] [Send] |
| [New Chat]                       |
+----------------------------------+
```

### Chat Behavior

- **Persistence:** sessionStorage stores message history + Typesense conversation_id
- **Typing indicator:** Shows while waiting for Typesense response
- **Markdown rendering:** AI responses rendered as basic HTML (bold, lists, code)
- **"New Chat" button:** Clears history, generates new conversation_id
- **Error handling:** Friendly error message with retry button on failure

### Tech Stack

- Vanilla JS with RequireJS (standard Magento admin pattern)
- Magento UI modal component for slideout
- No external chat libraries

---

## New Files

### Controllers
- `Controller/Adminhtml/Assistant/Chat.php` — AJAX chat endpoint

### Models
- `Model/Conversation/AdminConversationModelManager.php` — Admin conversation model lifecycle
- `Model/Indexer/Order/OrderSchemaProvider.php` + `OrderDataBuilder.php` + `OrderIndexer.php`
- `Model/Indexer/Customer/CustomerSchemaProvider.php` + `CustomerDataBuilder.php` + `CustomerIndexer.php`
- `Model/Indexer/Store/StoreSchemaProvider.php` + `StoreDataBuilder.php` + `StoreIndexer.php`
- `Model/Indexer/SystemConfig/SystemConfigSchemaProvider.php` + `SystemConfigDataBuilder.php` + `SystemConfigIndexer.php`

### Configuration
- `etc/adminhtml/system.xml` — New `admin_assistant` section (enable, system prompt, LLM model)
- `etc/acl.xml` — New `RunAsRoot_TypeSense::ai_assistant` resource
- `etc/indexer.xml` — Register 4 new indexers
- `etc/mview.xml` — Materialized views for new indexers

### View (adminhtml)
- `view/adminhtml/layout/default.xml` — Global AI button block injection
- `view/adminhtml/templates/assistant/button.phtml` — Floating AI button
- `view/adminhtml/templates/assistant/chat.phtml` — Slideout panel content
- `view/adminhtml/web/js/assistant.js` — Chat logic, sessionStorage, AJAX
- `view/adminhtml/web/css/assistant.css` — Chat styling

### Observer
- Extend or add observer to sync admin conversation model on config save

---

## Multi-Search Query Structure

```php
$searchRequests = [
    ['collection' => '{prefix}_product_{store}', 'q' => $query, 'query_by' => 'name,description,sku,...'],
    ['collection' => '{prefix}_category_{store}', 'q' => $query, 'query_by' => 'name,description'],
    ['collection' => '{prefix}_cms_page_{store}', 'q' => $query, 'query_by' => 'title,content'],
    ['collection' => '{prefix}_order_{store}', 'q' => $query, 'query_by' => 'increment_id,customer_name,item_names,...'],
    ['collection' => '{prefix}_customer_{store}', 'q' => $query, 'query_by' => 'email,firstname,lastname,group_name,...'],
    ['collection' => '{prefix}_store_{store}', 'q' => $query, 'query_by' => 'store_name,website_name,...'],
    ['collection' => '{prefix}_config_{store}', 'q' => $query, 'query_by' => 'path,label,value'],
];

$response = $client->multiSearch->perform(
    ['searches' => $searchRequests],
    [
        'conversation' => true,
        'conversation_model_id' => 'rar-admin-assistant',
        'conversation_id' => $conversationId,
    ]
);
```

---

## Indexing Strategy

All 4 new indexers follow the existing pattern:
- `SchemaProvider` defines collection schema
- `DataBuilder` builds Typesense documents from Magento entities
- `Indexer` orchestrates batch processing
- Support for: cron, queue (RabbitMQ/MySQL), zero-downtime reindexing
- Registered in `EntityIndexerPool`

---

## Config Admin Section

New section under `run_as_root_typesense/admin_assistant/`:
- `enabled` — Enable/disable admin AI assistant
- `system_prompt` — Customizable system prompt for admin model
- `openai_model` — LLM model override (default: inherits from conversational_search)
- `conversation_ttl` — Conversation history TTL in seconds
