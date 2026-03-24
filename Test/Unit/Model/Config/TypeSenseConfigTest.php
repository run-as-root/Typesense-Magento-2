<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Test\Unit\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfig;

final class TypeSenseConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private EncryptorInterface&MockObject $encryptor;
    private TypeSenseConfig $sut;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->sut = new TypeSenseConfig($this->scopeConfig, $this->encryptor);
    }

    public function test_is_enabled_returns_true_when_config_is_set(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('run_as_root_typesense/general/enabled', ScopeInterface::SCOPE_STORE, null)
            ->willReturn(true);

        self::assertTrue($this->sut->isEnabled());
    }

    public function test_get_api_key_decrypts_value(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('run_as_root_typesense/general/api_key', ScopeInterface::SCOPE_STORE, null)
            ->willReturn('encrypted_value');

        $this->encryptor->method('decrypt')
            ->with('encrypted_value')
            ->willReturn('actual_api_key');

        self::assertSame('actual_api_key', $this->sut->getApiKey());
    }

    public function test_get_host_returns_config_value(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('run_as_root_typesense/general/host', ScopeInterface::SCOPE_STORE, null)
            ->willReturn('typesense.example.com');

        self::assertSame('typesense.example.com', $this->sut->getHost());
    }

    public function test_get_port_returns_integer(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('run_as_root_typesense/general/port', ScopeInterface::SCOPE_STORE, null)
            ->willReturn('8108');

        self::assertSame(8108, $this->sut->getPort());
    }

    public function test_get_collection_name_builds_correct_pattern(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/general/index_prefix', ScopeInterface::SCOPE_STORE, null, 'rar'],
            ]);

        self::assertSame('rar_products_default', $this->sut->getCollectionName('products', 'default'));
    }

    public function test_get_batch_size_returns_integer(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('run_as_root_typesense/indexing/batch_size', ScopeInterface::SCOPE_STORE, null)
            ->willReturn('200');

        self::assertSame(200, $this->sut->getBatchSize());
    }

    public function test_is_conversational_search_enabled_returns_false_when_module_disabled(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/general/enabled', ScopeInterface::SCOPE_STORE, null, '0'],
            ]);

        self::assertFalse($this->sut->isConversationalSearchEnabled());
    }

    public function test_get_embedding_fields_returns_array(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/conversational_search/embedding_fields', ScopeInterface::SCOPE_STORE, null, 'name,description,sku'],
            ]);

        self::assertSame(['name', 'description', 'sku'], $this->sut->getEmbeddingFields());
    }

    public function test_get_embedding_fields_returns_defaults_when_empty(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['run_as_root_typesense/conversational_search/embedding_fields', ScopeInterface::SCOPE_STORE, null, ''],
            ]);

        self::assertSame(['name', 'description'], $this->sut->getEmbeddingFields());
    }
}
