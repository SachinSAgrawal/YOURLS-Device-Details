## Version 1 Installation

Follow these steps if you wish to install version 1 of the plugin. This version is *not recommended*, as it requires a third party dependency to be installed. Also, all of the same details, plus more, are tracked in version 3, so you should probably use that instead. However, I am keeping the version up if you are worried about privacy concerns regarding device fingerprinting, and would prefer to not record this extra information, only understand what has already been logged. 

### Steps

1. Install WhichBrowser to the root using `composer require whichbrowser/parser`.
2. In `/user/plugins`, create a new directory named `device-details`.
3. Download the version 1 `plugin.php` file from this repository and add it to that folder.
4. It is possible that you will have to change the path of `vendor/autoload.php`.
   - This because when using `composer require ...`, it installs files we need somewhere.
   - Specifically, edit line 12 of the plugin point to that exact location.
   - For some users, it may be `require '../includes/vendor/autoload.php'`
5. Follow steps four and five of the original instructions to activate it.

### Note

This version is unfortunately not compatible with [Download Plugins](https://github.com/krissss/yourls-download-plugin).