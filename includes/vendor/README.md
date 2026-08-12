# Vendored third-party libraries

## plugin-update-checker/ — Plugin Update Checker v5.7, MIT (© Jānis Elsts)

https://github.com/YahnisElsts/plugin-update-checker

Lets WordPress check GitHub Releases for a newer version and offer an
in-admin update, the same way a wp.org-hosted plugin does — without needing
wp.org. Wired up in karo-kit.php.

Trimmed to the runtime files (`Puc/`, `vendor/`, `css/`, `js/`, `languages/`,
`license.txt`, `load-v5p7.php`, `plugin-update-checker.php`); the library's
own dev/build tooling, tests and Composer metadata are not needed here and
were left out.

`vendor/` (Parsedown.php, ParsedownModern.php, PucReadmeParser.php) looks
like the kind of dev tooling that trim was meant to drop, but it isn't —
`Vcs/Api.php` and `Vcs/GitHubApi.php` load `Parsedown` and `PucReadmeParser`
at runtime, to render a GitHub Release's Markdown body into the changelog
shown in wp-admin. Leaving it out fatals with "Class Parsedown not found"
the first time an update check actually reaches a real release — which,
since update checks return early with nothing to render until a token is
set or the repo is public, didn't surface until after both were true.

To update: download the new tag's source archive, copy the same set of
files/folders back over this directory, and bump the version noted here
(currently v5.7).
