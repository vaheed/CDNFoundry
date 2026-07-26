import { defineConfig, type HeadConfig } from 'vitepress'

const siteUrl = (process.env.DOCS_SITE_URL ?? 'https://vaheed.github.io/CDNFoundry').replace(/\/$/, '')
const siteBase = process.env.DOCS_BASE ?? '/CDNFoundry/'

function pageUrl(relativePath: string): string {
  const cleanPath = relativePath
    .replace(/(^|\/)index\.md$/, '$1')
    .replace(/\.md$/, '')
  return `${siteUrl}/${cleanPath}`.replace(/([^:]\/)\/+/g, '$1')
}

export default defineConfig({
  lang: 'en-US',
  title: 'CDNFoundry Documentation',
  description: 'Operate, deploy, use, and develop the CDNFoundry private CDN.',
  base: siteBase,
  cleanUrls: true,
  lastUpdated: true,
  metaChunk: true,
  sitemap: {
    hostname: `${siteUrl}/`
  },
  srcExclude: [
    'legacy/**'
  ],
  head: [
    ['meta', { name: 'theme-color', content: '#0f5f7a' }],
    ['meta', { property: 'og:site_name', content: 'CDNFoundry Documentation' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ['link', { rel: 'icon', href: `${siteBase}favicon.svg`, type: 'image/svg+xml' }]
  ],
  transformHead({ pageData }): HeadConfig[] {
    const title = pageData.frontmatter.title ?? pageData.title ?? 'CDNFoundry Documentation'
    const description = pageData.frontmatter.description ?? 'CDNFoundry private CDN documentation.'
    const canonical = pageUrl(pageData.relativePath)
    const socialImage = `${siteUrl}/social-card.svg`

    return [
      ['link', { rel: 'canonical', href: canonical }],
      ['meta', { name: 'description', content: description }],
      ['meta', { property: 'og:title', content: title }],
      ['meta', { property: 'og:description', content: description }],
      ['meta', { property: 'og:url', content: canonical }],
      ['meta', { property: 'og:image', content: socialImage }],
      ['meta', { name: 'twitter:title', content: title }],
      ['meta', { name: 'twitter:description', content: description }],
      ['meta', { name: 'twitter:image', content: socialImage }]
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
      { text: 'Get started', link: '/getting-started/' },
      { text: 'Guides', link: '/guides/' },
      { text: 'API', link: '/reference/api/' },
      { text: 'Operations', link: '/operations/' },
      { text: 'Development', link: '/development/' }
    ],
    sidebar: {
      '/getting-started/': [
        {
          text: 'Getting started',
          items: [
            { text: 'Overview', link: '/getting-started/' },
            { text: 'Installation', link: '/getting-started/installation' },
            { text: 'First domain', link: '/getting-started/first-domain' }
          ]
        }
      ],
      '/concepts/': [
        {
          text: 'Concepts',
          items: [
            { text: 'Product model', link: '/concepts/' },
            { text: 'Desired state', link: '/concepts/desired-state' },
            { text: 'Domains and DNS', link: '/concepts/domains-and-dns' },
            { text: 'Edges and cells', link: '/concepts/edges-and-cells' },
            { text: 'Revisions and operations', link: '/concepts/revisions-and-operations' }
          ]
        }
      ],
      '/architecture/': [
        {
          text: 'Architecture',
          items: [
            { text: 'System overview', link: '/architecture/' },
            { text: 'Components', link: '/architecture/components' },
            { text: 'Data flows', link: '/architecture/data-flows' },
            { text: 'Data model', link: '/architecture/data-model' }
          ]
        }
      ],
      '/guides/': [
        {
          text: 'Feature guides',
          items: [
            { text: 'Guide index', link: '/guides/' },
            { text: 'Users and access', link: '/guides/users-and-access' },
            { text: 'Domains', link: '/guides/domains' },
            { text: 'Authoritative DNS', link: '/guides/dns' },
            { text: 'Geo-DNS', link: '/guides/geo-dns' },
            { text: 'Proxy and origins', link: '/guides/proxy-and-origins' },
            { text: 'Edges and placement', link: '/guides/edges' },
            { text: 'TLS', link: '/guides/tls' },
            { text: 'Cache and purge', link: '/guides/cache' },
            { text: 'Security', link: '/guides/security' },
            { text: 'Analytics and usage', link: '/guides/analytics' }
          ]
        }
      ],
      '/reference/': [
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
            { text: 'Telemetry schema', link: '/reference/telemetry-schema' }
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
      ],
      '/deployment/': [
        {
          text: 'Deployment',
          items: [
            { text: 'Production deployment', link: '/deployment/' },
            { text: 'Topology', link: '/deployment/topology' },
            { text: 'Certificates', link: '/deployment/certificates' },
            { text: 'Upgrade', link: '/deployment/upgrade' }
          ]
        }
      ],
      '/operations/': [
        {
          text: 'Operations',
          items: [
            { text: 'Operations index', link: '/operations/' },
            { text: 'Monitoring', link: '/operations/monitoring' },
            { text: 'Backup and recovery', link: '/operations/backup-and-recovery' },
            { text: 'Incident runbooks', link: '/operations/runbooks' },
            { text: 'Scaling', link: '/operations/scaling' }
          ]
        }
      ],
      '/security/': [
        {
          text: 'Security',
          items: [
            { text: 'Security model', link: '/security/' },
            { text: 'Deployment hardening', link: '/security/hardening' },
            { text: 'Report a vulnerability', link: '/security/reporting' }
          ]
        }
      ],
      '/troubleshooting/': [
        {
          text: 'Troubleshooting',
          items: [
            { text: 'Start here', link: '/troubleshooting/' },
            { text: 'DNS', link: '/troubleshooting/dns' },
            { text: 'Edge and origin', link: '/troubleshooting/edge-and-origin' },
            { text: 'TLS and cache', link: '/troubleshooting/tls-and-cache' },
            { text: 'Telemetry', link: '/troubleshooting/telemetry' }
          ]
        }
      ],
      '/development/': [
        {
          text: 'Development',
          items: [
            { text: 'Developer setup', link: '/development/' },
            { text: 'Testing', link: '/development/testing' },
            { text: 'Project layout', link: '/development/project-layout' },
            { text: 'Frontend', link: '/development/frontend' },
            { text: 'Scripts and CI', link: '/development/scripts-and-ci' }
          ]
        }
      ],
      '/contributing/': [
        {
          text: 'Contributing',
          items: [
            { text: 'Contribution guide', link: '/contributing/' },
            { text: 'Documentation', link: '/contributing/documentation' },
            { text: 'Documentation audit', link: '/contributing/documentation-audit' }
          ]
        }
      ]
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
      copyright: 'Licensed under MIT'
    }
  }
})
