<?php

/**
 * ISAcademia "API" client (more like, scraper in fact)
 */

namespace EPFL\WS\ISAcademia;

class ScrapeError extends \Exception { }

/**
 * @return A list of course URLs
 */
function parse_getCours ($url)
{
    $links = array();
    $html = file_get_contents($url);
    if (! $html) {
        throw new ScrapeError("Unable to read getCours page from $url");
    }
    $matches = array();
    preg_match_all('@<a href="([^"]*isa\\.epfl\\.ch[^"]*fichecours[^"]*)">@',
                   $html, $matches);
    return $matches[1];
}

function parse_course_page ($url) {
    return (new CourseParser($url))->parse();
}

class CourseParser
{
    function __construct($url)
    {
        $html = file_get_contents($url);
        if (! $html) {
            throw new ScrapeError("Unable to read course page from $url");
        }
        $this->dom = new \DOMDocument();
        @$this->dom->loadHTML($html);
        $this->finder = new \DomXPath($this->dom);
    }

    function parse ()
    {
        return array(
            "id"       => $this->_xpath2value("//fiche/@id"),
            "code"     => $this->_xpath2value("//texte[@var='ITEMPLAN_CODE']"),
            "title"    => $this->_xpath2value("//texte[@var='ITEMPLAN_XMATIERE']"),
            "summary"  => $this->_xpath2value("//texte[@var='RUBRIQUE_RESUME']"),
            "year"     => $this->_xpath2value("//n_annee"),
            "language" => $this->_xpath2value("//langue"),
        );
    }

    private function _xpath2value($xpath)
    {
        $nodes = $this->finder->query($xpath);
        return $nodes[0]->textContent;
    }
}

