# Laravel Translations Scan

Laravel command line for scan Laravel project for obtain all texts which will need translations.

## Installation

To your composer.json file add repository:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "git@github.com:PlayeRom/laravel-translations-scan.git"
    }
]
```

Then isntall package:

```bash
composer require playerom/translations-scan --dev
```

## Usage

First run command `php artisan lang:publish` then:


```bash
php artisan lang:scan [<language>]

Arguments:
    language              The language code as output file [default: "pl"]
```

## Result

As the result the json file will be created and located in *your-laravel-project/lang/&lt;language&gt;.json* containing all found texts to translate.
