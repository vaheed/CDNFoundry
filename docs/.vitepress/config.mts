import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { defineConfig, type HeadConfig } from 'vitepress'

const siteUrl = (process.env.DOCS_SITE_URL ?? 'https://vaheed.github.io/CDNFoundry').replace(/\/$/, '')
const siteBase = process.env.DOCS_BASE ?? '/CDNFoundry/'

function pageUrl(relativePath: string): string {
  const cleanPath = relativePath
    .replace(/(^|\/)index\.md$/, '$1')
    .replace(/\.md$/, '')
  return `${siteUrl}/${cleanPath}`.replace(/([^:]\/)\/+/g, '$1')
}

function breadcrumbItems(relativePath: string): Array<Record<string, unknown>> {
  const cleanPath = relativePath
    .replace(/(^|\/)index\.md$/, '$1')
    .replace(/\.md$/, '')
    .replace(/\/$/, '')
  const segments = cleanPath.split('/').filter(Boolean)
  const items: Array<Record<string, unknown>> = [{
    '@type': 'ListItem',
    position: 1,
    name: 'Documentation',
    item: `${siteUrl}/`
  }]

  segments.forEach((segment, index) => {
    const itemPath = segments.slice(0, index + 1).join('/')
    items.push({
      '@type': 'ListItem',
      position: index + 2,
      name: segment.replace(/-/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()),
      item: `${siteUrl}/${itemPath}`
    })
  })

  return items
}

const getStarted = [
  {
    text: 'Get started',
    items: [
      { text: 'Overview', link: '/getting-started/' },
      { text: 'Why CDNFoundry', link: '/getting-started/why-cdnfoundry' },
      { text: 'What is in CDNFoundry', link: '/getting-started/whats-in-the-box' },
      { text: 'Private CDN design guide', link: '/getting-started/private-cdn-design' },
      { text: 'Using CDNFoundry', link: '/getting-started/using-cdnfoundry' },
      { text: 'Installation', link: '/getting-started/installation' },
      { text: 'First domain', link: '/getting-started/first-domain' },
      { text: 'FAQ', link: '/getting-started/faq' }
    ]
  },
  {
    text: 'Concepts',
    items: [
      { text: 'Product model', link: '/concepts/' },
      { text: 'CDN fundamentals', link: '/concepts/cdn-fundamentals' },
      { text: 'How it works in four diagrams', link: '/concepts/the-four-flows' },
      { text: 'How CDNFoundry works', link: '/concepts/how-cdnfoundry-works' },
      { text: 'Desired state', link: '/concepts/desired-state' },
      { text: 'Domains and DNS', link: '/concepts/domains-and-dns' },
      { text: 'Edges and cells', link: '/concepts/edges-and-cells' },
      { text: 'Revisions and operations', link: '/concepts/revisions-and-operations' }
    ]
  }
]

const guides = [
  {
    text: 'Guides',
    items: [
      { text: 'All guides', link: '/guides/' },
      { text: 'Users and access', link: '/guides/users-and-access' },
      { text: 'Domains', link: '/guides/domains' },
      { text: 'Authoritative DNS', link: '/guides/dns' },
      { text: 'Geo-DNS', link: '/guides/geo-dns' },
      { text: 'Proxy and origins', link: '/guides/proxy-and-origins' },
      { text: 'Edges and placement', link: '/guides/edges' },
      { text: 'TLS', link: '/guides/tls' },
      { text: 'Cache and purge', link: '/guides/cache' },
      { text: 'Compression', link: '/guides/compression' },
      { text: 'Security', link: '/guides/security' },
      { text: 'Managed WAF', link: '/guides/managed-waf' },
      { text: 'Analytics and usage', link: '/guides/analytics' }
    ]
  }
]

const operate = [
  {
    text: 'Deploy',
    items: [
      { text: 'Production deployment', link: '/deployment/' },
      { text: 'Production guide', link: '/deployment/production-guide' },
      { text: 'Sizing guide', link: '/deployment/sizing' },
      { text: 'Production quick start', link: '/deployment/production-quick-start' },
      { text: 'Multi-region quick start', link: '/deployment/production-quick-start-multi-region' },
      { text: 'After the quick start', link: '/deployment/production-setup-after-quick-start' },
      { text: 'Fleet operator guide', link: '/deployment/production-fleet-operator-guide' },
      { text: 'Fleet configuration', link: '/deployment/production-fleet-config-reference' },
      { text: 'Fleet architecture', link: '/deployment/production-fleet' },
      { text: 'Manual Docker Compose deployment', link: '/deployment/manual-compose' },
      { text: 'Topology', link: '/deployment/topology' },
      { text: 'Certificates', link: '/deployment/certificates' },
      { text: 'Upgrade', link: '/deployment/upgrade' }
    ]
  },
  {
    text: 'Operate',
    items: [
      { text: 'Operations index', link: '/operations/' },
      { text: 'Production best practices', link: '/operations/production-best-practices' },
      { text: 'Backup and recovery', link: '/operations/backup-and-recovery' },
      { text: 'Control-plane high availability', link: '/operations/control-plane-high-availability' },
      { text: 'Scaling', link: '/operations/scaling' },
      { text: 'Incident runbooks', link: '/operations/runbooks' },
      { text: 'Monitoring', link: '/operations/monitoring' },
      { text: 'Grafana dashboards', link: '/operations/grafana' },
      { text: 'Operational logging', link: '/operations/operational-logging' },
      { text: 'Laravel operations dashboard', link: '/operations/laravel-operations-dashboard' },
      { text: 'CLI commands', link: '/operations/cli-commands' },
      { text: 'Fleet engine and web', link: '/operations/fleet-engine' },
      { text: 'Fleet rollouts', link: '/operations/fleet-rollouts' },
      { text: 'DNS nodes and the hidden primary', link: '/operations/dns-nodes' },
      { text: 'Edge gateway ingress', link: '/operations/gateway-ingress' },
      { text: 'Bounded cell inventory', link: '/operations/cell-inventory' },
      { text: 'Multi-cell pools', link: '/operations/multi-cell-pools' },
      { text: 'Pool service endpoints', link: '/operations/pool-service-endpoints' },
      { text: 'Simple Anycast', link: '/operations/simple-anycast' },
      { text: 'Anycast qualification', link: '/operations/simple-anycast-qualification' },
      { text: 'Runtime generations', link: '/operations/runtime-generations' },
      { text: 'Signing-key rotation', link: '/operations/signing-key-rotation' },
      { text: 'Software supply chain', link: '/operations/software-supply-chain' },
      { text: 'Production qualification', link: '/operations/production-qualification' },
      { text: 'Release notes v1.2.1', link: '/operations/releases/v1.2.1' }
    ]
  },
  {
    text: 'Troubleshoot',
    items: [
      { text: 'Start here', link: '/troubleshooting/' },
      { text: 'By symptom', link: '/troubleshooting/by-symptom' },
      { text: 'DNS', link: '/troubleshooting/dns' },
      { text: 'Edge and origin', link: '/troubleshooting/edge-and-origin' },
      { text: 'TLS and cache', link: '/troubleshooting/tls-and-cache' },
      { text: 'Telemetry', link: '/troubleshooting/telemetry' }
    ]
  }
]

const reference = [
  {
    text: 'Reference',
    items: [
      { text: 'Reference index', link: '/reference/' },
      { text: 'Configuration', link: '/reference/configuration' },
      { text: 'Platform settings', link: '/reference/platform-settings' },
      { text: 'Services and ports', link: '/reference/services-and-ports' },
      { text: 'DNS records', link: '/reference/dns-records' },
      { text: 'Limits', link: '/reference/limits' },
      { text: 'CLI and scheduler', link: '/reference/cli-and-scheduler' },
      { text: 'Fleet CLI', link: '/reference/fleet-cli' },
      { text: 'Telemetry schema', link: '/reference/telemetry-schema' },
      { text: 'Glossary', link: '/reference/glossary' }
    ]
  },
  {
    text: 'API reference',
    items: [
      { text: 'API conventions', link: '/reference/api/' },
      { text: 'Endpoint catalog', link: '/reference/api/endpoints' },
      { text: 'Edge-agent API', link: '/reference/api/edge-agent' },
      { text: 'Errors', link: '/reference/api/errors' }
    ]
  }
]

const architecture = [
  {
    text: 'Architecture',
    items: [
      { text: 'System overview', link: '/architecture/' },
      { text: 'Components', link: '/architecture/components' },
      { text: 'Data flows', link: '/architecture/data-flows' },
      { text: 'Data model', link: '/architecture/data-model' },
      { text: 'Production reference architectures', link: '/architecture/production-reference-architectures' }
    ]
  },
  {
    text: 'Security',
    items: [
      { text: 'Security model', link: '/security/' },
      { text: 'Deployment hardening', link: '/security/hardening' },
      { text: 'Report a vulnerability', link: '/security/reporting' }
    ]
  }
]

const community = [
  {
    text: 'Community',
    items: [
      { text: 'Contribution guide', link: '/contributing/' },
      { text: 'Documentation', link: '/contributing/documentation' },
      { text: 'Roadmap', link: '/roadmap' }
    ]
  },
  {
    text: 'Development',
    items: [
      { text: 'Developer setup', link: '/development/' },
      { text: 'Testing', link: '/development/testing' },
      { text: 'Project layout', link: '/development/project-layout' },
      { text: 'Frontend', link: '/development/frontend' },
      { text: 'Scripts and CI', link: '/development/scripts-and-ci' },
      { text: 'Coding agents', link: '/development/coding-agents' }
    ]
  }
]

// docs/versions.json lists the released documentation sets. The latest release is served at the site root.
const versionFile = new URL('../versions.json', import.meta.url)
const versions: Array<{ text: string; link: string }> = existsSync(versionFile)
  ? JSON.parse(readFileSync(versionFile, 'utf8'))
  : []
const versionLinks = [{ text: 'Latest', link: '/' }, ...versions]

export default defineConfig({
  lang: 'en-US',
  title: 'CDNFoundry',
  description: 'Build and operate a production private CDN with authoritative DNS, bounded edge delivery, TLS, caching, security, and telemetry.',
  base: siteBase,
  appearance: true,
  cleanUrls: true,
  lastUpdated: true,
  metaChunk: true,
  sitemap: {
    hostname: `${siteUrl}/`
  },
  markdown: {
    config(markdown) {
      const defaultFence = markdown.renderer.rules.fence
      markdown.renderer.rules.fence = (tokens, index, options, environment, self) => {
        const token = tokens[index]
        if (token.info.trim() === 'mermaid') {
          return `<MermaidDiagram graph="${encodeURIComponent(token.content)}" />`
        }
        return defaultFence?.(tokens, index, options, environment, self) ?? ''
      }
    }
  },
  head: [
    ['meta', { name: 'theme-color', content: '#0f5f7a' }],
    ['meta', {
      name: 'google-site-verification',
      content: '5Vy61YITiNmNEK2ePkuwEyAL34Lq2UQ6C7xXGXt05uI'
    }],
    ['meta', { property: 'og:site_name', content: 'CDNFoundry' }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ['link', { rel: 'icon', href: `${siteBase}favicon.svg`, type: 'image/svg+xml' }]
  ],
  buildEnd(siteConfig) {
    // llms.txt: a plain index of every page for AI assistants (https://llmstxt.org).
    const lines = ['# CDNFoundry', '', '> Self-hosted private CDN: authoritative DNS, edge delivery, TLS, caching, security, and telemetry.', '']
    for (const page of [...siteConfig.pages].sort()) {
      if (page.startsWith('roadmap')) continue
      const source = readFileSync(`${siteConfig.srcDir}/${page}`, 'utf8')
      const front = /^---\n([\s\S]*?)\n---/.exec(source)?.[1] ?? ''
      const field = (name: string) => new RegExp(`^${name}:\\s*(.+)$`, 'm').exec(front)?.[1]?.replace(/^["']|["']$/g, '')
      const title = field('title') ?? page
      const description = field('description')
      lines.push(`- [${title}](${pageUrl(page)})${description ? `: ${description}` : ''}`)
    }
    writeFileSync(`${siteConfig.outDir}/llms.txt`, `${lines.join('\n')}\n`)
  },
  transformHead({ pageData }): HeadConfig[] {
    const title = pageData.frontmatter.title ?? pageData.title ?? 'CDNFoundry Documentation'
    const description = pageData.frontmatter.description ?? 'CDNFoundry private CDN documentation.'
    const canonical = pageUrl(pageData.relativePath)
    const socialImage = `${siteUrl}/social-card.svg`
    const isHome = pageData.relativePath === 'index.md'
    const keywords = pageData.frontmatter.keywords ?? [
      'private CDN',
      'self-hosted CDN',
      'ISP CDN',
      'on-premises CDN',
      'PowerDNS',
      'OpenResty',
      'Laravel'
    ]
    const articleSchema = {
      '@context': 'https://schema.org',
      '@type': isHome ? 'WebSite' : 'TechArticle',
      name: title,
      headline: title,
      description,
      url: canonical,
      inLanguage: 'en-US',
      isPartOf: {
        '@type': 'WebSite',
        name: 'CDNFoundry',
        url: `${siteUrl}/`
      }
    }
    const breadcrumbSchema = {
      '@context': 'https://schema.org',
      '@type': 'BreadcrumbList',
      itemListElement: breadcrumbItems(pageData.relativePath)
    }

    return [
      ['link', { rel: 'canonical', href: canonical }],
      ['meta', { name: 'description', content: description }],
      ['meta', { name: 'keywords', content: Array.isArray(keywords) ? keywords.join(', ') : keywords }],
      ['meta', { name: 'author', content: 'CDNFoundry contributors' }],
      ['meta', { property: 'og:type', content: isHome ? 'website' : 'article' }],
      ['meta', { property: 'og:title', content: title }],
      ['meta', { property: 'og:description', content: description }],
      ['meta', { property: 'og:url', content: canonical }],
      ['meta', { property: 'og:image', content: socialImage }],
      ['meta', { property: 'og:image:width', content: '1200' }],
      ['meta', { property: 'og:image:height', content: '630' }],
      ['meta', { property: 'og:image:alt', content: 'CDNFoundry private CDN documentation' }],
      ['meta', { property: 'og:locale', content: 'en_US' }],
      ['meta', { name: 'twitter:title', content: title }],
      ['meta', { name: 'twitter:description', content: description }],
      ['meta', { name: 'twitter:image', content: socialImage }],
      ['meta', { name: 'twitter:image:alt', content: 'CDNFoundry private CDN documentation' }],
      ['script', { type: 'application/ld+json' }, JSON.stringify(articleSchema)],
      ['script', { type: 'application/ld+json' }, JSON.stringify(breadcrumbSchema)]
    ]
  },
  themeConfig: {
    logo: {
      src: '/favicon.svg',
      alt: 'CDNFoundry documentation home'
    },
    siteTitle: 'CDNFoundry',
    search: {
      provider: 'local',
      options: {
        detailedView: true
      }
    },
    nav: [
      { text: 'Get started', link: '/getting-started/', activeMatch: '^/(getting-started|concepts)/' },
      { text: 'Guides', link: '/guides/', activeMatch: '^/guides/' },
      { text: 'Deploy and operate', link: '/deployment/', activeMatch: '^/(deployment|operations|troubleshooting)/' },
      { text: 'Reference', link: '/reference/', activeMatch: '^/reference/' },
      { text: 'Architecture', link: '/architecture/', activeMatch: '^/(architecture|security)/' },
      { text: 'Community', link: '/contributing/', activeMatch: '^/(contributing|development|roadmap)' },
      { text: 'Versions', items: versionLinks }
    ],
    sidebar: {
      '/getting-started/': getStarted,
      '/concepts/': getStarted,
      '/guides/': guides,
      '/deployment/': operate,
      '/operations/': operate,
      '/troubleshooting/': operate,
      '/reference/': reference,
      '/architecture/': architecture,
      '/security/': architecture,
      '/contributing/': community,
      '/development/': community,
      '/roadmap': community
    },
    socialLinks: [
      { icon: 'github', link: 'https://github.com/vaheed/CDNFoundry' }
    ],
    editLink: {
      pattern: 'https://github.com/vaheed/CDNFoundry/edit/main/docs/:path',
      text: 'Edit this page on GitHub'
    },
    footer: {
      message: 'CDNFoundry documentation',
      copyright: 'Licensed under the Apache License, Version 2.0'
    }
  }
})
