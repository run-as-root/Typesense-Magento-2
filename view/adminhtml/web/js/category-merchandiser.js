define(['jquery', 'jquery/ui', 'mage/translate'], function ($, jqueryUi, $t) {
    'use strict';

    var state = {
        categoryId: null,
        saveUrl: null,
        searchUrl: null,
        products: [],
        searchTimeout: null
    };

    /**
     * Determine the display action badge for a product rule.
     *
     * @param {string} action
     * @returns {string}
     */
    function getActionBadge(action) {
        if (action === 'pin') {
            return '<span class="merchandiser-badge merchandiser-badge-pin">' + $t('Pinned') + '</span>';
        }

        if (action === 'hide') {
            return '<span class="merchandiser-badge merchandiser-badge-hide">' + $t('Hidden') + '</span>';
        }

        return '';
    }

    /**
     * Render the product card HTML.
     *
     * @param {Object} product
     * @param {number} index
     * @returns {string}
     */
    function renderProductCard(product, index) {
        var position = index + 1;
        var isPinned = product.action === 'pin';
        var isHidden = product.action === 'hide';
        var cardClasses = 'merchandiser-product';

        if (isPinned) {
            cardClasses += ' merchandiser-pinned';
        }

        if (isHidden) {
            cardClasses += ' merchandiser-hidden';
        }

        var imageHtml = '';

        if (product.image_url) {
            imageHtml = '<img src="' + $('<div/>').text(product.image_url).html() + '" alt="" class="merchandiser-product-image"/>';
        } else {
            imageHtml = '<div class="merchandiser-product-image-placeholder"></div>';
        }

        var pinLabel = isPinned ? $t('Unpin') : $t('Pin');
        var hideLabel = isHidden ? $t('Show') : $t('Hide');

        return '<div class="' + cardClasses + '" data-product-id="' + parseInt(product.id, 10) + '" data-action="' + (product.action || 'none') + '">' +
            '<span class="merchandiser-drag-handle" title="' + $t('Drag to reorder') + '">&#9776;</span>' +
            '<span class="merchandiser-position">' + position + '</span>' +
            imageHtml +
            '<div class="merchandiser-product-info">' +
            '<span class="merchandiser-product-name">' + $('<div/>').text(product.name).html() + '</span>' +
            '<span class="merchandiser-product-sku">' + $t('SKU:') + ' ' + $('<div/>').text(product.sku).html() + '</span>' +
            getActionBadge(product.action) +
            '</div>' +
            '<div class="merchandiser-product-actions">' +
            '<button type="button" class="action-default merchandiser-pin-btn" data-product-id="' + parseInt(product.id, 10) + '">' + pinLabel + '</button>' +
            '<button type="button" class="action-default merchandiser-hide-btn" data-product-id="' + parseInt(product.id, 10) + '">' + hideLabel + '</button>' +
            '<button type="button" class="action-default merchandiser-remove-btn" data-product-id="' + parseInt(product.id, 10) + '">' + $t('Remove') + '</button>' +
            '</div>' +
            '</div>';
    }

    /**
     * Re-render the entire product list.
     */
    function renderProductList() {
        var $list = $('#merchandiser-product-list');
        $list.sortable('destroy');
        $list.empty();

        if (state.products.length === 0) {
            $list.append('<p class="message message-notice">' + $t('No merchandising rules defined. Search for products to add them.') + '</p>');
            return;
        }

        $.each(state.products, function (index, product) {
            $list.append(renderProductCard(product, index));
        });

        $list.sortable({
            handle: '.merchandiser-drag-handle',
            axis: 'y',
            update: function () {
                reorderProductsFromDOM();
            }
        });
    }

    /**
     * Sync state.products order from the current DOM order.
     */
    function reorderProductsFromDOM() {
        var orderedProducts = [];

        $('#merchandiser-product-list .merchandiser-product').each(function () {
            var productId = parseInt($(this).data('product-id'), 10);
            var match = state.products.find(function (p) {
                return parseInt(p.id, 10) === productId;
            });

            if (match) {
                orderedProducts.push(match);
            }
        });

        state.products = orderedProducts;
        updatePositionNumbers();
    }

    /**
     * Update displayed position numbers after reorder.
     */
    function updatePositionNumbers() {
        $('#merchandiser-product-list .merchandiser-product').each(function (index) {
            $(this).find('.merchandiser-position').text(index + 1);
        });
    }

    /**
     * Find a product in state by its ID.
     *
     * @param {number} productId
     * @returns {Object|undefined}
     */
    function findProduct(productId) {
        return state.products.find(function (p) {
            return parseInt(p.id, 10) === productId;
        });
    }

    /**
     * Load existing merchandising rules from the server.
     */
    function loadRules() {
        if (!state.categoryId) {
            $('#merchandiser-product-list').html(
                '<p class="message message-notice">' + $t('Please save the category before configuring merchandising.') + '</p>'
            );
            return;
        }

        $.ajax({
            url: state.saveUrl,
            type: 'GET',
            data: { category_id: state.categoryId },
            dataType: 'json',
            success: function (response) {
                if (response && response.rules) {
                    state.products = response.rules.map(function (rule) {
                        return {
                            id: String(rule.product_id),
                            name: rule.name || $t('Product #') + rule.product_id,
                            sku: rule.sku || '',
                            image_url: rule.image_url || '',
                            action: rule.action || 'pin'
                        };
                    });
                } else {
                    state.products = [];
                }

                renderProductList();
            },
            error: function () {
                state.products = [];
                renderProductList();
            }
        });
    }

    /**
     * Save current merchandising rules to the server.
     */
    function saveRules() {
        var rules = state.products.map(function (product, index) {
            return {
                product_id: parseInt(product.id, 10),
                position: index + 1,
                action: product.action === 'hide' ? 'hide' : 'pin'
            };
        });

        var payload = {
            category_id: state.categoryId,
            store_id: 0,
            rules: rules
        };

        var $btn = $('#merchandiser-save-btn');
        $btn.prop('disabled', true);

        $.ajax({
            url: state.saveUrl,
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            dataType: 'json',
            success: function (response) {
                if (response && response.success) {
                    $btn.addClass('merchandiser-save-success');
                    setTimeout(function () {
                        $btn.removeClass('merchandiser-save-success');
                    }, 2000);
                } else {
                    var message = (response && response.message) ? response.message : $t('An error occurred while saving.');
                    alert(message);
                }
            },
            error: function () {
                alert($t('Failed to save merchandising rules. Please try again.'));
            },
            complete: function () {
                $btn.prop('disabled', false);
            }
        });
    }

    /**
     * Perform product search against Typesense via the admin endpoint.
     *
     * @param {string} query
     */
    function searchProducts(query) {
        if (!query || query.length < 2) {
            $('#merchandiser-search-results').hide().empty();
            return;
        }

        $.ajax({
            url: state.searchUrl,
            type: 'GET',
            data: { q: query, category_id: state.categoryId },
            dataType: 'json',
            success: function (results) {
                renderSearchResults(results || []);
            },
            error: function () {
                $('#merchandiser-search-results').hide().empty();
            }
        });
    }

    /**
     * Render the search results dropdown.
     *
     * @param {Array} results
     */
    function renderSearchResults(results) {
        var $container = $('#merchandiser-search-results');
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
                    addProduct(product);
                    $container.hide().empty();
                    $('#merchandiser-product-search').val('');
                }
            });
            $container.append($item);
        });

        $container.show();
    }

    /**
     * Add a product to the merchandising list (default action: pin).
     *
     * @param {Object} product
     */
    function addProduct(product) {
        var existing = findProduct(parseInt(product.id, 10));

        if (existing) {
            return;
        }

        state.products.push({
            id: String(product.id),
            name: product.name,
            sku: product.sku,
            image_url: product.image_url || '',
            action: 'pin'
        });

        renderProductList();
    }

    /**
     * Bind all event handlers.
     */
    function bindEvents() {
        var $container = $('#typesense-category-merchandiser');

        // Pin / Unpin toggle
        $container.on('click', '.merchandiser-pin-btn', function () {
            var productId = parseInt($(this).data('product-id'), 10);
            var product = findProduct(productId);

            if (!product) {
                return;
            }

            product.action = product.action === 'pin' ? 'none' : 'pin';
            renderProductList();
        });

        // Hide / Show toggle
        $container.on('click', '.merchandiser-hide-btn', function () {
            var productId = parseInt($(this).data('product-id'), 10);
            var product = findProduct(productId);

            if (!product) {
                return;
            }

            product.action = product.action === 'hide' ? 'none' : 'hide';
            renderProductList();
        });

        // Remove product from list
        $container.on('click', '.merchandiser-remove-btn', function () {
            var productId = parseInt($(this).data('product-id'), 10);
            state.products = state.products.filter(function (p) {
                return parseInt(p.id, 10) !== productId;
            });
            renderProductList();
        });

        // Save button
        $('#merchandiser-save-btn').on('click', function () {
            saveRules();
        });

        // Search input with debounce
        $('#merchandiser-product-search').on('input', function () {
            var query = $(this).val().trim();
            clearTimeout(state.searchTimeout);
            state.searchTimeout = setTimeout(function () {
                searchProducts(query);
            }, 300);
        });

        // Close search results when clicking outside
        $(document).on('click', function (e) {
            if (!$(e.target).closest('#merchandiser-product-search, #merchandiser-search-results').length) {
                $('#merchandiser-search-results').hide();
            }
        });
    }

    return {
        /**
         * Initialize the merchandiser UI.
         *
         * @param {string} containerId
         */
        init: function (containerId) {
            var $container = $('#' + containerId);

            if ($container.length === 0) {
                return;
            }

            state.categoryId = parseInt($container.data('category-id'), 10) || null;
            state.saveUrl = $container.data('save-url');
            state.searchUrl = $container.data('search-url');

            bindEvents();
            loadRules();
        }
    };
});
