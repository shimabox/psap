# Bundled JavaScript assets

## mermaid.min.js

- **Package**: [mermaid](https://www.npmjs.com/package/mermaid)
- **Version**: `11.16.0` (pinned)
- **Build**: `dist/mermaid.min.js` (global / IIFE build — exposes `globalThis.mermaid`)
- **Source**: `https://cdn.jsdelivr.net/npm/mermaid@11.16.0/dist/mermaid.min.js`
- **License**: MIT (see `mermaid.LICENSE`)

`PortalReporter` inlines this file into a `<script>` tag in the generated
`--format portal` HTML so that Mermaid diagrams render entirely in the browser
with **no external network access**. The diagram sources are supplied to
`mermaid.render()` as text nodes; `securityLevel: 'strict'` is used.

### Update policy

The version is pinned intentionally. Do **not** bump it automatically. Update
only for security fixes, and after the bump manually verify (in an offline
browser) that the portal's Diagrams tab still renders the quadrantChart and the
dependency flowchart. Mermaid's label-escaping rules can change between
versions and are relied upon by `MermaidReporter` / `MermaidFlowchartReporter`.

To refresh (replacing `X.Y.Z`):

```sh
curl -sL "https://cdn.jsdelivr.net/npm/mermaid@X.Y.Z/dist/mermaid.min.js" -o resources/js/mermaid.min.js
curl -sL "https://cdn.jsdelivr.net/npm/mermaid@X.Y.Z/LICENSE"              -o resources/js/mermaid.LICENSE
```

Then update the version recorded above.
