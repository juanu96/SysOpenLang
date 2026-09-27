# Security policy

## Supported versions

Security fixes are provided for the latest published SysOpenLang release. Site owners should update to the latest stable version before reporting an issue.

## Reporting a vulnerability

Do not publish an exploitable vulnerability in a public issue before a fix is available. Use GitHub's private vulnerability reporting for this repository when it is enabled. If private reporting is unavailable, contact the repository owner through the private contact method shown on their GitHub profile and include:

- the affected SysOpenLang version;
- the WordPress and PHP versions;
- the required user role or access level;
- reproducible steps and the security impact;
- a minimal proof of concept, without real credentials or personal data.

Please allow reasonable time to investigate and release a correction before public disclosure. Never test against a site you do not own or have explicit permission to assess.

## Credential handling

SysOpenLang never requires provider credentials for manual translation. Optional translation-provider keys are stored encrypted when OpenSSL is available and are only sent to the selected provider. Do not include API keys, database exports, access tokens, or personal data in reports, logs, screenshots, or public issues. Revoke any credential that may have been exposed.

## Scope

Security reports should concern SysOpenLang code distributed from this repository. Provider availability, provider account access, billing, translated-output accuracy, themes, and third-party extensions are outside the plugin's direct security boundary unless the issue is caused by SysOpenLang's integration code.
