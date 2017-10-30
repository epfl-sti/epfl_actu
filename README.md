# EPFL Actu WordPress Plugin
Insert some EPFL news on your blog from [EPFL News](https://news.epfl.ch) RSS feed.

## Info
* ShortCode **`[actu]`** available from page, post or text widget;
* ShortCode takes arguments:
  * `number`: the number of news you want (max);
  * `url`: the RSS url of news.epfl.ch, as described [here](https://help-actu.epfl.ch/flux-rss);
  * `tmpl`: the template you want to use
    * `full`: all information;
    * `short`: no description;
    * `widget`: title and first image only.

## Usage examples
 * `[actu number=10 tmpl=full url=https://actu.epfl.ch/feeds/rss/STI/en/]`
 * `[actu number=5 tmpl=short]`
 * `[actu number=3 tmpl=widget]`

## Releases
* https://github.com/epfl-sti/epfl_actu/releases/tag/v0.2
