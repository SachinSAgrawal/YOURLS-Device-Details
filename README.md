# Device Details [![Listed in Awesome YOURLS!](https://img.shields.io/badge/Awesome-YOURLS-C5A3BE)](https://github.com/YOURLS/awesome-yourls/)

A plugin to display information about each click, based on Tacoded's IP Click Details. <br>
Requires Your Own URL Shortener ([YOURLS](https://yourls.org)) `v1.9.2` and above.

## Usage

![screenshot](screenshot.png)

Check it out on my own website: [ipgrabber.pro](https://ipgrabber.pro). I use Sleeky's front-end, but the default should work.

## Version 1
This plugin uses WhichBrowser's Parser to display IP, user-agent, device, browser, location, and time.

## Version 2
In addition to everything in version 1, as promised, this update provides more tracked stats.  <br>
By forcing a Javascript redirect, device battery, orientation, language, and screen info can be logged.  <br>
However, for this to work, the `functions.php` file needs to be modified, which is not recommended.  <br>
In fact, "hacking" core files is essentially [banned](https://yourls.org/docs/development/dont-hack-core), but I can't figure out how to do it otherwise. <br>
I have also not really tested this super rigorously, so if you find any bugs, open up an issue!

## Installation

1. Install WhichBrowser to the root using `composer require whichbrowser/parser`.
2. It is possible that you will have the change the path of `vendor/autoload.php`.
3. In `/user/plugins`, create a new folder named `device-details`.
4. Choose what version you want (see the two sections above to decide).
5. Download the right `plugin.php` file from this git repo and drop it into step 3's directory.
6. If you use version 2, make sure to also replace `/includes/functions.php` with the provided one.
7. Go to the Plugins admin page (eg. `http://sho.rt/admin/plugins.php`) and activate it.
8. Even if the admin area is private, you should make the link stats page public.
9. Do this by adding `define('YOURLS_PRIVATE_INFOS', false);` to `config.php`.

## License

This package is licensed under the [MIT License](LICENSE.txt).
