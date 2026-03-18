/**
 * Typesense Instant Search Alpine.js component
 *
 * Plain function — no RequireJS. Loaded via standard <script> tag.
 * Registered as an Alpine.js component via x-data="typesenseInstantSearch(config)".
 */
function typesenseInstantSearch(config) {
    return {
        query: new URLSearchParams(window.location.search).get('q') || '',
        hits: [],
        facets: [],
        stats: '',
        page: 1,
        totalPages: 1,
        sortBy: '',
        filters: {},
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

            if (this.query) {
                this.search();
            }
        },

        async search() {
            const perPage = config.productsPerPage || 24;

            const searchParams = {
                q: this.query || '*',
                query_by: 'name,sku,description',
                per_page: perPage,
                page: this.page,
                facet_by: config.facetAttributes.join(','),
                max_facet_values: 20,
            };

            if (this.sortBy) {
                searchParams.sort_by = this.sortBy;
            }

            const filterParts = [];
            for (const [field, values] of Object.entries(this.filters)) {
                if (values.length > 0) {
                    filterParts.push(`${field}:[${values.join(',')}]`);
                }
            }
            if (filterParts.length > 0) {
                searchParams.filter_by = filterParts.join(' && ');
            }

            try {
                const result = await this.client
                    .collections(config.productCollection)
                    .documents()
                    .search(searchParams);

                this.hits = result.hits || [];
                this.facets = result.facet_counts || [];
                this.totalPages = Math.ceil((result.found || 0) / perPage);
                this.stats = `${result.found || 0} results found in ${result.search_time_ms}ms`;
            } catch (error) {
                console.error('Typesense instant search error:', error);
            }
        },

        toggleFilter(field, value) {
            if (!this.filters[field]) {
                this.filters[field] = [];
            }

            const index = this.filters[field].indexOf(value);
            if (index === -1) {
                this.filters[field] = [...this.filters[field], value];
            } else {
                this.filters[field] = this.filters[field].filter((v) => v !== value);
            }

            this.page = 1;
            this.search();
        },

        isFilterActive(field, value) {
            return this.filters[field] ? this.filters[field].includes(value) : false;
        },

        nextPage() {
            if (this.page < this.totalPages) {
                this.page++;
                this.search();
            }
        },

        prevPage() {
            if (this.page > 1) {
                this.page--;
                this.search();
            }
        },
    };
}
