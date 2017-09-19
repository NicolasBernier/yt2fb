<?php

/**
 * Edit this file then save it as config.php
 */

// Website configuration

define('SCRIPT_PATH', '/');                     // Path to the script (without index.php)
define('SITE_URL',    'http://www.domain.com'); // Site URL, without path

// Facebook configuration

define('SITE_NAME',   'domain.com');      // Your site name
define('FB_ADMINS',   '100000000000000'); // Your Facebook profile ID
define('EMBED_PLAYER', false);            // Embed the YouTube Flash player in Facebook. Will not work on all devices (not recommended)

// Twitter configuration

define('TWITTER',                  '@TwitterAccountName'); // Your Twitter usename
define('TWITTER_CARD_WHITELISTED', false);                 // Set this to true if your domain is whitelisted for video embedding
                                                           // Read https://dev.twitter.com/cards/types/player#submitting-your-card-for-approval to know more