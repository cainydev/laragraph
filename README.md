# Stateful, multi-agent LLM workflows in Laravel. 

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cainy/laragraph.svg?style=flat-square)](https://packagist.org/packages/cainy/laragraph)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/cainy/laragraph/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/cainy/laragraph/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/cainy/laragraph/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/cainy/laragraph/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/cainy/laragraph.svg?style=flat-square)](https://packagist.org/packages/cainy/laragraph)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Installation

You can install the package via composer:

```bash
composer require cainy/laragraph
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="laragraph-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laragraph-config"
```

This is the contents of the published config file:

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="laragraph-views"
```

## Usage

```php
$laragraph = new Cainy\Laragraph();
echo $laragraph->echoPhrase('Hello, Cainy!');
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [John Wagner](https://github.com/cainydev)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
