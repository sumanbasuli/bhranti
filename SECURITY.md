# Security policy

If you find a security issue in Bhranti, please report it privately through [GitHub’s vulnerability reporting form](https://github.com/sumanbasuli/bhranti/security/advisories/new).

Please avoid posting vulnerabilities, exploit details or sensitive site information in public issues, discussions or support forums while a report is being investigated.

## What to include

- The affected Bhranti version, WordPress version and PHP version.
- The account permissions and settings needed to reproduce the issue.
- Clear reproduction steps on a test site, with the expected and actual result.
- The security impact and any relevant screenshots or sanitized logs.

Use fake data in examples. Do not include passwords, API keys, connector tokens, private conversations or information about other people. Do not test against a site without its owner’s permission.

## What we review

Reports may cover the WordPress plugin, its bundled code, the Bhranti website or this repository’s build and release tooling. Relevant issues include unauthorized access or changes, private-data exposure, bypassed approvals, unsafe handling of untrusted content, and compromised release packages.

WordPress permissions, conversation ownership and required human approvals must remain effective when content, images, AI responses or agent instructions contain malicious instructions. An AI response should not be able to grant itself additional access.

Please report suspected issues even if you are unsure which component is responsible. If the problem belongs to WordPress, a connector or another dependency, we can help identify the appropriate maintainer.

## Versions and updates

Use the latest stable release when possible, and include the affected version in your report. Reports about earlier releases are welcome. Fixes normally target the current release; this policy does not promise maintenance of every older version.

We will review reports privately and coordinate fixes and public disclosure with the reporter. Response and resolution times depend on the issue; no fixed response time is promised.

For ordinary bugs, feature requests or usage questions that do not expose a security issue, use the [public issue tracker](https://github.com/sumanbasuli/bhranti/issues).
