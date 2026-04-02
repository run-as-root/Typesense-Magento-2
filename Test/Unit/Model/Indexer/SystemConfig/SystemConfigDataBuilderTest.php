<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Indexer\SystemConfig;

use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Indexer\SystemConfig\SystemConfigDataBuilder;

/**
 * Testable subclass that exposes the protected isSensitivePath method and
 * bypasses ResourceConnection (which is not available without Magento vendor).
 */
final class TestableSystemConfigDataBuilder extends SystemConfigDataBuilder
{
    /** @var array<int, array<string, mixed>> */
    private array $stubbedRows = [];

    public function __construct()
    {
        // Do not call parent constructor — no ResourceConnection available in unit tests.
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function setRows(array $rows): void
    {
        $this->stubbedRows = $rows;
    }

    public function getConfigData(array $entityIds, int $storeId): array
    {
        return $this->stubbedRows;
    }

    public function isSensitivePathPublic(string $path): bool
    {
        return $this->isSensitivePath($path);
    }
}

final class SystemConfigDataBuilderTest extends TestCase
{
    private TestableSystemConfigDataBuilder $sut;

    protected function setUp(): void
    {
        $this->sut = new TestableSystemConfigDataBuilder();
    }

    // -----------------------------------------------------------------
    // isSensitivePath unit tests
    // -----------------------------------------------------------------

    public function test_sensitive_path_password_is_detected(): void
    {
        self::assertTrue($this->sut->isSensitivePathPublic('payment/paypal/api_password'));
    }

    public function test_sensitive_path_key_is_detected(): void
    {
        self::assertTrue($this->sut->isSensitivePathPublic('payment/stripe/api_key'));
    }

    public function test_sensitive_path_secret_is_detected(): void
    {
        self::assertTrue($this->sut->isSensitivePathPublic('oauth/consumer/secret'));
    }

    public function test_sensitive_path_token_is_detected(): void
    {
        self::assertTrue($this->sut->isSensitivePathPublic('some/service/access_token'));
    }

    public function test_sensitive_path_encryption_is_detected(): void
    {
        self::assertTrue($this->sut->isSensitivePathPublic('system/encryption/key'));
    }

    public function test_sensitive_path_oauth_is_detected(): void
    {
        self::assertTrue($this->sut->isSensitivePathPublic('oauth/consumer/expiration_period'));
    }

    public function test_sensitive_path_detection_is_case_insensitive(): void
    {
        self::assertTrue($this->sut->isSensitivePathPublic('payment/stripe/API_KEY'));
        self::assertTrue($this->sut->isSensitivePathPublic('payment/stripe/PublicKey'));
        self::assertTrue($this->sut->isSensitivePathPublic('admin/security/PASSWORD'));
    }

    public function test_non_sensitive_path_web_secure_base_url_passes(): void
    {
        self::assertFalse($this->sut->isSensitivePathPublic('web/secure/base_url'));
    }

    public function test_non_sensitive_path_general_locale_code_passes(): void
    {
        self::assertFalse($this->sut->isSensitivePathPublic('general/locale/code'));
    }

    public function test_non_sensitive_path_catalog_frontend_list_mode_passes(): void
    {
        self::assertFalse($this->sut->isSensitivePathPublic('catalog/frontend/list_mode'));
    }

    // -----------------------------------------------------------------
    // buildDocuments integration (via stubbed getConfigData)
    // -----------------------------------------------------------------

    public function test_sensitive_paths_are_excluded(): void
    {
        $this->sut->setRows([
            ['config_id' => '1', 'path' => 'payment/paypal/api_password', 'scope' => 'default', 'scope_id' => '0', 'value' => 'secret123'],
            ['config_id' => '2', 'path' => 'admin/security/session_cookie_lifetime', 'scope' => 'default', 'scope_id' => '0', 'value' => '7200'],
            ['config_id' => '3', 'path' => 'oauth/consumer/expiration_period', 'scope' => 'default', 'scope_id' => '0', 'value' => '3600'],
            ['config_id' => '4', 'path' => 'customer/password/min_length', 'scope' => 'default', 'scope_id' => '0', 'value' => '8'],
            ['config_id' => '5', 'path' => 'catalog/seo/title_separator', 'scope' => 'default', 'scope_id' => '0', 'value' => '-'],
            ['config_id' => '6', 'path' => 'system/encryption/key', 'scope' => 'default', 'scope_id' => '0', 'value' => 'abc123'],
        ]);

        $documents = iterator_to_array($this->sut->buildDocuments(0));
        $ids = array_column($documents, 'id');

        self::assertNotContains('config_1', $ids); // api_password
        self::assertNotContains('config_3', $ids); // oauth
        self::assertNotContains('config_4', $ids); // password
        self::assertNotContains('config_6', $ids); // encryption + key
    }

    public function test_non_sensitive_paths_are_included(): void
    {
        $this->sut->setRows([
            ['config_id' => '10', 'path' => 'web/secure/base_url', 'scope' => 'default', 'scope_id' => '0', 'value' => 'https://example.com/'],
            ['config_id' => '11', 'path' => 'general/locale/code', 'scope' => 'default', 'scope_id' => '0', 'value' => 'en_US'],
            ['config_id' => '12', 'path' => 'catalog/frontend/list_mode', 'scope' => 'default', 'scope_id' => '0', 'value' => 'grid-list'],
        ]);

        $documents = iterator_to_array($this->sut->buildDocuments(0));
        $ids = array_column($documents, 'id');

        self::assertContains('config_10', $ids);
        self::assertContains('config_11', $ids);
        self::assertContains('config_12', $ids);
    }

    public function test_path_is_parsed_into_section_group_field(): void
    {
        $this->sut->setRows([
            ['config_id' => '20', 'path' => 'web/secure/base_url', 'scope' => 'default', 'scope_id' => '0', 'value' => 'https://example.com/'],
        ]);

        $documents = iterator_to_array($this->sut->buildDocuments(0));

        self::assertCount(1, $documents);
        self::assertSame('web', $documents[0]['section']);
        self::assertSame('secure', $documents[0]['group_field']);
        self::assertSame('base_url', $documents[0]['field']);
    }

    public function test_document_id_is_prefixed_with_config(): void
    {
        $this->sut->setRows([
            ['config_id' => '42', 'path' => 'web/secure/base_url', 'scope' => 'default', 'scope_id' => '0', 'value' => 'https://example.com/'],
        ]);

        $documents = iterator_to_array($this->sut->buildDocuments(0));

        self::assertSame('config_42', $documents[0]['id']);
    }

    public function test_label_equals_path(): void
    {
        $this->sut->setRows([
            ['config_id' => '30', 'path' => 'general/locale/code', 'scope' => 'default', 'scope_id' => '0', 'value' => 'en_US'],
        ]);

        $documents = iterator_to_array($this->sut->buildDocuments(0));

        self::assertSame('general/locale/code', $documents[0]['label']);
        self::assertSame('general/locale/code', $documents[0]['path']);
    }

    public function test_scope_and_scope_id_are_mapped_correctly(): void
    {
        $this->sut->setRows([
            ['config_id' => '50', 'path' => 'web/unsecure/base_url', 'scope' => 'stores', 'scope_id' => '2', 'value' => 'https://store.example.com/'],
        ]);

        $documents = iterator_to_array($this->sut->buildDocuments(0));

        self::assertSame('stores', $documents[0]['scope']);
        self::assertSame(2, $documents[0]['scope_id']);
    }

    public function test_value_defaults_to_empty_string_when_null(): void
    {
        $this->sut->setRows([
            ['config_id' => '60', 'path' => 'general/store_information/name', 'scope' => 'default', 'scope_id' => '0', 'value' => null],
        ]);

        $documents = iterator_to_array($this->sut->buildDocuments(0));

        self::assertSame('', $documents[0]['value']);
    }

    public function test_path_with_fewer_than_three_parts_handled_gracefully(): void
    {
        $this->sut->setRows([
            ['config_id' => '80', 'path' => 'section_only', 'scope' => 'default', 'scope_id' => '0', 'value' => 'val'],
            ['config_id' => '81', 'path' => 'section/group', 'scope' => 'default', 'scope_id' => '0', 'value' => 'val'],
        ]);

        $documents = iterator_to_array($this->sut->buildDocuments(0));

        self::assertCount(2, $documents);

        self::assertSame('section_only', $documents[0]['section']);
        self::assertSame('', $documents[0]['group_field']);
        self::assertSame('', $documents[0]['field']);

        self::assertSame('section', $documents[1]['section']);
        self::assertSame('group', $documents[1]['group_field']);
        self::assertSame('', $documents[1]['field']);
    }
}
