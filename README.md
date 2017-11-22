# EPFL Actu WordPress Plugin
Insert EPFL news on your WordPress site from [Actu](https://news.epfl.ch).

## Info
* ShortCode **`[actu]`** available from page, post or text widget;
* Actu shortcode can takes arguments:
  * `tmpl`: the template you want to use
    * `full`: all information;
    * `short`: compact;
    * `widget`: title and first image only.
  * `channel`: the channel's name (e.g. sti);
  * `lang`: english (en) or french (fr);
  * `limit`: the number of news you want;
  * `category`: the category ([details](https://actu.epfl.ch/api/v1/categories/));
  * `project`: the project ([details](https://actu.epfl.ch/api/jahia/channels/igm/projects/));
  * `fields`: limits the request (e.g. title,slug,...).

## Usage examples
  * `[actu]`
  * `[actu tmpl=full channel=sti lang=en limit=3]`
  * `[actu tmpl=short channel=igm lang=en limit=10 category=1 project=204 fields=title,subtitle,news_thumbnail_absolute_url,visual_and_thumbnail_description,description,absolute_slug]`

## Releases
* https://github.com/epfl-sti/wordpress.plugin.actu/releases
