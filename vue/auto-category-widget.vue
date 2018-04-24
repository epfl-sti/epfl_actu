<template>
<p>Hello, {{banner}} world!</p>
</template>
<script>
export default {
  el: 'auto-category-widget',
  props: {
    banner: () => "Vue"
  }
}
</script>
