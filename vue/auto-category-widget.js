import Vue from 'vue'

import AutoCategoryWidget from "./auto-category-widget.vue"

// Make $gettext, <translation> etc. available everywhere
import Gettext from 'vue-gettext'
Vue.use(Gettext, {translations: {}})
Vue.config.getTextPluginSilent = true  // No complaining about missing languages

// addLoadEvent is the poor man's $(function(){...}) provided by WordPress
addLoadEvent(() => {
    new Vue(AutoCategoryWidget).$mount("tr#auto-category-widget")
})
