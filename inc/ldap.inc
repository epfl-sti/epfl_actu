<?php

/**
 * LDAP client for the EPFL directory
 */

namespace EPFL\WS;

require_once(dirname(__FILE__) . "/i18n.inc");

use \Error;

class LDAPException extends \Exception { }
class LDAPUnsupportedException extends \Exception {
    function as_text () {
        return ___("This PHP doesn't support LDAP");
    }
}

class LDAPClient 
{
    const LDAP_SERVER = "ldap.epfl.ch";
    const BASE_DN     = "o=epfl,c=ch";
    const SEARCH_PERSON_BY_SCIPER  = "(&(objectClass=person)(uniqueIdentifier=%d))";
    const SEARCH_UNIT_BY_CN        = "(&(objectClass=EPFLorganizationalUnit)(cn=%s))";
    const SEARCH_UNIT_BY_OU        = "(&(objectClass=EPFLorganizationalUnit)(ou=%s))";
    const SEARCH_UNIT_BY_UNIQUE_ID = "(&(objectClass=EPFLorganizationalUnit)(uniqueIdentifier=%d))";
    const SEARCH_ALL_UNITS         = "(objectClass=EPFLorganizationalUnit)";
    const SEARCH_ALL_PEOPLE        = "(objectClass=Person)";

    static function query_by_sciper ($sciper)
    {
        return self::_do_ldap_search(
            sprintf(self::SEARCH_PERSON_BY_SCIPER, $sciper));
    }

    static function query_by_unit_name ($unit_name) {
        return self::_do_ldap_search(
            sprintf(self::SEARCH_UNIT_BY_CN, $unit_name));
    }

    static function query_by_unit_abbrev ($unit_abbrev) {
        return self::_do_ldap_search(
            sprintf(self::SEARCH_UNIT_BY_OU, $unit_abbrev));
    }

    static function query_by_unit_unique_id ($unique_id) {
        return self::_do_ldap_search(
            sprintf(self::SEARCH_UNIT_BY_UNIQUE_ID, $unique_id));
    }

    static function query_units_by_parent_dn ($dn) {
        return self::_do_ldap_search(self::SEARCH_ALL_UNITS, $dn, "one");
    }

    static function query_people_in_unit ($dn) {
        return self::_do_ldap_search(self::SEARCH_ALL_PEOPLE, $dn, "subtree");
    }

    static private function _do_ldap_search ($query, $base_dn = null,
                                             $scope = "subtree") {
        self::_require_ldap();
        if (! $base_dn) { $base_dn = self::BASE_DN; }
        $ld = @ldap_connect(self::LDAP_SERVER);
        if (! ($ld && @ldap_bind($ld))) {
            throw new LDAPException(
                sprintf(___('Unable to bind to LDAP server %s (error: %s)'),
                        self::LDAP_SERVER,
                        ldap_error($ld)));
        }

        switch ($scope) {
        case "subtree":
            $results = @ldap_search($ld, $base_dn, $query);
            break;
        case "one":
            $results = @ldap_list($ld, $base_dn, $query);
            break;
        default:
            throw new Error("Unknown or unimplemented scope: $scope");
        }
        if (! $results) {
            throw new LDAPException(sprintf(
                ___("ldap_search error: %s"), ldap_error($ld)));
        }
        $entries = @ldap_get_entries($ld, $results);
        if (! $entries) {
            throw new LDAPException(sprintf(
                ___("ldap_get_entries error: %s"), ldap_error($ld)));
        }
        $entry_list = array();
        for($i = 0; $i < $entries["count"]; $i++) {
            array_push($entry_list, $entries[$i]);
        }
        return $entry_list;
    }

    static private function _require_ldap () {
        if (! function_exists("ldap_connect")) {
            throw new LDAPUnsupportedException();
        }
    }
}
