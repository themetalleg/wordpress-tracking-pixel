#!/usr/bin/env sh
set -eu
for source in wordpress-tracking-pixel.php uninstall.php includes/*.php tests/*.php; do
    php -l "$source"
done