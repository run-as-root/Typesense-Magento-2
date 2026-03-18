define(['jquery', 'jquery/ui', 'mage/translate'], function ($, jqueryUi, $t) {
    'use strict';

    var state = {
        searchUrl: null,
        storeId: null,
        includes: [],  // [{id, position, name, sku, image_url}]
        excludes: [],  // [{id, name, sku, image_url}]
        searchTimeout: null
    };

    /**
     * Render an include product card (pinned, sortable).
     *
     * @param {Object} product
     * @param {number} index
     * @returns {string}
     */
    function renderIncludeCard(product, index) {
        var position = index + 1;
        var imageHtml = product.image_url
            ? '<img src="' + $('<div/>').text(product.image_url).html() + '" alt="" class="merchandiser-product-image"/>'
            : '<div class="merchandiser-product-image-placeholder"></div>';

        return '<div class="merchandiser-product merchandiser-pinned" data-product-id="' + parseInt(product.id, 10) + '">' +
            '<span class="merchandiser-drag-handle" title="' + $t('Drag to reorder') + '">&#9776;</span>' +
            '<span class="merchandiser-position">' + position + '</span>' +
            imageHtml +
            '<div class="merchandiser-product-info">' +
            '<span class="merchandiser-product-name">' + $('<div/>').text(product.name).html() + '</span>' +
            '<span class="merchandiser-product-sku">' + $t('SKU:') + ' ' + $('<div/>').text(product.sku).html() + '</span>' +
            '<span class="merchandiser-badge merchandiser-badge-pin">' + $t('Pinned') + '</span>' +
            '</div>' +
            '<div class="merchandiser-product-actions">' +
            '<button type="button" class="action-default merchandiser-remove-include-btn" data-product-id="' + parseInt(product.id, 10) + '">' + $t('Remove') + '</button>' +
            '</div>' +
            '</div>';
    }

    /**
     * Render an exclude product card (hidden from results).
     *
     * @param {Object} product
     * @returns {string}
     */
    function renderExcludeCard(product) {
        var imageHtml = product.image_url
            ? '<img src="' + $('<div/>').text(product.image_url).html() + '" alt="" class="merchandiser-product-image"/>'
            : '<div class="merchandiser-product-image-placeholder"></div>';

        return '<div class="merchandiser-product merchandiser-hidden" data-product-id="' + parseInt(product.id, 10) + '">' +
            imageHtml +
            '<div class="merchandiser-product-info">' +
            '<span class="merchandiser-product-name">' + $('<div/>').text(product.name).html() + '</span>' +
            '<span class="merchandiser-product-sku">' + $t('SKU:') + ' ' + $('<div/>').text(product.sku).html() + '</span>' +
            '<span class="merchandiser-badge merchandiser-badge-hide">' + $t('Hidden') + '</span>' +
            '</div>' +
            '<div class="merchandiser-product-actions">' +
            '<button type="button" class="action-default merchandiser-remove-exclude-btn" data-product-id="' + parseInt(product.id, 10) + '">' + $t('Remove') + '</button>' +
            '</div>' +
            '</div>';
    }

    /**
     * Re-render the includes product list and reinitialise sortable.
     */
    function renderIncludes() {
        var $list = $('#qm-includes-list');
        $list.sortable('destroy');
        $list.empty();

        if (state.includes.length === 0) {
            $list.append('<p class="message message-notice">' + $t('No pinned products. Search and add products to pin them at specific positions.') + '</p>');
        } else {
            $.each(state.includes, function (index, product) {
                $list.append(renderIncludeCard(product, index));
            });

            $list.sortable({
                handle: '.merchandiser-drag-handle',
                axis: 'y',
                update: function () {
                    reorderIncludesFromDOM();
                }
            });
        }

        syncHiddenFields();
    }

    /**
     * Re-render the excludes product list.
     */
    function renderExcludes() {
        var $list = $('#qm-excludes-list');
        $list.empty();

        if (state.excludes.length === 0) {
            $list.append('<p class="message message-notice">' + $t('No hidden products. Search and add products to exclude them from results.') + '</p>');
        } else {
            $.each(state.excludes, function (i, product) {
                $list.append(renderExcludeCard(product));
            });
        }

        syncHiddenFields();
    }

    /**
     * Sync state into the hidden form inputs so they are submitted with the form.
     */
    function syncHiddenFields() {
        var includesPayload = state.includes.map(function (product, index) {
            return { id: parseInt(product.id, 10), position: index + 1 };
        });

        var excludesPayload = state.excludes.map(function (product) {
            return { id: parseInt(product.id, 10) };
        });

        $('input[name="includes"]').val(JSON.stringify(includesPayload));
        $('input[name="excludes"]').val(JSON.stringify(excludesPayload));
    }

    /**
     * Sync state.includes order from current DOM order.
     */
    function reorderIncludesFromDOM() {
        var ordered = [];

        $('#qm-includes-list .merchandiser-product').each(function () {
            var productId = parseInt($(this).data('product-id'), 10);
            var match = state.includes.find(function (p) {
                return parseInt(p.id, 10) === productId;
            });

            if (match) {
                ordered.push(match);
            }
        });

        state.includes = ordered;
        updateIncludePositionNumbers();
        syncHiddenFields();
    }

    /**
     * Update displayed position numbers after drag-reorder.
     */
    function updateIncludePositionNumbers() {
        $('#qm-includes-list .merchandiser-product').each(function (index) {
            $(this).find('.merchandiser-position').text(index + 1);
        });
    }

    /**
     * Check if a product is already in the includes list.
     *
     * @param {number} productId
     * @returns {boolean}
     */
    function isIncluded(productId) {
        return state.includes.some(function (p) {
            return parseInt(p.id, 10) === productId;
        });
    }

    /**
     * Check if a product is already in the excludes list.
     *
     * @param {number} productId
     * @returns {boolean}
     */
    function isExcluded(productId) {
        return state.excludes.some(function (p) {
            return parseInt(p.id, 10) === productId;
        });
    }

    /**
     * Add a product to the includes list.
     *
     * @param {Object} product
     */
    function addToIncludes(product) {
        var productId = parseInt(product.id, 10);

        if (isIncluded(productId) || isExcluded(productId)) {
            return;
        }

        state.includes.push({
            id: String(product.id),
            name: product.name,
            sku: product.sku,
            image_url: product.image_url || ''
        });

        renderIncludes();
    }

    /**
     * Add a product to the excludes list.
     *
     * @param {Object} product
     */
    function addToExcludes(product) {
        var productId = parseInt(product.id, 10);

        if (isIncluded(productId) || isExcluded(productId)) {
            return;
        }

        state.excludes.push({
            id: String(product.id),
            name: product.name,
            sku: product.sku,
            image_url: product.image_url || ''
        });

        renderExcludes();
    }

    /**
     * Perform a product search via the admin AJAX endpoint.
     *
     * @param {string} query
     * @param {string} targetAction - 'include' or 'exclude'
     */
    function searchProducts(query, targetAction) {
        var $results = targetAction === 'include' ? $('#qm-include-search-results') : $('#qm-exclude-search-results');

        if (!query || query.length < 2) {
            $results.hide().empty();
            return;
        }

        $.ajax({
            url: state.searchUrl,
            type: 'GET',
            data: { q: query, store_id: state.storeId },
            dataType: 'json',
            success: function (results) {
                renderSearchResults(results || [], targetAction, $results);
            },
            error: function () {
                $results.hide().empty();
            }
        });
    }

    /**
     * Render the search results dropdown.
     *
     * @param {Array} results
     * @param {string} targetAction - 'include' or 'exclude'
     * @param {jQuery} $container
     */
    function renderSearchResults(results, targetAction, $container) {
        $container.empty();

        if (results.length === 0) {
            $container.append('<div class="merchandiser-search-no-results">' + $t('No products found.') + '</div>');
            $container.show();
            return;
        }

        $.each(results, function (i, product) {
            var $item = $('<div class="merchandiser-search-item" role="button" tabindex="0"/>');
            $item.data('product', product);
            $item.html(
                '<span class="merchandiser-search-item-name">' + $('<div/>').text(product.name).html() + '</span>' +
                '<span class="merchandiser-search-item-sku">' + $('<div/>').text(product.sku).html() + '</span>'
            );
            $item.on('click keypress', function (e) {
                if (e.type === 'click' || e.which === 13) {
                    if (targetAction === 'include') {
                        addToIncludes(product);
                        $('#qm-include-search').val('');
                    } else {
                        addToExcludes(product);
                        $('#qm-exclude-search').val('');
                    }
                    $container.hide().empty();
                }
            });
            $container.append($item);
        });

        $container.show();
    }

    /**
     * Load existing includes and excludes from the hidden form fields.
     */
    function loadFromHiddenFields() {
        var includesRaw = $('input[name="includes"]').val() || '[]';
        var excludesRaw = $('input[name="excludes"]').val() || '[]';

        try {
            var includesData = JSON.parse(includesRaw);

            if (Array.isArray(includesData)) {
                state.includes = includesData.map(function (item) {
                    return {
                        id: String(item.id || item.product_id || ''),
                        name: item.name || ($t('Product #') + (item.id || item.product_id || '')),
                        sku: item.sku || '',
                        image_url: item.image_url || ''
                    };
                });
            }
        } catch (e) {
            state.includes = [];
        }

        try {
            var excludesData = JSON.parse(excludesRaw);

            if (Array.isArray(excludesData)) {
                state.excludes = excludesData.map(function (item) {
                    return {
                        id: String(item.id || item.product_id || ''),
                        name: item.name || ($t('Product #') + (item.id || item.product_id || '')),
                        sku: item.sku || '',
                        image_url: item.image_url || ''
                    };
                });
            }
        } catch (e) {
            state.excludes = [];
        }
    }

    /**
     * Bind all event handlers.
     */
    function bindEvents() {
        var $container = $('#typesense-query-merchandiser');

        // Remove from includes
        $container.on('click', '.merchandiser-remove-include-btn', function () {
            var productId = parseInt($(this).data('product-id'), 10);
            state.includes = state.includes.filter(function (p) {
                return parseInt(p.id, 10) !== productId;
            });
            renderIncludes();
        });

        // Remove from excludes
        $container.on('click', '.merchandiser-remove-exclude-btn', function () {
            var productId = parseInt($(this).data('product-id'), 10);
            state.excludes = state.excludes.filter(function (p) {
                return parseInt(p.id, 10) !== productId;
            });
            renderExcludes();
        });

        // Include search input with debounce
        $('#qm-include-search').on('input', function () {
            var query = $(this).val().trim();
            clearTimeout(state.searchTimeout);
            state.searchTimeout = setTimeout(function () {
                searchProducts(query, 'include');
            }, 300);
        });

        // Exclude search input with debounce
        $('#qm-exclude-search').on('input', function () {
            var query = $(this).val().trim();
            clearTimeout(state.searchTimeout);
            state.searchTimeout = setTimeout(function () {
                searchProducts(query, 'exclude');
            }, 300);
        });

        // Close search results when clicking outside
        $(document).on('click', function (e) {
            if (!$(e.target).closest('#qm-include-search, #qm-include-search-results').length) {
                $('#qm-include-search-results').hide();
            }

            if (!$(e.target).closest('#qm-exclude-search, #qm-exclude-search-results').length) {
                $('#qm-exclude-search-results').hide();
            }
        });
    }

    /**
     * Inject the merchandiser HTML into the given container element.
     *
     * @param {jQuery} $container
     */
    function buildUI($container) {
        $container.html(
            '<div id="typesense-query-merchandiser">' +
                '<div class="qm-section">' +
                    '<h4>' + $t('Pinned Products (Includes)') + '</h4>' +
                    '<p class="qm-section-desc">' + $t('These products will be pinned at the top of results for this query, in the order listed.') + '</p>' +
                    '<div class="merchandiser-search-wrap">' +
                        '<input type="text" id="qm-include-search" class="admin__control-text" placeholder="' + $t('Search products to pin...') + '"/>' +
                        '<div id="qm-include-search-results" class="merchandiser-search-results" style="display:none;"></div>' +
                    '</div>' +
                    '<div id="qm-includes-list" class="merchandiser-product-list"></div>' +
                '</div>' +
                '<div class="qm-section">' +
                    '<h4>' + $t('Hidden Products (Excludes)') + '</h4>' +
                    '<p class="qm-section-desc">' + $t('These products will be hidden from results for this query.') + '</p>' +
                    '<div class="merchandiser-search-wrap">' +
                        '<input type="text" id="qm-exclude-search" class="admin__control-text" placeholder="' + $t('Search products to hide...') + '"/>' +
                        '<div id="qm-exclude-search-results" class="merchandiser-search-results" style="display:none;"></div>' +
                    '</div>' +
                    '<div id="qm-excludes-list" class="merchandiser-product-list"></div>' +
                '</div>' +
            '</div>'
        );
    }

    return {
        /**
         * Initialise the query merchandiser widget.
         *
         * @param {string} containerId
         * @param {string} searchUrl
         * @param {number} storeId
         */
        init: function (containerId, searchUrl, storeId) {
            var $container = $('#' + containerId);

            if ($container.length === 0) {
                return;
            }

            state.searchUrl = searchUrl;
            state.storeId = storeId || 0;

            buildUI($container);
            loadFromHiddenFields();
            bindEvents();
            renderIncludes();
            renderExcludes();
        }
    };
});
