export const contact = {
  supportUrl: 'https://wordpress.org/support/plugin/bhranti/',
  generalEmail: 'hey@bhranti.org',
  securityEmail: 'security@bhranti.org',
  privateReportUrl: 'https://github.com/sumanbasuli/bhranti/security/advisories/new',
};

export const contactIntro = 'A question, an idea, or something not working? Here’s where to reach us.';

export const contactSections = [
  {
    id: 'plugin-support',
    title: 'Plugin support',
    description: 'Need a hand with Bhranti? Ask in the WordPress.org support forum so the answer can help other people too.',
    links: [{ label: 'Open the support forum', href: contact.supportUrl }],
  },
  {
    id: 'general-questions',
    title: 'Say hello',
    description: 'For general questions, feedback or anything else, send us an email.',
    links: [{ label: contact.generalEmail, href: `mailto:${contact.generalEmail}` }],
  },
  {
    id: 'security-reports',
    title: 'Report a security issue',
    description: 'Found a vulnerability? Email us privately or use GitHub’s private reporting form. Keep security details out of public support threads.',
    links: [
      { label: contact.securityEmail, href: `mailto:${contact.securityEmail}` },
      { label: 'Report privately on GitHub', href: contact.privateReportUrl },
    ],
  },
];
