<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Search;

use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

class SearchParametersProvider
{
    public function __construct(
        private readonly TypeSenseConfigInterface $config,
    ) {
    }

    /**
     * @return array{
     *     text_match_type: string,
     *     drop_tokens_threshold: int,
     *     typo_tokens_threshold: int,
     *     num_typos: int,
     *     min_len_1typo: int,
     *     min_len_2typo: int,
     *     prioritize_exact_match: bool,
     *     prioritize_num_matching_fields: bool,
     *     split_join_tokens: string,
     *     prefix: bool
     * }
     */
    public function getParameters(?int $storeId = null): array
    {
        return [
            'text_match_type' => $this->config->getTextMatchType($storeId),
            'drop_tokens_threshold' => $this->config->getDropTokensThreshold($storeId),
            'typo_tokens_threshold' => $this->config->getTypoTokensThreshold($storeId),
            'num_typos' => $this->config->isTypoToleranceEnabled($storeId) ? 2 : 0,
            'min_len_1typo' => $this->config->getMinLen1Typo($storeId),
            'min_len_2typo' => $this->config->getMinLen2Typo($storeId),
            'prioritize_exact_match' => $this->config->isPrioritizeExactMatch($storeId),
            'prioritize_num_matching_fields' => true,
            'split_join_tokens' => $this->config->getSplitJoinTokens($storeId),
            'prefix' => $this->config->isPrefixSearchEnabled($storeId),
        ];
    }

    /**
     * @return array{use_cache?: true, cache_ttl?: int}
     */
    public function getCacheParameters(?int $storeId = null): array
    {
        if (!$this->config->isSearchCacheEnabled($storeId)) {
            return [];
        }

        return ['use_cache' => true, 'cache_ttl' => $this->config->getSearchCacheTtl($storeId)];
    }
}
