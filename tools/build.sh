#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")/.."
test -f vendor/autoload.php || { echo 'Run composer install --no-dev first.' >&2; exit 1; }
mkdir -p dist
staging=$(mktemp -d)
trap 'rm -rf "$staging"' EXIT HUP INT TERM
mkdir "$staging/wordpress-tracking-pixel"
cp wordpress-tracking-pixel.php uninstall.php README.md readme.txt LICENSE composer.json composer.lock "$staging/wordpress-tracking-pixel/"
cp -R includes vendor languages assets "$staging/wordpress-tracking-pixel/"
mkdir "$staging/wordpress-tracking-pixel/tools"
cp tools/live-test.mjs "$staging/wordpress-tracking-pixel/tools/"
target="$PWD/dist/wordpress-tracking-pixel.zip"
rm -f "$target"
cd "$staging"
zip -qr "$target" wordpress-tracking-pixel
echo "$target"