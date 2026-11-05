import { h } from 'vue'
import DefaultTheme from 'vitepress/theme'
import Card from './Card.vue'
import CardGrid from './CardGrid.vue'
import DocTabs from './DocTabs.vue'
import MermaidDiagram from './MermaidDiagram.vue'
import PageFeedback from './PageFeedback.vue'
import './custom.css'

export default {
  extends: DefaultTheme,
  Layout() {
    return h(DefaultTheme.Layout, null, { 'doc-after': () => h(PageFeedback) })
  },
  enhanceApp({ app }) {
    app.component('Card', Card)
    app.component('CardGrid', CardGrid)
    app.component('DocTabs', DocTabs)
    app.component('MermaidDiagram', MermaidDiagram)
  }
}
