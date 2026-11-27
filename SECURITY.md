# Security policy

## Reporting a vulnerability

Please report a vulnerability privately, not in a public issue:

1. Open the repository's **Security** tab on GitHub.
2. Choose **Report a vulnerability**. This opens a report only the maintainers can read.

Include what you can of the following:

- the version of the package, and the versions of PHP, Laravel and Znuny;
- what an attacker can do, and what they need for it (for example: a valid login, control over a setting, a position on the network);
- steps or code to reproduce it.

Remove passwords, session IDs and customer data from whatever you send.

You will get an answer as soon as the report has been read. A fix is released as a new version and credited to you in its notes, unless you prefer otherwise.

## Supported versions

| Version | Security fixes |
|---|---|
| latest 1.x release | yes |
| anything older | no |

## What counts

The package talks to Znuny with an account's credentials and handles ticket and customer data. Reports about these are especially welcome:

- secrets (passwords, session IDs, tokens, two-factor keys) reaching logs, events or exception messages;
- a request reaching another host or another web service than the one configured;
- certificate verification or timeouts being weakened by configuration that should not allow it;
- the webhook endpoint accepting a request without valid authentication.

A weakness in Znuny itself belongs with the [Znuny project](https://www.znuny.org).
