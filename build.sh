(cd dist && php -d phar.readonly=off ../vendor/bin/phar-composer build ../)

## Execute Post-Deploy Hooks
./post-build-hooks/*.sh