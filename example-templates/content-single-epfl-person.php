<?php
/**
 * Default "mock-up" template for EPFL Person.
 *
 */

use \EPFL\WS\Persons\Person;
global $post;
$person = Person::get($post);

// get the site header
get_header();

?><div class="container">
    <div class="row">
        <div class="col">
            <h1>I'M A PERSON</h1>
            <p>
                This page demonstrate what you can expect from the "Person" part of the
                EPFL-WS plugin. This plugin uses built in WordPress function but also
                uses some added "<code>meta input</code>" that you can retrieve within
                a template page.
            </p>
            <h2>Meta Inputs</h2>
            <p>
                The EPFL-WS\Person plugin add <code>meta inputs</code> to the post
                type <code>epfl-person</code>. They come from the institutional data
                of EPFL, as per the LDAP or people's page and are included to the
                plugin when you are creating or updating a person.
            </p>
            <h2>How to change this page?</h2>
            <p>
                In order to change this page, you can create a similar file within your
                theme, respecting the naming convention. The complete explaination stands
                on <a href="https://developer.wordpress.org/themes/basics/template-hierarchy/">
                wordpress.org</a> under the template hierarchy page, but, in summary, you
                can add a file named <code>single-epfl-person.php</code> in your theme to
                get it works.
            </p>
            <h2>Available variables</h2>
            <p>
                By using the <code>\EPFL\WS\Persons\Person</code> namespace and
                the <code>$post</code> variable you will be able to get an instance
                of the Person class: <code>$person = Person::get($post);</code>.
                <br />
                Then, with the object's accessors, you can retrieve some data.
                <pre>
                    echo $person->get_title()->as_greeting(); // get the title e.g. PATT <=> Tenure Track Assistant Professor
                    echo $person->get_publication_link();     // get the infoscience basket's link
                    echo $person->get_image_url();            // get the person's picture
                    echo $person->get_unit_long();            // get the unit breadcrumb
                    echo $person->get_bio();                  // get the bio of the person
                </pre>
                Note that the standard WordPress post content is still available as, for example:
                <pre>
                    echo get_the_post_thumbnail();
                </pre>
            </p>
        </div>
    </div>
</div><?php
// get the site footer
get_footer();
