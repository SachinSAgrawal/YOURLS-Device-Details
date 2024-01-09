# Device Details [![Listed in Awesome YOURLS!](https://img.shields.io/badge/Awesome-YOURLS-C5A3BE)](https://github.com/YOURLS/awesome-yourls/)

A plugin to display information about each click, based on Tacoded's IP Click Details. <br>
Requires Your Own URL Shortener ([YOURLS](https://yourls.org)) `v1.9.2` and above.

## Usage

![screenshot](screenshot.png)

This plugin uses WhichBrowser's Parser to display IP, user-agent, device, browser, location, and time. <br>
Check it out on my own website: [ipgrabber.pro](https://ipgrabber.pro). I use Sleeky's front-end, but the default should work. <br>
I might make a second version with more tracked stats, like device battery, orientation, language, etc.

## Installation

1. Install WhichBrowser to the root using `composer require whichbrowser/parser`.
2. In `/user/plugins`, create a new folder named `device-details`.
3. Download this git repo and drop the unzipped files in that directory.
4. Go to the Plugins admin page (eg. `http://sho.rt/admin/plugins.php`) and activate it.
5. Even if the admin area is private, make the link stats page public.
6. Do this by adding `define('YOURLS_PRIVATE_INFOS', false);` to `config.php`.

## License

This package is licensed under the [MIT License](LICENSE.txt).
