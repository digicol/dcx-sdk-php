# Official PHP SDK for the Digital Collections DC-X Digital Asset Management system

The `DcxApiClient` class helps your custom PHP code connect to your [DC-X](http://www.digicol.com/products/dc-x/) system
via the HTTP-based [DC-X JSON API](http://wiki.digicol.de/x/1oTc) (documented in our partner and customer Wiki).

## Versions

If you need to use the old `DCX_Api_Client` class, check out the [1.0.0 release](https://github.com/digicol/dcx-sdk-php/releases/tag/1.0.0).

For everyone else, we recommend the latest, [Guzzle](http://guzzlephp.org/)-based version.

## Installation in Composer-based projects

If your PHP project uses [Composer](https://getcomposer.org), installation is straightforward.

1) Either run `composer require digicol/dcx-sdk-php`.
2) Or add this to your project’s `composer.json` file:

```
    "require":
    {
        "digicol/dcx-sdk-php": "^2.0"
    },
```

… and run `composer update` in your project to download the SDK.

## Installation in projects not using Composer
 
You don’t have to use Composer in your project to use the SDK.
But you still need it (see its [installation instructions](https://getcomposer.org/doc/00-intro.md)) for 
downloading the SDK’s dependencies after checking out the sources:

```
$ git clone https://github.com/digicol/dcx-sdk-php.git
$ cd dcx-sdk-php
$ composer install
```

In your PHP code, include the SDK’s autoloader like this:

```
require('/path/to/dcx-sdk-php/vendor/autoload.php');
```

## Getting started

Here’s an example of retrieving a DC-X collection’s details (name, links to retrieving documents): 

```
<?php
 
require __DIR__ . '/vendor/autoload.php';
 
$dcxApiClient = new \Digicol\DcxSdk\DcxApiClient
(
    'http://example.com/dcx/api/',
    ['username' => 'testuser', 'password' => 'secret'],
    ['http_useragent' => 'MyCustomProject']
);
 
$httpStatusCode = $dcxApiClient->get
(
    'document',
    [
        'q' => ['channel' => ['ch050dcxsystempoolnative']],
        's' => ['fields' => ['_display_title', 'DateCreated']]
    ],
    $documentsData
);
 
echo "Got search results:\n";
var_dump($httpStatusCode);
print_r($documentsData);
 
```

See the [DC-X JSON API documentation](http://wiki.digicol.de/x/1oTc) for more examples.
