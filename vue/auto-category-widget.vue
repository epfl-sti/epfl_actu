<!--
    The top-level component for the "auto-category rules" widget

    This lets the administrator create auto-category rules that work
    sort of like email filtering rules (with WordPress categories instead
    of folders).

    * Serializes/deserializes state to/from JSON

    * Serialized JSON is available as a hidden field for "traditional" form submit

    * Consumes a number of AJAX endpoints to enumerate categories, etc.

-->
<template>
<tr>
  <td colspan="2">
    <h1 v-translate>Auto-category rules</h1>
    <button @click.prevent="rules.push({'foo': 'bar'})">Add rule</button>
    <as-input-hidden :rules="rules" form-field="foo"></as-input-hidden>
  </td>
</tr>
</template>
<script>
import asInputHidden from "./as-input-hidden.vue"

export default {
  data: () => ({
    rules: [],
  }),
  components: {
    asInputHidden
  }
}
</script>
