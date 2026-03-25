# Third-party licenses

This project uses Composer dependencies. Their licenses are listed below. The app itself is licensed under the MIT License (see [LICENSE](LICENSE)).

| Package | Version | License |
|---------|---------|---------|
| dompdf/dompdf | ^2.0 | [LGPL-2.1](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html) |
| phpmailer/phpmailer | ^7.0 | [LGPL-2.1-only](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html) |
| masterminds/html5 | (via dompdf) | MIT |
| phenx/php-font-lib | (via dompdf) | LGPL-2.1-or-later |
| phenx/php-svg-lib | (via dompdf) | LGPL-3.0-or-later |
| sabberworm/php-css-parser | (via dompdf) | MIT |

Full license texts are in each package’s directory under `vendor/` (e.g. `vendor/dompdf/dompdf/LICENSE`, `vendor/masterminds/html5/LICENSE.txt`). Run `composer install` to obtain them.

**Fabric.js** is loaded from a CDN (e.g. cdnjs) for the badge designer; it is not a Composer dependency. Its license: MIT. See https://github.com/fabricjs/fabric.js/blob/master/LICENSE.
