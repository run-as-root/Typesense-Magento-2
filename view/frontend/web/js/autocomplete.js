/**
 * Typesense Autocomplete Alpine.js component
 *
 * Plain function — no RequireJS. Loaded via standard <script> tag.
 * Registered as an Alpine.js component via x-data="typesenseAutocomplete(config)".
 */
function typesenseAutocomplete(config) {
    return {
        query: '',
        results: {
            products: [],
            categories: [],
            suggestions: [],
        },
        isOpen: false,
        hasSearched: false,
        selectedIndex: -1,
        client: null,

        init() {
            this.client = new Typesense.Client({
                nodes: [
                    {
                        host: config.typesenseHost,
                        port: String(config.typesensePort),
                        protocol: config.typesenseProtocol,
                    },
                ],
                apiKey: config.typesenseSearchOnlyApiKey,
                connectionTimeoutSeconds: 2,
            });
        },

        async search() {
            if (this.query.length < 2) {
                this.isOpen = false;
                this.hasSearched = false;
                return;
            }

            try {
                const response = await this.client.multiSearch.perform(
                    {
                        searches: [
                            {
                                collection: config.productCollection,
                                q: this.query,
                                query_by: 'name,sku,description',
                                per_page: config.productCount || 6,
                            },
                            {
                                collection: config.categoryCollection,
                                q: this.query,
                                query_by: 'name',
                                per_page: 4,
                            },
                            {
                                collection: config.suggestionCollection,
                                q: this.query,
                                query_by: 'q',
                                per_page: 5,
                            },
                        ],
                    },
                    {}
                );

                this.results.products = response.results[0]?.hits || [];
                this.results.categories = response.results[1]?.hits || [];
                this.results.suggestions = response.results[2]?.hits || [];
                this.isOpen = true;
                this.hasSearched = true;
                this.selectedIndex = -1;
            } catch (error) {
                console.error('Typesense search error:', error);
            }
        },

        get totalItems() {
            return (
                this.results.suggestions.length +
                this.results.products.length +
                this.results.categories.length
            );
        },

        moveDown() {
            if (this.totalItems === 0) {
                return;
            }
            this.selectedIndex = (this.selectedIndex + 1) % this.totalItems;
        },

        moveUp() {
            if (this.totalItems === 0) {
                return;
            }
            this.selectedIndex =
                this.selectedIndex <= 0
                    ? this.totalItems - 1
                    : this.selectedIndex - 1;
        },

        selectCurrent() {
            if (this.selectedIndex < 0) {
                return;
            }

            const suggestionsLen = this.results.suggestions.length;
            const productsLen = this.results.products.length;

            if (this.selectedIndex < suggestionsLen) {
                const hit = this.results.suggestions[this.selectedIndex];
                this.query = hit.document.q;
                this.isOpen = false;
                this.search();
                return;
            }

            const productIdx = this.selectedIndex - suggestionsLen;
            if (productIdx < productsLen) {
                const hit = this.results.products[productIdx];
                window.location.href = '/' + hit.document.url;
                return;
            }

            const categoryIdx = this.selectedIndex - suggestionsLen - productsLen;
            const hit = this.results.categories[categoryIdx];
            if (hit) {
                window.location.href = '/' + hit.document.url;
            }
        },
    };
}
