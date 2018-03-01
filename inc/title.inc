<?php
/**
 * Helper class to model titles
 * If you are looking for a test suite for all of the above, here
 * is one:
 * Full Prof :       283344 (Prof. Ali Sayed)
 * Associate Prof:   199128 (Prof. Volkan Cevher)
 * Tenure Track:     252123 (Prof. Elison Matioli)
 * SNSF Prof:        136108 (Prof. Niels Quack)
 * Adjunct Prof:     140973 (Prof. François Maréchal)
 * Senior Scientist: 168009 (Dr. Luca Pattaroni)
 * Lecturer:         106791 (Dr. Pierre Zweiacker)
 * Scientist:        248798 (Dr. Jonathan Scarlett)
 *
 * Of course, the lower down the ladder someone is, the sooner
 * they are likely to up-or-out of their current position.
 */

namespace EPFL\WS\Persons;

require_once(dirname(__FILE__) . "/i18n.inc");
use function \EPFL\WS\___;
use function \EPFL\WS\__x;

class NoSuchTitleException extends \Exception { }

/**
 * identity i18n function: picked up by xgettext, but in PHP we want
 * the original value.
 */
function __id ($thing) { return $thing; }

class Title
{
    private function TITLES()
    {
        return array(
            "FULL_PROF"        => __id("Full Professor"),
            "PROF"             => __id("Associate Professor"),
            "PATT"             => __id("Tenure Track Assistant Professor"),
            "PROF_BOURSIER"    => __id("SNSF-funded  Professor"),  // "Professeur boursier" - Yes, two spaces
            "PROF_TITULAIRE"   => __id("Adjunct Professor"),      // "Professeur titulaire"
            "MER"              => __id("Senior Scientist"),       // "Maître d'enseignement et de recherche"
            "CHARGE_DE_COURS"  => __id("Lecturer"),               // "Chargé de cours"
            "POST_DOC"         => __id("Scientist")               // Post-doc / Collaborateur scientifique
        );
    }

    function __construct($code)
    {
        if (false === array_search($code, array_keys(self::TITLES()))) {
            throw new NoSuchTitleException($code);
        }
        $this->code = $code;
    }

    public static function from_ldap ($entries)
    {
        $title_codes = array_keys(self::TITLES());
        $ranks = array_map(function($entry) use ($title_codes) {
            $title = array_search($entry["title;lang-en"][0], self::TITLES());
            return array_search($title, $title_codes);
        }, $entries);
        $ranks = array_filter($ranks, function($x) { return $x !== false; });
        if (! count($ranks)) return null;
        $klass = get_called_class();
        return new $klass($title_codes[min($ranks)]);
    }

    public function localize ()
    {
        $titles = self::TITLES();
        return ___($titles[$this->code]);
    }

    private static function numeric_rank($obj_or_code)
    {
        $klass = get_called_class();
        if ($obj_or_code instanceof $klass) {
            $code = $obj_or_code->code;
        } else {
            $code = $obj_or_code;
        }
        return array_search($code, array_keys(self::TITLES()));
    }

    static function compare ($a, $b)
    {
        $a = self::numeric_rank($a);
        $b = self::numeric_rank($b);
        if ($a === $b) {
            return 0;
        } elseif ($a === false) {
            return -1;
        } elseif ($b === false) {
            return 1;
        } else {
            return $a <=> $b;
        }
    }

    public function as_greeting ()
    {
        if (self::compare($this, "PROF_TITULAIRE") <= 0) {
            return __x("Professor", "greeting");
        } else {
            return __x("Doctor", "greeting");
        }
    }

    public function as_short_greeting ()
    {
        if (self::compare($this, "PROF_TITULAIRE") <= 0) {
            return __x("Prof.", "greeting");
        } else {
            return __x("Dr.", "greeting");
        }
    }

}
