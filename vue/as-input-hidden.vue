<!--

Render the "rules" prop (passed down by auto-category-widget.vue) as
an <input type="hidden"> component

This let the enclosing <form> work like this is 1998.

 -->
<template>
  <input type="hidden" :name="formField" :value="rulesAsJSON">
</template>

<script>
export default {
  props: {
    rules: Array,
    formField: String
  },
  computed: {
    rulesAsJSON () {
      console.log(this)
      return JSON.stringify(this.rules)
    }
  }
}
</script>
