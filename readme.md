## Disclaimer: The entirety of this is not my works, this is a tool maintained over several years and was most recently fairly heavily redesigned for me during my time at UK. I do not claim all of the work here, but in general it is still a good display of my coding abilities due to how much of it I have changed.

# NCA/ICA Commands

Prereqs:

- Install composer or download composer.phar
    - https://getcomposer.org/ or `$ brew install composer` if you have homebrew installed
- Get selenium webdriver
    - `brew install selenium-server-standalone` or https://www.seleniumhq.org/download/
    - We're using the facebook/php-webdriver library https://facebook.github.io/php-webdriver/latest/

To get started:

- Clone this repo
- cd into repo
- Run composer: `$ composer install`

You should be able to run `./app/console` and see a list of available commands like this:

```
ca-ica git:master ❯❯❯ ./app/console
UKCOMM NCA/ICA Harvester

Usage:
  command [options] [arguments]

Options:
  -h, --help            Display this help message
  -q, --quiet           Do not output any message
  -V, --version         Display this application version
      --ansi            Force ANSI output
      --no-ansi         Disable ANSI output
  -n, --no-interaction  Do not ask any interactive question
  -v|vv|vvv, --verbose  Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

Available commands:
  help                Displays help for a command
  list                Lists commands
 guzzle
  guzzle:harvest:nca  Harvest nca data (guzzle
 harvest
  harvest:ica         Harvest ica data
  harvest:nca         Harvest nca data
```

You can also inspect each commands help section for further info, ie: `$ ./app/console harvest:ica --help`

> To run any of these commands you will need to be running a selenium server (see prereqs above). If you installed with homebrew you just have run `$ selenium-server-standalone`. If you downloaded the jar file you just have cd to the directory and run `$ java -jar selenium-server-standalone.jar`. Either way you should see a few lines of init text and see that it has started a selenium session at localhost port 4444.

## ICA How-To Guide

TODO: RIGHT HOW TO GUIDE FOR ICA
