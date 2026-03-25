import { test, expect } from '@playwright/test';
import { ConfigPage } from '../../pages/admin/config';

test.describe('Admin Configuration', () => {
  let config: ConfigPage;

  test.beforeEach(async ({ page }) => {
    config = new ConfigPage(page);
    await config.goto();
  });

  test('config page loads with all groups', async ({ page }) => {
    await expect(page.locator('text=General Settings')).toBeVisible();
    await expect(page.locator('text=Indexing')).toBeVisible();
    await expect(page.locator('text=Instant Search')).toBeVisible();
    await expect(page.locator('text=Autocomplete')).toBeVisible();
    await expect(page.locator('text=Conversational Search')).toBeVisible();
    await expect(page.locator('text=Product Recommendations')).toBeVisible();
  });

  test('general settings group has expected fields', async () => {
    await config.openGroup('general');
    await expect(config.field('general', 'enabled')).toBeVisible();
    await expect(config.field('general', 'host')).toBeVisible();
    await expect(config.field('general', 'port')).toBeVisible();
    await expect(config.field('general', 'search_only_api_key')).toBeVisible();
  });

  test('indexing group has additional attributes multiselect', async () => {
    await config.openGroup('indexing');
    await expect(config.field('indexing', 'additional_attributes')).toBeVisible();
    const field = config.field('indexing', 'additional_attributes');
    const tagName = await field.evaluate(el => el.tagName.toLowerCase());
    expect(tagName).toBe('select');
  });

  test('instant search group has tile attributes, sort options, facet filters', async () => {
    await config.openGroup('instant_search');
    await expect(config.field('instant_search', 'tile_attributes')).toBeVisible();
    await expect(config.field('instant_search', 'sort_options')).toBeVisible();
    await expect(config.field('instant_search', 'facet_filters')).toBeVisible();
  });

  test('recommendations group has enable toggle and limit', async () => {
    await config.openGroup('recommendations');
    await expect(config.field('recommendations', 'enabled')).toBeVisible();
    await expect(config.field('recommendations', 'limit')).toBeVisible();
  });

  test('saving config persists values', async ({ page }) => {
    await config.openGroup('recommendations');
    const limitField = config.field('recommendations', 'limit');
    await limitField.fill('12');
    await config.save();

    await expect(page.locator('.message-success')).toBeVisible({ timeout: 10_000 });

    await config.goto();
    await config.openGroup('recommendations');
    await expect(limitField).toHaveValue('12');

    await limitField.fill('8');
    await config.save();
  });
});
