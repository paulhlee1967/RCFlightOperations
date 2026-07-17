# Third-party licenses

This project uses Composer dependencies. Their licenses are listed below. The app itself is licensed under the MIT License (see [LICENSE](LICENSE)).

| Package | Version | License |
|---------|---------|---------|
| dompdf/dompdf | ^3.0 (3.1.5) | [LGPL-2.1](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html) |
| phpmailer/phpmailer | ^7.0 (7.1.1) | [LGPL-2.1-only](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html) |
| stripe/stripe-php | ^16.0 (16.6.0) | [MIT](https://github.com/stripe/stripe-php/blob/master/LICENSE) |
| masterminds/html5 | (via dompdf) | MIT |
| dompdf/php-font-lib | (via dompdf) | LGPL-2.1-or-later |
| dompdf/php-svg-lib | (via dompdf) | LGPL-3.0-or-later |
| sabberworm/php-css-parser | (via dompdf) | MIT |
| thecodingmachine/safe | (via dompdf) | MIT |

Full license texts are in each package’s directory under `vendor/` (e.g. `vendor/dompdf/dompdf/LICENSE`, `vendor/stripe/stripe-php/LICENSE`). Run `composer install` to obtain them.

**Fabric.js** (v7.4.0), **Bootstrap** (v5.3.8), and **Bootstrap Icons** (v1.11.3) are vendored under `assets/vendor/` for the UI and badge designer (not Composer dependencies). Licenses: MIT. See `scripts/fetch_vendor_assets.sh` to refresh pinned copies.

| Asset | Version | License |
|-------|---------|---------|
| Bootstrap | 5.3.8 | [MIT](https://github.com/twbs/bootstrap/blob/main/LICENSE) |
| Bootstrap Icons | 1.11.3 | [MIT](https://github.com/twbs/icons/blob/main/LICENSE) |
| Fabric.js | 7.4.0 | [MIT](https://github.com/fabricjs/fabric.js/blob/master/LICENSE) |
