# yt2fb
YT2FB is a quick project that transforms YouTube video URLs to use your domain and allow you to share them on Facebook and Twitter using a full size thumbnail instead of the lousy square miniature.

## Requirements

* Apache web server with RewriteEngine
* PHP 5

## Installation

1. Extract archive / Git clone to your web hosting folder.
2. Edit **config.example.php** accordingly then save it as **config.php**.

Note: Your domain must be whitelisted on Twitter to allow video embedding. It's not whitelisted by default so keep **TWITTER_CARD_WHITELISTED** to **false** to use the standard *large image* layout. Please read the [Player Card documentation on Twitter](https://dev.twitter.com/cards/types/player#submitting-your-card-for-approval) to learn more.

## Test!

Use the validation tools to test the integration of your videos on the social media:
* [Facebook Open Graph Debugger](https://developers.facebook.com/tools/debug/)
* [Twitter Card Validator](https://cards-dev.twitter.com/validator)
