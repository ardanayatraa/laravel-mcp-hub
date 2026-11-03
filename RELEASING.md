# Release procedure

The 1.0 line includes the application-owned workflows described in
DEVELOPMENT.md. A stable tag is published only after its exact commit passes
the Tests workflow, including all thirteen PHP/Laravel rows, native PostgreSQL,
chat assets and distribution tests. A green workflow is compatibility evidence,
not a claim about production capacity or upstream framework security support.

## Verify the distribution

```sh
composer validate --strict
composer install --no-interaction --prefer-dist
composer audit --locked
composer test
node scripts/check-chat.js
composer archive --format=zip --dir=build --file=laravel-mcp-hub-1.0.0
php scripts/check-release.php build/laravel-mcp-hub-1.0.0.zip
composer update --working-dir=.compat/release --prefer-dist --no-interaction
php .compat/release/vendor/bin/phpunit --bootstrap .compat/release/vendor/autoload.php
```

The checker rejects unexpected files, verifies all runtime contents against the
checkout and extracts the archive. The second suite uses that extracted runtime,
including its migrations, views and assets. It exercises configuration
preservation, installation, pairing recovery, authorization, proposals and private
exports. PostgreSQL races run separately against the disposable CI database.
Historical framework advisory exceptions apply only to compatibility fixtures;
the modern distribution fixture must pass an unmodified Composer audit.

## Publish

Confirm the remote branch still points to the tested commit and that no 1.0.0
tag exists. Create `v1.0.0` at that exact SHA, publish release notes from
CHANGELOG.md and attach the verified ZIP and its SHA-256 checksum. Do not retag
an existing release. Corrections require a new patch release.

Wait for the tag's Tests workflow to succeed. Confirm Packagist lists `v1.0.0`
and its source reference equals the tag commit. A GitHub release alone does not
prove Composer distribution is available. Test resolution with default stable
settings, without `@alpha`, on a disposable consumer manifest.

Follow UPGRADE.md for application cutover. Publishing the library does not deploy
the Hub, change host application configuration or run production migrations.
