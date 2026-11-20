<script setup>
import { onMounted, ref, watch } from 'vue'
import Alert from '../../../components/Alert.vue'
import CashFlowTable from '../../../components/CashFlowTable.vue'
import CashTabs from '../../../components/CashTabs.vue'
import http, { errorMessage } from '../../../lib/http'

// Caisse › Entrées et sorties : une seule information, l'argent entré et sorti sur la période
const period = ref('today')
const flows = ref(null)
const error = ref('')

async function load() {
  error.value = ''
  flows.value = null
  try {
    flows.value = (await http.get('/cash-overview', { params: { period: period.value } })).data.data.flows
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)
watch(period, load)
</script>

<template>
  <div class="space-y-4">
    <CashTabs />
    <Alert v-if="error">{{ error }}</Alert>
    <CashFlowTable v-model:period="period" :flows="flows" />
  </div>
</template>
