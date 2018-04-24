/**
 * gulpfile.js is like the Makefile for client-side Web assets:
 * JavaScript code obviously, but also CSS, minified images etc.
 *
 * Using gulpfile.js requires npm, as in "npm install" or "npm start"
 * (sans double-quotes). Once you have done either of these once, you can
 * also say "./node_modules/.bin/gulp sass" (sans double-quotes) or replace
 * "sass" with the name of any of the gulp.task's found below.
 */

// The following are provided by an NPM package (and therefore should
// each be in the "devDependencies" or "dependencies" section of
// package.json):
const gulp          = require('gulp'),
      watch         = require('gulp-watch'),
      bro           = require('gulp-bro'),
      babelify      = require('babelify'),
      sourcemaps    = require('gulp-sourcemaps'),
      uglify        = require('gulp-uglify'),
      cssnext       = require('postcss-cssnext'),
      tildeImporter = require('node-sass-tilde-importer'),
      rename        = require('gulp-rename')

gulp.task('default', ['auto-category-widget'])
gulp.task('all', ['default'])

// Run:
// gulp auto-category
// to browserify all Vue and JS files for the auto-category-widget into
// assets/auto-category-widget{,.min}.js and
// assets/auto-category-widget{,.min}.css
gulp.task('auto-category-widget', function() {
    const vue_entrypoint = gulp.src('vue/auto-category-widget.js')

    const vue_pipeline = vue_entrypoint
        .pipe(bro({  // Bro is a modern wrapper for browserify
            debug: true,  // Produce a sourcemap
            cacheFile: "tmp/browserify-cache.json",
            transform: [
                /* Turn Vue components into pure JS */
                ['vueify', {
                  /* Use advanced JS in Vue */
                  babel: babelOptions(),
                  /* You can say <style lang="scss"></style> in Vue: */
                  sass: sassOptions(),
                  postcss: postcssOptions()
                }],
                /* One more bout of Babel for "straight" (non-Vue) JS files: */
                babelify.configure(babelOptions()),
                /* You can use assert in the test suite, and the browser won't see it. */
                'unassertify'
            ]
        }))
        .pipe(assetsDest())  // Save non-minified, then continue
        /* Source maps cause the Chrome debugger to reveal all source
         * files in their pristine splendor, *provided* it doesn't
         * silently refuse to do so for reasons such as a dodgy SSL
         * cert (see the comments near const keypair, above)
         */
        .pipe(sourcemaps.init({loadMaps: true}))
        .pipe(uglify({compress: {drop_debugger: false}}))
        .pipe(rename({suffix: '.min'}))
        .pipe(assetsDest());
})

gulp.task('watch', ['default'], function() {
    gulp.watch(['vue/**/*'], ['auto-category-widget'])
})

/**
 * Babel digests modern JS into something even IE8 can grok
 */
function babelOptions() {
    return {
        "presets": [
            ["env", {
                "targets": {
                    /* IE 7 is a non-goal (not supported by Vue) */
                    "browsers": ["> 5%", "ie >= 8"]
                }
            }]
        ],
        "plugins": ["transform-vue-jsx"]
    }
}

/**
 * SASS gives us compile-time @include's in CSS, templating (with
 * variables) and more.
 */
function sassOptions() {
    return {
        importer: function(url, prev, done) {
            // This turns @import ~foo/bar into
            // @import [....]/node_modules/foo/bar:
            return tildeImporter(url, __dirname, done)
        }
    }
}

/**
 * CSSNext turns :fullscreen into :-moz-full-screen etc., and more
 *
 * @see https://cssnext.io/
 */
function postcssOptions() {
    return [cssnext()]
}

// Support functions
function assetsDest() { return  gulp.dest('assets/') }
