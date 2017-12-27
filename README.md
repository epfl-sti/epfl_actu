# EPFL Web Services Plugin
This Wordpress plugin aim to unify all EPFL web services in one place.

<!-- toc -->

- [EPFL Actu (news)](#epfl-actu-news)
  * [Info](#info)
- [EPFL Memento (events)](#epfl-memento-events)
- [EPFL Infoscience](#epfl-infoscience)
- [EPFL People](#epfl-people)

<!-- tocstop -->

# EPFL Actu (news)
Actu Shortcode allows you to integrate EPFL News (actus) in any Wordpress pages or posts. To do so, just use the `[actu]` short code where ever you want to display the news.

In addition, you can be very picky on which news you want, by passing some arguments to the short code. Here are some example:

* `[actu]`
* `[actu tmpl=full channel=10 lang=en limit=3]`
* `[actu tmpl=short channel=10 lang=en limit=20 category=1 title=EPFL subtitle=EPFL text=EPFL faculties=6 themes=1 publics=6]`

Note that you don't have to specify any of these if you don't want to filter on something.

## Info
* ShortCode **`[actu]`** available from page, post or text widget;
* Actu shortcode can takes arguments:
  * `tmpl`: the template you want to use
    * `full`: all information;
    * `short`: compact;
    * `widget`: title and first image only.
  * `channel`: the channel's ID (e.g. sti=10). You can search your channel's ID here: <https://actu.epfl.ch/api/v1/channels/?name=sti>;
  * `lang`: english (en) or french (fr);
  * `limit`: the number of news you want;
  * `category` is in [1: EPFL, 2: EDUCATION, 3: RESEARCH, 4: INNOVATION, 5: CAMPUS LIFE];
  * `publics` is in [1: Prospective Students, 2: Students, 3: Collaborators, 4: Industries/partners, 5: Public, 6: Media];
  * `themes` is in [1: Basic Sciences, 2: Health, 3: Computer Science, 4: Engineering, 5: Environment, 6: Buildings, 7: Culture, 8: Economy, 9: Energy];
  * `faculties` is in [1: CDH, 2: CDM, 3: ENAC, 4: IC, 5: SB, 6: STI, 7: SV];
  * `search`, `title`, `subtitle`, `text` are search arguments you can use to get news across the school on, in example, keywords.


# EPFL Memento (events)
<https://github.com/epfl-sti/wordpress.plugin.memento>

# EPFL Infoscience
<https://github.com/epfl-idevelop/jahia2wp/blob/43105bfb5819eda6f7ceccfd25ed0006c64db664/data/wp/wp-content/mu-plugins/EPFL-SC-infoscience.php>

# EPFL People
