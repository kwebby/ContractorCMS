<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Security policy

ContractorCMS is currently a development release. It has automated security regression tests and an implementation review, but has not completed independent manual penetration testing or production-host certification. Read [the review](docs/Security-review.md) and [release gates](docs/RELEASE-ACCEPTANCE.md) before deploying business data.

## Reporting a vulnerability

Do not publish credentials, customer information, private documents or an exploitable proof of concept in a public issue. Contact the maintainer through [kwebby.com](https://kwebby.com) to arrange a private report, or use GitHub's private vulnerability reporting if it is enabled on the repository.

Include the affected commit/release, prerequisites, reproduction steps using synthetic data, expected versus actual behavior and impact. Redact tokens and customer details. Test only installations you own or are authorized to assess.

## Deployment responsibilities

- Use HTTPS, production mode, privileged MFA and a web root restricted to `public/`.
- Keep `.env`, `APP_KEY`, database credentials, provider keys, uploaded private files and backups outside public access and source control.
- Preserve the original application encryption key separately from backups; it is required to recover encrypted settings.
- Install supported PHP/database versions, apply reviewed dependency updates, and verify restore procedures.
- Configure only the actual trusted reverse proxies. Never use a blanket trust rule without understanding the deployment boundary.
- Follow the [installation](docs/INSTALLATION.md), [configuration](docs/CONFIGURATION.md) and [deployment](docs/DEPLOYMENT.md) guides.

There is no paid support or response-time commitment implied by this repository. Security fixes and supported releases will be documented in release notes as the project matures.
