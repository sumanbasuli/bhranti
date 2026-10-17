import { release } from '../data/release';
import { siteFooter } from '../data/marketing';

export function GET() {
  return new Response(`# Download Bhranti.

Bhranti for WordPress. Version ${release.version} — Beta.

Work on content, design, and supported site tasks, right inside WordPress.

## Get the beta.

Download the plugin ZIP, then follow the guide to install Bhranti and connect your AI provider.

[Download Bhranti ZIP](${release.downloadUrl})

[Read the setup guide](/docs/get-started/)

Requirements: WordPress ${release.wordpress} · PHP ${release.php} · Administrator access

## Set up in WordPress.

A regular plugin installation. A native AI connection. Then your first conversation.

[Full installation guide](/docs/get-started/)

1. **Install the plugin.** In WordPress, open **Plugins → Add New Plugin → Upload Plugin**. Choose the Bhranti ZIP, install it, and activate the plugin.
2. **Connect your AI provider.** Configure your provider in **Settings → Connectors**. Bhranti uses the native WordPress AI Client; provider configuration and credentials stay in Connectors.
3. **Choose permissions. Start a chat.** Review the actions enabled in Bhranti’s settings. Open Bhranti from the admin bar and start with one clear request.

## Before your first request.

### AI & privacy

Your messages and relevant site context go to your configured AI provider. Its terms apply. Understand what is shared before you connect.

[AI & privacy](/docs/ai-and-privacy/)

### Permissions & Undo

Choose the allowed actions and approve content Trash yourself. Supported changes have seven-day, conflict-safe Undo; third-party effects cannot be reversed.

[Permissions & Undo](/docs/permissions-and-undo/)

${siteFooter}

[Read this page as Markdown](/download.md)
`, { headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Content-Disposition': 'inline' } });
}
