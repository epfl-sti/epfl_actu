<?php

/**
 * LDAP client for the EPFL directory
 */

namespace EPFL\WS;

require_once(dirname(__FILE__) . "/i18n.inc");


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
    const SEARCH_PERSON_BY_SCIPER = "(&(objectClass=person)(uniqueIdentifier=%d))";
    const SEARCH_UNIT_BY_CN = "(&(objectClass=organizationalUnit)(cn=%s))";

    static function query_by_sciper ($sciper)
    {
        return self::_do_ldap_search(
            sprintf(self::SEARCH_PERSON_BY_SCIPER, $sciper));
    }

    static function query_by_unit_name ($unit_name) {
        return self::_do_ldap_search(
            sprintf(self::SEARCH_UNIT_BY_CN, $unit_name));
    }

    static private function _do_ldap_search ($query) {
        self::_require_ldap();
        $ld = @ldap_connect(self::LDAP_SERVER);
        if (! ($ld && @ldap_bind($ld))) {
            throw new LDAPException(
                sprintf(___('Unable to bind to LDAP server %s (error: %s)'),
                        self::LDAP_SERVER,
                        ldap_error($ld)));
        }

        $results = @ldap_search($ld, self::BASE_DN, $query);
        if (! $results) {
            throw new LDAPException(sprintf(
                ___("ldap_search error: %s"), ldap_error($ld)));
        }
        $entries = @ldap_get_entries($ld, $results);
        if (! $entries) {
            throw new LDAPException(sprintf(
                ___("ldap_get_entries error: %s"), ldap_error($ld)));
        }
        return $entries;
    }

    static private function _require_ldap () {
        if (! function_exists("ldap_connect")) {
            throw new LDAPUnsupportedException();
        }
    }
}
