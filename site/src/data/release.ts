import versionSource from '../../../VERSION?raw';
import pluginSource from '../../../bhranti.php?raw';

// Read release facts at build time; this module is used only by Astro pages.
const version = versionSource.trim();
const pluginHeader = pluginSource.split('*/', 1)[0];

function header(name: string): string {
  const matches = [...pluginHeader.matchAll(new RegExp(`^\\s*\\*\\s*${name}:\\s*(.+)$`, 'gm'))];
  if (matches.length !== 1) throw new Error(`Expected one plugin ${name} header.`);
  return matches[0][1].trim();
}

if (!/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/.test(version) || header('Version') !== version) {
  throw new Error('Site version must match VERSION and the plugin header.');
}

export const release = {
  version,
  wordpress: `${header('Requires at least')}+`,
  php: `${header('Requires PHP')}+`,
  downloadUrl: `/downloads/bhranti-${version}.zip`,
  checksumUrl: `/downloads/bhranti-${version}.zip.sha256`,
  repositoryUrl: 'https://github.com/sumanbasuli/bhranti',
  wordpressOrgUrl: 'https://wordpress.org/plugins/bhranti/',
};
