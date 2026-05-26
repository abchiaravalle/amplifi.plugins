# Tests

Minimal harness — no PHPUnit/composer.

## Pure-PHP unit tests
Run from plugin root: `php tests/test_<name>.php`
Each test prints PASS/FAIL lines and exits 1 on first failure.

## WP-integrated tests
Run from repo root after `docker-compose up -d` inside the plugin dir:
`docker-compose exec wordpress wp eval-file /var/www/html/wp-content/plugins/ac-wp-translator/tests/test_<name>.php`
