#!/usr/bin/env bash
set -euo pipefail

echo "=== Waiting for services ==="
echo "Waiting for MySQL..."
for i in $(seq 1 60); do
  php -r "try { new PDO('mysql:host=mysql;dbname=magento', 'magento', 'magento'); echo 'ok'; exit(0); } catch(Exception \$e) { exit(1); }" 2>/dev/null && break
  sleep 2
done
echo "MySQL ready."

echo "Waiting for Typesense..."
for i in $(seq 1 30); do
  curl -sf http://typesense:8108/health &>/dev/null && break
  sleep 2
done
echo "Typesense ready."

cd /var/www/html

echo "=== Creating Magento project via Composer ==="
if [ ! -f composer.json ]; then
  composer create-project --repository-url=https://repo.mage-os.org/ mage-os/project-community-edition . --no-install --no-interaction
fi

echo "=== Configuring Composer auth (public Mage-OS repo, no keys needed) ==="
composer config --no-plugins allow-plugins true
composer config repositories.mage-os composer https://repo.mage-os.org/

echo "=== Installing Magento ==="
composer install --no-interaction --prefer-dist

bin/magento setup:install \
  --base-url=http://localhost:8080 \
  --db-host=mysql \
  --db-name=magento \
  --db-user=magento \
  --db-password=magento \
  --admin-firstname=Admin \
  --admin-lastname=User \
  --admin-email=admin@example.com \
  --admin-user=admin \
  --admin-password='Admin12345!' \
  --language=en_US \
  --currency=USD \
  --timezone=America/New_York \
  --use-rewrites=1 \
  --search-engine=opensearch \
  --opensearch-host=opensearch \
  --opensearch-port=9200 \
  --backend-frontname=admin \
  --cache-backend=redis \
  --cache-backend-redis-server=redis \
  --cache-backend-redis-port=6379

echo "=== Installing sample data ==="
bin/magento sampledata:deploy || true
bin/magento setup:upgrade

echo "=== Installing TypeSense extension ==="
composer config repositories.typesense path /var/www/html/typesense-extension
composer require run-as-root/magento2-typesense:@dev --no-interaction
bin/magento setup:upgrade
bin/magento setup:di:compile

echo "=== Configuring TypeSense ==="
bin/magento config:set run_as_root_typesense/general/enabled 1
bin/magento config:set run_as_root_typesense/general/protocol http
bin/magento config:set run_as_root_typesense/general/host typesense
bin/magento config:set run_as_root_typesense/general/port 8108
bin/magento config:set run_as_root_typesense/general/api_key typesense_dev_key
bin/magento config:set run_as_root_typesense/general/search_only_api_key typesense_dev_key
bin/magento config:set run_as_root_typesense/general/index_prefix rar
bin/magento config:set run_as_root_typesense/instant_search/enabled 1
bin/magento config:set run_as_root_typesense/instant_search/replace_category_page 1
bin/magento config:set run_as_root_typesense/autocomplete/enabled 1
bin/magento config:set run_as_root_typesense/indexing/additional_attributes color,size,material,activity,gender,climate
bin/magento config:set run_as_root_typesense/instant_search/facet_filters color,size,material,activity,gender,climate
bin/magento config:set run_as_root_typesense/instant_search/sort_options relevance,price_asc,price_desc,newest
bin/magento config:set run_as_root_typesense/merchandising/category_merchandiser_enabled 1
bin/magento config:set run_as_root_typesense/merchandising/query_merchandiser_enabled 1

echo "=== Disabling TFA and admin captcha ==="
bin/magento module:disable Magento_AdminAdobeImsTwoFactorAuth Magento_TwoFactorAuth || true
bin/magento setup:upgrade

echo "=== Setting file permissions ==="
chown -R www-data:www-data /var/www/html

echo "=== Flushing caches ==="
bin/magento cache:flush

echo "=== Indexing into TypeSense ==="
bin/magento typesense:reindex

echo "=== Setup complete ==="
bin/magento typesense:health
