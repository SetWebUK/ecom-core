# Storefront themes

Each directory here is a theme: `themes/{slug}/theme.json`, `views/`, `assets/`, `config/`, optional `Theme.php`.
The active one is `COMMERCE_THEME` in `.env`.

    php artisan commerce:theme:make {{CLIENT_SLUG}} --parent=default     # child of the neutral default theme
    php artisan commerce:theme:make {{CLIENT_SLUG}} --parent=default --copy   # full copy to restyle everything
    php artisan commerce:theme:check {{CLIENT_SLUG}}                      # validates the theme contract
    php artisan commerce:theme:publish                                  # copies assets to public/ (never symlinks)

A child theme only contains the files it changes; everything else comes from the parent chain
(ending in the package's `default` theme). Plain CSS/JS - there is no Node build on the servers.
Full guide: pine/commerce docs/THEMES.md.
